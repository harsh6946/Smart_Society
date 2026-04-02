<?php
/**
 * Securis Smart Society Platform — Complaint Handler
 * Manages complaints: raise, assign, resolve, close, reopen, and category CRUD.
 */

require_once __DIR__ . '/../../../../include/helpers.php';
require_once __DIR__ . '/../../../../include/security.php';

class ComplaintHandler {
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

        // Category sub-resource
        if ($action === 'categories') {
            $this->handleCategories($method, $id);
            return;
        }

        switch ($method) {
            case 'GET':
                if ($id) {
                    $this->getComplaint($id);
                } else {
                    $this->listComplaints();
                }
                break;

            case 'POST':
                $this->createComplaint();
                break;

            case 'PUT':
                if (!$id) {
                    ApiResponse::error('Complaint ID is required', 400);
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
            case 'assign':
                $this->assignComplaint($id);
                break;
            case 'resolve':
                $this->resolveComplaint($id);
                break;
            case 'close':
                $this->closeComplaint($id);
                break;
            case 'reopen':
                $this->reopenComplaint($id);
                break;
            default:
                ApiResponse::error('Invalid action', 400);
        }
    }

    // ─── GET /api/v1/complaints ─────────────────────────────────────────

    /**
     * List complaints. Residents see only their own; primary owners see all in society.
     * Filters: status, priority, category_id. Paginated. Ordered by created_at DESC.
     */
    private function listComplaints() {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);
        $userId = $this->auth->getUserId();
        $isPrimary = $this->user['is_primary'];

        $where = "c.society_id = ?";
        $params = [$this->societyId];
        $types = 'i';

        // Residents see only their own complaints
        if (!$isPrimary) {
            $where .= " AND c.raised_by = ?";
            $params[] = $userId;
            $types .= 'i';
        }

        // Filter by status
        if (!empty($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $where .= " AND c.status = ?";
            $params[] = $status;
            $types .= 's';
        }

        // Filter by priority
        if (!empty($this->input['priority'])) {
            $priority = sanitizeInput($this->input['priority']);
            $where .= " AND c.priority = ?";
            $params[] = $priority;
            $types .= 's';
        }

        // Filter by category_id
        if (!empty($this->input['category_id'])) {
            $categoryId = (int)$this->input['category_id'];
            $where .= " AND c.category_id = ?";
            $params[] = $categoryId;
            $types .= 'i';
        }

        // Count total
        $countStmt = $this->conn->prepare("SELECT COUNT(*) as total FROM tbl_complaint c WHERE $where");
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch complaints
        $sql = "SELECT c.id, c.society_id, c.flat_id, c.raised_by, c.category_id,
                       c.title, c.description, c.images_json, c.assigned_to,
                       c.priority, c.status, c.resolution_note, c.sla_hours,
                       c.created_at, c.updated_at, c.resolved_at, c.closed_at,
                       cc.name as category_name,
                       u.name as raised_by_name,
                       f.flat_number
                FROM tbl_complaint c
                LEFT JOIN tbl_complaint_category cc ON cc.id = c.category_id
                LEFT JOIN tbl_user u ON u.id = c.raised_by
                LEFT JOIN tbl_flat f ON f.id = c.flat_id
                WHERE $where
                ORDER BY c.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $complaints = [];
        while ($row = $result->fetch_assoc()) {
            $complaints[] = $this->formatComplaint($row);
        }
        $stmt->close();

        ApiResponse::paginated($complaints, $total, $page, $perPage, 'Complaints retrieved successfully');
    }

    // ─── GET /api/v1/complaints/{id} ────────────────────────────────────

    /**
     * Get single complaint detail with full info, category name, raiser name, assigned_to name.
     */
    private function getComplaint($id) {
        $userId = $this->auth->getUserId();
        $isPrimary = $this->user['is_primary'];

        $stmt = $this->conn->prepare(
            "SELECT c.id, c.society_id, c.flat_id, c.raised_by, c.category_id,
                    c.title, c.description, c.images_json, c.assigned_to,
                    c.priority, c.status, c.resolution_note, c.sla_hours,
                    c.created_at, c.updated_at, c.resolved_at, c.closed_at,
                    cc.name as category_name,
                    u.name as raised_by_name,
                    f.flat_number,
                    au.name as assigned_to_name
             FROM tbl_complaint c
             LEFT JOIN tbl_complaint_category cc ON cc.id = c.category_id
             LEFT JOIN tbl_user u ON u.id = c.raised_by
             LEFT JOIN tbl_flat f ON f.id = c.flat_id
             LEFT JOIN tbl_user au ON au.id = c.assigned_to
             WHERE c.id = ? AND c.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Complaint not found');
        }

        $row = $result->fetch_assoc();
        $stmt->close();

        // Non-primary users can only see their own complaints
        if (!$isPrimary && (int)$row['raised_by'] !== $userId) {
            ApiResponse::forbidden('You can only view your own complaints');
        }

        $complaint = $this->formatComplaintDetail($row);

        ApiResponse::success($complaint, 'Complaint retrieved successfully');
    }

