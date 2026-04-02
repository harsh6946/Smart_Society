<?php
/**
 * Securis Smart Society Platform — Tenant Handler
 * Manages tenant documents (upload, verify, delete) and rent payments (record, list, summary).
 */

require_once __DIR__ . '/../../../../include/helpers.php';
require_once __DIR__ . '/../../../../include/security.php';

class TenantHandler {
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

        if ($action === 'documents') {
            $this->handleDocuments($method, $id);
            return;
        }

        if ($action === 'rent') {
            $this->handleRent($method, $id);
            return;
        }

        ApiResponse::error('Invalid action', 400);
    }

    // ─── Document routing ───────────────────────────────────────────────

    private function handleDocuments($method, $id) {
        switch ($method) {
            case 'GET':
                $this->listDocuments();
                break;

            case 'POST':
                $this->uploadDocument();
                break;

            case 'PUT':
                if (!$id) {
                    ApiResponse::error('Document ID is required', 400);
                }
                // Check URI for /verify suffix
                $uri = $_SERVER['REQUEST_URI'] ?? '';
                if (strpos($uri, '/verify') !== false) {
                    $this->verifyDocument($id);
                } else {
                    ApiResponse::error('Invalid action', 400);
                }
                break;

            case 'DELETE':
                if (!$id) {
                    ApiResponse::error('Document ID is required', 400);
                }
                $this->deleteDocument($id);
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ─── Rent routing ───────────────────────────────────────────────────

    private function handleRent($method, $id) {
        switch ($method) {
            case 'GET':
                // Check URI for /summary suffix
                $uri = $_SERVER['REQUEST_URI'] ?? '';
                if (strpos($uri, '/summary') !== false) {
                    $this->rentSummary();
                } else {
                    $this->listRentPayments();
                }
                break;

            case 'POST':
                $this->recordRentPayment();
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ─── GET /api/v1/tenant/documents ───────────────────────────────────

    /**
     * List tenant documents. Admin (primary) sees all for the society,
     * tenant/resident sees only their own documents.
     * Optional filter: resident_id (admin only), document_type.
     */
    private function listDocuments() {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);
        $isPrimary = $this->user['is_primary'];
        $residentId = $this->auth->getResidentId();

        $where = "r.society_id = ?";
        $params = [$this->societyId];
        $types = 'i';

        // Non-primary users see only their own documents
        if (!$isPrimary) {
            $where .= " AND td.resident_id = ?";
            $params[] = $residentId;
            $types .= 'i';
        } elseif (!empty($this->input['resident_id'])) {
            $where .= " AND td.resident_id = ?";
            $params[] = (int)$this->input['resident_id'];
            $types .= 'i';
        }

        // Filter by document_type
        if (!empty($this->input['document_type'])) {
            $docType = sanitizeInput($this->input['document_type']);
            $where .= " AND td.document_type = ?";
            $params[] = $docType;
            $types .= 's';
        }

        // Count total
        $countSql = "SELECT COUNT(*) as total
                     FROM tbl_tenant_document td
                     JOIN tbl_resident r ON r.id = td.resident_id
                     WHERE $where";
        $countStmt = $this->conn->prepare($countSql);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch documents
        $sql = "SELECT td.id, td.resident_id, td.document_type, td.file_path,
                       td.expiry_date, td.is_verified, td.verified_by, td.created_at,
                       u.name as resident_name
                FROM tbl_tenant_document td
                JOIN tbl_resident r ON r.id = td.resident_id
                LEFT JOIN tbl_user u ON u.id = r.user_id
                WHERE $where
                ORDER BY td.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $documents = [];
        while ($row = $result->fetch_assoc()) {
            $documents[] = $this->formatDocument($row);
        }
        $stmt->close();

        ApiResponse::paginated($documents, $total, $page, $perPage, 'Tenant documents retrieved successfully');
    }

    // ─── POST /api/v1/tenant/documents ──────────────────────────────────

    /**
     * Upload a tenant document. Accepts multipart form with file, document_type, expiry_date.
     */
    private function uploadDocument() {
        $documentType = sanitizeInput($this->input['document_type'] ?? '');
        $expiryDate = sanitizeInput($this->input['expiry_date'] ?? '');
        $residentId = $this->auth->getResidentId();

        if (!$residentId) {
            ApiResponse::forbidden('You must be an approved resident to upload documents');
        }

        $allowedTypes = ['rent_agreement', 'police_verification', 'id_proof', 'address_proof', 'other'];
        if (empty($documentType) || !in_array($documentType, $allowedTypes)) {
            ApiResponse::error('Invalid document_type. Allowed: ' . implode(', ', $allowedTypes), 400);
        }

        // Handle file upload
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            ApiResponse::error('Document file is required', 400);
        }

        $upload = uploadFile($_FILES['file'], 'tenant_documents', ['jpg', 'jpeg', 'png', 'pdf', 'webp']);
        if (isset($upload['error'])) {
            ApiResponse::error($upload['error'], 400);
        }

        $filePath = $upload['path'];

        // Validate expiry_date format if provided
        $expiryDateVal = null;
        if (!empty($expiryDate)) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate)) {
                ApiResponse::error('Invalid expiry_date format. Use YYYY-MM-DD', 400);
            }
            $expiryDateVal = $expiryDate;
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_tenant_document (resident_id, document_type, file_path, expiry_date, is_verified, created_at)
             VALUES (?, ?, ?, ?, 0, NOW())"
        );
        $stmt->bind_param('isss', $residentId, $documentType, $filePath, $expiryDateVal);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to upload document', 500);
        }

        $docId = $stmt->insert_id;
        $stmt->close();

        // Fetch created document
        $fetchStmt = $this->conn->prepare(
            "SELECT td.id, td.resident_id, td.document_type, td.file_path,
                    td.expiry_date, td.is_verified, td.verified_by, td.created_at,
                    u.name as resident_name
             FROM tbl_tenant_document td
             JOIN tbl_resident r ON r.id = td.resident_id
             LEFT JOIN tbl_user u ON u.id = r.user_id
             WHERE td.id = ?"
        );
        $fetchStmt->bind_param('i', $docId);
        $fetchStmt->execute();
        $row = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        ApiResponse::created($this->formatDocument($row), 'Document uploaded successfully');
    }

    // ─── PUT /api/v1/tenant/documents/{id}/verify ───────────────────────

    /**
     * Verify a tenant document. Only primary owners (admin).
     */
    private function verifyDocument($id) {
        $this->auth->requirePrimary();

        // Verify document exists and belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT td.id, td.is_verified
             FROM tbl_tenant_document td
             JOIN tbl_resident r ON r.id = td.resident_id
             WHERE td.id = ? AND r.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Document not found');
        }

        $doc = $result->fetch_assoc();
        $stmt->close();

        if ((int)$doc['is_verified'] === 1) {
            ApiResponse::error('Document is already verified', 400);
        }

        $verifiedBy = $this->auth->getUserId();

        $updateStmt = $this->conn->prepare(
            "UPDATE tbl_tenant_document SET is_verified = 1, verified_by = ? WHERE id = ?"
        );
        $updateStmt->bind_param('ii', $verifiedBy, $id);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to verify document', 500);
        }
        $updateStmt->close();

        // Fetch updated document
        $fetchStmt = $this->conn->prepare(
            "SELECT td.id, td.resident_id, td.document_type, td.file_path,
                    td.expiry_date, td.is_verified, td.verified_by, td.created_at,
                    u.name as resident_name
             FROM tbl_tenant_document td
             JOIN tbl_resident r ON r.id = td.resident_id
             LEFT JOIN tbl_user u ON u.id = r.user_id
             WHERE td.id = ?"
        );
        $fetchStmt->bind_param('i', $id);
        $fetchStmt->execute();
        $row = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        ApiResponse::success($this->formatDocument($row), 'Document verified successfully');
    }

    // ─── DELETE /api/v1/tenant/documents/{id} ───────────────────────────

    /**
     * Delete a tenant document. Owner of the document or primary owner can delete.
     */
    private function deleteDocument($id) {
        $isPrimary = $this->user['is_primary'];
        $residentId = $this->auth->getResidentId();

        // Verify document exists and belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT td.id, td.resident_id, td.file_path
             FROM tbl_tenant_document td
             JOIN tbl_resident r ON r.id = td.resident_id
             WHERE td.id = ? AND r.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Document not found');
        }

        $doc = $result->fetch_assoc();
        $stmt->close();

        // Authorization: only document owner or primary
        if ((int)$doc['resident_id'] !== $residentId && !$isPrimary) {
            ApiResponse::forbidden('You can only delete your own documents');
        }

        $deleteStmt = $this->conn->prepare("DELETE FROM tbl_tenant_document WHERE id = ?");
        $deleteStmt->bind_param('i', $id);

        if (!$deleteStmt->execute()) {
            ApiResponse::error('Failed to delete document', 500);
        }
        $deleteStmt->close();

        ApiResponse::success(null, 'Document deleted successfully');
    }

    // ─── GET /api/v1/tenant/rent ────────────────────────────────────────

    /**
     * List rent payments. Tenant sees own, owner sees tenant payments linked to them,
     * admin (primary) sees all in society.
     * Filters: status, month, year, tenant_resident_id, owner_resident_id.
     */
    private function listRentPayments() {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);
        $isPrimary = $this->user['is_primary'];
        $residentId = $this->auth->getResidentId();

        $where = "tr.society_id = ?";
        $params = [$this->societyId];
        $types = 'i';

        // Non-primary users see payments where they are tenant or owner
        if (!$isPrimary) {
            $where .= " AND (rp.tenant_resident_id = ? OR rp.owner_resident_id = ?)";
            $params[] = $residentId;
            $params[] = $residentId;
            $types .= 'ii';
        }

        // Filter by status
        if (!empty($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $where .= " AND rp.status = ?";
            $params[] = $status;
            $types .= 's';
        }

        // Filter by month
        if (!empty($this->input['month'])) {
            $where .= " AND rp.month = ?";
            $params[] = (int)$this->input['month'];
            $types .= 'i';
        }

        // Filter by year
        if (!empty($this->input['year'])) {
            $where .= " AND rp.year = ?";
            $params[] = (int)$this->input['year'];
            $types .= 'i';
        }

        // Filter by tenant_resident_id (admin only)
        if ($isPrimary && !empty($this->input['tenant_resident_id'])) {
            $where .= " AND rp.tenant_resident_id = ?";
            $params[] = (int)$this->input['tenant_resident_id'];
            $types .= 'i';
        }

        // Filter by owner_resident_id (admin only)
        if ($isPrimary && !empty($this->input['owner_resident_id'])) {
            $where .= " AND rp.owner_resident_id = ?";
            $params[] = (int)$this->input['owner_resident_id'];
            $types .= 'i';
        }

        // Count total
        $countSql = "SELECT COUNT(*) as total
                     FROM tbl_rent_payment rp
                     JOIN tbl_resident tr ON tr.id = rp.tenant_resident_id
                     WHERE $where";
        $countStmt = $this->conn->prepare($countSql);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch payments
        $sql = "SELECT rp.id, rp.tenant_resident_id, rp.owner_resident_id,
                       rp.amount, rp.month, rp.year, rp.payment_mode,
                       rp.transaction_id, rp.status, rp.due_date, rp.paid_at, rp.created_at,
                       tu.name as tenant_name, ou.name as owner_name
                FROM tbl_rent_payment rp
                JOIN tbl_resident tr ON tr.id = rp.tenant_resident_id
                LEFT JOIN tbl_user tu ON tu.id = tr.user_id
                JOIN tbl_resident orr ON orr.id = rp.owner_resident_id
                LEFT JOIN tbl_user ou ON ou.id = orr.user_id
                WHERE $where
                ORDER BY rp.year DESC, rp.month DESC, rp.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $payments = [];
        while ($row = $result->fetch_assoc()) {
            $payments[] = $this->formatRentPayment($row);
        }
        $stmt->close();

        ApiResponse::paginated($payments, $total, $page, $perPage, 'Rent payments retrieved successfully');
    }

    // ─── POST /api/v1/tenant/rent ───────────────────────────────────────

    /**
     * Record a rent payment.
     */
    private function recordRentPayment() {
        $tenantResidentId = isset($this->input['tenant_resident_id']) ? (int)$this->input['tenant_resident_id'] : 0;
        $ownerResidentId = isset($this->input['owner_resident_id']) ? (int)$this->input['owner_resident_id'] : 0;
        $amount = isset($this->input['amount']) ? (float)$this->input['amount'] : 0;
        $month = isset($this->input['month']) ? (int)$this->input['month'] : 0;
        $year = isset($this->input['year']) ? (int)$this->input['year'] : 0;
        $paymentMode = sanitizeInput($this->input['payment_mode'] ?? 'upi');
        $transactionId = sanitizeInput($this->input['transaction_id'] ?? '');
        $dueDate = sanitizeInput($this->input['due_date'] ?? '');
        $status = sanitizeInput($this->input['status'] ?? 'pending');

        // Validation
        if (!$tenantResidentId) {
            ApiResponse::error('tenant_resident_id is required', 400);
        }
        if (!$ownerResidentId) {
            ApiResponse::error('owner_resident_id is required', 400);
        }
        if ($amount <= 0) {
            ApiResponse::error('Amount must be greater than 0', 400);
        }
        if ($month < 1 || $month > 12) {
            ApiResponse::error('Month must be between 1 and 12', 400);
        }
        if ($year < 2000 || $year > 2100) {
            ApiResponse::error('Invalid year', 400);
        }

        $allowedModes = ['cash', 'upi', 'bank_transfer', 'online'];
        if (!in_array($paymentMode, $allowedModes)) {
            ApiResponse::error('Invalid payment_mode. Allowed: ' . implode(', ', $allowedModes), 400);
        }

        $allowedStatuses = ['pending', 'paid', 'overdue'];
        if (!in_array($status, $allowedStatuses)) {
            ApiResponse::error('Invalid status. Allowed: ' . implode(', ', $allowedStatuses), 400);
        }

        // Verify tenant resident belongs to this society
        $checkStmt = $this->conn->prepare(
            "SELECT id FROM tbl_resident WHERE id = ? AND society_id = ?"
        );
        $checkStmt->bind_param('ii', $tenantResidentId, $this->societyId);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows === 0) {
            ApiResponse::error('Tenant resident not found in this society', 400);
        }
        $checkStmt->close();

        // Verify owner resident belongs to this society
        $checkStmt2 = $this->conn->prepare(
            "SELECT id FROM tbl_resident WHERE id = ? AND society_id = ?"
        );
        $checkStmt2->bind_param('ii', $ownerResidentId, $this->societyId);
        $checkStmt2->execute();
        if ($checkStmt2->get_result()->num_rows === 0) {
            ApiResponse::error('Owner resident not found in this society', 400);
        }
        $checkStmt2->close();

        // Check for duplicate entry (unique constraint on tenant + month + year)
        $dupStmt = $this->conn->prepare(
            "SELECT id FROM tbl_rent_payment WHERE tenant_resident_id = ? AND month = ? AND year = ?"
        );
        $dupStmt->bind_param('iii', $tenantResidentId, $month, $year);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) {
            ApiResponse::error('Rent payment for this tenant for the given month/year already exists', 409);
        }
        $dupStmt->close();

        $dueDateVal = !empty($dueDate) ? $dueDate : null;
        $paidAt = ($status === 'paid') ? date('Y-m-d H:i:s') : null;

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_rent_payment
                (tenant_resident_id, owner_resident_id, amount, month, year,
                 payment_mode, transaction_id, status, due_date, paid_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param(
            'iidiisssss',
            $tenantResidentId, $ownerResidentId, $amount, $month, $year,
            $paymentMode, $transactionId, $status, $dueDateVal, $paidAt
        );

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to record rent payment', 500);
        }

        $paymentId = $stmt->insert_id;
        $stmt->close();

        // Fetch created payment
        $fetchStmt = $this->conn->prepare(
            "SELECT rp.id, rp.tenant_resident_id, rp.owner_resident_id,
                    rp.amount, rp.month, rp.year, rp.payment_mode,
                    rp.transaction_id, rp.status, rp.due_date, rp.paid_at, rp.created_at,
                    tu.name as tenant_name, ou.name as owner_name
             FROM tbl_rent_payment rp
             JOIN tbl_resident tr ON tr.id = rp.tenant_resident_id
             LEFT JOIN tbl_user tu ON tu.id = tr.user_id
             JOIN tbl_resident orr ON orr.id = rp.owner_resident_id
             LEFT JOIN tbl_user ou ON ou.id = orr.user_id
             WHERE rp.id = ?"
        );
        $fetchStmt->bind_param('i', $paymentId);
        $fetchStmt->execute();
        $row = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        ApiResponse::created($this->formatRentPayment($row), 'Rent payment recorded successfully');
    }

    // ─── GET /api/v1/tenant/rent/summary ────────────────────────────────

    /**
     * Rent collection summary for an owner. Shows total collected, total pending,
     * and breakdown by month. Filterable by year.
     */
    private function rentSummary() {
        $residentId = $this->auth->getResidentId();
        $isPrimary = $this->user['is_primary'];
        $year = isset($this->input['year']) ? (int)$this->input['year'] : (int)date('Y');

        $where = "tr.society_id = ? AND rp.year = ?";
        $params = [$this->societyId, $year];
        $types = 'ii';

        // Non-primary users see only payments where they are the owner
        if (!$isPrimary) {
            $where .= " AND rp.owner_resident_id = ?";
            $params[] = $residentId;
            $types .= 'i';
        } elseif (!empty($this->input['owner_resident_id'])) {
            $where .= " AND rp.owner_resident_id = ?";
            $params[] = (int)$this->input['owner_resident_id'];
            $types .= 'i';
        }

        // Total collected
        $collectedSql = "SELECT COALESCE(SUM(rp.amount), 0) as total_collected
                         FROM tbl_rent_payment rp
                         JOIN tbl_resident tr ON tr.id = rp.tenant_resident_id
                         WHERE $where AND rp.status = 'paid'";
        $collectedStmt = $this->conn->prepare($collectedSql);
        $collectedStmt->bind_param($types, ...$params);
        $collectedStmt->execute();
        $totalCollected = (float)$collectedStmt->get_result()->fetch_assoc()['total_collected'];
        $collectedStmt->close();

        // Total pending
        $pendingSql = "SELECT COALESCE(SUM(rp.amount), 0) as total_pending
                       FROM tbl_rent_payment rp
                       JOIN tbl_resident tr ON tr.id = rp.tenant_resident_id
                       WHERE $where AND rp.status IN ('pending', 'overdue')";
        $pendingStmt = $this->conn->prepare($pendingSql);
        $pendingStmt->bind_param($types, ...$params);
        $pendingStmt->execute();
        $totalPending = (float)$pendingStmt->get_result()->fetch_assoc()['total_pending'];
        $pendingStmt->close();

        // Monthly breakdown
        $monthlySql = "SELECT rp.month,
                              COUNT(*) as total_entries,
                              SUM(CASE WHEN rp.status = 'paid' THEN rp.amount ELSE 0 END) as collected,
                              SUM(CASE WHEN rp.status IN ('pending', 'overdue') THEN rp.amount ELSE 0 END) as pending
                       FROM tbl_rent_payment rp
                       JOIN tbl_resident tr ON tr.id = rp.tenant_resident_id
                       WHERE $where
                       GROUP BY rp.month
                       ORDER BY rp.month ASC";
        $monthlyStmt = $this->conn->prepare($monthlySql);
        $monthlyStmt->bind_param($types, ...$params);
        $monthlyStmt->execute();
        $monthlyResult = $monthlyStmt->get_result();

        $monthly = [];
        while ($row = $monthlyResult->fetch_assoc()) {
            $monthly[] = [
                'month' => (int)$row['month'],
                'total_entries' => (int)$row['total_entries'],
                'collected' => (float)$row['collected'],
                'pending' => (float)$row['pending'],
            ];
        }
        $monthlyStmt->close();

        ApiResponse::success([
            'year' => $year,
            'total_collected' => $totalCollected,
            'total_pending' => $totalPending,
            'monthly' => $monthly,
        ], 'Rent summary retrieved successfully');
    }

    // ─── Formatters ─────────────────────────────────────────────────────

    private function formatDocument($row) {
        return [
            'id' => (int)$row['id'],
            'resident_id' => (int)$row['resident_id'],
            'resident_name' => $row['resident_name'] ?? null,
            'document_type' => $row['document_type'],
            'file_path' => $row['file_path'],
            'expiry_date' => $row['expiry_date'],
            'is_verified' => (bool)$row['is_verified'],
            'verified_by' => $row['verified_by'] ? (int)$row['verified_by'] : null,
            'created_at' => $row['created_at'],
        ];
    }

    private function formatRentPayment($row) {
        return [
            'id' => (int)$row['id'],
            'tenant_resident_id' => (int)$row['tenant_resident_id'],
            'tenant_name' => $row['tenant_name'] ?? null,
            'owner_resident_id' => (int)$row['owner_resident_id'],
            'owner_name' => $row['owner_name'] ?? null,
            'amount' => (float)$row['amount'],
            'month' => (int)$row['month'],
            'year' => (int)$row['year'],
            'payment_mode' => $row['payment_mode'],
            'transaction_id' => $row['transaction_id'],
            'status' => $row['status'],
            'due_date' => $row['due_date'],
            'paid_at' => $row['paid_at'],
            'created_at' => $row['created_at'],
        ];
    }
}
