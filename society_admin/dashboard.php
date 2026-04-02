<?php
require_once 'includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';
require_once __DIR__ . '/../include/helpers.php';

// Total Residents
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_resident WHERE society_id = ? AND status = 'approved'");
$stmt->bind_param('i', $society_id);
$stmt->execute();
$total_residents = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Pending Approvals
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_resident WHERE society_id = ? AND status = 'pending'");
$stmt->bind_param('i', $society_id);
$stmt->execute();
$pending_approvals = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Total Flats
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_flat f JOIN tbl_tower t ON f.tower_id = t.id WHERE t.society_id = ?");
$stmt->bind_param('i', $society_id);
$stmt->execute();
$total_flats = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Occupied Flats
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_flat f JOIN tbl_tower t ON f.tower_id = t.id WHERE t.society_id = ? AND f.status = 'occupied'");
$stmt->bind_param('i', $society_id);
$stmt->execute();
$occupied_flats = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Bills Pending
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_maintenance_bill WHERE society_id = ? AND status IN ('pending','overdue')");
$stmt->bind_param('i', $society_id);
$stmt->execute();
$bills_pending = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Open Complaints
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_complaint WHERE society_id = ? AND status IN ('open','in_progress','reopened')");
$stmt->bind_param('i', $society_id);
$stmt->execute();
$open_complaints = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Visitors Today
$today = date('Y-m-d');
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_visitor WHERE society_id = ? AND DATE(created_at) = ?");
$stmt->bind_param('is', $society_id, $today);
$stmt->execute();
$visitors_today = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Recent Residents
$stmt = $conn->prepare(
    "SELECT r.id, u.name, u.phone, r.status, r.created_at, f.flat_number, tw.name as tower_name
     FROM tbl_resident r
     JOIN tbl_user u ON r.user_id = u.id
     JOIN tbl_flat f ON r.flat_id = f.id
     JOIN tbl_tower tw ON f.tower_id = tw.id
     WHERE r.society_id = ?
     ORDER BY r.created_at DESC LIMIT 5"
);
$stmt->bind_param('i', $society_id);
$stmt->execute();
$recent_residents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Recent Complaints
$stmt = $conn->prepare(
    "SELECT c.id, c.title, c.priority, c.status, c.created_at, u.name as raised_by_name
     FROM tbl_complaint c
     JOIN tbl_user u ON c.raised_by = u.id
     WHERE c.society_id = ?
     ORDER BY c.created_at DESC LIMIT 5"
);
$stmt->bind_param('i', $society_id);
$stmt->execute();
$recent_complaints = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<h4 class="mb-4"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</h4>

<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card border-left-primary">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label">Total Residents</div>
                    <div class="stat-value"><?php echo $total_residents; ?></div>
                </div>
                <div class="stat-icon bg-icon-primary"><i class="fas fa-users"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card border-left-warning">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label">Pending Approvals</div>
                    <div class="stat-value"><?php echo $pending_approvals; ?></div>
                </div>
                <div class="stat-icon bg-icon-warning"><i class="fas fa-user-clock"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card border-left-info">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label">Total Flats</div>
                    <div class="stat-value"><?php echo $total_flats; ?></div>
                </div>
                <div class="stat-icon bg-icon-info"><i class="fas fa-building"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card border-left-success">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label">Occupied Flats</div>
                    <div class="stat-value"><?php echo $occupied_flats; ?></div>
                </div>
                <div class="stat-icon bg-icon-success"><i class="fas fa-home"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-4 col-md-6">
        <div class="card stat-card border-left-danger">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label">Bills Pending</div>
                    <div class="stat-value"><?php echo $bills_pending; ?></div>
                </div>
                <div class="stat-icon bg-icon-danger"><i class="fas fa-file-invoice-dollar"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-4 col-md-6">
        <div class="card stat-card border-left-warning">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label">Open Complaints</div>
                    <div class="stat-value"><?php echo $open_complaints; ?></div>
                </div>
                <div class="stat-icon bg-icon-warning"><i class="fas fa-exclamation-circle"></i></div>
            </div>
        </div>
    </div>
    <div class="col-xl-4 col-md-6">
        <div class="card stat-card border-left-info">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label">Visitors Today</div>
                    <div class="stat-value"><?php echo $visitors_today; ?></div>
                </div>
                <div class="stat-icon bg-icon-info"><i class="fas fa-id-card"></i></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card table-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6><i class="fas fa-user-plus me-2"></i>Recent Residents</h6>
                <a href="residents" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Flat</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_residents)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">No residents found</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_residents as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['name']); ?></td>
                                        <td><?php echo htmlspecialchars($r['tower_name'] . ' - ' . $r['flat_number']); ?></td>
                                        <td><span class="badge badge-<?php echo $r['status']; ?>"><?php echo ucfirst($r['status']); ?></span></td>
                                        <td><?php echo formatDate($r['created_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card table-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6><i class="fas fa-exclamation-triangle me-2"></i>Recent Complaints</h6>
                <a href="complaints" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>By</th>
                                <th>Priority</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_complaints)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">No complaints found</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_complaints as $c): ?>
                                    <tr>
                                        <td class="text-truncate" style="max-width:150px"><?php echo htmlspecialchars($c['title']); ?></td>
                                        <td><?php echo htmlspecialchars($c['raised_by_name']); ?></td>
                                        <td><span class="badge badge-<?php echo $c['priority']; ?>"><?php echo ucfirst($c['priority']); ?></span></td>
                                        <td><span class="badge badge-<?php echo $c['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $c['status'])); ?></span></td>
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

<?php require_once 'includes/footer.php'; ?>
