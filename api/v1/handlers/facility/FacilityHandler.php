<?php
/**
 * Securis Smart Society Platform — Facility Booking Handler
 * Manages facilities, time slot availability, and booking lifecycle.
 */

require_once __DIR__ . '/../../../../include/helpers.php';
require_once __DIR__ . '/../../../../include/security.php';

class FacilityHandler {
    private $conn;
    private $auth;
    private $input;
    private $user;
    private $societyId;

    /** Default time slots when no booking_rules_json is defined */
    private const DEFAULT_SLOTS = [
        ['start' => '06:00', 'end' => '09:00'],
        ['start' => '09:00', 'end' => '12:00'],
        ['start' => '12:00', 'end' => '15:00'],
        ['start' => '15:00', 'end' => '18:00'],
        ['start' => '18:00', 'end' => '21:00'],
        ['start' => '21:00', 'end' => '00:00'],
    ];

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
                if ($action === 'bookings') {
                    // GET /facilities/bookings
                    $this->listBookings();
                } elseif ($id && $action === 'slots') {
                    // GET /facilities/{id}/slots?date=YYYY-MM-DD
                    $this->getSlots($id);
                } elseif ($id) {
                    // GET /facilities/{id}
                    $this->getFacility($id);
                } else {
                    // GET /facilities
                    $this->listFacilities();
                }
                break;

            case 'POST':
                if ($action === 'book') {
                    // POST /facilities/book
                    $this->bookFacility();
                } else {
                    // POST /facilities (admin create)
                    $this->createFacility();
                }
                break;

            case 'PUT':
                if ($action === 'bookings' && $id) {
                    // Check for sub-action (cancel)
                    $subAction = $this->input['_sub_action'] ?? '';
                    // The router puts segment3 into subAction, but we receive it via action/id.
                    // For /facilities/bookings/{id}/cancel the router gives action=bookings, id={id}
                    // We need to check the URI for /cancel suffix
                    $uri = $_SERVER['REQUEST_URI'] ?? '';
                    if (strpos($uri, '/cancel') !== false) {
                        // PUT /facilities/bookings/{id}/cancel
                        $this->cancelBooking($id);
                    } else {
                        // PUT /facilities/bookings/{id} (approve/reject)
                        $this->updateBookingStatus($id);
                    }
                } elseif ($id) {
                    // PUT /facilities/{id} (admin update)
                    $this->updateFacility($id);
                } else {
                    ApiResponse::error('Resource ID is required', 400);
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ─── Facility CRUD ───────────────────────────────────────────────

    /**
     * GET /api/v1/facilities
     * List all active facilities in the user's society.
     */
    private function listFacilities() {
        $stmt = $this->conn->prepare(
            "SELECT id, society_id, name, description, capacity, image, is_active
             FROM tbl_facility
             WHERE society_id = ? AND is_active = 1
             ORDER BY name ASC"
        );
        $stmt->bind_param('i', $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        $facilities = [];
        while ($row = $result->fetch_assoc()) {
            $facilities[] = $this->formatFacility($row);
        }
        $stmt->close();

        ApiResponse::success($facilities, 'Facilities retrieved successfully');
    }

    /**
     * GET /api/v1/facilities/{id}
     * Get facility detail including booking_rules_json.
     */
    private function getFacility($id) {
        $stmt = $this->conn->prepare(
            "SELECT id, society_id, name, description, capacity, image, booking_rules_json, is_active
             FROM tbl_facility
             WHERE id = ? AND society_id = ? AND is_active = 1"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Facility not found');
        }

        $row = $result->fetch_assoc();
        $stmt->close();

        $facility = $this->formatFacility($row, true);

        ApiResponse::success($facility, 'Facility retrieved successfully');
    }

    /**
     * POST /api/v1/facilities
     * Create a new facility. Only primary owners.
     */
    private function createFacility() {
        $this->auth->requirePrimary();

        $name = sanitizeInput($this->input['name'] ?? '');
        $description = sanitizeInput($this->input['description'] ?? '');
        $capacity = isset($this->input['capacity']) ? (int)$this->input['capacity'] : 0;
        $bookingRulesJson = $this->input['booking_rules_json'] ?? null;

        if (empty($name)) {
            ApiResponse::error('Facility name is required', 400);
        }

        // Validate booking_rules_json if provided
        if ($bookingRulesJson !== null) {
            if (is_string($bookingRulesJson)) {
                $decoded = json_decode($bookingRulesJson, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    ApiResponse::error('Invalid booking_rules_json format', 400);
                }
                // Store as-is (valid JSON string)
            } elseif (is_array($bookingRulesJson)) {
                $bookingRulesJson = json_encode($bookingRulesJson);
            }
        }

        // Handle image upload
        $image = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['image'], 'facilities', ['jpg', 'jpeg', 'png', 'webp']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error'], 400);
            }
            $image = $upload['path'];
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_facility (society_id, name, description, capacity, image, booking_rules_json, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)"
        );
        $stmt->bind_param('ississ', $this->societyId, $name, $description, $capacity, $image, $bookingRulesJson);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to create facility', 500);
        }

