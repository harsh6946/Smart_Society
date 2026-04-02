<?php
require_once 'includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';
require_once __DIR__ . '/../include/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_slot') {
        $slot_number = trim($_POST['slot_number'] ?? '');
        $slot_type = $_POST['slot_type'] ?? 'covered';
        $floor = trim($_POST['floor'] ?? '');
        $location = trim($_POST['location'] ?? '');
        if ($slot_number !== '') {
            $stmt = $conn->prepare(
                "INSERT INTO tbl_parking_slot (society_id, slot_number, slot_type, floor, location, status)
                 VALUES (?, ?, ?, ?, ?, 'available')"
            );
            $stmt->bind_param('issss', $society_id, $slot_number, $slot_type, $floor, $location);
            if ($stmt->execute()) {
                $_SESSION['flash_success'] = "Parking slot $slot_number added.";
            } else {
                $_SESSION['flash_error'] = 'Failed to add slot. Number may already exist.';
            }
            $stmt->close();
        }
    } elseif ($action === 'assign') {
        $slot_id = intval($_POST['slot_id'] ?? 0);
        $flat_id = intval($_POST['flat_id'] ?? 0);
        if ($slot_id > 0 && $flat_id > 0) {
            $stmt = $conn->prepare(
                "UPDATE tbl_parking_slot SET assigned_flat_id = ?, status = 'assigned'
                 WHERE id = ? AND society_id = ?"
            );
            $stmt->bind_param('iii', $flat_id, $slot_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Slot assigned to flat.';
        }
    } elseif ($action === 'unassign') {
        $slot_id = intval($_POST['slot_id'] ?? 0);
        if ($slot_id > 0) {
            $stmt = $conn->prepare(
                "UPDATE tbl_parking_slot SET assigned_flat_id = NULL, status = 'available'
                 WHERE id = ? AND society_id = ?"
            );
            $stmt->bind_param('ii', $slot_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Slot unassigned.';
        }
    } elseif ($action === 'delete_slot') {
        $slot_id = intval($_POST['slot_id'] ?? 0);
        if ($slot_id > 0) {
            $stmt = $conn->prepare(
                "DELETE FROM tbl_parking_slot WHERE id = ? AND society_id = ? AND assigned_flat_id IS NULL"
            );
            $stmt->bind_param('ii', $slot_id, $society_id);
            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                $_SESSION['flash_success'] = 'Slot deleted.';
            } else {
                $_SESSION['flash_error'] = 'Cannot delete assigned slot. Unassign first.';
            }
            $stmt->close();
        }
    }

    header('Location: parking');
    exit;
}

// Get all parking slots with assigned flat info
$stmt = $conn->prepare(
    "SELECT ps.*, f.flat_number, t.name AS tower_name
     FROM tbl_parking_slot ps
     LEFT JOIN tbl_flat f ON ps.assigned_flat_id = f.id
     LEFT JOIN tbl_tower t ON f.tower_id = t.id
     WHERE ps.society_id = ?
     ORDER BY ps.slot_number"
);
$stmt->bind_param('i', $society_id);
$stmt->execute();
$slots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get all flats for assignment dropdown
$stmt = $conn->prepare(
    "SELECT f.id, f.flat_number, t.name AS tower_name
     FROM tbl_flat f
     JOIN tbl_tower t ON f.tower_id = t.id
     WHERE t.society_id = ?
     ORDER BY t.name, f.flat_number"
);
$stmt->bind_param('i', $society_id);
$stmt->execute();
$flats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get recent violations
$stmt = $conn->prepare(
    "SELECT pv.*, ps.slot_number, u.name AS reported_by_name
     FROM tbl_parking_violation pv
     LEFT JOIN tbl_parking_slot ps ON pv.slot_id = ps.id
     LEFT JOIN tbl_user u ON pv.reported_by = u.id
     WHERE pv.society_id = ?
     ORDER BY pv.created_at DESC
     LIMIT 20"
);
$stmt->bind_param('i', $society_id);
$stmt->execute();
$violations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Stats
$total_slots = count($slots);
$assigned_count = count(array_filter($slots, fn($s) => $s['assigned_flat_id'] !== null));
$available_count = $total_slots - $assigned_count;

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-car me-2"></i>Parking Management</h4>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSlotModal">
        <i class="fas fa-plus me-1"></i>Add Slot
    </button>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card stat-card border-left-primary">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div><div class="text-muted small">TOTAL SLOTS</div><h4 class="mb-0"><?php echo $total_slots; ?></h4></div>
                <i class="fas fa-parking fa-2x text-primary opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card border-left-success">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div><div class="text-muted small">ASSIGNED</div><h4 class="mb-0"><?php echo $assigned_count; ?></h4></div>
                <i class="fas fa-check-circle fa-2x text-success opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card border-left-warning">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div><div class="text-muted small">AVAILABLE</div><h4 class="mb-0"><?php echo $available_count; ?></h4></div>
                <i class="fas fa-circle fa-2x text-warning opacity-50"></i>
            </div>
        </div>
    </div>
