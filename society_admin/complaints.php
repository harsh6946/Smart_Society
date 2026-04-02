<?php
require_once 'includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';
require_once __DIR__ . '/../include/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'assign') {
        $complaint_id = intval($_POST['complaint_id'] ?? 0);
        $assigned_to = intval($_POST['assigned_to'] ?? 0) ?: null;
        if ($complaint_id > 0) {
            $stmt = $conn->prepare("UPDATE tbl_complaint SET assigned_to = ?, status = 'in_progress', updated_at = NOW() WHERE id = ? AND society_id = ?");
            $stmt->bind_param('iii', $assigned_to, $complaint_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Complaint assigned.';
        }
    } elseif ($action === 'resolve') {
        $complaint_id = intval($_POST['complaint_id'] ?? 0);
        $note = trim($_POST['resolution_note'] ?? '');
        if ($complaint_id > 0) {
            $stmt = $conn->prepare(
                "UPDATE tbl_complaint SET status = 'resolved', resolution_note = ?, resolved_at = NOW(), updated_at = NOW() WHERE id = ? AND society_id = ?"
            );
            $stmt->bind_param('sii', $note, $complaint_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Complaint resolved.';
        }
    } elseif ($action === 'close') {
        $complaint_id = intval($_POST['complaint_id'] ?? 0);
        if ($complaint_id > 0) {
            $stmt = $conn->prepare(
                "UPDATE tbl_complaint SET status = 'closed', closed_at = NOW(), updated_at = NOW() WHERE id = ? AND society_id = ?"
            );
            $stmt->bind_param('ii', $complaint_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Complaint closed.';
        }
    }

    header('Location: complaints?' . http_build_query($_GET));
    exit;
}

$filter = $_GET['status'] ?? 'all';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

$where = "c.society_id = ?";
$params = [$society_id];
$types = 'i';

if ($filter !== 'all' && in_array($filter, ['open', 'in_progress', 'resolved', 'closed', 'reopened'])) {
    $where .= " AND c.status = ?";
    $params[] = $filter;
    $types .= 's';
}

$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_complaint c WHERE $where");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
$total_pages = max(1, ceil($total / $per_page));

$q_params = $params;
$q_types = $types;
$q_params[] = $per_page;
$q_params[] = $offset;
$q_types .= 'ii';

$stmt = $conn->prepare(
    "SELECT c.*, u.name as raised_by_name, cc.name as category_name,
            f.flat_number, tw.name as tower_name,
            au.name as assigned_to_name
     FROM tbl_complaint c
     JOIN tbl_user u ON c.raised_by = u.id
     LEFT JOIN tbl_complaint_category cc ON c.category_id = cc.id
     JOIN tbl_flat f ON c.flat_id = f.id
     JOIN tbl_tower tw ON f.tower_id = tw.id
     LEFT JOIN tbl_admin au ON c.assigned_to = au.id
     WHERE $where
     ORDER BY c.created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->bind_param($q_types, ...$q_params);
$stmt->execute();
$complaints = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get admins for assignment
$stmt = $conn->prepare("SELECT id, name FROM tbl_admin WHERE society_id = ? AND status = 'active'");
$stmt->bind_param('i', $society_id);
$stmt->execute();
$admins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// View detail
$detail = null;
$detail_id = intval($_GET['view'] ?? 0);
if ($detail_id > 0) {
    $stmt = $conn->prepare(
        "SELECT c.*, u.name as raised_by_name, u.phone as raised_by_phone, cc.name as category_name,
                f.flat_number, tw.name as tower_name, au.name as assigned_to_name
         FROM tbl_complaint c
         JOIN tbl_user u ON c.raised_by = u.id
         LEFT JOIN tbl_complaint_category cc ON c.category_id = cc.id
         JOIN tbl_flat f ON c.flat_id = f.id
         JOIN tbl_tower tw ON f.tower_id = tw.id
         LEFT JOIN tbl_admin au ON c.assigned_to = au.id
         WHERE c.id = ? AND c.society_id = ?"
    );
    $stmt->bind_param('ii', $detail_id, $society_id);
    $stmt->execute();
    $detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<?php if ($detail): ?>
<!-- Detail View -->
<div class="d-flex align-items-center mb-4">
    <a href="complaints" class="btn btn-outline-secondary btn-sm me-3"><i class="fas fa-arrow-left"></i></a>
    <h4 class="mb-0">Complaint #<?php echo $detail['id']; ?></h4>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card table-card">
            <div class="card-body">
                <h5><?php echo htmlspecialchars($detail['title']); ?></h5>
                <div class="mb-3">
                    <span class="badge badge-<?php echo $detail['priority']; ?> me-1"><?php echo ucfirst($detail['priority']); ?></span>
                    <span class="badge badge-<?php echo $detail['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $detail['status'])); ?></span>
                </div>
                <p><?php echo nl2br(htmlspecialchars($detail['description'] ?? '')); ?></p>
                <?php if ($detail['resolution_note']): ?>
                    <div class="alert alert-success mt-3">
                        <strong>Resolution Note:</strong><br>
                        <?php echo nl2br(htmlspecialchars($detail['resolution_note'])); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card table-card">
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted">Raised By</td><td><?php echo htmlspecialchars($detail['raised_by_name']); ?></td></tr>
                    <tr><td class="text-muted">Phone</td><td><?php echo htmlspecialchars($detail['raised_by_phone']); ?></td></tr>
                    <tr><td class="text-muted">Flat</td><td><?php echo htmlspecialchars($detail['tower_name'] . ' - ' . $detail['flat_number']); ?></td></tr>
                    <tr><td class="text-muted">Category</td><td><?php echo htmlspecialchars($detail['category_name'] ?? 'N/A'); ?></td></tr>
                    <tr><td class="text-muted">Assigned To</td><td><?php echo htmlspecialchars($detail['assigned_to_name'] ?? 'Unassigned'); ?></td></tr>
                    <tr><td class="text-muted">Created</td><td><?php echo formatDate($detail['created_at'], 'd M Y H:i'); ?></td></tr>
                    <?php
                    $sla_class = 'sla-within';
                    $sla_text = 'Within SLA';
                    $hours_elapsed = (time() - strtotime($detail['created_at'])) / 3600;
                    if ($detail['status'] !== 'resolved' && $detail['status'] !== 'closed') {
                        if ($hours_elapsed > $detail['sla_hours']) {
                            $sla_class = 'sla-breached';
                            $sla_text = 'SLA Breached';
                        } elseif ($hours_elapsed > $detail['sla_hours'] * 0.75) {
                            $sla_class = 'sla-warning';
                            $sla_text = 'SLA Warning';
                        }
                    }
                    ?>
                    <tr><td class="text-muted">SLA</td><td class="<?php echo $sla_class; ?>"><?php echo $sla_text; ?> (<?php echo $detail['sla_hours']; ?>h)</td></tr>
                </table>

                <?php if (!in_array($detail['status'], ['resolved', 'closed'])): ?>
                    <hr>
                    <form method="POST" class="mb-2">
                        <input type="hidden" name="action" value="assign">
                        <input type="hidden" name="complaint_id" value="<?php echo $detail['id']; ?>">
                        <div class="input-group input-group-sm">
                            <select name="assigned_to" class="form-select form-select-sm">
                                <option value="">Assign to...</option>
                                <?php foreach ($admins as $a): ?>
                                    <option value="<?php echo $a['id']; ?>" <?php echo ($detail['assigned_to'] == $a['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($a['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-primary">Assign</button>
                        </div>
                    </form>
                    <button class="btn btn-success btn-sm w-100" data-bs-toggle="modal" data-bs-target="#resolveModal">
                        <i class="fas fa-check me-1"></i>Resolve
                    </button>
                <?php elseif ($detail['status'] === 'resolved'): ?>
                    <hr>
                    <form method="POST">
                        <input type="hidden" name="action" value="close">
                        <input type="hidden" name="complaint_id" value="<?php echo $detail['id']; ?>">
                        <button type="submit" class="btn btn-secondary btn-sm w-100">
                            <i class="fas fa-lock me-1"></i>Close Complaint
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Resolve Modal -->
<div class="modal fade" id="resolveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="resolve">
                <input type="hidden" name="complaint_id" value="<?php echo $detail['id']; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Resolve Complaint</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Resolution Note</label>
                        <textarea name="resolution_note" class="form-control" rows="4" placeholder="Describe how the issue was resolved..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Mark as Resolved</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php else: ?>
<!-- List View -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Complaints</h4>
</div>

<div class="card table-card">
    <div class="card-header">
        <ul class="nav filter-tabs">
            <?php foreach (['all' => 'All', 'open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $key => $label): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $filter === $key ? 'active' : ''; ?>" href="?status=<?php echo $key; ?>"><?php echo $label; ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Raised By</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Assigned To</th>
                        <th>SLA</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($complaints)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">No complaints found</td></tr>
                    <?php else: ?>
                        <?php foreach ($complaints as $c): ?>
                            <?php
                            $sla_class = 'sla-within';
                            $sla_icon = 'check-circle';
                            $hours_elapsed = (time() - strtotime($c['created_at'])) / 3600;
                            if (!in_array($c['status'], ['resolved', 'closed'])) {
                                if ($hours_elapsed > $c['sla_hours']) {
                                    $sla_class = 'sla-breached';
                                    $sla_icon = 'times-circle';
                                } elseif ($hours_elapsed > $c['sla_hours'] * 0.75) {
                                    $sla_class = 'sla-warning';
                                    $sla_icon = 'exclamation-circle';
                                }
                            }
                            ?>
                            <tr>
                                <td><?php echo $c['id']; ?></td>
                                <td class="text-truncate" style="max-width:150px"><?php echo htmlspecialchars($c['title']); ?></td>
                                <td><?php echo htmlspecialchars($c['category_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($c['raised_by_name']); ?></td>
                                <td><span class="badge badge-<?php echo $c['priority']; ?>"><?php echo ucfirst($c['priority']); ?></span></td>
                                <td><span class="badge badge-<?php echo $c['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $c['status'])); ?></span></td>
                                <td><?php echo htmlspecialchars($c['assigned_to_name'] ?? 'Unassigned'); ?></td>
                                <td class="<?php echo $sla_class; ?>"><i class="fas fa-<?php echo $sla_icon; ?>"></i></td>
                                <td>
                                    <a href="?view=<?php echo $c['id']; ?>" class="btn btn-sm btn-outline-primary" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
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
                        <a class="page-link" href="?status=<?php echo $filter; ?>&page=<?php echo $page - 1; ?>">Prev</a>
                    </li>
                    <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?status=<?php echo $filter; ?>&page=<?php echo $p; ?>"><?php echo $p; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?status=<?php echo $filter; ?>&page=<?php echo $page + 1; ?>">Next</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
