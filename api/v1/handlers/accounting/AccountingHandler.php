<?php
/**
 * Securis Smart Society Platform — Accounting Handler
 * Manages expenses, expense categories, ledger entries, and financial summaries.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class AccountingHandler {
    private $conn;
    private $auth;
    private $input;

    public function __construct($conn, $auth, $input) {
        $this->conn = $conn;
        $this->auth = $auth;
        $this->input = $input;
    }

    public function handle($method, $action, $id) {
        // All accounting endpoints require admin access
        $this->auth->authenticate();
        $this->auth->requireSociety();
        $this->auth->requirePrimary();

        switch ($method) {
            case 'GET':
                switch ($action) {
                    case 'expenses':
                        if ($id) {
                            $this->getExpense($id);
                        } else {
                            $this->listExpenses();
                        }
                        break;
                    case 'categories':
                        $this->listCategories();
                        break;
                    case 'ledger':
                        $this->listLedger();
                        break;
                    case 'summary':
                        $this->getFinancialSummary();
                        break;
                    case 'report':
                        $this->getExpenseReport();
                        break;
                    default:
                        ApiResponse::notFound('Accounting endpoint not found');
                }
                break;

            case 'POST':
                switch ($action) {
                    case 'expenses':
                        $this->createExpense();
                        break;
                    case 'categories':
                        $this->createCategory();
                        break;
                    default:
                        ApiResponse::notFound('Accounting endpoint not found');
                }
                break;

            case 'PUT':
                switch ($action) {
                    case 'expenses':
                        if ($id) {
                            $this->updateExpense($id);
                        } else {
                            ApiResponse::error('Expense ID is required');
                        }
                        break;
                    case 'categories':
                        if ($id) {
                            $this->updateCategory($id);
                        } else {
                            ApiResponse::error('Category ID is required');
                        }
                        break;
                    default:
                        ApiResponse::notFound('Accounting endpoint not found');
                }
                break;

            case 'DELETE':
                switch ($action) {
                    case 'expenses':
                        if ($id) {
                            $this->deleteExpense($id);
                        } else {
                            ApiResponse::error('Expense ID is required');
                        }
                        break;
                    default:
                        ApiResponse::notFound('Accounting endpoint not found');
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // =========================================================================
    // Expenses
    // =========================================================================

    /**
     * GET /accounting/expenses
     * List expenses. Paginated. Filter by category, date range.
     */
    private function listExpenses() {
        $societyId = $this->auth->getSocietyId();

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $where = "e.society_id = ?";
        $params = [$societyId];
        $types = 'i';

        // Filter by category
        if (!empty($this->input['category'])) {
            $category = sanitizeInput($this->input['category']);
            $where .= " AND e.category = ?";
            $params[] = $category;
            $types .= 's';
        }

        // Filter by date range
        if (!empty($this->input['date_from'])) {
            $dateFrom = sanitizeInput($this->input['date_from']);
            $where .= " AND e.expense_date >= ?";
            $params[] = $dateFrom;
            $types .= 's';
        }

        if (!empty($this->input['date_to'])) {
            $dateTo = sanitizeInput($this->input['date_to']);
            $where .= " AND e.expense_date <= ?";
            $params[] = $dateTo;
            $types .= 's';
        }

        // Filter by payment mode
        if (!empty($this->input['payment_mode'])) {
            $paymentMode = sanitizeInput($this->input['payment_mode']);
            $where .= " AND e.payment_mode = ?";
            $params[] = $paymentMode;
            $types .= 's';
        }

        // Count
        $countSql = "SELECT COUNT(*) as total FROM tbl_expense e WHERE $where";
        $stmt = $this->conn->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        // Fetch
        $sql = "SELECT e.id, e.society_id, e.category, e.title, e.description, e.amount,
                       e.payment_mode, e.vendor_name, e.receipt_image, e.expense_date,
                       e.recorded_by, e.created_at,
                       u.name as recorded_by_name
                FROM tbl_expense e
                LEFT JOIN tbl_user u ON u.id = e.recorded_by
                WHERE $where
                ORDER BY e.expense_date DESC, e.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $expenses = [];
        while ($row = $result->fetch_assoc()) {
            $expenses[] = $this->formatExpense($row);
        }

        ApiResponse::paginated($expenses, $total, $page, $perPage, 'Expenses retrieved successfully');
    }

    /**
     * GET /accounting/expenses/{id}
     * Get a single expense detail.
     */
    private function getExpense($id) {
        $societyId = $this->auth->getSocietyId();

        $stmt = $this->conn->prepare(
            "SELECT e.id, e.society_id, e.category, e.title, e.description, e.amount,
                    e.payment_mode, e.vendor_name, e.receipt_image, e.expense_date,
                    e.recorded_by, e.created_at,
                    u.name as recorded_by_name
             FROM tbl_expense e
             LEFT JOIN tbl_user u ON u.id = e.recorded_by
             WHERE e.id = ? AND e.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Expense not found');
        }

        $row = $result->fetch_assoc();
        ApiResponse::success($this->formatExpense($row), 'Expense retrieved successfully');
    }

    /**
     * POST /accounting/expenses
     * Create a new expense with optional receipt image upload.
     * Input: category, title, description, amount, payment_mode, vendor_name, expense_date.
     * Also creates a corresponding ledger entry.
     */
    private function createExpense() {
        $societyId = $this->auth->getSocietyId();
        $userId = $this->auth->getUserId();

        $category = sanitizeInput($this->input['category'] ?? '');
        $title = sanitizeInput($this->input['title'] ?? '');
        $description = sanitizeInput($this->input['description'] ?? '');
        $amount = floatval($this->input['amount'] ?? 0);
        $paymentMode = sanitizeInput($this->input['payment_mode'] ?? 'cash');
        $vendorName = sanitizeInput($this->input['vendor_name'] ?? '');
        $expenseDate = sanitizeInput($this->input['expense_date'] ?? '');

        // Validation
        if (empty($category)) {
            ApiResponse::error('Category is required');
        }
        if (empty($title)) {
            ApiResponse::error('Title is required');
        }
        if ($amount <= 0) {
            ApiResponse::error('Valid amount is required');
        }
        if (empty($expenseDate)) {
            ApiResponse::error('Expense date is required');
        }

        $allowedModes = ['cash', 'upi', 'bank_transfer', 'cheque'];
        if (!in_array($paymentMode, $allowedModes)) {
            ApiResponse::error('Invalid payment mode. Allowed: ' . implode(', ', $allowedModes));
        }

        // Handle receipt image upload
        $receiptImage = null;
        if (!empty($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = uploadFile($_FILES['receipt_image'], 'expenses');
            if (isset($uploadResult['error'])) {
                ApiResponse::error($uploadResult['error']);
            }
            $receiptImage = $uploadResult['path'] ?? null;
        }

        // Determine financial year (April to March)
        $expMonth = (int)date('n', strtotime($expenseDate));
        $expYear = (int)date('Y', strtotime($expenseDate));
        $financialYear = $expMonth >= 4
            ? $expYear . '-' . ($expYear + 1)
            : ($expYear - 1) . '-' . $expYear;

        $this->conn->begin_transaction();

        try {
            // Insert expense
            $stmt = $this->conn->prepare(
                "INSERT INTO tbl_expense
                 (society_id, category, title, description, amount, payment_mode, vendor_name, receipt_image, expense_date, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('isssdsssi', $societyId, $category, $title, $description, $amount, $paymentMode, $vendorName, $receiptImage, $expenseDate, $userId);
            $stmt->execute();
            $expenseId = $this->conn->insert_id;

            // Create corresponding ledger entry
            $ledgerDesc = $title;
            $refType = 'expense';
            $ledgerType = 'expense';
            $stmt = $this->conn->prepare(
                "INSERT INTO tbl_ledger
                 (society_id, type, category, description, amount, reference_type, reference_id, transaction_date, financial_year)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('isssdsis', $societyId, $ledgerType, $category, $ledgerDesc, $amount, $refType, $expenseId, $expenseDate, $financialYear);
            $stmt->execute();

            $this->conn->commit();
        } catch (Exception $e) {
            $this->conn->rollback();
            ApiResponse::error('Failed to create expense: ' . $e->getMessage(), 500);
        }

        // Fetch and return created expense
        $stmt = $this->conn->prepare(
            "SELECT e.id, e.society_id, e.category, e.title, e.description, e.amount,
                    e.payment_mode, e.vendor_name, e.receipt_image, e.expense_date,
                    e.recorded_by, e.created_at,
                    u.name as recorded_by_name
             FROM tbl_expense e
             LEFT JOIN tbl_user u ON u.id = e.recorded_by
             WHERE e.id = ?"
        );
        $stmt->bind_param('i', $expenseId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        ApiResponse::created($this->formatExpense($row), 'Expense created successfully');
    }

    /**
     * PUT /accounting/expenses/{id}
     * Update an existing expense.
     */
    private function updateExpense($id) {
        $societyId = $this->auth->getSocietyId();

        // Verify expense exists
        $stmt = $this->conn->prepare(
            "SELECT id, category, title, description, amount, payment_mode, vendor_name, expense_date
             FROM tbl_expense
             WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Expense not found');
        }

        $existing = $result->fetch_assoc();

        $category = sanitizeInput($this->input['category'] ?? $existing['category']);
        $title = sanitizeInput($this->input['title'] ?? $existing['title']);
        $description = sanitizeInput($this->input['description'] ?? $existing['description']);
        $amount = floatval($this->input['amount'] ?? $existing['amount']);
        $paymentMode = sanitizeInput($this->input['payment_mode'] ?? $existing['payment_mode']);
        $vendorName = sanitizeInput($this->input['vendor_name'] ?? $existing['vendor_name']);
        $expenseDate = sanitizeInput($this->input['expense_date'] ?? $existing['expense_date']);

        if ($amount <= 0) {
            ApiResponse::error('Valid amount is required');
        }

        $allowedModes = ['cash', 'upi', 'bank_transfer', 'cheque'];
        if (!in_array($paymentMode, $allowedModes)) {
            ApiResponse::error('Invalid payment mode. Allowed: ' . implode(', ', $allowedModes));
        }

        // Handle receipt image upload
        $receiptImage = null;
        if (!empty($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = uploadFile($_FILES['receipt_image'], 'expenses');
            if (isset($uploadResult['error'])) {
                ApiResponse::error($uploadResult['error']);
            }
            $receiptImage = $uploadResult['path'] ?? null;
        }

        // Determine financial year
        $expMonth = (int)date('n', strtotime($expenseDate));
        $expYear = (int)date('Y', strtotime($expenseDate));
        $financialYear = $expMonth >= 4
            ? $expYear . '-' . ($expYear + 1)
            : ($expYear - 1) . '-' . $expYear;

        $this->conn->begin_transaction();

        try {
            // Update expense
            if ($receiptImage) {
                $stmt = $this->conn->prepare(
                    "UPDATE tbl_expense
                     SET category = ?, title = ?, description = ?, amount = ?, payment_mode = ?,
                         vendor_name = ?, receipt_image = ?, expense_date = ?
                     WHERE id = ?"
                );
                $stmt->bind_param('sssdssssi', $category, $title, $description, $amount, $paymentMode, $vendorName, $receiptImage, $expenseDate, $id);
            } else {
                $stmt = $this->conn->prepare(
                    "UPDATE tbl_expense
                     SET category = ?, title = ?, description = ?, amount = ?, payment_mode = ?,
                         vendor_name = ?, expense_date = ?
                     WHERE id = ?"
                );
                $stmt->bind_param('sssdsssi', $category, $title, $description, $amount, $paymentMode, $vendorName, $expenseDate, $id);
            }
            $stmt->execute();

            // Update corresponding ledger entry
            $refType = 'expense';
            $ledgerType = 'expense';
            $stmt = $this->conn->prepare(
                "UPDATE tbl_ledger
                 SET category = ?, description = ?, amount = ?, transaction_date = ?, financial_year = ?
                 WHERE reference_type = ? AND reference_id = ? AND society_id = ? AND type = ?"
            );
            $stmt->bind_param('ssdsssiis', $category, $title, $amount, $expenseDate, $financialYear, $refType, $id, $societyId, $ledgerType);
            $stmt->execute();

            $this->conn->commit();
        } catch (Exception $e) {
            $this->conn->rollback();
            ApiResponse::error('Failed to update expense: ' . $e->getMessage(), 500);
        }

        // Fetch updated expense
        $stmt = $this->conn->prepare(
            "SELECT e.id, e.society_id, e.category, e.title, e.description, e.amount,
                    e.payment_mode, e.vendor_name, e.receipt_image, e.expense_date,
                    e.recorded_by, e.created_at,
                    u.name as recorded_by_name
             FROM tbl_expense e
             LEFT JOIN tbl_user u ON u.id = e.recorded_by
             WHERE e.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        ApiResponse::success($this->formatExpense($row), 'Expense updated successfully');
    }

    /**
     * DELETE /accounting/expenses/{id}
     * Delete an expense and its corresponding ledger entry.
     */
    private function deleteExpense($id) {
        $societyId = $this->auth->getSocietyId();

        // Verify expense exists
        $stmt = $this->conn->prepare(
            "SELECT id FROM tbl_expense WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();

        if ($stmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Expense not found');
        }

        $this->conn->begin_transaction();

        try {
            // Delete ledger entry first (foreign key consideration)
            $refType = 'expense';
            $ledgerType = 'expense';
            $stmt = $this->conn->prepare(
                "DELETE FROM tbl_ledger
                 WHERE reference_type = ? AND reference_id = ? AND society_id = ? AND type = ?"
            );
            $stmt->bind_param('siis', $refType, $id, $societyId, $ledgerType);
            $stmt->execute();

            // Delete expense
            $stmt = $this->conn->prepare(
                "DELETE FROM tbl_expense WHERE id = ? AND society_id = ?"
            );
            $stmt->bind_param('ii', $id, $societyId);
            $stmt->execute();

            $this->conn->commit();
        } catch (Exception $e) {
            $this->conn->rollback();
            ApiResponse::error('Failed to delete expense: ' . $e->getMessage(), 500);
        }

        ApiResponse::success(null, 'Expense deleted successfully');
    }

    // =========================================================================
    // Expense Categories
    // =========================================================================

    /**
     * GET /accounting/categories
     * List expense categories for the society.
     */
    private function listCategories() {
        $societyId = $this->auth->getSocietyId();

        $stmt = $this->conn->prepare(
            "SELECT id, society_id, name, is_active
             FROM tbl_expense_category
             WHERE society_id = ?
             ORDER BY is_active DESC, name ASC"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        $categories = [];
        while ($row = $result->fetch_assoc()) {
            $categories[] = [
                'id' => (int)$row['id'],
                'society_id' => (int)$row['society_id'],
                'name' => $row['name'],
                'is_active' => (bool)$row['is_active'],
            ];
        }

        ApiResponse::success($categories, 'Expense categories retrieved successfully');
    }

    /**
     * POST /accounting/categories
     * Create a new expense category.
     */
    private function createCategory() {
        $societyId = $this->auth->getSocietyId();

        $name = sanitizeInput($this->input['name'] ?? '');

        if (empty($name)) {
            ApiResponse::error('Category name is required');
        }

        // Check for duplicate
        $stmt = $this->conn->prepare(
            "SELECT id FROM tbl_expense_category
             WHERE society_id = ? AND name = ? AND is_active = 1"
        );
        $stmt->bind_param('is', $societyId, $name);
        $stmt->execute();

        if ($stmt->get_result()->num_rows > 0) {
            ApiResponse::error('An expense category with this name already exists');
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_expense_category (society_id, name, is_active) VALUES (?, ?, 1)"
        );
        $stmt->bind_param('is', $societyId, $name);
        $stmt->execute();
        $categoryId = $this->conn->insert_id;

        ApiResponse::created([
            'id' => $categoryId,
            'society_id' => $societyId,
            'name' => $name,
            'is_active' => true,
        ], 'Expense category created successfully');
    }

    /**
     * PUT /accounting/categories/{id}
     * Update an expense category (name, is_active).
     */
    private function updateCategory($id) {
        $societyId = $this->auth->getSocietyId();

        // Verify category exists
        $stmt = $this->conn->prepare(
            "SELECT id, name, is_active FROM tbl_expense_category WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Expense category not found');
        }

        $existing = $result->fetch_assoc();

        $fields = [];
        $params = [];
        $types = '';

        if (isset($this->input['name'])) {
            $name = sanitizeInput($this->input['name']);
            if (empty($name)) {
                ApiResponse::error('Category name cannot be empty');
            }

            // Check for duplicate (exclude self)
            $checkStmt = $this->conn->prepare(
                "SELECT id FROM tbl_expense_category
                 WHERE society_id = ? AND name = ? AND is_active = 1 AND id != ?"
            );
            $checkStmt->bind_param('isi', $societyId, $name, $id);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                ApiResponse::error('Another expense category with this name already exists');
            }

            $fields[] = 'name = ?';
            $params[] = $name;
            $types .= 's';
        }

        if (isset($this->input['is_active'])) {
            $fields[] = 'is_active = ?';
            $params[] = (int)(bool)$this->input['is_active'];
            $types .= 'i';
        }

        if (empty($fields)) {
            ApiResponse::error('No fields to update');
        }

        $sql = "UPDATE tbl_expense_category SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        // Fetch updated
        $stmt = $this->conn->prepare(
            "SELECT id, society_id, name, is_active FROM tbl_expense_category WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        ApiResponse::success([
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'name' => $row['name'],
            'is_active' => (bool)$row['is_active'],
        ], 'Expense category updated successfully');
    }

    // =========================================================================
    // Ledger
    // =========================================================================

    /**
     * GET /accounting/ledger
     * View ledger entries. Paginated. Filter by type, date range, financial year.
     */
    private function listLedger() {
        $societyId = $this->auth->getSocietyId();

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $where = "l.society_id = ?";
        $params = [$societyId];
        $types = 'i';

        // Filter by type (income/expense)
        if (!empty($this->input['type'])) {
            $type = sanitizeInput($this->input['type']);
            if (!in_array($type, ['income', 'expense'])) {
                ApiResponse::error('Invalid type. Allowed: income, expense');
            }
            $where .= " AND l.type = ?";
            $params[] = $type;
            $types .= 's';
        }

        // Filter by category
        if (!empty($this->input['category'])) {
            $category = sanitizeInput($this->input['category']);
            $where .= " AND l.category = ?";
            $params[] = $category;
            $types .= 's';
        }

        // Filter by date range
        if (!empty($this->input['date_from'])) {
            $dateFrom = sanitizeInput($this->input['date_from']);
            $where .= " AND l.transaction_date >= ?";
            $params[] = $dateFrom;
            $types .= 's';
        }

        if (!empty($this->input['date_to'])) {
            $dateTo = sanitizeInput($this->input['date_to']);
            $where .= " AND l.transaction_date <= ?";
            $params[] = $dateTo;
            $types .= 's';
        }

        // Filter by financial year
        if (!empty($this->input['financial_year'])) {
            $fy = sanitizeInput($this->input['financial_year']);
            $where .= " AND l.financial_year = ?";
            $params[] = $fy;
            $types .= 's';
        }

        // Count
        $countSql = "SELECT COUNT(*) as total FROM tbl_ledger l WHERE $where";
        $stmt = $this->conn->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        // Fetch
        $sql = "SELECT l.id, l.society_id, l.type, l.category, l.description, l.amount,
                       l.reference_type, l.reference_id, l.transaction_date, l.financial_year,
                       l.created_at
                FROM tbl_ledger l
                WHERE $where
                ORDER BY l.transaction_date DESC, l.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $entries = [];
        while ($row = $result->fetch_assoc()) {
            $entries[] = [
                'id' => (int)$row['id'],
                'society_id' => (int)$row['society_id'],
                'type' => $row['type'],
                'category' => $row['category'],
                'description' => $row['description'],
                'amount' => (float)$row['amount'],
                'reference_type' => $row['reference_type'],
                'reference_id' => $row['reference_id'] ? (int)$row['reference_id'] : null,
                'transaction_date' => $row['transaction_date'],
                'financial_year' => $row['financial_year'],
                'created_at' => $row['created_at'],
            ];
        }

        ApiResponse::paginated($entries, $total, $page, $perPage, 'Ledger entries retrieved successfully');
    }

    // =========================================================================
    // Financial Summary
    // =========================================================================

    /**
     * GET /accounting/summary
     * Financial summary: total income, total expenses, balance, monthly breakdown.
     * Optional filter: financial_year, year, month.
     */
    private function getFinancialSummary() {
        $societyId = $this->auth->getSocietyId();

        $financialYear = sanitizeInput($this->input['financial_year'] ?? '');
        $year = intval($this->input['year'] ?? 0);
        $month = intval($this->input['month'] ?? 0);

        // Base filters
        $where = "l.society_id = ?";
        $params = [$societyId];
        $types = 'i';

        if (!empty($financialYear)) {
            $where .= " AND l.financial_year = ?";
            $params[] = $financialYear;
            $types .= 's';
        }

        if ($year > 0) {
            $where .= " AND YEAR(l.transaction_date) = ?";
            $params[] = $year;
            $types .= 'i';
        }

        if ($month > 0) {
            $where .= " AND MONTH(l.transaction_date) = ?";
            $params[] = $month;
            $types .= 'i';
        }

        // Total income
        $incomeSql = "SELECT COALESCE(SUM(l.amount), 0) as total
                      FROM tbl_ledger l
                      WHERE $where AND l.type = 'income'";
        $stmt = $this->conn->prepare($incomeSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $totalIncome = (float)$stmt->get_result()->fetch_assoc()['total'];

        // Total expenses
        $expenseSql = "SELECT COALESCE(SUM(l.amount), 0) as total
                       FROM tbl_ledger l
                       WHERE $where AND l.type = 'expense'";
        $stmt = $this->conn->prepare($expenseSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $totalExpenses = (float)$stmt->get_result()->fetch_assoc()['total'];

        $balance = $totalIncome - $totalExpenses;

        // Monthly breakdown
        $monthlySql = "SELECT YEAR(l.transaction_date) as yr, MONTH(l.transaction_date) as mn,
                              l.type,
                              COALESCE(SUM(l.amount), 0) as total
                       FROM tbl_ledger l
                       WHERE $where
                       GROUP BY yr, mn, l.type
                       ORDER BY yr DESC, mn DESC";
        $stmt = $this->conn->prepare($monthlySql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $monthlyResult = $stmt->get_result();

        $monthlyBreakdown = [];
        while ($row = $monthlyResult->fetch_assoc()) {
            $key = $row['yr'] . '-' . str_pad($row['mn'], 2, '0', STR_PAD_LEFT);
            if (!isset($monthlyBreakdown[$key])) {
                $monthlyBreakdown[$key] = [
                    'year' => (int)$row['yr'],
                    'month' => (int)$row['mn'],
                    'income' => 0.0,
                    'expense' => 0.0,
                    'balance' => 0.0,
                ];
            }
            $monthlyBreakdown[$key][$row['type']] = (float)$row['total'];
        }

        // Calculate balance per month
        foreach ($monthlyBreakdown as &$entry) {
            $entry['balance'] = $entry['income'] - $entry['expense'];
        }
        unset($entry);

        // Category-wise expense breakdown
        $categorySql = "SELECT l.category, COALESCE(SUM(l.amount), 0) as total
                        FROM tbl_ledger l
                        WHERE $where AND l.type = 'expense'
                        GROUP BY l.category
                        ORDER BY total DESC";
        $stmt = $this->conn->prepare($categorySql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $catResult = $stmt->get_result();

        $categoryBreakdown = [];
        while ($row = $catResult->fetch_assoc()) {
            $categoryBreakdown[] = [
                'category' => $row['category'],
                'total' => (float)$row['total'],
            ];
        }

        ApiResponse::success([
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'balance' => $balance,
            'total_income_formatted' => formatCurrency($totalIncome),
            'total_expenses_formatted' => formatCurrency($totalExpenses),
            'balance_formatted' => formatCurrency($balance),
            'monthly_breakdown' => array_values($monthlyBreakdown),
            'category_breakdown' => $categoryBreakdown,
        ], 'Financial summary retrieved successfully');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Format an expense row for API response.
     */
    private function formatExpense($row) {
        return [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'category' => $row['category'],
            'title' => $row['title'],
            'description' => $row['description'],
            'amount' => (float)$row['amount'],
            'amount_formatted' => formatCurrency((float)$row['amount']),
            'payment_mode' => $row['payment_mode'],
            'vendor_name' => $row['vendor_name'],
            'receipt_image' => $row['receipt_image'],
            'expense_date' => $row['expense_date'],
            'recorded_by' => [
                'id' => (int)$row['recorded_by'],
                'name' => $row['recorded_by_name'] ?? null,
            ],
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * GET /accounting/report — expense report by financial year
     */
    private function getExpenseReport() {
        $societyId = $this->auth->getSocietyId();
        $financialYear = $this->input['financial_year'] ?? date('Y') . '-' . (date('Y') + 1);

        // Parse financial year (e.g., "2025-26" → April 2025 to March 2026)
        $parts = explode('-', $financialYear);
        $startYear = (int)$parts[0];
        $fromDate = "$startYear-04-01";
        $toDate = ($startYear + 1) . "-03-31";

        // Monthly totals
        $stmt = $this->conn->prepare(
            "SELECT DATE_FORMAT(expense_date, '%Y-%m') as month,
                    SUM(amount) as total
             FROM tbl_expense
             WHERE society_id = ? AND expense_date BETWEEN ? AND ?
             GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
             ORDER BY month"
        );
        $stmt->bind_param('iss', $societyId, $fromDate, $toDate);
        $stmt->execute();
        $monthly = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Category breakdown
        $stmt = $this->conn->prepare(
            "SELECT category, SUM(amount) as total, COUNT(*) as count
             FROM tbl_expense
             WHERE society_id = ? AND expense_date BETWEEN ? AND ?
             GROUP BY category
             ORDER BY total DESC"
        );
        $stmt->bind_param('iss', $societyId, $fromDate, $toDate);
        $stmt->execute();
        $byCategory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Income from maintenance payments
        $stmt = $this->conn->prepare(
            "SELECT SUM(p.amount) as total_income
             FROM tbl_payment p
             JOIN tbl_maintenance_bill b ON p.bill_id = b.id
             WHERE b.society_id = ? AND p.payment_date BETWEEN ? AND ?"
        );
        $stmt->bind_param('iss', $societyId, $fromDate, $toDate);
        $stmt->execute();
        $incomeRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $totalExpense = 0;
        foreach ($byCategory as $c) {
            $totalExpense += (float)$c['total'];
        }
        $totalIncome = (float)($incomeRow['total_income'] ?? 0);

        ApiResponse::success([
            'financial_year' => $financialYear,
            'from' => $fromDate,
            'to' => $toDate,
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'net' => $totalIncome - $totalExpense,
            'monthly' => $monthly,
            'by_category' => $byCategory,
        ]);
    }
}
