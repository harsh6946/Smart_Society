<?php
/**
 * Securis Smart Society Platform — Parking Management Handler
 * Manages parking slots, visitor bookings, and violation reporting.
 */

require_once __DIR__ . '/../../../../include/helpers.php';
require_once __DIR__ . '/../../../../include/security.php';

class ParkingHandler {
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

        // Sub-resource routing
        if ($action === 'slots') {
            $this->handleSlots($method, $id);
            return;
        }

        if ($action === 'bookings') {
            $this->handleBookings($method, $id);
            return;
        }

        if ($action === 'violations') {
            $this->handleViolations($method, $id);
            return;
        }

        ApiResponse::error('Invalid parking resource. Use: slots, bookings, violations', 400);
    }

    // ─── Slot routing ───────────────────────────────────────────────────

    private function handleSlots($method, $id) {
        switch ($method) {
            case 'GET':
                if ($id) {
                    $this->getSlot($id);
                } else {
                    $this->listSlots();
                }
                break;

            case 'POST':
                $this->createSlot();
                break;

            case 'PUT':
                if (!$id) {
                    ApiResponse::error('Slot ID is required', 400);
                }
                // Check for sub-actions: assign / unassign
                $uri = $_SERVER['REQUEST_URI'] ?? '';
                if (strpos($uri, '/assign') !== false && strpos($uri, '/unassign') === false) {
                    $this->assignSlot($id);
                } elseif (strpos($uri, '/unassign') !== false) {
                    $this->unassignSlot($id);
                } else {
                    $this->updateSlot($id);
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ─── Booking routing ────────────────────────────────────────────────

    private function handleBookings($method, $id) {
        switch ($method) {
            case 'GET':
                $this->listBookings();
                break;

            case 'POST':
                $this->createBooking();
                break;

            case 'PUT':
                if (!$id) {
                    ApiResponse::error('Booking ID is required', 400);
                }
                $uri = $_SERVER['REQUEST_URI'] ?? '';
                if (strpos($uri, '/cancel') !== false) {
                    $this->cancelBooking($id);
                } else {
                    ApiResponse::error('Invalid booking action', 400);
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ─── Violation routing ──────────────────────────────────────────────

    private function handleViolations($method, $id) {
        switch ($method) {
            case 'GET':
                $this->listViolations();
                break;

            case 'POST':
                $this->reportViolation();
                break;

            case 'PUT':
                if (!$id) {
                    ApiResponse::error('Violation ID is required', 400);
                }
                $this->updateViolation($id);
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  SLOTS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/parking/slots
     * List parking slots. Admin (primary) sees all; resident sees only own assigned.
     * Filters: slot_type, status, floor.
     */
    private function listSlots() {
        $isPrimary = $this->user['is_primary'];
        $flatId = $this->auth->getFlatId();

        $where = "ps.society_id = ?";
        $params = [$this->societyId];
        $types = 'i';

        // Non-primary users see only their assigned slot(s)
        if (!$isPrimary) {
            $where .= " AND ps.assigned_flat_id = ?";
            $params[] = $flatId;
            $types .= 'i';
        }

        // Filter by slot_type
        if (!empty($this->input['slot_type'])) {
            $slotType = sanitizeInput($this->input['slot_type']);
            $allowed = ['covered', 'open', 'basement', 'visitor'];
            if (in_array($slotType, $allowed)) {
                $where .= " AND ps.slot_type = ?";
                $params[] = $slotType;
                $types .= 's';
            }
        }

        // Filter by status
        if (!empty($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $allowed = ['available', 'assigned', 'reserved', 'maintenance'];
            if (in_array($status, $allowed)) {
                $where .= " AND ps.status = ?";
                $params[] = $status;
                $types .= 's';
            }
        }

        // Filter by floor
        if (!empty($this->input['floor'])) {
            $floor = sanitizeInput($this->input['floor']);
            $where .= " AND ps.floor = ?";
            $params[] = $floor;
            $types .= 's';
        }

        $sql = "SELECT ps.id, ps.society_id, ps.slot_number, ps.slot_type,
                       ps.floor, ps.location, ps.assigned_flat_id, ps.status,
                       ps.created_at,
                       f.flat_number as assigned_flat_number
                FROM tbl_parking_slot ps
                LEFT JOIN tbl_flat f ON f.id = ps.assigned_flat_id
                WHERE $where
                ORDER BY ps.slot_number ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $slots = [];
        while ($row = $result->fetch_assoc()) {
            $slots[] = $this->formatSlot($row);
        }
        $stmt->close();

        ApiResponse::success($slots, 'Parking slots retrieved successfully');
    }

    /**
     * GET /api/v1/parking/slots/{id}
     * Slot detail with current active booking (if any).
     */
    private function getSlot($id) {
        $stmt = $this->conn->prepare(
            "SELECT ps.id, ps.society_id, ps.slot_number, ps.slot_type,
                    ps.floor, ps.location, ps.assigned_flat_id, ps.status,
                    ps.created_at,
                    f.flat_number as assigned_flat_number
             FROM tbl_parking_slot ps
             LEFT JOIN tbl_flat f ON f.id = ps.assigned_flat_id
             WHERE ps.id = ? AND ps.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Parking slot not found');
        }

        $row = $result->fetch_assoc();
        $stmt->close();

        $slot = $this->formatSlot($row);

        // Fetch current active booking for this slot
        $bookingStmt = $this->conn->prepare(
            "SELECT pb.id, pb.booked_by, pb.vehicle_number, pb.booking_date,
                    pb.start_time, pb.end_time, pb.purpose, pb.status,
                    pb.created_at,
                    u.name as booked_by_name
             FROM tbl_parking_booking pb
             LEFT JOIN tbl_user u ON u.id = pb.booked_by
             WHERE pb.slot_id = ? AND pb.status IN ('booked', 'active')
             ORDER BY pb.booking_date ASC, pb.start_time ASC
             LIMIT 1"
        );
        $bookingStmt->bind_param('i', $id);
        $bookingStmt->execute();
        $bookingResult = $bookingStmt->get_result();

        $slot['current_booking'] = null;
        if ($bookingResult->num_rows > 0) {
            $slot['current_booking'] = $this->formatBooking($bookingResult->fetch_assoc());
        }
        $bookingStmt->close();

        ApiResponse::success($slot, 'Parking slot retrieved successfully');
    }

    /**
     * POST /api/v1/parking/slots
     * Create a new parking slot. Admin (primary) only.
     */
    private function createSlot() {
        $this->auth->requirePrimary();

        $slotNumber = sanitizeInput($this->input['slot_number'] ?? '');
        $slotType = sanitizeInput($this->input['slot_type'] ?? 'covered');
        $floor = sanitizeInput($this->input['floor'] ?? '');
        $location = sanitizeInput($this->input['location'] ?? '');

        if (empty($slotNumber)) {
            ApiResponse::error('Slot number is required', 400);
        }

        $allowedTypes = ['covered', 'open', 'basement', 'visitor'];
        if (!in_array($slotType, $allowedTypes)) {
            ApiResponse::error('Invalid slot type. Allowed: ' . implode(', ', $allowedTypes), 400);
        }

        // Check for duplicate slot number in society
        $checkStmt = $this->conn->prepare(
            "SELECT id FROM tbl_parking_slot
             WHERE society_id = ? AND slot_number = ?"
        );
        $checkStmt->bind_param('is', $this->societyId, $slotNumber);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            ApiResponse::error('A parking slot with this number already exists', 400);
        }
        $checkStmt->close();

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_parking_slot (society_id, slot_number, slot_type, floor, location, status)
             VALUES (?, ?, ?, ?, ?, 'available')"
        );
        $stmt->bind_param('issss', $this->societyId, $slotNumber, $slotType, $floor, $location);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to create parking slot', 500);
        }

        $slotId = $stmt->insert_id;
        $stmt->close();

        // Fetch created slot
        $fetchStmt = $this->conn->prepare(
            "SELECT ps.id, ps.society_id, ps.slot_number, ps.slot_type,
                    ps.floor, ps.location, ps.assigned_flat_id, ps.status,
                    ps.created_at,
                    f.flat_number as assigned_flat_number
             FROM tbl_parking_slot ps
             LEFT JOIN tbl_flat f ON f.id = ps.assigned_flat_id
             WHERE ps.id = ?"
        );
        $fetchStmt->bind_param('i', $slotId);
        $fetchStmt->execute();
        $slot = $this->formatSlot($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::created($slot, 'Parking slot created successfully');
    }

    /**
     * PUT /api/v1/parking/slots/{id}
     * Update slot details. Admin (primary) only.
     */
    private function updateSlot($id) {
        $this->auth->requirePrimary();

        // Verify slot exists in this society
        $checkStmt = $this->conn->prepare(
            "SELECT id FROM tbl_parking_slot WHERE id = ? AND society_id = ?"
        );
        $checkStmt->bind_param('ii', $id, $this->societyId);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Parking slot not found');
        }
        $checkStmt->close();

        // Build dynamic update
        $fields = [];
        $params = [];
        $types = '';

        if (isset($this->input['slot_number'])) {
            // Check for duplicate
            $slotNumber = sanitizeInput($this->input['slot_number']);
            $dupStmt = $this->conn->prepare(
                "SELECT id FROM tbl_parking_slot
                 WHERE society_id = ? AND slot_number = ? AND id != ?"
            );
            $dupStmt->bind_param('isi', $this->societyId, $slotNumber, $id);
            $dupStmt->execute();
            if ($dupStmt->get_result()->num_rows > 0) {
                ApiResponse::error('A parking slot with this number already exists', 400);
            }
            $dupStmt->close();

            $fields[] = 'slot_number = ?';
            $params[] = $slotNumber;
            $types .= 's';
        }

        if (isset($this->input['slot_type'])) {
            $slotType = sanitizeInput($this->input['slot_type']);
            $allowedTypes = ['covered', 'open', 'basement', 'visitor'];
            if (!in_array($slotType, $allowedTypes)) {
                ApiResponse::error('Invalid slot type. Allowed: ' . implode(', ', $allowedTypes), 400);
            }
            $fields[] = 'slot_type = ?';
            $params[] = $slotType;
            $types .= 's';
        }

        if (isset($this->input['floor'])) {
            $fields[] = 'floor = ?';
            $params[] = sanitizeInput($this->input['floor']);
            $types .= 's';
        }

        if (isset($this->input['location'])) {
            $fields[] = 'location = ?';
            $params[] = sanitizeInput($this->input['location']);
            $types .= 's';
        }

        if (isset($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $allowedStatuses = ['available', 'assigned', 'reserved', 'maintenance'];
            if (!in_array($status, $allowedStatuses)) {
                ApiResponse::error('Invalid status. Allowed: ' . implode(', ', $allowedStatuses), 400);
            }
            $fields[] = 'status = ?';
            $params[] = $status;
            $types .= 's';
        }

        if (empty($fields)) {
            ApiResponse::error('No fields to update', 400);
        }

        $sql = "UPDATE tbl_parking_slot SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $updateStmt = $this->conn->prepare($sql);
        $updateStmt->bind_param($types, ...$params);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to update parking slot', 500);
        }
        $updateStmt->close();

        // Return updated slot
        $fetchStmt = $this->conn->prepare(
            "SELECT ps.id, ps.society_id, ps.slot_number, ps.slot_type,
                    ps.floor, ps.location, ps.assigned_flat_id, ps.status,
                    ps.created_at,
                    f.flat_number as assigned_flat_number
             FROM tbl_parking_slot ps
             LEFT JOIN tbl_flat f ON f.id = ps.assigned_flat_id
             WHERE ps.id = ?"
        );
        $fetchStmt->bind_param('i', $id);
        $fetchStmt->execute();
        $slot = $this->formatSlot($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::success($slot, 'Parking slot updated successfully');
    }

    /**
     * PUT /api/v1/parking/slots/{id}/assign
     * Assign a slot to a flat. Admin (primary) only.
     */
    private function assignSlot($id) {
        $this->auth->requirePrimary();

        $flatId = isset($this->input['flat_id']) ? (int)$this->input['flat_id'] : 0;
        if (empty($flatId)) {
            ApiResponse::error('flat_id is required', 400);
        }

        // Verify slot exists in this society
        $slotStmt = $this->conn->prepare(
            "SELECT id, status FROM tbl_parking_slot WHERE id = ? AND society_id = ?"
        );
        $slotStmt->bind_param('ii', $id, $this->societyId);
        $slotStmt->execute();
        $slotResult = $slotStmt->get_result();

        if ($slotResult->num_rows === 0) {
            ApiResponse::notFound('Parking slot not found');
        }

        $slot = $slotResult->fetch_assoc();
        $slotStmt->close();

        if ($slot['status'] === 'assigned') {
            ApiResponse::error('This slot is already assigned. Unassign it first.', 400);
        }

        // Verify flat belongs to this society
        $flatStmt = $this->conn->prepare(
            "SELECT id FROM tbl_flat WHERE id = ? AND society_id = ?"
        );
        $flatStmt->bind_param('ii', $flatId, $this->societyId);
        $flatStmt->execute();
        if ($flatStmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Flat not found in this society');
        }
        $flatStmt->close();

        $updateStmt = $this->conn->prepare(
            "UPDATE tbl_parking_slot SET assigned_flat_id = ?, status = 'assigned' WHERE id = ?"
        );
        $updateStmt->bind_param('ii', $flatId, $id);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to assign parking slot', 500);
        }
        $updateStmt->close();

        // Return updated slot
        $fetchStmt = $this->conn->prepare(
            "SELECT ps.id, ps.society_id, ps.slot_number, ps.slot_type,
                    ps.floor, ps.location, ps.assigned_flat_id, ps.status,
                    ps.created_at,
                    f.flat_number as assigned_flat_number
             FROM tbl_parking_slot ps
             LEFT JOIN tbl_flat f ON f.id = ps.assigned_flat_id
             WHERE ps.id = ?"
        );
        $fetchStmt->bind_param('i', $id);
        $fetchStmt->execute();
        $slot = $this->formatSlot($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::success($slot, 'Parking slot assigned successfully');
    }

    /**
     * PUT /api/v1/parking/slots/{id}/unassign
     * Unassign a slot from its flat. Admin (primary) only.
     */
    private function unassignSlot($id) {
        $this->auth->requirePrimary();

        // Verify slot exists in this society
        $slotStmt = $this->conn->prepare(
            "SELECT id, status FROM tbl_parking_slot WHERE id = ? AND society_id = ?"
        );
        $slotStmt->bind_param('ii', $id, $this->societyId);
        $slotStmt->execute();
        $slotResult = $slotStmt->get_result();

        if ($slotResult->num_rows === 0) {
            ApiResponse::notFound('Parking slot not found');
        }

        $slot = $slotResult->fetch_assoc();
        $slotStmt->close();

        if ($slot['status'] !== 'assigned') {
            ApiResponse::error('This slot is not currently assigned', 400);
        }

        $updateStmt = $this->conn->prepare(
            "UPDATE tbl_parking_slot SET assigned_flat_id = NULL, status = 'available' WHERE id = ?"
        );
        $updateStmt->bind_param('i', $id);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to unassign parking slot', 500);
        }
        $updateStmt->close();

        // Return updated slot
        $fetchStmt = $this->conn->prepare(
            "SELECT ps.id, ps.society_id, ps.slot_number, ps.slot_type,
                    ps.floor, ps.location, ps.assigned_flat_id, ps.status,
                    ps.created_at,
                    f.flat_number as assigned_flat_number
             FROM tbl_parking_slot ps
             LEFT JOIN tbl_flat f ON f.id = ps.assigned_flat_id
             WHERE ps.id = ?"
        );
        $fetchStmt->bind_param('i', $id);
        $fetchStmt->execute();
        $slotData = $this->formatSlot($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::success($slotData, 'Parking slot unassigned successfully');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  BOOKINGS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/parking/bookings
     * List bookings. Paginated. Filters: date, status.
     * Admin sees all in society; resident sees own bookings.
     */
    private function listBookings() {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $isPrimary = $this->user['is_primary'];
        $userId = $this->auth->getUserId();

        $where = "ps.society_id = ?";
        $params = [$this->societyId];
        $types = 'i';

        // Non-primary users see only their own bookings
        if (!$isPrimary) {
            $where .= " AND pb.booked_by = ?";
            $params[] = $userId;
            $types .= 'i';
        }

        // Filter by date
        if (!empty($this->input['date'])) {
            $date = sanitizeInput($this->input['date']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $where .= " AND pb.booking_date = ?";
                $params[] = $date;
                $types .= 's';
            }
        }

        // Filter by status
        if (!empty($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $allowed = ['booked', 'active', 'completed', 'cancelled'];
            if (in_array($status, $allowed)) {
                $where .= " AND pb.status = ?";
                $params[] = $status;
                $types .= 's';
            }
        }

        // Count total
        $countSql = "SELECT COUNT(*) as total
                     FROM tbl_parking_booking pb
                     JOIN tbl_parking_slot ps ON ps.id = pb.slot_id
                     WHERE $where";
        $countStmt = $this->conn->prepare($countSql);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch bookings
        $sql = "SELECT pb.id, pb.slot_id, pb.booked_by, pb.vehicle_number,
                       pb.booking_date, pb.start_time, pb.end_time,
                       pb.purpose, pb.status, pb.created_at,
                       ps.slot_number,
                       u.name as booked_by_name
                FROM tbl_parking_booking pb
                JOIN tbl_parking_slot ps ON ps.id = pb.slot_id
                LEFT JOIN tbl_user u ON u.id = pb.booked_by
                WHERE $where
                ORDER BY pb.booking_date DESC, pb.start_time ASC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $bookings = [];
        while ($row = $result->fetch_assoc()) {
            $bookings[] = $this->formatBooking($row);
        }
        $stmt->close();

        ApiResponse::paginated($bookings, $total, $page, $perPage, 'Bookings retrieved successfully');
    }

    /**
     * POST /api/v1/parking/bookings
     * Book a visitor parking slot. Checks for time overlap.
     */
    private function createBooking() {
        $slotId = isset($this->input['slot_id']) ? (int)$this->input['slot_id'] : 0;
        $vehicleNumber = sanitizeInput($this->input['vehicle_number'] ?? '');
        $bookingDate = sanitizeInput($this->input['booking_date'] ?? '');
        $startTime = sanitizeInput($this->input['start_time'] ?? '');
        $endTime = sanitizeInput($this->input['end_time'] ?? '');
        $purpose = sanitizeInput($this->input['purpose'] ?? '');

        // Validation
        if (!$slotId) {
            ApiResponse::error('slot_id is required', 400);
        }
        if (empty($vehicleNumber)) {
            ApiResponse::error('vehicle_number is required', 400);
        }
        if (empty($bookingDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookingDate)) {
            ApiResponse::error('Valid booking_date is required (YYYY-MM-DD)', 400);
        }
        if (empty($startTime) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime)) {
            ApiResponse::error('Valid start_time is required (HH:MM)', 400);
        }
        if (empty($endTime) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
            ApiResponse::error('Valid end_time is required (HH:MM)', 400);
        }

        // Normalize to HH:MM:SS
        if (strlen($startTime) === 5) $startTime .= ':00';
        if (strlen($endTime) === 5) $endTime .= ':00';

        if ($startTime >= $endTime) {
            ApiResponse::error('end_time must be after start_time', 400);
        }

        // Verify slot exists in this society and is a visitor slot or available
        $slotStmt = $this->conn->prepare(
            "SELECT id, slot_type, status FROM tbl_parking_slot
             WHERE id = ? AND society_id = ?"
        );
        $slotStmt->bind_param('ii', $slotId, $this->societyId);
        $slotStmt->execute();
        $slotResult = $slotStmt->get_result();

        if ($slotResult->num_rows === 0) {
            ApiResponse::notFound('Parking slot not found');
        }

        $slot = $slotResult->fetch_assoc();
        $slotStmt->close();

        if ($slot['slot_type'] !== 'visitor') {
            ApiResponse::error('Only visitor parking slots can be booked', 400);
        }

        if ($slot['status'] === 'maintenance') {
            ApiResponse::error('This slot is currently under maintenance', 400);
        }

        // Check for overlapping bookings on the same slot and date
        $overlapStmt = $this->conn->prepare(
            "SELECT COUNT(*) as cnt FROM tbl_parking_booking
             WHERE slot_id = ? AND booking_date = ?
             AND status IN ('booked', 'active')
             AND (start_time < ? AND end_time > ?)"
        );
        $overlapStmt->bind_param('isss', $slotId, $bookingDate, $endTime, $startTime);
        $overlapStmt->execute();
        $overlapCount = $overlapStmt->get_result()->fetch_assoc()['cnt'];
        $overlapStmt->close();

        if ($overlapCount > 0) {
            ApiResponse::error('This time slot overlaps with an existing booking', 409);
        }

        $userId = $this->auth->getUserId();

        $insertStmt = $this->conn->prepare(
            "INSERT INTO tbl_parking_booking
                (slot_id, booked_by, vehicle_number, booking_date, start_time, end_time, purpose, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'booked')"
        );
        $insertStmt->bind_param('iisssss', $slotId, $userId, $vehicleNumber, $bookingDate, $startTime, $endTime, $purpose);

        if (!$insertStmt->execute()) {
            ApiResponse::error('Failed to create booking', 500);
        }

        $bookingId = $insertStmt->insert_id;
        $insertStmt->close();

        // Fetch created booking
        $fetchStmt = $this->conn->prepare(
            "SELECT pb.id, pb.slot_id, pb.booked_by, pb.vehicle_number,
                    pb.booking_date, pb.start_time, pb.end_time,
                    pb.purpose, pb.status, pb.created_at,
                    ps.slot_number,
                    u.name as booked_by_name
             FROM tbl_parking_booking pb
             JOIN tbl_parking_slot ps ON ps.id = pb.slot_id
             LEFT JOIN tbl_user u ON u.id = pb.booked_by
             WHERE pb.id = ?"
        );
        $fetchStmt->bind_param('i', $bookingId);
        $fetchStmt->execute();
        $booking = $this->formatBooking($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::created($booking, 'Parking slot booked successfully');
    }

    /**
     * PUT /api/v1/parking/bookings/{id}/cancel
     * Cancel a booking. Only the booking owner or admin.
     */
    private function cancelBooking($bookingId) {
        // Verify booking exists and belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT pb.id, pb.booked_by, pb.status
             FROM tbl_parking_booking pb
             JOIN tbl_parking_slot ps ON ps.id = pb.slot_id
             WHERE pb.id = ? AND ps.society_id = ?"
        );
        $stmt->bind_param('ii', $bookingId, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Booking not found');
        }

        $existing = $result->fetch_assoc();
        $stmt->close();

        // Authorization: only booking owner or primary
        $userId = $this->auth->getUserId();
        $isPrimary = $this->user['is_primary'];
        if ((int)$existing['booked_by'] !== $userId && !$isPrimary) {
            ApiResponse::forbidden('You can only cancel your own bookings');
        }

        if ($existing['status'] === 'cancelled') {
            ApiResponse::error('Booking is already cancelled', 400);
        }

        if ($existing['status'] === 'completed') {
            ApiResponse::error('Cannot cancel a completed booking', 400);
        }

        $updateStmt = $this->conn->prepare(
            "UPDATE tbl_parking_booking SET status = 'cancelled' WHERE id = ?"
        );
        $updateStmt->bind_param('i', $bookingId);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to cancel booking', 500);
        }
        $updateStmt->close();

        // Fetch updated booking
        $fetchStmt = $this->conn->prepare(
            "SELECT pb.id, pb.slot_id, pb.booked_by, pb.vehicle_number,
                    pb.booking_date, pb.start_time, pb.end_time,
                    pb.purpose, pb.status, pb.created_at,
                    ps.slot_number,
                    u.name as booked_by_name
             FROM tbl_parking_booking pb
             JOIN tbl_parking_slot ps ON ps.id = pb.slot_id
             LEFT JOIN tbl_user u ON u.id = pb.booked_by
             WHERE pb.id = ?"
        );
        $fetchStmt->bind_param('i', $bookingId);
        $fetchStmt->execute();
        $booking = $this->formatBooking($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::success($booking, 'Booking cancelled successfully');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  VIOLATIONS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/parking/violations
     * List violations. Paginated. Filter by status.
     * Admin sees all; resident sees own reported violations.
     */
    private function listViolations() {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $isPrimary = $this->user['is_primary'];
        $userId = $this->auth->getUserId();

        $where = "pv.society_id = ?";
        $params = [$this->societyId];
        $types = 'i';

        // Non-primary users see only their own reported violations
        if (!$isPrimary) {
            $where .= " AND pv.reported_by = ?";
            $params[] = $userId;
            $types .= 'i';
        }

        // Filter by status
        if (!empty($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $allowed = ['reported', 'warned', 'resolved'];
            if (in_array($status, $allowed)) {
                $where .= " AND pv.status = ?";
                $params[] = $status;
                $types .= 's';
            }
        }

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM tbl_parking_violation pv WHERE $where";
        $countStmt = $this->conn->prepare($countSql);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch violations
        $sql = "SELECT pv.id, pv.society_id, pv.slot_id, pv.vehicle_number,
                       pv.violation_type, pv.description, pv.photo,
                       pv.reported_by, pv.status, pv.created_at,
                       ps.slot_number,
                       u.name as reported_by_name
                FROM tbl_parking_violation pv
                LEFT JOIN tbl_parking_slot ps ON ps.id = pv.slot_id
                LEFT JOIN tbl_user u ON u.id = pv.reported_by
                WHERE $where
                ORDER BY pv.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $violations = [];
        while ($row = $result->fetch_assoc()) {
            $violations[] = $this->formatViolation($row);
        }
        $stmt->close();

        ApiResponse::paginated($violations, $total, $page, $perPage, 'Violations retrieved successfully');
    }

    /**
     * POST /api/v1/parking/violations
     * Report a parking violation. Any authenticated resident can report.
     * Supports photo upload.
     */
    private function reportViolation() {
        $slotId = isset($this->input['slot_id']) ? (int)$this->input['slot_id'] : null;
        $vehicleNumber = sanitizeInput($this->input['vehicle_number'] ?? '');
        $violationType = sanitizeInput($this->input['violation_type'] ?? '');
        $description = sanitizeInput($this->input['description'] ?? '');

        // Validation
        $allowedTypes = ['wrong_slot', 'double_parking', 'blocking', 'unauthorized', 'other'];
        if (empty($violationType) || !in_array($violationType, $allowedTypes)) {
            ApiResponse::error('Invalid violation_type. Allowed: ' . implode(', ', $allowedTypes), 400);
        }

        // Verify slot belongs to this society if provided
        if ($slotId) {
            $slotStmt = $this->conn->prepare(
                "SELECT id FROM tbl_parking_slot WHERE id = ? AND society_id = ?"
            );
            $slotStmt->bind_param('ii', $slotId, $this->societyId);
            $slotStmt->execute();
            if ($slotStmt->get_result()->num_rows === 0) {
                ApiResponse::notFound('Parking slot not found');
            }
            $slotStmt->close();
        }

        // Handle photo upload
        $photo = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['photo'], 'parking_violations', ['jpg', 'jpeg', 'png', 'webp']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error'], 400);
            }
            $photo = $upload['path'];
        }

        $userId = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_parking_violation
                (society_id, slot_id, vehicle_number, violation_type, description, photo, reported_by, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'reported')"
        );
        $stmt->bind_param('iissssi', $this->societyId, $slotId, $vehicleNumber, $violationType, $description, $photo, $userId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to report violation', 500);
        }

        $violationId = $stmt->insert_id;
        $stmt->close();

        // Fetch created violation
        $fetchStmt = $this->conn->prepare(
            "SELECT pv.id, pv.society_id, pv.slot_id, pv.vehicle_number,
                    pv.violation_type, pv.description, pv.photo,
                    pv.reported_by, pv.status, pv.created_at,
                    ps.slot_number,
                    u.name as reported_by_name
             FROM tbl_parking_violation pv
             LEFT JOIN tbl_parking_slot ps ON ps.id = pv.slot_id
             LEFT JOIN tbl_user u ON u.id = pv.reported_by
             WHERE pv.id = ?"
        );
        $fetchStmt->bind_param('i', $violationId);
        $fetchStmt->execute();
        $violation = $this->formatViolation($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::created($violation, 'Violation reported successfully');
    }

    /**
     * PUT /api/v1/parking/violations/{id}
     * Update violation status. Admin (primary) only. Status: warned, resolved.
     */
    private function updateViolation($id) {
        $this->auth->requirePrimary();

        $status = sanitizeInput($this->input['status'] ?? '');
        $allowedStatuses = ['warned', 'resolved'];

        if (!in_array($status, $allowedStatuses)) {
            ApiResponse::error('Status must be "warned" or "resolved"', 400);
        }

        // Verify violation exists in this society
        $checkStmt = $this->conn->prepare(
            "SELECT id, status FROM tbl_parking_violation WHERE id = ? AND society_id = ?"
        );
        $checkStmt->bind_param('ii', $id, $this->societyId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Violation not found');
        }

        $existing = $result->fetch_assoc();
        $checkStmt->close();

        if ($existing['status'] === 'resolved') {
            ApiResponse::error('This violation is already resolved', 400);
        }

        $updateStmt = $this->conn->prepare(
            "UPDATE tbl_parking_violation SET status = ? WHERE id = ?"
        );
        $updateStmt->bind_param('si', $status, $id);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to update violation', 500);
        }
        $updateStmt->close();

        // Return updated violation
        $fetchStmt = $this->conn->prepare(
            "SELECT pv.id, pv.society_id, pv.slot_id, pv.vehicle_number,
                    pv.violation_type, pv.description, pv.photo,
                    pv.reported_by, pv.status, pv.created_at,
                    ps.slot_number,
                    u.name as reported_by_name
             FROM tbl_parking_violation pv
             LEFT JOIN tbl_parking_slot ps ON ps.id = pv.slot_id
             LEFT JOIN tbl_user u ON u.id = pv.reported_by
             WHERE pv.id = ?"
        );
        $fetchStmt->bind_param('i', $id);
        $fetchStmt->execute();
        $violation = $this->formatViolation($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::success($violation, 'Violation updated successfully');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  FORMATTERS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Format a parking slot row for API output.
     */
    private function formatSlot($row) {
        return [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'slot_number' => $row['slot_number'],
            'slot_type' => $row['slot_type'],
            'floor' => $row['floor'],
            'location' => $row['location'],
            'assigned_flat_id' => $row['assigned_flat_id'] !== null ? (int)$row['assigned_flat_id'] : null,
            'assigned_flat_number' => $row['assigned_flat_number'] ?? null,
            'status' => $row['status'],
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * Format a parking booking row for API output.
     */
    private function formatBooking($row) {
        return [
            'id' => (int)$row['id'],
            'slot_id' => (int)$row['slot_id'],
            'slot_number' => $row['slot_number'] ?? null,
            'booked_by' => [
                'id' => (int)$row['booked_by'],
                'name' => $row['booked_by_name'] ?? null,
            ],
            'vehicle_number' => $row['vehicle_number'],
            'booking_date' => $row['booking_date'],
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'purpose' => $row['purpose'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * Format a parking violation row for API output.
     */
    private function formatViolation($row) {
        return [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'slot_id' => $row['slot_id'] !== null ? (int)$row['slot_id'] : null,
            'slot_number' => $row['slot_number'] ?? null,
            'vehicle_number' => $row['vehicle_number'],
            'violation_type' => $row['violation_type'],
            'description' => $row['description'],
            'photo' => $row['photo'],
            'reported_by' => [
                'id' => (int)$row['reported_by'],
                'name' => $row['reported_by_name'] ?? null,
            ],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
        ];
    }
}
