<?php
require_once 'includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';
require_once __DIR__ . '/../include/helpers.php';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_tower') {
        $name = trim($_POST['tower_name'] ?? '');
        $total_floors = intval($_POST['total_floors'] ?? 0);
        if ($name !== '') {
            $stmt = $conn->prepare("INSERT INTO tbl_tower (society_id, name, total_floors) VALUES (?, ?, ?)");
            $stmt->bind_param('isi', $society_id, $name, $total_floors);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Tower added successfully.';
        } else {
            $_SESSION['flash_error'] = 'Tower name is required.';
        }
    } elseif ($action === 'edit_tower') {
        $tower_id = intval($_POST['tower_id'] ?? 0);
        $name = trim($_POST['tower_name'] ?? '');
        $total_floors = intval($_POST['total_floors'] ?? 0);
        if ($tower_id > 0 && $name !== '') {
            $stmt = $conn->prepare("UPDATE tbl_tower SET name = ?, total_floors = ? WHERE id = ? AND society_id = ?");
            $stmt->bind_param('siii', $name, $total_floors, $tower_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Tower updated successfully.';
        }
    } elseif ($action === 'delete_tower') {
        $tower_id = intval($_POST['tower_id'] ?? 0);
        if ($tower_id > 0) {
            $stmt = $conn->prepare("DELETE FROM tbl_tower WHERE id = ? AND society_id = ?");
            $stmt->bind_param('ii', $tower_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Tower deleted successfully.';
        }
    } elseif ($action === 'add_flat') {
        $tower_id = intval($_POST['tower_id'] ?? 0);
        $flat_number = trim($_POST['flat_number'] ?? '');
        $floor = intval($_POST['floor'] ?? 0);
        $type = trim($_POST['flat_type'] ?? '');
        $area = floatval($_POST['area_sqft'] ?? 0);
        if ($tower_id > 0 && $flat_number !== '') {
            $stmt = $conn->prepare("INSERT INTO tbl_flat (tower_id, flat_number, floor, type, area_sqft) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('isisd', $tower_id, $flat_number, $floor, $type, $area);
            $stmt->execute();
            $stmt->close();
            // Update tower flat count
            $stmt = $conn->prepare("UPDATE tbl_tower SET total_flats = (SELECT COUNT(*) FROM tbl_flat WHERE tower_id = ?) WHERE id = ?");
            $stmt->bind_param('ii', $tower_id, $tower_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Flat added successfully.';
        }
    } elseif ($action === 'edit_flat') {
        $flat_id = intval($_POST['flat_id'] ?? 0);
        $flat_number = trim($_POST['flat_number'] ?? '');
        $floor = intval($_POST['floor'] ?? 0);
        $type = trim($_POST['flat_type'] ?? '');
        $area = floatval($_POST['area_sqft'] ?? 0);
        $status = $_POST['flat_status'] ?? 'vacant';
        if ($flat_id > 0 && $flat_number !== '') {
            $stmt = $conn->prepare("UPDATE tbl_flat SET flat_number = ?, floor = ?, type = ?, area_sqft = ?, status = ? WHERE id = ?");
            $stmt->bind_param('sisds' . 'i', $flat_number, $floor, $type, $area, $status, $flat_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Flat updated successfully.';
        }
    } elseif ($action === 'delete_flat') {
        $flat_id = intval($_POST['flat_id'] ?? 0);
        $tower_id_for_update = intval($_POST['tower_id'] ?? 0);
        if ($flat_id > 0) {
            $stmt = $conn->prepare(
                "DELETE f FROM tbl_flat f JOIN tbl_tower t ON f.tower_id = t.id WHERE f.id = ? AND t.society_id = ?"
            );
            $stmt->bind_param('ii', $flat_id, $society_id);
            $stmt->execute();
            $stmt->close();
            if ($tower_id_for_update > 0) {
                $stmt = $conn->prepare("UPDATE tbl_tower SET total_flats = (SELECT COUNT(*) FROM tbl_flat WHERE tower_id = ?) WHERE id = ?");
                $stmt->bind_param('ii', $tower_id_for_update, $tower_id_for_update);
                $stmt->execute();
                $stmt->close();
            }
            $_SESSION['flash_success'] = 'Flat deleted successfully.';
        }
    }

    $redirect = 'towers';
    if (isset($_POST['tower_id']) && in_array($action, ['add_flat', 'edit_flat', 'delete_flat'])) {
        $redirect .= '?tower_id=' . intval($_POST['tower_id'] ?? 0);
    }
    header('Location: ' . $redirect);
    exit;
}

// Get towers
$stmt = $conn->prepare(
    "SELECT t.*, (SELECT COUNT(*) FROM tbl_flat WHERE tower_id = t.id) as flat_count
     FROM tbl_tower t WHERE t.society_id = ? ORDER BY t.name"
);
$stmt->bind_param('i', $society_id);
$stmt->execute();
$towers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// If viewing a specific tower's flats
$selected_tower = null;
$flats = [];
$view_tower_id = intval($_GET['tower_id'] ?? 0);
if ($view_tower_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM tbl_tower WHERE id = ? AND society_id = ?");
    $stmt->bind_param('ii', $view_tower_id, $society_id);
    $stmt->execute();
    $selected_tower = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($selected_tower) {
        $stmt = $conn->prepare("SELECT * FROM tbl_flat WHERE tower_id = ? ORDER BY floor, flat_number");
        $stmt->bind_param('i', $view_tower_id);
        $stmt->execute();
        $flats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-building me-2"></i>Towers & Flats</h4>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addTowerModal">
        <i class="fas fa-plus me-1"></i>Add Tower
    </button>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($towers as $t): ?>
        <div class="col-md-4 col-lg-3">
            <div class="card stat-card border-left-info">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="mb-0"><?php echo htmlspecialchars($t['name']); ?></h6>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-link text-muted p-0" data-bs-toggle="dropdown">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item edit-tower" href="#"
                                       data-id="<?php echo $t['id']; ?>"
                                       data-name="<?php echo htmlspecialchars($t['name']); ?>"
                                       data-floors="<?php echo $t['total_floors']; ?>">
                                    <i class="fas fa-edit me-2"></i>Edit</a></li>
                                <li>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="delete_tower">
                                        <input type="hidden" name="tower_id" value="<?php echo $t['id']; ?>">
                                        <button type="submit" class="dropdown-item text-danger"
                                                onclick="return confirm('Delete this tower and all its flats?')">
                                            <i class="fas fa-trash me-2"></i>Delete</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <p class="text-muted mb-2 small">Floors: <?php echo $t['total_floors']; ?></p>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="badge bg-secondary"><?php echo $t['flat_count']; ?> Flats</span>
                        <a href="?tower_id=<?php echo $t['id']; ?>" class="btn btn-sm btn-outline-primary">View Flats</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($towers)): ?>
        <div class="col-12">
            <div class="text-center text-muted py-5">No towers added yet. Click "Add Tower" to get started.</div>
        </div>
    <?php endif; ?>
</div>

<?php if ($selected_tower): ?>
<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">
            <a href="towers" class="text-decoration-none me-2"><i class="fas fa-arrow-left"></i></a>
            Flats in <?php echo htmlspecialchars($selected_tower['name']); ?>
        </h6>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addFlatModal">
            <i class="fas fa-plus me-1"></i>Add Flat
        </button>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Flat No</th>
                        <th>Floor</th>
                        <th>Type</th>
                        <th>Area (sqft)</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($flats)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No flats added</td></tr>
                    <?php else: ?>
                        <?php foreach ($flats as $f): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($f['flat_number']); ?></td>
                                <td><?php echo $f['floor']; ?></td>
                                <td><?php echo htmlspecialchars($f['type'] ?: '-'); ?></td>
                                <td><?php echo $f['area_sqft'] > 0 ? number_format($f['area_sqft'], 0) : '-'; ?></td>
                                <td><span class="badge badge-<?php echo $f['status']; ?>"><?php echo ucfirst($f['status']); ?></span></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary edit-flat"
                                            data-id="<?php echo $f['id']; ?>"
                                            data-number="<?php echo htmlspecialchars($f['flat_number']); ?>"
                                            data-floor="<?php echo $f['floor']; ?>"
                                            data-type="<?php echo htmlspecialchars($f['type']); ?>"
                                            data-area="<?php echo $f['area_sqft']; ?>"
                                            data-status="<?php echo $f['status']; ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="delete_flat">
                                        <input type="hidden" name="flat_id" value="<?php echo $f['id']; ?>">
                                        <input type="hidden" name="tower_id" value="<?php echo $view_tower_id; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('Delete this flat?')">
                                            <i class="fas fa-trash"></i>
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

<!-- Add Flat Modal -->
<div class="modal fade" id="addFlatModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_flat">
                <input type="hidden" name="tower_id" value="<?php echo $view_tower_id; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Add Flat</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Flat Number</label>
                        <input type="text" name="flat_number" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Floor</label>
                        <input type="number" name="floor" class="form-control" value="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <input type="text" name="flat_type" class="form-control" placeholder="e.g., 2BHK, 3BHK">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Area (sqft)</label>
                        <input type="number" name="area_sqft" class="form-control" step="0.01" value="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Flat</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Flat Modal -->
<div class="modal fade" id="editFlatModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit_flat">
                <input type="hidden" name="flat_id" id="edit_flat_id">
                <input type="hidden" name="tower_id" value="<?php echo $view_tower_id; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Flat</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Flat Number</label>
                        <input type="text" name="flat_number" id="edit_flat_number" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Floor</label>
                        <input type="number" name="floor" id="edit_flat_floor" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <input type="text" name="flat_type" id="edit_flat_type" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Area (sqft)</label>
                        <input type="number" name="area_sqft" id="edit_flat_area" class="form-control" step="0.01">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="flat_status" id="edit_flat_status" class="form-select">
                            <option value="vacant">Vacant</option>
                            <option value="occupied">Occupied</option>
                            <option value="locked">Locked</option>
                        </select>
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
<?php endif; ?>

<!-- Add Tower Modal -->
<div class="modal fade" id="addTowerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_tower">
                <div class="modal-header">
                    <h5 class="modal-title">Add Tower</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tower Name</label>
                        <input type="text" name="tower_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Total Floors</label>
                        <input type="number" name="total_floors" class="form-control" value="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Tower</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Tower Modal -->
<div class="modal fade" id="editTowerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit_tower">
                <input type="hidden" name="tower_id" id="edit_tower_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Tower</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tower Name</label>
                        <input type="text" name="tower_name" id="edit_tower_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Total Floors</label>
                        <input type="number" name="total_floors" id="edit_tower_floors" class="form-control">
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
    $('.edit-tower').on('click', function(e) {
        e.preventDefault();
        $('#edit_tower_id').val($(this).data('id'));
        $('#edit_tower_name').val($(this).data('name'));
        $('#edit_tower_floors').val($(this).data('floors'));
        new bootstrap.Modal($('#editTowerModal')).show();
    });

    $('.edit-flat').on('click', function() {
        $('#edit_flat_id').val($(this).data('id'));
        $('#edit_flat_number').val($(this).data('number'));
        $('#edit_flat_floor').val($(this).data('floor'));
        $('#edit_flat_type').val($(this).data('type'));
        $('#edit_flat_area').val($(this).data('area'));
        $('#edit_flat_status').val($(this).data('status'));
        new bootstrap.Modal($('#editFlatModal')).show();
    });
});
</script>
