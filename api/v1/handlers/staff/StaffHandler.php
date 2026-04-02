<?php
/**
 * Securis Smart Society Platform — Staff Management Handler
 * Endpoints for domestic staff registration, assignment, attendance,
 * verification, and blacklisting.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class StaffHandler {
    private $conn;
    private $auth;
    private $input;

    public function __construct($conn, $auth, $input) {
        $this->conn = $conn;
        $this->auth = $auth;
        $this->input = $input;
    }

    /**
     * Route: /api/v1/staff/{action_or_id}/{sub_action}
     */
    public function handle($method, $action, $id) {
        switch ($method) {
            case 'GET':
                if ($action === 'list') {
                    $this->listStaff();
                } elseif ($action === 'my-staff') {
                    $this->myStaff();
                } elseif ($action === 'attendance') {
                    $this->getAttendanceReport();
                } elseif ($id && !$action) {
                    $this->getStaffDetail($id);
                } else {
                    ApiResponse::notFound('Staff endpoint not found');
                }
                break;

            case 'POST':
                if ($action === 'register') {
                    $this->registerStaff();
                } elseif ($action === 'assign') {
                    $this->assignStaff();
                } elseif ($action === 'attendance') {
                    $this->markAttendance();
                } else {
                    ApiResponse::error('Method not allowed', 405);
                }
                break;

            case 'PUT':
                if ($id && $action === 'verify') {
                    $this->verifyStaff($id);
                } elseif ($id && $action === 'blacklist') {
                    $this->blacklistStaff($id);
                } elseif ($id && !$action) {
                    $this->updateStaff($id);
                } else {
                    ApiResponse::error('Method not allowed', 405);
                }
                break;

            case 'DELETE':
                if ($action === 'assign' && $id) {
                    $this->removeAssignment($id);
                } else {
                    ApiResponse::error('Method not allowed', 405);
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ---------------------------------------------------------------
    //  GET /staff/list
    // ---------------------------------------------------------------
    private function listStaff() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $staffType = sanitizeInput($this->input['staff_type'] ?? '');
        $statusFilter = sanitizeInput($this->input['status'] ?? '');
        $search = sanitizeInput($this->input['search'] ?? '');

        $where = "WHERE ds.society_id = ?";
        $params = [$societyId];
        $types = 'i';

        if (!empty($staffType)) {
            $where .= " AND ds.staff_type = ?";
            $params[] = $staffType;
            $types .= 's';
        }
        if (!empty($statusFilter)) {
            $where .= " AND ds.status = ?";
            $params[] = $statusFilter;
            $types .= 's';
        }
        if (!empty($search)) {
            $where .= " AND (ds.name LIKE ? OR ds.phone LIKE ?)";
            $searchParam = '%' . $search . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= 'ss';
        }

        // Count
        $countStmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total FROM tbl_domestic_staff ds $where"
        );
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];

        // Fetch
        $fetchParams = array_merge($params, [$perPage, $offset]);
        $fetchTypes = $types . 'ii';

        $stmt = $this->conn->prepare(
            "SELECT ds.id, ds.society_id, ds.name, ds.phone, ds.photo,
                    ds.staff_type, ds.id_proof_type, ds.id_proof_number,
                    ds.is_verified, ds.status, ds.created_at
             FROM tbl_domestic_staff ds
             $where
             ORDER BY ds.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param($fetchTypes, ...$fetchParams);
        $stmt->execute();
        $result = $stmt->get_result();

        $staff = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['society_id'] = (int)$row['society_id'];
            $row['is_verified'] = (bool)$row['is_verified'];
            $staff[] = $row;
        }

        ApiResponse::paginated($staff, $total, $page, $perPage, 'Staff list retrieved');
    }

    // ---------------------------------------------------------------
    //  GET /staff/{id}
    // ---------------------------------------------------------------
    private function getStaffDetail($id) {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        $stmt = $this->conn->prepare(
            "SELECT ds.id, ds.society_id, ds.name, ds.phone, ds.photo,
                    ds.staff_type, ds.id_proof_type, ds.id_proof_number,
                    ds.id_proof_image, ds.address, ds.is_verified,
                    ds.status, ds.created_at
             FROM tbl_domestic_staff ds
             WHERE ds.id = ? AND ds.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Staff member not found');
        }

        $staffMember = $result->fetch_assoc();
        $staffMember['id'] = (int)$staffMember['id'];
        $staffMember['society_id'] = (int)$staffMember['society_id'];
        $staffMember['is_verified'] = (bool)$staffMember['is_verified'];

        // Fetch active assignments
        $assignStmt = $this->conn->prepare(
            "SELECT sa.id, sa.flat_id, sa.schedule_json, sa.start_date,
                    sa.end_date, sa.is_active, sa.approved_by, sa.created_at,
                    f.flat_number, t.name AS tower_name
             FROM tbl_staff_assignment sa
             LEFT JOIN tbl_flat f ON f.id = sa.flat_id
             LEFT JOIN tbl_tower t ON t.id = f.tower_id
             WHERE sa.staff_id = ? AND sa.is_active = 1
             ORDER BY sa.created_at DESC"
        );
        $assignStmt->bind_param('i', $id);
        $assignStmt->execute();
        $assignResult = $assignStmt->get_result();

        $assignments = [];
        while ($row = $assignResult->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['flat_id'] = (int)$row['flat_id'];
            $row['is_active'] = (bool)$row['is_active'];
            $row['approved_by'] = $row['approved_by'] !== null ? (int)$row['approved_by'] : null;
            $row['schedule_json'] = $row['schedule_json'] ? json_decode($row['schedule_json'], true) : null;
            $assignments[] = $row;
        }

        $staffMember['assignments'] = $assignments;

        ApiResponse::success($staffMember, 'Staff details retrieved');
    }

    // ---------------------------------------------------------------
    //  POST /staff/register
    // ---------------------------------------------------------------
    private function registerStaff() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        $name = sanitizeInput($this->input['name'] ?? '');
        $phone = sanitizeInput($this->input['phone'] ?? '');
        $staffType = sanitizeInput($this->input['staff_type'] ?? '');
        $idProofType = sanitizeInput($this->input['id_proof_type'] ?? '');
        $idProofNumber = sanitizeInput($this->input['id_proof_number'] ?? '');
        $address = sanitizeInput($this->input['address'] ?? '');

        if (empty($name)) {
            ApiResponse::error('Staff name is required');
        }
        if (empty($staffType)) {
            ApiResponse::error('Staff type is required');
        }

        $allowedTypes = ['maid', 'driver', 'cook', 'gardener', 'watchman', 'nanny', 'other'];
        if (!in_array($staffType, $allowedTypes)) {
            ApiResponse::error('Invalid staff type. Allowed: ' . implode(', ', $allowedTypes));
        }

        // Check for duplicate by phone within society
        if (!empty($phone)) {
            $dupStmt = $this->conn->prepare(
                "SELECT id FROM tbl_domestic_staff WHERE society_id = ? AND phone = ? AND status != 'inactive'"
            );
            $dupStmt->bind_param('is', $societyId, $phone);
            $dupStmt->execute();
            if ($dupStmt->get_result()->num_rows > 0) {
                ApiResponse::error('A staff member with this phone number already exists in the society');
            }
        }

        // Handle photo upload
        $photo = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['photo'], 'staff', ['jpg', 'jpeg', 'png', 'webp']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error'], 400);
            }
            $photo = $upload['path'];
        }

        // Handle ID proof image upload
        $idProofImage = null;
        if (isset($_FILES['id_proof_image']) && $_FILES['id_proof_image']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['id_proof_image'], 'staff_id_proofs', ['jpg', 'jpeg', 'png', 'webp', 'pdf']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error'], 400);
            }
            $idProofImage = $upload['path'];
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_domestic_staff
             (society_id, name, phone, photo, staff_type, id_proof_type,
              id_proof_number, id_proof_image, address, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')"
        );
        $stmt->bind_param(
            'issssssss',
            $societyId, $name, $phone, $photo, $staffType,
            $idProofType, $idProofNumber, $idProofImage, $address
        );

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to register staff', 500);
        }

        $staffId = $stmt->insert_id;

        ApiResponse::created([
            'id' => $staffId,
            'name' => $name,
            'phone' => $phone,
            'photo' => $photo,
            'staff_type' => $staffType,
            'id_proof_type' => $idProofType,
            'id_proof_number' => $idProofNumber,
            'id_proof_image' => $idProofImage,
            'address' => $address,
            'status' => 'active',
            'is_verified' => false
        ], 'Staff registered successfully');
    }

    // ---------------------------------------------------------------
    //  PUT /staff/{id}
    // ---------------------------------------------------------------
    private function updateStaff($id) {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        // Verify staff exists in this society
        $checkStmt = $this->conn->prepare(
            "SELECT id FROM tbl_domestic_staff WHERE id = ? AND society_id = ?"
        );
        $checkStmt->bind_param('ii', $id, $societyId);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Staff member not found');
        }

        // Build dynamic update
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
        if (isset($this->input['staff_type'])) {
            $staffType = sanitizeInput($this->input['staff_type']);
            $allowedTypes = ['maid', 'driver', 'cook', 'gardener', 'watchman', 'nanny', 'other'];
            if (!in_array($staffType, $allowedTypes)) {
                ApiResponse::error('Invalid staff type. Allowed: ' . implode(', ', $allowedTypes));
            }
            $fields[] = 'staff_type = ?';
            $params[] = $staffType;
            $types .= 's';
        }
        if (isset($this->input['id_proof_type'])) {
            $fields[] = 'id_proof_type = ?';
            $params[] = sanitizeInput($this->input['id_proof_type']);
            $types .= 's';
        }
        if (isset($this->input['id_proof_number'])) {
            $fields[] = 'id_proof_number = ?';
            $params[] = sanitizeInput($this->input['id_proof_number']);
            $types .= 's';
        }
        if (isset($this->input['address'])) {
            $fields[] = 'address = ?';
            $params[] = sanitizeInput($this->input['address']);
            $types .= 's';
        }
        if (isset($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $allowedStatuses = ['active', 'inactive'];
            if (!in_array($status, $allowedStatuses)) {
                ApiResponse::error('Invalid status. Allowed: active, inactive');
            }
            $fields[] = 'status = ?';
            $params[] = $status;
            $types .= 's';
        }

        // Handle photo upload
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['photo'], 'staff', ['jpg', 'jpeg', 'png', 'webp']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error'], 400);
            }
            $fields[] = 'photo = ?';
            $params[] = $upload['path'];
            $types .= 's';
        }

        // Handle ID proof image upload
        if (isset($_FILES['id_proof_image']) && $_FILES['id_proof_image']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['id_proof_image'], 'staff_id_proofs', ['jpg', 'jpeg', 'png', 'webp', 'pdf']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error'], 400);
            }
            $fields[] = 'id_proof_image = ?';
            $params[] = $upload['path'];
            $types .= 's';
        }

        if (empty($fields)) {
            ApiResponse::error('No fields to update', 400);
        }

        $sql = "UPDATE tbl_domestic_staff SET " . implode(', ', $fields) . " WHERE id = ? AND society_id = ?";
        $params[] = $id;
        $params[] = $societyId;
        $types .= 'ii';

        $updateStmt = $this->conn->prepare($sql);
        $updateStmt->bind_param($types, ...$params);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to update staff', 500);
        }

        // Return updated record
        $fetchStmt = $this->conn->prepare(
            "SELECT id, society_id, name, phone, photo, staff_type, id_proof_type,
                    id_proof_number, id_proof_image, address, is_verified, status, created_at
             FROM tbl_domestic_staff WHERE id = ?"
        );
        $fetchStmt->bind_param('i', $id);
        $fetchStmt->execute();
        $staffMember = $fetchStmt->get_result()->fetch_assoc();
        $staffMember['id'] = (int)$staffMember['id'];
        $staffMember['society_id'] = (int)$staffMember['society_id'];
        $staffMember['is_verified'] = (bool)$staffMember['is_verified'];

        ApiResponse::success($staffMember, 'Staff updated successfully');
    }

    // ---------------------------------------------------------------
    //  PUT /staff/{id}/verify
    // ---------------------------------------------------------------
    private function verifyStaff($id) {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $staffMember = $this->getStaffRecord($id, $societyId);

        if ((bool)$staffMember['is_verified']) {
            ApiResponse::error('Staff member is already verified');
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_domestic_staff SET is_verified = 1
             WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to verify staff', 500);
        }

        ApiResponse::success([
            'id' => (int)$id,
            'is_verified' => true,
            'status' => $staffMember['status']
        ], 'Staff verified successfully');
    }

    // ---------------------------------------------------------------
    //  PUT /staff/{id}/blacklist
    // ---------------------------------------------------------------
    private function blacklistStaff($id) {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $staffMember = $this->getStaffRecord($id, $societyId);

        if ($staffMember['status'] === 'blacklisted') {
            ApiResponse::error('Staff member is already blacklisted');
        }

        // Blacklist the staff member
        $stmt = $this->conn->prepare(
            "UPDATE tbl_domestic_staff SET status = 'blacklisted'
             WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to blacklist staff', 500);
        }

        // Deactivate all active assignments
        $deactivateStmt = $this->conn->prepare(
            "UPDATE tbl_staff_assignment SET is_active = 0, end_date = CURDATE()
             WHERE staff_id = ? AND is_active = 1"
        );
        $deactivateStmt->bind_param('i', $id);
        $deactivateStmt->execute();

        ApiResponse::success([
            'id' => (int)$id,
            'status' => 'blacklisted'
        ], 'Staff blacklisted successfully');
    }

    // ---------------------------------------------------------------
    //  POST /staff/assign
    // ---------------------------------------------------------------
    private function assignStaff() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $userId = $this->auth->getUserId();

        $staffId = isset($this->input['staff_id']) ? (int)$this->input['staff_id'] : 0;
        $flatId = isset($this->input['flat_id']) ? (int)$this->input['flat_id'] : 0;
        $scheduleJson = $this->input['schedule_json'] ?? null;
        $startDate = sanitizeInput($this->input['start_date'] ?? date('Y-m-d'));
        $endDate = sanitizeInput($this->input['end_date'] ?? '');

        if ($staffId <= 0) {
            ApiResponse::error('Valid staff_id is required');
        }
        if ($flatId <= 0) {
            ApiResponse::error('Valid flat_id is required');
        }

        // Verify staff belongs to this society and is active
        $staffStmt = $this->conn->prepare(
            "SELECT id, status FROM tbl_domestic_staff WHERE id = ? AND society_id = ?"
        );
        $staffStmt->bind_param('ii', $staffId, $societyId);
        $staffStmt->execute();
        $staffResult = $staffStmt->get_result();

        if ($staffResult->num_rows === 0) {
            ApiResponse::notFound('Staff member not found');
        }

        $staffRecord = $staffResult->fetch_assoc();
        if ($staffRecord['status'] !== 'active') {
            ApiResponse::error('Cannot assign staff with status: ' . $staffRecord['status']);
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

        // Check for existing active assignment for same staff-flat combination
        $dupStmt = $this->conn->prepare(
            "SELECT id FROM tbl_staff_assignment
             WHERE staff_id = ? AND flat_id = ? AND is_active = 1"
        );
        $dupStmt->bind_param('ii', $staffId, $flatId);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) {
            ApiResponse::error('This staff member is already assigned to this flat');
        }

        // Process schedule_json
        $scheduleJsonStr = null;
        if ($scheduleJson !== null) {
            if (is_array($scheduleJson)) {
                $scheduleJsonStr = json_encode($scheduleJson);
            } elseif (is_string($scheduleJson)) {
                $decoded = json_decode($scheduleJson, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    ApiResponse::error('Invalid schedule_json format');
                }
                $scheduleJsonStr = $scheduleJson;
            }
        }

        $endDateParam = !empty($endDate) ? $endDate : null;

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_staff_assignment
             (staff_id, flat_id, schedule_json, start_date, end_date, is_active, approved_by)
             VALUES (?, ?, ?, ?, ?, 1, ?)"
        );
        $stmt->bind_param('iisssi', $staffId, $flatId, $scheduleJsonStr, $startDate, $endDateParam, $userId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to assign staff', 500);
        }

        $assignmentId = $stmt->insert_id;

        ApiResponse::created([
            'id' => $assignmentId,
            'staff_id' => $staffId,
            'flat_id' => $flatId,
            'schedule_json' => $scheduleJson,
            'start_date' => $startDate,
            'end_date' => $endDateParam,
            'is_active' => true
        ], 'Staff assigned successfully');
    }

    // ---------------------------------------------------------------
    //  DELETE /staff/assign/{id}
    // ---------------------------------------------------------------
    private function removeAssignment($id) {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        // Verify assignment exists and belongs to this society
        $checkStmt = $this->conn->prepare(
            "SELECT sa.id, sa.staff_id, sa.flat_id, sa.is_active
             FROM tbl_staff_assignment sa
             INNER JOIN tbl_domestic_staff ds ON ds.id = sa.staff_id
             WHERE sa.id = ? AND ds.society_id = ?"
        );
        $checkStmt->bind_param('ii', $id, $societyId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Staff assignment not found');
        }

        $assignment = $result->fetch_assoc();
        if (!(bool)$assignment['is_active']) {
            ApiResponse::error('This assignment is already inactive');
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_staff_assignment SET is_active = 0, end_date = CURDATE()
             WHERE id = ?"
        );
        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to remove assignment', 500);
        }

        ApiResponse::success([
            'id' => (int)$id,
            'is_active' => false,
            'end_date' => date('Y-m-d')
        ], 'Staff assignment removed successfully');
    }

    // ---------------------------------------------------------------
    //  GET /staff/my-staff
    // ---------------------------------------------------------------
    private function myStaff() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $flatId = $this->auth->getFlatId();

        if (!$flatId) {
            ApiResponse::error('No flat associated with your account');
        }

        $stmt = $this->conn->prepare(
            "SELECT ds.id, ds.name, ds.phone, ds.photo, ds.staff_type,
                    ds.is_verified, ds.status,
                    sa.id AS assignment_id, sa.schedule_json,
                    sa.start_date, sa.end_date
             FROM tbl_staff_assignment sa
             INNER JOIN tbl_domestic_staff ds ON ds.id = sa.staff_id
             WHERE sa.flat_id = ? AND sa.is_active = 1 AND ds.society_id = ?
             ORDER BY ds.name ASC"
        );
        $stmt->bind_param('ii', $flatId, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        $staff = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['is_verified'] = (bool)$row['is_verified'];
            $row['assignment_id'] = (int)$row['assignment_id'];
            $row['schedule_json'] = $row['schedule_json'] ? json_decode($row['schedule_json'], true) : null;
            $staff[] = $row;
        }

        ApiResponse::success($staff, 'My staff list retrieved');
    }

    // ---------------------------------------------------------------
    //  POST /staff/attendance
    // ---------------------------------------------------------------
    private function markAttendance() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $userId = $this->auth->getUserId();
        $flatId = $this->auth->getFlatId();

        $staffId = isset($this->input['staff_id']) ? (int)$this->input['staff_id'] : 0;
        $inputFlatId = isset($this->input['flat_id']) ? (int)$this->input['flat_id'] : $flatId;
        $status = sanitizeInput($this->input['status'] ?? 'present');
        $attendanceDate = sanitizeInput($this->input['attendance_date'] ?? date('Y-m-d'));
        $checkInTime = sanitizeInput($this->input['check_in_time'] ?? '');
        $checkOutTime = sanitizeInput($this->input['check_out_time'] ?? '');

        if ($staffId <= 0) {
            ApiResponse::error('Valid staff_id is required');
        }
        if ($inputFlatId <= 0) {
            ApiResponse::error('Valid flat_id is required');
        }

        $allowedStatuses = ['present', 'absent', 'half_day', 'leave'];
        if (!in_array($status, $allowedStatuses)) {
            ApiResponse::error('Invalid status. Allowed: ' . implode(', ', $allowedStatuses));
        }

        // Verify staff belongs to this society
        $staffStmt = $this->conn->prepare(
            "SELECT id FROM tbl_domestic_staff WHERE id = ? AND society_id = ?"
        );
        $staffStmt->bind_param('ii', $staffId, $societyId);
        $staffStmt->execute();
        if ($staffStmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Staff member not found');
        }

        // Verify staff is assigned to this flat
        $assignStmt = $this->conn->prepare(
            "SELECT id FROM tbl_staff_assignment
             WHERE staff_id = ? AND flat_id = ? AND is_active = 1"
        );
        $assignStmt->bind_param('ii', $staffId, $inputFlatId);
        $assignStmt->execute();
        if ($assignStmt->get_result()->num_rows === 0) {
            ApiResponse::error('Staff member is not assigned to this flat');
        }

        // Check for existing attendance record
        $existStmt = $this->conn->prepare(
            "SELECT id FROM tbl_staff_attendance
             WHERE staff_id = ? AND flat_id = ? AND attendance_date = ?"
        );
        $existStmt->bind_param('iis', $staffId, $inputFlatId, $attendanceDate);
        $existStmt->execute();
        $existResult = $existStmt->get_result();

        $checkInParam = !empty($checkInTime) ? $checkInTime : null;
        $checkOutParam = !empty($checkOutTime) ? $checkOutTime : null;

        if ($existResult->num_rows > 0) {
            // Update existing attendance
            $existingId = $existResult->fetch_assoc()['id'];
            $updateStmt = $this->conn->prepare(
                "UPDATE tbl_staff_attendance
                 SET status = ?, check_in_time = ?, check_out_time = ?, marked_by = ?
                 WHERE id = ?"
            );
            $updateStmt->bind_param('sssii', $status, $checkInParam, $checkOutParam, $userId, $existingId);

            if (!$updateStmt->execute()) {
                ApiResponse::error('Failed to update attendance', 500);
            }

            ApiResponse::success([
                'id' => (int)$existingId,
                'staff_id' => $staffId,
                'flat_id' => $inputFlatId,
                'attendance_date' => $attendanceDate,
                'status' => $status,
                'check_in_time' => $checkInParam,
                'check_out_time' => $checkOutParam
            ], 'Attendance updated successfully');
        } else {
            // Insert new attendance
            $insertStmt = $this->conn->prepare(
                "INSERT INTO tbl_staff_attendance
                 (staff_id, flat_id, attendance_date, check_in_time, check_out_time, status, marked_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $insertStmt->bind_param('iissssi', $staffId, $inputFlatId, $attendanceDate, $checkInParam, $checkOutParam, $status, $userId);

            if (!$insertStmt->execute()) {
                ApiResponse::error('Failed to mark attendance', 500);
            }

            $attendanceId = $insertStmt->insert_id;

            ApiResponse::created([
                'id' => $attendanceId,
                'staff_id' => $staffId,
                'flat_id' => $inputFlatId,
                'attendance_date' => $attendanceDate,
                'status' => $status,
                'check_in_time' => $checkInParam,
                'check_out_time' => $checkOutParam
            ], 'Attendance marked successfully');
        }
    }

    // ---------------------------------------------------------------
    //  GET /staff/attendance
    // ---------------------------------------------------------------
    private function getAttendanceReport() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $flatId = $this->auth->getFlatId();

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $staffId = isset($this->input['staff_id']) ? (int)$this->input['staff_id'] : 0;
        $filterFlatId = isset($this->input['flat_id']) ? (int)$this->input['flat_id'] : 0;
        $dateFrom = sanitizeInput($this->input['date_from'] ?? '');
        $dateTo = sanitizeInput($this->input['date_to'] ?? '');
        $statusFilter = sanitizeInput($this->input['status'] ?? '');

        $where = "WHERE ds.society_id = ?";
        $params = [$societyId];
        $types = 'i';

        if ($staffId > 0) {
            $where .= " AND att.staff_id = ?";
            $params[] = $staffId;
            $types .= 'i';
        }
        if ($filterFlatId > 0) {
            $where .= " AND att.flat_id = ?";
            $params[] = $filterFlatId;
            $types .= 'i';
        }
        if (!empty($dateFrom)) {
            $where .= " AND att.attendance_date >= ?";
            $params[] = $dateFrom;
            $types .= 's';
        }
        if (!empty($dateTo)) {
            $where .= " AND att.attendance_date <= ?";
            $params[] = $dateTo;
            $types .= 's';
        }
        if (!empty($statusFilter)) {
            $where .= " AND att.status = ?";
            $params[] = $statusFilter;
            $types .= 's';
        }

        // Count
        $countStmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total
             FROM tbl_staff_attendance att
             INNER JOIN tbl_domestic_staff ds ON ds.id = att.staff_id
             $where"
        );
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];

        // Fetch
        $fetchParams = array_merge($params, [$perPage, $offset]);
        $fetchTypes = $types . 'ii';

        $stmt = $this->conn->prepare(
            "SELECT att.id, att.staff_id, att.flat_id, att.attendance_date,
                    att.check_in_time, att.check_out_time, att.status,
                    att.marked_by, att.created_at,
                    ds.name AS staff_name, ds.staff_type,
                    f.flat_number, t.name AS tower_name
             FROM tbl_staff_attendance att
             INNER JOIN tbl_domestic_staff ds ON ds.id = att.staff_id
             LEFT JOIN tbl_flat f ON f.id = att.flat_id
             LEFT JOIN tbl_tower t ON t.id = f.tower_id
             $where
             ORDER BY att.attendance_date DESC, ds.name ASC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param($fetchTypes, ...$fetchParams);
        $stmt->execute();
        $result = $stmt->get_result();

        $records = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['staff_id'] = (int)$row['staff_id'];
            $row['flat_id'] = (int)$row['flat_id'];
            $row['marked_by'] = $row['marked_by'] !== null ? (int)$row['marked_by'] : null;
            $records[] = $row;
        }

        ApiResponse::paginated($records, $total, $page, $perPage, 'Attendance report retrieved');
    }

    // ---------------------------------------------------------------
    //  Helper: fetch a staff record and verify it belongs to society
    // ---------------------------------------------------------------
    private function getStaffRecord($id, $societyId) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM tbl_domestic_staff WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Staff member not found');
        }

        return $result->fetch_assoc();
    }
}
