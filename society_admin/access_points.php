<?php
require_once 'includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';
require_once __DIR__ . '/../include/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['ap_name'] ?? '');
        $type = $_POST['ap_type'] ?? 'main_gate';
        $location = trim($_POST['ap_location'] ?? '');
        $device_id = trim($_POST['device_id'] ?? '');
        $device_category = $_POST['device_category'] ?? null;
        $is_common = isset($_POST['is_common']) ? 1 : 0;
        if ($device_category === '') $device_category = null;
        if ($name !== '') {
            $stmt = $conn->prepare(
                "INSERT INTO tbl_access_point (society_id, name, type, location, device_id, device_category, is_common)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('isssssi', $society_id, $name, $type, $location, $device_id, $device_category, $is_common);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Access point added.';
        }
    } elseif ($action === 'edit') {
        $ap_id = intval($_POST['ap_id'] ?? 0);
        $name = trim($_POST['ap_name'] ?? '');
        $type = $_POST['ap_type'] ?? 'main_gate';
        $location = trim($_POST['ap_location'] ?? '');
        $device_id = trim($_POST['device_id'] ?? '');
        $status = $_POST['ap_status'] ?? 'active';
        $device_category = $_POST['device_category'] ?? null;
        $is_common = isset($_POST['is_common']) ? 1 : 0;
        if ($device_category === '') $device_category = null;
        if ($ap_id > 0 && $name !== '') {
            $stmt = $conn->prepare(
                "UPDATE tbl_access_point SET name = ?, type = ?, location = ?, device_id = ?, status = ?, device_category = ?, is_common = ? WHERE id = ? AND society_id = ?"
            );
            $stmt->bind_param('ssssssiii', $name, $type, $location, $device_id, $status, $device_category, $is_common, $ap_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Access point updated.';
        }
    } elseif ($action === 'delete') {
        $ap_id = intval($_POST['ap_id'] ?? 0);
        if ($ap_id > 0) {
            $stmt = $conn->prepare("DELETE FROM tbl_access_point WHERE id = ? AND society_id = ?");
            $stmt->bind_param('ii', $ap_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Access point deleted.';
        }
    } elseif ($action === 'share_device') {
        $ap_id = intval($_POST['ap_id'] ?? 0);
        $user_id = intval($_POST['share_user_id'] ?? 0);
        if ($ap_id > 0 && $user_id > 0) {
            // Verify access point belongs to this society and is common
            $stmt = $conn->prepare("SELECT tuya_device_id FROM tbl_access_point WHERE id = ? AND society_id = ? AND is_common = 1");
            $stmt->bind_param('ii', $ap_id, $society_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($result && $result['tuya_device_id']) {
                $stmt = $conn->prepare(
                    "INSERT INTO tbl_tuya_device_share (access_point_id, shared_to_user_id, tuya_device_id, status)
                     VALUES (?, ?, ?, 'active')
                     ON DUPLICATE KEY UPDATE status = 'active', revoked_at = NULL"
                );
                $stmt->bind_param('iis', $ap_id, $user_id, $result['tuya_device_id']);
                $stmt->execute();
                $stmt->close();
                $_SESSION['flash_success'] = 'Device shared successfully.';
            } else {
                $_SESSION['flash_error'] = 'Invalid access point or device not bound.';
            }
        }
    } elseif ($action === 'revoke_share') {
        $share_id = intval($_POST['share_id'] ?? 0);
        if ($share_id > 0) {
            $stmt = $conn->prepare(
                "UPDATE tbl_tuya_device_share ds
                 JOIN tbl_access_point ap ON ds.access_point_id = ap.id
                 SET ds.status = 'revoked', ds.revoked_at = NOW()
                 WHERE ds.id = ? AND ap.society_id = ?"
            );
            $stmt->bind_param('ii', $share_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Share revoked.';
        }
    } elseif ($action === 'share_all') {
        $ap_id = intval($_POST['ap_id'] ?? 0);
        if ($ap_id > 0) {
            // Verify access point
            $stmt = $conn->prepare("SELECT tuya_device_id FROM tbl_access_point WHERE id = ? AND society_id = ? AND is_common = 1");
            $stmt->bind_param('ii', $ap_id, $society_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($result && $result['tuya_device_id']) {
                $tuya_dev_id = $result['tuya_device_id'];
                // Get all approved residents in this society
                $stmt = $conn->prepare(
                    "SELECT u.id FROM tbl_user u
                     JOIN tbl_resident r ON r.user_id = u.id
                     WHERE r.society_id = ? AND r.status = 'approved'"
                );
                $stmt->bind_param('i', $society_id);
                $stmt->execute();
                $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                $shared_count = 0;
                $stmt = $conn->prepare(
                    "INSERT INTO tbl_tuya_device_share (access_point_id, shared_to_user_id, tuya_device_id, status)
                     VALUES (?, ?, ?, 'active')
                     ON DUPLICATE KEY UPDATE status = 'active', revoked_at = NULL"
                );
                foreach ($users as $u) {
                    $stmt->bind_param('iis', $ap_id, $u['id'], $tuya_dev_id);
                    $stmt->execute();
                    $shared_count++;
                }
                $stmt->close();
                $_SESSION['flash_success'] = "Device shared with {$shared_count} residents.";
            } else {
                $_SESSION['flash_error'] = 'Invalid access point or device not bound.';
            }
        }
    }

    header('Location: access_points');
    exit;
}

// Get access points with Tuya fields and bound_by user name
$stmt = $conn->prepare(
    "SELECT ap.*, u.name AS bound_by_name
     FROM tbl_access_point ap
     LEFT JOIN tbl_user u ON ap.bound_by = u.id
     WHERE ap.society_id = ?
     ORDER BY ap.name"
);
$stmt->bind_param('i', $society_id);
$stmt->execute();
$access_points = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get device shares for common access points
$stmt = $conn->prepare(
    "SELECT ds.*, u.name AS user_name, ap.id AS ap_id
     FROM tbl_tuya_device_share ds
     JOIN tbl_user u ON ds.shared_to_user_id = u.id
     JOIN tbl_access_point ap ON ds.access_point_id = ap.id
     WHERE ap.society_id = ? AND ap.is_common = 1
     ORDER BY ds.shared_at DESC"
);
$stmt->bind_param('i', $society_id);
$stmt->execute();
$all_shares = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Group shares by access_point_id
$shares_by_ap = [];
foreach ($all_shares as $share) {
    $shares_by_ap[$share['ap_id']][] = $share;
}

// Get society residents for sharing dropdown
$stmt = $conn->prepare(
    "SELECT u.id, u.name, f.flat_number
     FROM tbl_user u
     JOIN tbl_resident r ON r.user_id = u.id
     JOIN tbl_flat f ON r.flat_id = f.id
     WHERE r.society_id = ? AND r.status = 'approved'
     ORDER BY u.name"
);
$stmt->bind_param('i', $society_id);
$stmt->execute();
$society_residents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Access logs
$date_filter = $_GET['log_date'] ?? date('Y-m-d');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$stmt = $conn->prepare(
    "SELECT al.*, ap.name as ap_name, ap.type as ap_type,
            u.name as user_name, v.name as visitor_name
     FROM tbl_access_log al
     JOIN tbl_access_point ap ON al.access_point_id = ap.id
     LEFT JOIN tbl_user u ON al.user_id = u.id
     LEFT JOIN tbl_visitor v ON al.visitor_id = v.id
     WHERE ap.society_id = ? AND DATE(al.timestamp) = ?
     ORDER BY al.timestamp DESC
     LIMIT ? OFFSET ?"
);
$stmt->bind_param('isii', $society_id, $date_filter, $per_page, $offset);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT COUNT(*) as cnt FROM tbl_access_log al
     JOIN tbl_access_point ap ON al.access_point_id = ap.id
     WHERE ap.society_id = ? AND DATE(al.timestamp) = ?"
);
$stmt->bind_param('is', $society_id, $date_filter);
$stmt->execute();
$total_logs = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
$total_pages = max(1, ceil($total_logs / $per_page));

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-door-open me-2"></i>Access Points</h4>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAPModal">
        <i class="fas fa-plus me-1"></i>Add Access Point
    </button>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($access_points as $ap): ?>
        <div class="col-md-4 col-lg-3">
            <div class="card stat-card border-left-<?php echo $ap['status'] === 'active' ? 'success' : ($ap['status'] === 'maintenance' ? 'warning' : 'danger'); ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h6 class="mb-1"><?php echo htmlspecialchars($ap['name']); ?></h6>
                            <small class="text-muted"><?php echo ucfirst(str_replace('_', ' ', $ap['type'])); ?></small>
                        </div>
                        <span class="badge badge-<?php echo $ap['status']; ?>"><?php echo ucfirst($ap['status']); ?></span>
                    </div>
                    <?php if ($ap['location']): ?>
                        <small class="text-muted d-block mb-1"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($ap['location']); ?></small>
                    <?php endif; ?>
                    <div class="d-flex flex-wrap gap-1 mb-2">
                        <?php if (!empty($ap['tuya_device_id'])): ?>
                            <span class="badge bg-success" title="<?php echo htmlspecialchars($ap['tuya_device_id']); ?>">
                                <i class="fas fa-link me-1"></i>Tuya Connected
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><i class="fas fa-unlink me-1"></i>No Device</span>
                        <?php endif; ?>
                        <?php if (!empty($ap['is_common'])): ?>
                            <span class="badge bg-info"><i class="fas fa-building me-1"></i>Common Door</span>
                        <?php else: ?>
                            <span class="badge bg-light text-dark"><i class="fas fa-key me-1"></i>Personal Lock</span>
                        <?php endif; ?>
                        <?php if (!empty($ap['device_category'])): ?>
                            <span class="badge bg-primary"><i class="fas fa-microchip me-1"></i><?php echo htmlspecialchars(ucfirst($ap['device_category'])); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($ap['bound_by_name'])): ?>
                        <small class="text-muted d-block mb-2">
                            <i class="fas fa-user-check me-1"></i>Bound by <?php echo htmlspecialchars($ap['bound_by_name']); ?>
                            <?php if (!empty($ap['bound_at'])): ?>
                                on <?php echo date('M j, Y', strtotime($ap['bound_at'])); ?>
                            <?php endif; ?>
                        </small>
                    <?php endif; ?>
                    <div class="d-flex gap-1">
                        <button class="btn btn-sm btn-outline-primary edit-ap"
                                data-id="<?php echo $ap['id']; ?>"
                                data-name="<?php echo htmlspecialchars($ap['name']); ?>"
                                data-type="<?php echo $ap['type']; ?>"
                                data-location="<?php echo htmlspecialchars($ap['location'] ?? ''); ?>"
                                data-device="<?php echo htmlspecialchars($ap['device_id'] ?? ''); ?>"
                                data-status="<?php echo $ap['status']; ?>"
                                data-category="<?php echo htmlspecialchars($ap['device_category'] ?? ''); ?>"
                                data-common="<?php echo $ap['is_common'] ?? 0; ?>">
                            <i class="fas fa-edit"></i>
                        </button>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="ap_id" value="<?php echo $ap['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    onclick="return confirm('Delete this access point?')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($access_points)): ?>
        <div class="col-12"><p class="text-muted text-center py-4">No access points configured.</p></div>
    <?php endif; ?>
</div>

<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Access Logs</h6>
        <form method="GET" class="d-flex align-items-center gap-2">
            <input type="date" name="log_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_filter); ?>">
            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter"></i></button>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>User / Visitor</th>
                        <th>Access Point</th>
                        <th>Direction</th>
                        <th>Type</th>
                        <th>Time</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No logs for selected date</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($l['user_name'] ?? $l['visitor_name'] ?? 'Unknown'); ?></td>
                                <td><?php echo htmlspecialchars($l['ap_name']); ?></td>
                                <td>
                                    <?php if ($l['direction'] === 'entry'): ?>
                                        <span class="text-success"><i class="fas fa-arrow-right me-1"></i>Entry</span>
                                    <?php else: ?>
                                        <span class="text-danger"><i class="fas fa-arrow-left me-1"></i>Exit</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-secondary"><?php echo strtoupper($l['access_type']); ?></span></td>
                                <td><?php echo formatDate($l['timestamp'], 'H:i:s'); ?></td>
                                <td><span class="badge badge-<?php echo $l['status']; ?>"><?php echo ucfirst($l['status']); ?></span></td>
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
                        <a class="page-link" href="?log_date=<?php echo $date_filter; ?>&page=<?php echo $page - 1; ?>">Prev</a>
                    </li>
                    <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?log_date=<?php echo $date_filter; ?>&page=<?php echo $p; ?>"><?php echo $p; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?log_date=<?php echo $date_filter; ?>&page=<?php echo $page + 1; ?>">Next</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<!-- Device Sharing Section (Common Devices Only) -->
<?php
$common_aps = array_filter($access_points, function($ap) { return !empty($ap['is_common']) && !empty($ap['tuya_device_id']); });
if (!empty($common_aps)):
?>
<div class="card table-card mb-4">
    <div class="card-header">
        <h6 class="mb-0"><i class="fas fa-share-alt me-2"></i>Device Sharing - Common Doors</h6>
    </div>
    <div class="card-body">
        <?php foreach ($common_aps as $ap): ?>
            <div class="mb-4 pb-3 border-bottom">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">
                        <?php echo htmlspecialchars($ap['name']); ?>
                        <span class="badge bg-success ms-2">Tuya Connected</span>
                    </h6>
                    <div class="d-flex gap-2">
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="share_all">
                            <input type="hidden" name="ap_id" value="<?php echo $ap['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-info"
                                    onclick="return confirm('Share this device with ALL approved residents?')">
                                <i class="fas fa-users me-1"></i>Share to All
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Share to individual user -->
                <form method="POST" class="row g-2 mb-3">
                    <input type="hidden" name="action" value="share_device">
                    <input type="hidden" name="ap_id" value="<?php echo $ap['id']; ?>">
                    <div class="col-auto flex-grow-1">
                        <select name="share_user_id" class="form-select form-select-sm" required>
                            <option value="">Select Resident...</option>
                            <?php foreach ($society_residents as $r): ?>
                                <option value="<?php echo $r['id']; ?>">
                                    <?php echo htmlspecialchars($r['name']); ?> (Flat <?php echo htmlspecialchars($r['flat_no']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fas fa-share me-1"></i>Share
                        </button>
                    </div>
                </form>

                <!-- Current shares table -->
                <?php $ap_shares = $shares_by_ap[$ap['id']] ?? []; ?>
                <?php if (!empty($ap_shares)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>User Name</th>
                                    <th>Status</th>
                                    <th>Shared At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ap_shares as $share): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($share['user_name']); ?></td>
                                        <td>
                                            <?php if ($share['status'] === 'active'): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Revoked</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M j, Y H:i', strtotime($share['shared_at'])); ?></td>
                                        <td>
                                            <?php if ($share['status'] === 'active'): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="revoke_share">
                                                    <input type="hidden" name="share_id" value="<?php echo $share['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                                            onclick="return confirm('Revoke this share?')">
                                                        <i class="fas fa-times me-1"></i>Revoke
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-0">No shares yet for this device.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Add Access Point Modal -->
<div class="modal fade" id="addAPModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">Add Access Point</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="ap_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select name="ap_type" class="form-select">
                            <option value="main_gate">Main Gate</option>
                            <option value="tower_gate">Tower Gate</option>
                            <option value="door">Door</option>
                            <option value="parking_gate">Parking Gate</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Location</label>
                        <input type="text" name="ap_location" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Device ID</label>
                        <input type="text" name="device_id" class="form-control">
                    </div>
                    <hr>
                    <small class="text-muted d-block mb-2">Tuya Device Settings (Optional)</small>
                    <div class="mb-3">
                        <label class="form-label">Device Category</label>
                        <select name="device_category" class="form-select">
                            <option value="">-- None --</option>
                            <option value="lock">Lock</option>
                            <option value="camera">Camera</option>
                            <option value="sensor">Sensor</option>
                        </select>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="is_common" value="1" class="form-check-input" id="add_is_common">
                        <label class="form-check-label" for="add_is_common">Common Door - accessible by all residents</label>
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

<!-- Edit Access Point Modal -->
<div class="modal fade" id="editAPModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="ap_id" id="edit_ap_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Access Point</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="ap_name" id="edit_ap_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select name="ap_type" id="edit_ap_type" class="form-select">
                            <option value="main_gate">Main Gate</option>
                            <option value="tower_gate">Tower Gate</option>
                            <option value="door">Door</option>
                            <option value="parking_gate">Parking Gate</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Location</label>
                        <input type="text" name="ap_location" id="edit_ap_location" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Device ID</label>
                        <input type="text" name="device_id" id="edit_ap_device" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="ap_status" id="edit_ap_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="maintenance">Maintenance</option>
                        </select>
                    </div>
                    <hr>
                    <small class="text-muted d-block mb-2">Tuya Device Settings (Optional)</small>
                    <div class="mb-3">
                        <label class="form-label">Device Category</label>
                        <select name="device_category" id="edit_device_category" class="form-select">
                            <option value="">-- None --</option>
                            <option value="lock">Lock</option>
                            <option value="camera">Camera</option>
                            <option value="sensor">Sensor</option>
                        </select>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="is_common" value="1" class="form-check-input" id="edit_is_common">
                        <label class="form-check-label" for="edit_is_common">Common Door - accessible by all residents</label>
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
    $('.edit-ap').on('click', function() {
        $('#edit_ap_id').val($(this).data('id'));
        $('#edit_ap_name').val($(this).data('name'));
        $('#edit_ap_type').val($(this).data('type'));
        $('#edit_ap_location').val($(this).data('location'));
        $('#edit_ap_device').val($(this).data('device'));
        $('#edit_ap_status').val($(this).data('status'));
        $('#edit_device_category').val($(this).data('category') || '');
        $('#edit_is_common').prop('checked', $(this).data('common') == 1);
        new bootstrap.Modal($('#editAPModal')).show();
    });
});
</script>
