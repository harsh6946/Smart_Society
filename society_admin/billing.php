<?php
require_once 'includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';
require_once __DIR__ . '/../include/helpers.php';
require_once __DIR__ . '/../include/security.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'generate_bills') {
        $bill_month = intval($_POST['bill_month'] ?? 0);
        $bill_year = intval($_POST['bill_year'] ?? 0);
        $due_date = $_POST['due_date'] ?? '';

        if ($bill_month >= 1 && $bill_month <= 12 && $bill_year >= 2020 && $due_date !== '') {
            // Get active maintenance heads
            $stmt = $conn->prepare("SELECT id, amount FROM tbl_maintenance_head WHERE society_id = ? AND is_active = 1 AND frequency = 'monthly'");
            $stmt->bind_param('i', $society_id);
            $stmt->execute();
            $heads = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $total_amount = 0;
            foreach ($heads as $h) {
                $total_amount += $h['amount'];
            }

            if ($total_amount > 0) {
                // Get all flats in this society
                $stmt = $conn->prepare(
                    "SELECT f.id as flat_id FROM tbl_flat f
                     JOIN tbl_tower t ON f.tower_id = t.id
                     WHERE t.society_id = ? AND f.status != 'locked'"
                );
                $stmt->bind_param('i', $society_id);
                $stmt->execute();
                $flats_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                $generated = 0;
                foreach ($flats_result as $flat) {
                    // Check if bill already exists
                    $stmt = $conn->prepare("SELECT id FROM tbl_maintenance_bill WHERE flat_id = ? AND month = ? AND year = ?");
                    $stmt->bind_param('iii', $flat['flat_id'], $bill_month, $bill_year);
                    $stmt->execute();
                    $existing = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$existing) {
                        $stmt = $conn->prepare(
                            "INSERT INTO tbl_maintenance_bill (society_id, flat_id, month, year, total_amount, due_date)
                             VALUES (?, ?, ?, ?, ?, ?)"
                        );
                        $stmt->bind_param('iiiisd', $society_id, $flat['flat_id'], $bill_month, $bill_year, $total_amount, $due_date);
                        $stmt->execute();
                        $bill_id = $stmt->insert_id;
                        $stmt->close();

                        // Add bill items
                        foreach ($heads as $h) {
                            $stmt = $conn->prepare("INSERT INTO tbl_bill_item (bill_id, head_id, amount) VALUES (?, ?, ?)");
                            $stmt->bind_param('iid', $bill_id, $h['id'], $h['amount']);
                            $stmt->execute();
                            $stmt->close();
                        }
                        $generated++;
                    }
                }
                $_SESSION['flash_success'] = "$generated bills generated for " . date('F', mktime(0, 0, 0, $bill_month)) . " $bill_year.";
            } else {
                $_SESSION['flash_error'] = 'No active maintenance heads found. Add maintenance heads first.';
            }
        } else {
            $_SESSION['flash_error'] = 'Please provide valid month, year, and due date.';
        }
    } elseif ($action === 'add_head') {
        $name = trim($_POST['head_name'] ?? '');
        $amount = floatval($_POST['head_amount'] ?? 0);
        $frequency = $_POST['frequency'] ?? 'monthly';
        if ($name !== '' && $amount > 0) {
            $stmt = $conn->prepare("INSERT INTO tbl_maintenance_head (society_id, name, amount, frequency) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('isds', $society_id, $name, $amount, $frequency);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Maintenance head added.';
        }
    } elseif ($action === 'edit_head') {
        $head_id = intval($_POST['head_id'] ?? 0);
        $name = trim($_POST['head_name'] ?? '');
        $amount = floatval($_POST['head_amount'] ?? 0);
        $frequency = $_POST['frequency'] ?? 'monthly';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        if ($head_id > 0 && $name !== '') {
            $stmt = $conn->prepare("UPDATE tbl_maintenance_head SET name = ?, amount = ?, frequency = ?, is_active = ? WHERE id = ? AND society_id = ?");
            $stmt->bind_param('sdsiii', $name, $amount, $frequency, $is_active, $head_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Maintenance head updated.';
        }
    } elseif ($action === 'delete_head') {
        $head_id = intval($_POST['head_id'] ?? 0);
        if ($head_id > 0) {
            $stmt = $conn->prepare("DELETE FROM tbl_maintenance_head WHERE id = ? AND society_id = ?");
            $stmt->bind_param('ii', $head_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Maintenance head deleted.';
        }
    }

    header('Location: billing?' . http_build_query($_GET));
    exit;
}

// Filters
$filter_month = intval($_GET['month'] ?? date('n'));
$filter_year = intval($_GET['year'] ?? date('Y'));
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get bills
$stmt = $conn->prepare(
    "SELECT b.*, f.flat_number, tw.name as tower_name
     FROM tbl_maintenance_bill b
     JOIN tbl_flat f ON b.flat_id = f.id
     JOIN tbl_tower tw ON f.tower_id = tw.id
     WHERE b.society_id = ? AND b.month = ? AND b.year = ?
     ORDER BY tw.name, f.flat_number
     LIMIT ? OFFSET ?"
);
$stmt->bind_param('iiiii', $society_id, $filter_month, $filter_year, $per_page, $offset);
$stmt->execute();
$bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_maintenance_bill WHERE society_id = ? AND month = ? AND year = ?");
$stmt->bind_param('iii', $society_id, $filter_month, $filter_year);
$stmt->execute();
$total_bills = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
$total_pages = max(1, ceil($total_bills / $per_page));

// Get maintenance heads
$stmt = $conn->prepare("SELECT * FROM tbl_maintenance_head WHERE society_id = ? ORDER BY name");
$stmt->bind_param('i', $society_id);
$stmt->execute();
$heads = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-file-invoice-dollar me-2"></i>Maintenance Billing</h4>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#generateBillModal">
        <i class="fas fa-receipt me-1"></i>Generate Bills
    </button>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card table-card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Bills</h6>
                    <form method="GET" class="d-flex align-items-center gap-2">
                        <select name="month" class="form-select form-select-sm" style="width:auto">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m == $filter_month ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <select name="year" class="form-select form-select-sm" style="width:auto">
                            <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == $filter_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter"></i></button>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Tower</th>
                                <th>Flat</th>
                                <th>Amount</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Paid At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bills)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No bills for this period</td></tr>
                            <?php else: ?>
                                <?php foreach ($bills as $b): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($b['tower_name']); ?></td>
                                        <td><?php echo htmlspecialchars($b['flat_number']); ?></td>
                                        <td><?php echo formatCurrency($b['total_amount']); ?></td>
                                        <td><?php echo formatDate($b['due_date']); ?></td>
                                        <td><span class="badge badge-<?php echo $b['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $b['status'])); ?></span></td>
                                        <td><?php echo $b['paid_at'] ? formatDate($b['paid_at'], 'd M Y H:i') : '-'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($total_pages > 1): ?>
                <div class="card-footer">
                    <nav>
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?month=<?php echo $filter_month; ?>&year=<?php echo $filter_year; ?>&page=<?php echo $page - 1; ?>">Prev</a>
                            </li>
                            <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?month=<?php echo $filter_month; ?>&year=<?php echo $filter_year; ?>&page=<?php echo $p; ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?month=<?php echo $filter_month; ?>&year=<?php echo $filter_year; ?>&page=<?php echo $page + 1; ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card table-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Maintenance Heads</h6>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addHeadModal">
                    <i class="fas fa-plus"></i>
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Amount</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($heads)): ?>
                                <tr><td colspan="3" class="text-center text-muted py-3">No heads</td></tr>
                            <?php else: ?>
                                <?php foreach ($heads as $h): ?>
                                    <tr class="<?php echo !$h['is_active'] ? 'text-muted' : ''; ?>">
                                        <td>
                                            <?php echo htmlspecialchars($h['name']); ?>
                                            <?php if (!$h['is_active']): ?><small class="text-danger">(Inactive)</small><?php endif; ?>
                                        </td>
                                        <td><?php echo formatCurrency($h['amount']); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary edit-head"
                                                    data-id="<?php echo $h['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($h['name']); ?>"
                                                    data-amount="<?php echo $h['amount']; ?>"
                                                    data-frequency="<?php echo $h['frequency']; ?>"
                                                    data-active="<?php echo $h['is_active']; ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="delete_head">
                                                <input type="hidden" name="head_id" value="<?php echo $h['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                                        onclick="return confirm('Delete this head?')"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Generate Bill Modal -->
