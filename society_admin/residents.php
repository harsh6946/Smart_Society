<?php
require_once 'includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';
require_once __DIR__ . '/../include/helpers.php';

/**
 * Share all common Tuya-bound access points in the society to a user.
 * Called when a resident is approved.
 */
function autoShareCommonDevices($conn, $userId, $societyId) {
    $stmt = $conn->prepare(
        "SELECT id, tuya_device_id FROM tbl_access_point
         WHERE society_id = ? AND is_common = 1
           AND tuya_device_id IS NOT NULL AND status = 'active'"
    );
    $stmt->bind_param('i', $societyId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($ap = $result->fetch_assoc()) {
        $shareStmt = $conn->prepare(
            "INSERT INTO tbl_tuya_device_share
                (access_point_id, shared_to_user_id, tuya_device_id, status)
             VALUES (?, ?, ?, 'active')
             ON DUPLICATE KEY UPDATE status = 'active', revoked_at = NULL"
        );
        $apId = (int)$ap['id'];
        $tuyaDevId = $ap['tuya_device_id'];
        $shareStmt->bind_param('iis', $apId, $userId, $tuyaDevId);
        $shareStmt->execute();
    }
}

/**
 * Revoke all Tuya device shares for a user in a society.
 * Called when a resident is rejected or moved out.
 */
function revokeAllShares($conn, $userId, $societyId) {
    $stmt = $conn->prepare(
        "UPDATE tbl_tuya_device_share tds
         INNER JOIN tbl_access_point ap ON ap.id = tds.access_point_id
         SET tds.status = 'revoked', tds.revoked_at = NOW()
         WHERE tds.shared_to_user_id = ? AND ap.society_id = ? AND tds.status = 'active'"
    );
    $stmt->bind_param('ii', $userId, $societyId);
    $stmt->execute();
}

// Handle approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $resident_id = intval($_POST['resident_id'] ?? 0);

    if ($resident_id > 0 && in_array($action, ['approve', 'reject'])) {
        $new_status = ($action === 'approve') ? 'approved' : 'rejected';

        // Verify resident belongs to this society
        $stmt = $conn->prepare("SELECT id, flat_id, user_id FROM tbl_resident WHERE id = ? AND society_id = ?");
        $stmt->bind_param('ii', $resident_id, $society_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($res) {
            $stmt = $conn->prepare("UPDATE tbl_resident SET status = ?, approved_by = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('sii', $new_status, $admin_id, $resident_id);
            $stmt->execute();
            $stmt->close();

            if ($action === 'approve') {
                $stmt = $conn->prepare("UPDATE tbl_flat SET status = 'occupied' WHERE id = ?");
                $stmt->bind_param('i', $res['flat_id']);
                $stmt->execute();
                $stmt->close();

                // Auto-share common Tuya devices with newly approved resident
                autoShareCommonDevices($conn, (int)$res['user_id'], $society_id);
            }

            if ($action === 'reject') {
                // Revoke all Tuya device shares for this user in this society
                revokeAllShares($conn, (int)$res['user_id'], $society_id);
            }

            $_SESSION['flash_success'] = 'Resident ' . $action . 'd successfully.';
        } else {
            $_SESSION['flash_error'] = 'Resident not found.';
        }
    }
    header('Location: residents?' . http_build_query($_GET));
    exit;
}

$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

$where = "r.society_id = ?";
$params = [$society_id];
$types = 'i';

if ($filter === 'pending') {
    $where .= " AND r.status = 'pending'";
} elseif ($filter === 'approved') {
    $where .= " AND r.status = 'approved'";
} elseif ($filter === 'rejected') {
    $where .= " AND r.status = 'rejected'";
}

if ($search !== '') {
    $where .= " AND (u.name LIKE ? OR u.phone LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
}

// Count
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_resident r JOIN tbl_user u ON r.user_id = u.id WHERE $where");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
$total_pages = max(1, ceil($total / $per_page));

// Fetch
$stmt = $conn->prepare(
    "SELECT r.id, r.resident_type, r.status, r.created_at, u.name, u.phone, u.email,
            f.flat_number, tw.name as tower_name,
            ps.slot_number AS parking_slot, ps.slot_type AS parking_type
     FROM tbl_resident r
     JOIN tbl_user u ON r.user_id = u.id
     JOIN tbl_flat f ON r.flat_id = f.id
     JOIN tbl_tower tw ON f.tower_id = tw.id
     LEFT JOIN tbl_parking_slot ps ON ps.assigned_flat_id = f.id
     WHERE $where
     ORDER BY r.created_at DESC
     LIMIT ? OFFSET ?"
);
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';
$stmt->bind_param($types, ...$params);
$stmt->execute();
$residents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-users me-2"></i>Residents</h4>
</div>

<div class="card table-card">
    <div class="card-header">
        <div class="row align-items-center">
            <div class="col-md-6">
                <ul class="nav filter-tabs">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter === 'all' ? 'active' : ''; ?>" href="?filter=all">All (<?php echo $total; ?>)</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter === 'pending' ? 'active' : ''; ?>" href="?filter=pending">Pending</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter === 'approved' ? 'active' : ''; ?>" href="?filter=approved">Approved</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter === 'rejected' ? 'active' : ''; ?>" href="?filter=rejected">Rejected</a>
                    </li>
                </ul>
            </div>
            <div class="col-md-6">
                <form method="GET" class="d-flex">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    <input type="text" name="search" class="form-control form-control-sm me-2"
                           placeholder="Search by name or phone..."
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search"></i></button>
                </form>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Tower</th>
                        <th>Flat</th>
                        <th>Type</th>
                        <th>Parking</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($residents)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No residents found</td></tr>
                    <?php else: ?>
                        <?php foreach ($residents as $i => $r): ?>
                            <tr>
                                <td><?php echo $offset + $i + 1; ?></td>
                                <td><?php echo htmlspecialchars($r['name']); ?></td>
                                <td><?php echo htmlspecialchars($r['phone']); ?></td>
                                <td><?php echo htmlspecialchars($r['tower_name']); ?></td>
                                <td><?php echo htmlspecialchars($r['flat_number']); ?></td>
                                <td><span class="badge badge-<?php echo $r['resident_type']; ?>"><?php echo ucfirst($r['resident_type']); ?></span></td>
                                <td>
                                    <?php if (!empty($r['parking_slot'])): ?>
                                        <span class="badge bg-info"><i class="fas fa-car me-1"></i><?php echo htmlspecialchars($r['parking_slot']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-<?php echo $r['status']; ?>"><?php echo ucfirst($r['status']); ?></span></td>
                                <td><?php echo formatDate($r['created_at']); ?></td>
                                <td>
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="resident_id" value="<?php echo $r['id']; ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn btn-success btn-sm" title="Approve">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="resident_id" value="<?php echo $r['id']; ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Reject"
                                                    onclick="return confirm('Reject this resident?')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
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
                        <a class="page-link" href="?filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $page - 1; ?>">Prev</a>
                    </li>
                    <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $p; ?>"><?php echo $p; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $page + 1; ?>">Next</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
