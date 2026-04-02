<?php
session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['admin_id']) && $_SESSION['admin_role'] === 'super_admin') {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/../include/dbconfig.php';
require_once __DIR__ . '/../include/security.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } elseif (!validateEmail($email)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare("SELECT id, name, email, password_hash, role, status FROM tbl_admin WHERE email = ? AND role = 'super_admin' LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $admin = $result->fetch_assoc();

            if ($admin['status'] !== 'active') {
                $error = 'Your account has been deactivated. Contact support.';
            } elseif (verifyPassword($password, $admin['password_hash'])) {
                // Set session
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['name'];
                $_SESSION['admin_email'] = $admin['email'];
                $_SESSION['admin_role'] = $admin['role'];

                // Update last login
                $updateStmt = $conn->prepare("UPDATE tbl_admin SET last_login = NOW() WHERE id = ?");
                $updateStmt->bind_param('i', $admin['id']);
                $updateStmt->execute();
                $updateStmt->close();

                // Audit log
                $logStmt = $conn->prepare("INSERT INTO tbl_audit_log (admin_id, action, entity_type, entity_id, ip_address, details) VALUES (?, 'login', 'admin', ?, ?, ?)");
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $details = json_encode(['email' => $admin['email']]);
                $logStmt->bind_param('iiss', $admin['id'], $admin['id'], $ip, $details);
                $logStmt->execute();
                $logStmt->close();

                header('Location: dashboard');
                exit;
            } else {
                $error = 'Invalid email or password.';
            }
        } else {
            $error = 'Invalid email or password.';
        }
        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Securis Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="p-0">
    <div class="login-container">
        <div class="login-card">
            <div class="text-center mb-4">
                <i class="fas fa-shield-halved fa-3x" style="color: var(--xrda3-highlight);"></i>
                <h4 class="brand-title mt-3">Securis Admin</h4>
                <p class="brand-subtitle">Smart Society Management</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-sm py-2">
                    <i class="fas fa-exclamation-circle me-1"></i><?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['flash_error'])): ?>
                <div class="alert alert-danger alert-sm py-2">
                    <i class="fas fa-exclamation-circle me-1"></i><?php echo htmlspecialchars($_SESSION['flash_error']); ?>
                </div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>

            <form method="POST" action="index">
                <div class="mb-3">
                    <label for="email" class="form-label">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                               placeholder="admin@xrda3.com" required>
                    </div>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="Enter password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-xrda3 w-100 py-2">
                    <i class="fas fa-sign-in-alt me-2"></i>Sign In
                </button>
            </form>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