        $facilityId = $stmt->insert_id;
        $stmt->close();

        // Fetch created facility
        $fetchStmt = $this->conn->prepare(
            "SELECT id, society_id, name, description, capacity, image, booking_rules_json, is_active
             FROM tbl_facility WHERE id = ?"
        );
        $fetchStmt->bind_param('i', $facilityId);
        $fetchStmt->execute();
        $facility = $this->formatFacility($fetchStmt->get_result()->fetch_assoc(), true);
        $fetchStmt->close();

        ApiResponse::created($facility, 'Facility created successfully');
    }

    /**
     * PUT /api/v1/facilities/{id}
     * Update facility details. Only primary owners.
     */
    private function updateFacility($id) {
        $this->auth->requirePrimary();

        // Verify facility exists in this society
        $stmt = $this->conn->prepare(
            "SELECT id FROM tbl_facility WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Facility not found');
        }
        $stmt->close();

        // Build dynamic update
        $fields = [];
        $params = [];
        $types = '';

        if (isset($this->input['name'])) {
            $fields[] = 'name = ?';
            $params[] = sanitizeInput($this->input['name']);
            $types .= 's';
        }

        if (isset($this->input['description'])) {
            $fields[] = 'description = ?';
            $params[] = sanitizeInput($this->input['description']);
            $types .= 's';
        }

        if (isset($this->input['capacity'])) {
            $fields[] = 'capacity = ?';
            $params[] = (int)$this->input['capacity'];
            $types .= 'i';
        }

        if (isset($this->input['is_active'])) {
            $fields[] = 'is_active = ?';
            $params[] = (int)(bool)$this->input['is_active'];
            $types .= 'i';
        }

        if (isset($this->input['booking_rules_json'])) {
            $bookingRulesJson = $this->input['booking_rules_json'];
            if (is_string($bookingRulesJson)) {
                $decoded = json_decode($bookingRulesJson, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    ApiResponse::error('Invalid booking_rules_json format', 400);
                }
            } elseif (is_array($bookingRulesJson)) {
                $bookingRulesJson = json_encode($bookingRulesJson);
            }
            $fields[] = 'booking_rules_json = ?';
            $params[] = $bookingRulesJson;
            $types .= 's';
        }

        // Handle image upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['image'], 'facilities', ['jpg', 'jpeg', 'png', 'webp']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error'], 400);
            }
            $fields[] = 'image = ?';
            $params[] = $upload['path'];
            $types .= 's';
        }

        if (empty($fields)) {
            ApiResponse::error('No fields to update', 400);
        }

        $sql = "UPDATE tbl_facility SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $updateStmt = $this->conn->prepare($sql);
        $updateStmt->bind_param($types, ...$params);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to update facility', 500);
        }
        $updateStmt->close();

        // Return updated facility
        $fetchStmt = $this->conn->prepare(
            "SELECT id, society_id, name, description, capacity, image, booking_rules_json, is_active
             FROM tbl_facility WHERE id = ?"
        );
        $fetchStmt->bind_param('i', $id);
        $fetchStmt->execute();
        $facility = $this->formatFacility($fetchStmt->get_result()->fetch_assoc(), true);
        $fetchStmt->close();

        ApiResponse::success($facility, 'Facility updated successfully');
    }

    // ─── Slots & Booking ─────────────────────────────────────────────

    /**
     * GET /api/v1/facilities/{id}/slots?date=YYYY-MM-DD
     * Return available time slots for a facility on a given date.
     */
    private function getSlots($facilityId) {
        $date = sanitizeInput($this->input['date'] ?? '');

        if (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            ApiResponse::error('Valid date parameter is required (YYYY-MM-DD)', 400);
        }

        // Verify facility exists
        $stmt = $this->conn->prepare(
            "SELECT id, name, booking_rules_json
             FROM tbl_facility
             WHERE id = ? AND society_id = ? AND is_active = 1"
        );
        $stmt->bind_param('ii', $facilityId, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Facility not found');
        }

        $facility = $result->fetch_assoc();
        $stmt->close();

        // Determine slots from booking_rules_json or defaults
        $slots = self::DEFAULT_SLOTS;
        if (!empty($facility['booking_rules_json'])) {
            $rules = json_decode($facility['booking_rules_json'], true);
            if (is_array($rules) && isset($rules['slots']) && is_array($rules['slots'])) {
                $slots = $rules['slots'];
            }
        }

        // Fetch existing bookings for this facility on this date (pending or approved)
        $bookingStmt = $this->conn->prepare(
            "SELECT start_time, end_time, status
             FROM tbl_facility_booking
             WHERE facility_id = ? AND booking_date = ? AND status IN ('pending', 'approved')
             ORDER BY start_time ASC"
        );
        $bookingStmt->bind_param('is', $facilityId, $date);
        $bookingStmt->execute();
        $bookingResult = $bookingStmt->get_result();

        $bookedRanges = [];
        while ($row = $bookingResult->fetch_assoc()) {
            $bookedRanges[] = [
                'start_time' => substr($row['start_time'], 0, 5),
                'end_time' => substr($row['end_time'], 0, 5),
                'status' => $row['status'],
            ];
        }
        $bookingStmt->close();

        // Build slot list with availability
        $slotList = [];
        foreach ($slots as $slot) {
            $slotStart = $slot['start'];
            $slotEnd = $slot['end'];
            $isBooked = false;
            $bookingStatus = null;

            foreach ($bookedRanges as $booked) {
                // Overlap check: new_end > existing_start AND new_start < existing_end
                if ($slotEnd > $booked['start_time'] && $slotStart < $booked['end_time']) {
                    $isBooked = true;
                    $bookingStatus = $booked['status'];
                    break;
                }
            }

            $slotList[] = [
                'start_time' => $slotStart,
                'end_time' => $slotEnd,
                'is_available' => !$isBooked,
                'booking_status' => $bookingStatus,
            ];
        }

        ApiResponse::success([
            'facility_id' => (int)$facilityId,
            'facility_name' => $facility['name'],
            'date' => $date,
            'booked_ranges' => $bookedRanges,
            'slots' => $slotList,
        ], 'Slots retrieved successfully');
    }

    /**
     * POST /api/v1/facilities/book
     * Book a facility. Creates a pending booking after checking for overlaps.
     */
    private function bookFacility() {
        $facilityId = isset($this->input['facility_id']) ? (int)$this->input['facility_id'] : 0;
        $bookingDate = sanitizeInput($this->input['booking_date'] ?? '');
        $startTime = sanitizeInput($this->input['start_time'] ?? '');
        $endTime = sanitizeInput($this->input['end_time'] ?? '');
        $purpose = sanitizeInput($this->input['purpose'] ?? '');

        // Validation
        if (!$facilityId) {
            ApiResponse::error('facility_id is required', 400);
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

        // Verify facility exists in this society
        $stmt = $this->conn->prepare(
            "SELECT id FROM tbl_facility WHERE id = ? AND society_id = ? AND is_active = 1"
        );
        $stmt->bind_param('ii', $facilityId, $this->societyId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Facility not found');
        }
        $stmt->close();

        // Check for overlapping bookings
        $overlapStmt = $this->conn->prepare(
            "SELECT COUNT(*) as cnt FROM tbl_facility_booking
             WHERE facility_id = ? AND booking_date = ?
             AND status IN ('pending', 'approved')
             AND (start_time < ? AND end_time > ?)"
        );
        $overlapStmt->bind_param('isss', $facilityId, $bookingDate, $endTime, $startTime);
        $overlapStmt->execute();
        $overlapCount = $overlapStmt->get_result()->fetch_assoc()['cnt'];
        $overlapStmt->close();

        if ($overlapCount > 0) {
            ApiResponse::error('This time slot overlaps with an existing booking', 409);
        }

        // Create booking
        $residentId = $this->auth->getResidentId();
        if (!$residentId) {
            ApiResponse::forbidden('You must be an approved resident to book a facility');
        }

        $insertStmt = $this->conn->prepare(
            "INSERT INTO tbl_facility_booking (facility_id, resident_id, booking_date, start_time, end_time, purpose, status)
             VALUES (?, ?, ?, ?, ?, ?, 'pending')"
        );
        $insertStmt->bind_param('iissss', $facilityId, $residentId, $bookingDate, $startTime, $endTime, $purpose);

        if (!$insertStmt->execute()) {
            ApiResponse::error('Failed to create booking', 500);
        }

        $bookingId = $insertStmt->insert_id;
        $insertStmt->close();

        // Fetch created booking
        $fetchStmt = $this->conn->prepare(
            "SELECT fb.id, fb.facility_id, fb.resident_id, fb.booking_date, fb.start_time, fb.end_time,
                    fb.purpose, fb.status, fb.approved_by,
                    f.name as facility_name
             FROM tbl_facility_booking fb
             JOIN tbl_facility f ON f.id = fb.facility_id
             WHERE fb.id = ?"
        );
        $fetchStmt->bind_param('i', $bookingId);
        $fetchStmt->execute();
        $booking = $this->formatBooking($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::created($booking, 'Facility booked successfully. Pending approval.');
    }

    /**
     * GET /api/v1/facilities/bookings
     * List bookings. Residents see their own; primary owners see all for the society.
     * Supports status filter and pagination.
     */
    private function listBookings() {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $isPrimary = $this->user['is_primary'];
        $residentId = $this->auth->getResidentId();

        $where = "f.society_id = ?";
        $params = [$this->societyId];
        $types = 'i';

        // Non-primary users see only their own bookings
        if (!$isPrimary) {
            $where .= " AND fb.resident_id = ?";
            $params[] = $residentId;
            $types .= 'i';
        }

        // Filter by status
        if (!empty($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled'];
            if (in_array($status, $allowedStatuses)) {
                $where .= " AND fb.status = ?";
                $params[] = $status;
                $types .= 's';
            }
        }

        // Filter by facility_id
        if (!empty($this->input['facility_id'])) {
            $where .= " AND fb.facility_id = ?";
            $params[] = (int)$this->input['facility_id'];
            $types .= 'i';
        }

        // Count total
        $countSql = "SELECT COUNT(*) as total
                     FROM tbl_facility_booking fb
                     JOIN tbl_facility f ON f.id = fb.facility_id
                     WHERE $where";
        $countStmt = $this->conn->prepare($countSql);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch bookings
        $sql = "SELECT fb.id, fb.facility_id, fb.resident_id, fb.booking_date, fb.start_time, fb.end_time,
                       fb.purpose, fb.status, fb.approved_by,
                       f.name as facility_name
                FROM tbl_facility_booking fb
                JOIN tbl_facility f ON f.id = fb.facility_id
                WHERE $where
                ORDER BY fb.booking_date DESC, fb.start_time ASC
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
     * PUT /api/v1/facilities/bookings/{id}
     * Approve or reject a booking. Only primary owners.
     */
    private function updateBookingStatus($bookingId) {
        $this->auth->requirePrimary();

        $status = sanitizeInput($this->input['status'] ?? '');

        if (!in_array($status, ['approved', 'rejected'])) {
            ApiResponse::error('Status must be "approved" or "rejected"', 400);
        }

        // Verify booking exists and belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT fb.id, fb.status
             FROM tbl_facility_booking fb
             JOIN tbl_facility f ON f.id = fb.facility_id
             WHERE fb.id = ? AND f.society_id = ?"
        );
        $stmt->bind_param('ii', $bookingId, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Booking not found');
        }

        $existing = $result->fetch_assoc();
        $stmt->close();

        if ($existing['status'] !== 'pending') {
            ApiResponse::error('Only pending bookings can be approved or rejected', 400);
        }

        $approvedBy = $this->auth->getResidentId();

        $updateStmt = $this->conn->prepare(
            "UPDATE tbl_facility_booking SET status = ?, approved_by = ? WHERE id = ?"
        );
        $updateStmt->bind_param('sii', $status, $approvedBy, $bookingId);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to update booking', 500);
        }
        $updateStmt->close();

        // Fetch updated booking
        $fetchStmt = $this->conn->prepare(
            "SELECT fb.id, fb.facility_id, fb.resident_id, fb.booking_date, fb.start_time, fb.end_time,
                    fb.purpose, fb.status, fb.approved_by,
                    f.name as facility_name
             FROM tbl_facility_booking fb
             JOIN tbl_facility f ON f.id = fb.facility_id
             WHERE fb.id = ?"
        );
        $fetchStmt->bind_param('i', $bookingId);
        $fetchStmt->execute();
        $booking = $this->formatBooking($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::success($booking, 'Booking ' . $status . ' successfully');
    }

    /**
     * PUT /api/v1/facilities/bookings/{id}/cancel
     * Cancel a booking. Only the booking owner or a primary owner.
     */
    private function cancelBooking($bookingId) {
        // Verify booking exists and belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT fb.id, fb.resident_id, fb.status
             FROM tbl_facility_booking fb
             JOIN tbl_facility f ON f.id = fb.facility_id
             WHERE fb.id = ? AND f.society_id = ?"
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
        $residentId = $this->auth->getResidentId();
        $isPrimary = $this->user['is_primary'];
        if ((int)$existing['resident_id'] !== $residentId && !$isPrimary) {
            ApiResponse::forbidden('You can only cancel your own bookings');
        }

        if ($existing['status'] === 'cancelled') {
            ApiResponse::error('Booking is already cancelled', 400);
        }

        if ($existing['status'] === 'rejected') {
            ApiResponse::error('Cannot cancel a rejected booking', 400);
        }

        $updateStmt = $this->conn->prepare(
            "UPDATE tbl_facility_booking SET status = 'cancelled' WHERE id = ?"
        );
        $updateStmt->bind_param('i', $bookingId);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to cancel booking', 500);
        }
        $updateStmt->close();

        // Fetch updated booking
        $fetchStmt = $this->conn->prepare(
            "SELECT fb.id, fb.facility_id, fb.resident_id, fb.booking_date, fb.start_time, fb.end_time,
                    fb.purpose, fb.status, fb.approved_by,
                    f.name as facility_name
             FROM tbl_facility_booking fb
             JOIN tbl_facility f ON f.id = fb.facility_id
             WHERE fb.id = ?"
        );
        $fetchStmt->bind_param('i', $bookingId);
        $fetchStmt->execute();
        $booking = $this->formatBooking($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::success($booking, 'Booking cancelled successfully');
    }

    // ─── Formatters ──────────────────────────────────────────────────

    /**
     * Format a facility row for API output.
     */
    private function formatFacility($row, $includeRules = false) {
        $facility = [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'capacity' => (int)$row['capacity'],
            'image' => $row['image'],
            'is_active' => (bool)$row['is_active'],
        ];

        if ($includeRules && isset($row['booking_rules_json'])) {
            $facility['booking_rules_json'] = $row['booking_rules_json']
                ? json_decode($row['booking_rules_json'], true)
                : null;
        }

        return $facility;
    }

    /**
     * Format a booking row for API output.
     */
    private function formatBooking($row) {
        return [
            'id' => (int)$row['id'],
            'facility_id' => (int)$row['facility_id'],
            'facility_name' => $row['facility_name'],
            'resident_id' => (int)$row['resident_id'],
            'booking_date' => $row['booking_date'],
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'purpose' => $row['purpose'],
            'status' => $row['status'],
            'approved_by' => $row['approved_by'] ? (int)$row['approved_by'] : null,
        ];
    }
}
