<?php
/**
 * Securis Smart Society Platform — Marketplace Handler
 * Community Commerce & Marketplace: classifieds, service providers, reviews, carpool.
 */

require_once __DIR__ . '/../../../../include/helpers.php';
require_once __DIR__ . '/../../../../include/security.php';

class MarketplaceHandler {
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
                $this->handleGet($action, $id);
                break;

            case 'POST':
                $this->handlePost($action, $id);
                break;

            case 'PUT':
                $this->handlePut($action, $id);
                break;

            case 'DELETE':
                $this->handleDelete($action, $id);
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ─── Route Dispatchers ──────────────────────────────────────────

    private function handleGet($action, $id) {
        switch ($action) {
            case 'classifieds':
                if ($id) {
                    $this->getClassified($id);
                } else {
                    $this->listClassifieds();
                }
                break;

            case 'services':
                if ($id) {
                    $this->getServiceProvider($id);
                } else {
                    $this->listServiceProviders();
                }
                break;

            case 'carpool':
                $this->listCarpool();
                break;

            default:
                ApiResponse::error('Invalid action', 400);
        }
    }

    private function handlePost($action, $id) {
        switch ($action) {
            case 'classifieds':
                $this->createClassified();
                break;

            case 'services':
                $this->addServiceProvider();
                break;

            case 'reviews':
                $this->addReview();
                break;

            case 'carpool':
                $this->createCarpool();
                break;

            default:
                ApiResponse::error('Invalid action', 400);
        }
    }

    private function handlePut($action, $id) {
        if (!$id) {
            ApiResponse::error('Resource ID is required', 400);
        }

        switch ($action) {
            case 'classifieds':
                // Check for sub-action in URI (mark-sold)
                $uri = $_SERVER['REQUEST_URI'] ?? '';
                if (strpos($uri, '/mark-sold') !== false) {
                    $this->markClassifiedSold($id);
                } else {
                    $this->updateClassified($id);
                }
                break;

            case 'services':
                // Check for sub-action in URI (verify)
                $uri = $_SERVER['REQUEST_URI'] ?? '';
                if (strpos($uri, '/verify') !== false) {
                    $this->verifyServiceProvider($id);
                } else {
                    ApiResponse::error('Invalid action', 400);
                }
                break;

            case 'carpool':
                $this->updateCarpool($id);
                break;

            default:
                ApiResponse::error('Invalid action', 400);
        }
    }

    private function handleDelete($action, $id) {
        if (!$id) {
            ApiResponse::error('Resource ID is required', 400);
        }

        switch ($action) {
            case 'classifieds':
                $this->deleteClassified($id);
                break;

            case 'carpool':
                $this->deleteCarpool($id);
                break;

            default:
                ApiResponse::error('Invalid action', 400);
        }
    }

    // ─── Classifieds ────────────────────────────────────────────────

    /**
     * GET /marketplace/classifieds
     * List classifieds for the society. Paginated, filterable by type, category, status.
     */
    private function listClassifieds() {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $where = "c.society_id = ?";
        $params = [$this->societyId];
        $types = 'i';

        // Filter by type
        if (!empty($this->input['type'])) {
            $type = sanitizeInput($this->input['type']);
            $allowedTypes = ['sell', 'buy', 'rent', 'free', 'lost', 'found'];
            if (in_array($type, $allowedTypes)) {
                $where .= " AND c.type = ?";
                $params[] = $type;
                $types .= 's';
            }
        }

        // Filter by category
        if (!empty($this->input['category'])) {
            $category = sanitizeInput($this->input['category']);
            $where .= " AND c.category = ?";
            $params[] = $category;
            $types .= 's';
        }

        // Filter by status (default to active)
        $status = sanitizeInput($this->input['status'] ?? 'active');
        $allowedStatuses = ['active', 'sold', 'expired', 'removed'];
        if (in_array($status, $allowedStatuses)) {
            $where .= " AND c.status = ?";
            $params[] = $status;
            $types .= 's';
        }

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM tbl_classified c WHERE $where";
        $countStmt = $this->conn->prepare($countSql);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch classifieds
        $sql = "SELECT c.id, c.society_id, c.user_id, c.type, c.category, c.title,
                       c.description, c.price, c.images_json, c.contact_phone,
                       c.status, c.expires_at, c.created_at,
                       u.name as user_name, u.avatar as user_avatar
                FROM tbl_classified c
                LEFT JOIN tbl_user u ON u.id = c.user_id
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

        $classifieds = [];
        while ($row = $result->fetch_assoc()) {
            $classifieds[] = $this->formatClassified($row);
        }
        $stmt->close();

        ApiResponse::paginated($classifieds, $total, $page, $perPage, 'Classifieds retrieved successfully');
    }

