<?php
/**
 * Securis Smart Society Platform — Emergency Handler
 * Manages emergency alerts (SOS, fire, medical, etc.) and emergency contacts CRUD.
 */

require_once __DIR__ . '/../../../../include/helpers.php';
require_once __DIR__ . '/../../../../include/security.php';

class EmergencyHandler {
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

        // Emergency contacts sub-resource
        if ($action === 'contacts') {
            $this->handleContacts($method, $id);
            return;
        }

        // SOS trigger
        if ($action === 'sos' && $method === 'POST') {
            $this->triggerSOS();
            return;
        }

        // Alerts sub-resource
        if ($action === 'alerts') {
            $this->handleAlerts($method, $id);
            return;
        }

        ApiResponse::error('Invalid endpoint', 400);
    }

    // ─── Alerts routing ─────────────────────────────────────────────────

    private function handleAlerts($method, $id) {
        switch ($method) {
            case 'GET':
                if ($id) {
                    $this->getAlert($id);
                } else {
                    $this->listAlerts();
                }
                break;

            case 'PUT':
                if (!$id) {
                    ApiResponse::error('Alert ID is required', 400);
                }
                $this->handleAlertPutAction($id);
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    private function handleAlertPutAction($id) {
        // Determine sub-action from the URL segments
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($uri, '/respond') !== false) {
            $this->respondToAlert($id);
        } elseif (strpos($uri, '/resolve') !== false) {
            $this->resolveAlert($id);
        } elseif (strpos($uri, '/false-alarm') !== false) {
            $this->markFalseAlarm($id);
        } else {
            ApiResponse::error('Invalid action', 400);
        }
    }

    // ─── POST /api/v1/emergency/sos ─────────────────────────────────────

    /**
     * Trigger an SOS / emergency alert.
     * Sends FCM notifications and stores in-app notifications for all society members and guards.
     */
    private function triggerSOS() {
        $alertType = sanitizeInput($this->input['alert_type'] ?? 'sos');
        $message = sanitizeInput($this->input['message'] ?? '');
        $location = sanitizeInput($this->input['location'] ?? '');
        $latitude = isset($this->input['latitude']) ? (float)$this->input['latitude'] : null;
        $longitude = isset($this->input['longitude']) ? (float)$this->input['longitude'] : null;

        $allowedTypes = ['sos', 'fire', 'medical', 'security', 'natural_disaster', 'other'];
        if (!in_array($alertType, $allowedTypes)) {
            ApiResponse::error('Invalid alert type. Allowed: ' . implode(', ', $allowedTypes), 400);
        }

        $userId = $this->auth->getUserId();
        $flatId = $this->auth->getFlatId();

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_emergency_alert
                (society_id, user_id, flat_id, alert_type, message, location, latitude, longitude, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())"
        );
        $stmt->bind_param(
            'iiisssdd',
            $this->societyId, $userId, $flatId, $alertType,
            $message, $location, $latitude, $longitude
        );

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to trigger emergency alert', 500);
        }

        $alertId = $stmt->insert_id;
        $stmt->close();

        // Fetch user name for notification
        $userStmt = $this->conn->prepare("SELECT name FROM tbl_user WHERE id = ?");
        $userStmt->bind_param('i', $userId);
        $userStmt->execute();
        $userName = $userStmt->get_result()->fetch_assoc()['name'] ?? 'A resident';
        $userStmt->close();

        $notifTitle = strtoupper($alertType) . ' EMERGENCY ALERT';
        $notifBody = $userName . ' has triggered a ' . $alertType . ' alert.';
        if (!empty($message)) {
            $notifBody .= ' Message: ' . $message;
        }

        // Get all society members and guards for notifications
        $memberStmt = $this->conn->prepare(
            "SELECT u.id as user_id, u.fcm_token
             FROM tbl_user u
             INNER JOIN tbl_resident r ON r.user_id = u.id
             WHERE r.society_id = ? AND r.status = 'approved' AND u.status = 'active' AND u.id != ?"
        );
        $memberStmt->bind_param('ii', $this->societyId, $userId);
        $memberStmt->execute();
        $memberResult = $memberStmt->get_result();

        $fcmTokens = [];
        while ($row = $memberResult->fetch_assoc()) {
            // Store in-app notification for each member
            storeNotification(
                $this->conn, $this->societyId, (int)$row['user_id'],
                $notifTitle, $notifBody, 'emergency', 'emergency_alert', $alertId
            );
            if (!empty($row['fcm_token'])) {
                $fcmTokens[] = $row['fcm_token'];
            }
        }
        $memberStmt->close();

        // Send bulk FCM
        if (!empty($fcmTokens)) {
            sendBulkFCMNotification($fcmTokens, $notifTitle, $notifBody, [
                'type' => 'emergency_alert',
                'alert_id' => $alertId,
                'alert_type' => $alertType,
            ]);
        }

        // Fetch and return the created alert
        $alert = $this->fetchAlert($alertId);
        ApiResponse::created($alert, 'Emergency alert triggered successfully');
    }

    // ─── GET /api/v1/emergency/alerts ───────────────────────────────────

    /**
     * List emergency alerts. Admin/guard sees all; residents see only their own.
     * Filters: status, alert_type. Paginated.
     */
    private function listAlerts() {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);
        $userId = $this->auth->getUserId();
        $isPrimary = $this->user['is_primary'];
        $isGuard = $this->auth->isGuard();

        $where = "a.society_id = ?";
        $params = [$this->societyId];
        $types = 'i';

        // Residents see only their own alerts
        if (!$isPrimary && !$isGuard) {
            $where .= " AND a.user_id = ?";
            $params[] = $userId;
            $types .= 'i';
        }

        // Filter by status
        if (!empty($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $where .= " AND a.status = ?";
            $params[] = $status;
            $types .= 's';
        }

        // Filter by alert_type
        if (!empty($this->input['alert_type'])) {
            $alertType = sanitizeInput($this->input['alert_type']);
            $where .= " AND a.alert_type = ?";
            $params[] = $alertType;
            $types .= 's';
        }

        // Count total
        $countStmt = $this->conn->prepare("SELECT COUNT(*) as total FROM tbl_emergency_alert a WHERE $where");
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch alerts
        $sql = "SELECT a.id, a.society_id, a.user_id, a.flat_id, a.alert_type,
                       a.message, a.location, a.latitude, a.longitude,
                       a.status, a.responded_by, a.resolved_at, a.created_at,
                       u.name as user_name, u.avatar as user_avatar,
                       f.flat_number,
                       ru.name as responded_by_name
                FROM tbl_emergency_alert a
                LEFT JOIN tbl_user u ON u.id = a.user_id
                LEFT JOIN tbl_flat f ON f.id = a.flat_id
                LEFT JOIN tbl_user ru ON ru.id = a.responded_by
                WHERE $where
                ORDER BY a.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $alerts = [];
        while ($row = $result->fetch_assoc()) {
            $alerts[] = $this->formatAlert($row);
        }
        $stmt->close();

        ApiResponse::paginated($alerts, $total, $page, $perPage, 'Emergency alerts retrieved successfully');
    }

    // ─── GET /api/v1/emergency/alerts/{id} ──────────────────────────────

    private function getAlert($id) {
        $alert = $this->fetchAlert($id);

        if (!$alert) {
            ApiResponse::notFound('Emergency alert not found');
        }

        $userId = $this->auth->getUserId();
        $isPrimary = $this->user['is_primary'];
        $isGuard = $this->auth->isGuard();

        // Residents can only view their own alerts
        if (!$isPrimary && !$isGuard && (int)$alert['user_id'] !== $userId) {
            ApiResponse::forbidden('You can only view your own alerts');
        }

        ApiResponse::success($alert, 'Emergency alert retrieved successfully');
    }

    // ─── PUT /api/v1/emergency/alerts/{id}/respond ──────────────────────

    /**
     * Mark alert as "responding". Guard or admin only.
     */
    private function respondToAlert($id) {
        $isPrimary = $this->user['is_primary'];
        $isGuard = $this->auth->isGuard();

        if (!$isPrimary && !$isGuard) {
            ApiResponse::forbidden('Only guards or admins can respond to alerts');
        }

        $alert = $this->findAlert($id);

        if ($alert['status'] !== 'active') {
            ApiResponse::error('Only active alerts can be responded to', 400);
        }

        $userId = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "UPDATE tbl_emergency_alert SET status = 'responding', responded_by = ? WHERE id = ?"
        );
        $stmt->bind_param('ii', $userId, $id);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to update alert', 500);
        }
        $stmt->close();

        // Notify the alert creator
        $responderStmt = $this->conn->prepare("SELECT name FROM tbl_user WHERE id = ?");
        $responderStmt->bind_param('i', $userId);
        $responderStmt->execute();
        $responderName = $responderStmt->get_result()->fetch_assoc()['name'] ?? 'Someone';
        $responderStmt->close();

        storeNotification(
            $this->conn, $this->societyId, (int)$alert['user_id'],
            'Help is on the way',
            $responderName . ' is responding to your emergency alert.',
            'emergency', 'emergency_alert', $id
        );

        $updatedAlert = $this->fetchAlert($id);
        ApiResponse::success($updatedAlert, 'Alert marked as responding');
    }

    // ─── PUT /api/v1/emergency/alerts/{id}/resolve ──────────────────────

    /**
     * Resolve alert. Guard or admin only. Requires resolution notes.
     */
    private function resolveAlert($id) {
        $isPrimary = $this->user['is_primary'];
        $isGuard = $this->auth->isGuard();

        if (!$isPrimary && !$isGuard) {
            ApiResponse::forbidden('Only guards or admins can resolve alerts');
        }

        $alert = $this->findAlert($id);

        $resolutionNote = sanitizeInput($this->input['resolution_note'] ?? '');
        if (empty($resolutionNote)) {
            ApiResponse::error('Resolution note is required', 400);
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_emergency_alert SET status = 'resolved', resolved_at = NOW() WHERE id = ?"
        );
        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to resolve alert', 500);
        }
        $stmt->close();

        // Notify the alert creator
        storeNotification(
            $this->conn, $this->societyId, (int)$alert['user_id'],
            'Emergency Resolved',
            'Your emergency alert has been resolved. Note: ' . $resolutionNote,
            'emergency', 'emergency_alert', $id
        );

        $updatedAlert = $this->fetchAlert($id);
        ApiResponse::success($updatedAlert, 'Alert resolved successfully');
    }

    // ─── PUT /api/v1/emergency/alerts/{id}/false-alarm ──────────────────

    /**
     * Mark alert as false alarm. Admin only.
     */
    private function markFalseAlarm($id) {
        $this->auth->requirePrimary();

        $alert = $this->findAlert($id);

        $stmt = $this->conn->prepare(
            "UPDATE tbl_emergency_alert SET status = 'false_alarm', resolved_at = NOW() WHERE id = ?"
        );
        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to update alert', 500);
        }
        $stmt->close();

        // Notify all society members that this was a false alarm
        $memberStmt = $this->conn->prepare(
            "SELECT u.id as user_id
             FROM tbl_user u
             INNER JOIN tbl_resident r ON r.user_id = u.id
             WHERE r.society_id = ? AND r.status = 'approved' AND u.status = 'active'"
        );
        $memberStmt->bind_param('i', $this->societyId);
        $memberStmt->execute();
        $memberResult = $memberStmt->get_result();

        while ($row = $memberResult->fetch_assoc()) {
            storeNotification(
                $this->conn, $this->societyId, (int)$row['user_id'],
                'False Alarm',
                'A recent ' . $alert['alert_type'] . ' alert has been marked as a false alarm.',
                'emergency', 'emergency_alert', $id
            );
        }
        $memberStmt->close();

        $updatedAlert = $this->fetchAlert($id);
        ApiResponse::success($updatedAlert, 'Alert marked as false alarm');
    }

    // ─── Emergency contacts ─────────────────────────────────────────────

    private function handleContacts($method, $id) {
        switch ($method) {
            case 'GET':
                $this->listContacts();
                break;

            case 'POST':
                $this->createContact();
                break;

            case 'PUT':
                if (!$id) {
                    ApiResponse::error('Contact ID is required', 400);
                }
                $this->updateContact($id);
                break;

            case 'DELETE':
                if (!$id) {
                    ApiResponse::error('Contact ID is required', 400);
                }
                $this->deleteContact($id);
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ─── GET /api/v1/emergency/contacts ─────────────────────────────────

    /**
     * List emergency contacts for the society.
     */
    private function listContacts() {
        $stmt = $this->conn->prepare(
            "SELECT id, society_id, name, phone, type, is_active, created_at
             FROM tbl_emergency_contact
             WHERE society_id = ? AND is_active = 1
             ORDER BY type ASC, name ASC"
        );
        $stmt->bind_param('i', $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        $contacts = [];
        while ($row = $result->fetch_assoc()) {
            $contacts[] = [
                'id' => (int)$row['id'],
                'society_id' => (int)$row['society_id'],
                'name' => $row['name'],
                'phone' => $row['phone'],
                'type' => $row['type'],
                'is_active' => (bool)$row['is_active'],
                'created_at' => $row['created_at'],
            ];
        }
        $stmt->close();

        ApiResponse::success($contacts, 'Emergency contacts retrieved successfully');
    }

    // ─── POST /api/v1/emergency/contacts ────────────────────────────────

    /**
     * Add an emergency contact. Admin only.
     */
    private function createContact() {
        $this->auth->requirePrimary();

        $name = sanitizeInput($this->input['name'] ?? '');
        $phone = sanitizeInput($this->input['phone'] ?? '');
        $type = sanitizeInput($this->input['type'] ?? '');

        if (empty($name)) {
            ApiResponse::error('Contact name is required', 400);
        }
        if (empty($phone)) {
            ApiResponse::error('Phone number is required', 400);
        }

        $allowedTypes = ['police', 'fire', 'ambulance', 'hospital', 'society_security', 'other'];
        if (!in_array($type, $allowedTypes)) {
            ApiResponse::error('Invalid contact type. Allowed: ' . implode(', ', $allowedTypes), 400);
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_emergency_contact (society_id, name, phone, type, is_active, created_at)
             VALUES (?, ?, ?, ?, 1, NOW())"
        );
        $stmt->bind_param('isss', $this->societyId, $name, $phone, $type);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to add emergency contact', 500);
        }

        $contactId = $stmt->insert_id;
        $stmt->close();

        $contact = [
            'id' => $contactId,
            'society_id' => $this->societyId,
            'name' => $name,
            'phone' => $phone,
            'type' => $type,
            'is_active' => true,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        ApiResponse::created($contact, 'Emergency contact added successfully');
    }

    // ─── PUT /api/v1/emergency/contacts/{id} ────────────────────────────

    /**
     * Update an emergency contact. Admin only.
     */
    private function updateContact($id) {
        $this->auth->requirePrimary();

        // Verify contact exists
        $checkStmt = $this->conn->prepare(
            "SELECT id FROM tbl_emergency_contact WHERE id = ? AND society_id = ? AND is_active = 1"
        );
        $checkStmt->bind_param('ii', $id, $this->societyId);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Emergency contact not found');
        }
        $checkStmt->close();

        $fields = [];
        $params = [];
        $types = '';

        if (isset($this->input['name'])) {
            $fields[] = 'name = ?';
            $params[] = sanitizeInput($this->input['name']);
            $types .= 's';
        }

        if (isset($this->input['phone'])) {
            $fields[] = 'phone = ?';
            $params[] = sanitizeInput($this->input['phone']);
            $types .= 's';
        }

        if (isset($this->input['type'])) {
            $type = sanitizeInput($this->input['type']);
            $allowedTypes = ['police', 'fire', 'ambulance', 'hospital', 'society_security', 'other'];
            if (!in_array($type, $allowedTypes)) {
                ApiResponse::error('Invalid contact type. Allowed: ' . implode(', ', $allowedTypes), 400);
            }
            $fields[] = 'type = ?';
            $params[] = $type;
            $types .= 's';
        }

        if (empty($fields)) {
            ApiResponse::error('No fields to update', 400);
        }

        $sql = "UPDATE tbl_emergency_contact SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to update emergency contact', 500);
        }
        $stmt->close();

        // Fetch updated contact
        $fetchStmt = $this->conn->prepare(
            "SELECT id, society_id, name, phone, type, is_active, created_at
             FROM tbl_emergency_contact WHERE id = ?"
        );
        $fetchStmt->bind_param('i', $id);
        $fetchStmt->execute();
        $row = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        $contact = [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'name' => $row['name'],
            'phone' => $row['phone'],
            'type' => $row['type'],
            'is_active' => (bool)$row['is_active'],
            'created_at' => $row['created_at'],
        ];

        ApiResponse::success($contact, 'Emergency contact updated successfully');
    }

    // ─── DELETE /api/v1/emergency/contacts/{id} ─────────────────────────

    /**
     * Soft delete an emergency contact. Admin only.
     */
    private function deleteContact($id) {
        $this->auth->requirePrimary();

        $checkStmt = $this->conn->prepare(
            "SELECT id FROM tbl_emergency_contact WHERE id = ? AND society_id = ? AND is_active = 1"
        );
        $checkStmt->bind_param('ii', $id, $this->societyId);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Emergency contact not found');
        }
        $checkStmt->close();

        $stmt = $this->conn->prepare(
            "UPDATE tbl_emergency_contact SET is_active = 0 WHERE id = ?"
        );
        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to delete emergency contact', 500);
        }
        $stmt->close();

        ApiResponse::success(null, 'Emergency contact deleted successfully');
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * Find alert by ID within society. Returns raw row or exits 404.
     */
    private function findAlert($id) {
        $stmt = $this->conn->prepare(
            "SELECT id, user_id, alert_type, status
             FROM tbl_emergency_alert
             WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Emergency alert not found');
        }

        $alert = $result->fetch_assoc();
        $stmt->close();

        return $alert;
    }

    /**
     * Fetch a fully formatted alert by ID.
     */
    private function fetchAlert($id) {
        $stmt = $this->conn->prepare(
            "SELECT a.id, a.society_id, a.user_id, a.flat_id, a.alert_type,
                    a.message, a.location, a.latitude, a.longitude,
                    a.status, a.responded_by, a.resolved_at, a.created_at,
                    u.name as user_name, u.avatar as user_avatar,
                    f.flat_number,
                    ru.name as responded_by_name
             FROM tbl_emergency_alert a
             LEFT JOIN tbl_user u ON u.id = a.user_id
             LEFT JOIN tbl_flat f ON f.id = a.flat_id
             LEFT JOIN tbl_user ru ON ru.id = a.responded_by
             WHERE a.id = ? AND a.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            return null;
        }

        $row = $result->fetch_assoc();
        $stmt->close();

        return $this->formatAlert($row);
    }

    /**
     * Format an alert row for API output.
     */
    private function formatAlert($row) {
        return [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'user' => [
                'id' => (int)$row['user_id'],
                'name' => $row['user_name'],
                'avatar' => $row['user_avatar'] ?? null,
            ],
            'flat_id' => $row['flat_id'] !== null ? (int)$row['flat_id'] : null,
            'flat_number' => $row['flat_number'] ?? null,
            'alert_type' => $row['alert_type'],
            'message' => $row['message'],
            'location' => $row['location'],
            'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
            'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
            'status' => $row['status'],
            'responded_by' => $row['responded_by'] !== null ? [
                'id' => (int)$row['responded_by'],
                'name' => $row['responded_by_name'] ?? null,
            ] : null,
            'resolved_at' => $row['resolved_at'],
            'created_at' => $row['created_at'],
        ];
    }
}