    // ─── POST /api/v1/complaints ────────────────────────────────────────

    /**
     * Raise a new complaint. Multipart form with optional image uploads.
     */
    private function createComplaint() {
        $categoryId = isset($this->input['category_id']) ? (int)$this->input['category_id'] : 0;
        $title = sanitizeInput($this->input['title'] ?? '');
        $description = sanitizeInput($this->input['description'] ?? '');
        $priority = sanitizeInput($this->input['priority'] ?? 'medium');

        // Validation
        if (empty($categoryId)) {
            ApiResponse::error('Category ID is required', 400);
        }
        if (empty($title)) {
            ApiResponse::error('Title is required', 400);
        }
        if (empty($description)) {
            ApiResponse::error('Description is required', 400);
        }

        $allowedPriorities = ['low', 'medium', 'high', 'urgent'];
        if (!in_array($priority, $allowedPriorities)) {
            ApiResponse::error('Invalid priority. Allowed: ' . implode(', ', $allowedPriorities), 400);
        }

        // Verify category exists and belongs to society
        $catStmt = $this->conn->prepare(
            "SELECT id, default_sla_hours FROM tbl_complaint_category
             WHERE id = ? AND society_id = ? AND is_active = 1"
        );
        $catStmt->bind_param('ii', $categoryId, $this->societyId);
        $catStmt->execute();
        $catResult = $catStmt->get_result();

        if ($catResult->num_rows === 0) {
            ApiResponse::notFound('Complaint category not found');
        }

        $category = $catResult->fetch_assoc();
        $slaHours = $category['default_sla_hours'];
        $catStmt->close();

        // Handle image uploads
        $imagesJson = null;
        if (!empty($_FILES['images']) && $_FILES['images']['error'][0] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = uploadMultipleFiles($_FILES['images'], 'complaints');
            if (isset($uploadResult['error'])) {
                ApiResponse::error($uploadResult['error'], 400);
            }
            if (!empty($uploadResult['paths'])) {
                $imagesJson = json_encode($uploadResult['paths']);
            }
        }

        $userId = $this->auth->getUserId();
        $flatId = $this->auth->getFlatId();

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_complaint
                (society_id, flat_id, raised_by, category_id, title, description,
                 images_json, priority, status, sla_hours, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, NOW(), NOW())"
        );
        $stmt->bind_param(
            'iiiissssi',
            $this->societyId, $flatId, $userId, $categoryId,
            $title, $description, $imagesJson, $priority, $slaHours
        );

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to raise complaint', 500);
        }

        $complaintId = $stmt->insert_id;
        $stmt->close();

        // Fetch created complaint
        $this->fetchAndRespond($complaintId, 'Complaint raised successfully', 201);
    }

    // ─── PUT /api/v1/complaints/{id}/assign ─────────────────────────────

    /**
     * Assign complaint to a user. Only primary owners.
     */
    private function assignComplaint($id) {
        $this->auth->requirePrimary();

        $assignedTo = isset($this->input['assigned_to']) ? (int)$this->input['assigned_to'] : 0;
        if (empty($assignedTo)) {
            ApiResponse::error('assigned_to (user ID) is required', 400);
        }

        $complaint = $this->findComplaint($id);

        $stmt = $this->conn->prepare(
            "UPDATE tbl_complaint
             SET assigned_to = ?, status = 'in_progress', updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->bind_param('ii', $assignedTo, $id);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to assign complaint', 500);
        }
        $stmt->close();

        $this->fetchAndRespond($id, 'Complaint assigned successfully');
    }

    // ─── PUT /api/v1/complaints/{id}/resolve ────────────────────────────

    /**
     * Mark complaint as resolved. Only the assigned user or primary owner.
     */
    private function resolveComplaint($id) {
        $complaint = $this->findComplaint($id);

        $userId = $this->auth->getUserId();
        $isPrimary = $this->user['is_primary'];

        if ((int)$complaint['assigned_to'] !== $userId && !$isPrimary) {
            ApiResponse::forbidden('Only the assigned user or primary owner can resolve this complaint');
        }

        $resolutionNote = sanitizeInput($this->input['resolution_note'] ?? '');
        if (empty($resolutionNote)) {
            ApiResponse::error('Resolution note is required', 400);
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_complaint
             SET status = 'resolved', resolution_note = ?, resolved_at = NOW(), updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->bind_param('si', $resolutionNote, $id);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to resolve complaint', 500);
        }
        $stmt->close();

        $this->fetchAndRespond($id, 'Complaint resolved successfully');
    }

    // ─── PUT /api/v1/complaints/{id}/close ──────────────────────────────

    /**
     * Close complaint. Only primary owners.
     */
    private function closeComplaint($id) {
        $this->auth->requirePrimary();

        $complaint = $this->findComplaint($id);

        $stmt = $this->conn->prepare(
            "UPDATE tbl_complaint
             SET status = 'closed', closed_at = NOW(), updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to close complaint', 500);
        }
        $stmt->close();

        $this->fetchAndRespond($id, 'Complaint closed successfully');
    }

    // ─── PUT /api/v1/complaints/{id}/reopen ─────────────────────────────

    /**
     * Reopen complaint. Only the user who raised it.
     */
    private function reopenComplaint($id) {
        $complaint = $this->findComplaint($id);

        $userId = $this->auth->getUserId();
        if ((int)$complaint['raised_by'] !== $userId) {
            ApiResponse::forbidden('Only the user who raised this complaint can reopen it');
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_complaint
             SET status = 'reopened', resolved_at = NULL, closed_at = NULL, updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to reopen complaint', 500);
        }
        $stmt->close();

        $this->fetchAndRespond($id, 'Complaint reopened successfully');
    }

    // ─── Category endpoints ─────────────────────────────────────────────

    private function handleCategories($method, $id) {
        switch ($method) {
            case 'GET':
                $this->listCategories();
                break;

            case 'POST':
                $this->createCategory();
                break;

            case 'PUT':
                if (!$id) {
                    ApiResponse::error('Category ID is required', 400);
                }
                $this->updateCategory($id);
                break;

            case 'DELETE':
                if (!$id) {
                    ApiResponse::error('Category ID is required', 400);
                }
                $this->deleteCategory($id);
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    /**
     * GET /api/v1/complaints/categories
     * List complaint categories for the society.
     */
    private function listCategories() {
        $stmt = $this->conn->prepare(
            "SELECT id, society_id, name, default_sla_hours, is_active
             FROM tbl_complaint_category
             WHERE society_id = ? AND is_active = 1
             ORDER BY name ASC"
        );
        $stmt->bind_param('i', $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        $categories = [];
        while ($row = $result->fetch_assoc()) {
            $categories[] = [
                'id' => (int)$row['id'],
                'society_id' => (int)$row['society_id'],
                'name' => $row['name'],
                'default_sla_hours' => (int)$row['default_sla_hours'],
                'is_active' => (bool)$row['is_active'],
            ];
        }
        $stmt->close();

        ApiResponse::success($categories, 'Categories retrieved successfully');
    }

    /**
     * POST /api/v1/complaints/categories
     * Create a complaint category. Only primary owners.
     */
    private function createCategory() {
        $this->auth->requirePrimary();

        $name = sanitizeInput($this->input['name'] ?? '');
        $defaultSlaHours = isset($this->input['default_sla_hours']) ? (int)$this->input['default_sla_hours'] : 0;

        if (empty($name)) {
            ApiResponse::error('Category name is required', 400);
        }
        if ($defaultSlaHours <= 0) {
            ApiResponse::error('Default SLA hours must be a positive number', 400);
        }

        // Check for duplicate name in society
        $checkStmt = $this->conn->prepare(
            "SELECT id FROM tbl_complaint_category
             WHERE society_id = ? AND name = ? AND is_active = 1"
        );
        $checkStmt->bind_param('is', $this->societyId, $name);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            ApiResponse::error('A category with this name already exists', 400);
        }
        $checkStmt->close();

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_complaint_category (society_id, name, default_sla_hours, is_active)
             VALUES (?, ?, ?, 1)"
        );
        $stmt->bind_param('isi', $this->societyId, $name, $defaultSlaHours);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to create category', 500);
        }

        $categoryId = $stmt->insert_id;
        $stmt->close();

        $category = [
            'id' => $categoryId,
            'society_id' => $this->societyId,
            'name' => $name,
            'default_sla_hours' => $defaultSlaHours,
            'is_active' => true,
        ];

        ApiResponse::created($category, 'Category created successfully');
    }

    /**
     * PUT /api/v1/complaints/categories/{id}
     * Update a complaint category. Only primary owners.
     */
    private function updateCategory($id) {
        $this->auth->requirePrimary();

        // Verify category exists
        $checkStmt = $this->conn->prepare(
            "SELECT id FROM tbl_complaint_category WHERE id = ? AND society_id = ? AND is_active = 1"
        );
        $checkStmt->bind_param('ii', $id, $this->societyId);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Category not found');
        }
        $checkStmt->close();

        $fields = [];
        $params = [];
        $types = '';

        if (isset($this->input['name'])) {
            $fields[] = 'name = ?';
            $params[] = sanitizeInput($this->input['name']);
            $types .= 's';
        }

        if (isset($this->input['default_sla_hours'])) {
            $sla = (int)$this->input['default_sla_hours'];
            if ($sla <= 0) {
                ApiResponse::error('Default SLA hours must be a positive number', 400);
            }
            $fields[] = 'default_sla_hours = ?';
            $params[] = $sla;
            $types .= 'i';
        }

        if (empty($fields)) {
            ApiResponse::error('No fields to update', 400);
        }

        $sql = "UPDATE tbl_complaint_category SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to update category', 500);
        }
        $stmt->close();

        // Fetch updated category
        $fetchStmt = $this->conn->prepare(
            "SELECT id, society_id, name, default_sla_hours, is_active
             FROM tbl_complaint_category WHERE id = ?"
        );
        $fetchStmt->bind_param('i', $id);
        $fetchStmt->execute();
        $row = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        $category = [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'name' => $row['name'],
            'default_sla_hours' => (int)$row['default_sla_hours'],
            'is_active' => (bool)$row['is_active'],
        ];

        ApiResponse::success($category, 'Category updated successfully');
    }

    /**
     * DELETE /api/v1/complaints/categories/{id}
     * Deactivate a category (soft delete). Only primary owners.
     */
    private function deleteCategory($id) {
        $this->auth->requirePrimary();

        $checkStmt = $this->conn->prepare(
            "SELECT id FROM tbl_complaint_category WHERE id = ? AND society_id = ? AND is_active = 1"
        );
        $checkStmt->bind_param('ii', $id, $this->societyId);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Category not found');
        }
        $checkStmt->close();

        $stmt = $this->conn->prepare(
            "UPDATE tbl_complaint_category SET is_active = 0 WHERE id = ?"
        );
        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to deactivate category', 500);
        }
        $stmt->close();

        ApiResponse::success(null, 'Category deactivated successfully');
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * Find a complaint by ID within the current society. Exits 404 if not found.
     */
    private function findComplaint($id) {
        $stmt = $this->conn->prepare(
            "SELECT id, raised_by, assigned_to, status
             FROM tbl_complaint
             WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Complaint not found');
        }

        $complaint = $result->fetch_assoc();
        $stmt->close();

        return $complaint;
    }

    /**
     * Fetch a complaint by ID and send API response.
     */
    private function fetchAndRespond($id, $message, $code = 200) {
        $stmt = $this->conn->prepare(
            "SELECT c.id, c.society_id, c.flat_id, c.raised_by, c.category_id,
                    c.title, c.description, c.images_json, c.assigned_to,
                    c.priority, c.status, c.resolution_note, c.sla_hours,
                    c.created_at, c.updated_at, c.resolved_at, c.closed_at,
                    cc.name as category_name,
                    u.name as raised_by_name,
                    f.flat_number,
                    au.name as assigned_to_name
             FROM tbl_complaint c
             LEFT JOIN tbl_complaint_category cc ON cc.id = c.category_id
             LEFT JOIN tbl_user u ON u.id = c.raised_by
             LEFT JOIN tbl_flat f ON f.id = c.flat_id
             LEFT JOIN tbl_user au ON au.id = c.assigned_to
             WHERE c.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $complaint = $this->formatComplaintDetail($row);

        if ($code === 201) {
            ApiResponse::created($complaint, $message);
        } else {
            ApiResponse::success($complaint, $message);
        }
    }

    /**
     * Format complaint row for list view (no assigned_to_name).
     */
    private function formatComplaint($row) {
        return [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'flat_id' => $row['flat_id'] !== null ? (int)$row['flat_id'] : null,
            'flat_number' => $row['flat_number'] ?? null,
            'category' => [
                'id' => $row['category_id'] !== null ? (int)$row['category_id'] : null,
                'name' => $row['category_name'] ?? null,
            ],
            'title' => $row['title'],
            'description' => $row['description'],
            'images' => $row['images_json'] ? json_decode($row['images_json'], true) : [],
            'raised_by' => [
                'id' => (int)$row['raised_by'],
                'name' => $row['raised_by_name'],
            ],
            'assigned_to' => $row['assigned_to'] !== null ? (int)$row['assigned_to'] : null,
            'priority' => $row['priority'],
            'status' => $row['status'],
            'resolution_note' => $row['resolution_note'],
            'sla_hours' => $row['sla_hours'] !== null ? (int)$row['sla_hours'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'resolved_at' => $row['resolved_at'],
            'closed_at' => $row['closed_at'],
        ];
    }

    /**
     * Format complaint row for detail view (includes assigned_to name).
     */
    private function formatComplaintDetail($row) {
        $complaint = $this->formatComplaint($row);

        // Override assigned_to with full object
        $complaint['assigned_to'] = $row['assigned_to'] !== null ? [
            'id' => (int)$row['assigned_to'],
            'name' => $row['assigned_to_name'] ?? null,
        ] : null;

        return $complaint;
    }
}