<div class="modal fade" id="generateBillModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="generate_bills">
                <div class="modal-header">
                    <h5 class="modal-title">Generate Monthly Bills</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Month</label>
                        <select name="bill_month" class="form-select" required>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m == date('n') ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Year</label>
                        <select name="bill_year" class="form-select" required>
                            <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Due Date</label>
                        <input type="date" name="due_date" class="form-control" required>
                    </div>
                    <div class="alert alert-info small">
                        Bills will be generated for all non-locked flats using active monthly maintenance heads.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Generate Bills</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Head Modal -->
<div class="modal fade" id="addHeadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_head">
                <div class="modal-header">
                    <h5 class="modal-title">Add Maintenance Head</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="head_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount</label>
                        <input type="number" name="head_amount" class="form-control" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Frequency</label>
                        <select name="frequency" class="form-select">
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="yearly">Yearly</option>
                            <option value="one_time">One Time</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Head Modal -->
<div class="modal fade" id="editHeadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit_head">
                <input type="hidden" name="head_id" id="edit_head_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Maintenance Head</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="head_name" id="edit_head_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount</label>
                        <input type="number" name="head_amount" id="edit_head_amount" class="form-control" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Frequency</label>
                        <select name="frequency" id="edit_head_frequency" class="form-select">
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="yearly">Yearly</option>
                            <option value="one_time">One Time</option>
                        </select>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_active" id="edit_head_active" class="form-check-input" value="1">
                        <label class="form-check-label">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
<script>
$(document).ready(function() {
    $('.edit-head').on('click', function() {
        $('#edit_head_id').val($(this).data('id'));
        $('#edit_head_name').val($(this).data('name'));
        $('#edit_head_amount').val($(this).data('amount'));
        $('#edit_head_frequency').val($(this).data('frequency'));
        if ($(this).data('active') == 1) {
            $('#edit_head_active').prop('checked', true);
        } else {
            $('#edit_head_active').prop('checked', false);
        }
        new bootstrap.Modal($('#editHeadModal')).show();
    });
});
</script>
