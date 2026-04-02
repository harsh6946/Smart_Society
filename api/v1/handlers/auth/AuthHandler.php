<?php
/**
 * Securis Smart Society Platform — Auth Handler
 * Handles user authentication via Firebase Phone OTP,
 * profile management, society listing, and notifications.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class AuthHandler {
    private $conn;
    private $auth;
    private $input;

    public function __construct($conn, $auth, $input) {
        $this->conn = $conn;
        $this->auth = $auth;
        $this->input = $input;
    }

    public function handle($method, $action, $id) {
        switch ($method) {
            case 'POST':
                switch ($action) {
                    case 'send-otp':
                        $this->sendOtp();
                        break;
                    case 'verify-otp':
                        $this->verifyOtp();
                        break;
                    default:
                        ApiResponse::notFound('Auth endpoint not found');
                }
                break;

            case 'GET':
                switch ($action) {
                    case 'profile':
                        $this->getProfile();
                        break;
                    case 'societies':
                        $this->getSocieties();
                        break;
                    case 'notifications':
                        $this->getNotifications();
                        break;
                    case 'feature-toggles':
                        $this->getFeatureToggles();
                        break;
                    case 'atmosphere':
                        $this->getAtmosphere();
                        break;
                    default:
                        ApiResponse::notFound('Auth endpoint not found');
                }
                break;

            case 'PUT':
                switch ($action) {
                    case 'profile':
                        $this->updateProfile();
                        break;
                    case 'update-fcm-token':
                        $this->updateFcmToken();
                        break;
                    case 'atmosphere':
                        $this->setAtmosphere();
                        break;
                    case 'notifications':
                        if ($id) {
                            $this->markNotificationRead($id);
                        } else {
                            ApiResponse::error('Notification ID required');
                        }
                        break;
                    default:
                        ApiResponse::notFound('Auth endpoint not found');
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    /**
     * POST /auth/send-otp
     * Accept phone number, ensure user record exists, confirm OTP can be sent client-side.
     */
    private function sendOtp() {
        $phone = sanitizeInput($this->input['phone'] ?? '');

        if (empty($phone)) {
            ApiResponse::error('Phone number is required');
        }

        if (!validatePhone($phone)) {
            ApiResponse::error('Invalid phone number format');
        }

        // Check if user exists
        $stmt = $this->conn->prepare("SELECT id, status FROM tbl_user WHERE phone = ?");
        $stmt->bind_param('s', $phone);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            // Create new user record
            $stmt = $this->conn->prepare(
                "INSERT INTO tbl_user (phone, status) VALUES (?, 'active')"
            );
            $stmt->bind_param('s', $phone);
            $stmt->execute();
        } else {
            $user = $result->fetch_assoc();
            if ($user['status'] !== 'active') {
                ApiResponse::error('Account is deactivated. Please contact support.');
            }
        }

        ApiResponse::success(
            ['phone' => $phone],
            'OTP sent successfully'
        );
    }

    /**
     * POST /auth/verify-otp
     * Verify Firebase OTP, generate auth token, update user record.
     * Returns token, user data, is_new_user flag, and has_society flag.
     */
    private function verifyOtp() {
        $phone = sanitizeInput($this->input['phone'] ?? '');
        $firebaseUid = sanitizeInput($this->input['firebase_uid'] ?? '');
        $fcmToken = sanitizeInput($this->input['fcm_token'] ?? '');

        if (empty($phone) || empty($firebaseUid)) {
            ApiResponse::error('Phone and firebase_uid are required');
        }

        if (!validatePhone($phone)) {
            ApiResponse::error('Invalid phone number format');
        }

        // Find user by phone
        $stmt = $this->conn->prepare("SELECT id, name, email, avatar, status FROM tbl_user WHERE phone = ?");
        $stmt->bind_param('s', $phone);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::error('User not found. Please request OTP first.');
        }

        $user = $result->fetch_assoc();

        if ($user['status'] !== 'active') {
            ApiResponse::error('Account is deactivated. Please contact support.');
        }

        $userId = (int)$user['id'];
        $isNewUser = empty($user['name']);

        // Generate auth token
        $authToken = generateToken();
        $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        // Update user with firebase_uid, auth_token, and fcm_token
        $stmt = $this->conn->prepare(
            "UPDATE tbl_user
             SET firebase_uid = ?, auth_token = ?, token_expires_at = ?, fcm_token = ?
             WHERE id = ?"
        );
        $stmt->bind_param('ssssi', $firebaseUid, $authToken, $tokenExpiresAt, $fcmToken, $userId);
        $stmt->execute();

        // Check if user has any approved society membership
        $stmt = $this->conn->prepare(
            "SELECT r.id as resident_id, r.society_id, r.flat_id, r.is_primary, r.is_guard,
                    s.name as society_name,
                    t.name as tower_name,
                    f.flat_number
             FROM tbl_resident r
             JOIN tbl_society s ON s.id = r.society_id
             JOIN tbl_flat f ON f.id = r.flat_id
             JOIN tbl_tower t ON t.id = f.tower_id
             WHERE r.user_id = ? AND r.status = 'approved'
             LIMIT 1"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $societyResult = $stmt->get_result();
        $hasSociety = $societyResult->num_rows > 0;

        $societyData = null;
        if ($hasSociety) {
            $row = $societyResult->fetch_assoc();
            $societyData = [
                'resident_id' => (int)$row['resident_id'],
                'society_id' => (int)$row['society_id'],
                'society_name' => $row['society_name'],
                'flat_id' => (int)$row['flat_id'],
                'flat_number' => $row['flat_number'],
                'tower_name' => $row['tower_name'],
                'is_primary' => (bool)$row['is_primary'],
                'is_guard' => (bool)$row['is_guard'],
            ];
        }

        ApiResponse::success([
            'token' => $authToken,
            'token_expires_at' => $tokenExpiresAt,
            'user' => [
                'id' => $userId,
                'phone' => $phone,
                'name' => $user['name'],
                'email' => $user['email'],
                'avatar' => $user['avatar'],
            ],
            'is_new_user' => $isNewUser,
            'has_society' => $hasSociety,
            'society' => $societyData,
        ], 'Login successful');
    }

    /**
     * GET /auth/profile
     * Return authenticated user's full profile including residences, family members, and vehicles.
     */
    private function getProfile() {
        $this->auth->authenticate();
        $userId = $this->auth->getUserId();

        // Fetch user info
        $stmt = $this->conn->prepare(
            "SELECT id, phone, name, email, avatar, firebase_uid, fcm_token, status
             FROM tbl_user WHERE id = ?"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            ApiResponse::notFound('User not found');
        }

        // Fetch residences with society, tower, and flat info
        $stmt = $this->conn->prepare(
            "SELECT r.id as resident_id, r.society_id, r.flat_id, r.resident_type, r.is_primary, r.is_guard, r.status,
                    s.name as society_name,
                    t.id as tower_id, t.name as tower_name,
                    f.flat_number
             FROM tbl_resident r
             JOIN tbl_society s ON s.id = r.society_id
             JOIN tbl_flat f ON f.id = r.flat_id
             JOIN tbl_tower t ON t.id = f.tower_id
             WHERE r.user_id = ? AND r.status != 'removed'
             ORDER BY r.is_primary DESC, s.name ASC"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $residenceResult = $stmt->get_result();

        $residences = [];
        $residentIds = [];
        while ($row = $residenceResult->fetch_assoc()) {
            $residences[] = [
                'resident_id' => (int)$row['resident_id'],
                'society_id' => (int)$row['society_id'],
                'society_name' => $row['society_name'],
                'tower_id' => (int)$row['tower_id'],
                'tower_name' => $row['tower_name'],
                'flat_id' => (int)$row['flat_id'],
                'flat_number' => $row['flat_number'],
                'resident_type' => $row['resident_type'],
                'is_primary' => (bool)$row['is_primary'],
                'is_guard' => (bool)$row['is_guard'],
                'status' => $row['status'],
            ];
            $residentIds[] = (int)$row['resident_id'];
        }

        // Fetch family members for all residences
        $familyMembers = [];
        if (!empty($residentIds)) {
            $placeholders = implode(',', array_fill(0, count($residentIds), '?'));
            $types = str_repeat('i', count($residentIds));

            $stmt = $this->conn->prepare(
                "SELECT id, resident_id, name, relation, phone, age
                 FROM tbl_family_member
                 WHERE resident_id IN ($placeholders)
                 ORDER BY name ASC"
            );
            $stmt->bind_param($types, ...$residentIds);
            $stmt->execute();
            $familyResult = $stmt->get_result();

            while ($row = $familyResult->fetch_assoc()) {
                $familyMembers[] = [
                    'id' => (int)$row['id'],
                    'resident_id' => (int)$row['resident_id'],
                    'name' => $row['name'],
                    'relation' => $row['relation'],
                    'phone' => $row['phone'],
                    'age' => $row['age'] !== null ? (int)$row['age'] : null,
                ];
            }
        }

        // Fetch vehicles for all residences
        $vehicles = [];
        if (!empty($residentIds)) {
            $placeholders = implode(',', array_fill(0, count($residentIds), '?'));
            $types = str_repeat('i', count($residentIds));

            $stmt = $this->conn->prepare(
                "SELECT id, resident_id, vehicle_type, vehicle_number, model, color
                 FROM tbl_vehicle
                 WHERE resident_id IN ($placeholders)
                 ORDER BY vehicle_number ASC"
            );
            $stmt->bind_param($types, ...$residentIds);
            $stmt->execute();
            $vehicleResult = $stmt->get_result();

            while ($row = $vehicleResult->fetch_assoc()) {
                $vehicles[] = [
                    'id' => (int)$row['id'],
                    'resident_id' => (int)$row['resident_id'],
                    'vehicle_type' => $row['vehicle_type'],
                    'vehicle_number' => $row['vehicle_number'],
                    'model' => $row['model'],
                    'color' => $row['color'],
                ];
            }
        }

        // Find first approved residence for has_society check
        $approvedResidence = null;
        foreach ($residences as $r) {
            if ($r['status'] === 'approved') {
                $approvedResidence = $r;
                break;
            }
        }

        ApiResponse::success([
            'user' => [
                'id' => (int)$user['id'],
                'phone' => $user['phone'],
                'name' => $user['name'],
                'email' => $user['email'],
                'avatar' => $user['avatar'],
                'firebase_uid' => $user['firebase_uid'],
                'status' => $user['status'],
            ],
            'has_society' => $approvedResidence !== null,
            'society' => $approvedResidence,
            'residences' => $residences,
            'family_members' => $familyMembers,
            'vehicles' => $vehicles,
        ], 'Profile retrieved successfully');
    }

    /**
     * PUT /auth/profile
     * Update FCM token for push notifications.
     */
    private function updateFcmToken() {
        $this->auth->authenticate();
        $userId = $this->auth->getUserId();

        $fcmToken = trim($this->input['fcm_token'] ?? '');
        if (empty($fcmToken)) {
            ApiResponse::error('FCM token is required', 400);
        }

        $stmt = $this->conn->prepare("UPDATE tbl_user SET fcm_token = ? WHERE id = ?");
        $stmt->bind_param('si', $fcmToken, $userId);
        $stmt->execute();
        $stmt->close();

        ApiResponse::success(['message' => 'FCM token updated']);
    }

    /**
     * GET /auth/feature-toggles — returns feature flags for the user's society.
     * Merges global defaults (society_id IS NULL) with society-specific overrides.
     */
    private function getFeatureToggles() {
        $this->auth->authenticate();
        $societyId = $this->auth->getSocietyId();

        // Get global defaults
        $stmt = $this->conn->prepare(
            "SELECT feature_key, is_enabled FROM tbl_feature_toggle WHERE society_id IS NULL"
        );
        $stmt->execute();
        $globals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $toggles = [];
        foreach ($globals as $g) {
            $toggles[$g['feature_key']] = (bool)$g['is_enabled'];
        }

        // Override with society-specific if exists
        if ($societyId) {
            $stmt = $this->conn->prepare(
                "SELECT feature_key, is_enabled FROM tbl_feature_toggle WHERE society_id = ?"
            );
            $stmt->bind_param('i', $societyId);
            $stmt->execute();
            $overrides = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($overrides as $o) {
                $toggles[$o['feature_key']] = (bool)$o['is_enabled'];
            }
        }

        ApiResponse::success(['toggles' => $toggles]);
    }

    /**
     * Update authenticated user's name and email.
     */
    private function updateProfile() {
        $this->auth->authenticate();
        $userId = $this->auth->getUserId();

        $name = sanitizeInput($this->input['name'] ?? '');
        $email = sanitizeInput($this->input['email'] ?? '');

        if (empty($name)) {
            ApiResponse::error('Name is required');
        }

        if (!empty($email) && !validateEmail($email)) {
            ApiResponse::error('Invalid email format');
        }

        // Check if email is already used by another user
        if (!empty($email)) {
            $stmt = $this->conn->prepare(
                "SELECT id FROM tbl_user WHERE email = ? AND id != ?"
            );
            $stmt->bind_param('si', $email, $userId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                ApiResponse::error('Email is already in use by another account');
            }
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_user SET name = ?, email = ? WHERE id = ?"
        );
        $stmt->bind_param('ssi', $name, $email, $userId);
        $stmt->execute();

        // Return updated user
        $stmt = $this->conn->prepare(
            "SELECT id, phone, name, email, avatar, status FROM tbl_user WHERE id = ?"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        ApiResponse::success([
            'user' => [
                'id' => (int)$user['id'],
                'phone' => $user['phone'],
                'name' => $user['name'],
                'email' => $user['email'],
                'avatar' => $user['avatar'],
                'status' => $user['status'],
            ],
        ], 'Profile updated successfully');
    }

    /**
     * GET /auth/societies
     * List all societies the authenticated user belongs to.
     */
    private function getSocieties() {
        $this->auth->authenticate();
        $userId = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "SELECT r.id as resident_id, r.society_id, r.flat_id, r.resident_type, r.is_primary, r.is_guard, r.status,
                    s.name as society_name, s.address as society_address, s.city as society_city,
                    t.id as tower_id, t.name as tower_name,
                    f.flat_number
             FROM tbl_resident r
             JOIN tbl_society s ON s.id = r.society_id
             JOIN tbl_flat f ON f.id = r.flat_id
             JOIN tbl_tower t ON t.id = f.tower_id
             WHERE r.user_id = ? AND r.status != 'removed'
             ORDER BY r.is_primary DESC, s.name ASC"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $societies = [];
        while ($row = $result->fetch_assoc()) {
            $societies[] = [
                'resident_id' => (int)$row['resident_id'],
                'society_id' => (int)$row['society_id'],
                'society_name' => $row['society_name'],
                'society_address' => $row['society_address'],
                'society_city' => $row['society_city'],
                'tower_id' => (int)$row['tower_id'],
                'tower_name' => $row['tower_name'],
                'flat_id' => (int)$row['flat_id'],
                'flat_number' => $row['flat_number'],
                'resident_type' => $row['resident_type'],
                'is_primary' => (bool)$row['is_primary'],
                'is_guard' => (bool)$row['is_guard'],
                'status' => $row['status'],
            ];
        }

        ApiResponse::success($societies, 'Societies retrieved successfully');
    }

    /**
     * GET /auth/notifications
     * List paginated notifications for the authenticated user.
     */
    private function getNotifications() {
        $this->auth->authenticate();
        $userId = $this->auth->getUserId();

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        // Get total count
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) as total FROM tbl_notification WHERE user_id = ?"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        // Get paginated notifications
        $stmt = $this->conn->prepare(
            "SELECT id, society_id, title, body, type, reference_type, reference_id, is_read, created_at
             FROM tbl_notification
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('iii', $userId, $perPage, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $notifications = [];
        while ($row = $result->fetch_assoc()) {
            $notifications[] = [
                'id' => (int)$row['id'],
                'society_id' => $row['society_id'] ? (int)$row['society_id'] : null,
                'title' => $row['title'],
                'body' => $row['body'],
                'type' => $row['type'],
                'reference_type' => $row['reference_type'],
                'reference_id' => $row['reference_id'] ? (int)$row['reference_id'] : null,
                'is_read' => (bool)$row['is_read'],
                'created_at' => $row['created_at'],
            ];
        }

        ApiResponse::paginated($notifications, $total, $page, $perPage, 'Notifications retrieved successfully');
    }

    /**
     * PUT /auth/notifications/{id}/read
     * Mark a specific notification as read for the authenticated user.
     */
    private function markNotificationRead($id) {
        $this->auth->authenticate();
        $userId = $this->auth->getUserId();

        // Verify the notification belongs to the user
        $stmt = $this->conn->prepare(
            "SELECT id, is_read FROM tbl_notification WHERE id = ? AND user_id = ?"
        );
        $stmt->bind_param('ii', $id, $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Notification not found');
        }

        $notification = $result->fetch_assoc();

        if ($notification['is_read']) {
            ApiResponse::success(null, 'Notification already marked as read');
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_notification SET is_read = 1 WHERE id = ? AND user_id = ?"
        );
        $stmt->bind_param('ii', $id, $userId);
        $stmt->execute();

        ApiResponse::success(null, 'Notification marked as read');
    }

    /**
     * GET /auth/atmosphere
     * Returns current atmosphere/animation for the user's society.
     * Types: auto (time-based), rain, heat_wave, snow, party, festival, diwali, christmas, independence_day
     * Staff can set this per society from the admin panel.
     */
    private function getAtmosphere() {
        $this->auth->authenticate();
        $societyId = $this->auth->getSocietyId();

        $atmosphere = [
            'type' => 'auto',        // default: time-based
            'intensity' => 'normal',  // light, normal, heavy
            'message' => null,        // optional banner message
            'expires_at' => null,
        ];

        if ($societyId) {
            $stmt = $this->conn->prepare(
                "SELECT atmosphere_type, atmosphere_intensity, atmosphere_message, atmosphere_expires_at
                 FROM tbl_society WHERE id = ?"
            );
            $stmt->bind_param('i', $societyId);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($result && !empty($result['atmosphere_type'])) {
                // Check expiry
                if ($result['atmosphere_expires_at'] && strtotime($result['atmosphere_expires_at']) < time()) {
                    // Expired — reset to auto
                    $resetStmt = $this->conn->prepare(
                        "UPDATE tbl_society SET atmosphere_type = 'auto', atmosphere_message = NULL, atmosphere_expires_at = NULL WHERE id = ?"
                    );
                    $resetStmt->bind_param('i', $societyId);
                    $resetStmt->execute();
                    $resetStmt->close();
                } else {
                    $atmosphere['type'] = $result['atmosphere_type'];
                    $atmosphere['intensity'] = $result['atmosphere_intensity'] ?? 'normal';
                    $atmosphere['message'] = $result['atmosphere_message'];
                    $atmosphere['expires_at'] = $result['atmosphere_expires_at'];
                }
            }
        }

        ApiResponse::success($atmosphere);
    }

    /**
     * PUT /auth/atmosphere
     * Set atmosphere for a society. Only primary residents (admin) or staff can do this.
     * Input: type, intensity, message, duration_hours
     */
    private function setAtmosphere() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $type = sanitizeInput($this->input['type'] ?? 'auto');
        $intensity = sanitizeInput($this->input['intensity'] ?? 'normal');
        $message = sanitizeInput($this->input['message'] ?? '');
        $durationHours = intval($this->input['duration_hours'] ?? 24);

        $validTypes = ['auto', 'rain', 'heat_wave', 'snow', 'party', 'festival',
                       'diwali', 'christmas', 'independence_day', 'holi', 'eid', 'newyear'];
        if (!in_array($type, $validTypes)) {
            ApiResponse::error('Invalid atmosphere type. Valid: ' . implode(', ', $validTypes));
        }

        $validIntensities = ['light', 'normal', 'heavy'];
        if (!in_array($intensity, $validIntensities)) {
            ApiResponse::error('Invalid intensity. Valid: light, normal, heavy');
        }

        $expiresAt = $type === 'auto' ? null : date('Y-m-d H:i:s', strtotime("+{$durationHours} hours"));

        $stmt = $this->conn->prepare(
            "UPDATE tbl_society SET atmosphere_type = ?, atmosphere_intensity = ?,
             atmosphere_message = ?, atmosphere_expires_at = ? WHERE id = ?"
        );
        $msgVal = empty($message) ? null : $message;
        $stmt->bind_param('ssssi', $type, $intensity, $msgVal, $expiresAt, $societyId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to update atmosphere', 500);
        }

        ApiResponse::success([
            'type' => $type,
            'intensity' => $intensity,
            'message' => $msgVal,
            'expires_at' => $expiresAt,
        ], 'Atmosphere updated successfully');
    }
}
