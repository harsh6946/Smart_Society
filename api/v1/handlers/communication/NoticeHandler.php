<?php
/**
 * Securis Smart Society Platform — Notice Handler
 * Manages society notices: CRUD operations with access control.
 */

require_once __DIR__ . '/../../../../include/helpers.php';
require_once __DIR__ . '/../../../../include/security.php';

class NoticeHandler {
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
                    $this->getNotice($id);
                } else {
                    $this->listNotices();
                }
                break;

            case 'POST':
                $this->createNotice();
                break;

            case 'PUT':
                if (!$id) {
                    ApiResponse::error('Notice ID is required', 400);
                }
                $this->updateNotice($id);
                break;

            case 'DELETE':
                if (!$id) {
                    ApiResponse::error('Notice ID is required', 400);
                }
                $this->deleteNotice($id);
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    /**
     * GET /api/v1/notices
     * List notices for user's society. Supports type and tower_id filters. Paginated.
     */
    private function listNotices() {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $where = "n.society_id = ? AND n.is_active = 1 AND (n.expires_at IS NULL OR n.expires_at > NOW())";
        $params = [$this->societyId];
        $types = 'i';

        // Filter by type
        if (!empty($this->input['type'])) {
            $type = sanitizeInput($this->input['type']);
            $where .= " AND n.type = ?";
            $params[] = $type;
            $types .= 's';
        }

        // Filter by tower_id (null means society-wide, so include those too)
        if (isset($this->input['tower_id']) && $this->input['tower_id'] !== '') {
            $towerId = (int)$this->input['tower_id'];
            $where .= " AND (n.tower_id = ? OR n.tower_id IS NULL)";
            $params[] = $towerId;
            $types .= 'i';
        }

        // Count total
        $countStmt = $this->conn->prepare("SELECT COUNT(*) as total FROM tbl_notice n WHERE $where");
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch notices
        $sql = "SELECT n.id, n.society_id, n.tower_id, n.title, n.content, n.type,
                       n.attachment, n.posted_by, n.is_active, n.created_at, n.expires_at,
                       u.name as posted_by_name, u.avatar as posted_by_avatar
                FROM tbl_notice n
                LEFT JOIN tbl_user u ON u.id = n.posted_by
                WHERE $where
                ORDER BY n.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $notices = [];
        while ($row = $result->fetch_assoc()) {
            $notices[] = $this->formatNotice($row);
        }
        $stmt->close();

        ApiResponse::paginated($notices, $total, $page, $perPage, 'Notices retrieved successfully');
    }

    /**
     * GET /api/v1/notices/{id}
     * Get a single notice detail.
     */
    private function getNotice($id) {
        $stmt = $this->conn->prepare(
            "SELECT n.id, n.society_id, n.tower_id, n.title, n.content, n.type,
                    n.attachment, n.posted_by, n.is_active, n.created_at, n.expires_at,
                    u.name as posted_by_name, u.avatar as posted_by_avatar
             FROM tbl_notice n
             LEFT JOIN tbl_user u ON u.id = n.posted_by
             WHERE n.id = ? AND n.society_id = ? AND n.is_active = 1"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Notice not found');
        }

        $notice = $this->formatNotice($result->fetch_assoc());
        $stmt->close();

        ApiResponse::success($notice, 'Notice retrieved successfully');
    }

    /**
     * POST /api/v1/notices
     * Create a new notice. Only primary owners can create notices.
     */
    private function createNotice() {
        $this->auth->requirePrimary();

        $title = sanitizeInput($this->input['title'] ?? '');
        $content = sanitizeInput($this->input['content'] ?? '');
        $type = sanitizeInput($this->input['type'] ?? 'general');
        $towerId = isset($this->input['tower_id']) && $this->input['tower_id'] !== '' ? (int)$this->input['tower_id'] : null;
        $expiresAt = !empty($this->input['expires_at']) ? sanitizeInput($this->input['expires_at']) : null;

        // Validation
        if (empty($title)) {
            ApiResponse::error('Title is required', 400);
        }
        if (empty($content)) {
            ApiResponse::error('Content is required', 400);
        }

        $allowedTypes = ['general', 'emergency', 'event', 'maintenance'];
        if (!in_array($type, $allowedTypes)) {
            ApiResponse::error('Invalid notice type. Allowed: ' . implode(', ', $allowedTypes), 400);
        }

        $userId = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_notice (society_id, tower_id, title, content, type, posted_by, is_active, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), ?)"
        );
        $stmt->bind_param('iisssis', $this->societyId, $towerId, $title, $content, $type, $userId, $expiresAt);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to create notice', 500);
        }

        $noticeId = $stmt->insert_id;
        $stmt->close();

        // Fetch the created notice
        $fetchStmt = $this->conn->prepare(
            "SELECT n.id, n.society_id, n.tower_id, n.title, n.content, n.type,
                    n.attachment, n.posted_by, n.is_active, n.created_at, n.expires_at,
                    u.name as posted_by_name, u.avatar as posted_by_avatar
             FROM tbl_notice n
             LEFT JOIN tbl_user u ON u.id = n.posted_by
             WHERE n.id = ?"
        );
        $fetchStmt->bind_param('i', $noticeId);
        $fetchStmt->execute();
        $notice = $this->formatNotice($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::created($notice, 'Notice created successfully');
    }

    /**
     * PUT /api/v1/notices/{id}
     * Update a notice. Only the poster or a primary owner can update.
     */
    private function updateNotice($id) {
        // Fetch existing notice
        $stmt = $this->conn->prepare(
            "SELECT id, posted_by FROM tbl_notice WHERE id = ? AND society_id = ? AND is_active = 1"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Notice not found');
        }

        $existing = $result->fetch_assoc();
        $stmt->close();

        // Authorization: only posted_by user or primary owner
        $userId = $this->auth->getUserId();
        $isPrimary = $this->user['is_primary'];
        if ((int)$existing['posted_by'] !== $userId && !$isPrimary) {
            ApiResponse::forbidden('You can only update your own notices');
        }

        // Build dynamic update
        $fields = [];
        $params = [];
        $types = '';

        if (isset($this->input['title'])) {
            $fields[] = 'title = ?';
            $params[] = sanitizeInput($this->input['title']);
            $types .= 's';
        }

        if (isset($this->input['content'])) {
            $fields[] = 'content = ?';
            $params[] = sanitizeInput($this->input['content']);
            $types .= 's';
        }

        if (isset($this->input['type'])) {
            $type = sanitizeInput($this->input['type']);
            $allowedTypes = ['general', 'emergency', 'event', 'maintenance'];
            if (!in_array($type, $allowedTypes)) {
                ApiResponse::error('Invalid notice type. Allowed: ' . implode(', ', $allowedTypes), 400);
            }
            $fields[] = 'type = ?';
            $params[] = $type;
            $types .= 's';
        }

        if (array_key_exists('tower_id', $this->input)) {
            $fields[] = 'tower_id = ?';
            $params[] = $this->input['tower_id'] !== '' && $this->input['tower_id'] !== null ? (int)$this->input['tower_id'] : null;
            $types .= 'i';
        }

        if (array_key_exists('expires_at', $this->input)) {
            $fields[] = 'expires_at = ?';
            $params[] = !empty($this->input['expires_at']) ? sanitizeInput($this->input['expires_at']) : null;
            $types .= 's';
        }

        if (empty($fields)) {
            ApiResponse::error('No fields to update', 400);
        }

        $sql = "UPDATE tbl_notice SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $updateStmt = $this->conn->prepare($sql);
        $updateStmt->bind_param($types, ...$params);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to update notice', 500);
        }
        $updateStmt->close();

        // Return updated notice
        $fetchStmt = $this->conn->prepare(
            "SELECT n.id, n.society_id, n.tower_id, n.title, n.content, n.type,
                    n.attachment, n.posted_by, n.is_active, n.created_at, n.expires_at,
                    u.name as posted_by_name, u.avatar as posted_by_avatar
             FROM tbl_notice n
             LEFT JOIN tbl_user u ON u.id = n.posted_by
             WHERE n.id = ?"
        );
        $fetchStmt->bind_param('i', $id);
        $fetchStmt->execute();
        $notice = $this->formatNotice($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::success($notice, 'Notice updated successfully');
    }

    /**
     * DELETE /api/v1/notices/{id}
     * Soft delete a notice (set is_active = 0). Only poster or primary owner.
     */
    private function deleteNotice($id) {
        $stmt = $this->conn->prepare(
            "SELECT id, posted_by FROM tbl_notice WHERE id = ? AND society_id = ? AND is_active = 1"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Notice not found');
        }

        $existing = $result->fetch_assoc();
        $stmt->close();

        // Authorization
        $userId = $this->auth->getUserId();
        $isPrimary = $this->user['is_primary'];
        if ((int)$existing['posted_by'] !== $userId && !$isPrimary) {
            ApiResponse::forbidden('You can only delete your own notices');
        }

        $deleteStmt = $this->conn->prepare("UPDATE tbl_notice SET is_active = 0 WHERE id = ?");
        $deleteStmt->bind_param('i', $id);

        if (!$deleteStmt->execute()) {
            ApiResponse::error('Failed to delete notice', 500);
        }
        $deleteStmt->close();

        ApiResponse::success(null, 'Notice deleted successfully');
    }

    /**
     * Format a notice row for API output.
     */
    private function formatNotice($row) {
        return [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'tower_id' => $row['tower_id'] !== null ? (int)$row['tower_id'] : null,
            'title' => $row['title'],
            'content' => $row['content'],
            'type' => $row['type'],
            'attachment' => $row['attachment'],
            'posted_by' => [
                'id' => (int)$row['posted_by'],
                'name' => $row['posted_by_name'],
                'avatar' => $row['posted_by_avatar'],
            ],
            'is_active' => (bool)$row['is_active'],
            'created_at' => $row['created_at'],
            'expires_at' => $row['expires_at'],
        ];
    }
}
