<?php
/**
 * Securis Smart Society Platform — Maintenance & Billing Handler
 * Handles maintenance head management, bill generation, payments,
 * receipts, and society billing summaries.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class MaintenanceHandler {
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
                    case 'bills':
                        if ($id) {
                            $this->getBillDetail($id);
                        } else {
                            $this->listBills();
                        }
                        break;
                    case 'receipts':
                        $this->listReceipts();
                        break;
                    case 'heads':
                        $this->listHeads();
                        break;
                    case 'summary':
                        $this->getSummary();
                        break;
                    default:
                        ApiResponse::notFound('Maintenance endpoint not found');
                }
                break;

            case 'POST':
                switch ($action) {
                    case 'generate':
                        $this->generateBills();
                        break;
                    case 'pay':
                        if ($id) {
                            $this->recordPayment($id);
                        } else {
                            ApiResponse::error('Bill ID is required');
                        }
                        break;
                    case 'heads':
                        $this->createHead();
                        break;
                    default:
                        ApiResponse::notFound('Maintenance endpoint not found');
                }
                break;

            case 'PUT':
                switch ($action) {
                    case 'heads':
                        if ($id) {
                            $this->updateHead($id);
                        } else {
                            ApiResponse::error('Head ID is required');
                        }
                        break;
                    default:
                        ApiResponse::notFound('Maintenance endpoint not found');
                }
                break;

            case 'DELETE':
                switch ($action) {
                    case 'heads':
                        if ($id) {
                            $this->deactivateHead($id);
                        } else {
                            ApiResponse::error('Head ID is required');
                        }
                        break;
                    default:
                        ApiResponse::notFound('Maintenance endpoint not found');
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // =========================================================================
    // Bills
    // =========================================================================

    /**
     * GET /maintenance/bills
     * List bills. Residents see their flat's bills; admins see all society bills.
     * Filters: status, month, year. Paginated.
     */
    private function listBills() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $user = $this->auth->getUser();

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $status = sanitizeInput($this->input['status'] ?? '');
        $month = intval($this->input['month'] ?? 0);
        $year = intval($this->input['year'] ?? 0);

        $isPrimary = !empty($user['is_primary']);

        // Build WHERE clauses
        $where = "WHERE b.society_id = ?";
        $params = [$societyId];
        $types = 'i';

        // Residents only see their own flat's bills
        if (!$isPrimary) {
            $flatId = $this->auth->getFlatId();
            if (!$flatId) {
                ApiResponse::error('No flat assigned to your account');
            }
            $where .= " AND b.flat_id = ?";
            $params[] = $flatId;
            $types .= 'i';
        }

        if (!empty($status)) {
            $where .= " AND b.status = ?";
            $params[] = $status;
            $types .= 's';
        }

        if ($month > 0) {
            $where .= " AND b.month = ?";
            $params[] = $month;
            $types .= 'i';
        }

        if ($year > 0) {
            $where .= " AND b.year = ?";
            $params[] = $year;
            $types .= 'i';
        }

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM tbl_maintenance_bill b $where";
        $stmt = $this->conn->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        // Fetch paginated bills with flat info
        $sql = "SELECT b.id, b.society_id, b.flat_id, b.month, b.year, b.total_amount,
                       b.due_date, b.penalty_amount, b.status, b.generated_at, b.paid_at,
                       f.flat_number, t.name as tower_name
                FROM tbl_maintenance_bill b
                JOIN tbl_flat f ON f.id = b.flat_id
                JOIN tbl_tower t ON t.id = f.tower_id
                $where
                ORDER BY b.year DESC, b.month DESC, t.name ASC, f.flat_number ASC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $bills = [];
        while ($row = $result->fetch_assoc()) {
            $bills[] = [
                'id' => (int)$row['id'],
                'society_id' => (int)$row['society_id'],
                'flat_id' => (int)$row['flat_id'],
                'flat_number' => $row['flat_number'],
                'tower_name' => $row['tower_name'],
                'month' => (int)$row['month'],
                'year' => (int)$row['year'],
                'total_amount' => (float)$row['total_amount'],
                'due_date' => $row['due_date'],
                'penalty_amount' => (float)$row['penalty_amount'],
                'status' => $row['status'],
                'generated_at' => $row['generated_at'],
                'paid_at' => $row['paid_at'],
            ];
        }

        ApiResponse::paginated($bills, $total, $page, $perPage, 'Bills retrieved successfully');
    }

    /**
     * GET /maintenance/bills/{id}
     * Bill detail with line items joined with maintenance heads.
     */
    private function getBillDetail($id) {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $user = $this->auth->getUser();

        // Fetch bill
        $stmt = $this->conn->prepare(
            "SELECT b.id, b.society_id, b.flat_id, b.month, b.year, b.total_amount,
                    b.due_date, b.penalty_amount, b.status, b.generated_at, b.paid_at,
                    f.flat_number, t.name as tower_name
             FROM tbl_maintenance_bill b
             JOIN tbl_flat f ON f.id = b.flat_id
             JOIN tbl_tower t ON t.id = f.tower_id
             WHERE b.id = ? AND b.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Bill not found');
        }

        $bill = $result->fetch_assoc();

        // Non-admin residents can only view their own flat's bills
        $isPrimary = !empty($user['is_primary']);
        if (!$isPrimary) {
            $flatId = $this->auth->getFlatId();
            if ((int)$bill['flat_id'] !== $flatId) {
                ApiResponse::forbidden('You can only view bills for your own flat');
            }
        }

        // Fetch line items with head info
        $stmt = $this->conn->prepare(
            "SELECT bi.id, bi.bill_id, bi.head_id, bi.amount, bi.description,
                    mh.name as head_name, mh.frequency
             FROM tbl_bill_item bi
             JOIN tbl_maintenance_head mh ON mh.id = bi.head_id
             WHERE bi.bill_id = ?
             ORDER BY mh.name ASC"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $itemResult = $stmt->get_result();

        $items = [];
        while ($row = $itemResult->fetch_assoc()) {
            $items[] = [
                'id' => (int)$row['id'],
                'bill_id' => (int)$row['bill_id'],
                'head_id' => (int)$row['head_id'],
                'head_name' => $row['head_name'],
                'frequency' => $row['frequency'],
                'amount' => (float)$row['amount'],
                'description' => $row['description'],
            ];
        }

        // Fetch payments for this bill
        $stmt = $this->conn->prepare(
            "SELECT p.id, p.amount, p.payment_mode, p.transaction_id,
                    p.payment_date, p.receipt_number, p.notes
             FROM tbl_payment p
             WHERE p.bill_id = ?
             ORDER BY p.payment_date DESC"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $paymentResult = $stmt->get_result();

        $payments = [];
        while ($row = $paymentResult->fetch_assoc()) {
            $payments[] = [
                'id' => (int)$row['id'],
                'amount' => (float)$row['amount'],
                'payment_mode' => $row['payment_mode'],
                'transaction_id' => $row['transaction_id'],
                'payment_date' => $row['payment_date'],
                'receipt_number' => $row['receipt_number'],
                'notes' => $row['notes'],
            ];
        }

        $billData = [
            'id' => (int)$bill['id'],
            'society_id' => (int)$bill['society_id'],
            'flat_id' => (int)$bill['flat_id'],
            'flat_number' => $bill['flat_number'],
            'tower_name' => $bill['tower_name'],
            'month' => (int)$bill['month'],
            'year' => (int)$bill['year'],
            'total_amount' => (float)$bill['total_amount'],
            'due_date' => $bill['due_date'],
            'penalty_amount' => (float)$bill['penalty_amount'],
            'status' => $bill['status'],
            'generated_at' => $bill['generated_at'],
            'paid_at' => $bill['paid_at'],
            'items' => $items,
            'payments' => $payments,
        ];

        ApiResponse::success($billData, 'Bill detail retrieved successfully');
    }

    // =========================================================================
    // Bill Generation
    // =========================================================================

    /**
     * POST /maintenance/generate
     * Generate bills for all occupied flats in society for a given month/year.
     * Creates tbl_maintenance_bill + tbl_bill_item for each active head.
     * Only primary owner/admin can generate. Skips if bill already exists.
     */
    private function generateBills() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $month = intval($this->input['month'] ?? 0);
        $year = intval($this->input['year'] ?? 0);

        if ($month < 1 || $month > 12) {
            ApiResponse::error('Valid month (1-12) is required');
        }

        if ($year < 2020 || $year > 2100) {
            ApiResponse::error('Valid year is required');
        }

        // Get all active maintenance heads for this society
        $stmt = $this->conn->prepare(
            "SELECT id, name, amount, frequency
             FROM tbl_maintenance_head
             WHERE society_id = ? AND is_active = 1"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $headResult = $stmt->get_result();

        $heads = [];
        while ($row = $headResult->fetch_assoc()) {
            $heads[] = $row;
        }

        if (empty($heads)) {
            ApiResponse::error('No active maintenance heads found. Please create maintenance heads first.');
        }

        // Calculate total amount from all heads
        $totalAmount = 0;
        foreach ($heads as $head) {
            $totalAmount += (float)$head['amount'];
        }

        // Get all occupied flats in society (flats that have at least one approved resident)
        $stmt = $this->conn->prepare(
            "SELECT DISTINCT f.id as flat_id
             FROM tbl_flat f
             JOIN tbl_tower tw ON tw.id = f.tower_id
             JOIN tbl_resident r ON r.flat_id = f.id AND r.status = 'approved'
             WHERE tw.society_id = ? AND f.status = 'occupied'"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $flatResult = $stmt->get_result();

        $flats = [];
        while ($row = $flatResult->fetch_assoc()) {
            $flats[] = (int)$row['flat_id'];
        }

        if (empty($flats)) {
            ApiResponse::error('No occupied flats found in this society');
        }

        // Due date: last day of the billing month
        $dueDate = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));

        $generated = 0;
        $skipped = 0;

        $this->conn->begin_transaction();

        try {
            foreach ($flats as $flatId) {
                // Check if bill already exists (unique key: flat_id, month, year)
                $stmt = $this->conn->prepare(
                    "SELECT id FROM tbl_maintenance_bill
                     WHERE flat_id = ? AND month = ? AND year = ?"
                );
                $stmt->bind_param('iii', $flatId, $month, $year);
                $stmt->execute();

                if ($stmt->get_result()->num_rows > 0) {
                    $skipped++;
                    continue;
                }

                // Create bill
                $stmt = $this->conn->prepare(
                    "INSERT INTO tbl_maintenance_bill
                     (society_id, flat_id, month, year, total_amount, due_date, penalty_amount, status, generated_at)
                     VALUES (?, ?, ?, ?, ?, ?, 0, 'pending', NOW())"
                );
                $stmt->bind_param('iiiids', $societyId, $flatId, $month, $year, $totalAmount, $dueDate);
                $stmt->execute();
                $billId = $this->conn->insert_id;

                // Create bill items for each head
                $stmtItem = $this->conn->prepare(
                    "INSERT INTO tbl_bill_item (bill_id, head_id, amount, description)
                     VALUES (?, ?, ?, ?)"
                );

                foreach ($heads as $head) {
                    $headId = (int)$head['id'];
                    $headAmount = (float)$head['amount'];
                    $description = $head['name'];
                    $stmtItem->bind_param('iids', $billId, $headId, $headAmount, $description);
                    $stmtItem->execute();
                }

                $generated++;
            }

            $this->conn->commit();
        } catch (Exception $e) {
            $this->conn->rollback();
            ApiResponse::error('Failed to generate bills: ' . $e->getMessage(), 500);
        }

        ApiResponse::created([
            'generated' => $generated,
            'skipped' => $skipped,
            'total_flats' => count($flats),
            'month' => $month,
            'year' => $year,
            'amount_per_flat' => $totalAmount,
        ], "Bills generated successfully. Created: $generated, Skipped (already exist): $skipped");
    }

    // =========================================================================
    // Payments
    // =========================================================================

    /**
     * POST /maintenance/pay/{id}
     * Record manual payment for a bill.
     * Input: amount, payment_mode, transaction_id, notes.
     * Creates tbl_payment, generates receipt_number.
     * Updates bill status to 'paid' if fully paid, 'partially_paid' otherwise.
     */
    private function recordPayment($billId) {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $user = $this->auth->getUser();

        $amount = floatval($this->input['amount'] ?? 0);
        $paymentMode = sanitizeInput($this->input['payment_mode'] ?? '');
        $transactionId = sanitizeInput($this->input['transaction_id'] ?? '');
        $notes = sanitizeInput($this->input['notes'] ?? '');

        if ($amount <= 0) {
            ApiResponse::error('Valid payment amount is required');
        }

        if (empty($paymentMode)) {
            ApiResponse::error('Payment mode is required');
        }

        // Fetch bill and verify it belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT b.id, b.flat_id, b.total_amount, b.penalty_amount, b.status
             FROM tbl_maintenance_bill b
             WHERE b.id = ? AND b.society_id = ?"
        );
        $stmt->bind_param('ii', $billId, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Bill not found');
        }

        $bill = $result->fetch_assoc();

        if ($bill['status'] === 'paid') {
            ApiResponse::error('This bill is already fully paid');
        }

        // Get resident_id for the current user
        $residentId = $this->auth->getResidentId();
        if (!$residentId) {
            ApiResponse::error('No resident record found for your account');
        }

        // Calculate total already paid
        $stmt = $this->conn->prepare(
            "SELECT COALESCE(SUM(amount), 0) as total_paid FROM tbl_payment WHERE bill_id = ?"
        );
        $stmt->bind_param('i', $billId);
        $stmt->execute();
        $totalPaid = (float)$stmt->get_result()->fetch_assoc()['total_paid'];

        $billTotal = (float)$bill['total_amount'] + (float)$bill['penalty_amount'];
        $remaining = $billTotal - $totalPaid;

        if ($amount > $remaining) {
            ApiResponse::error('Payment amount exceeds remaining balance of ' . formatCurrency($remaining));
        }

        $receiptNumber = generateReceiptNumber();
        $recordedBy = $this->auth->getUserId();

        $this->conn->begin_transaction();

        try {
            // Create payment record
            $stmt = $this->conn->prepare(
                "INSERT INTO tbl_payment
                 (bill_id, resident_id, amount, payment_mode, transaction_id, payment_date, receipt_number, recorded_by, notes)
                 VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?)"
            );
            $stmt->bind_param('iidsssis', $billId, $residentId, $amount, $paymentMode, $transactionId, $receiptNumber, $recordedBy, $notes);
            $stmt->execute();
            $paymentId = $this->conn->insert_id;

            // Determine new bill status
            $newTotalPaid = $totalPaid + $amount;
            if ($newTotalPaid >= $billTotal) {
                $newStatus = 'paid';
                $stmt = $this->conn->prepare(
                    "UPDATE tbl_maintenance_bill SET status = 'paid', paid_at = NOW() WHERE id = ?"
                );
            } else {
                $newStatus = 'partially_paid';
                $stmt = $this->conn->prepare(
                    "UPDATE tbl_maintenance_bill SET status = 'partially_paid' WHERE id = ?"
                );
            }
            $stmt->bind_param('i', $billId);
            $stmt->execute();

            $this->conn->commit();
        } catch (Exception $e) {
            $this->conn->rollback();
            ApiResponse::error('Failed to record payment: ' . $e->getMessage(), 500);
        }

        ApiResponse::created([
            'payment_id' => $paymentId,
            'receipt_number' => $receiptNumber,
            'amount' => $amount,
            'payment_mode' => $paymentMode,
            'bill_status' => $newStatus,
            'total_paid' => $newTotalPaid,
            'remaining' => $billTotal - $newTotalPaid,
        ], 'Payment recorded successfully');
    }

    // =========================================================================
    // Receipts
    // =========================================================================

    /**
     * GET /maintenance/receipts
     * Payment history for the authenticated resident. Paginated.
     */
    private function listReceipts() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $residentId = $this->auth->getResidentId();

        if (!$residentId) {
            ApiResponse::error('No resident record found for your account');
        }

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        // Count total
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) as total
             FROM tbl_payment p
             JOIN tbl_maintenance_bill b ON b.id = p.bill_id
             WHERE p.resident_id = ? AND b.society_id = ?"
        );
        $stmt->bind_param('ii', $residentId, $societyId);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        // Fetch paginated receipts
        $stmt = $this->conn->prepare(
            "SELECT p.id, p.bill_id, p.amount, p.payment_mode, p.transaction_id,
                    p.payment_date, p.receipt_number, p.notes,
                    b.month, b.year, b.total_amount as bill_amount,
                    f.flat_number, t.name as tower_name
             FROM tbl_payment p
             JOIN tbl_maintenance_bill b ON b.id = p.bill_id
             JOIN tbl_flat f ON f.id = b.flat_id
             JOIN tbl_tower t ON t.id = f.tower_id
             WHERE p.resident_id = ? AND b.society_id = ?
             ORDER BY p.payment_date DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('iiii', $residentId, $societyId, $perPage, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $receipts = [];
        while ($row = $result->fetch_assoc()) {
            $receipts[] = [
                'id' => (int)$row['id'],
                'bill_id' => (int)$row['bill_id'],
                'amount' => (float)$row['amount'],
                'payment_mode' => $row['payment_mode'],
                'transaction_id' => $row['transaction_id'],
                'payment_date' => $row['payment_date'],
                'receipt_number' => $row['receipt_number'],
                'notes' => $row['notes'],
                'bill_month' => (int)$row['month'],
                'bill_year' => (int)$row['year'],
                'bill_amount' => (float)$row['bill_amount'],
                'flat_number' => $row['flat_number'],
                'tower_name' => $row['tower_name'],
            ];
        }

        ApiResponse::paginated($receipts, $total, $page, $perPage, 'Receipts retrieved successfully');
    }

    // =========================================================================
    // Maintenance Heads
    // =========================================================================

    /**
     * GET /maintenance/heads
     * List maintenance heads for the society (admin only).
     */
    private function listHeads() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $stmt = $this->conn->prepare(
            "SELECT id, society_id, name, amount, frequency, is_active
             FROM tbl_maintenance_head
             WHERE society_id = ?
             ORDER BY is_active DESC, name ASC"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        $heads = [];
        while ($row = $result->fetch_assoc()) {
            $heads[] = [
                'id' => (int)$row['id'],
                'society_id' => (int)$row['society_id'],
                'name' => $row['name'],
                'amount' => (float)$row['amount'],
                'frequency' => $row['frequency'],
                'is_active' => (bool)$row['is_active'],
            ];
        }

        ApiResponse::success($heads, 'Maintenance heads retrieved successfully');
    }

    /**
     * POST /maintenance/heads
     * Create a new maintenance head (admin only).
     * Input: name, amount, frequency.
     */
    private function createHead() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $name = sanitizeInput($this->input['name'] ?? '');
        $amount = floatval($this->input['amount'] ?? 0);
        $frequency = sanitizeInput($this->input['frequency'] ?? 'monthly');

        if (empty($name)) {
            ApiResponse::error('Maintenance head name is required');
        }

        if ($amount <= 0) {
            ApiResponse::error('Valid amount is required');
        }

        $allowedFrequencies = ['monthly', 'quarterly', 'yearly', 'one-time'];
        if (!in_array($frequency, $allowedFrequencies)) {
            ApiResponse::error('Invalid frequency. Allowed: ' . implode(', ', $allowedFrequencies));
        }

        // Check for duplicate name in same society
        $stmt = $this->conn->prepare(
            "SELECT id FROM tbl_maintenance_head
             WHERE society_id = ? AND name = ? AND is_active = 1"
        );
        $stmt->bind_param('is', $societyId, $name);
        $stmt->execute();

        if ($stmt->get_result()->num_rows > 0) {
            ApiResponse::error('A maintenance head with this name already exists');
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_maintenance_head (society_id, name, amount, frequency, is_active)
             VALUES (?, ?, ?, ?, 1)"
        );
        $stmt->bind_param('isds', $societyId, $name, $amount, $frequency);
        $stmt->execute();
        $headId = $this->conn->insert_id;

        ApiResponse::created([
            'id' => $headId,
            'society_id' => $societyId,
            'name' => $name,
            'amount' => $amount,
            'frequency' => $frequency,
            'is_active' => true,
        ], 'Maintenance head created successfully');
    }

    /**
     * PUT /maintenance/heads/{id}
     * Update an existing maintenance head (admin only).
     */
    private function updateHead($id) {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        // Verify head exists and belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT id, name, amount, frequency, is_active
             FROM tbl_maintenance_head
             WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Maintenance head not found');
        }

        $head = $result->fetch_assoc();

        $name = sanitizeInput($this->input['name'] ?? $head['name']);
        $amount = floatval($this->input['amount'] ?? $head['amount']);
        $frequency = sanitizeInput($this->input['frequency'] ?? $head['frequency']);

        if (empty($name)) {
            ApiResponse::error('Maintenance head name is required');
        }

        if ($amount <= 0) {
            ApiResponse::error('Valid amount is required');
        }

        $allowedFrequencies = ['monthly', 'quarterly', 'yearly', 'one-time'];
        if (!in_array($frequency, $allowedFrequencies)) {
            ApiResponse::error('Invalid frequency. Allowed: ' . implode(', ', $allowedFrequencies));
        }

        // Check for duplicate name (excluding current head)
        $stmt = $this->conn->prepare(
            "SELECT id FROM tbl_maintenance_head
             WHERE society_id = ? AND name = ? AND is_active = 1 AND id != ?"
        );
        $stmt->bind_param('isi', $societyId, $name, $id);
        $stmt->execute();

        if ($stmt->get_result()->num_rows > 0) {
            ApiResponse::error('Another maintenance head with this name already exists');
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_maintenance_head SET name = ?, amount = ?, frequency = ? WHERE id = ?"
        );
        $stmt->bind_param('sdsi', $name, $amount, $frequency, $id);
        $stmt->execute();

        ApiResponse::success([
            'id' => (int)$id,
            'society_id' => $societyId,
            'name' => $name,
            'amount' => $amount,
            'frequency' => $frequency,
            'is_active' => (bool)$head['is_active'],
        ], 'Maintenance head updated successfully');
    }

    /**
     * DELETE /maintenance/heads/{id}
     * Deactivate a maintenance head (set is_active = 0). Admin only.
     */
    private function deactivateHead($id) {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        // Verify head exists and belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT id, is_active FROM tbl_maintenance_head
             WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Maintenance head not found');
        }

        $head = $result->fetch_assoc();

        if (!$head['is_active']) {
            ApiResponse::success(null, 'Maintenance head is already deactivated');
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_maintenance_head SET is_active = 0 WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();

        ApiResponse::success(null, 'Maintenance head deactivated successfully');
    }

    // =========================================================================
    // Summary
    // =========================================================================

    /**
     * GET /maintenance/summary
     * Society billing summary: total collected, total pending, overdue count.
     * Admin only.
     */
    private function getSummary() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        // Total collected (sum of all payments for this society's bills)
        $stmt = $this->conn->prepare(
            "SELECT COALESCE(SUM(p.amount), 0) as total_collected
             FROM tbl_payment p
             JOIN tbl_maintenance_bill b ON b.id = p.bill_id
             WHERE b.society_id = ?"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $totalCollected = (float)$stmt->get_result()->fetch_assoc()['total_collected'];

        // Total pending (sum of total_amount + penalty_amount for unpaid/partially_paid bills)
        $stmt = $this->conn->prepare(
            "SELECT COALESCE(SUM(b.total_amount + b.penalty_amount), 0) as total_billed,
                    COUNT(*) as pending_count
             FROM tbl_maintenance_bill b
             WHERE b.society_id = ? AND b.status IN ('pending', 'partially_paid')"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $pendingResult = $stmt->get_result()->fetch_assoc();
        $totalPendingBilled = (float)$pendingResult['total_billed'];
        $pendingCount = (int)$pendingResult['pending_count'];

        // Subtract already paid amounts from pending bills to get actual pending
        $stmt = $this->conn->prepare(
            "SELECT COALESCE(SUM(p.amount), 0) as paid_on_pending
             FROM tbl_payment p
             JOIN tbl_maintenance_bill b ON b.id = p.bill_id
             WHERE b.society_id = ? AND b.status IN ('pending', 'partially_paid')"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $paidOnPending = (float)$stmt->get_result()->fetch_assoc()['paid_on_pending'];
        $totalPending = $totalPendingBilled - $paidOnPending;

        // Overdue count (pending/partially_paid bills past due date)
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) as overdue_count
             FROM tbl_maintenance_bill b
             WHERE b.society_id = ? AND b.status IN ('pending', 'partially_paid')
               AND b.due_date < CURDATE()"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $overdueCount = (int)$stmt->get_result()->fetch_assoc()['overdue_count'];

        // Total bills generated
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) as total_bills FROM tbl_maintenance_bill WHERE society_id = ?"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $totalBills = (int)$stmt->get_result()->fetch_assoc()['total_bills'];

        // Fully paid count
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) as paid_count
             FROM tbl_maintenance_bill
             WHERE society_id = ? AND status = 'paid'"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $paidCount = (int)$stmt->get_result()->fetch_assoc()['paid_count'];

        ApiResponse::success([
            'total_collected' => $totalCollected,
            'total_pending' => $totalPending,
            'overdue_count' => $overdueCount,
            'total_bills' => $totalBills,
            'paid_count' => $paidCount,
            'pending_count' => $pendingCount,
        ], 'Billing summary retrieved successfully');
    }
}
