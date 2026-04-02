<?php
/**
 * Securis Smart Society Platform — Event Handler
 * Manages society events: CRUD, cancellation, and RSVP operations.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class EventHandler {
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
                if ($id && $action === 'rsvp') {
                    // GET /events/{id}/rsvp
                    $this->listRsvps($id);
                } elseif ($id) {
                    // GET /events/{id}
                    $this->getEvent($id);
                } else {
                    // GET /events
                    $this->listEvents();
                }
                break;

            case 'POST':
                if ($id && $action === 'rsvp') {
                    // POST /events/{id}/rsvp
                    $this->rsvpEvent($id);
                } else {
                    // POST /events
                    $this->createEvent();
                }
                break;

            case 'PUT':
                if (!$id) {
                    ApiResponse::error('Event ID is required', 400);
                }
                if ($action === 'cancel') {
                    // PUT /events/{id}/cancel
                    $this->cancelEvent($id);
                } else {
                    // PUT /events/{id}
                    $this->updateEvent($id);
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ---------------------------------------------------------------
    //  GET /events
    // ---------------------------------------------------------------
    private function listEvents() {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $where = "e.society_id = ?";
        $params = [$this->societyId];
        $types = 'i';

        // Filter by event_type
        if (!empty($this->input['event_type'])) {
            $eventType = sanitizeInput($this->input['event_type']);
            $allowedTypes = ['festival', 'agm', 'sports', 'cultural', 'workshop', 'other'];
            if (in_array($eventType, $allowedTypes)) {
                $where .= " AND e.event_type = ?";
                $params[] = $eventType;
                $types .= 's';
            }
        }

        // Filter by status
        if (!empty($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $allowedStatuses = ['upcoming', 'ongoing', 'completed', 'cancelled'];
            if (in_array($status, $allowedStatuses)) {
                $where .= " AND e.status = ?";
                $params[] = $status;
                $types .= 's';
            }
        }

        // Count total
        $countStmt = $this->conn->prepare("SELECT COUNT(*) AS total FROM tbl_event e WHERE $where");
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch events
        $sql = "SELECT e.id, e.society_id, e.title, e.description, e.event_type, e.venue,
                       e.start_datetime, e.end_datetime, e.image, e.max_participants,
                       e.is_rsvp_required, e.created_by, e.status, e.created_at,
                       u.name AS created_by_name,
                       (SELECT COUNT(*) FROM tbl_event_rsvp r WHERE r.event_id = e.id AND r.status = 'going') AS rsvp_going_count
                FROM tbl_event e
                LEFT JOIN tbl_user u ON u.id = e.created_by
                WHERE $where
                ORDER BY e.start_datetime DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $events = [];
        while ($row = $result->fetch_assoc()) {
            $events[] = $this->formatEvent($row);
        }
        $stmt->close();

        ApiResponse::paginated($events, $total, $page, $perPage, 'Events retrieved successfully');
    }

    // ---------------------------------------------------------------
    //  GET /events/{id}
    // ---------------------------------------------------------------
    private function getEvent($id) {
        $userId = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "SELECT e.id, e.society_id, e.title, e.description, e.event_type, e.venue,
                    e.start_datetime, e.end_datetime, e.image, e.max_participants,
                    e.is_rsvp_required, e.created_by, e.status, e.created_at,
                    u.name AS created_by_name,
                    (SELECT COUNT(*) FROM tbl_event_rsvp r WHERE r.event_id = e.id AND r.status = 'going') AS rsvp_going_count,
                    (SELECT COUNT(*) FROM tbl_event_rsvp r WHERE r.event_id = e.id AND r.status = 'maybe') AS rsvp_maybe_count,
                    (SELECT COUNT(*) FROM tbl_event_rsvp r WHERE r.event_id = e.id AND r.status = 'not_going') AS rsvp_not_going_count
             FROM tbl_event e
             LEFT JOIN tbl_user u ON u.id = e.created_by
             WHERE e.id = ? AND e.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Event not found');
        }

        $row = $result->fetch_assoc();
        $stmt->close();

        $event = $this->formatEvent($row, true);

        // Get current user's RSVP status
        $rsvpStmt = $this->conn->prepare(
            "SELECT status, guests FROM tbl_event_rsvp WHERE event_id = ? AND user_id = ?"
        );
        $rsvpStmt->bind_param('ii', $id, $userId);
        $rsvpStmt->execute();
        $rsvpResult = $rsvpStmt->get_result();

        if ($rsvpResult->num_rows > 0) {
            $rsvpRow = $rsvpResult->fetch_assoc();
            $event['my_rsvp'] = [
                'status' => $rsvpRow['status'],
                'guests' => (int)$rsvpRow['guests'],
            ];
        } else {
            $event['my_rsvp'] = null;
        }
        $rsvpStmt->close();

        ApiResponse::success($event, 'Event retrieved successfully');
    }

    // ---------------------------------------------------------------
    //  POST /events
    // ---------------------------------------------------------------
    private function createEvent() {
        $this->auth->requirePrimary();

        $title = sanitizeInput($this->input['title'] ?? '');
        $description = sanitizeInput($this->input['description'] ?? '');
        $eventType = sanitizeInput($this->input['event_type'] ?? 'other');
        $venue = sanitizeInput($this->input['venue'] ?? '');
        $startDatetime = sanitizeInput($this->input['start_datetime'] ?? '');
        $endDatetime = !empty($this->input['end_datetime']) ? sanitizeInput($this->input['end_datetime']) : null;
        $maxParticipants = isset($this->input['max_participants']) ? (int)$this->input['max_participants'] : null;
        $isRsvpRequired = isset($this->input['is_rsvp_required']) ? (int)(bool)$this->input['is_rsvp_required'] : 0;

        // Validation
        if (empty($title)) {
            ApiResponse::error('Title is required', 400);
        }
        if (empty($startDatetime)) {
            ApiResponse::error('Start datetime is required', 400);
        }

        $allowedTypes = ['festival', 'agm', 'sports', 'cultural', 'workshop', 'other'];
        if (!in_array($eventType, $allowedTypes)) {
            ApiResponse::error('Invalid event type. Allowed: ' . implode(', ', $allowedTypes), 400);
        }

        // Handle image upload
        $image = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['image'], 'events', ['jpg', 'jpeg', 'png', 'webp']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error'], 400);
            }
            $image = $upload['path'];
        }

        $userId = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_event (society_id, title, description, event_type, venue, start_datetime,
                                    end_datetime, image, max_participants, is_rsvp_required, created_by, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'upcoming')"
        );
        $stmt->bind_param(
            'isssssssiis',
            $this->societyId, $title, $description, $eventType, $venue, $startDatetime,
            $endDatetime, $image, $maxParticipants, $isRsvpRequired, $userId
        );

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to create event', 500);
        }

        $eventId = $stmt->insert_id;
        $stmt->close();

        // Fetch created event
        $fetchStmt = $this->conn->prepare(
            "SELECT e.id, e.society_id, e.title, e.description, e.event_type, e.venue,
                    e.start_datetime, e.end_datetime, e.image, e.max_participants,
                    e.is_rsvp_required, e.created_by, e.status, e.created_at,
                    u.name AS created_by_name
             FROM tbl_event e
             LEFT JOIN tbl_user u ON u.id = e.created_by
             WHERE e.id = ?"
        );
        $fetchStmt->bind_param('i', $eventId);
        $fetchStmt->execute();
        $event = $this->formatEvent($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::created($event, 'Event created successfully');
    }

    // ---------------------------------------------------------------
    //  PUT /events/{id}
    // ---------------------------------------------------------------
    private function updateEvent($id) {
        $this->auth->requirePrimary();

        // Verify event exists in this society
        $stmt = $this->conn->prepare(
            "SELECT id, status FROM tbl_event WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Event not found');
        }

        $existing = $result->fetch_assoc();
        $stmt->close();

        if ($existing['status'] === 'cancelled') {
            ApiResponse::error('Cannot update a cancelled event', 400);
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

        if (isset($this->input['description'])) {
            $fields[] = 'description = ?';
            $params[] = sanitizeInput($this->input['description']);
            $types .= 's';
        }

        if (isset($this->input['event_type'])) {
            $eventType = sanitizeInput($this->input['event_type']);
            $allowedTypes = ['festival', 'agm', 'sports', 'cultural', 'workshop', 'other'];
            if (!in_array($eventType, $allowedTypes)) {
                ApiResponse::error('Invalid event type. Allowed: ' . implode(', ', $allowedTypes), 400);
            }
            $fields[] = 'event_type = ?';
            $params[] = $eventType;
            $types .= 's';
        }

        if (isset($this->input['venue'])) {
            $fields[] = 'venue = ?';
            $params[] = sanitizeInput($this->input['venue']);
            $types .= 's';
        }

        if (isset($this->input['start_datetime'])) {
            $fields[] = 'start_datetime = ?';
            $params[] = sanitizeInput($this->input['start_datetime']);
            $types .= 's';
        }

        if (array_key_exists('end_datetime', $this->input)) {
            $fields[] = 'end_datetime = ?';
            $params[] = !empty($this->input['end_datetime']) ? sanitizeInput($this->input['end_datetime']) : null;
            $types .= 's';
        }

        if (isset($this->input['max_participants'])) {
            $fields[] = 'max_participants = ?';
            $params[] = (int)$this->input['max_participants'];
            $types .= 'i';
        }

        if (isset($this->input['is_rsvp_required'])) {
            $fields[] = 'is_rsvp_required = ?';
            $params[] = (int)(bool)$this->input['is_rsvp_required'];
            $types .= 'i';
        }

        if (isset($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $allowedStatuses = ['upcoming', 'ongoing', 'completed', 'cancelled'];
            if (!in_array($status, $allowedStatuses)) {
                ApiResponse::error('Invalid status. Allowed: ' . implode(', ', $allowedStatuses), 400);
            }
            $fields[] = 'status = ?';
            $params[] = $status;
            $types .= 's';
        }

        // Handle image upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['image'], 'events', ['jpg', 'jpeg', 'png', 'webp']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error'], 400);
            }
            $fields[] = 'image = ?';
            $params[] = $upload['path'];
            $types .= 's';
        }

        if (empty($fields)) {
            ApiResponse::error('No fields to update', 400);
        }

        $sql = "UPDATE tbl_event SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $updateStmt = $this->conn->prepare($sql);
        $updateStmt->bind_param($types, ...$params);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to update event', 500);
        }
        $updateStmt->close();

        // Return updated event
        $fetchStmt = $this->conn->prepare(
            "SELECT e.id, e.society_id, e.title, e.description, e.event_type, e.venue,
                    e.start_datetime, e.end_datetime, e.image, e.max_participants,
                    e.is_rsvp_required, e.created_by, e.status, e.created_at,
                    u.name AS created_by_name
             FROM tbl_event e
             LEFT JOIN tbl_user u ON u.id = e.created_by
             WHERE e.id = ?"
        );
        $fetchStmt->bind_param('i', $id);
        $fetchStmt->execute();
        $event = $this->formatEvent($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::success($event, 'Event updated successfully');
    }

    // ---------------------------------------------------------------
    //  PUT /events/{id}/cancel
    // ---------------------------------------------------------------
    private function cancelEvent($id) {
        $this->auth->requirePrimary();

        $stmt = $this->conn->prepare(
            "SELECT id, status FROM tbl_event WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Event not found');
        }

        $existing = $result->fetch_assoc();
        $stmt->close();

        if ($existing['status'] === 'cancelled') {
            ApiResponse::error('Event is already cancelled', 400);
        }

        if ($existing['status'] === 'completed') {
            ApiResponse::error('Cannot cancel a completed event', 400);
        }

        $updateStmt = $this->conn->prepare(
            "UPDATE tbl_event SET status = 'cancelled' WHERE id = ?"
        );
        $updateStmt->bind_param('i', $id);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to cancel event', 500);
        }
        $updateStmt->close();

        ApiResponse::success(['id' => (int)$id, 'status' => 'cancelled'], 'Event cancelled successfully');
    }

    // ---------------------------------------------------------------
    //  POST /events/{id}/rsvp
    // ---------------------------------------------------------------
    private function rsvpEvent($id) {
        $userId = $this->auth->getUserId();

        // Verify event exists and is in this society
        $stmt = $this->conn->prepare(
            "SELECT id, status, max_participants, is_rsvp_required
             FROM tbl_event WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Event not found');
        }

        $event = $result->fetch_assoc();
        $stmt->close();

        if ($event['status'] === 'cancelled') {
            ApiResponse::error('Cannot RSVP to a cancelled event', 400);
        }

        if ($event['status'] === 'completed') {
            ApiResponse::error('Cannot RSVP to a completed event', 400);
        }

        $rsvpStatus = sanitizeInput($this->input['status'] ?? 'going');
        $guests = isset($this->input['guests']) ? (int)$this->input['guests'] : 0;

        $allowedStatuses = ['going', 'maybe', 'not_going'];
        if (!in_array($rsvpStatus, $allowedStatuses)) {
            ApiResponse::error('Invalid RSVP status. Allowed: ' . implode(', ', $allowedStatuses), 400);
        }

        if ($guests < 0) {
            ApiResponse::error('Guests cannot be negative', 400);
        }

        // Check max_participants if going
        if ($rsvpStatus === 'going' && $event['max_participants'] > 0) {
            $countStmt = $this->conn->prepare(
                "SELECT COALESCE(SUM(1 + guests), 0) AS total_attending
                 FROM tbl_event_rsvp
                 WHERE event_id = ? AND status = 'going' AND user_id != ?"
            );
            $countStmt->bind_param('ii', $id, $userId);
            $countStmt->execute();
            $currentCount = (int)$countStmt->get_result()->fetch_assoc()['total_attending'];
            $countStmt->close();

            if (($currentCount + 1 + $guests) > $event['max_participants']) {
                ApiResponse::error('Event has reached maximum participants', 400);
            }
        }

        // INSERT ON DUPLICATE KEY UPDATE
        $rsvpStmt = $this->conn->prepare(
            "INSERT INTO tbl_event_rsvp (event_id, user_id, status, guests)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), guests = VALUES(guests)"
        );
        $rsvpStmt->bind_param('iisi', $id, $userId, $rsvpStatus, $guests);

        if (!$rsvpStmt->execute()) {
            ApiResponse::error('Failed to submit RSVP', 500);
        }
        $rsvpStmt->close();

        ApiResponse::success([
            'event_id' => (int)$id,
            'user_id' => $userId,
            'status' => $rsvpStatus,
            'guests' => $guests,
        ], 'RSVP submitted successfully');
    }

    // ---------------------------------------------------------------
    //  GET /events/{id}/rsvp
    // ---------------------------------------------------------------
    private function listRsvps($id) {
        // Verify event exists in this society
        $stmt = $this->conn->prepare(
            "SELECT id FROM tbl_event WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();

        if ($stmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Event not found');
        }
        $stmt->close();

        $rsvpStmt = $this->conn->prepare(
            "SELECT r.id, r.event_id, r.user_id, r.status, r.guests, r.created_at,
                    u.name AS user_name, u.avatar AS user_avatar
             FROM tbl_event_rsvp r
             LEFT JOIN tbl_user u ON u.id = r.user_id
             WHERE r.event_id = ?
             ORDER BY r.created_at ASC"
        );
        $rsvpStmt->bind_param('i', $id);
        $rsvpStmt->execute();
        $result = $rsvpStmt->get_result();

        $rsvps = [];
        while ($row = $result->fetch_assoc()) {
            $rsvps[] = [
                'id' => (int)$row['id'],
                'event_id' => (int)$row['event_id'],
                'user_id' => (int)$row['user_id'],
                'user_name' => $row['user_name'],
                'user_avatar' => $row['user_avatar'],
                'status' => $row['status'],
                'guests' => (int)$row['guests'],
                'created_at' => $row['created_at'],
            ];
        }
        $rsvpStmt->close();

        ApiResponse::success($rsvps, 'RSVPs retrieved successfully');
    }

    // ---------------------------------------------------------------
    //  Formatters
    // ---------------------------------------------------------------
    private function formatEvent($row, $detailed = false) {
        $event = [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'event_type' => $row['event_type'],
            'venue' => $row['venue'],
            'start_datetime' => $row['start_datetime'],
            'end_datetime' => $row['end_datetime'],
            'image' => $row['image'],
            'max_participants' => $row['max_participants'] !== null ? (int)$row['max_participants'] : null,
            'is_rsvp_required' => (bool)$row['is_rsvp_required'],
            'status' => $row['status'],
            'created_by' => [
                'id' => (int)$row['created_by'],
                'name' => $row['created_by_name'] ?? null,
            ],
            'created_at' => $row['created_at'],
            'rsvp_going_count' => isset($row['rsvp_going_count']) ? (int)$row['rsvp_going_count'] : 0,
        ];

        if ($detailed) {
            $event['rsvp_maybe_count'] = isset($row['rsvp_maybe_count']) ? (int)$row['rsvp_maybe_count'] : 0;
            $event['rsvp_not_going_count'] = isset($row['rsvp_not_going_count']) ? (int)$row['rsvp_not_going_count'] : 0;
        }

        return $event;
    }
}
