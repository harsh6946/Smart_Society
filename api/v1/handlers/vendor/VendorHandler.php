<?php
/**
 * Securis Smart Society Platform — Vendor Management Handler
 * Endpoints for vendor CRUD operations including listing, creation,
 * update, and deactivation.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class VendorHandler {
    private $conn;
    private $auth;
    private $input;

    public function __construct($conn, $auth, $input) {
        $this->conn = $conn;
        $this->auth = $auth;
        $this->input = $input;
    }

    /**
     * Route: /api/v1/vendors/{action_or_id}
     */
    public function handle($method, $action, $id) {
        switch ($method) {
            case 'GET':
                if ($id && !$action) {
                    $this->getVendorDetail($id);
                } elseif (!$action && !$id) {
                    $this->listVendors();
                } else {
                    ApiResponse::notFound('Vendor endpoint not found');
                }
                break;

            case 'POST':
                if (!$action && !$id) {
                    $this->createVendor();
                } else {
                    ApiResponse::error('Method not allowed', 405);
                }
                break;

            case 'PUT':
                if ($id && !$action) {
                    $this->updateVendor($id);
                } else {
                    ApiResponse::error('Method not allowed', 405);
                }
                break;

            case 'DELETE':
                if ($id && !$action) {
                    $this->deactivateVendor($id);
                } else {
                    ApiResponse::error('Method not allowed', 405);
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ---------------------------------------------------------------
    //  GET /vendors
    // ---------------------------------------------------------------
    private function listVendors() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $serviceType = sanitizeInput($this->input['service_type'] ?? '');
        $statusFilter = sanitizeInput($this->input['status'] ?? '');
        $search = sanitizeInput($this->input['search'] ?? '');

        $where = "WHERE v.society_id = ?";
        $params = [$societyId];
        $types = 'i';

        if (!empty($serviceType)) {
            $where .= " AND v.service_type = ?";
            $params[] = $serviceType;
            $types .= 's';
        }
        if (!empty($statusFilter)) {
            $where .= " AND v.status = ?";
            $params[] = $statusFilter;
            $types .= 's';
        }
        if (!empty($search)) {
            $where .= " AND (v.name LIKE ? OR v.company_name LIKE ? OR v.service_type LIKE ?)";
            $searchParam = '%' . $search . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= 'sss';
        }

        // Count
        $countStmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total FROM tbl_vendor v $where"
        );
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];

        // Fetch
        $fetchParams = array_merge($params, [$perPage, $offset]);
        $fetchTypes = $types . 'ii';

        $stmt = $this->conn->prepare(
            "SELECT v.id, v.society_id, v.name, v.company_name, v.phone,
                    v.email, v.service_type, v.address, v.gst_number,
                    v.rating, v.total_reviews, v.status, v.created_at
             FROM tbl_vendor v
             $where
             ORDER BY v.name ASC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param($fetchTypes, ...$fetchParams);
        $stmt->execute();
        $result = $stmt->get_result();

        $vendors = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['society_id'] = (int)$row['society_id'];
            $row['rating'] = (float)$row['rating'];
            $row['total_reviews'] = (int)$row['total_reviews'];
            $vendors[] = $row;
        }

        ApiResponse::paginated($vendors, $total, $page, $perPage, 'Vendors retrieved');
    }

    // ---------------------------------------------------------------
    //  GET /vendors/{id}
    // ---------------------------------------------------------------
    private function getVendorDetail($id) {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        $stmt = $this->conn->prepare(
            "SELECT v.id, v.society_id, v.name, v.company_name, v.phone,
                    v.email, v.service_type, v.address, v.gst_number,
                    v.rating, v.total_reviews, v.status, v.created_at
             FROM tbl_vendor v
             WHERE v.id = ? AND v.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Vendor not found');
        }

        $vendor = $result->fetch_assoc();
        $vendor['id'] = (int)$vendor['id'];
        $vendor['society_id'] = (int)$vendor['society_id'];
        $vendor['rating'] = (float)$vendor['rating'];
        $vendor['total_reviews'] = (int)$vendor['total_reviews'];

        ApiResponse::success($vendor, 'Vendor details retrieved');
    }

    // ---------------------------------------------------------------
    //  POST /vendors
    // ---------------------------------------------------------------
    private function createVendor() {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $name = sanitizeInput($this->input['name'] ?? '');
        $companyName = sanitizeInput($this->input['company_name'] ?? '');
        $phone = sanitizeInput($this->input['phone'] ?? '');
        $email = sanitizeInput($this->input['email'] ?? '');
        $serviceType = sanitizeInput($this->input['service_type'] ?? '');
        $address = sanitizeInput($this->input['address'] ?? '');
        $gstNumber = sanitizeInput($this->input['gst_number'] ?? '');

        if (empty($name)) {
            ApiResponse::error('Vendor name is required');
        }
        if (empty($serviceType)) {
            ApiResponse::error('Service type is required');
        }

        // Validate email if provided
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            ApiResponse::error('Invalid email address');
        }

        // Check for duplicate vendor by name and service type
        $dupStmt = $this->conn->prepare(
            "SELECT id FROM tbl_vendor
             WHERE society_id = ? AND name = ? AND service_type = ? AND status = 'active'"
        );
        $dupStmt->bind_param('iss', $societyId, $name, $serviceType);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) {
            ApiResponse::error('A vendor with this name and service type already exists');
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_vendor
             (society_id, name, company_name, phone, email, service_type,
              address, gst_number, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')"
        );
        $stmt->bind_param(
            'isssssss',
            $societyId, $name, $companyName, $phone, $email,
            $serviceType, $address, $gstNumber
        );

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to create vendor', 500);
        }

        $vendorId = $stmt->insert_id;

        ApiResponse::created([
            'id' => $vendorId,
            'society_id' => $societyId,
            'name' => $name,
            'company_name' => $companyName,
            'phone' => $phone,
            'email' => $email,
            'service_type' => $serviceType,
            'address' => $address,
            'gst_number' => $gstNumber,
            'rating' => 0.0,
            'total_reviews' => 0,
            'status' => 'active'
        ], 'Vendor created successfully');
    }

    // ---------------------------------------------------------------
    //  PUT /vendors/{id}
    // ---------------------------------------------------------------
    private function updateVendor($id) {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        // Verify vendor exists in this society
        $checkStmt = $this->conn->prepare(
            "SELECT id FROM tbl_vendor WHERE id = ? AND society_id = ?"
        );
        $checkStmt->bind_param('ii', $id, $societyId);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Vendor not found');
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
        if (isset($this->input['company_name'])) {
            $fields[] = 'company_name = ?';
            $params[] = sanitizeInput($this->input['company_name']);
            $types .= 's';
        }
        if (isset($this->input['phone'])) {
            $fields[] = 'phone = ?';
            $params[] = sanitizeInput($this->input['phone']);
            $types .= 's';
        }
        if (isset($this->input['email'])) {
            $email = sanitizeInput($this->input['email']);
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                ApiResponse::error('Invalid email address');
            }
            $fields[] = 'email = ?';
            $params[] = $email;
            $types .= 's';
        }
        if (isset($this->input['service_type'])) {
            $fields[] = 'service_type = ?';
            $params[] = sanitizeInput($this->input['service_type']);
            $types .= 's';
        }
        if (isset($this->input['address'])) {
            $fields[] = 'address = ?';
            $params[] = sanitizeInput($this->input['address']);
            $types .= 's';
        }
        if (isset($this->input['gst_number'])) {
            $fields[] = 'gst_number = ?';
            $params[] = sanitizeInput($this->input['gst_number']);
            $types .= 's';
        }

        if (empty($fields)) {
            ApiResponse::error('No fields to update', 400);
        }

        $sql = "UPDATE tbl_vendor SET " . implode(', ', $fields) . " WHERE id = ? AND society_id = ?";
        $params[] = $id;
        $params[] = $societyId;
        $types .= 'ii';

        $updateStmt = $this->conn->prepare($sql);
        $updateStmt->bind_param($types, ...$params);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to update vendor', 500);
        }

        // Return updated record
        $fetchStmt = $this->conn->prepare(
            "SELECT id, society_id, name, company_name, phone, email, service_type,
                    address, gst_number, rating, total_reviews, status, created_at
             FROM tbl_vendor WHERE id = ?"
        );
        $fetchStmt->bind_param('i', $id);
        $fetchStmt->execute();
        $vendor = $fetchStmt->get_result()->fetch_assoc();
        $vendor['id'] = (int)$vendor['id'];
        $vendor['society_id'] = (int)$vendor['society_id'];
        $vendor['rating'] = (float)$vendor['rating'];
        $vendor['total_reviews'] = (int)$vendor['total_reviews'];

        ApiResponse::success($vendor, 'Vendor updated successfully');
    }

    // ---------------------------------------------------------------
    //  DELETE /vendors/{id}
    // ---------------------------------------------------------------
    private function deactivateVendor($id) {
        $user = $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        // Verify vendor exists in this society
        $checkStmt = $this->conn->prepare(
            "SELECT id, status FROM tbl_vendor WHERE id = ? AND society_id = ?"
        );
        $checkStmt->bind_param('ii', $id, $societyId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Vendor not found');
        }

        $vendor = $result->fetch_assoc();
        if ($vendor['status'] === 'inactive') {
            ApiResponse::error('Vendor is already inactive');
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_vendor SET status = 'inactive'
             WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to deactivate vendor', 500);
        }

        ApiResponse::success([
            'id' => (int)$id,
            'status' => 'inactive'
        ], 'Vendor deactivated successfully');
    }
}
