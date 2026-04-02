<?php
/**
 * Securis Smart Society Platform — Move-in/Move-out Handler
 * Manages move requests: create, approve, complete, cancel, and deposit tracking.
 */

require_once __DIR__ . '/../../../../include/helpers.php';
require_once __DIR__ . '/../../../../include/security.php';

class MoveHandler {
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
                    $this->getMoveRequest($id);
                } else {
                    $this->listMoveRequests();
                }
                break;

            case 'POST':
                $this->createMoveRequest();
                break;

            case 'PUT':
                if (!$id) {
                    ApiResponse::error('Move request ID is required', 400);
                }
                $this->handlePutAction($id, $action);
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ─── PUT action routing ─────────────────────────────────────────────

    private function handlePutAction($id, $action) {
        switch ($action) {
            case 'approve':
                $this->approveMoveRequest($id);
                break;
            case 'complete':
                $this->completeMoveRequest($id);
                break;
            case 'cancel':
                $this->cancelMoveRequest($id);
                break;
            case 'deposit':
                $this->updateDeposit($id);
                break;
            default:
                ApiResponse::error('Invalid action', 400);
        }
    }

    // ─── GET /api/v1/move/requests ──────────────────────────────────────

    /**
     * List move requests. Admin (primary) sees all for the society,
     * resident sees only their own.
     * Filters: type (move_in/move_out), status. Paginated.
     */
    private function listMoveRequests() {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);
        $isPrimary = $this->user['is_primary'];
        $residentId = $this->auth->getResidentId();

        $where = "mr.society_id = ?";
        $params = [$this->societyId];
        $types = 'i';

        // Non-primary users see only their own requests
        if (!$isPrimary) {
            $where .= " AND mr.resident_id = ?";
            $params[] = $residentId;
            $types .= 'i';
        }

        // Filter by type
        if (!empty($this->input['type'])) {
            $type = sanitizeInput($this->input['type']);
            $allowedTypes = ['move_in', 'move_out'];
            if (in_array($type, $allowedTypes)) {
                $where .= " AND mr.type = ?";
                $params[] = $type;
                $types .= 's';
            }
        }

        // Filter by status
        if (!empty($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $allowedStatuses = ['pending', 'approved', 'in_progress', 'completed', 'cancelled'];
            if (in_array($status, $allowedStatuses)) {
                $where .= " AND mr.status = ?";
                $params[] = $status;
                $types .= 's';
            }
        }

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM tbl_move_request mr WHERE $where";
        $countStmt = $this->conn->prepare($countSql);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch move requests
        $sql = "SELECT mr.id, mr.society_id, mr.flat_id, mr.resident_id,
                       mr.type, mr.scheduled_date, mr.scheduled_time,
                       mr.elevator_required, mr.moving_company, mr.vehicle_number,
                       mr.damage_deposit, mr.deposit_status, mr.notes,
                       mr.status, mr.approved_by, mr.created_at,
                       f.flat_number, u.name as resident_name
                FROM tbl_move_request mr
                LEFT JOIN tbl_flat f ON f.id = mr.flat_id
                JOIN tbl_resident r ON r.id = mr.resident_id
                LEFT JOIN tbl_user u ON u.id = r.user_id
                WHERE $where
                ORDER BY mr.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $requests = [];
        while ($row = $result->fetch_assoc()) {
            $requests[] = $this->formatMoveRequest($row);
        }
        $stmt->close();

        ApiResponse::paginated($requests, $total, $page, $perPage, 'Move requests retrieved successfully');
    }

    // ─── GET /api/v1/move/requests/{id} ─────────────────────────────────

    /**
     * Get single move request detail.
     */
    private function getMoveRequest($id) {
        $isPrimary = $this->user['is_primary'];
        $residentId = $this->auth->getResidentId();

        $stmt = $this->conn->prepare(
            "SELECT mr.id, mr.society_id, mr.flat_id, mr.resident_id,
                    mr.type, mr.scheduled_date, mr.scheduled_time,
                    mr.elevator_required, mr.moving_company, mr.vehicle_number,
                    mr.damage_deposit, mr.deposit_status, mr.notes,
                    mr.status, mr.approved_by, mr.created_at,
                    f.flat_number, u.name as resident_name,
                    au.name as approved_by_name
             FROM tbl_move_request mr
             LEFT JOIN tbl_flat f ON f.id = mr.flat_id
             JOIN tbl_resident r ON r.id = mr.resident_id
             LEFT JOIN tbl_user u ON u.id = r.user_id
             LEFT JOIN tbl_user au ON au.id = mr.approved_by
             WHERE mr.id = ? AND mr.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Move request not found');
        }

        $row = $result->fetch_assoc();
        $stmt->close();

        // Non-primary users can only see their own requests
        if (!$isPrimary && (int)$row['resident_id'] !== $residentId) {
            ApiResponse::forbidden('You can only view your own move requests');
        }

        $request = $this->formatMoveRequest($row);
        $request['approved_by_name'] = $row['approved_by_name'] ?? null;

        ApiResponse::success($request, 'Move request retrieved successfully');
    }

    // ─── POST /api/v1/move/requests ─────────────────────────────────────

    /**
     * Create a new move request.
     */
    private function createMoveRequest() {
        $residentId = $this->auth->getResidentId();
        $flatId = $this->auth->getFlatId();

        if (!$residentId) {
            ApiResponse::forbidden('You must be an approved resident to create a move request');
        }

        $type = sanitizeInput($this->input['type'] ?? '');
        $scheduledDate = sanitizeInput($this->input['scheduled_date'] ?? '');
        $scheduledTime = sanitizeInput($this->input['scheduled_time'] ?? '');
        $elevatorRequired = isset($this->input['elevator_required']) ? (int)(bool)$this->input['elevator_required'] : 0;
        $movingCompany = sanitizeInput($this->input['moving_company'] ?? '');
        $vehicleNumber = sanitizeInput($this->input['vehicle_number'] ?? '');
        $notes = sanitizeInput($this->input['notes'] ?? '');

        // Validation
        $allowedTypes = ['move_in', 'move_out'];
        if (empty($type) || !in_array($type, $allowedTypes)) {
            ApiResponse::error('Invalid type. Allowed: ' . implode(', ', $allowedTypes), 400);
        }

        if (empty($scheduledDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledDate)) {
            ApiResponse::error('Valid scheduled_date is required (YYYY-MM-DD)', 400);
        }

        // Validate scheduled_time if provided
        $scheduledTimeVal = null;
        if (!empty($scheduledTime)) {
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $scheduledTime)) {
                ApiResponse::error('Invalid scheduled_time format. Use HH:MM', 400);
            }
            if (strlen($scheduledTime) === 5) $scheduledTime .= ':00';
            $scheduledTimeVal = $scheduledTime;
        }

        $movingCompanyVal = !empty($movingCompany) ? $movingCompany : null;
        $vehicleNumberVal = !empty($vehicleNumber) ? $vehicleNumber : null;
        $notesVal = !empty($notes) ? $notes : null;

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_move_request
                (society_id, flat_id, resident_id, type, scheduled_date, scheduled_time,
                 elevator_required, moving_company, vehicle_number, notes, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())"
        );
        $stmt->bind_param(
            'iiisssisss',
            $this->societyId, $flatId, $residentId, $type, $scheduledDate, $scheduledTimeVal,
            $elevatorRequired, $movingCompanyVal, $vehicleNumberVal, $notesVal
        );

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to create move request', 500);
        }

        $requestId = $stmt->insert_id;
        $stmt->close();

        $this->fetchAndRespond($requestId, 'Move request created successfully', 201);
    }

    // ─── PUT /api/v1/move/requests/{id}/approve ─────────────────────────

    /**
     * Approve a move request. Admin (primary) only. Can set damage_deposit amount.
     */
    private function approveMoveRequest($id) {
        $this->auth->requirePrimary();

        $request = $this->findMoveRequest($id);

        if ($request['status'] !== 'pending') {
            ApiResponse::error('Only pending move requests can be approved', 400);
        }

        $damageDeposit = isset($this->input['damage_deposit']) ? (float)$this->input['damage_deposit'] : 0;
        $approvedBy = $this->auth->getUserId();

        $depositStatus = ($damageDeposit > 0) ? 'pending' : $request['deposit_status'];

        $stmt = $this->conn->prepare(
            "UPDATE tbl_move_request
             SET status = 'approved', approved_by = ?, damage_deposit = ?, deposit_status = ?
             WHERE id = ?"
        );
        $stmt->bind_param('idsi', $approvedBy, $damageDeposit, $depositStatus, $id);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to approve move request', 500);
        }
        $stmt->close();

        // Notify resident
        storeNotification(
            $this->conn,
            $request['resident_id'],
            'move_request',
            'Your move request has been approved',
            $id
        );

        $this->fetchAndRespond($id, 'Move request approved successfully');
    }

    // ─── PUT /api/v1/move/requests/{id}/complete ────────────────────────

    /**
     * Mark move request as completed. Admin (primary) only.
     */
    private function completeMoveRequest($id) {
        $this->auth->requirePrimary();

        $request = $this->findMoveRequest($id);

        if (!in_array($request['status'], ['approved', 'in_progress'])) {
            ApiResponse::error('Only approved or in-progress move requests can be marked as completed', 400);
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_move_request SET status = 'completed' WHERE id = ?"
        );
        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to complete move request', 500);
        }
        $stmt->close();

        storeNotification(
            $this->conn,
            $request['resident_id'],
            'move_request',
            'Your move request has been marked as completed',
            $id
        );

        $this->fetchAndRespond($id, 'Move request completed successfully');
    }

    // ─── PUT /api/v1/move/requests/{id}/cancel ──────────────────────────

    /**
     * Cancel a move request. Owner of the request or admin can cancel.
     */
    private function cancelMoveRequest($id) {
        $request = $this->findMoveRequest($id);

        $isPrimary = $this->user['is_primary'];
        $residentId = $this->auth->getResidentId();

        if ((int)$request['resident_id'] !== $residentId && !$isPrimary) {
            ApiResponse::forbidden('You can only cancel your own move requests');
        }

        if (in_array($request['status'], ['completed', 'cancelled'])) {
            ApiResponse::error('This move request cannot be cancelled', 400);
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_move_request SET status = 'cancelled' WHERE id = ?"
        );
        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to cancel move request', 500);
        }
        $stmt->close();

        $this->fetchAndRespond($id, 'Move request cancelled successfully');
    }

    // ─── PUT /api/v1/move/requests/{id}/deposit ─────────────────────────

    /**
     * Update deposit status. Admin (primary) only.
     */
    private function updateDeposit($id) {
        $this->auth->requirePrimary();

        $request = $this->findMoveRequest($id);

        $depositStatus = sanitizeInput($this->input['deposit_status'] ?? '');
        $allowedStatuses = ['pending', 'paid', 'refunded', 'deducted'];

        if (empty($depositStatus) || !in_array($depositStatus, $allowedStatuses)) {
            ApiResponse::error('Invalid deposit_status. Allowed: ' . implode(', ', $allowedStatuses), 400);
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_move_request SET deposit_status = ? WHERE id = ?"
        );
        $stmt->bind_param('si', $depositStatus, $id);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to update deposit status', 500);
        }
        $stmt->close();

        storeNotification(
            $this->conn,
            $request['resident_id'],
            'move_request',
            'Your move request deposit status has been updated to: ' . $depositStatus,
            $id
        );

        $this->fetchAndRespond($id, 'Deposit status updated successfully');
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * Find a move request by ID within the current society. Exits 404 if not found.
     */
    private function findMoveRequest($id) {
        $stmt = $this->conn->prepare(
            "SELECT id, resident_id, status, deposit_status
             FROM tbl_move_request
             WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Move request not found');
        }

        $request = $result->fetch_assoc();
        $stmt->close();

        return $request;
    }

    /**
     * Fetch a move request by ID and send API response.
     */
    private function fetchAndRespond($id, $message, $code = 200) {
        $stmt = $this->conn->prepare(
            "SELECT mr.id, mr.society_id, mr.flat_id, mr.resident_id,
                    mr.type, mr.scheduled_date, mr.scheduled_time,
                    mr.elevator_required, mr.moving_company, mr.vehicle_number,
                    mr.damage_deposit, mr.deposit_status, mr.notes,
                    mr.status, mr.approved_by, mr.created_at,
                    f.flat_number, u.name as resident_name,
                    au.name as approved_by_name
             FROM tbl_move_request mr
             LEFT JOIN tbl_flat f ON f.id = mr.flat_id
             JOIN tbl_resident r ON r.id = mr.resident_id
             LEFT JOIN tbl_user u ON u.id = r.user_id
             LEFT JOIN tbl_user au ON au.id = mr.approved_by
             WHERE mr.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $request = $this->formatMoveRequest($row);
        $request['approved_by_name'] = $row['approved_by_name'] ?? null;

        if ($code === 201) {
            ApiResponse::created($request, $message);
        } else {
            ApiResponse::success($request, $message);
        }
    }

    /**
     * Format a move request row for API output.
     */
    private function formatMoveRequest($row) {
        return [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'flat_id' => $row['flat_id'] ? (int)$row['flat_id'] : null,
            'flat_number' => $row['flat_number'] ?? null,
            'resident_id' => (int)$row['resident_id'],
            'resident_name' => $row['resident_name'] ?? null,
            'type' => $row['type'],
            'scheduled_date' => $row['scheduled_date'],
            'scheduled_time' => $row['scheduled_time'],
            'elevator_required' => (bool)$row['elevator_required'],
            'moving_company' => $row['moving_company'],
            'vehicle_number' => $row['vehicle_number'],
            'damage_deposit' => (float)$row['damage_deposit'],
            'deposit_status' => $row['deposit_status'],
            'notes' => $row['notes'],
            'status' => $row['status'],
            'approved_by' => $row['approved_by'] ? (int)$row['approved_by'] : null,
            'created_at' => $row['created_at'],
        ];
    }
}
