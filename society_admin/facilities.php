<?php
require_once 'includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';
require_once __DIR__ . '/../include/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_facility') {
        $name = trim($_POST['fac_name'] ?? '');
        $description = trim($_POST['fac_description'] ?? '');
        $capacity = intval($_POST['fac_capacity'] ?? 0);
        if ($name !== '') {
            $stmt = $conn->prepare(
                "INSERT INTO tbl_facility (society_id, name, description, capacity)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param('issi', $society_id, $name, $description, $capacity);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Facility added.';
        }
    } elseif ($action === 'edit_facility') {
        $fac_id = intval($_POST['fac_id'] ?? 0);
        $name = trim($_POST['fac_name'] ?? '');
        $description = trim($_POST['fac_description'] ?? '');
        $capacity = intval($_POST['fac_capacity'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        if ($fac_id > 0 && $name !== '') {
            $stmt = $conn->prepare(
                "UPDATE tbl_facility SET name = ?, description = ?, capacity = ?, is_active = ? WHERE id = ? AND society_id = ?"
            );
            $stmt->bind_param('ssiiii', $name, $description, $capacity, $is_active, $fac_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Facility updated.';
        }
    } elseif ($action === 'delete_facility') {
        $fac_id = intval($_POST['fac_id'] ?? 0);
        if ($fac_id > 0) {
            $stmt = $conn->prepare("DELETE FROM tbl_facility WHERE id = ? AND society_id = ?");
            $stmt->bind_param('ii', $fac_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Facility deleted.';
        }
    } elseif ($action === 'approve_booking' || $action === 'reject_booking') {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $new_status = ($action === 'approve_booking') ? 'approved' : 'rejected';
        if ($booking_id > 0) {
            $stmt = $conn->prepare(
                "UPDATE tbl_facility_booking SET status = ?, approved_by = ?
                 WHERE id = ? AND facility_id IN (SELECT id FROM tbl_facility WHERE society_id = ?)"
            );
            $stmt->bind_param('siii', $new_status, $admin_id, $booking_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Booking ' . $new_status . '.';
        }
    }

    header('Location: facilities');
    exit;
}

// Get facilities
$stmt = $conn->prepare("SELECT * FROM tbl_facility WHERE society_id = ? ORDER BY name");
$stmt->bind_param('i', $society_id);
$stmt->execute();
$facilities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get bookings
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

$stmt = $conn->prepare(
    "SELECT fb.*, fac.name as facility_name, u.name as resident_name, f.flat_number, tw.name as tower_name
     FROM tbl_facility_booking fb
     JOIN tbl_facility fac ON fb.facility_id = fac.id
     JOIN tbl_resident r ON fb.resident_id = r.id
     JOIN tbl_user u ON r.user_id = u.id
     JOIN tbl_flat f ON r.flat_id = f.id
     JOIN tbl_tower tw ON f.tower_id = tw.id
     WHERE fac.society_id = ?
     ORDER BY fb.booking_date DESC, fb.start_time DESC
     LIMIT ? OFFSET ?"
);
$stmt->bind_param('iii', $society_id, $per_page, $offset);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT COUNT(*) as cnt FROM tbl_facility_booking fb
     JOIN tbl_facility fac ON fb.facility_id = fac.id
     WHERE fac.society_id = ?"
);
$stmt->bind_param('i', $society_id);
$stmt->execute();
$total_bookings = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
$total_pages = max(1, ceil($total_bookings / $per_page));

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-swimming-pool me-2"></i>Facilities</h4>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addFacilityModal">
        <i class="fas fa-plus me-1"></i>Add Facility
    </button>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($facilities as $fac): ?>
        <div class="col-md-4 col-lg-3">
            <div class="card stat-card border-left-<?php echo $fac['is_active'] ? 'success' : 'secondary'; ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="mb-0"><?php echo htmlspecialchars($fac['name']); ?></h6>
                        <?php if (!$fac['is_active']): ?>
                            <span class="badge bg-secondary">Inactive</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($fac['description']): ?>
                        <p class="small text-muted mb-2 text-truncate-2"><?php echo htmlspecialchars($fac['description']); ?></p>
                    <?php endif; ?>
                    <?php if ($fac['capacity'] > 0): ?>
                        <small class="text-muted"><i class="fas fa-users me-1"></i>Capacity: <?php echo $fac['capacity']; ?></small>
                    <?php endif; ?>
                    <div class="mt-2 d-flex gap-1">
                        <button class="btn btn-sm btn-outline-primary edit-facility"
                                data-id="<?php echo $fac['id']; ?>"
                                data-name="<?php echo htmlspecialchars($fac['name']); ?>"
                                data-description="<?php echo htmlspecialchars($fac['description'] ?? ''); ?>"
                                data-capacity="<?php echo $fac['capacity']; ?>"
                                data-active="<?php echo $fac['is_active']; ?>">
                            <i class="fas fa-edit"></i>
                        </button>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="delete_facility">
                            <input type="hidden" name="fac_id" value="<?php echo $fac['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    onclick="return confirm('Delete this facility?')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($facilities)): ?>
        <div class="col-12"><p class="text-muted text-center py-4">No facilities added yet.</p></div>
    <?php endif; ?>
</div>

<div class="card table-card">
    <div class="card-header"><h6 class="mb-0">Bookings</h6></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Facility</th>
                        <th>Resident</th>
                        <th>Flat</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Purpose</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bookings)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No bookings</td></tr>
                    <?php else: ?>
                        <?php foreach ($bookings as $b): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($b['facility_name']); ?></td>
                                <td><?php echo htmlspecialchars($b['resident_name']); ?></td>
                                <td><?php echo htmlspecialchars($b['tower_name'] . '-' . $b['flat_number']); ?></td>
                                <td><?php echo formatDate($b['booking_date']); ?></td>
                                <td><?php echo date('H:i', strtotime($b['start_time'])) . ' - ' . date('H:i', strtotime($b['end_time'])); ?></td>
                                <td class="text-truncate" style="max-width:120px"><?php echo htmlspecialchars($b['purpose'] ?? '-'); ?></td>
                                <td><span class="badge badge-<?php echo $b['status']; ?>"><?php echo ucfirst($b['status']); ?></span></td>
                                <td>
                                    <?php if ($b['status'] === 'pending'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="approve_booking">
                                            <input type="hidden" name="booking_id" value="<?php echo $b['id']; ?>">
                                            <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i></button>
                                        </form>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="reject_booking">
                                            <input type="hidden" name="booking_id" value="<?php echo $b['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-times"></i></button>
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
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>">Prev</a>
                    </li>
                    <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $p; ?>"><?php echo $p; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>">Next</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<!-- Add Facility Modal -->
<div class="modal fade" id="addFacilityModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_facility">
                <div class="modal-header">
                    <h5 class="modal-title">Add Facility</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="fac_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="fac_description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Capacity</label>
                        <input type="number" name="fac_capacity" class="form-control" value="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Facility</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Facility Modal -->
<div class="modal fade" id="editFacilityModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit_facility">
                <input type="hidden" name="fac_id" id="edit_fac_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Facility</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="fac_name" id="edit_fac_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="fac_description" id="edit_fac_description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Capacity</label>
                        <input type="number" name="fac_capacity" id="edit_fac_capacity" class="form-control">
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_active" id="edit_fac_active" class="form-check-input" value="1">
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
    $('.edit-facility').on('click', function() {
        $('#edit_fac_id').val($(this).data('id'));
        $('#edit_fac_name').val($(this).data('name'));
        $('#edit_fac_description').val($(this).data('description'));
        $('#edit_fac_capacity').val($(this).data('capacity'));
        $('#edit_fac_active').prop('checked', $(this).data('active') == 1);
        new bootstrap.Modal($('#editFacilityModal')).show();
    });
});
</script>