    /**
     * GET /marketplace/classifieds/{id}
     * Classified detail with poster info.
     */
    private function getClassified($id) {
        $stmt = $this->conn->prepare(
            "SELECT c.id, c.society_id, c.user_id, c.type, c.category, c.title,
                    c.description, c.price, c.images_json, c.contact_phone,
                    c.status, c.expires_at, c.created_at,
                    u.name as user_name, u.avatar as user_avatar
             FROM tbl_classified c
             LEFT JOIN tbl_user u ON u.id = c.user_id
             WHERE c.id = ? AND c.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Classified not found');
        }

        $classified = $this->formatClassified($result->fetch_assoc());
        $stmt->close();

        ApiResponse::success($classified, 'Classified retrieved successfully');
    }

    /**
     * POST /marketplace/classifieds
     * Post a new classified. Auto-expires in 30 days.
     */
    private function createClassified() {
        $userId = $this->auth->getUserId();

        $type = sanitizeInput($this->input['type'] ?? '');
        $category = sanitizeInput($this->input['category'] ?? '');
        $title = sanitizeInput($this->input['title'] ?? '');
        $description = sanitizeInput($this->input['description'] ?? '');
        $price = isset($this->input['price']) ? (float)$this->input['price'] : null;
        $contactPhone = sanitizeInput($this->input['contact_phone'] ?? '');

        // Validation
        if (empty($type)) {
            ApiResponse::error('Type is required', 400);
        }

        $allowedTypes = ['sell', 'buy', 'rent', 'free', 'lost', 'found'];
        if (!in_array($type, $allowedTypes)) {
            ApiResponse::error('Invalid type. Allowed: ' . implode(', ', $allowedTypes), 400);
        }

        if (empty($title)) {
            ApiResponse::error('Title is required', 400);
        }

        // Handle image uploads (multiple images stored as JSON)
        $imagesJson = null;
        if (isset($_FILES['images'])) {
            $images = [];
            $files = $_FILES['images'];

            // Handle both single and multiple file uploads
            if (is_array($files['name'])) {
                $fileCount = count($files['name']);
                for ($i = 0; $i < $fileCount; $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $singleFile = [
                            'name' => $files['name'][$i],
                            'type' => $files['type'][$i],
                            'tmp_name' => $files['tmp_name'][$i],
                            'error' => $files['error'][$i],
                            'size' => $files['size'][$i],
                        ];
                        $upload = uploadFile($singleFile, 'classifieds', ['jpg', 'jpeg', 'png', 'webp']);
                        if (isset($upload['error'])) {
                            ApiResponse::error($upload['error'], 400);
                        }
                        $images[] = $upload['path'];
                    }
                }
            } else {
                if ($files['error'] === UPLOAD_ERR_OK) {
                    $upload = uploadFile($files, 'classifieds', ['jpg', 'jpeg', 'png', 'webp']);
                    if (isset($upload['error'])) {
                        ApiResponse::error($upload['error'], 400);
                    }
                    $images[] = $upload['path'];
                }
            }

            if (!empty($images)) {
                $imagesJson = json_encode($images);
            }
        }

