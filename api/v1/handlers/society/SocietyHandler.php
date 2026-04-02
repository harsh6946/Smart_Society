<?php
/**
 * Securis Smart Society Platform — Society Handler
 * Endpoints for society details, directory, towers, flats, and joining a society.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class SocietyHandler {
    private $conn;
    private $auth;
    private $input;

    public function __construct($conn, $auth, $input) {
        $this->conn = $conn;
        $this->auth = $auth;
        $this->input = $input;
    }

    /**
     * Route: /api/v1/society/{action_or_id}/{sub_action}/{sub_id}
     *
     * GET  /{id}                      — Society details
     * GET  /{id}/directory            — Resident directory (paginated)
     * GET  /{id}/towers               — List towers
     * GET  /{id}/towers/{towerId}/flats — List flats in a tower
     * POST /join                      — Join society via invite code
     */
    public function handle($method, $action, $id, $subAction = '') {
        switch ($method) {
            case 'GET':
                if ($id && $action === 'directory') {
                    $this->getDirectory($id);
                } elseif ($id && $action === 'towers' && $subAction === 'flats') {
                    // Route: /{societyId}/towers/{towerId}/flats
                    // In this routing pattern, towerId comes through $subAction's position
                    // but we need to parse the segments differently.
                    // The router gives us: id=societyId, action='towers', subAction=segment3
                    // For /{id}/towers/{towerId}/flats the URI is:
                    //   segments: [society, {id}, towers, {towerId}, flats]
                    //   id = segments[1], action = segments[2]='towers', subAction = segments[3]={towerId}
                    //   But segment4 = 'flats' is not passed by the router.
                    // We need to handle this via the request URI directly.
                    $this->getFlatsInTower($id, $subAction);
                } elseif ($id && $action === 'towers' && is_numeric($subAction)) {
                    // /{id}/towers/{towerId} — we need to check for /flats after
                    $this->handleTowerSubRoute($id, (int)$subAction);
                } elseif ($id && $action === 'towers') {
                    $this->getTowers($id);
                } elseif ($id && !$action) {
                    $this->getDetails($id);
                } elseif ($action === 'flats' && !empty($this->input['invite_code'])) {
                    $this->getFlatsByInviteCode();
                } elseif (!$id && !$action) {
                    $this->listSocieties();
                } else {
                    ApiResponse::notFound('Society endpoint not found');
                }
                break;

            case 'POST':
                if ($action === 'join') {
                    $this->joinSociety();
                } else {
                    ApiResponse::error('Method not allowed', 405);
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    /**
     * Handle sub-routes under /towers/{towerId}/...
     * Parses the remaining URI to determine if /flats is requested.
     */
    private function handleTowerSubRoute($societyId, $towerId) {
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (preg_match('/\/towers\/\d+\/flats\/?$/', $requestUri)) {
            $this->getFlatsInTower($societyId, $towerId);
        } else {
            ApiResponse::notFound('Society endpoint not found');
        }
    }

    /**
     * GET /society/{id}
     * Returns society details with tower, flat, and resident counts.
     */
    private function getDetails($societyId) {
        $user = $this->auth->authenticate();

        // Verify user belongs to this society
        $userSocietyId = $this->auth->getSocietyId();
        if (!$userSocietyId || $userSocietyId !== (int)$societyId) {
            ApiResponse::forbidden('You are not a member of this society');
        }

        $stmt = $this->conn->prepare(
            "SELECT s.id, s.name, s.address, s.city, s.state, s.pincode, s.logo, s.status,
                    (SELECT COUNT(*) FROM tbl_tower t WHERE t.society_id = s.id) AS towers_count,
                    (SELECT COUNT(*) FROM tbl_flat f
                     INNER JOIN tbl_tower t2 ON f.tower_id = t2.id
                     WHERE t2.society_id = s.id) AS flats_count,
                    (SELECT COUNT(*) FROM tbl_resident r
                     WHERE r.society_id = s.id AND r.status = 'approved') AS resident_count
             FROM tbl_society s
             WHERE s.id = ? AND s.status = 'active'"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Society not found');
        }

        $society = $result->fetch_assoc();
        $society['id'] = (int)$society['id'];
        $society['towers_count'] = (int)$society['towers_count'];
        $society['flats_count'] = (int)$society['flats_count'];
        $society['resident_count'] = (int)$society['resident_count'];

        ApiResponse::success($society, 'Society details retrieved');
    }

    /**
     * GET /society/{id}/directory
     * Paginated list of approved residents with flat, tower, name, and phone.
     */
    private function getDirectory($societyId) {
        $user = $this->auth->authenticate();
        $this->auth->requireSociety();

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        // Count total approved residents in this society
        $countStmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total
             FROM tbl_resident r
             INNER JOIN tbl_user u ON u.id = r.user_id
             WHERE r.society_id = ? AND r.status = 'approved'"
        );
        $countStmt->bind_param('i', $societyId);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];

        // Fetch paginated directory
        $stmt = $this->conn->prepare(
            "SELECT r.id AS resident_id, u.name, u.phone, u.avatar,
                    f.flat_number, f.floor,
                    t.name AS tower_name,
                    r.resident_type, r.is_primary
             FROM tbl_resident r
             INNER JOIN tbl_user u ON u.id = r.user_id
             INNER JOIN tbl_flat f ON f.id = r.flat_id
             INNER JOIN tbl_tower t ON t.id = f.tower_id
             WHERE r.society_id = ? AND r.status = 'approved'
             ORDER BY t.name ASC, f.flat_number ASC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('iii', $societyId, $perPage, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $residents = [];
        while ($row = $result->fetch_assoc()) {
            $row['resident_id'] = (int)$row['resident_id'];
            $row['floor'] = (int)$row['floor'];
            $row['is_primary'] = (bool)$row['is_primary'];
            $residents[] = $row;
        }

        ApiResponse::paginated($residents, $total, $page, $perPage, 'Resident directory retrieved');
    }

    /**
     * GET /society/{id}/towers
     * List all towers in the society with flat counts.
     */
    private function getTowers($societyId) {
        $user = $this->auth->authenticate();
        $this->auth->requireSociety();

        $stmt = $this->conn->prepare(
            "SELECT t.id, t.name, t.total_floors, t.total_flats,
                    (SELECT COUNT(*) FROM tbl_flat f WHERE f.tower_id = t.id) AS actual_flats,
                    (SELECT COUNT(DISTINCT r.flat_id) FROM tbl_resident r
                     INNER JOIN tbl_flat f2 ON f2.id = r.flat_id
                     WHERE f2.tower_id = t.id AND r.status = 'approved') AS occupied_flats
             FROM tbl_tower t
             WHERE t.society_id = ?
             ORDER BY t.name ASC"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        $towers = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['total_floors'] = (int)$row['total_floors'];
            $row['total_flats'] = (int)$row['total_flats'];
            $row['actual_flats'] = (int)$row['actual_flats'];
            $row['occupied_flats'] = (int)$row['occupied_flats'];
            $towers[] = $row;
        }

        ApiResponse::success($towers, 'Towers retrieved');
    }

    /**
     * GET /society/{id}/towers/{towerId}/flats
     * List all flats in a tower with occupancy status.
     */
    private function getFlatsInTower($societyId, $towerId) {
        $user = $this->auth->authenticate();
        $this->auth->requireSociety();

        $towerId = (int)$towerId;

        // Verify tower belongs to this society
        $checkStmt = $this->conn->prepare(
            "SELECT id FROM tbl_tower WHERE id = ? AND society_id = ?"
        );
        $checkStmt->bind_param('ii', $towerId, $societyId);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Tower not found in this society');
        }

        $stmt = $this->conn->prepare(
            "SELECT f.id, f.flat_number, f.floor, f.type, f.area_sqft, f.status,
                    r.id AS resident_id, u.name AS resident_name
             FROM tbl_flat f
             LEFT JOIN tbl_resident r ON r.flat_id = f.id AND r.status = 'approved' AND r.is_primary = 1
             LEFT JOIN tbl_user u ON u.id = r.user_id
             WHERE f.tower_id = ?
             ORDER BY f.floor ASC, f.flat_number ASC"
        );
        $stmt->bind_param('i', $towerId);
        $stmt->execute();
        $result = $stmt->get_result();

        $flats = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['floor'] = (int)$row['floor'];
            $row['area_sqft'] = $row['area_sqft'] !== null ? (float)$row['area_sqft'] : null;
            $row['is_occupied'] = $row['resident_id'] !== null;
            $row['resident_id'] = $row['resident_id'] !== null ? (int)$row['resident_id'] : null;
            $flats[] = $row;
        }

        ApiResponse::success($flats, 'Flats retrieved');
    }

    /**
     * GET /society/flats?invite_code=XXX
     * Look up society by invite code and return all towers with their flats.
     */
    private function getFlatsByInviteCode() {
        $user = $this->auth->authenticate();
        $inviteCode = sanitizeInput($this->input['invite_code'] ?? '');

        if (empty($inviteCode)) {
            ApiResponse::error('invite_code is required');
        }

        // Find society
        $stmt = $this->conn->prepare(
            "SELECT id, name FROM tbl_society WHERE invite_code = ? AND status = 'active'"
        );
        $stmt->bind_param('s', $inviteCode);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Invalid invite code or society not active');
        }

        $society = $result->fetch_assoc();
        $societyId = (int)$society['id'];

        // Get towers with flats
        $towerStmt = $this->conn->prepare(
            "SELECT id, name, total_floors FROM tbl_tower WHERE society_id = ? ORDER BY name"
        );
        $towerStmt->bind_param('i', $societyId);
        $towerStmt->execute();
        $towers = $towerStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $flats = [];
        foreach ($towers as $tower) {
            $flatStmt = $this->conn->prepare(
                "SELECT f.id, f.flat_number, f.floor, f.type, f.status,
                        t.name AS tower_name
                 FROM tbl_flat f
                 INNER JOIN tbl_tower t ON t.id = f.tower_id
                 WHERE f.tower_id = ?
                 ORDER BY f.flat_number"
            );
            $towerId = (int)$tower['id'];
            $flatStmt->bind_param('i', $towerId);
            $flatStmt->execute();
            $towerFlats = $flatStmt->get_result()->fetch_all(MYSQLI_ASSOC);

            foreach ($towerFlats as &$flat) {
                $flat['id'] = (int)$flat['id'];
                $flat['floor'] = (int)$flat['floor'];
                $flat['display_name'] = $flat['tower_name'] . ' - ' . $flat['flat_number'];
            }
            $flats = array_merge($flats, $towerFlats);
        }

        ApiResponse::success([
            'society_id' => $societyId,
            'society_name' => $society['name'],
            'flats' => $flats,
        ], 'Flats retrieved');
    }

    /**
     * GET /society (no id, no action)
     * List societies the current user belongs to.
     */
    private function listSocieties() {
        $user = $this->auth->authenticate();
        $userId = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "SELECT s.id, s.name, s.address, s.city, s.logo, s.invite_code,
                    r.id AS resident_id, r.resident_type, r.is_primary, r.is_guard,
                    r.status AS resident_status, f.flat_number, t.name AS tower_name
             FROM tbl_resident r
             INNER JOIN tbl_society s ON s.id = r.society_id
             INNER JOIN tbl_flat f ON f.id = r.flat_id
             INNER JOIN tbl_tower t ON t.id = f.tower_id
             WHERE r.user_id = ?
             ORDER BY r.created_at DESC"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $societies = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['resident_id'] = (int)$row['resident_id'];
            $row['is_primary'] = (bool)$row['is_primary'];
            $row['is_guard'] = (bool)$row['is_guard'];
            $societies[] = $row;
        }

        ApiResponse::success($societies, 'Societies retrieved');
    }

    /**
     * POST /society/join
     * Join a society via invite code. Creates a pending resident record.
     */
    private function joinSociety() {
        $user = $this->auth->authenticate();
        $userId = $this->auth->getUserId();

        $inviteCode = sanitizeInput($this->input['invite_code'] ?? '');
        $flatId = isset($this->input['flat_id']) ? (int)$this->input['flat_id'] : 0;

        if (empty($inviteCode)) {
            ApiResponse::error('Invite code is required');
        }
        if ($flatId <= 0) {
            ApiResponse::error('Valid flat ID is required');
        }

        // Look up society by invite code
        $stmt = $this->conn->prepare(
            "SELECT id FROM tbl_society WHERE invite_code = ? AND status = 'active'"
        );
        $stmt->bind_param('s', $inviteCode);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Invalid invite code');
        }

        $society = $result->fetch_assoc();
        $societyId = (int)$society['id'];

        // Verify flat belongs to this society
        $flatStmt = $this->conn->prepare(
            "SELECT f.id FROM tbl_flat f
             INNER JOIN tbl_tower t ON t.id = f.tower_id
             WHERE f.id = ? AND t.society_id = ?"
        );
        $flatStmt->bind_param('ii', $flatId, $societyId);
        $flatStmt->execute();
        if ($flatStmt->get_result()->num_rows === 0) {
            ApiResponse::error('Flat does not belong to this society');
        }

        // Check if user already has a pending or approved record for this society
        $existStmt = $this->conn->prepare(
            "SELECT id, status FROM tbl_resident
             WHERE user_id = ? AND society_id = ? AND status IN ('pending', 'approved')"
        );
        $existStmt->bind_param('ii', $userId, $societyId);
        $existStmt->execute();
        $existResult = $existStmt->get_result();

        if ($existResult->num_rows > 0) {
            $existing = $existResult->fetch_assoc();
            if ($existing['status'] === 'approved') {
                ApiResponse::error('You are already a member of this society');
            }
            ApiResponse::error('You already have a pending request for this society');
        }

        // Create pending resident record
        $insertStmt = $this->conn->prepare(
            "INSERT INTO tbl_resident (user_id, society_id, flat_id, resident_type, is_primary, is_guard, status)
             VALUES (?, ?, ?, 'owner', 0, 0, 'pending')"
        );
        $insertStmt->bind_param('iii', $userId, $societyId, $flatId);

        if (!$insertStmt->execute()) {
            ApiResponse::error('Failed to submit join request', 500);
        }

        $residentId = $insertStmt->insert_id;

        ApiResponse::created([
            'resident_id' => $residentId,
            'society_id' => $societyId,
            'flat_id' => $flatId,
            'status' => 'pending'
        ], 'Join request submitted successfully. Awaiting approval.');
    }
}
