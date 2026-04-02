<?php
/**
 * Securis Smart Society Platform — Access Handler (USP)
 * Endpoints for digital keys, access verification, access points, and access logs.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class AccessHandler {
    private $conn;
    private $auth;
    private $input;

    public function __construct($conn, $auth, $input) {
        $this->conn = $conn;
        $this->auth = $auth;
        $this->input = $input;
    }

    /**
     * Route: /api/v1/access/{action}/{id}
     *
     * GET    /my-keys              — List active digital keys for current user
     * POST   /generate-key         — Generate a temporary digital key
     * POST   /verify               — Verify a QR code or PIN for access
     * POST   /unlock               — Software unlock (placeholder for IoT)
     * GET    /logs                  — Access log history (paginated)
     * GET    /points               — List access points in society
     * POST   /points               — Create access point (primary only)
     * PUT    /points/{id}          — Update access point (primary only)
     * PUT    /keys/{id}/revoke     — Revoke a digital key
     */
    public function handle($method, $action, $id) {
        switch ($method) {
            case 'GET':
                if ($action === 'my-keys') {
                    $this->getMyKeys();
                } elseif ($action === 'logs') {
                    $this->getLogs();
                } elseif ($action === 'points') {
                    $this->getPoints();
                } else {
                    ApiResponse::notFound('Access endpoint not found');
                }
                break;

            case 'POST':
                if ($action === 'generate-key') {
                    $this->generateKey();
                } elseif ($action === 'verify') {
                    $this->verifyAccess();
                } elseif ($action === 'unlock') {
                    $this->unlock();
                } elseif ($action === 'points') {
                    $this->createPoint();
                } else {
                    ApiResponse::error('Method not allowed', 405);
                }
                break;

            case 'PUT':
                if ($action === 'points' && $id) {
                    $this->updatePoint($id);
                } elseif ($action === 'keys' && $id) {
                    $this->revokeKey($id);
                } else {
                    ApiResponse::error('Method not allowed', 405);
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // =========================================================================
    //  GET /access/my-keys
    // =========================================================================

    /**
     * List active digital keys for the current user.
     * Returns keys where issued_to = user_id, is_active = 1, valid_until > NOW().
     * Includes access point name. Ordered by created_at DESC.
     */
    private function getMyKeys() {
        $user = $this->auth->authenticate();
        $userId = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "SELECT dk.id, dk.access_point_id, ap.name AS access_point_name,
                    dk.key_type, dk.qr_code, dk.pin_code, dk.label,
                    dk.valid_from, dk.valid_until, dk.is_active,
                    dk.used_count, dk.max_uses, dk.created_at
             FROM tbl_digital_key dk
             INNER JOIN tbl_access_point ap ON ap.id = dk.access_point_id
             WHERE dk.issued_to = ? AND dk.is_active = 1 AND dk.valid_until > NOW()
             ORDER BY dk.created_at DESC"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $keys = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['access_point_id'] = (int)$row['access_point_id'];
            $row['is_active'] = (bool)$row['is_active'];
            $row['used_count'] = (int)$row['used_count'];
            $row['max_uses'] = (int)$row['max_uses'];
            $keys[] = $row;
        }

        ApiResponse::success($keys, 'Digital keys retrieved');
    }

    // =========================================================================
    //  POST /access/generate-key
    // =========================================================================

    /**
     * Generate a temporary digital key with QR code and PIN.
     * Input: access_point_id, key_type, valid_hours, max_uses, label.
     */
    private function generateKey() {
        $user = $this->auth->authenticate();
        $userId = $this->auth->getUserId();
        $societyId = $this->auth->requireSociety();

        $accessPointId = isset($this->input['access_point_id']) ? (int)$this->input['access_point_id'] : 0;
        $keyType = sanitizeInput($this->input['key_type'] ?? 'temporary');
        $validHours = isset($this->input['valid_hours']) ? (int)$this->input['valid_hours'] : 24;
        $maxUses = isset($this->input['max_uses']) ? (int)$this->input['max_uses'] : 0;
        $label = sanitizeInput($this->input['label'] ?? '');

        if ($accessPointId <= 0) {
            ApiResponse::error('Valid access_point_id is required');
        }

        $allowedTypes = ['temporary', 'one_time', 'scheduled'];
        if (!in_array($keyType, $allowedTypes)) {
            ApiResponse::error('Invalid key_type. Allowed: temporary, one_time, scheduled');
        }

        if ($validHours <= 0 || $validHours > 8760) {
            ApiResponse::error('valid_hours must be between 1 and 8760');
        }

        // Verify access point belongs to user's society
        $apStmt = $this->conn->prepare(
            "SELECT id, name FROM tbl_access_point WHERE id = ? AND society_id = ?"
        );
        $apStmt->bind_param('ii', $accessPointId, $societyId);
        $apStmt->execute();
        $apResult = $apStmt->get_result();

        if ($apResult->num_rows === 0) {
            ApiResponse::notFound('Access point not found in your society');
        }

        $accessPoint = $apResult->fetch_assoc();

        // Generate QR code and PIN
        $qrCode = generateQRCode();
        $pinCode = generatePinCode();

        // Set validity window
        $validFrom = date('Y-m-d H:i:s');
        $validUntil = date('Y-m-d H:i:s', strtotime("+{$validHours} hours"));

        // If one_time, force max_uses = 1
        if ($keyType === 'one_time') {
            $maxUses = 1;
        }

        // Insert the digital key
        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_digital_key
                (access_point_id, issued_to, issued_by, key_type, qr_code, pin_code, label,
                 valid_from, valid_until, is_active, used_count, max_uses)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?)"
        );
        $stmt->bind_param(
            'iiissssssi',
            $accessPointId, $userId, $userId, $keyType, $qrCode, $pinCode, $label,
            $validFrom, $validUntil, $maxUses
        );

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to generate digital key', 500);
        }

        $keyId = $stmt->insert_id;

        ApiResponse::created([
            'id' => $keyId,
            'access_point_id' => $accessPointId,
            'access_point_name' => $accessPoint['name'],
            'key_type' => $keyType,
            'qr_code' => $qrCode,
            'pin_code' => $pinCode,
            'label' => $label,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'max_uses' => $maxUses,
            'used_count' => 0
        ], 'Digital key generated successfully');
    }

    // =========================================================================
    //  POST /access/verify
    // =========================================================================

    /**
     * Verify a QR code or PIN for access.
     * Called by the guard app or future hardware.
     * Input: code, access_point_id, direction (entry/exit).
     */
    private function verifyAccess() {
        $user = $this->auth->authenticate();
        $userId = $this->auth->getUserId();

        $code = sanitizeInput($this->input['code'] ?? '');
        $accessPointId = isset($this->input['access_point_id']) ? (int)$this->input['access_point_id'] : 0;
        $direction = sanitizeInput($this->input['direction'] ?? 'entry');

        if (empty($code)) {
            ApiResponse::error('Code (QR or PIN) is required');
        }
        if ($accessPointId <= 0) {
            ApiResponse::error('Valid access_point_id is required');
        }
        if (!in_array($direction, ['entry', 'exit'])) {
            ApiResponse::error('Direction must be entry or exit');
        }

        // Get access point name for response
        $apStmt = $this->conn->prepare(
            "SELECT id, name FROM tbl_access_point WHERE id = ?"
        );
        $apStmt->bind_param('i', $accessPointId);
        $apStmt->execute();
        $apResult = $apStmt->get_result();

        if ($apResult->num_rows === 0) {
            ApiResponse::notFound('Access point not found');
        }

        $accessPoint = $apResult->fetch_assoc();

        // --- Try tbl_digital_key first ---
        $keyStmt = $this->conn->prepare(
            "SELECT dk.id, dk.issued_to, dk.key_type, dk.is_active,
                    dk.valid_from, dk.valid_until, dk.used_count, dk.max_uses,
                    u.name AS user_name
             FROM tbl_digital_key dk
             INNER JOIN tbl_user u ON u.id = dk.issued_to
             WHERE (dk.qr_code = ? OR dk.pin_code = ?)"
        );
        $keyStmt->bind_param('ss', $code, $code);
        $keyStmt->execute();
        $keyResult = $keyStmt->get_result();

        if ($keyResult->num_rows > 0) {
            $key = $keyResult->fetch_assoc();

            // Validate the key
            $now = date('Y-m-d H:i:s');
            $denied = false;
            $denyReason = '';

            if (!$key['is_active']) {
                $denied = true;
                $denyReason = 'Key is deactivated';
            } elseif ($key['valid_from'] > $now) {
                $denied = true;
                $denyReason = 'Key is not yet valid';
            } elseif ($key['valid_until'] < $now) {
                $denied = true;
                $denyReason = 'Key has expired';
            } elseif ((int)$key['max_uses'] > 0 && (int)$key['used_count'] >= (int)$key['max_uses']) {
                $denied = true;
                $denyReason = 'Key usage limit exceeded';
            }

            if ($denied) {
                // Log denied access
                $this->logAccess(
                    $accessPointId, (int)$key['issued_to'], (int)$key['id'], null,
                    'qr_pin', $direction, 'denied', $userId, $denyReason
                );
                ApiResponse::error($denyReason, 403);
            }

            // Access granted — increment used_count
            $updateStmt = $this->conn->prepare(
                "UPDATE tbl_digital_key SET used_count = used_count + 1 WHERE id = ?"
            );
            $keyId = (int)$key['id'];
            $updateStmt->bind_param('i', $keyId);
            $updateStmt->execute();

            // If one_time key, deactivate after use
            if ($key['key_type'] === 'one_time') {
                $deactivateStmt = $this->conn->prepare(
                    "UPDATE tbl_digital_key SET is_active = 0 WHERE id = ?"
                );
                $deactivateStmt->bind_param('i', $keyId);
                $deactivateStmt->execute();
            }

            // Log granted access
            $this->logAccess(
                $accessPointId, (int)$key['issued_to'], $keyId, null,
                'qr_pin', $direction, 'granted', $userId, null
            );

            ApiResponse::success([
                'status' => 'granted',
                'type' => 'resident_key',
                'user_name' => $key['user_name'],
                'access_point_name' => $accessPoint['name'],
                'key_type' => $key['key_type'],
                'direction' => $direction
            ], 'Access granted');
        }

        // --- Try tbl_visitor ---
        $visitorStmt = $this->conn->prepare(
            "SELECT v.id, v.name AS visitor_name, v.status
             FROM tbl_visitor v
             WHERE (v.qr_code = ? OR v.pin_code = ?)"
        );
        $visitorStmt->bind_param('ss', $code, $code);
        $visitorStmt->execute();
        $visitorResult = $visitorStmt->get_result();

        if ($visitorResult->num_rows > 0) {
            $visitor = $visitorResult->fetch_assoc();

            if ($visitor['status'] !== 'approved' && $visitor['status'] !== 'checked_in') {
                // Log denied visitor access
                $this->logAccess(
                    $accessPointId, null, null, (int)$visitor['id'],
                    'qr_pin', $direction, 'denied', $userId, 'Visitor status: ' . $visitor['status']
                );
                ApiResponse::error('Visitor access denied. Status: ' . $visitor['status'], 403);
            }

            // Log granted visitor access
            $this->logAccess(
                $accessPointId, null, null, (int)$visitor['id'],
                'qr_pin', $direction, 'granted', $userId, null
            );

            ApiResponse::success([
                'status' => 'granted',
                'type' => 'visitor',
                'visitor_name' => $visitor['visitor_name'],
                'access_point_name' => $accessPoint['name'],
                'direction' => $direction
            ], 'Visitor access granted');
        }

        // --- Not found in either table ---
        $this->logAccess(
            $accessPointId, null, null, null,
            'qr_pin', $direction, 'denied', $userId, 'Code not recognized: ' . $code
        );

        ApiResponse::error('Access denied. Code not recognized.', 403);
    }

    // =========================================================================
    //  POST /access/unlock
    // =========================================================================

    /**
     * Software unlock — placeholder for future IoT integration.
     * Logs an access entry with access_type='app'.
     * Requires the user to have a valid permanent key for the access point.
     */
    private function unlock() {
        $user = $this->auth->authenticate();
        $userId = $this->auth->getUserId();
        $societyId = $this->auth->requireSociety();

        $accessPointId = isset($this->input['access_point_id']) ? (int)$this->input['access_point_id'] : 0;

        if ($accessPointId <= 0) {
            ApiResponse::error('Valid access_point_id is required');
        }

        // Verify access point belongs to user's society
        $apStmt = $this->conn->prepare(
            "SELECT id, name FROM tbl_access_point WHERE id = ? AND society_id = ?"
        );
        $apStmt->bind_param('ii', $accessPointId, $societyId);
        $apStmt->execute();
        $apResult = $apStmt->get_result();

        if ($apResult->num_rows === 0) {
            ApiResponse::notFound('Access point not found in your society');
        }

        $accessPoint = $apResult->fetch_assoc();

        // Check user has a valid active key for this access point
        $keyStmt = $this->conn->prepare(
            "SELECT id FROM tbl_digital_key
             WHERE issued_to = ? AND access_point_id = ?
               AND is_active = 1 AND valid_from <= NOW() AND valid_until >= NOW()
             LIMIT 1"
        );
        $keyStmt->bind_param('ii', $userId, $accessPointId);
        $keyStmt->execute();
        $keyResult = $keyStmt->get_result();

        if ($keyResult->num_rows === 0) {
            ApiResponse::forbidden('No valid key found for this access point');
        }

        $key = $keyResult->fetch_assoc();

        // Log the app-based unlock
        $this->logAccess(
            $accessPointId, $userId, (int)$key['id'], null,
            'app', 'entry', 'granted', $userId, 'Software unlock via app'
        );

        ApiResponse::success([
            'status' => 'unlocked',
            'access_point_name' => $accessPoint['name'],
            'message' => 'Access point unlocked successfully'
        ], 'Unlock successful');
    }

    // =========================================================================
    //  GET /access/logs
    // =========================================================================

    /**
     * Access log history. Paginated.
     * Guards see all logs for their society.
     * Residents see only their own logs.
     * Filters: access_point_id, direction, date_from, date_to.
     */
    private function getLogs() {
        $user = $this->auth->authenticate();
        $userId = $this->auth->getUserId();
        $societyId = $this->auth->requireSociety();
        $isGuard = $this->auth->isGuard();

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        // Build WHERE conditions
        $conditions = ["ap.society_id = ?"];
        $params = [$societyId];
        $types = 'i';

        // Guards see all society logs; residents see only their own
        if (!$isGuard) {
            $conditions[] = "al.user_id = ?";
            $params[] = $userId;
            $types .= 'i';
        }

        // Optional filters
        if (!empty($this->input['access_point_id'])) {
            $conditions[] = "al.access_point_id = ?";
            $params[] = (int)$this->input['access_point_id'];
            $types .= 'i';
        }

        if (!empty($this->input['direction']) && in_array($this->input['direction'], ['entry', 'exit'])) {
            $conditions[] = "al.direction = ?";
            $params[] = $this->input['direction'];
            $types .= 's';
        }

        if (!empty($this->input['date_from'])) {
            $conditions[] = "al.timestamp >= ?";
            $params[] = sanitizeInput($this->input['date_from']);
            $types .= 's';
        }

        if (!empty($this->input['date_to'])) {
            $conditions[] = "al.timestamp <= ?";
            $params[] = sanitizeInput($this->input['date_to']) . ' 23:59:59';
            $types .= 's';
        }

        $whereClause = implode(' AND ', $conditions);

        // Count total matching records
        $countSql = "SELECT COUNT(*) AS total
                     FROM tbl_access_log al
                     INNER JOIN tbl_access_point ap ON ap.id = al.access_point_id
                     WHERE {$whereClause}";

        $countStmt = $this->conn->prepare($countSql);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];

        // Fetch paginated logs
        $dataSql = "SELECT al.id, al.access_point_id, ap.name AS access_point_name,
                           al.user_id, u.name AS user_name,
                           al.key_id, al.visitor_id, v.name AS visitor_name,
                           al.access_type, al.direction, al.status,
                           al.verified_by, al.notes, al.timestamp
                    FROM tbl_access_log al
                    INNER JOIN tbl_access_point ap ON ap.id = al.access_point_id
                    LEFT JOIN tbl_user u ON u.id = al.user_id
                    LEFT JOIN tbl_visitor v ON v.id = al.visitor_id
                    WHERE {$whereClause}
                    ORDER BY al.timestamp DESC
                    LIMIT ? OFFSET ?";

        $dataTypes = $types . 'ii';
        $dataParams = array_merge($params, [$perPage, $offset]);

        $dataStmt = $this->conn->prepare($dataSql);
        $dataStmt->bind_param($dataTypes, ...$dataParams);
        $dataStmt->execute();
        $result = $dataStmt->get_result();

        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['access_point_id'] = (int)$row['access_point_id'];
            $row['user_id'] = $row['user_id'] !== null ? (int)$row['user_id'] : null;
            $row['key_id'] = $row['key_id'] !== null ? (int)$row['key_id'] : null;
            $row['visitor_id'] = $row['visitor_id'] !== null ? (int)$row['visitor_id'] : null;
            $row['verified_by'] = $row['verified_by'] !== null ? (int)$row['verified_by'] : null;
            $logs[] = $row;
        }

        ApiResponse::paginated($logs, $total, $page, $perPage, 'Access logs retrieved');
    }

    // =========================================================================
    //  GET /access/points
    // =========================================================================

    /**
     * List access points in the user's society.
     */
    private function getPoints() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        $stmt = $this->conn->prepare(
            "SELECT id, society_id, name, type, location, device_id, status
             FROM tbl_access_point
             WHERE society_id = ?
             ORDER BY name ASC"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        $points = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['society_id'] = (int)$row['society_id'];
            $points[] = $row;
        }

        ApiResponse::success($points, 'Access points retrieved');
    }

    // =========================================================================
    //  POST /access/points
    // =========================================================================

    /**
     * Create a new access point. Only primary owners can do this.
     * Input: name, type (main_gate/tower_gate/door/parking_gate), location.
     */
    private function createPoint() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $name = sanitizeInput($this->input['name'] ?? '');
        $type = sanitizeInput($this->input['type'] ?? '');
        $location = sanitizeInput($this->input['location'] ?? '');

        if (empty($name)) {
            ApiResponse::error('Access point name is required');
        }

        $allowedTypes = ['main_gate', 'tower_gate', 'door', 'parking_gate'];
        if (!in_array($type, $allowedTypes)) {
            ApiResponse::error('Invalid type. Allowed: main_gate, tower_gate, door, parking_gate');
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_access_point (society_id, name, type, location, status)
             VALUES (?, ?, ?, ?, 'active')"
        );
        $stmt->bind_param('isss', $societyId, $name, $type, $location);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to create access point', 500);
        }

        $pointId = $stmt->insert_id;

        ApiResponse::created([
            'id' => $pointId,
            'society_id' => $societyId,
            'name' => $name,
            'type' => $type,
            'location' => $location,
            'status' => 'active'
        ], 'Access point created successfully');
    }

    // =========================================================================
    //  PUT /access/points/{id}
    // =========================================================================

    /**
     * Update an access point. Only primary owners can do this.
     * Input: name, type, location, status.
     */
    private function updatePoint($pointId) {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $pointId = (int)$pointId;

        // Verify access point belongs to user's society
        $checkStmt = $this->conn->prepare(
            "SELECT id, name, type, location, status FROM tbl_access_point WHERE id = ? AND society_id = ?"
        );
        $checkStmt->bind_param('ii', $pointId, $societyId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows === 0) {
            ApiResponse::notFound('Access point not found in your society');
        }

        $existing = $checkResult->fetch_assoc();

        // Build update fields
        $name = sanitizeInput($this->input['name'] ?? $existing['name']);
        $type = sanitizeInput($this->input['type'] ?? $existing['type']);
        $location = sanitizeInput($this->input['location'] ?? $existing['location']);
        $status = sanitizeInput($this->input['status'] ?? $existing['status']);

        $allowedTypes = ['main_gate', 'tower_gate', 'door', 'parking_gate'];
        if (!in_array($type, $allowedTypes)) {
            ApiResponse::error('Invalid type. Allowed: main_gate, tower_gate, door, parking_gate');
        }

        $allowedStatuses = ['active', 'inactive', 'maintenance'];
        if (!in_array($status, $allowedStatuses)) {
            ApiResponse::error('Invalid status. Allowed: active, inactive, maintenance');
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_access_point SET name = ?, type = ?, location = ?, status = ? WHERE id = ?"
        );
        $stmt->bind_param('ssssi', $name, $type, $location, $status, $pointId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to update access point', 500);
        }

        ApiResponse::success([
            'id' => $pointId,
            'society_id' => $societyId,
            'name' => $name,
            'type' => $type,
            'location' => $location,
            'status' => $status
        ], 'Access point updated successfully');
    }

    // =========================================================================
    //  PUT /access/keys/{id}/revoke
    // =========================================================================

    /**
     * Revoke (deactivate) a digital key.
     * Only the issuer or a primary owner can revoke.
     */
    private function revokeKey($keyId) {
        $user = $this->auth->authenticate();
        $userId = $this->auth->getUserId();
        $societyId = $this->auth->requireSociety();

        $keyId = (int)$keyId;

        // Fetch the key and verify it belongs to the user's society
        $stmt = $this->conn->prepare(
            "SELECT dk.id, dk.issued_by, dk.issued_to, dk.is_active, ap.society_id
             FROM tbl_digital_key dk
             INNER JOIN tbl_access_point ap ON ap.id = dk.access_point_id
             WHERE dk.id = ?"
        );
        $stmt->bind_param('i', $keyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Digital key not found');
        }

        $key = $result->fetch_assoc();

        // Verify the key belongs to the user's society
        if ((int)$key['society_id'] !== $societyId) {
            ApiResponse::forbidden('This key does not belong to your society');
        }

        // Only the issuer or a primary owner can revoke
        $isPrimary = $this->auth->getUser()['is_primary'];
        if ((int)$key['issued_by'] !== $userId && !$isPrimary) {
            ApiResponse::forbidden('Only the key issuer or a primary owner can revoke this key');
        }

        if (!$key['is_active']) {
            ApiResponse::error('Key is already deactivated');
        }

        // Deactivate the key
        $updateStmt = $this->conn->prepare(
            "UPDATE tbl_digital_key SET is_active = 0 WHERE id = ?"
        );
        $updateStmt->bind_param('i', $keyId);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to revoke key', 500);
        }

        ApiResponse::success([
            'id' => $keyId,
            'is_active' => false
        ], 'Digital key revoked successfully');
    }

    // =========================================================================
    //  Helper: Log access entry
    // =========================================================================

    /**
     * Insert a record into tbl_access_log.
     */
    private function logAccess($accessPointId, $userId, $keyId, $visitorId, $accessType, $direction, $status, $verifiedBy, $notes) {
        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_access_log
                (access_point_id, user_id, key_id, visitor_id, access_type, direction, status, verified_by, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'iiiisssis',
            $accessPointId, $userId, $keyId, $visitorId,
            $accessType, $direction, $status, $verifiedBy, $notes
        );
        $stmt->execute();
        return $stmt->insert_id;
    }
}