</div>

<!-- Slots Table -->
<div class="card table-card mb-4">
    <div class="card-header"><h6 class="mb-0">Parking Slots</h6></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Slot #</th>
                        <th>Type</th>
                        <th>Floor</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Assigned To</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($slots)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No parking slots configured. Click "Add Slot" to create one.</td></tr>
                    <?php else: ?>
                        <?php foreach ($slots as $slot): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($slot['slot_number']); ?></strong></td>
                                <td><span class="badge bg-secondary"><?php echo ucfirst($slot['slot_type']); ?></span></td>
                                <td><?php echo htmlspecialchars($slot['floor'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($slot['location'] ?? '-'); ?></td>
                                <td>
                                    <?php if ($slot['assigned_flat_id']): ?>
                                        <span class="badge bg-success">Assigned</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Available</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($slot['assigned_flat_id']): ?>
                                        <strong><?php echo htmlspecialchars($slot['tower_name'] . ' - ' . $slot['flat_number']); ?></strong>
                                        <form method="POST" class="d-inline ms-2">
                                            <input type="hidden" name="action" value="unassign">
                                            <input type="hidden" name="slot_id" value="<?php echo $slot['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Unassign this slot?')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" class="d-flex gap-1 align-items-center">
                                            <input type="hidden" name="action" value="assign">
                                            <input type="hidden" name="slot_id" value="<?php echo $slot['id']; ?>">
                                            <select name="flat_id" class="form-select form-select-sm" style="max-width: 200px;" required>
                                                <option value="">Select Flat...</option>
                                                <?php foreach ($flats as $f): ?>
                                                    <option value="<?php echo $f['id']; ?>"><?php echo htmlspecialchars($f['tower_name'] . ' - ' . $f['flat_number']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-link"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$slot['assigned_flat_id']): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="delete_slot">
                                            <input type="hidden" name="slot_id" value="<?php echo $slot['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this slot?')">
                                                <i class="fas fa-trash"></i>
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
</div>

<!-- Recent Violations -->
<div class="card table-card">
    <div class="card-header"><h6 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Recent Violations</h6></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Vehicle</th>
                        <th>Slot</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Reported By</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($violations)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No violations reported</td></tr>
                    <?php else: ?>
                        <?php foreach ($violations as $v): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($v['vehicle_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($v['slot_number'] ?? '-'); ?></td>
                                <td><span class="badge bg-danger"><?php echo ucfirst(str_replace('_', ' ', $v['violation_type'])); ?></span></td>
                                <td><?php echo htmlspecialchars(substr($v['description'] ?? '', 0, 60)); ?></td>
                                <td><?php echo htmlspecialchars($v['reported_by_name'] ?? 'Unknown'); ?></td>
                                <td><span class="badge badge-<?php echo $v['status']; ?>"><?php echo ucfirst($v['status']); ?></span></td>
                                <td><?php echo formatDate($v['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Slot Modal -->
<div class="modal fade" id="addSlotModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_slot">
                <div class="modal-header">
                    <h5 class="modal-title">Add Parking Slot</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Slot Number</label>
                        <input type="text" name="slot_number" class="form-control" placeholder="e.g. A-01, B-12" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select name="slot_type" class="form-select">
                            <option value="covered">Covered</option>
                            <option value="open">Open</option>
                            <option value="basement">Basement</option>
                            <option value="visitor">Visitor</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Floor / Level</label>
                        <input type="text" name="floor" class="form-control" placeholder="e.g. B1, G, 1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Location</label>
                        <input type="text" name="location" class="form-control" placeholder="e.g. Near Tower A entrance">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Slot</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
