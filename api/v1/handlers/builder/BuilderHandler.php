<?php
/**
 * Securis Smart Society Platform — Builder Portal Handler
 * Manages builder info, builder-society relationships, and builder announcements.
 */

require_once __DIR__ . '/../../../../include/helpers.php';
require_once __DIR__ . '/../../../../include/security.php';

class BuilderHandler {
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

        // Announcement sub-resource
        if ($action === 'announcements') {
            $this->handleAnnouncements($method, $id);
            return;
        }

        // Builder info sub-resource
        if ($action === 'info') {
            $this->getBuilderInfo();
            return;
        }

        switch ($method) {
            case 'POST':
                $this->createBuilder();
                break;

            case 'PUT':
                if (!$id) {
                    ApiResponse::error('Builder ID is required', 400);
                }
                $this->updateBuilder($id);
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ─── Announcement routing ───────────────────────────────────────────

    private function handleAnnouncements($method, $id) {
        switch ($method) {
            case 'GET':
                $this->listAnnouncements();
                break;

            case 'POST':
                $this->createAnnouncement();
                break;

            case 'PUT':
                if (!$id) {
                    ApiResponse::error('Announcement ID is required', 400);
                }
                $this->updateAnnouncement($id);
                break;

            case 'DELETE':
                if (!$id) {
                    ApiResponse::error('Announcement ID is required', 400);
                }
                $this->deleteAnnouncement($id);
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ─── GET /api/v1/builder/info ───────────────────────────────────────

    /**
     * Get builder info for the current society. Joins tbl_builder_society with tbl_builder.
     */
    private function getBuilderInfo() {
        $stmt = $this->conn->prepare(
            "SELECT b.id, b.name, b.company_name, b.phone, b.email, b.logo, b.status,
                    bs.id as builder_society_id, bs.handover_date, bs.warranty_end_date,
                    bs.status as project_status
             FROM tbl_builder_society bs
             JOIN tbl_builder b ON b.id = bs.builder_id
             WHERE bs.society_id = ?"
        );
        $stmt->bind_param('i', $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('No builder found for this society');
        }

        $row = $result->fetch_assoc();
        $stmt->close();

        $data = [
            'builder' => [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'company_name' => $row['company_name'],
                'phone' => $row['phone'],
                'email' => $row['email'],
                'logo' => $row['logo'],
                'status' => $row['status'],
            ],
            'project' => [
                'id' => (int)$row['builder_society_id'],
                'handover_date' => $row['handover_date'],
                'warranty_end_date' => $row['warranty_end_date'],
                'status' => $row['project_status'],
            ],
        ];

        ApiResponse::success($data, 'Builder info retrieved successfully');
    }

    // ─── POST /api/v1/builder ───────────────────────────────────────────

    /**
     * Create a new builder. Requires primary role.
     * Optionally links to current society via tbl_builder_society.
     */
    private function createBuilder() {
        $this->auth->requirePrimary();

        $name = sanitizeInput($this->input['name'] ?? '');
        $companyName = sanitizeInput($this->input['company_name'] ?? '');
        $phone = sanitizeInput($this->input['phone'] ?? '');
        $email = sanitizeInput($this->input['email'] ?? '');
        $handoverDate = sanitizeInput($this->input['handover_date'] ?? '');
        $warrantyEndDate = sanitizeInput($this->input['warranty_end_date'] ?? '');
        $projectStatus = sanitizeInput($this->input['project_status'] ?? 'under_construction');

        if (empty($name)) {
            ApiResponse::error('Builder name is required', 400);
        }

        // Handle logo upload
        $logo = null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['logo'], 'builders', ['jpg', 'jpeg', 'png', 'webp']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error'], 400);
            }
            $logo = $upload['path'];
        }

        $companyNameVal = !empty($companyName) ? $companyName : null;
        $phoneVal = !empty($phone) ? $phone : null;
        $emailVal = !empty($email) ? $email : null;

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_builder (name, company_name, phone, email, logo, status, created_at)
             VALUES (?, ?, ?, ?, ?, 'active', NOW())"
        );
        $stmt->bind_param('sssss', $name, $companyNameVal, $phoneVal, $emailVal, $logo);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to create builder', 500);
        }

