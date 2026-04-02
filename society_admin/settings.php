<?php
require_once 'includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';
require_once __DIR__ . '/../include/helpers.php';
require_once __DIR__ . '/../include/security.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['society_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $pincode = trim($_POST['pincode'] ?? '');

        if ($name !== '') {
            $stmt = $conn->prepare(
                "UPDATE tbl_society SET name = ?, address = ?, city = ?, state = ?, pincode = ?, updated_at = NOW() WHERE id = ?"
            );
            $stmt->bind_param('sssssi', $name, $address, $city, $state, $pincode, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['society_name'] = $name;
            $_SESSION['flash_success'] = 'Society profile updated.';
        } else {
            $_SESSION['flash_error'] = 'Society name is required.';
        }
    } elseif ($action === 'regenerate_invite') {
        $new_code = generateInviteCode();
        $stmt = $conn->prepare("UPDATE tbl_society SET invite_code = ? WHERE id = ?");
        $stmt->bind_param('si', $new_code, $society_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash_success'] = 'Invite code regenerated: ' . $new_code;
    } elseif ($action === 'update_atmosphere') {
        $atm_type = trim($_POST['atmosphere_type'] ?? 'auto');
        $atm_intensity = trim($_POST['atmosphere_intensity'] ?? 'normal');
        $atm_message = trim($_POST['atmosphere_message'] ?? '');
        $atm_hours = intval($_POST['duration_hours'] ?? 24);

        $expires = ($atm_type === 'auto') ? null : date('Y-m-d H:i:s', strtotime("+{$atm_hours} hours"));
        $msg_val = empty($atm_message) ? null : $atm_message;

        $stmt = $conn->prepare(
            "UPDATE tbl_society SET atmosphere_type = ?, atmosphere_intensity = ?,
             atmosphere_message = ?, atmosphere_expires_at = ? WHERE id = ?"
        );
        $stmt->bind_param('ssssi', $atm_type, $atm_intensity, $msg_val, $expires, $society_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash_success'] = 'Atmosphere updated to: ' . ucfirst(str_replace('_', ' ', $atm_type));
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($new_pass !== $confirm) {
            $_SESSION['flash_error'] = 'New passwords do not match.';
        } elseif (strlen($new_pass) < 8) {
            $_SESSION['flash_error'] = 'Password must be at least 8 characters.';
        } else {
            $stmt = $conn->prepare("SELECT password_hash FROM tbl_admin WHERE id = ?");
            $stmt->bind_param('i', $admin_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row && verifyPassword($current, $row['password_hash'])) {
                $new_hash = hashPassword($new_pass);
                $stmt = $conn->prepare("UPDATE tbl_admin SET password_hash = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param('si', $new_hash, $admin_id);
                $stmt->execute();
                $stmt->close();
                $_SESSION['flash_success'] = 'Password changed successfully.';
            } else {
                $_SESSION['flash_error'] = 'Current password is incorrect.';
            }
        }
    }

    header('Location: settings');
    exit;
}

// Get society details
$stmt = $conn->prepare("SELECT * FROM tbl_society WHERE id = ?");
$stmt->bind_param('i', $society_id);
$stmt->execute();
$society = $stmt->get_result()->fetch_assoc();
$stmt->close();

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<h4 class="mb-4"><i class="fas fa-cog me-2"></i>Settings</h4>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card table-card mb-4">
            <div class="card-header"><h6 class="mb-0">Society Profile</h6></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="mb-3">
                        <label class="form-label">Society Name</label>
                        <input type="text" name="society_name" class="form-control"
                               value="<?php echo htmlspecialchars($society['name']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="3"><?php echo htmlspecialchars($society['address'] ?? ''); ?></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">City</label>
                            <input type="text" name="city" class="form-control"
                                   value="<?php echo htmlspecialchars($society['city'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">State</label>
                            <input type="text" name="state" class="form-control"
                                   value="<?php echo htmlspecialchars($society['state'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Pincode</label>
                            <input type="text" name="pincode" class="form-control"
                                   value="<?php echo htmlspecialchars($society['pincode'] ?? ''); ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Save Changes
                    </button>
                </form>
            </div>
        </div>

        <div class="card table-card">
            <div class="card-header"><h6 class="mb-0">Change Admin Password</h6></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" required minlength="8">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="8">
                    </div>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-key me-1"></i>Change Password
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card table-card">
            <div class="card-header"><h6 class="mb-0">Invite Code</h6></div>
            <div class="card-body text-center">
                <p class="text-muted small mb-2">Share this code with residents to join your society</p>
                <div class="bg-light rounded p-3 mb-3">
                    <h3 class="mb-0 text-primary fw-bold letter-spacing-2">
                        <?php echo htmlspecialchars($society['invite_code'] ?? 'N/A'); ?>
                    </h3>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="regenerate_invite">
                    <button type="submit" class="btn btn-outline-primary btn-sm"
                            onclick="return confirm('Regenerate invite code? The old code will stop working.')">
                        <i class="fas fa-sync-alt me-1"></i>Regenerate Code
                    </button>
                </form>
            </div>
        </div>

        <div class="card table-card mt-3">
            <div class="card-header"><h6 class="mb-0">🎨 App Atmosphere</h6></div>
            <div class="card-body">
                <p class="text-muted small mb-3">Control the animated header in the resident app. Set weather effects, celebrations, or let it auto-adjust by time of day.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="update_atmosphere">
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Atmosphere Type</label>
                        <select name="atmosphere_type" class="form-select form-select-sm">
                            <option value="auto" <?php echo ($society['atmosphere_type'] ?? 'auto') === 'auto' ? 'selected' : ''; ?>>🕐 Auto (Time-based)</option>
                            <optgroup label="Weather">
                                <option value="rain" <?php echo ($society['atmosphere_type'] ?? '') === 'rain' ? 'selected' : ''; ?>>🌧️ Rain</option>
                                <option value="heat_wave" <?php echo ($society['atmosphere_type'] ?? '') === 'heat_wave' ? 'selected' : ''; ?>>🔥 Extreme Heat</option>
                                <option value="snow" <?php echo ($society['atmosphere_type'] ?? '') === 'snow' ? 'selected' : ''; ?>>❄️ Snow</option>
                            </optgroup>
                            <optgroup label="Celebrations">
                                <option value="party" <?php echo ($society['atmosphere_type'] ?? '') === 'party' ? 'selected' : ''; ?>>🎉 Party / Celebration</option>
                                <option value="festival" <?php echo ($society['atmosphere_type'] ?? '') === 'festival' ? 'selected' : ''; ?>>🎊 Festival</option>
                                <option value="diwali" <?php echo ($society['atmosphere_type'] ?? '') === 'diwali' ? 'selected' : ''; ?>>🪔 Diwali</option>
                                <option value="holi" <?php echo ($society['atmosphere_type'] ?? '') === 'holi' ? 'selected' : ''; ?>>🎨 Holi</option>
                                <option value="christmas" <?php echo ($society['atmosphere_type'] ?? '') === 'christmas' ? 'selected' : ''; ?>>🎄 Christmas</option>
                                <option value="newyear" <?php echo ($society['atmosphere_type'] ?? '') === 'newyear' ? 'selected' : ''; ?>>🎆 New Year</option>
                                <option value="independence_day" <?php echo ($society['atmosphere_type'] ?? '') === 'independence_day' ? 'selected' : ''; ?>>🇮🇳 Independence Day</option>
                                <option value="eid" <?php echo ($society['atmosphere_type'] ?? '') === 'eid' ? 'selected' : ''; ?>>🌙 Eid</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Intensity</label>
                        <select name="atmosphere_intensity" class="form-select form-select-sm">
                            <option value="light" <?php echo ($society['atmosphere_intensity'] ?? '') === 'light' ? 'selected' : ''; ?>>Light</option>
                            <option value="normal" <?php echo ($society['atmosphere_intensity'] ?? 'normal') === 'normal' ? 'selected' : ''; ?>>Normal</option>
                            <option value="heavy" <?php echo ($society['atmosphere_intensity'] ?? '') === 'heavy' ? 'selected' : ''; ?>>Heavy</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Banner Message (optional)</label>
                        <input type="text" name="atmosphere_message" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($society['atmosphere_message'] ?? ''); ?>"
                               placeholder="e.g. Happy Diwali! 🪔">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Duration (hours)</label>
                        <input type="number" name="duration_hours" class="form-control form-control-sm"
                               value="24" min="1" max="168">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-magic me-1"></i>Set Atmosphere
                    </button>
                </form>
            </div>
        </div>

        <div class="card table-card mt-3">
            <div class="card-header"><h6 class="mb-0">Society Info</h6></div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted">Plan</td>
                        <td><span class="badge bg-primary"><?php echo ucfirst($society['subscription_plan'] ?? 'free'); ?></span></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Status</td>
                        <td><span class="badge badge-<?php echo $society['status']; ?>"><?php echo ucfirst($society['status']); ?></span></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Created</td>
                        <td><?php echo formatDate($society['created_at']); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
