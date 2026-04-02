<?php
/**
 * Securis Smart Society Platform -- Asset, Maintenance Log & AMC Handler
 * Manages society assets, maintenance logs, and annual maintenance contracts.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class AssetHandler {
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
            case 'GET':
                switch ($action) {
                    case 'amc':
                        $this->listAmc();
                        break;
                    case 'logs':
                        // GET /assets/{id}/logs — id comes from the route
                        if ($id) {
                            $this->listMaintenanceLogs($id);
                        } else {
                            ApiResponse::error('Asset ID is required');
                        }
                        break;
                    default:
                        // GET /assets or GET /assets/{id}
                        if ($id) {
                            $this->getAssetDetail($id);
                        } else {
                            $this->listAssets();
                        }
                }
                break;

            case 'POST':
                switch ($action) {
                    case 'amc':
                        $this->createAmc();
                        break;
                    case 'logs':
                        // POST /assets/{id}/logs
                        if ($id) {
                            $this->addMaintenanceLog($id);
                        } else {
                            ApiResponse::error('Asset ID is required');
                        }
                        break;
                    default:
                        // POST /assets
                        $this->createAsset();
                }
                break;

            case 'PUT':
                switch ($action) {
                    case 'amc':
                        if ($id) {
                            $this->updateAmc($id);
                        } else {
                            ApiResponse::error('AMC ID is required');
                        }
                        break;
                    default:
                        // PUT /assets/{id}
                        if ($id) {
                            $this->updateAsset($id);
                        } else {
                            ApiResponse::error('Asset ID is required');
                        }
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // =========================================================================
    // Assets
    // =========================================================================

    /**
     * GET /assets
     * List assets. Admin only. Paginated. Filter by category, status.
     */
    private function listAssets() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $category = sanitizeInput($this->input['category'] ?? '');
        $status = sanitizeInput($this->input['status'] ?? '');

        $where = "WHERE a.society_id = ?";
        $params = [$societyId];
        $types = 'i';

        if (!empty($category)) {
            $allowedCategories = ['lift', 'generator', 'pump', 'transformer', 'cctv', 'fire_equipment', 'other'];
            if (in_array($category, $allowedCategories)) {
                $where .= " AND a.category = ?";
                $params[] = $category;
                $types .= 's';
            }
        }

        if (!empty($status)) {
            $allowedStatuses = ['working', 'under_repair', 'not_working', 'decommissioned'];
            if (in_array($status, $allowedStatuses)) {
                $where .= " AND a.status = ?";
                $params[] = $status;
                $types .= 's';
            }
        }

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM tbl_asset a $where";
        $stmt = $this->conn->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        // Fetch paginated assets
        $sql = "SELECT a.id, a.society_id, a.name, a.category, a.location,
                       a.serial_number, a.purchase_date, a.purchase_cost,
                       a.warranty_end, a.vendor_id, a.status,
                       a.last_service_date, a.next_service_date, a.notes, a.created_at,
                       v.name as vendor_name
                FROM tbl_asset a
                LEFT JOIN tbl_vendor v ON v.id = a.vendor_id
                $where
                ORDER BY a.name ASC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $assets = [];
        while ($row = $result->fetch_assoc()) {
            $assets[] = $this->formatAsset($row);
        }

        ApiResponse::paginated($assets, $total, $page, $perPage, 'Assets retrieved successfully');
    }

    /**
     * GET /assets/{id}
     * Asset detail with recent maintenance logs and active AMC info.
     */
    private function getAssetDetail($id) {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $stmt = $this->conn->prepare(
            "SELECT a.id, a.society_id, a.name, a.category, a.location,
                    a.serial_number, a.purchase_date, a.purchase_cost,
                    a.warranty_end, a.vendor_id, a.status,
                    a.last_service_date, a.next_service_date, a.notes, a.created_at,
                    v.name as vendor_name
             FROM tbl_asset a
             LEFT JOIN tbl_vendor v ON v.id = a.vendor_id
             WHERE a.id = ? AND a.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Asset not found');
        }

        $asset = $this->formatAsset($result->fetch_assoc());

        // Fetch recent maintenance logs (last 10)
        $stmt = $this->conn->prepare(
            "SELECT ml.id, ml.asset_id, ml.maintenance_type, ml.description,
                    ml.cost, ml.vendor_id, ml.technician_name, ml.service_date,
                    ml.next_service_date, ml.images_json, ml.recorded_by, ml.created_at,
                    v.name as vendor_name, u.name as recorded_by_name
             FROM tbl_asset_maintenance_log ml
             LEFT JOIN tbl_vendor v ON v.id = ml.vendor_id
             LEFT JOIN tbl_user u ON u.id = ml.recorded_by
             WHERE ml.asset_id = ?
             ORDER BY ml.service_date DESC
             LIMIT 10"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $logResult = $stmt->get_result();

        $logs = [];
        while ($row = $logResult->fetch_assoc()) {
            $logs[] = $this->formatMaintenanceLog($row);
        }

        // Fetch active AMC contracts for this asset
        $stmt = $this->conn->prepare(
            "SELECT amc.id, amc.title, amc.contract_number, amc.start_date, amc.end_date,
                    amc.amount, amc.frequency, amc.status,
                    v.name as vendor_name
             FROM tbl_amc amc
             LEFT JOIN tbl_vendor v ON v.id = amc.vendor_id
             WHERE amc.asset_id = ? AND amc.status = 'active'
             ORDER BY amc.end_date DESC"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $amcResult = $stmt->get_result();

        $amcContracts = [];
        while ($row = $amcResult->fetch_assoc()) {
            $amcContracts[] = [
                'id' => (int)$row['id'],
                'title' => $row['title'],
                'contract_number' => $row['contract_number'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'amount' => (float)$row['amount'],
                'frequency' => $row['frequency'],
                'status' => $row['status'],
                'vendor_name' => $row['vendor_name'],
            ];
        }

        $asset['maintenance_logs'] = $logs;
        $asset['amc_contracts'] = $amcContracts;

        ApiResponse::success($asset, 'Asset detail retrieved successfully');
    }

    /**
     * POST /assets
     * Create a new asset. Admin only.
     */
    private function createAsset() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $name = sanitizeInput($this->input['name'] ?? '');
        $category = sanitizeInput($this->input['category'] ?? '');
        $location = sanitizeInput($this->input['location'] ?? '');
        $serialNumber = sanitizeInput($this->input['serial_number'] ?? '');
        $purchaseDate = sanitizeInput($this->input['purchase_date'] ?? '');
        $purchaseCost = isset($this->input['purchase_cost']) ? floatval($this->input['purchase_cost']) : null;
        $warrantyEnd = sanitizeInput($this->input['warranty_end'] ?? '');
        $vendorId = isset($this->input['vendor_id']) ? intval($this->input['vendor_id']) : null;
        $status = sanitizeInput($this->input['status'] ?? 'working');
        $notes = sanitizeInput($this->input['notes'] ?? '');

        if (empty($name)) {
            ApiResponse::error('Asset name is required');
        }

        $allowedCategories = ['lift', 'generator', 'pump', 'transformer', 'cctv', 'fire_equipment', 'other'];
        if (empty($category) || !in_array($category, $allowedCategories)) {
            ApiResponse::error('Valid category is required. Allowed: ' . implode(', ', $allowedCategories));
        }

        $allowedStatuses = ['working', 'under_repair', 'not_working', 'decommissioned'];
        if (!in_array($status, $allowedStatuses)) {
            ApiResponse::error('Invalid status. Allowed: ' . implode(', ', $allowedStatuses));
        }

        // Validate vendor if provided
        if ($vendorId) {
            $stmt = $this->conn->prepare("SELECT id FROM tbl_vendor WHERE id = ?");
            $stmt->bind_param('i', $vendorId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                ApiResponse::notFound('Vendor not found');
            }
        }

        $purchaseDateVal = !empty($purchaseDate) ? $purchaseDate : null;
        $warrantyEndVal = !empty($warrantyEnd) ? $warrantyEnd : null;
        $locationVal = !empty($location) ? $location : null;
        $serialNumberVal = !empty($serialNumber) ? $serialNumber : null;
        $notesVal = !empty($notes) ? $notes : null;

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_asset (society_id, name, category, location, serial_number,
                                    purchase_date, purchase_cost, warranty_end, vendor_id, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'isssssdsis',
            $societyId, $name, $category, $locationVal, $serialNumberVal,
            $purchaseDateVal, $purchaseCost, $warrantyEndVal, $vendorId, $status, $notesVal
        );
        $stmt->execute();
        $assetId = $this->conn->insert_id;

        // Fetch created asset
        $stmt = $this->conn->prepare(
            "SELECT a.id, a.society_id, a.name, a.category, a.location,
                    a.serial_number, a.purchase_date, a.purchase_cost,
                    a.warranty_end, a.vendor_id, a.status,
                    a.last_service_date, a.next_service_date, a.notes, a.created_at,
                    v.name as vendor_name
             FROM tbl_asset a
             LEFT JOIN tbl_vendor v ON v.id = a.vendor_id
             WHERE a.id = ?"
        );
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $asset = $this->formatAsset($stmt->get_result()->fetch_assoc());

        ApiResponse::created($asset, 'Asset created successfully');
    }

    /**
     * PUT /assets/{id}
     * Update an asset. Admin only.
     */
    private function updateAsset($id) {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        // Verify asset exists and belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT id FROM tbl_asset WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Asset not found');
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

        if (isset($this->input['category'])) {
            $category = sanitizeInput($this->input['category']);
            $allowedCategories = ['lift', 'generator', 'pump', 'transformer', 'cctv', 'fire_equipment', 'other'];
            if (!in_array($category, $allowedCategories)) {
                ApiResponse::error('Invalid category. Allowed: ' . implode(', ', $allowedCategories));
            }
            $fields[] = 'category = ?';
            $params[] = $category;
            $types .= 's';
        }

        if (isset($this->input['location'])) {
            $fields[] = 'location = ?';
            $params[] = sanitizeInput($this->input['location']);
            $types .= 's';
        }

        if (isset($this->input['serial_number'])) {
            $fields[] = 'serial_number = ?';
            $params[] = sanitizeInput($this->input['serial_number']);
            $types .= 's';
        }

        if (isset($this->input['purchase_date'])) {
            $fields[] = 'purchase_date = ?';
            $params[] = sanitizeInput($this->input['purchase_date']);
            $types .= 's';
        }

        if (isset($this->input['purchase_cost'])) {
            $fields[] = 'purchase_cost = ?';
            $params[] = floatval($this->input['purchase_cost']);
            $types .= 'd';
        }

        if (isset($this->input['warranty_end'])) {
            $fields[] = 'warranty_end = ?';
            $params[] = sanitizeInput($this->input['warranty_end']);
            $types .= 's';
        }

        if (isset($this->input['vendor_id'])) {
            $vendorId = intval($this->input['vendor_id']);
            if ($vendorId) {
                $vStmt = $this->conn->prepare("SELECT id FROM tbl_vendor WHERE id = ?");
                $vStmt->bind_param('i', $vendorId);
                $vStmt->execute();
                if ($vStmt->get_result()->num_rows === 0) {
                    ApiResponse::notFound('Vendor not found');
                }
            }
            $fields[] = 'vendor_id = ?';
            $params[] = $vendorId ?: null;
            $types .= 'i';
        }

        if (isset($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $allowedStatuses = ['working', 'under_repair', 'not_working', 'decommissioned'];
            if (!in_array($status, $allowedStatuses)) {
                ApiResponse::error('Invalid status. Allowed: ' . implode(', ', $allowedStatuses));
            }
            $fields[] = 'status = ?';
            $params[] = $status;
            $types .= 's';
        }

        if (isset($this->input['last_service_date'])) {
            $fields[] = 'last_service_date = ?';
            $params[] = sanitizeInput($this->input['last_service_date']);
            $types .= 's';
        }

        if (isset($this->input['next_service_date'])) {
            $fields[] = 'next_service_date = ?';
            $params[] = sanitizeInput($this->input['next_service_date']);
            $types .= 's';
        }

        if (isset($this->input['notes'])) {
            $fields[] = 'notes = ?';
            $params[] = sanitizeInput($this->input['notes']);
            $types .= 's';
        }

        if (empty($fields)) {
            ApiResponse::error('No fields to update');
        }

        $sql = "UPDATE tbl_asset SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        // Fetch updated asset
        $stmt = $this->conn->prepare(
            "SELECT a.id, a.society_id, a.name, a.category, a.location,
                    a.serial_number, a.purchase_date, a.purchase_cost,
                    a.warranty_end, a.vendor_id, a.status,
                    a.last_service_date, a.next_service_date, a.notes, a.created_at,
                    v.name as vendor_name
             FROM tbl_asset a
             LEFT JOIN tbl_vendor v ON v.id = a.vendor_id
             WHERE a.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $asset = $this->formatAsset($stmt->get_result()->fetch_assoc());

        ApiResponse::success($asset, 'Asset updated successfully');
    }

    // =========================================================================
    // Maintenance Logs
    // =========================================================================

    /**
     * GET /assets/{id}/logs
     * List maintenance logs for a specific asset. Paginated. Admin only.
     */
    private function listMaintenanceLogs($assetId) {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        // Verify asset exists and belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT id FROM tbl_asset WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $assetId, $societyId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Asset not found');
        }

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        // Count total
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) as total FROM tbl_asset_maintenance_log WHERE asset_id = ?"
        );
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        // Fetch paginated logs
        $stmt = $this->conn->prepare(
            "SELECT ml.id, ml.asset_id, ml.maintenance_type, ml.description,
                    ml.cost, ml.vendor_id, ml.technician_name, ml.service_date,
                    ml.next_service_date, ml.images_json, ml.recorded_by, ml.created_at,
                    v.name as vendor_name, u.name as recorded_by_name
             FROM tbl_asset_maintenance_log ml
             LEFT JOIN tbl_vendor v ON v.id = ml.vendor_id
             LEFT JOIN tbl_user u ON u.id = ml.recorded_by
             WHERE ml.asset_id = ?
             ORDER BY ml.service_date DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('iii', $assetId, $perPage, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $this->formatMaintenanceLog($row);
        }

        ApiResponse::paginated($logs, $total, $page, $perPage, 'Maintenance logs retrieved successfully');
    }

    /**
     * POST /assets/{id}/logs
     * Add a maintenance log entry for an asset. Admin only.
     * Input: maintenance_type, description, cost, vendor_id, technician_name,
     *        service_date, next_service_date, images_json.
     */
    private function addMaintenanceLog($assetId) {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        // Verify asset exists and belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT id FROM tbl_asset WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $assetId, $societyId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Asset not found');
        }

        $maintenanceType = sanitizeInput($this->input['maintenance_type'] ?? 'preventive');
        $description = sanitizeInput($this->input['description'] ?? '');
        $cost = isset($this->input['cost']) ? floatval($this->input['cost']) : null;
        $vendorId = isset($this->input['vendor_id']) ? intval($this->input['vendor_id']) : null;
        $technicianName = sanitizeInput($this->input['technician_name'] ?? '');
        $serviceDate = sanitizeInput($this->input['service_date'] ?? '');
        $nextServiceDate = sanitizeInput($this->input['next_service_date'] ?? '');
        $imagesJson = $this->input['images_json'] ?? null;

        if (empty($serviceDate)) {
            ApiResponse::error('Service date is required');
        }

        $allowedTypes = ['preventive', 'corrective', 'emergency'];
        if (!in_array($maintenanceType, $allowedTypes)) {
            ApiResponse::error('Invalid maintenance type. Allowed: ' . implode(', ', $allowedTypes));
        }

        // Validate vendor if provided
        if ($vendorId) {
            $stmt = $this->conn->prepare("SELECT id FROM tbl_vendor WHERE id = ?");
            $stmt->bind_param('i', $vendorId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                ApiResponse::notFound('Vendor not found');
            }
        }

        // Handle images_json
        if ($imagesJson !== null && is_array($imagesJson)) {
            $imagesJson = json_encode($imagesJson);
        }

        $technicianNameVal = !empty($technicianName) ? $technicianName : null;
        $nextServiceDateVal = !empty($nextServiceDate) ? $nextServiceDate : null;
        $descriptionVal = !empty($description) ? $description : null;
        $recordedBy = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_asset_maintenance_log
             (asset_id, maintenance_type, description, cost, vendor_id, technician_name,
              service_date, next_service_date, images_json, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'issdissssi',
            $assetId, $maintenanceType, $descriptionVal, $cost, $vendorId,
            $technicianNameVal, $serviceDate, $nextServiceDateVal, $imagesJson, $recordedBy
        );
        $stmt->execute();
        $logId = $this->conn->insert_id;

        // Update asset's last_service_date and next_service_date
        $updateFields = "last_service_date = ?";
        $updateParams = [$serviceDate];
        $updateTypes = 's';

        if (!empty($nextServiceDate)) {
            $updateFields .= ", next_service_date = ?";
            $updateParams[] = $nextServiceDate;
            $updateTypes .= 's';
        }

        $updateParams[] = $assetId;
        $updateTypes .= 'i';

        $stmt = $this->conn->prepare(
            "UPDATE tbl_asset SET $updateFields WHERE id = ?"
        );
        $stmt->bind_param($updateTypes, ...$updateParams);
        $stmt->execute();

        // Fetch created log
        $stmt = $this->conn->prepare(
            "SELECT ml.id, ml.asset_id, ml.maintenance_type, ml.description,
                    ml.cost, ml.vendor_id, ml.technician_name, ml.service_date,
                    ml.next_service_date, ml.images_json, ml.recorded_by, ml.created_at,
                    v.name as vendor_name, u.name as recorded_by_name
             FROM tbl_asset_maintenance_log ml
             LEFT JOIN tbl_vendor v ON v.id = ml.vendor_id
             LEFT JOIN tbl_user u ON u.id = ml.recorded_by
             WHERE ml.id = ?"
        );
        $stmt->bind_param('i', $logId);
        $stmt->execute();
        $log = $this->formatMaintenanceLog($stmt->get_result()->fetch_assoc());

        ApiResponse::created($log, 'Maintenance log added successfully');
    }

    // =========================================================================
    // AMC (Annual Maintenance Contracts)
    // =========================================================================

    /**
     * GET /assets/amc
     * List AMC contracts. Admin only. Filter by status.
     */
    private function listAmc() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $status = sanitizeInput($this->input['status'] ?? '');

        $where = "WHERE amc.society_id = ?";
        $params = [$societyId];
        $types = 'i';

        if (!empty($status)) {
            $allowedStatuses = ['active', 'expired', 'cancelled'];
            if (in_array($status, $allowedStatuses)) {
                $where .= " AND amc.status = ?";
                $params[] = $status;
                $types .= 's';
            }
        }

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM tbl_amc amc $where";
        $stmt = $this->conn->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        // Fetch paginated AMC contracts
        $sql = "SELECT amc.id, amc.society_id, amc.asset_id, amc.vendor_id,
                       amc.title, amc.contract_number, amc.start_date, amc.end_date,
                       amc.amount, amc.frequency, amc.terms, amc.document_path,
                       amc.status, amc.created_at,
                       a.name as asset_name,
                       v.name as vendor_name
                FROM tbl_amc amc
                LEFT JOIN tbl_asset a ON a.id = amc.asset_id
                LEFT JOIN tbl_vendor v ON v.id = amc.vendor_id
                $where
                ORDER BY amc.end_date DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $contracts = [];
        while ($row = $result->fetch_assoc()) {
            $contracts[] = $this->formatAmc($row);
        }

        ApiResponse::paginated($contracts, $total, $page, $perPage, 'AMC contracts retrieved successfully');
    }

    /**
     * POST /assets/amc
     * Create an AMC contract. Admin only. Supports document upload.
     * Input: asset_id, vendor_id, title, contract_number, start_date, end_date,
     *        amount, frequency, terms, document (file upload).
     */
    private function createAmc() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $assetId = isset($this->input['asset_id']) ? intval($this->input['asset_id']) : null;
        $vendorId = isset($this->input['vendor_id']) ? intval($this->input['vendor_id']) : null;
        $title = sanitizeInput($this->input['title'] ?? '');
        $contractNumber = sanitizeInput($this->input['contract_number'] ?? '');
        $startDate = sanitizeInput($this->input['start_date'] ?? '');
        $endDate = sanitizeInput($this->input['end_date'] ?? '');
        $amount = isset($this->input['amount']) ? floatval($this->input['amount']) : null;
        $frequency = sanitizeInput($this->input['frequency'] ?? 'yearly');
        $terms = sanitizeInput($this->input['terms'] ?? '');

        if (empty($title)) {
            ApiResponse::error('AMC title is required');
        }

        if (empty($startDate)) {
            ApiResponse::error('Start date is required');
        }

        if (empty($endDate)) {
            ApiResponse::error('End date is required');
        }

        if ($startDate > $endDate) {
            ApiResponse::error('Start date must be before end date');
        }

        $allowedFrequencies = ['monthly', 'quarterly', 'half_yearly', 'yearly'];
        if (!in_array($frequency, $allowedFrequencies)) {
            ApiResponse::error('Invalid frequency. Allowed: ' . implode(', ', $allowedFrequencies));
        }

        // Validate asset if provided
        if ($assetId) {
            $stmt = $this->conn->prepare(
                "SELECT id FROM tbl_asset WHERE id = ? AND society_id = ?"
            );
            $stmt->bind_param('ii', $assetId, $societyId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                ApiResponse::notFound('Asset not found in this society');
            }
        }

        // Validate vendor if provided
        if ($vendorId) {
            $stmt = $this->conn->prepare("SELECT id FROM tbl_vendor WHERE id = ?");
            $stmt->bind_param('i', $vendorId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                ApiResponse::notFound('Vendor not found');
            }
        }

        // Handle document upload
        $documentPath = null;
        if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['document'], 'amc', ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error']);
            }
            $documentPath = $upload['path'];
        }

        $contractNumberVal = !empty($contractNumber) ? $contractNumber : null;
        $termsVal = !empty($terms) ? $terms : null;

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_amc (society_id, asset_id, vendor_id, title, contract_number,
                                  start_date, end_date, amount, frequency, terms, document_path, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')"
        );
        $stmt->bind_param(
            'iiissssdsss',
            $societyId, $assetId, $vendorId, $title, $contractNumberVal,
            $startDate, $endDate, $amount, $frequency, $termsVal, $documentPath
        );
        $stmt->execute();
        $amcId = $this->conn->insert_id;

        // Fetch created AMC
        $stmt = $this->conn->prepare(
            "SELECT amc.id, amc.society_id, amc.asset_id, amc.vendor_id,
                    amc.title, amc.contract_number, amc.start_date, amc.end_date,
                    amc.amount, amc.frequency, amc.terms, amc.document_path,
                    amc.status, amc.created_at,
                    a.name as asset_name,
                    v.name as vendor_name
             FROM tbl_amc amc
             LEFT JOIN tbl_asset a ON a.id = amc.asset_id
             LEFT JOIN tbl_vendor v ON v.id = amc.vendor_id
             WHERE amc.id = ?"
        );
        $stmt->bind_param('i', $amcId);
        $stmt->execute();
        $amc = $this->formatAmc($stmt->get_result()->fetch_assoc());

        ApiResponse::created($amc, 'AMC contract created successfully');
    }

    /**
     * PUT /assets/amc/{id}
     * Update an AMC contract. Admin only.
     */
    private function updateAmc($id) {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        // Verify AMC exists and belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT id FROM tbl_amc WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('AMC contract not found');
        }

        // Build dynamic update
        $fields = [];
        $params = [];
        $types = '';

        if (isset($this->input['asset_id'])) {
            $assetId = intval($this->input['asset_id']);
            if ($assetId) {
                $aStmt = $this->conn->prepare(
                    "SELECT id FROM tbl_asset WHERE id = ? AND society_id = ?"
                );
                $aStmt->bind_param('ii', $assetId, $societyId);
                $aStmt->execute();
                if ($aStmt->get_result()->num_rows === 0) {
                    ApiResponse::notFound('Asset not found in this society');
                }
            }
            $fields[] = 'asset_id = ?';
            $params[] = $assetId ?: null;
            $types .= 'i';
        }

        if (isset($this->input['vendor_id'])) {
            $vendorId = intval($this->input['vendor_id']);
            if ($vendorId) {
                $vStmt = $this->conn->prepare("SELECT id FROM tbl_vendor WHERE id = ?");
                $vStmt->bind_param('i', $vendorId);
                $vStmt->execute();
                if ($vStmt->get_result()->num_rows === 0) {
                    ApiResponse::notFound('Vendor not found');
                }
            }
            $fields[] = 'vendor_id = ?';
            $params[] = $vendorId ?: null;
            $types .= 'i';
        }

        if (isset($this->input['title'])) {
            $fields[] = 'title = ?';
            $params[] = sanitizeInput($this->input['title']);
            $types .= 's';
        }

        if (isset($this->input['contract_number'])) {
            $fields[] = 'contract_number = ?';
            $params[] = sanitizeInput($this->input['contract_number']);
            $types .= 's';
        }

        if (isset($this->input['start_date'])) {
            $fields[] = 'start_date = ?';
            $params[] = sanitizeInput($this->input['start_date']);
            $types .= 's';
        }

        if (isset($this->input['end_date'])) {
            $fields[] = 'end_date = ?';
            $params[] = sanitizeInput($this->input['end_date']);
            $types .= 's';
        }

        if (isset($this->input['amount'])) {
            $fields[] = 'amount = ?';
            $params[] = floatval($this->input['amount']);
            $types .= 'd';
        }

        if (isset($this->input['frequency'])) {
            $frequency = sanitizeInput($this->input['frequency']);
            $allowedFrequencies = ['monthly', 'quarterly', 'half_yearly', 'yearly'];
            if (!in_array($frequency, $allowedFrequencies)) {
                ApiResponse::error('Invalid frequency. Allowed: ' . implode(', ', $allowedFrequencies));
            }
            $fields[] = 'frequency = ?';
            $params[] = $frequency;
            $types .= 's';
        }

        if (isset($this->input['terms'])) {
            $fields[] = 'terms = ?';
            $params[] = sanitizeInput($this->input['terms']);
            $types .= 's';
        }

        if (isset($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $allowedStatuses = ['active', 'expired', 'cancelled'];
            if (!in_array($status, $allowedStatuses)) {
                ApiResponse::error('Invalid status. Allowed: ' . implode(', ', $allowedStatuses));
            }
            $fields[] = 'status = ?';
            $params[] = $status;
            $types .= 's';
        }

        // Handle document upload
        if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['document'], 'amc', ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error']);
            }
            $fields[] = 'document_path = ?';
            $params[] = $upload['path'];
            $types .= 's';
        }

        if (empty($fields)) {
            ApiResponse::error('No fields to update');
        }

        $sql = "UPDATE tbl_amc SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        // Fetch updated AMC
        $stmt = $this->conn->prepare(
            "SELECT amc.id, amc.society_id, amc.asset_id, amc.vendor_id,
                    amc.title, amc.contract_number, amc.start_date, amc.end_date,
                    amc.amount, amc.frequency, amc.terms, amc.document_path,
                    amc.status, amc.created_at,
                    a.name as asset_name,
                    v.name as vendor_name
             FROM tbl_amc amc
             LEFT JOIN tbl_asset a ON a.id = amc.asset_id
             LEFT JOIN tbl_vendor v ON v.id = amc.vendor_id
             WHERE amc.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $amc = $this->formatAmc($stmt->get_result()->fetch_assoc());

        ApiResponse::success($amc, 'AMC contract updated successfully');
    }

    // =========================================================================
    // Formatters
    // =========================================================================

    /**
     * Format an asset row for API output.
     */
    private function formatAsset($row) {
        return [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'name' => $row['name'],
            'category' => $row['category'],
            'location' => $row['location'],
            'serial_number' => $row['serial_number'],
            'purchase_date' => $row['purchase_date'],
            'purchase_cost' => $row['purchase_cost'] !== null ? (float)$row['purchase_cost'] : null,
            'warranty_end' => $row['warranty_end'],
            'vendor_id' => $row['vendor_id'] ? (int)$row['vendor_id'] : null,
            'vendor_name' => $row['vendor_name'] ?? null,
            'status' => $row['status'],
            'last_service_date' => $row['last_service_date'],
            'next_service_date' => $row['next_service_date'],
            'notes' => $row['notes'],
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * Format a maintenance log row for API output.
     */
    private function formatMaintenanceLog($row) {
        $images = null;
        if (!empty($row['images_json'])) {
            $images = json_decode($row['images_json'], true);
        }

        return [
            'id' => (int)$row['id'],
            'asset_id' => (int)$row['asset_id'],
            'maintenance_type' => $row['maintenance_type'],
            'description' => $row['description'],
            'cost' => $row['cost'] !== null ? (float)$row['cost'] : null,
            'vendor_id' => $row['vendor_id'] ? (int)$row['vendor_id'] : null,
            'vendor_name' => $row['vendor_name'] ?? null,
            'technician_name' => $row['technician_name'],
            'service_date' => $row['service_date'],
            'next_service_date' => $row['next_service_date'],
            'images' => $images,
            'recorded_by' => $row['recorded_by'] ? (int)$row['recorded_by'] : null,
            'recorded_by_name' => $row['recorded_by_name'] ?? null,
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * Format an AMC row for API output.
     */
    private function formatAmc($row) {
        return [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'asset_id' => $row['asset_id'] ? (int)$row['asset_id'] : null,
            'asset_name' => $row['asset_name'] ?? null,
            'vendor_id' => $row['vendor_id'] ? (int)$row['vendor_id'] : null,
            'vendor_name' => $row['vendor_name'] ?? null,
            'title' => $row['title'],
            'contract_number' => $row['contract_number'],
            'start_date' => $row['start_date'],
            'end_date' => $row['end_date'],
            'amount' => $row['amount'] !== null ? (float)$row['amount'] : null,
            'frequency' => $row['frequency'],
            'terms' => $row['terms'],
            'document_path' => $row['document_path'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
        ];
    }
}