        $builderId = $stmt->insert_id;
        $stmt->close();

        // Link builder to society
        $handoverDateVal = !empty($handoverDate) ? $handoverDate : null;
        $warrantyEndDateVal = !empty($warrantyEndDate) ? $warrantyEndDate : null;

        $allowedProjectStatuses = ['under_construction', 'handover_pending', 'handed_over'];
        if (!in_array($projectStatus, $allowedProjectStatuses)) {
            $projectStatus = 'under_construction';
        }

        $linkStmt = $this->conn->prepare(
            "INSERT INTO tbl_builder_society (builder_id, society_id, handover_date, warranty_end_date, status)
             VALUES (?, ?, ?, ?, ?)"
        );
        $linkStmt->bind_param('iisss', $builderId, $this->societyId, $handoverDateVal, $warrantyEndDateVal, $projectStatus);

        if (!$linkStmt->execute()) {
            ApiResponse::error('Builder created but failed to link to society', 500);
        }
        $linkStmt->close();

        // Fetch created builder with society link
        $fetchStmt = $this->conn->prepare(
            "SELECT b.id, b.name, b.company_name, b.phone, b.email, b.logo, b.status,
                    bs.id as builder_society_id, bs.handover_date, bs.warranty_end_date,
                    bs.status as project_status
             FROM tbl_builder b
             JOIN tbl_builder_society bs ON bs.builder_id = b.id AND bs.society_id = ?
             WHERE b.id = ?"
        );
        $fetchStmt->bind_param('ii', $this->societyId, $builderId);
        $fetchStmt->execute();
        $row = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        $data = [
            'builder' => [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'company_name' => $row['company_name'],
                'phone' => $row['phone'],
                'email' => $row['email'],
                'logo' => $row['logo'],
                'status' => $row['status'],
            ],
            'project' => [
                'id' => (int)$row['builder_society_id'],
                'handover_date' => $row['handover_date'],
                'warranty_end_date' => $row['warranty_end_date'],
                'status' => $row['project_status'],
            ],
        ];

