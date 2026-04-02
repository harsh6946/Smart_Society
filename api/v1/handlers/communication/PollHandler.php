<?php
/**
 * Securis Smart Society Platform — Poll Handler
 * Manages society polls: create, vote, view results.
 */

require_once __DIR__ . '/../../../../include/helpers.php';
require_once __DIR__ . '/../../../../include/security.php';

class PollHandler {
    private $conn;
    private $auth;
    private $input;
    private $user;
    private $societyId;

    public function __construct($conn, $auth, $input) {
        $this->conn = $conn;
        $this->auth = $auth;
        $this->input = $input;
    }

    public function handle($method, $action, $id) {
        $this->user = $this->auth->authenticate();
        $this->societyId = $this->auth->requireSociety();

        switch ($method) {
            case 'GET':
                if ($id) {
                    $this->getPoll($id);
                } else {
                    $this->listPolls();
                }
                break;

            case 'POST':
                if ($id && $action === 'vote') {
                    $this->castVote($id);
                } elseif (!$id) {
                    $this->createPoll();
                } else {
                    ApiResponse::error('Invalid action', 400);
                }
                break;

            case 'DELETE':
                if (!$id) {
                    ApiResponse::error('Poll ID is required', 400);
                }
                $this->deletePoll($id);
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    /**
     * GET /api/v1/polls
     * List active polls for society with vote counts per option. Paginated.
     */
    private function listPolls() {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        // Count total active polls
        $countStmt = $this->conn->prepare(
            "SELECT COUNT(*) as total FROM tbl_poll WHERE society_id = ? AND is_active = 1"
        );
        $countStmt->bind_param('i', $this->societyId);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch polls
        $stmt = $this->conn->prepare(
            "SELECT p.id, p.society_id, p.question, p.options_json, p.end_date,
                    p.is_active, p.created_by,
                    u.name as created_by_name, u.avatar as created_by_avatar
             FROM tbl_poll p
             LEFT JOIN tbl_user u ON u.id = p.created_by
             WHERE p.society_id = ? AND p.is_active = 1
             ORDER BY p.end_date DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('iii', $this->societyId, $perPage, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $polls = [];
        while ($row = $result->fetch_assoc()) {
            $polls[] = $this->formatPoll($row, false);
        }
        $stmt->close();

        ApiResponse::paginated($polls, $total, $page, $perPage, 'Polls retrieved successfully');
    }

    /**
     * GET /api/v1/polls/{id}
     * Poll detail with results: each option with vote count, plus current user's vote.
     */
    private function getPoll($id) {
        $stmt = $this->conn->prepare(
            "SELECT p.id, p.society_id, p.question, p.options_json, p.end_date,
                    p.is_active, p.created_by,
                    u.name as created_by_name, u.avatar as created_by_avatar
             FROM tbl_poll p
             LEFT JOIN tbl_user u ON u.id = p.created_by
             WHERE p.id = ? AND p.society_id = ? AND p.is_active = 1"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Poll not found');
        }

        $poll = $this->formatPoll($result->fetch_assoc(), true);
        $stmt->close();

        ApiResponse::success($poll, 'Poll retrieved successfully');
    }

    /**
     * POST /api/v1/polls
     * Create a new poll. Only primary owners can create.
     */
    private function createPoll() {
        $this->auth->requirePrimary();

        $question = sanitizeInput($this->input['question'] ?? '');
        $options = $this->input['options'] ?? [];
        $endDate = sanitizeInput($this->input['end_date'] ?? '');

        // Validation
        if (empty($question)) {
            ApiResponse::error('Question is required', 400);
        }

        if (!is_array($options) || count($options) < 2) {
            ApiResponse::error('At least 2 options are required', 400);
        }

        if (count($options) > 10) {
            ApiResponse::error('Maximum 10 options allowed', 400);
        }

        if (empty($endDate)) {
            ApiResponse::error('End date is required', 400);
        }

        if (strtotime($endDate) <= time()) {
            ApiResponse::error('End date must be in the future', 400);
        }

        // Sanitize each option
        $sanitizedOptions = [];
        foreach ($options as $option) {
            $cleaned = sanitizeInput($option);
            if (empty($cleaned)) {
                ApiResponse::error('Option text cannot be empty', 400);
            }
            $sanitizedOptions[] = $cleaned;
        }

        $optionsJson = json_encode($sanitizedOptions);
        $userId = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_poll (society_id, question, options_json, end_date, is_active, created_by)
             VALUES (?, ?, ?, ?, 1, ?)"
        );
        $stmt->bind_param('isssi', $this->societyId, $question, $optionsJson, $endDate, $userId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to create poll', 500);
        }

        $pollId = $stmt->insert_id;
        $stmt->close();

        // Fetch the created poll
        $fetchStmt = $this->conn->prepare(
            "SELECT p.id, p.society_id, p.question, p.options_json, p.end_date,
                    p.is_active, p.created_by,
                    u.name as created_by_name, u.avatar as created_by_avatar
             FROM tbl_poll p
             LEFT JOIN tbl_user u ON u.id = p.created_by
             WHERE p.id = ?"
        );
        $fetchStmt->bind_param('i', $pollId);
        $fetchStmt->execute();
        $poll = $this->formatPoll($fetchStmt->get_result()->fetch_assoc(), true);
        $fetchStmt->close();

        ApiResponse::created($poll, 'Poll created successfully');
    }

    /**
     * POST /api/v1/polls/{id}/vote
     * Cast a vote on a poll. One vote per user enforced by unique key.
     */
    private function castVote($pollId) {
        $userId = $this->auth->getUserId();

        // Fetch the poll
        $stmt = $this->conn->prepare(
            "SELECT id, options_json, end_date, is_active
             FROM tbl_poll
             WHERE id = ? AND society_id = ? AND is_active = 1"
        );
        $stmt->bind_param('ii', $pollId, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Poll not found');
        }

        $poll = $result->fetch_assoc();
        $stmt->close();

        // Check if poll has ended
        if (strtotime($poll['end_date']) <= time()) {
            ApiResponse::error('This poll has ended', 400);
        }

        // Validate option_index
        if (!isset($this->input['option_index']) && $this->input['option_index'] !== 0) {
            ApiResponse::error('option_index is required', 400);
        }

        $optionIndex = (int)$this->input['option_index'];
        $options = json_decode($poll['options_json'], true);

        if ($optionIndex < 0 || $optionIndex >= count($options)) {
            ApiResponse::error('Invalid option index', 400);
        }

        // Check if user has already voted
        $checkStmt = $this->conn->prepare(
            "SELECT id FROM tbl_poll_vote WHERE poll_id = ? AND user_id = ?"
        );
        $checkStmt->bind_param('ii', $pollId, $userId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows > 0) {
            ApiResponse::error('You have already voted on this poll', 409);
        }
        $checkStmt->close();

        // Insert vote
        $voteStmt = $this->conn->prepare(
            "INSERT INTO tbl_poll_vote (poll_id, user_id, option_index, voted_at)
             VALUES (?, ?, ?, NOW())"
        );
        $voteStmt->bind_param('iii', $pollId, $userId, $optionIndex);

        if (!$voteStmt->execute()) {
            // Handle duplicate key error gracefully
            if ($this->conn->errno === 1062) {
                ApiResponse::error('You have already voted on this poll', 409);
            }
            ApiResponse::error('Failed to cast vote', 500);
        }
        $voteStmt->close();

        // Return updated poll with results
        $fetchStmt = $this->conn->prepare(
            "SELECT p.id, p.society_id, p.question, p.options_json, p.end_date,
                    p.is_active, p.created_by,
                    u.name as created_by_name, u.avatar as created_by_avatar
             FROM tbl_poll p
             LEFT JOIN tbl_user u ON u.id = p.created_by
             WHERE p.id = ?"
        );
        $fetchStmt->bind_param('i', $pollId);
        $fetchStmt->execute();
        $updatedPoll = $this->formatPoll($fetchStmt->get_result()->fetch_assoc(), true);
        $fetchStmt->close();

        ApiResponse::success($updatedPoll, 'Vote cast successfully');
    }

    /**
     * DELETE /api/v1/polls/{id}
     * Deactivate a poll (set is_active = 0). Primary owners only.
     */
    private function deletePoll($id) {
        $this->auth->requirePrimary();

        $stmt = $this->conn->prepare(
            "SELECT id FROM tbl_poll WHERE id = ? AND society_id = ? AND is_active = 1"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Poll not found');
        }
        $stmt->close();

        $deleteStmt = $this->conn->prepare("UPDATE tbl_poll SET is_active = 0 WHERE id = ?");
        $deleteStmt->bind_param('i', $id);

        if (!$deleteStmt->execute()) {
            ApiResponse::error('Failed to deactivate poll', 500);
        }
        $deleteStmt->close();

        ApiResponse::success(null, 'Poll deactivated successfully');
    }

    /**
     * Format a poll row for API output.
     * @param array $row     Database row
     * @param bool  $detail  If true, include per-option vote counts and user's vote
     */
    private function formatPoll($row, $detail = false) {
        $pollId = (int)$row['id'];
        $options = json_decode($row['options_json'], true);
        $isExpired = strtotime($row['end_date']) <= time();

        $formatted = [
            'id' => $pollId,
            'society_id' => (int)$row['society_id'],
            'question' => $row['question'],
            'end_date' => $row['end_date'],
            'is_active' => (bool)$row['is_active'],
            'is_expired' => $isExpired,
            'created_by' => [
                'id' => (int)$row['created_by'],
                'name' => $row['created_by_name'],
                'avatar' => $row['created_by_avatar'],
            ],
        ];

        // Get vote counts per option
        $voteCounts = $this->getVoteCounts($pollId, count($options));
        $totalVotes = array_sum($voteCounts);

        $optionsData = [];
        for ($i = 0; $i < count($options); $i++) {
            $optionEntry = [
                'index' => $i,
                'text' => $options[$i],
                'votes' => $voteCounts[$i],
            ];
            $optionsData[] = $optionEntry;
        }

        $formatted['options'] = $optionsData;
        $formatted['total_votes'] = $totalVotes;

        if ($detail) {
            // Check if current user has voted and which option
            $userId = $this->auth->getUserId();
            $voteStmt = $this->conn->prepare(
                "SELECT option_index, voted_at FROM tbl_poll_vote WHERE poll_id = ? AND user_id = ?"
            );
            $voteStmt->bind_param('ii', $pollId, $userId);
            $voteStmt->execute();
            $voteResult = $voteStmt->get_result();

            if ($voteResult->num_rows > 0) {
                $vote = $voteResult->fetch_assoc();
                $formatted['user_vote'] = [
                    'has_voted' => true,
                    'option_index' => (int)$vote['option_index'],
                    'voted_at' => $vote['voted_at'],
                ];
            } else {
                $formatted['user_vote'] = [
                    'has_voted' => false,
                    'option_index' => null,
                    'voted_at' => null,
                ];
            }
            $voteStmt->close();
        }

        return $formatted;
    }

    /**
     * Get vote counts per option for a poll.
     */
    private function getVoteCounts($pollId, $optionCount) {
        $counts = array_fill(0, $optionCount, 0);

        $stmt = $this->conn->prepare(
            "SELECT option_index, COUNT(*) as cnt
             FROM tbl_poll_vote
             WHERE poll_id = ?
             GROUP BY option_index"
        );
        $stmt->bind_param('i', $pollId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $idx = (int)$row['option_index'];
            if ($idx >= 0 && $idx < $optionCount) {
                $counts[$idx] = (int)$row['cnt'];
            }
        }
        $stmt->close();

        return $counts;
    }
}
