<?php
/**
 * Securis Smart Society Platform — Notification Handler
 * Manages push notification listing and read status for users.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class NotificationHandler {
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
                if ($action === 'unread-count') {
                    $this->getUnreadCount();
                } else {
                    $this->listNotifications();
                }
                break;

            case 'PUT':
                if ($action === 'read-all') {
                    $this->markAllRead();
                } elseif ($id) {
                    $this->markRead($id);
                } else {
                    ApiResponse::error('Notification ID is required', 400);
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    private function listNotifications() {
        $page = max(1, intval($this->input['page'] ?? 1));
        $limit = min(50, max(1, intval($this->input['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $stmt = $this->conn->prepare(
            "SELECT id, title, body, type, reference_type, reference_id, is_read, created_at
             FROM tbl_notification
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('iii', $this->user['id'], $limit, $offset);
        $stmt->execute();
        $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Get total count
        $stmt = $this->conn->prepare("SELECT COUNT(*) as total FROM tbl_notification WHERE user_id = ?");
        $stmt->bind_param('i', $this->user['id']);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        // Cast types
        foreach ($notifications as &$n) {
            $n['id'] = (int)$n['id'];
            $n['is_read'] = (bool)$n['is_read'];
            $n['reference_id'] = $n['reference_id'] ? (int)$n['reference_id'] : null;
        }

        ApiResponse::success([
            'notifications' => $notifications,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }

    private function getUnreadCount() {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) as count FROM tbl_notification WHERE user_id = ? AND is_read = 0"
        );
        $stmt->bind_param('i', $this->user['id']);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();

        ApiResponse::success(['unread_count' => (int)$count]);
    }

    private function markRead($id) {
        $stmt = $this->conn->prepare(
            "UPDATE tbl_notification SET is_read = 1 WHERE id = ? AND user_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->user['id']);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected === 0) {
            ApiResponse::notFound('Notification not found');
        }

        ApiResponse::success(['message' => 'Notification marked as read']);
    }

    private function markAllRead() {
        $stmt = $this->conn->prepare(
            "UPDATE tbl_notification SET is_read = 1 WHERE user_id = ? AND is_read = 0"
        );
        $stmt->bind_param('i', $this->user['id']);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        ApiResponse::success(['message' => "$affected notifications marked as read"]);
    }
}
