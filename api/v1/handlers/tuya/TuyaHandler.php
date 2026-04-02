<?php
/**
 * Securis Smart Society Platform — Tuya Smart Lock Handler
 * Endpoints for Tuya device binding, sharing, logging, and configuration.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class TuyaHandler {
    private $conn;
    private $auth;
    private $input;

    public function __construct($conn, $auth, $input) {
        $this->conn = $conn;
        $this->auth = $auth;
        $this->input = $input;
    }

    /**
     * Route: /api/v1/tuya/{action}/{id}
     *
     * POST   /bind-device          — Bind a Tuya device to an access point
     * POST   /unbind-device        — Unbind a Tuya device from an access point
     * GET    /my-devices           — List user's bound + shared devices
     * POST   /log-action           — Log a Tuya lock action from the app
     * GET    /device-history/{id}  — Paginated history for an access point
     * POST   /share-device         — Share common device to a resident
     * POST   /revoke-share         — Revoke device share
     * GET    /society-config       — Get society's Tuya home config
     * POST   /society-config       — Set society's Tuya home config
     * GET    /check-device         — Check if a tuya_device_id is already bound
     */
    public function handle($method, $action, $id) {
        switch ($method) {
            case 'GET':
                if ($action === 'my-devices') {
                    $this->getMyDevices();
                } elseif ($action === 'device-history' && $id) {
                    $this->getDeviceHistory($id);
                } elseif ($action === 'society-config') {
                    $this->getSocietyConfig();
                } elseif ($action === 'check-device') {
                    $this->checkDevice();
                } else {
                    ApiResponse::notFound('Tuya endpoint not found');
                }
                break;

            case 'POST':
                if ($action === 'bind-device') {
                    $this->bindDevice();
                } elseif ($action === 'unbind-device') {
                    $this->unbindDevice();
                } elseif ($action === 'log-action') {
                    $this->logAction();
                } elseif ($action === 'share-device') {
                    $this->shareDevice();
                } elseif ($action === 'revoke-share') {
                    $this->revokeShare();
                } elseif ($action === 'society-config') {
                    $this->setSocietyConfig();
                } else {
                    ApiResponse::error('Method not allowed', 405);
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // =========================================================================
    //  POST /tuya/bind-device
    // =========================================================================

    /**
     * Bind a Tuya device to an access point.
     * If access_point_id is provided, updates the existing access point.
     * If not provided, creates a new access point.
     * Auto-generates a permanent digital key for the binding user.
     */
    private function bindDevice() {
        $user = $this->auth->authenticate();
        $userId = $this->auth->getUserId();
        $societyId = $this->auth->requireSociety();

        $tuyaDeviceId = sanitizeInput($this->input['tuya_device_id'] ?? '');
        $accessPointId = isset($this->input['access_point_id']) ? (int)$this->input['access_point_id'] : 0;
        $tuyaHomeId = sanitizeInput($this->input['tuya_home_id'] ?? '');
        $deviceCategory = sanitizeInput($this->input['device_category'] ?? 'smart_lock');
        $isCommon = isset($this->input['is_common']) ? (int)$this->input['is_common'] : 0;
        $flatId = isset($this->input['flat_id']) ? (int)$this->input['flat_id'] : null;
        $name = sanitizeInput($this->input['name'] ?? '');
        $location = sanitizeInput($this->input['location'] ?? '');

        if (empty($tuyaDeviceId)) {
            ApiResponse::error('tuya_device_id is required');
        }

        // Check if tuya_device_id already bound
        $checkStmt = $this->conn->prepare(
            "SELECT id, name FROM tbl_access_point WHERE tuya_device_id = ?"
        );
        $checkStmt->bind_param('s', $tuyaDeviceId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows > 0) {
            $existing = $checkResult->fetch_assoc();
            ApiResponse::error('Device already bound to access point: ' . $existing['name'], 409);
        }

        $now = date('Y-m-d H:i:s');

        if ($accessPointId > 0) {
            // Update existing access point — verify it belongs to user's society
            $apStmt = $this->conn->prepare(
                "SELECT id FROM tbl_access_point WHERE id = ? AND society_id = ?"
            );
            $apStmt->bind_param('ii', $accessPointId, $societyId);
            $apStmt->execute();
            $apResult = $apStmt->get_result();

            if ($apResult->num_rows === 0) {
                ApiResponse::notFound('Access point not found in your society');
            }

            $updateStmt = $this->conn->prepare(
                "UPDATE tbl_access_point
                 SET tuya_device_id = ?, tuya_home_id = ?, device_category = ?,
                     is_common = ?, flat_id = ?, bound_by = ?, bound_at = ?
                 WHERE id = ?"
            );
            $updateStmt->bind_param('sssiiisi', $tuyaDeviceId, $tuyaHomeId, $deviceCategory,
                $isCommon, $flatId, $userId, $now, $accessPointId);

            if (!$updateStmt->execute()) {
                ApiResponse::error('Failed to bind device', 500);
            }
        } else {
            // Create new access point
            if (empty($name)) {
                $name = 'Tuya Lock ' . substr($tuyaDeviceId, -6);
            }

            $insertStmt = $this->conn->prepare(
                "INSERT INTO tbl_access_point
                    (society_id, name, type, location, status, tuya_device_id, tuya_home_id,
                     device_category, is_common, flat_id, bound_by, bound_at)
                 VALUES (?, ?, 'door', ?, 'active', ?, ?, ?, ?, ?, ?, ?)"
            );
            $insertStmt->bind_param('isssssiiis', $societyId, $name, $location, $tuyaDeviceId,
                $tuyaHomeId, $deviceCategory, $isCommon, $flatId, $userId, $now);

            if (!$insertStmt->execute()) {
                ApiResponse::error('Failed to create access point', 500);
            }

            $accessPointId = $insertStmt->insert_id;
        }

        // Auto-generate a permanent digital key for the binding user
        $qrCode = generateQRCode();
        $pinCode = generatePinCode();
        $validFrom = $now;
        $validUntil = '2099-12-31 23:59:59';

        $keyStmt = $this->conn->prepare(
            "INSERT INTO tbl_digital_key
                (access_point_id, issued_to, issued_by, key_type, qr_code, pin_code, label,
                 valid_from, valid_until, is_active, used_count, max_uses)
             VALUES (?, ?, ?, 'permanent', ?, ?, 'Tuya Device Owner Key', ?, ?, 1, 0, 0)"
        );
        $keyStmt->bind_param('iiissss', $accessPointId, $userId, $userId,
            $qrCode, $pinCode, $validFrom, $validUntil);
        $keyStmt->execute();
        $keyId = $keyStmt->insert_id;

        ApiResponse::created([
            'access_point_id' => $accessPointId,
            'tuya_device_id' => $tuyaDeviceId,
            'bound_by' => $userId,
            'bound_at' => $now,
            'digital_key' => [
                'id' => $keyId,
                'qr_code' => $qrCode,
                'pin_code' => $pinCode,
                'valid_until' => $validUntil
            ]
        ], 'Tuya device bound successfully');
    }

    // =========================================================================
    //  POST /tuya/unbind-device
    // =========================================================================

    /**
     * Unbind a Tuya device from an access point.
     * Only the user who bound the device or a primary/committee member can unbind.
     * Clears Tuya fields and revokes all shares.
     */
    private function unbindDevice() {
        $user = $this->auth->authenticate();
        $userId = $this->auth->getUserId();
        $societyId = $this->auth->requireSociety();

        $accessPointId = isset($this->input['access_point_id']) ? (int)$this->input['access_point_id'] : 0;

        if ($accessPointId <= 0) {
            ApiResponse::error('Valid access_point_id is required');
        }

        // Fetch access point and verify society
        $apStmt = $this->conn->prepare(
            "SELECT id, bound_by, tuya_device_id FROM tbl_access_point
             WHERE id = ? AND society_id = ?"
        );
        $apStmt->bind_param('ii', $accessPointId, $societyId);
        $apStmt->execute();
        $apResult = $apStmt->get_result();

        if ($apResult->num_rows === 0) {
            ApiResponse::notFound('Access point not found in your society');
        }

        $ap = $apResult->fetch_assoc();

        if (empty($ap['tuya_device_id'])) {
            ApiResponse::error('This access point has no Tuya device bound');
        }

        // Only bound_by user or primary/committee can unbind
        $isPrimary = $this->auth->getUser()['is_primary'] ?? false;
        if ((int)$ap['bound_by'] !== $userId && !$isPrimary) {
            ApiResponse::forbidden('Only the device owner or a committee member can unbind this device');
        }

        // Clear Tuya fields on access point
        $clearStmt = $this->conn->prepare(
            "UPDATE tbl_access_point
             SET tuya_device_id = NULL, tuya_home_id = NULL, device_category = NULL,
                 is_common = 0, bound_by = NULL, bound_at = NULL
             WHERE id = ?"
        );
        $clearStmt->bind_param('i', $accessPointId);

        if (!$clearStmt->execute()) {
            ApiResponse::error('Failed to unbind device', 500);
        }

        // Revoke all active shares for this access point
        $now = date('Y-m-d H:i:s');
        $revokeStmt = $this->conn->prepare(
            "UPDATE tbl_tuya_device_share
             SET status = 'revoked', revoked_at = ?
             WHERE access_point_id = ? AND status = 'active'"
        );
        $revokeStmt->bind_param('si', $now, $accessPointId);
        $revokeStmt->execute();

        ApiResponse::success([
            'access_point_id' => $accessPointId,
            'shares_revoked' => $revokeStmt->affected_rows
        ], 'Tuya device unbound successfully');
    }

    // =========================================================================
    //  GET /tuya/my-devices
    // =========================================================================

    /**
     * Get user's bound + shared Tuya devices.
     * Returns personal devices (bound_by = user OR flat matches) and common shared devices.
     * Includes access_level: 'owner', 'resident', 'shared'.
     */
    private function getMyDevices() {
        $user = $this->auth->authenticate();
        $userId = $this->auth->getUserId();
        $societyId = $this->auth->requireSociety();

        // Get user's flat_id from tbl_resident
        $resStmt = $this->conn->prepare(
            "SELECT flat_id FROM tbl_resident WHERE user_id = ? AND society_id = ? AND status = 'approved' LIMIT 1"
        );
        $resStmt->bind_param('ii', $userId, $societyId);
        $resStmt->execute();
        $resResult = $resStmt->get_result();
        $flatId = 0;
        if ($resResult->num_rows > 0) {
            $flatId = (int)$resResult->fetch_assoc()['flat_id'];
        }

        // Fetch devices the user owns (bound_by = user)
        $ownedStmt = $this->conn->prepare(
            "SELECT ap.id, ap.name, ap.type, ap.location, ap.status, ap.tuya_device_id,
                    ap.tuya_home_id, ap.device_category, ap.is_common, ap.flat_id,
                    ap.bound_by, ap.bound_at, 'owner' AS access_level
             FROM tbl_access_point ap
             WHERE ap.society_id = ? AND ap.tuya_device_id IS NOT NULL AND ap.bound_by = ?
             ORDER BY ap.name ASC"
        );
        $ownedStmt->bind_param('ii', $societyId, $userId);
        $ownedStmt->execute();
        $ownedResult = $ownedStmt->get_result();

        $devices = [];
        $seenIds = [];
        while ($row = $ownedResult->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['is_common'] = (bool)$row['is_common'];
            $row['flat_id'] = $row['flat_id'] !== null ? (int)$row['flat_id'] : null;
            $row['bound_by'] = $row['bound_by'] !== null ? (int)$row['bound_by'] : null;
            $devices[] = $row;
            $seenIds[] = $row['id'];
        }

        // Fetch devices in user's flat (resident access) that user doesn't own
        if ($flatId > 0) {
            $flatStmt = $this->conn->prepare(
                "SELECT ap.id, ap.name, ap.type, ap.location, ap.status, ap.tuya_device_id,
                        ap.tuya_home_id, ap.device_category, ap.is_common, ap.flat_id,
                        ap.bound_by, ap.bound_at, 'resident' AS access_level
                 FROM tbl_access_point ap
                 WHERE ap.society_id = ? AND ap.tuya_device_id IS NOT NULL
                   AND ap.flat_id = ? AND ap.bound_by != ?
                 ORDER BY ap.name ASC"
            );
            $flatStmt->bind_param('iii', $societyId, $flatId, $userId);
            $flatStmt->execute();
            $flatResult = $flatStmt->get_result();

            while ($row = $flatResult->fetch_assoc()) {
                if (!in_array((int)$row['id'], $seenIds)) {
                    $row['id'] = (int)$row['id'];
                    $row['is_common'] = (bool)$row['is_common'];
                    $row['flat_id'] = $row['flat_id'] !== null ? (int)$row['flat_id'] : null;
                    $row['bound_by'] = $row['bound_by'] !== null ? (int)$row['bound_by'] : null;
                    $devices[] = $row;
                    $seenIds[] = $row['id'];
                }
            }
        }

        // Fetch common devices shared to this user
        $sharedStmt = $this->conn->prepare(
            "SELECT ap.id, ap.name, ap.type, ap.location, ap.status, ap.tuya_device_id,
                    ap.tuya_home_id, ap.device_category, ap.is_common, ap.flat_id,
                    ap.bound_by, ap.bound_at, 'shared' AS access_level
             FROM tbl_tuya_device_share tds
             INNER JOIN tbl_access_point ap ON ap.id = tds.access_point_id
             WHERE tds.shared_to_user_id = ? AND tds.status = 'active'
               AND ap.society_id = ? AND ap.tuya_device_id IS NOT NULL
             ORDER BY ap.name ASC"
        );
        $sharedStmt->bind_param('ii', $userId, $societyId);
        $sharedStmt->execute();
        $sharedResult = $sharedStmt->get_result();

        while ($row = $sharedResult->fetch_assoc()) {
            if (!in_array((int)$row['id'], $seenIds)) {
                $row['id'] = (int)$row['id'];
                $row['is_common'] = (bool)$row['is_common'];
                $row['flat_id'] = $row['flat_id'] !== null ? (int)$row['flat_id'] : null;
                $row['bound_by'] = $row['bound_by'] !== null ? (int)$row['bound_by'] : null;
                $devices[] = $row;
                $seenIds[] = $row['id'];
            }
        }

        ApiResponse::success($devices, 'Tuya devices retrieved');
    }

    // =========================================================================
    //  POST /tuya/log-action
    // =========================================================================

    /**
     * Log a Tuya lock action from the app.
     * Input: access_point_id, access_type, direction, status, notes.
     */
    private function logAction() {
        $user = $this->auth->authenticate();
        $userId = $this->auth->getUserId();
        $societyId = $this->auth->requireSociety();

        $accessPointId = isset($this->input['access_point_id']) ? (int)$this->input['access_point_id'] : 0;
        $accessType = sanitizeInput($this->input['access_type'] ?? '');
        $direction = sanitizeInput($this->input['direction'] ?? 'entry');
        $status = sanitizeInput($this->input['status'] ?? 'granted');
        $notes = sanitizeInput($this->input['notes'] ?? '');

        if ($accessPointId <= 0) {
            ApiResponse::error('Valid access_point_id is required');
        }

        $allowedTypes = ['ble', 'wifi_remote', 'tuya_password', 'app'];
        if (!in_array($accessType, $allowedTypes)) {
            ApiResponse::error('Invalid access_type. Allowed: ble, wifi_remote, tuya_password, app');
        }

        if (!in_array($direction, ['entry', 'exit'])) {
            ApiResponse::error('Direction must be entry or exit');
        }

        if (!in_array($status, ['granted', 'denied'])) {
            ApiResponse::error('Status must be granted or denied');
        }

        // Verify access point belongs to user's society
        $apStmt = $this->conn->prepare(
            "SELECT id FROM tbl_access_point WHERE id = ? AND society_id = ?"
        );
        $apStmt->bind_param('ii', $accessPointId, $societyId);
        $apStmt->execute();
        $apResult = $apStmt->get_result();

        if ($apResult->num_rows === 0) {
            ApiResponse::notFound('Access point not found in your society');
        }

        // Insert into tbl_access_log
        $logStmt = $this->conn->prepare(
            "INSERT INTO tbl_access_log
                (access_point_id, user_id, key_id, visitor_id, access_type, direction, status, verified_by, notes)
             VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?)"
        );
        $logStmt->bind_param('iisssis', $accessPointId, $userId, $accessType, $direction,
            $status, $userId, $notes);

        if (!$logStmt->execute()) {
            ApiResponse::error('Failed to log action', 500);
        }

        ApiResponse::created([
            'id' => $logStmt->insert_id,
            'access_point_id' => $accessPointId,
            'access_type' => $accessType,
            'direction' => $direction,
            'status' => $status
        ], 'Action logged successfully');
    }

    // =========================================================================
    //  GET /tuya/device-history/{id}
    // =========================================================================

    /**
     * Paginated access log history for a specific access point.
     * Verifies user has access to the device's society.
     */
    private function getDeviceHistory($accessPointId) {
        $user = $this->auth->authenticate();
        $userId = $this->auth->getUserId();
        $societyId = $this->auth->requireSociety();

        $accessPointId = (int)$accessPointId;

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

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        // Count total
        $countStmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total FROM tbl_access_log WHERE access_point_id = ?"
        );
        $countStmt->bind_param('i', $accessPointId);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];

        // Fetch paginated logs
        $dataStmt = $this->conn->prepare(
            "SELECT al.id, al.access_point_id, al.user_id, u.name AS user_name,
                    al.key_id, al.visitor_id, al.access_type, al.direction,
                    al.status, al.verified_by, al.notes, al.timestamp
             FROM tbl_access_log al
             LEFT JOIN tbl_user u ON u.id = al.user_id
             WHERE al.access_point_id = ?
             ORDER BY al.timestamp DESC
             LIMIT ? OFFSET ?"
        );
        $dataStmt->bind_param('iii', $accessPointId, $perPage, $offset);
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

        ApiResponse::paginated($logs, $total, $page, $perPage, 'Device history retrieved');
    }

    // =========================================================================
    //  POST /tuya/share-device
    // =========================================================================

    /**
     * Share a common device to a resident. Committee/primary only.
     * Verifies the access point is_common = 1.
     * UPSERTs into tbl_tuya_device_share.
     */
    private function shareDevice() {
        $user = $this->auth->authenticate();
        $userId = $this->auth->getUserId();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $accessPointId = isset($this->input['access_point_id']) ? (int)$this->input['access_point_id'] : 0;
        $targetUserId = isset($this->input['user_id']) ? (int)$this->input['user_id'] : 0;

        if ($accessPointId <= 0) {
            ApiResponse::error('Valid access_point_id is required');
        }
        if ($targetUserId <= 0) {
            ApiResponse::error('Valid user_id is required');
        }

        // Verify access point is in society and is_common
        $apStmt = $this->conn->prepare(
            "SELECT id, tuya_device_id, is_common FROM tbl_access_point
             WHERE id = ? AND society_id = ? AND tuya_device_id IS NOT NULL"
        );
        $apStmt->bind_param('ii', $accessPointId, $societyId);
        $apStmt->execute();
        $apResult = $apStmt->get_result();

        if ($apResult->num_rows === 0) {
            ApiResponse::notFound('Tuya access point not found in your society');
        }

        $ap = $apResult->fetch_assoc();

        if (!(int)$ap['is_common']) {
            ApiResponse::error('Only common devices can be shared. This is a private device.');
        }

        // Verify target user is a resident in the same society
        $userStmt = $this->conn->prepare(
            "SELECT r.user_id FROM tbl_resident r
             WHERE r.user_id = ? AND r.society_id = ? AND r.status = 'approved'"
        );
        $userStmt->bind_param('ii', $targetUserId, $societyId);
        $userStmt->execute();
        $userResult = $userStmt->get_result();

        if ($userResult->num_rows === 0) {
            ApiResponse::notFound('Target user is not an approved resident in your society');
        }

        $tuyaDeviceId = $ap['tuya_device_id'];
        $now = date('Y-m-d H:i:s');

        // UPSERT: check if share already exists
        $existStmt = $this->conn->prepare(
            "SELECT id, status FROM tbl_tuya_device_share
             WHERE access_point_id = ? AND shared_to_user_id = ?"
        );
        $existStmt->bind_param('ii', $accessPointId, $targetUserId);
        $existStmt->execute();
        $existResult = $existStmt->get_result();

        if ($existResult->num_rows > 0) {
            $existing = $existResult->fetch_assoc();
            // Re-activate if revoked
            $updateStmt = $this->conn->prepare(
                "UPDATE tbl_tuya_device_share
                 SET status = 'active', shared_at = ?, revoked_at = NULL
                 WHERE id = ?"
            );
            $shareId = (int)$existing['id'];
            $updateStmt->bind_param('si', $now, $shareId);

            if (!$updateStmt->execute()) {
                ApiResponse::error('Failed to update device share', 500);
            }
        } else {
            // Insert new share
            $insertStmt = $this->conn->prepare(
                "INSERT INTO tbl_tuya_device_share
                    (access_point_id, shared_to_user_id, tuya_device_id, status, shared_at)
                 VALUES (?, ?, ?, 'active', ?)"
            );
            $insertStmt->bind_param('iiss', $accessPointId, $targetUserId, $tuyaDeviceId, $now);

            if (!$insertStmt->execute()) {
                ApiResponse::error('Failed to share device', 500);
            }
        }

        ApiResponse::created([
            'access_point_id' => $accessPointId,
            'shared_to_user_id' => $targetUserId,
            'status' => 'active',
            'shared_at' => $now
        ], 'Device shared successfully');
    }

    // =========================================================================
    //  POST /tuya/revoke-share
    // =========================================================================

    /**
     * Revoke device share. Committee/primary only.
     * Updates status to 'revoked' and sets revoked_at.
     */
    private function revokeShare() {
        $user = $this->auth->authenticate();
        $userId = $this->auth->getUserId();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $accessPointId = isset($this->input['access_point_id']) ? (int)$this->input['access_point_id'] : 0;
        $targetUserId = isset($this->input['user_id']) ? (int)$this->input['user_id'] : 0;

        if ($accessPointId <= 0) {
            ApiResponse::error('Valid access_point_id is required');
        }
        if ($targetUserId <= 0) {
            ApiResponse::error('Valid user_id is required');
        }

        // Verify access point is in society
        $apStmt = $this->conn->prepare(
            "SELECT id FROM tbl_access_point WHERE id = ? AND society_id = ?"
        );
        $apStmt->bind_param('ii', $accessPointId, $societyId);
        $apStmt->execute();
        $apResult = $apStmt->get_result();

        if ($apResult->num_rows === 0) {
            ApiResponse::notFound('Access point not found in your society');
        }

        // Find and revoke the share
        $now = date('Y-m-d H:i:s');
        $revokeStmt = $this->conn->prepare(
            "UPDATE tbl_tuya_device_share
             SET status = 'revoked', revoked_at = ?
             WHERE access_point_id = ? AND shared_to_user_id = ? AND status = 'active'"
        );
        $revokeStmt->bind_param('sii', $now, $accessPointId, $targetUserId);

        if (!$revokeStmt->execute()) {
            ApiResponse::error('Failed to revoke share', 500);
        }

        if ($revokeStmt->affected_rows === 0) {
            ApiResponse::notFound('No active share found for this user and device');
        }

        ApiResponse::success([
            'access_point_id' => $accessPointId,
            'user_id' => $targetUserId,
            'status' => 'revoked',
            'revoked_at' => $now
        ], 'Device share revoked successfully');
    }

    // =========================================================================
    //  GET /tuya/society-config
    // =========================================================================

    /**
     * Get society's Tuya home configuration.
     * Returns tuya_home_id and tuya_uid for the society.
     */
    private function getSocietyConfig() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        $stmt = $this->conn->prepare(
            "SELECT society_id, tuya_home_id, tuya_uid
             FROM tbl_society_tuya_config
             WHERE society_id = ?"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::success([
                'society_id' => $societyId,
                'tuya_home_id' => null,
                'tuya_uid' => null,
                'configured' => false
            ], 'No Tuya configuration found for this society');
        }

        $config = $result->fetch_assoc();
        $config['society_id'] = (int)$config['society_id'];
        $config['configured'] = true;

        ApiResponse::success($config, 'Tuya configuration retrieved');
    }

    // =========================================================================
    //  POST /tuya/society-config
    // =========================================================================

    /**
     * Set society's Tuya home configuration. Committee/primary only.
     * UPSERTs into tbl_society_tuya_config.
     */
    private function setSocietyConfig() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $tuyaHomeId = sanitizeInput($this->input['tuya_home_id'] ?? '');
        $tuyaUid = sanitizeInput($this->input['tuya_uid'] ?? '');

        if (empty($tuyaHomeId)) {
            ApiResponse::error('tuya_home_id is required');
        }
        if (empty($tuyaUid)) {
            ApiResponse::error('tuya_uid is required');
        }

        // Check if config already exists
        $checkStmt = $this->conn->prepare(
            "SELECT society_id FROM tbl_society_tuya_config WHERE society_id = ?"
        );
        $checkStmt->bind_param('i', $societyId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows > 0) {
            // Update existing config
            $updateStmt = $this->conn->prepare(
                "UPDATE tbl_society_tuya_config
                 SET tuya_home_id = ?, tuya_uid = ?
                 WHERE society_id = ?"
            );
            $updateStmt->bind_param('ssi', $tuyaHomeId, $tuyaUid, $societyId);

            if (!$updateStmt->execute()) {
                ApiResponse::error('Failed to update Tuya configuration', 500);
            }
        } else {
            // Insert new config
            $insertStmt = $this->conn->prepare(
                "INSERT INTO tbl_society_tuya_config (society_id, tuya_home_id, tuya_uid)
                 VALUES (?, ?, ?)"
            );
            $insertStmt->bind_param('iss', $societyId, $tuyaHomeId, $tuyaUid);

            if (!$insertStmt->execute()) {
                ApiResponse::error('Failed to save Tuya configuration', 500);
            }
        }

        ApiResponse::success([
            'society_id' => $societyId,
            'tuya_home_id' => $tuyaHomeId,
            'tuya_uid' => $tuyaUid,
            'configured' => true
        ], 'Tuya configuration saved successfully');
    }

    // =========================================================================
    //  GET /tuya/check-device
    // =========================================================================

    /**
     * Check if a tuya_device_id is already bound.
     * Returns is_bound boolean and bound_to name if bound.
     */
    private function checkDevice() {
        $user = $this->auth->authenticate();

        $tuyaDeviceId = sanitizeInput($this->input['tuya_device_id'] ?? '');

        if (empty($tuyaDeviceId)) {
            ApiResponse::error('tuya_device_id query parameter is required');
        }

        $stmt = $this->conn->prepare(
            "SELECT ap.id, ap.name, ap.society_id, u.name AS bound_to_name
             FROM tbl_access_point ap
             LEFT JOIN tbl_user u ON u.id = ap.bound_by
             WHERE ap.tuya_device_id = ?"
        );
        $stmt->bind_param('s', $tuyaDeviceId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::success([
                'tuya_device_id' => $tuyaDeviceId,
                'is_bound' => false,
                'bound_to' => null
            ], 'Device is not bound');
        }

        $row = $result->fetch_assoc();

        ApiResponse::success([
            'tuya_device_id' => $tuyaDeviceId,
            'is_bound' => true,
            'bound_to' => $row['bound_to_name'],
            'access_point_id' => (int)$row['id'],
            'access_point_name' => $row['name']
        ], 'Device is bound');
    }
}
