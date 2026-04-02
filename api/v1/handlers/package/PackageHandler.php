<?php
/**
 * Securis Smart Society Platform — Package Tracking Handler
 * Manages package logging, notifications, collection, and returns.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class PackageHandler {
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
                    // GET /packages/{id}
                    $this->getPackage($id);
                } else {
                    // GET /packages
                    $this->listPackages();
                }
                break;

            case 'POST':
                // POST /packages
                $this->createPackage();
                break;

            case 'PUT':
                if (!$id) {
                    ApiResponse::error('Package ID is required', 400);
                }
                if ($action === 'notify') {
                    // PUT /packages/{id}/notify
                    $this->notifyPackage($id);
                } elseif ($action === 'collect') {
                    // PUT /packages/{id}/collect
                    $this->collectPackage($id);
                } elseif ($action === 'return') {
                    // PUT /packages/{id}/return
                    $this->returnPackage($id);
                } else {
                    ApiResponse::error('Invalid action', 400);
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ---------------------------------------------------------------
    //  GET /packages
    // ---------------------------------------------------------------
    private function listPackages() {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $isGuard = $this->auth->isGuard();
        $flatId = $this->auth->getFlatId();

        $where = "p.society_id = ?";
        $params = [$this->societyId];
        $types = 'i';

        if ($isGuard) {
            // Guards see all packages; default to pending (received/notified)
            if (empty($this->input['status'])) {
                $where .= " AND p.status IN ('received', 'notified')";
            }
        } else {
            // Residents see only their flat's packages
            $where .= " AND p.flat_id = ?";
            $params[] = $flatId;
            $types .= 'i';
        }

        // Filter by status
        if (!empty($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $allowedStatuses = ['received', 'notified', 'collected', 'returned'];
            if (in_array($status, $allowedStatuses)) {
                $where .= " AND p.status = ?";
                $params[] = $status;
                $types .= 's';
            }
        }

        // Count total
        $countStmt = $this->conn->prepare("SELECT COUNT(*) AS total FROM tbl_package p WHERE $where");
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch packages
        $sql = "SELECT p.id, p.society_id, p.flat_id, p.courier_name, p.tracking_number,
                       p.description, p.photo, p.received_by_guard, p.collected_by,
                       p.status, p.received_at, p.collected_at,
                       f.flat_number, t.name AS tower_name,
                       guard.name AS guard_name,
                       collector.name AS collected_by_name
                FROM tbl_package p
                LEFT JOIN tbl_flat f ON f.id = p.flat_id
                LEFT JOIN tbl_tower t ON t.id = f.tower_id
                LEFT JOIN tbl_user guard ON guard.id = p.received_by_guard
                LEFT JOIN tbl_user collector ON collector.id = p.collected_by
                WHERE $where
                ORDER BY p.received_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $packages = [];
        while ($row = $result->fetch_assoc()) {
            $packages[] = $this->formatPackage($row);
        }
        $stmt->close();

        ApiResponse::paginated($packages, $total, $page, $perPage, 'Packages retrieved successfully');
    }

    // ---------------------------------------------------------------
    //  GET /packages/{id}
    // ---------------------------------------------------------------
    private function getPackage($id) {
        $stmt = $this->conn->prepare(
            "SELECT p.id, p.society_id, p.flat_id, p.courier_name, p.tracking_number,
                    p.description, p.photo, p.received_by_guard, p.collected_by,
                    p.status, p.received_at, p.collected_at,
                    f.flat_number, t.name AS tower_name,
                    guard.name AS guard_name,
                    collector.name AS collected_by_name
             FROM tbl_package p
             LEFT JOIN tbl_flat f ON f.id = p.flat_id
             LEFT JOIN tbl_tower t ON t.id = f.tower_id
             LEFT JOIN tbl_user guard ON guard.id = p.received_by_guard
             LEFT JOIN tbl_user collector ON collector.id = p.collected_by
             WHERE p.id = ? AND p.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Package not found');
        }

        $package = $this->formatPackage($result->fetch_assoc());
        $stmt->close();

        ApiResponse::success($package, 'Package retrieved successfully');
    }

    // ---------------------------------------------------------------
    //  POST /packages
    // ---------------------------------------------------------------
    private function createPackage() {
        if (!$this->auth->isGuard()) {
            ApiResponse::forbidden('Only guards can log packages');
        }

        $flatId = isset($this->input['flat_id']) ? (int)$this->input['flat_id'] : 0;
        $courierName = sanitizeInput($this->input['courier_name'] ?? '');
        $trackingNumber = sanitizeInput($this->input['tracking_number'] ?? '');
        $description = sanitizeInput($this->input['description'] ?? '');

        if ($flatId <= 0) {
            ApiResponse::error('Valid flat_id is required', 400);
        }

        // Verify flat belongs to this society
        $flatStmt = $this->conn->prepare(
            "SELECT f.id FROM tbl_flat f
             INNER JOIN tbl_tower t ON t.id = f.tower_id
             WHERE f.id = ? AND t.society_id = ?"
        );
        $flatStmt->bind_param('ii', $flatId, $this->societyId);
        $flatStmt->execute();
        if ($flatStmt->get_result()->num_rows === 0) {
            ApiResponse::error('Flat does not belong to this society', 400);
        }
        $flatStmt->close();

        // Handle photo upload
        $photo = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['photo'], 'packages', ['jpg', 'jpeg', 'png', 'webp']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error'], 400);
            }
            $photo = $upload['path'];
        }

        $guardId = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_package (society_id, flat_id, courier_name, tracking_number, description, photo, received_by_guard, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'received')"
        );
        $stmt->bind_param('iissssi', $this->societyId, $flatId, $courierName, $trackingNumber, $description, $photo, $guardId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to log package', 500);
        }

        $packageId = $stmt->insert_id;
        $stmt->close();

        // Notify flat residents
        $this->notifyFlatResidents(
            $flatId,
            'New Package Received',
            'A package' . (!empty($courierName) ? ' from ' . $courierName : '') . ' has been received at the gate.',
            'package',
            $packageId
        );

        // Fetch created package
        $fetchStmt = $this->conn->prepare(
            "SELECT p.id, p.society_id, p.flat_id, p.courier_name, p.tracking_number,
                    p.description, p.photo, p.received_by_guard, p.collected_by,
                    p.status, p.received_at, p.collected_at,
                    f.flat_number, t.name AS tower_name,
                    guard.name AS guard_name
             FROM tbl_package p
             LEFT JOIN tbl_flat f ON f.id = p.flat_id
             LEFT JOIN tbl_tower t ON t.id = f.tower_id
             LEFT JOIN tbl_user guard ON guard.id = p.received_by_guard
             WHERE p.id = ?"
        );
        $fetchStmt->bind_param('i', $packageId);
        $fetchStmt->execute();
        $package = $this->formatPackage($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::created($package, 'Package logged successfully');
    }

    // ---------------------------------------------------------------
    //  PUT /packages/{id}/notify
    // ---------------------------------------------------------------
    private function notifyPackage($id) {
        if (!$this->auth->isGuard()) {
            ApiResponse::forbidden('Only guards can send package notifications');
        }

        $stmt = $this->conn->prepare(
            "SELECT id, flat_id, courier_name, status FROM tbl_package WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Package not found');
        }

        $package = $result->fetch_assoc();
        $stmt->close();

        if ($package['status'] === 'collected') {
            ApiResponse::error('Package has already been collected', 400);
        }
        if ($package['status'] === 'returned') {
            ApiResponse::error('Package has already been returned', 400);
        }

        // Update status to notified
        $updateStmt = $this->conn->prepare(
            "UPDATE tbl_package SET status = 'notified' WHERE id = ?"
        );
        $updateStmt->bind_param('i', $id);
        $updateStmt->execute();
        $updateStmt->close();

        // Send notification
        $this->notifyFlatResidents(
            (int)$package['flat_id'],
            'Package Awaiting Collection',
            'You have a package' . (!empty($package['courier_name']) ? ' from ' . $package['courier_name'] : '') . ' waiting at the gate. Please collect it.',
            'package',
            $id
        );

        ApiResponse::success(['id' => (int)$id, 'status' => 'notified'], 'Notification sent successfully');
    }

    // ---------------------------------------------------------------
    //  PUT /packages/{id}/collect
    // ---------------------------------------------------------------
    private function collectPackage($id) {
        $isGuard = $this->auth->isGuard();
        $flatId = $this->auth->getFlatId();
        $userId = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "SELECT id, flat_id, status FROM tbl_package WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Package not found');
        }

        $package = $result->fetch_assoc();
        $stmt->close();

        if ($package['status'] === 'collected') {
            ApiResponse::error('Package has already been collected', 400);
        }
        if ($package['status'] === 'returned') {
            ApiResponse::error('Package has already been returned', 400);
        }

        // Authorization: guard or resident of the flat
        if (!$isGuard && (int)$package['flat_id'] !== $flatId) {
            ApiResponse::forbidden('You can only collect packages for your flat');
        }

        $updateStmt = $this->conn->prepare(
            "UPDATE tbl_package SET status = 'collected', collected_by = ?, collected_at = NOW() WHERE id = ?"
        );
        $updateStmt->bind_param('ii', $userId, $id);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to mark package as collected', 500);
        }
        $updateStmt->close();

        ApiResponse::success([
            'id' => (int)$id,
            'status' => 'collected',
            'collected_by' => $userId,
            'collected_at' => date('Y-m-d H:i:s'),
        ], 'Package marked as collected');
    }

    // ---------------------------------------------------------------
    //  PUT /packages/{id}/return
    // ---------------------------------------------------------------
    private function returnPackage($id) {
        if (!$this->auth->isGuard()) {
            ApiResponse::forbidden('Only guards can mark packages as returned');
        }

        $stmt = $this->conn->prepare(
            "SELECT id, status FROM tbl_package WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Package not found');
        }

        $package = $result->fetch_assoc();
        $stmt->close();

        if ($package['status'] === 'collected') {
            ApiResponse::error('Package has already been collected and cannot be returned', 400);
        }
        if ($package['status'] === 'returned') {
            ApiResponse::error('Package has already been returned', 400);
        }

        $updateStmt = $this->conn->prepare(
            "UPDATE tbl_package SET status = 'returned' WHERE id = ?"
        );
        $updateStmt->bind_param('i', $id);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to mark package as returned', 500);
        }
        $updateStmt->close();

        ApiResponse::success(['id' => (int)$id, 'status' => 'returned'], 'Package marked as returned');
    }

    // ---------------------------------------------------------------
    //  Helpers
    // ---------------------------------------------------------------
    private function notifyFlatResidents($flatId, $title, $body, $refType, $refId) {
        $stmt = $this->conn->prepare(
            "SELECT user_id FROM tbl_resident WHERE society_id = ? AND flat_id = ? AND status = 'approved'"
        );
        $stmt->bind_param('ii', $this->societyId, $flatId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            storeNotification($this->conn, $this->societyId, (int)$row['user_id'], $title, $body, 'package', $refType, $refId);
        }
        $stmt->close();
    }

    private function formatPackage($row) {
        return [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'flat_id' => (int)$row['flat_id'],
            'flat_number' => $row['flat_number'] ?? null,
            'tower_name' => $row['tower_name'] ?? null,
            'courier_name' => $row['courier_name'],
            'tracking_number' => $row['tracking_number'],
            'description' => $row['description'],
            'photo' => $row['photo'],
            'received_by_guard' => $row['received_by_guard'] ? (int)$row['received_by_guard'] : null,
            'guard_name' => $row['guard_name'] ?? null,
            'collected_by' => $row['collected_by'] ? (int)$row['collected_by'] : null,
            'collected_by_name' => $row['collected_by_name'] ?? null,
            'status' => $row['status'],
            'received_at' => $row['received_at'],
            'collected_at' => $row['collected_at'],
        ];
    }
}
