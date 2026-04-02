<?php
/**
 * Securis Smart Society Platform — Visitor Management Handler
 * Endpoints for visitor pre-registration, walk-ins, check-in/out,
 * blacklisting, staff entries, and visitor logs.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class VisitorHandler {
    private $conn;
    private $auth;
    private $input;

    public function __construct($conn, $auth, $input) {
        $this->conn = $conn;
        $this->auth = $auth;
        $this->input = $input;
    }

    /**
     * Route: /api/v1/visitors/{action_or_id}/{sub_action}
     */
    public function handle($method, $action, $id) {
        switch ($method) {
            case 'GET':
                if ($action === 'blacklist') {
                    $this->listBlacklist();
                } elseif ($action === 'staff-entries') {
                    $this->listStaffEntries();
                } elseif ($action === 'logs') {
                    $this->getVisitorLogs();
                } elseif ($action === 'analytics') {
                    $this->getAnalytics();
                } elseif ($id && !$action) {
                    $this->getVisitorDetail($id);
                } elseif (!$action && !$id) {
                    $this->listVisitors();
                } else {
                    ApiResponse::notFound('Visitor endpoint not found');
                }
                break;

            case 'POST':
                if ($action === 'pre-register') {
                    $this->preRegister();
                } elseif ($action === 'walk-in') {
                    $this->walkIn();
                } elseif ($action === 'blacklist') {
                    $this->addToBlacklist();
                } elseif ($action === 'staff-entry') {
                    $this->logStaffEntry();
                } else {
                    ApiResponse::error('Method not allowed', 405);
                }
                break;

            case 'PUT':
                if ($id && $action === 'approve') {
                    $this->approveVisitor($id);
                } elseif ($id && $action === 'reject') {
                    $this->rejectVisitor($id);
                } elseif ($id && $action === 'checkin') {
                    $this->checkInVisitor($id);
                } elseif ($id && $action === 'checkout') {
                    $this->checkOutVisitor($id);
                } elseif ($action === 'staff-entry' && $id) {
                    // PUT /visitors/staff-entry/{id}/checkout
                    $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                    if (preg_match('/\/staff-entry\/\d+\/checkout\/?$/', $requestUri)) {
                        $this->checkOutStaff($id);
                    } else {
                        ApiResponse::notFound('Visitor endpoint not found');
                    }
                } else {
                    ApiResponse::error('Method not allowed', 405);
                }
                break;

            case 'DELETE':
                if ($action === 'blacklist' && $id) {
                    $this->removeFromBlacklist($id);
                } else {
                    ApiResponse::error('Method not allowed', 405);
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ---------------------------------------------------------------
    //  POST /visitors/pre-register
    // ---------------------------------------------------------------
    private function preRegister() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $userId = $this->auth->getUserId();
        $flatId = $this->auth->getFlatId();

        $name = sanitizeInput($this->input['name'] ?? '');
        $phone = sanitizeInput($this->input['phone'] ?? '');
        $purpose = sanitizeInput($this->input['purpose'] ?? '');
        $vehicleNumber = sanitizeInput($this->input['vehicle_number'] ?? '');
        $visitorType = sanitizeInput($this->input['visitor_type'] ?? 'guest');
        $validFrom = sanitizeInput($this->input['valid_from'] ?? '');
        $validUntil = sanitizeInput($this->input['valid_until'] ?? '');
        $validHours = isset($this->input['valid_hours']) ? (int)$this->input['valid_hours'] : 0;

        if (empty($name)) {
            ApiResponse::error('Visitor name is required');
        }
        if (empty($phone) || !validatePhone($phone)) {
            ApiResponse::error('Valid phone number is required');
        }

        $allowedTypes = ['guest', 'delivery', 'staff', 'vendor', 'cab'];
        if (!in_array($visitorType, $allowedTypes)) {
            ApiResponse::error('Invalid visitor type. Allowed: ' . implode(', ', $allowedTypes));
        }

        if (empty($validFrom)) {
            $validFrom = date('Y-m-d H:i:s');
        }
        if (empty($validUntil) && $validHours > 0) {
            $validUntil = date('Y-m-d H:i:s', strtotime($validFrom) + ($validHours * 3600));
        }
        if (empty($validUntil)) {
            $validUntil = date('Y-m-d H:i:s', strtotime($validFrom) + 86400);
        }

        // Check blacklist
        $blStmt = $this->conn->prepare(
            "SELECT id FROM tbl_visitor_blacklist WHERE society_id = ? AND phone = ?"
        );
        $blStmt->bind_param('is', $societyId, $phone);
        $blStmt->execute();
        if ($blStmt->get_result()->num_rows > 0) {
            ApiResponse::error('This person has been blacklisted', 403);
        }

        // Generate QR and PIN
        $qrCode = generateQRCode();
        $pinCode = generatePinCode();

        $preRegistered = 1;
        $status = 'expected';

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_visitor
             (society_id, flat_id, name, phone, purpose, vehicle_number, visitor_type,
              pre_registered, qr_code, pin_code, valid_from, valid_until, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'iisssssisssssi',
            $societyId, $flatId, $name, $phone, $purpose, $vehicleNumber, $visitorType,
            $preRegistered, $qrCode, $pinCode, $validFrom, $validUntil, $status, $userId
        );

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to pre-register visitor', 500);
        }

        $visitorId = $stmt->insert_id;

        // Notify society guards
        $this->notifyGuards($societyId, 'New Visitor Expected',
            $name . ' is expected at your society. Visitor type: ' . $visitorType,
            'visitor', $visitorId);

        ApiResponse::created([
            'id' => $visitorId,
            'name' => $name,
            'phone' => $phone,
            'purpose' => $purpose,
            'vehicle_number' => $vehicleNumber,
            'visitor_type' => $visitorType,
            'qr_code' => $qrCode,
            'pin_code' => $pinCode,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'status' => $status
        ], 'Visitor pre-registered successfully');
    }

    // ---------------------------------------------------------------
    //  GET /visitors (list)
    // ---------------------------------------------------------------
    private function listVisitors() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $flatId = $this->auth->getFlatId();
        $isGuard = $this->auth->isGuard();

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $statusFilter = sanitizeInput($this->input['status'] ?? '');
        $typeFilter = sanitizeInput($this->input['visitor_type'] ?? '');
        $dateFrom = sanitizeInput($this->input['date_from'] ?? '');
        $dateTo = sanitizeInput($this->input['date_to'] ?? '');

        $where = "WHERE v.society_id = ?";
        $params = [$societyId];
        $types = 'i';

        if ($isGuard) {
            // Guards see expected, approved, checked_in visitors by default
            if (empty($statusFilter)) {
                $where .= " AND v.status IN ('expected', 'approved', 'checked_in')";
            }
        } else {
            // Residents see only visitors for their flat
            $where .= " AND v.flat_id = ?";
            $params[] = $flatId;
            $types .= 'i';
        }

        if (!empty($statusFilter)) {
            // "expected" tab should show both expected AND approved visitors
            if ($statusFilter === 'expected') {
                $where .= " AND v.status IN ('expected', 'approved')";
            } elseif ($statusFilter === 'pending') {
                // Dashboard: only truly pending approval (not yet approved)
                $where .= " AND v.status = 'expected'";
            } elseif ($statusFilter === 'checked_out') {
                $where .= " AND v.status IN ('checked_out', 'rejected', 'expired', 'cancelled')";
            } else {
                $where .= " AND v.status = ?";
                $params[] = $statusFilter;
                $types .= 's';
            }
        }
        if (!empty($typeFilter)) {
            $where .= " AND v.visitor_type = ?";
            $params[] = $typeFilter;
            $types .= 's';
        }
        if (!empty($dateFrom)) {
            $where .= " AND v.created_at >= ?";
            $params[] = $dateFrom;
            $types .= 's';
        }
        if (!empty($dateTo)) {
            $where .= " AND v.created_at <= ?";
            $params[] = $dateTo . ' 23:59:59';
            $types .= 's';
        }

        // Count
        $countStmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total FROM tbl_visitor v $where"
        );
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];

        // Fetch
        $fetchParams = array_merge($params, [$perPage, $offset]);
        $fetchTypes = $types . 'ii';

        $stmt = $this->conn->prepare(
            "SELECT v.id, v.society_id, v.flat_id, v.name, v.phone, v.purpose,
                    v.vehicle_number, v.visitor_type, v.photo, v.pre_registered,
                    v.qr_code, v.pin_code, v.valid_from, v.valid_until, v.status,
                    v.checked_in_at, v.checked_out_at, v.created_at,
                    f.flat_number, t.name AS tower_name
             FROM tbl_visitor v
             LEFT JOIN tbl_flat f ON f.id = v.flat_id
             LEFT JOIN tbl_tower t ON t.id = f.tower_id
             $where
             ORDER BY v.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param($fetchTypes, ...$fetchParams);
        $stmt->execute();
        $result = $stmt->get_result();

        $visitors = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['society_id'] = (int)$row['society_id'];
            $row['flat_id'] = $row['flat_id'] !== null ? (int)$row['flat_id'] : null;
            $row['pre_registered'] = (bool)$row['pre_registered'];
            $visitors[] = $row;
        }

        ApiResponse::paginated($visitors, $total, $page, $perPage, 'Visitors retrieved');
    }

    // ---------------------------------------------------------------
    //  GET /visitors/{id}
    // ---------------------------------------------------------------
    private function getVisitorDetail($id) {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        $stmt = $this->conn->prepare(
            "SELECT v.id, v.society_id, v.flat_id, v.name, v.phone, v.purpose,
                    v.vehicle_number, v.visitor_type, v.photo, v.pre_registered,
                    v.qr_code, v.pin_code, v.valid_from, v.valid_until, v.status,
                    v.approved_by, v.checked_in_at, v.checked_out_at,
                    v.created_by, v.created_at,
                    f.flat_number, t.name AS tower_name,
                    creator.name AS created_by_name,
                    approver.name AS approved_by_name,
                    verifier.name AS verified_by_name
             FROM tbl_visitor v
             LEFT JOIN tbl_flat f ON f.id = v.flat_id
             LEFT JOIN tbl_tower t ON t.id = f.tower_id
             LEFT JOIN tbl_user creator ON creator.id = v.created_by
             LEFT JOIN tbl_user approver ON approver.id = v.approved_by
             LEFT JOIN tbl_user verifier ON verifier.id = v.verified_by
             WHERE v.id = ? AND v.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Visitor not found');
        }

        $visitor = $result->fetch_assoc();
        $visitor['id'] = (int)$visitor['id'];
        $visitor['society_id'] = (int)$visitor['society_id'];
        $visitor['flat_id'] = $visitor['flat_id'] !== null ? (int)$visitor['flat_id'] : null;
        $visitor['pre_registered'] = (bool)$visitor['pre_registered'];

        // Build timeline
        $timeline = [];
        $timeline[] = [
            'event' => 'created',
            'timestamp' => $visitor['created_at'],
            'by' => $visitor['created_by_name']
        ];
        if ($visitor['approved_by']) {
            $timeline[] = [
                'event' => $visitor['status'] === 'rejected' ? 'rejected' : 'approved',
                'timestamp' => null,
                'by' => $visitor['approved_by_name']
            ];
        }
        if ($visitor['checked_in_at']) {
            $timeline[] = [
                'event' => 'checked_in',
                'timestamp' => $visitor['checked_in_at'],
                'by' => $visitor['verified_by_name']
            ];
        }
        if ($visitor['checked_out_at']) {
            $timeline[] = [
                'event' => 'checked_out',
                'timestamp' => $visitor['checked_out_at'],
                'by' => null
            ];
        }

        $visitor['timeline'] = $timeline;

        // Remove redundant join fields
        unset($visitor['created_by_name'], $visitor['approved_by_name'], $visitor['verified_by_name']);

        ApiResponse::success($visitor, 'Visitor details retrieved');
    }

    // ---------------------------------------------------------------
    //  PUT /visitors/{id}/approve
    // ---------------------------------------------------------------
    private function approveVisitor($id) {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $userId = $this->auth->getUserId();
        $flatId = $this->auth->getFlatId();

        $visitor = $this->getVisitorRecord($id, $societyId);

        // Only flat resident or is_primary can approve
        if ((int)$visitor['flat_id'] !== $flatId && !$this->auth->getUser()['is_primary']) {
            ApiResponse::forbidden('Only the flat resident or primary owner can approve visitors');
        }

        if ($visitor['status'] !== 'expected') {
            ApiResponse::error('Only visitors with status "expected" can be approved');
        }

        // Generate QR/PIN if not present
        $qrCode = $visitor['qr_code'];
        $pinCode = $visitor['pin_code'];
        if (empty($qrCode)) {
            $qrCode = generateQRCode();
        }
        if (empty($pinCode)) {
            $pinCode = generatePinCode();
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_visitor SET status = 'approved', approved_by = ?, qr_code = ?, pin_code = ?
             WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('issii', $userId, $qrCode, $pinCode, $id, $societyId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to approve visitor', 500);
        }

        // Notify guards
        $this->notifyGuards($societyId, 'Visitor Approved',
            $visitor['name'] . ' has been approved for entry.',
            'visitor', $id);

        ApiResponse::success([
            'id' => (int)$id,
            'status' => 'approved',
            'qr_code' => $qrCode,
            'pin_code' => $pinCode
        ], 'Visitor approved successfully');
    }

    // ---------------------------------------------------------------
    //  PUT /visitors/{id}/reject
    // ---------------------------------------------------------------
    private function rejectVisitor($id) {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $userId = $this->auth->getUserId();
        $flatId = $this->auth->getFlatId();

        $visitor = $this->getVisitorRecord($id, $societyId);

        // Only flat resident or is_primary can reject
        if ((int)$visitor['flat_id'] !== $flatId && !$this->auth->getUser()['is_primary']) {
            ApiResponse::forbidden('Only the flat resident or primary owner can reject visitors');
        }

        if (!in_array($visitor['status'], ['expected', 'approved'])) {
            ApiResponse::error('Only visitors with status "expected" or "approved" can be rejected');
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_visitor SET status = 'rejected', approved_by = ?
             WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('iii', $userId, $id, $societyId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to reject visitor', 500);
        }

        ApiResponse::success([
            'id' => (int)$id,
            'status' => 'rejected'
        ], 'Visitor rejected successfully');
    }

    // ---------------------------------------------------------------
    //  POST /visitors/walk-in
    // ---------------------------------------------------------------
    private function walkIn() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $userId = $this->auth->getUserId();

        if (!$this->auth->isGuard()) {
            ApiResponse::forbidden('Only guards can register walk-in visitors');
        }

        $name = sanitizeInput($this->input['name'] ?? '');
        $phone = sanitizeInput($this->input['phone'] ?? '');
        $purpose = sanitizeInput($this->input['purpose'] ?? '');
        $flatId = isset($this->input['flat_id']) ? (int)$this->input['flat_id'] : 0;
        $visitorType = sanitizeInput($this->input['visitor_type'] ?? 'guest');

        if (empty($name)) {
            ApiResponse::error('Visitor name is required');
        }
        if (empty($phone) || !validatePhone($phone)) {
            ApiResponse::error('Valid phone number is required');
        }
        if ($flatId <= 0) {
            ApiResponse::error('Valid flat ID is required');
        }

        $allowedTypes = ['guest', 'delivery', 'staff', 'vendor', 'cab'];
        if (!in_array($visitorType, $allowedTypes)) {
            ApiResponse::error('Invalid visitor type. Allowed: ' . implode(', ', $allowedTypes));
        }

        // Check blacklist
        $blStmt = $this->conn->prepare(
            "SELECT id FROM tbl_visitor_blacklist WHERE society_id = ? AND phone = ?"
        );
        $blStmt->bind_param('is', $societyId, $phone);
        $blStmt->execute();
        if ($blStmt->get_result()->num_rows > 0) {
            ApiResponse::error('This person has been blacklisted', 403);
        }

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

        $preRegistered = 0;
        $status = 'expected';

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_visitor
             (society_id, flat_id, name, phone, purpose, visitor_type,
              pre_registered, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'iissssisi',
            $societyId, $flatId, $name, $phone, $purpose, $visitorType,
            $preRegistered, $status, $userId
        );

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to register walk-in visitor', 500);
        }

        $visitorId = $stmt->insert_id;

        // Notify flat residents for approval
        $this->notifyFlatResidents($societyId, $flatId, 'Walk-in Visitor',
            $name . ' is at the gate and requesting entry. Purpose: ' . $purpose,
            'visitor', $visitorId);

        ApiResponse::created([
            'id' => $visitorId,
            'name' => $name,
            'phone' => $phone,
            'purpose' => $purpose,
            'flat_id' => $flatId,
            'visitor_type' => $visitorType,
            'status' => $status
        ], 'Walk-in visitor registered successfully');
    }

    // ---------------------------------------------------------------
    //  PUT /visitors/{id}/checkin
    // ---------------------------------------------------------------
    private function checkInVisitor($id) {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $userId = $this->auth->getUserId();

        if (!$this->auth->isGuard()) {
            ApiResponse::forbidden('Only guards can check in visitors');
        }

        $visitor = $this->getVisitorRecord($id, $societyId);

        if (!in_array($visitor['status'], ['approved', 'expected'])) {
            ApiResponse::error('Visitor must be in "approved" or "expected" status to check in');
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_visitor SET status = 'checked_in', checked_in_at = NOW(), verified_by = ?
             WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('iii', $userId, $id, $societyId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to check in visitor', 500);
        }

        // Notify flat residents
        $this->notifyFlatResidents($societyId, (int)$visitor['flat_id'], 'Visitor Checked In',
            $visitor['name'] . ' has entered the premises.',
            'visitor', $id);

        ApiResponse::success([
            'id' => (int)$id,
            'status' => 'checked_in',
            'checked_in_at' => date('Y-m-d H:i:s')
        ], 'Visitor checked in successfully');
    }

    // ---------------------------------------------------------------
    //  PUT /visitors/{id}/checkout
    // ---------------------------------------------------------------
    private function checkOutVisitor($id) {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        if (!$this->auth->isGuard()) {
            ApiResponse::forbidden('Only guards can check out visitors');
        }

        $visitor = $this->getVisitorRecord($id, $societyId);

        if ($visitor['status'] !== 'checked_in') {
            ApiResponse::error('Visitor must be in "checked_in" status to check out');
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_visitor SET status = 'checked_out', checked_out_at = NOW()
             WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to check out visitor', 500);
        }

        ApiResponse::success([
            'id' => (int)$id,
            'status' => 'checked_out',
            'checked_out_at' => date('Y-m-d H:i:s')
        ], 'Visitor checked out successfully');
    }

    // ---------------------------------------------------------------
    //  POST /visitors/blacklist
    // ---------------------------------------------------------------
    private function addToBlacklist() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();
        $userId = $this->auth->getUserId();

        $phone = sanitizeInput($this->input['phone'] ?? '');
        $name = sanitizeInput($this->input['name'] ?? '');
        $reason = sanitizeInput($this->input['reason'] ?? '');

        if (empty($phone) || !validatePhone($phone)) {
            ApiResponse::error('Valid phone number is required');
        }
        if (empty($name)) {
            ApiResponse::error('Name is required');
        }

        // Check if already blacklisted
        $checkStmt = $this->conn->prepare(
            "SELECT id FROM tbl_visitor_blacklist WHERE society_id = ? AND phone = ?"
        );
        $checkStmt->bind_param('is', $societyId, $phone);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            ApiResponse::error('This phone number is already blacklisted');
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_visitor_blacklist (society_id, phone, name, reason, blacklisted_by)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isssi', $societyId, $phone, $name, $reason, $userId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to blacklist visitor', 500);
        }

        ApiResponse::created([
            'id' => $stmt->insert_id,
            'phone' => $phone,
            'name' => $name,
            'reason' => $reason
        ], 'Visitor blacklisted successfully');
    }

    // ---------------------------------------------------------------
    //  GET /visitors/blacklist
    // ---------------------------------------------------------------
    private function listBlacklist() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $countStmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total FROM tbl_visitor_blacklist WHERE society_id = ?"
        );
        $countStmt->bind_param('i', $societyId);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];

        $stmt = $this->conn->prepare(
            "SELECT bl.id, bl.phone, bl.name, bl.reason, bl.blacklisted_by, bl.created_at,
                    u.name AS blacklisted_by_name
             FROM tbl_visitor_blacklist bl
             LEFT JOIN tbl_user u ON u.id = bl.blacklisted_by
             WHERE bl.society_id = ?
             ORDER BY bl.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('iii', $societyId, $perPage, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $entries = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['blacklisted_by'] = (int)$row['blacklisted_by'];
            $entries[] = $row;
        }

        ApiResponse::paginated($entries, $total, $page, $perPage, 'Blacklist retrieved');
    }

    // ---------------------------------------------------------------
    //  DELETE /visitors/blacklist/{id}
    // ---------------------------------------------------------------
    private function removeFromBlacklist($id) {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        // Verify record exists and belongs to this society
        $checkStmt = $this->conn->prepare(
            "SELECT id FROM tbl_visitor_blacklist WHERE id = ? AND society_id = ?"
        );
        $checkStmt->bind_param('ii', $id, $societyId);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Blacklist entry not found');
        }

        $stmt = $this->conn->prepare(
            "DELETE FROM tbl_visitor_blacklist WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to remove from blacklist', 500);
        }

        ApiResponse::success(null, 'Removed from blacklist successfully');
    }

    // ---------------------------------------------------------------
    //  GET /visitors/staff-entries
    // ---------------------------------------------------------------
    private function listStaffEntries() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $staffType = sanitizeInput($this->input['staff_type'] ?? '');
        $dateFilter = sanitizeInput($this->input['date'] ?? '');

        $where = "WHERE se.society_id = ?";
        $params = [$societyId];
        $types = 'i';

        if (!empty($staffType)) {
            $where .= " AND se.staff_type = ?";
            $params[] = $staffType;
            $types .= 's';
        }
        if (!empty($dateFilter)) {
            $where .= " AND DATE(se.check_in) = ?";
            $params[] = $dateFilter;
            $types .= 's';
        }

        $countStmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total FROM tbl_staff_entry se $where"
        );
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];

        $fetchParams = array_merge($params, [$perPage, $offset]);
        $fetchTypes = $types . 'ii';

        $stmt = $this->conn->prepare(
            "SELECT se.id, se.society_id, se.staff_name, se.staff_type, se.phone,
                    se.flat_id, se.photo, se.check_in, se.check_out, se.approved_by,
                    f.flat_number, t.name AS tower_name
             FROM tbl_staff_entry se
             LEFT JOIN tbl_flat f ON f.id = se.flat_id
             LEFT JOIN tbl_tower t ON t.id = f.tower_id
             $where
             ORDER BY se.check_in DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param($fetchTypes, ...$fetchParams);
        $stmt->execute();
        $result = $stmt->get_result();

        $entries = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['society_id'] = (int)$row['society_id'];
            $row['flat_id'] = $row['flat_id'] !== null ? (int)$row['flat_id'] : null;
            $entries[] = $row;
        }

        ApiResponse::paginated($entries, $total, $page, $perPage, 'Staff entries retrieved');
    }

    // ---------------------------------------------------------------
    //  POST /visitors/staff-entry
    // ---------------------------------------------------------------
    private function logStaffEntry() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        if (!$this->auth->isGuard()) {
            ApiResponse::forbidden('Only guards can log staff entries');
        }

        $staffName = sanitizeInput($this->input['staff_name'] ?? '');
        $staffType = sanitizeInput($this->input['staff_type'] ?? '');
        $phone = sanitizeInput($this->input['phone'] ?? '');
        $flatId = isset($this->input['flat_id']) ? (int)$this->input['flat_id'] : 0;

        if (empty($staffName)) {
            ApiResponse::error('Staff name is required');
        }
        if (empty($staffType)) {
            ApiResponse::error('Staff type is required');
        }
        if ($flatId <= 0) {
            ApiResponse::error('Valid flat ID is required');
        }

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

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_staff_entry (society_id, staff_name, staff_type, phone, flat_id, check_in)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param('isssi', $societyId, $staffName, $staffType, $phone, $flatId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to log staff entry', 500);
        }

        $entryId = $stmt->insert_id;

        // Notify flat residents
        $this->notifyFlatResidents($societyId, $flatId, 'Staff Arrived',
            $staffName . ' (' . $staffType . ') has checked in.',
            'staff_entry', $entryId);

        ApiResponse::created([
            'id' => $entryId,
            'staff_name' => $staffName,
            'staff_type' => $staffType,
            'phone' => $phone,
            'flat_id' => $flatId,
            'check_in' => date('Y-m-d H:i:s')
        ], 'Staff entry logged successfully');
    }

    // ---------------------------------------------------------------
    //  PUT /visitors/staff-entry/{id}/checkout
    // ---------------------------------------------------------------
    private function checkOutStaff($id) {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        if (!$this->auth->isGuard()) {
            ApiResponse::forbidden('Only guards can check out staff');
        }

        $checkStmt = $this->conn->prepare(
            "SELECT id, check_out FROM tbl_staff_entry WHERE id = ? AND society_id = ?"
        );
        $checkStmt->bind_param('ii', $id, $societyId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Staff entry not found');
        }

        $entry = $result->fetch_assoc();
        if (!empty($entry['check_out'])) {
            ApiResponse::error('Staff member has already been checked out');
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_staff_entry SET check_out = NOW() WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to check out staff', 500);
        }

        ApiResponse::success([
            'id' => (int)$id,
            'check_out' => date('Y-m-d H:i:s')
        ], 'Staff checked out successfully');
    }

    // ---------------------------------------------------------------
    //  GET /visitors/logs
    // ---------------------------------------------------------------
    private function getVisitorLogs() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $dateFrom = sanitizeInput($this->input['date_from'] ?? '');
        $dateTo = sanitizeInput($this->input['date_to'] ?? '');

        $where = "WHERE v.society_id = ? AND v.status = 'checked_out'";
        $params = [$societyId];
        $types = 'i';

        if (!empty($dateFrom)) {
            $where .= " AND v.checked_out_at >= ?";
            $params[] = $dateFrom;
            $types .= 's';
        }
        if (!empty($dateTo)) {
            $where .= " AND v.checked_out_at <= ?";
            $params[] = $dateTo . ' 23:59:59';
            $types .= 's';
        }

        $countStmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total FROM tbl_visitor v $where"
        );
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];

        $fetchParams = array_merge($params, [$perPage, $offset]);
        $fetchTypes = $types . 'ii';

        $stmt = $this->conn->prepare(
            "SELECT v.id, v.name, v.phone, v.purpose, v.vehicle_number, v.visitor_type,
                    v.pre_registered, v.checked_in_at, v.checked_out_at,
                    v.flat_id, f.flat_number, t.name AS tower_name
             FROM tbl_visitor v
             LEFT JOIN tbl_flat f ON f.id = v.flat_id
             LEFT JOIN tbl_tower t ON t.id = f.tower_id
             $where
             ORDER BY v.checked_out_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param($fetchTypes, ...$fetchParams);
        $stmt->execute();
        $result = $stmt->get_result();

        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['flat_id'] = $row['flat_id'] !== null ? (int)$row['flat_id'] : null;
            $row['pre_registered'] = (bool)$row['pre_registered'];
            $logs[] = $row;
        }

        ApiResponse::paginated($logs, $total, $page, $perPage, 'Visitor logs retrieved');
    }

    // ---------------------------------------------------------------
    //  Helper: fetch a visitor record and verify it belongs to society
    // ---------------------------------------------------------------
    private function getVisitorRecord($id, $societyId) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM tbl_visitor WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Visitor not found');
        }

        return $result->fetch_assoc();
    }

    // ---------------------------------------------------------------
    //  Helper: notify all guards in a society
    // ---------------------------------------------------------------
    private function notifyGuards($societyId, $title, $body, $refType, $refId) {
        $stmt = $this->conn->prepare(
            "SELECT user_id FROM tbl_resident WHERE society_id = ? AND is_guard = 1 AND status = 'approved'"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            storeNotification($this->conn, $societyId, (int)$row['user_id'], $title, $body, 'visitor', $refType, $refId);
        }
    }

    // ---------------------------------------------------------------
    //  Helper: notify all residents in a flat
    // ---------------------------------------------------------------
    private function notifyFlatResidents($societyId, $flatId, $title, $body, $refType, $refId) {
        $stmt = $this->conn->prepare(
            "SELECT user_id FROM tbl_resident WHERE society_id = ? AND flat_id = ? AND status = 'approved'"
        );
        $stmt->bind_param('ii', $societyId, $flatId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            storeNotification($this->conn, $societyId, (int)$row['user_id'], $title, $body, 'visitor', $refType, $refId);
        }
    }

    /**
     * GET /visitors/analytics — visitor statistics for the society
     */
    private function getAnalytics() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        $from = $this->input['from'] ?? date('Y-m-01');
        $to = $this->input['to'] ?? date('Y-m-d');

        // Total by type
        $stmt = $this->conn->prepare(
            "SELECT visitor_type, COUNT(*) as count
             FROM tbl_visitor WHERE society_id = ? AND created_at BETWEEN ? AND ?
             GROUP BY visitor_type"
        );
        $fromDate = $from . ' 00:00:00';
        $toDate = $to . ' 23:59:59';
        $stmt->bind_param('iss', $societyId, $fromDate, $toDate);
        $stmt->execute();
        $byType = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Daily trend
        $stmt = $this->conn->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as count
             FROM tbl_visitor WHERE society_id = ? AND created_at BETWEEN ? AND ?
             GROUP BY DATE(created_at) ORDER BY date"
        );
        $stmt->bind_param('iss', $societyId, $fromDate, $toDate);
        $stmt->execute();
        $dailyTrend = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Peak hours
        $stmt = $this->conn->prepare(
            "SELECT HOUR(created_at) as hour, COUNT(*) as count
             FROM tbl_visitor WHERE society_id = ? AND created_at BETWEEN ? AND ?
             GROUP BY HOUR(created_at) ORDER BY hour"
        );
        $stmt->bind_param('iss', $societyId, $fromDate, $toDate);
        $stmt->execute();
        $byHour = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Total count
        $total = 0;
        foreach ($byType as $t) {
            $total += (int)$t['count'];
        }

        ApiResponse::success([
            'total' => $total,
            'by_type' => $byType,
            'daily_trend' => $dailyTrend,
            'by_hour' => $byHour,
            'from' => $from,
            'to' => $to,
        ]);
    }
}