        // Auto-expire in 30 days
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_classified (society_id, user_id, type, category, title, description, price, images_json, contact_phone, status, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)"
        );
        $stmt->bind_param(
            'iissssdsss',
            $this->societyId, $userId, $type, $category, $title,
            $description, $price, $imagesJson, $contactPhone, $expiresAt
        );

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to create classified', 500);
        }

        $classifiedId = $stmt->insert_id;
        $stmt->close();

        // Fetch created classified
        $fetchStmt = $this->conn->prepare(
            "SELECT c.id, c.society_id, c.user_id, c.type, c.category, c.title,
                    c.description, c.price, c.images_json, c.contact_phone,
                    c.status, c.expires_at, c.created_at,
                    u.name as user_name, u.avatar as user_avatar
             FROM tbl_classified c
             LEFT JOIN tbl_user u ON u.id = c.user_id
             WHERE c.id = ?"
        );
        $fetchStmt->bind_param('i', $classifiedId);
        $fetchStmt->execute();
        $classified = $this->formatClassified($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::created($classified, 'Classified posted successfully');
    }

    /**
     * PUT /marketplace/classifieds/{id}
     * Update own classified.
     */
    private function updateClassified($id) {
        $userId = $this->auth->getUserId();

        // Verify ownership
        $stmt = $this->conn->prepare(
            "SELECT id, user_id FROM tbl_classified WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Classified not found');
        }

        $existing = $result->fetch_assoc();
        $stmt->close();

        if ((int)$existing['user_id'] !== $userId) {
            ApiResponse::forbidden('You can only update your own classifieds');
        }

        // Build dynamic update
        $fields = [];
        $params = [];
        $types = '';

        if (isset($this->input['type'])) {
            $type = sanitizeInput($this->input['type']);
            $allowedTypes = ['sell', 'buy', 'rent', 'free', 'lost', 'found'];
            if (!in_array($type, $allowedTypes)) {
                ApiResponse::error('Invalid type', 400);
            }
            $fields[] = 'type = ?';
            $params[] = $type;
            $types .= 's';
        }

        if (isset($this->input['category'])) {
            $fields[] = 'category = ?';
            $params[] = sanitizeInput($this->input['category']);
            $types .= 's';
        }

        if (isset($this->input['title'])) {
            $title = sanitizeInput($this->input['title']);
            if (empty($title)) {
                ApiResponse::error('Title cannot be empty', 400);
            }
            $fields[] = 'title = ?';
            $params[] = $title;
            $types .= 's';
        }

        if (isset($this->input['description'])) {
            $fields[] = 'description = ?';
            $params[] = sanitizeInput($this->input['description']);
            $types .= 's';
        }

        if (isset($this->input['price'])) {
            $fields[] = 'price = ?';
            $params[] = (float)$this->input['price'];
            $types .= 'd';
        }

        if (isset($this->input['contact_phone'])) {
            $fields[] = 'contact_phone = ?';
            $params[] = sanitizeInput($this->input['contact_phone']);
            $types .= 's';
        }

        // Handle image uploads
        if (isset($_FILES['images'])) {
            $images = [];
            $files = $_FILES['images'];

            if (is_array($files['name'])) {
                $fileCount = count($files['name']);
                for ($i = 0; $i < $fileCount; $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $singleFile = [
                            'name' => $files['name'][$i],
                            'type' => $files['type'][$i],
                            'tmp_name' => $files['tmp_name'][$i],
                            'error' => $files['error'][$i],
                            'size' => $files['size'][$i],
                        ];
                        $upload = uploadFile($singleFile, 'classifieds', ['jpg', 'jpeg', 'png', 'webp']);
                        if (isset($upload['error'])) {
                            ApiResponse::error($upload['error'], 400);
                        }
                        $images[] = $upload['path'];
                    }
                }
            } else {
                if ($files['error'] === UPLOAD_ERR_OK) {
                    $upload = uploadFile($files, 'classifieds', ['jpg', 'jpeg', 'png', 'webp']);
                    if (isset($upload['error'])) {
                        ApiResponse::error($upload['error'], 400);
                    }
                    $images[] = $upload['path'];
                }
            }

            if (!empty($images)) {
                $fields[] = 'images_json = ?';
                $params[] = json_encode($images);
                $types .= 's';
            }
        }

        if (empty($fields)) {
            ApiResponse::error('No fields to update', 400);
        }

        $sql = "UPDATE tbl_classified SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $updateStmt = $this->conn->prepare($sql);
        $updateStmt->bind_param($types, ...$params);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to update classified', 500);
        }
        $updateStmt->close();

        // Fetch updated classified
        $fetchStmt = $this->conn->prepare(
            "SELECT c.id, c.society_id, c.user_id, c.type, c.category, c.title,
                    c.description, c.price, c.images_json, c.contact_phone,
                    c.status, c.expires_at, c.created_at,
                    u.name as user_name, u.avatar as user_avatar
             FROM tbl_classified c
             LEFT JOIN tbl_user u ON u.id = c.user_id
             WHERE c.id = ?"
        );
        $fetchStmt->bind_param('i', $id);
        $fetchStmt->execute();
        $classified = $this->formatClassified($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::success($classified, 'Classified updated successfully');
    }

    /**
     * PUT /marketplace/classifieds/{id}/mark-sold
     * Mark classified as sold. Owner only.
     */
    private function markClassifiedSold($id) {
        $userId = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "SELECT id, user_id, status FROM tbl_classified WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Classified not found');
        }

        $existing = $result->fetch_assoc();
        $stmt->close();

        if ((int)$existing['user_id'] !== $userId) {
            ApiResponse::forbidden('Only the owner can mark a classified as sold');
        }

        if ($existing['status'] !== 'active') {
            ApiResponse::error('Only active classifieds can be marked as sold', 400);
        }

        $updateStmt = $this->conn->prepare(
            "UPDATE tbl_classified SET status = 'sold' WHERE id = ?"
        );
        $updateStmt->bind_param('i', $id);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to update classified', 500);
        }
        $updateStmt->close();

        ApiResponse::success(null, 'Classified marked as sold');
    }

    /**
     * DELETE /marketplace/classifieds/{id}
     * Remove a classified. Owner or admin (primary).
     */
    private function deleteClassified($id) {
        $userId = $this->auth->getUserId();
        $isPrimary = $this->user['is_primary'];

        $stmt = $this->conn->prepare(
            "SELECT id, user_id FROM tbl_classified WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Classified not found');
        }

        $existing = $result->fetch_assoc();
        $stmt->close();

        if ((int)$existing['user_id'] !== $userId && !$isPrimary) {
            ApiResponse::forbidden('You can only remove your own classifieds');
        }

        $updateStmt = $this->conn->prepare(
            "UPDATE tbl_classified SET status = 'removed' WHERE id = ?"
        );
        $updateStmt->bind_param('i', $id);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to remove classified', 500);
        }
        $updateStmt->close();

        ApiResponse::success(null, 'Classified removed successfully');
    }

    // ─── Service Providers ──────────────────────────────────────────

    /**
     * GET /marketplace/services
     * List service providers. Paginated, filterable by service_category, sorted by avg_rating.
     */
    private function listServiceProviders() {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $where = "sp.society_id = ? AND sp.status = 'active'";
        $params = [$this->societyId];
        $types = 'i';

        // Filter by service_category
        if (!empty($this->input['service_category'])) {
            $serviceCategory = sanitizeInput($this->input['service_category']);
            $where .= " AND sp.service_category = ?";
            $params[] = $serviceCategory;
            $types .= 's';
        }

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM tbl_service_provider sp WHERE $where";
        $countStmt = $this->conn->prepare($countSql);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch providers sorted by avg_rating descending
        $sql = "SELECT sp.id, sp.society_id, sp.name, sp.service_category, sp.phone,
                       sp.description, sp.photo, sp.avg_rating, sp.total_reviews,
                       sp.is_verified, sp.added_by, sp.status, sp.created_at,
                       u.name as added_by_name
                FROM tbl_service_provider sp
                LEFT JOIN tbl_user u ON u.id = sp.added_by
                WHERE $where
                ORDER BY sp.avg_rating DESC, sp.total_reviews DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $providers = [];
        while ($row = $result->fetch_assoc()) {
            $providers[] = $this->formatServiceProvider($row);
        }
        $stmt->close();

        ApiResponse::paginated($providers, $total, $page, $perPage, 'Service providers retrieved successfully');
    }

    /**
     * GET /marketplace/services/{id}
     * Service provider detail with reviews.
     */
    private function getServiceProvider($id) {
        $stmt = $this->conn->prepare(
            "SELECT sp.id, sp.society_id, sp.name, sp.service_category, sp.phone,
                    sp.description, sp.photo, sp.avg_rating, sp.total_reviews,
                    sp.is_verified, sp.added_by, sp.status, sp.created_at,
                    u.name as added_by_name
             FROM tbl_service_provider sp
             LEFT JOIN tbl_user u ON u.id = sp.added_by
             WHERE sp.id = ? AND sp.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Service provider not found');
        }

        $provider = $this->formatServiceProvider($result->fetch_assoc());
        $stmt->close();

        // Fetch reviews for this provider
        $reviewStmt = $this->conn->prepare(
            "SELECT sr.id, sr.provider_id, sr.user_id, sr.rating, sr.review, sr.created_at,
                    u.name as user_name, u.avatar as user_avatar
             FROM tbl_service_review sr
             LEFT JOIN tbl_user u ON u.id = sr.user_id
             WHERE sr.provider_id = ?
             ORDER BY sr.created_at DESC"
        );
        $reviewStmt->bind_param('i', $id);
        $reviewStmt->execute();
        $reviewResult = $reviewStmt->get_result();

        $reviews = [];
        while ($row = $reviewResult->fetch_assoc()) {
            $reviews[] = [
                'id' => (int)$row['id'],
                'provider_id' => (int)$row['provider_id'],
                'user' => [
                    'id' => (int)$row['user_id'],
                    'name' => $row['user_name'],
                    'avatar' => $row['user_avatar'],
                ],
                'rating' => (int)$row['rating'],
                'review' => $row['review'],
                'created_at' => $row['created_at'],
            ];
        }
        $reviewStmt->close();

        $provider['reviews'] = $reviews;

        ApiResponse::success($provider, 'Service provider retrieved successfully');
    }

    /**
     * POST /marketplace/services
     * Add a service provider recommendation.
     */
    private function addServiceProvider() {
        $userId = $this->auth->getUserId();

        $name = sanitizeInput($this->input['name'] ?? '');
        $serviceCategory = sanitizeInput($this->input['service_category'] ?? '');
        $phone = sanitizeInput($this->input['phone'] ?? '');
        $description = sanitizeInput($this->input['description'] ?? '');

        // Validation
        if (empty($name)) {
            ApiResponse::error('Name is required', 400);
        }

        if (empty($serviceCategory)) {
            ApiResponse::error('Service category is required', 400);
        }

        $allowedCategories = [
            'maid', 'electrician', 'plumber', 'laundry', 'grocery', 'tiffin',
            'car_wash', 'doctor', 'carpenter', 'painter', 'pest_control',
            'ac_repair', 'other'
        ];
        if (!in_array($serviceCategory, $allowedCategories)) {
            ApiResponse::error('Invalid service category. Allowed: ' . implode(', ', $allowedCategories), 400);
        }

        if (empty($phone)) {
            ApiResponse::error('Phone number is required', 400);
        }

        // Handle photo upload
        $photo = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['photo'], 'services', ['jpg', 'jpeg', 'png', 'webp']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error'], 400);
            }
            $photo = $upload['path'];
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_service_provider (society_id, name, service_category, phone, description, photo, added_by, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'active')"
        );
        $stmt->bind_param('isssssi', $this->societyId, $name, $serviceCategory, $phone, $description, $photo, $userId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to add service provider', 500);
        }

        $providerId = $stmt->insert_id;
        $stmt->close();

        // Fetch created provider
        $fetchStmt = $this->conn->prepare(
            "SELECT sp.id, sp.society_id, sp.name, sp.service_category, sp.phone,
                    sp.description, sp.photo, sp.avg_rating, sp.total_reviews,
                    sp.is_verified, sp.added_by, sp.status, sp.created_at,
                    u.name as added_by_name
             FROM tbl_service_provider sp
             LEFT JOIN tbl_user u ON u.id = sp.added_by
             WHERE sp.id = ?"
        );
        $fetchStmt->bind_param('i', $providerId);
        $fetchStmt->execute();
        $provider = $this->formatServiceProvider($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::created($provider, 'Service provider added successfully');
    }

    /**
     * PUT /marketplace/services/{id}/verify
     * Verify a service provider. Admin (primary) only.
     */
    private function verifyServiceProvider($id) {
        $this->auth->requirePrimary();

        $stmt = $this->conn->prepare(
            "SELECT id, is_verified FROM tbl_service_provider WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Service provider not found');
        }

        $existing = $result->fetch_assoc();
        $stmt->close();

        if ((int)$existing['is_verified'] === 1) {
            ApiResponse::error('Service provider is already verified', 400);
        }

        $updateStmt = $this->conn->prepare(
            "UPDATE tbl_service_provider SET is_verified = 1 WHERE id = ?"
        );
        $updateStmt->bind_param('i', $id);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to verify service provider', 500);
        }
        $updateStmt->close();

        ApiResponse::success(null, 'Service provider verified successfully');
    }

    // ─── Reviews ────────────────────────────────────────────────────

    /**
     * POST /marketplace/reviews
     * Add a review for a service provider. One review per user per provider.
     * Updates avg_rating and total_reviews on the provider.
     */
    private function addReview() {
        $userId = $this->auth->getUserId();

        $providerId = isset($this->input['provider_id']) ? (int)$this->input['provider_id'] : 0;
        $rating = isset($this->input['rating']) ? (int)$this->input['rating'] : 0;
        $review = sanitizeInput($this->input['review'] ?? '');

        // Validation
        if (!$providerId) {
            ApiResponse::error('provider_id is required', 400);
        }

        if ($rating < 1 || $rating > 5) {
            ApiResponse::error('Rating must be between 1 and 5', 400);
        }

        // Verify provider exists in this society
        $providerStmt = $this->conn->prepare(
            "SELECT id FROM tbl_service_provider WHERE id = ? AND society_id = ? AND status = 'active'"
        );
        $providerStmt->bind_param('ii', $providerId, $this->societyId);
        $providerStmt->execute();

        if ($providerStmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Service provider not found');
        }
        $providerStmt->close();

        // Check for existing review (unique per user-provider)
        $checkStmt = $this->conn->prepare(
            "SELECT id FROM tbl_service_review WHERE provider_id = ? AND user_id = ?"
        );
        $checkStmt->bind_param('ii', $providerId, $userId);
        $checkStmt->execute();

        if ($checkStmt->get_result()->num_rows > 0) {
            ApiResponse::error('You have already reviewed this service provider', 409);
        }
        $checkStmt->close();

        // Insert review
        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_service_review (provider_id, user_id, rating, review)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param('iiis', $providerId, $userId, $rating, $review);

        if (!$stmt->execute()) {
            if ($this->conn->errno === 1062) {
                ApiResponse::error('You have already reviewed this service provider', 409);
            }
            ApiResponse::error('Failed to add review', 500);
        }

        $reviewId = $stmt->insert_id;
        $stmt->close();

        // Update avg_rating and total_reviews on the provider
        $updateStmt = $this->conn->prepare(
            "UPDATE tbl_service_provider
             SET avg_rating = (SELECT AVG(rating) FROM tbl_service_review WHERE provider_id = ?),
                 total_reviews = (SELECT COUNT(*) FROM tbl_service_review WHERE provider_id = ?)
             WHERE id = ?"
        );
        $updateStmt->bind_param('iii', $providerId, $providerId, $providerId);
        $updateStmt->execute();
        $updateStmt->close();

        // Fetch created review
        $fetchStmt = $this->conn->prepare(
            "SELECT sr.id, sr.provider_id, sr.user_id, sr.rating, sr.review, sr.created_at,
                    u.name as user_name, u.avatar as user_avatar
             FROM tbl_service_review sr
             LEFT JOIN tbl_user u ON u.id = sr.user_id
             WHERE sr.id = ?"
        );
        $fetchStmt->bind_param('i', $reviewId);
        $fetchStmt->execute();
        $row = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        $reviewData = [
            'id' => (int)$row['id'],
            'provider_id' => (int)$row['provider_id'],
            'user' => [
                'id' => (int)$row['user_id'],
                'name' => $row['user_name'],
                'avatar' => $row['user_avatar'],
            ],
            'rating' => (int)$row['rating'],
            'review' => $row['review'],
            'created_at' => $row['created_at'],
        ];

        ApiResponse::created($reviewData, 'Review added successfully');
    }

    // ─── Carpool ────────────────────────────────────────────────────

    /**
     * GET /marketplace/carpool
     * List carpool offers/requests. Paginated, filterable by type.
     */
    private function listCarpool() {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $where = "cp.society_id = ? AND cp.status = 'active'";
        $params = [$this->societyId];
        $types = 'i';

        // Filter by type
        if (!empty($this->input['type'])) {
            $type = sanitizeInput($this->input['type']);
            $allowedTypes = ['offer', 'request'];
            if (in_array($type, $allowedTypes)) {
                $where .= " AND cp.type = ?";
                $params[] = $type;
                $types .= 's';
            }
        }

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM tbl_carpool cp WHERE $where";
        $countStmt = $this->conn->prepare($countSql);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch carpool listings
        $sql = "SELECT cp.id, cp.society_id, cp.user_id, cp.type, cp.from_location,
                       cp.to_location, cp.departure_time, cp.days_json,
                       cp.seats_available, cp.vehicle_type, cp.notes,
                       cp.status, cp.created_at,
                       u.name as user_name, u.avatar as user_avatar
                FROM tbl_carpool cp
                LEFT JOIN tbl_user u ON u.id = cp.user_id
                WHERE $where
                ORDER BY cp.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $carpools = [];
        while ($row = $result->fetch_assoc()) {
            $carpools[] = $this->formatCarpool($row);
        }
        $stmt->close();

        ApiResponse::paginated($carpools, $total, $page, $perPage, 'Carpool listings retrieved successfully');
    }

    /**
     * POST /marketplace/carpool
     * Create a carpool offer or request.
     */
    private function createCarpool() {
        $userId = $this->auth->getUserId();

        $type = sanitizeInput($this->input['type'] ?? '');
        $fromLocation = sanitizeInput($this->input['from_location'] ?? '');
        $toLocation = sanitizeInput($this->input['to_location'] ?? '');
        $departureTime = sanitizeInput($this->input['departure_time'] ?? '');
        $daysJson = isset($this->input['days']) ? json_encode($this->input['days']) : null;
        $seatsAvailable = isset($this->input['seats_available']) ? (int)$this->input['seats_available'] : 1;
        $vehicleType = sanitizeInput($this->input['vehicle_type'] ?? '');
        $notes = sanitizeInput($this->input['notes'] ?? '');

        // Validation
        if (empty($type)) {
            ApiResponse::error('Type is required', 400);
        }

        $allowedTypes = ['offer', 'request'];
        if (!in_array($type, $allowedTypes)) {
            ApiResponse::error('Invalid type. Allowed: offer, request', 400);
        }

        if (empty($fromLocation)) {
            ApiResponse::error('From location is required', 400);
        }

        if (empty($toLocation)) {
            ApiResponse::error('To location is required', 400);
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_carpool (society_id, user_id, type, from_location, to_location, departure_time, days_json, seats_available, vehicle_type, notes, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')"
        );
        $stmt->bind_param(
            'iisssssisss',
            $this->societyId, $userId, $type, $fromLocation, $toLocation,
            $departureTime, $daysJson, $seatsAvailable, $vehicleType, $notes
        );

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to create carpool listing', 500);
        }

        $carpoolId = $stmt->insert_id;
        $stmt->close();

        // Fetch created carpool
        $fetchStmt = $this->conn->prepare(
            "SELECT cp.id, cp.society_id, cp.user_id, cp.type, cp.from_location,
                    cp.to_location, cp.departure_time, cp.days_json,
                    cp.seats_available, cp.vehicle_type, cp.notes,
                    cp.status, cp.created_at,
                    u.name as user_name, u.avatar as user_avatar
             FROM tbl_carpool cp
             LEFT JOIN tbl_user u ON u.id = cp.user_id
             WHERE cp.id = ?"
        );
        $fetchStmt->bind_param('i', $carpoolId);
        $fetchStmt->execute();
        $carpool = $this->formatCarpool($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::created($carpool, 'Carpool listing created successfully');
    }

    /**
     * PUT /marketplace/carpool/{id}
     * Update own carpool listing.
     */
    private function updateCarpool($id) {
        $userId = $this->auth->getUserId();

        // Verify ownership
        $stmt = $this->conn->prepare(
            "SELECT id, user_id FROM tbl_carpool WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Carpool listing not found');
        }

        $existing = $result->fetch_assoc();
        $stmt->close();

        if ((int)$existing['user_id'] !== $userId) {
            ApiResponse::forbidden('You can only update your own carpool listings');
        }

        // Build dynamic update
        $fields = [];
        $params = [];
        $types = '';

        if (isset($this->input['type'])) {
            $type = sanitizeInput($this->input['type']);
            $allowedTypes = ['offer', 'request'];
            if (!in_array($type, $allowedTypes)) {
                ApiResponse::error('Invalid type', 400);
            }
            $fields[] = 'type = ?';
            $params[] = $type;
            $types .= 's';
        }

        if (isset($this->input['from_location'])) {
            $fields[] = 'from_location = ?';
            $params[] = sanitizeInput($this->input['from_location']);
            $types .= 's';
        }

        if (isset($this->input['to_location'])) {
            $fields[] = 'to_location = ?';
            $params[] = sanitizeInput($this->input['to_location']);
            $types .= 's';
        }

        if (isset($this->input['departure_time'])) {
            $fields[] = 'departure_time = ?';
            $params[] = sanitizeInput($this->input['departure_time']);
            $types .= 's';
        }

        if (isset($this->input['days'])) {
            $fields[] = 'days_json = ?';
            $params[] = json_encode($this->input['days']);
            $types .= 's';
        }

        if (isset($this->input['seats_available'])) {
            $fields[] = 'seats_available = ?';
            $params[] = (int)$this->input['seats_available'];
            $types .= 'i';
        }

        if (isset($this->input['vehicle_type'])) {
            $fields[] = 'vehicle_type = ?';
            $params[] = sanitizeInput($this->input['vehicle_type']);
            $types .= 's';
        }

        if (isset($this->input['notes'])) {
            $fields[] = 'notes = ?';
            $params[] = sanitizeInput($this->input['notes']);
            $types .= 's';
        }

        if (isset($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $allowedStatuses = ['active', 'inactive', 'expired'];
            if (in_array($status, $allowedStatuses)) {
                $fields[] = 'status = ?';
                $params[] = $status;
                $types .= 's';
            }
        }

        if (empty($fields)) {
            ApiResponse::error('No fields to update', 400);
        }

        $sql = "UPDATE tbl_carpool SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $updateStmt = $this->conn->prepare($sql);
        $updateStmt->bind_param($types, ...$params);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to update carpool listing', 500);
        }
        $updateStmt->close();

        // Fetch updated carpool
        $fetchStmt = $this->conn->prepare(
            "SELECT cp.id, cp.society_id, cp.user_id, cp.type, cp.from_location,
                    cp.to_location, cp.departure_time, cp.days_json,
                    cp.seats_available, cp.vehicle_type, cp.notes,
                    cp.status, cp.created_at,
                    u.name as user_name, u.avatar as user_avatar
             FROM tbl_carpool cp
             LEFT JOIN tbl_user u ON u.id = cp.user_id
             WHERE cp.id = ?"
        );
        $fetchStmt->bind_param('i', $id);
        $fetchStmt->execute();
        $carpool = $this->formatCarpool($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::success($carpool, 'Carpool listing updated successfully');
    }

    /**
     * DELETE /marketplace/carpool/{id}
     * Remove own carpool listing.
     */
    private function deleteCarpool($id) {
        $userId = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "SELECT id, user_id FROM tbl_carpool WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Carpool listing not found');
        }

        $existing = $result->fetch_assoc();
        $stmt->close();

        if ((int)$existing['user_id'] !== $userId) {
            ApiResponse::forbidden('You can only remove your own carpool listings');
        }

        $deleteStmt = $this->conn->prepare(
            "DELETE FROM tbl_carpool WHERE id = ?"
        );
        $deleteStmt->bind_param('i', $id);

        if (!$deleteStmt->execute()) {
            ApiResponse::error('Failed to remove carpool listing', 500);
        }
        $deleteStmt->close();

        ApiResponse::success(null, 'Carpool listing removed successfully');
    }

    // ─── Formatters ─────────────────────────────────────────────────

    /**
     * Format a classified row for API output.
     */
    private function formatClassified($row) {
        return [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'type' => $row['type'],
            'category' => $row['category'],
            'title' => $row['title'],
            'description' => $row['description'],
            'price' => $row['price'] !== null ? (float)$row['price'] : null,
            'images' => $row['images_json'] ? json_decode($row['images_json'], true) : [],
            'contact_phone' => $row['contact_phone'],
            'status' => $row['status'],
            'expires_at' => $row['expires_at'],
            'created_at' => $row['created_at'],
            'posted_by' => [
                'id' => (int)$row['user_id'],
                'name' => $row['user_name'],
                'avatar' => $row['user_avatar'],
            ],
        ];
    }

    /**
     * Format a service provider row for API output.
     */
    private function formatServiceProvider($row) {
        return [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'name' => $row['name'],
            'service_category' => $row['service_category'],
            'phone' => $row['phone'],
            'description' => $row['description'],
            'photo' => $row['photo'],
            'avg_rating' => (float)$row['avg_rating'],
            'total_reviews' => (int)$row['total_reviews'],
            'is_verified' => (bool)$row['is_verified'],
            'added_by' => [
                'id' => (int)$row['added_by'],
                'name' => $row['added_by_name'],
            ],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * Format a carpool row for API output.
     */
    private function formatCarpool($row) {
        return [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'type' => $row['type'],
            'from_location' => $row['from_location'],
            'to_location' => $row['to_location'],
            'departure_time' => $row['departure_time'],
            'days' => $row['days_json'] ? json_decode($row['days_json'], true) : [],
            'seats_available' => (int)$row['seats_available'],
            'vehicle_type' => $row['vehicle_type'],
            'notes' => $row['notes'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'user' => [
                'id' => (int)$row['user_id'],
                'name' => $row['user_name'],
                'avatar' => $row['user_avatar'],
            ],
        ];
    }
}
