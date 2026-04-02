<?php
require_once 'includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';
require_once __DIR__ . '/../include/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_blacklist') {
        $name = trim($_POST['bl_name'] ?? '');
        $phone = trim($_POST['bl_phone'] ?? '');
        $reason = trim($_POST['bl_reason'] ?? '');
        if ($name !== '' || $phone !== '') {
            $stmt = $conn->prepare(
                "INSERT INTO tbl_visitor_blacklist (society_id, phone, name, reason, blacklisted_by)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('isssi', $society_id, $phone, $name, $reason, $admin_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Added to blacklist.';
        }
    } elseif ($action === 'remove_blacklist') {
        $bl_id = intval($_POST['bl_id'] ?? 0);
        if ($bl_id > 0) {
            $stmt = $conn->prepare("DELETE FROM tbl_visitor_blacklist WHERE id = ? AND society_id = ?");
            $stmt->bind_param('ii', $bl_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Removed from blacklist.';
        }
    }

    header('Location: visitors?' . http_build_query($_GET));
    exit;
}

$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$stmt = $conn->prepare(
    "SELECT v.*, f.flat_number, tw.name as tower_name
     FROM tbl_visitor v
     JOIN tbl_flat f ON v.flat_id = f.id
     JOIN tbl_tower tw ON f.tower_id = tw.id
     WHERE v.society_id = ? AND DATE(v.created_at) BETWEEN ? AND ?
     ORDER BY v.created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->bind_param('issii', $society_id, $date_from, $date_to, $per_page, $offset);
$stmt->execute();
$visitors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT COUNT(*) as cnt FROM tbl_visitor WHERE society_id = ? AND DATE(created_at) BETWEEN ? AND ?"
);
$stmt->bind_param('iss', $society_id, $date_from, $date_to);
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
$total_pages = max(1, ceil($total / $per_page));

// Blacklist
$stmt = $conn->prepare(
    "SELECT bl.*, a.name as blacklisted_by_name FROM tbl_visitor_blacklist bl
     LEFT JOIN tbl_admin a ON bl.blacklisted_by = a.id
     WHERE bl.society_id = ? ORDER BY bl.created_at DESC"
);
$stmt->bind_param('i', $society_id);
$stmt->execute();
$blacklist = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-id-card me-2"></i>Visitors & Access</h4>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card table-card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Visitor Log</h6>
                    <form method="GET" class="d-flex align-items-center gap-2">
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_from); ?>">
                        <span class="text-muted">to</span>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_to); ?>">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter"></i></button>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Type</th>
                                <th>Flat</th>
                                <th>Status</th>
                                <th>Check-In</th>
                                <th>Check-Out</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($visitors)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No visitors for selected date range</td></tr>
                            <?php else: ?>
                                <?php foreach ($visitors as $v): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($v['name']); ?></td>
                                        <td><?php echo htmlspecialchars($v['phone'] ?? '-'); ?></td>
                                        <td><span class="badge bg-secondary"><?php echo ucfirst($v['visitor_type']); ?></span></td>
                                        <td><?php echo htmlspecialchars($v['tower_name'] . ' - ' . $v['flat_number']); ?></td>
                                        <td><span class="badge badge-<?php echo $v['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $v['status'])); ?></span></td>
                                        <td><?php echo $v['checked_in_at'] ? formatDate($v['checked_in_at'], 'H:i') : '-'; ?></td>
                                        <td><?php echo $v['checked_out_at'] ? formatDate($v['checked_out_at'], 'H:i') : '-'; ?></td>
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
                                <a class="page-link" href="?date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&page=<?php echo $page - 1; ?>">Prev</a>
                            </li>
                            <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&page=<?php echo $p; ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&page=<?php echo $page + 1; ?>">Next</a>
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
                <h6 class="mb-0">Blacklist</h6>
                <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#addBlacklistModal">
                    <i class="fas fa-plus me-1"></i>Add
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($blacklist)): ?>
                                <tr><td colspan="3" class="text-center text-muted py-3">No blacklisted visitors</td></tr>
                            <?php else: ?>
                                <?php foreach ($blacklist as $bl): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($bl['name'] ?? '-'); ?>
                                            <?php if ($bl['reason']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($bl['reason']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($bl['phone'] ?? '-'); ?></td>
                                        <td>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="remove_blacklist">
                                                <input type="hidden" name="bl_id" value="<?php echo $bl['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                                        onclick="return confirm('Remove from blacklist?')">
                                                    <i class="fas fa-times"></i>
                                                </button>
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

<!-- Add Blacklist Modal -->
<div class="modal fade" id="addBlacklistModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_blacklist">
                <div class="modal-header">
                    <h5 class="modal-title">Add to Blacklist</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="bl_name" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="bl_phone" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <textarea name="bl_reason" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Add to Blacklist</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
