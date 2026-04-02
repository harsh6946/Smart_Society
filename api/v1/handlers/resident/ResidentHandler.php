<?php
/**
 * Securis Smart Society Platform — Resident Handler
 * Endpoints for managing family members and vehicles.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class ResidentHandler {
    private $conn;
    private $auth;
    private $input;

    public function __construct($conn, $auth, $input) {
        $this->conn = $conn;
        $this->auth = $auth;
        $this->input = $input;
    }

    /**
     * Route: /api/v1/resident/{action}/{id}
     *
     * GET    /family          — List family members
     * POST   /family          — Add family member
     * PUT    /family/{id}     — Update family member
     * DELETE /family/{id}     — Delete family member
     * GET    /vehicles        — List vehicles
     * POST   /vehicles        — Add vehicle
     * PUT    /vehicles/{id}   — Update vehicle
     * DELETE /vehicles/{id}   — Delete vehicle
     */
    public function handle($method, $action, $id) {
        switch ($action) {
            case 'family':
                $this->handleFamily($method, $id);
                break;

            case 'vehicles':
                $this->handleVehicles($method, $id);
                break;

            default:
                ApiResponse::notFound('Resident endpoint not found');
        }
    }

    // ─────────────────────────────────────────────
    // Family Members
    // ─────────────────────────────────────────────

    private function handleFamily($method, $id) {
        switch ($method) {
            case 'GET':
                $this->listFamily();
                break;
            case 'POST':
                $this->addFamily();
                break;
            case 'PUT':
                if (!$id) ApiResponse::error('Family member ID is required');
                $this->updateFamily($id);
                break;
            case 'DELETE':
                if (!$id) ApiResponse::error('Family member ID is required');
                $this->deleteFamily($id);
                break;
            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    /**
     * GET /resident/family
     * List all family members for the current resident.
     */
    private function listFamily() {
        $this->auth->authenticate();
        $residentId = $this->auth->getResidentId();
        $this->auth->requireSociety();

        if (!$residentId) {
            ApiResponse::forbidden('You must be an approved resident');
        }

        $stmt = $this->conn->prepare(
            "SELECT id, resident_id, name, relation, phone, age
             FROM tbl_family_member
             WHERE resident_id = ?
             ORDER BY name ASC"
        );
        $stmt->bind_param('i', $residentId);
        $stmt->execute();
        $result = $stmt->get_result();

        $members = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['resident_id'] = (int)$row['resident_id'];
            $row['age'] = $row['age'] !== null ? (int)$row['age'] : null;
            $members[] = $row;
        }

        ApiResponse::success($members, 'Family members retrieved');
    }

    /**
     * POST /resident/family
     * Add a new family member.
     */
    private function addFamily() {
        $this->auth->authenticate();
        $residentId = $this->auth->getResidentId();
        $this->auth->requireSociety();

        if (!$residentId) {
            ApiResponse::forbidden('You must be an approved resident');
        }

        $name = sanitizeInput($this->input['name'] ?? '');
        $relation = sanitizeInput($this->input['relation'] ?? '');
        $phone = sanitizeInput($this->input['phone'] ?? '');
        $age = isset($this->input['age']) ? (int)$this->input['age'] : null;

        // Validation
        if (empty($name)) {
            ApiResponse::error('Name is required');
        }
        if (empty($relation)) {
            ApiResponse::error('Relation is required');
        }
        if (!empty($phone) && !validatePhone($phone)) {
            ApiResponse::error('Invalid phone number format');
        }
        if ($age !== null && ($age < 0 || $age > 150)) {
            ApiResponse::error('Invalid age');
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_family_member (resident_id, name, relation, phone, age)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isssi', $residentId, $name, $relation, $phone, $age);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to add family member', 500);
        }

        $memberId = $stmt->insert_id;

        ApiResponse::created([
            'id' => $memberId,
            'resident_id' => $residentId,
            'name' => $name,
            'relation' => $relation,
            'phone' => $phone,
            'age' => $age
        ], 'Family member added successfully');
    }

    /**
     * PUT /resident/family/{id}
     * Update an existing family member. Verifies ownership.
     */
    private function updateFamily($memberId) {
        $this->auth->authenticate();
        $residentId = $this->auth->getResidentId();
        $this->auth->requireSociety();

        if (!$residentId) {
            ApiResponse::forbidden('You must be an approved resident');
        }

        // Verify ownership
        $member = $this->verifyFamilyOwnership($memberId, $residentId);

        $name = sanitizeInput($this->input['name'] ?? $member['name']);
        $relation = sanitizeInput($this->input['relation'] ?? $member['relation']);
        $phone = sanitizeInput($this->input['phone'] ?? $member['phone']);
        $age = isset($this->input['age']) ? (int)$this->input['age'] : $member['age'];

        if (empty($name)) {
            ApiResponse::error('Name is required');
        }
        if (empty($relation)) {
            ApiResponse::error('Relation is required');
        }
        if (!empty($phone) && !validatePhone($phone)) {
            ApiResponse::error('Invalid phone number format');
        }
        if ($age !== null && ($age < 0 || $age > 150)) {
            ApiResponse::error('Invalid age');
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_family_member SET name = ?, relation = ?, phone = ?, age = ?
             WHERE id = ? AND resident_id = ?"
        );
        $stmt->bind_param('sssiii', $name, $relation, $phone, $age, $memberId, $residentId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to update family member', 500);
        }

        ApiResponse::success([
            'id' => (int)$memberId,
            'resident_id' => $residentId,
            'name' => $name,
            'relation' => $relation,
            'phone' => $phone,
            'age' => $age
        ], 'Family member updated successfully');
    }

    /**
     * DELETE /resident/family/{id}
     * Delete a family member. Verifies ownership.
     */
    private function deleteFamily($memberId) {
        $this->auth->authenticate();
        $residentId = $this->auth->getResidentId();
        $this->auth->requireSociety();

        if (!$residentId) {
            ApiResponse::forbidden('You must be an approved resident');
        }

        // Verify ownership
        $this->verifyFamilyOwnership($memberId, $residentId);

        $stmt = $this->conn->prepare(
            "DELETE FROM tbl_family_member WHERE id = ? AND resident_id = ?"
        );
        $stmt->bind_param('ii', $memberId, $residentId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to delete family member', 500);
        }

        if ($stmt->affected_rows === 0) {
            ApiResponse::notFound('Family member not found');
        }

        ApiResponse::success(null, 'Family member deleted successfully');
    }

    /**
     * Verify that a family member belongs to the current resident.
     */
    private function verifyFamilyOwnership($memberId, $residentId) {
        $stmt = $this->conn->prepare(
            "SELECT id, name, relation, phone, age
             FROM tbl_family_member
             WHERE id = ? AND resident_id = ?"
        );
        $stmt->bind_param('ii', $memberId, $residentId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Family member not found or access denied');
        }

        return $result->fetch_assoc();
    }

    // ─────────────────────────────────────────────
    // Vehicles
    // ─────────────────────────────────────────────

    private function handleVehicles($method, $id) {
        switch ($method) {
            case 'GET':
                $this->listVehicles();
                break;
            case 'POST':
                $this->addVehicle();
                break;
            case 'PUT':
                if (!$id) ApiResponse::error('Vehicle ID is required');
                $this->updateVehicle($id);
                break;
            case 'DELETE':
                if (!$id) ApiResponse::error('Vehicle ID is required');
                $this->deleteVehicle($id);
                break;
            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    /**
     * GET /resident/vehicles
     * List all vehicles for the current resident.
     */
    private function listVehicles() {
        $this->auth->authenticate();
        $residentId = $this->auth->getResidentId();
        $this->auth->requireSociety();

        if (!$residentId) {
            ApiResponse::forbidden('You must be an approved resident');
        }

        $stmt = $this->conn->prepare(
            "SELECT id, resident_id, vehicle_type, vehicle_number, model, color
             FROM tbl_vehicle
             WHERE resident_id = ?
             ORDER BY vehicle_number ASC"
        );
        $stmt->bind_param('i', $residentId);
        $stmt->execute();
        $result = $stmt->get_result();

        $vehicles = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['resident_id'] = (int)$row['resident_id'];
            $vehicles[] = $row;
        }

        ApiResponse::success($vehicles, 'Vehicles retrieved');
    }

    /**
     * POST /resident/vehicles
     * Add a new vehicle.
     */
    private function addVehicle() {
        $this->auth->authenticate();
        $residentId = $this->auth->getResidentId();
        $this->auth->requireSociety();

        if (!$residentId) {
            ApiResponse::forbidden('You must be an approved resident');
        }

        $vehicleType = sanitizeInput($this->input['vehicle_type'] ?? '');
        $vehicleNumber = sanitizeInput($this->input['vehicle_number'] ?? '');
        $model = sanitizeInput($this->input['model'] ?? '');
        $color = sanitizeInput($this->input['color'] ?? '');

        // Validation
        $allowedTypes = ['car', 'bike', 'scooter', 'bicycle', 'other'];
        if (empty($vehicleType) || !in_array($vehicleType, $allowedTypes)) {
            ApiResponse::error('Valid vehicle type is required (' . implode(', ', $allowedTypes) . ')');
        }
        if (empty($vehicleNumber)) {
            ApiResponse::error('Vehicle number is required');
        }

        // Check for duplicate vehicle number across the system
        $dupStmt = $this->conn->prepare(
            "SELECT id FROM tbl_vehicle WHERE vehicle_number = ?"
        );
        $dupStmt->bind_param('s', $vehicleNumber);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) {
            ApiResponse::error('Vehicle number already registered');
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_vehicle (resident_id, vehicle_type, vehicle_number, model, color)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('issss', $residentId, $vehicleType, $vehicleNumber, $model, $color);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to add vehicle', 500);
        }

        $vehicleId = $stmt->insert_id;

        ApiResponse::created([
            'id' => $vehicleId,
            'resident_id' => $residentId,
            'vehicle_type' => $vehicleType,
            'vehicle_number' => $vehicleNumber,
            'model' => $model,
            'color' => $color
        ], 'Vehicle added successfully');
    }

    /**
     * PUT /resident/vehicles/{id}
     * Update an existing vehicle. Verifies ownership.
     */
    private function updateVehicle($vehicleId) {
        $this->auth->authenticate();
        $residentId = $this->auth->getResidentId();
        $this->auth->requireSociety();

        if (!$residentId) {
            ApiResponse::forbidden('You must be an approved resident');
        }

        // Verify ownership
        $vehicle = $this->verifyVehicleOwnership($vehicleId, $residentId);

        $vehicleType = sanitizeInput($this->input['vehicle_type'] ?? $vehicle['vehicle_type']);
        $vehicleNumber = sanitizeInput($this->input['vehicle_number'] ?? $vehicle['vehicle_number']);
        $model = sanitizeInput($this->input['model'] ?? $vehicle['model']);
        $color = sanitizeInput($this->input['color'] ?? $vehicle['color']);

        $allowedTypes = ['car', 'bike', 'scooter', 'bicycle', 'other'];
        if (!in_array($vehicleType, $allowedTypes)) {
            ApiResponse::error('Valid vehicle type is required (' . implode(', ', $allowedTypes) . ')');
        }
        if (empty($vehicleNumber)) {
            ApiResponse::error('Vehicle number is required');
        }

        // Check for duplicate vehicle number (exclude current vehicle)
        $dupStmt = $this->conn->prepare(
            "SELECT id FROM tbl_vehicle WHERE vehicle_number = ? AND id != ?"
        );
        $dupStmt->bind_param('si', $vehicleNumber, $vehicleId);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) {
            ApiResponse::error('Vehicle number already registered to another vehicle');
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_vehicle SET vehicle_type = ?, vehicle_number = ?, model = ?, color = ?
             WHERE id = ? AND resident_id = ?"
        );
        $stmt->bind_param('ssssii', $vehicleType, $vehicleNumber, $model, $color, $vehicleId, $residentId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to update vehicle', 500);
        }

        ApiResponse::success([
            'id' => (int)$vehicleId,
            'resident_id' => $residentId,
            'vehicle_type' => $vehicleType,
            'vehicle_number' => $vehicleNumber,
            'model' => $model,
            'color' => $color
        ], 'Vehicle updated successfully');
    }

    /**
     * DELETE /resident/vehicles/{id}
     * Delete a vehicle. Verifies ownership.
     */
    private function deleteVehicle($vehicleId) {
        $this->auth->authenticate();
        $residentId = $this->auth->getResidentId();
        $this->auth->requireSociety();

        if (!$residentId) {
            ApiResponse::forbidden('You must be an approved resident');
        }

        // Verify ownership
        $this->verifyVehicleOwnership($vehicleId, $residentId);

        $stmt = $this->conn->prepare(
            "DELETE FROM tbl_vehicle WHERE id = ? AND resident_id = ?"
        );
        $stmt->bind_param('ii', $vehicleId, $residentId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to delete vehicle', 500);
        }

        if ($stmt->affected_rows === 0) {
            ApiResponse::notFound('Vehicle not found');
        }

        ApiResponse::success(null, 'Vehicle deleted successfully');
    }

    /**
     * Verify that a vehicle belongs to the current resident.
     */
    private function verifyVehicleOwnership($vehicleId, $residentId) {
        $stmt = $this->conn->prepare(
            "SELECT id, vehicle_type, vehicle_number, model, color
             FROM tbl_vehicle
             WHERE id = ? AND resident_id = ?"
        );
        $stmt->bind_param('ii', $vehicleId, $residentId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Vehicle not found or access denied');
        }

        return $result->fetch_assoc();
    }
}