        ApiResponse::created($data, 'Builder created successfully');
    }

    // ─── PUT /api/v1/builder/{id} ───────────────────────────────────────

    /**
     * Update builder info. Requires primary role.
     */
    private function updateBuilder($id) {
        $this->auth->requirePrimary();

        // Verify builder exists and is linked to this society
        $checkStmt = $this->conn->prepare(
            "SELECT b.id
             FROM tbl_builder b
             JOIN tbl_builder_society bs ON bs.builder_id = b.id
             WHERE b.id = ? AND bs.society_id = ?"
        );
        $checkStmt->bind_param('ii', $id, $this->societyId);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Builder not found for this society');
        }
        $checkStmt->close();

        // Build dynamic update for tbl_builder
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
            $fields[] = 'email = ?';
            $params[] = sanitizeInput($this->input['email']);
            $types .= 's';
        }
        if (isset($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            if (in_array($status, ['active', 'inactive'])) {
                $fields[] = 'status = ?';
                $params[] = $status;
                $types .= 's';
            }
        }

        // Handle logo upload
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['logo'], 'builders', ['jpg', 'jpeg', 'png', 'webp']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error'], 400);
            }
            $fields[] = 'logo = ?';
            $params[] = $upload['path'];
            $types .= 's';
        }

        // Update tbl_builder if there are fields
        if (!empty($fields)) {
            $sql = "UPDATE tbl_builder SET " . implode(', ', $fields) . " WHERE id = ?";
            $params[] = $id;
            $types .= 'i';

            $updateStmt = $this->conn->prepare($sql);
            $updateStmt->bind_param($types, ...$params);

            if (!$updateStmt->execute()) {
                ApiResponse::error('Failed to update builder', 500);
            }
            $updateStmt->close();
        }

        // Update tbl_builder_society if relevant fields provided
        $bsFields = [];
        $bsParams = [];
        $bsTypes = '';

        if (isset($this->input['handover_date'])) {
            $bsFields[] = 'handover_date = ?';
            $bsParams[] = sanitizeInput($this->input['handover_date']);
            $bsTypes .= 's';
        }
        if (isset($this->input['warranty_end_date'])) {
            $bsFields[] = 'warranty_end_date = ?';
            $bsParams[] = sanitizeInput($this->input['warranty_end_date']);
            $bsTypes .= 's';
        }
        if (isset($this->input['project_status'])) {
            $projectStatus = sanitizeInput($this->input['project_status']);
            $allowedProjectStatuses = ['under_construction', 'handover_pending', 'handed_over'];
            if (in_array($projectStatus, $allowedProjectStatuses)) {
                $bsFields[] = 'status = ?';
                $bsParams[] = $projectStatus;
                $bsTypes .= 's';
            }
        }

        if (!empty($bsFields)) {
            $bsSql = "UPDATE tbl_builder_society SET " . implode(', ', $bsFields) . " WHERE builder_id = ? AND society_id = ?";
            $bsParams[] = $id;
            $bsParams[] = $this->societyId;
            $bsTypes .= 'ii';

            $bsStmt = $this->conn->prepare($bsSql);
            $bsStmt->bind_param($bsTypes, ...$bsParams);

            if (!$bsStmt->execute()) {
                ApiResponse::error('Failed to update builder-society link', 500);
            }
            $bsStmt->close();
        }

        if (empty($fields) && empty($bsFields)) {
            ApiResponse::error('No fields to update', 400);
        }

        // Fetch updated builder
        $fetchStmt = $this->conn->prepare(
            "SELECT b.id, b.name, b.company_name, b.phone, b.email, b.logo, b.status,
                    bs.id as builder_society_id, bs.handover_date, bs.warranty_end_date,
                    bs.status as project_status
             FROM tbl_builder b
             JOIN tbl_builder_society bs ON bs.builder_id = b.id AND bs.society_id = ?
             WHERE b.id = ?"
        );
        $fetchStmt->bind_param('ii', $this->societyId, $id);
        $fetchStmt->execute();
        $row = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        $data = [
            'builder' => [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'company_name' => $row['company_name'],
                'phone' => $row['phone'],
                'email' => $row['email'],
                'logo' => $row['logo'],
                'status' => $row['status'],
            ],
            'project' => [
                'id' => (int)$row['builder_society_id'],
                'handover_date' => $row['handover_date'],
                'warranty_end_date' => $row['warranty_end_date'],
                'status' => $row['project_status'],
            ],
        ];

        ApiResponse::success($data, 'Builder updated successfully');
    }

    // ─── GET /api/v1/builder/announcements ──────────────────────────────

    /**
     * List builder announcements for the current society. All members can view.
     * Supports pagination and optional type filter.
     */
    private function listAnnouncements() {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $where = "(ba.society_id = ? OR ba.society_id IS NULL)";
        $params = [$this->societyId];
        $types = 'i';

        // Only show announcements from builders linked to this society
        $where .= " AND ba.builder_id IN (SELECT builder_id FROM tbl_builder_society WHERE society_id = ?)";
        $params[] = $this->societyId;
        $types .= 'i';

        // Filter by type
        if (!empty($this->input['type'])) {
            $type = sanitizeInput($this->input['type']);
            $allowedTypes = ['update', 'warranty', 'promotion', 'other'];
            if (in_array($type, $allowedTypes)) {
                $where .= " AND ba.type = ?";
                $params[] = $type;
                $types .= 's';
            }
        }

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM tbl_builder_announcement ba WHERE $where";
        $countStmt = $this->conn->prepare($countSql);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch announcements
        $sql = "SELECT ba.id, ba.builder_id, ba.society_id, ba.title, ba.content,
                       ba.type, ba.image, ba.created_at,
                       b.name as builder_name, b.company_name
                FROM tbl_builder_announcement ba
                JOIN tbl_builder b ON b.id = ba.builder_id
                WHERE $where
                ORDER BY ba.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $announcements = [];
        while ($row = $result->fetch_assoc()) {
            $announcements[] = $this->formatAnnouncement($row);
        }
        $stmt->close();

        ApiResponse::paginated($announcements, $total, $page, $perPage, 'Builder announcements retrieved successfully');
    }

    // ─── POST /api/v1/builder/announcements ─────────────────────────────

    /**
     * Create a builder announcement. Requires primary role.
     */
    private function createAnnouncement() {
        $this->auth->requirePrimary();

        $title = sanitizeInput($this->input['title'] ?? '');
        $content = sanitizeInput($this->input['content'] ?? '');
        $type = sanitizeInput($this->input['type'] ?? 'update');
        $builderId = isset($this->input['builder_id']) ? (int)$this->input['builder_id'] : 0;

        if (empty($title)) {
            ApiResponse::error('Announcement title is required', 400);
        }

        $allowedTypes = ['update', 'warranty', 'promotion', 'other'];
        if (!in_array($type, $allowedTypes)) {
            ApiResponse::error('Invalid type. Allowed: ' . implode(', ', $allowedTypes), 400);
        }

        // If builder_id not provided, try to get from the society's linked builder
        if (!$builderId) {
            $bStmt = $this->conn->prepare(
                "SELECT builder_id FROM tbl_builder_society WHERE society_id = ? LIMIT 1"
            );
            $bStmt->bind_param('i', $this->societyId);
            $bStmt->execute();
            $bResult = $bStmt->get_result();
            if ($bResult->num_rows === 0) {
                ApiResponse::error('No builder linked to this society. Provide builder_id.', 400);
            }
            $builderId = (int)$bResult->fetch_assoc()['builder_id'];
            $bStmt->close();
        } else {
            // Verify builder is linked to this society
            $checkStmt = $this->conn->prepare(
                "SELECT id FROM tbl_builder_society WHERE builder_id = ? AND society_id = ?"
            );
            $checkStmt->bind_param('ii', $builderId, $this->societyId);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows === 0) {
                ApiResponse::error('Builder is not linked to this society', 400);
            }
            $checkStmt->close();
        }

        // Handle image upload
        $image = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['image'], 'builder_announcements', ['jpg', 'jpeg', 'png', 'webp']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error'], 400);
            }
            $image = $upload['path'];
        }

        $contentVal = !empty($content) ? $content : null;

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_builder_announcement (builder_id, society_id, title, content, type, image, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param('iissss', $builderId, $this->societyId, $title, $contentVal, $type, $image);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to create announcement', 500);
        }

        $announcementId = $stmt->insert_id;
        $stmt->close();

        // Fetch created announcement
        $fetchStmt = $this->conn->prepare(
            "SELECT ba.id, ba.builder_id, ba.society_id, ba.title, ba.content,
                    ba.type, ba.image, ba.created_at,
                    b.name as builder_name, b.company_name
             FROM tbl_builder_announcement ba
             JOIN tbl_builder b ON b.id = ba.builder_id
             WHERE ba.id = ?"
        );
        $fetchStmt->bind_param('i', $announcementId);
        $fetchStmt->execute();
        $row = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        ApiResponse::created($this->formatAnnouncement($row), 'Announcement created successfully');
    }

    // ─── PUT /api/v1/builder/announcements/{id} ─────────────────────────

    /**
     * Update a builder announcement. Requires primary role.
     */
    private function updateAnnouncement($id) {
        $this->auth->requirePrimary();

        // Verify announcement exists and is associated with this society
        $checkStmt = $this->conn->prepare(
            "SELECT ba.id, ba.builder_id
             FROM tbl_builder_announcement ba
             JOIN tbl_builder_society bs ON bs.builder_id = ba.builder_id AND bs.society_id = ?
             WHERE ba.id = ? AND (ba.society_id = ? OR ba.society_id IS NULL)"
        );
        $checkStmt->bind_param('iii', $this->societyId, $id, $this->societyId);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Announcement not found');
        }
        $checkStmt->close();

        $fields = [];
        $params = [];
        $types = '';

        if (isset($this->input['title'])) {
            $fields[] = 'title = ?';
            $params[] = sanitizeInput($this->input['title']);
            $types .= 's';
        }
        if (isset($this->input['content'])) {
            $fields[] = 'content = ?';
            $params[] = sanitizeInput($this->input['content']);
            $types .= 's';
        }
        if (isset($this->input['type'])) {
            $type = sanitizeInput($this->input['type']);
            $allowedTypes = ['update', 'warranty', 'promotion', 'other'];
            if (in_array($type, $allowedTypes)) {
                $fields[] = 'type = ?';
                $params[] = $type;
                $types .= 's';
            }
        }

        // Handle image upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['image'], 'builder_announcements', ['jpg', 'jpeg', 'png', 'webp']);
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

        $sql = "UPDATE tbl_builder_announcement SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $updateStmt = $this->conn->prepare($sql);
        $updateStmt->bind_param($types, ...$params);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to update announcement', 500);
        }
        $updateStmt->close();

        // Fetch updated announcement
        $fetchStmt = $this->conn->prepare(
            "SELECT ba.id, ba.builder_id, ba.society_id, ba.title, ba.content,
                    ba.type, ba.image, ba.created_at,
                    b.name as builder_name, b.company_name
             FROM tbl_builder_announcement ba
             JOIN tbl_builder b ON b.id = ba.builder_id
             WHERE ba.id = ?"
        );
        $fetchStmt->bind_param('i', $id);
        $fetchStmt->execute();
        $row = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        ApiResponse::success($this->formatAnnouncement($row), 'Announcement updated successfully');
    }

    // ─── DELETE /api/v1/builder/announcements/{id} ──────────────────────

    /**
     * Delete a builder announcement. Requires primary role.
     */
    private function deleteAnnouncement($id) {
        $this->auth->requirePrimary();

        // Verify announcement exists and is associated with this society
        $checkStmt = $this->conn->prepare(
            "SELECT ba.id
             FROM tbl_builder_announcement ba
             JOIN tbl_builder_society bs ON bs.builder_id = ba.builder_id AND bs.society_id = ?
             WHERE ba.id = ? AND (ba.society_id = ? OR ba.society_id IS NULL)"
        );
        $checkStmt->bind_param('iii', $this->societyId, $id, $this->societyId);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Announcement not found');
        }
        $checkStmt->close();

        $stmt = $this->conn->prepare("DELETE FROM tbl_builder_announcement WHERE id = ?");
        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to delete announcement', 500);
        }
        $stmt->close();

        ApiResponse::success(null, 'Announcement deleted successfully');
    }

    // ─── Formatters ─────────────────────────────────────────────────────

    private function formatAnnouncement($row) {
        return [
            'id' => (int)$row['id'],
            'builder_id' => (int)$row['builder_id'],
            'builder_name' => $row['builder_name'] ?? null,
            'company_name' => $row['company_name'] ?? null,
            'society_id' => $row['society_id'] ? (int)$row['society_id'] : null,
            'title' => $row['title'],
            'content' => $row['content'],
            'type' => $row['type'],
            'image' => $row['image'],
            'created_at' => $row['created_at'],
        ];
    }
}
