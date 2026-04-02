<?php
session_start();

if (isset($_SESSION['admin_id']) && isset($_SESSION['society_id'])) {
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
    } else {
        $stmt = $conn->prepare(
            "SELECT a.id, a.name, a.email, a.password_hash, a.role, a.society_id, a.status, s.name as society_name
             FROM tbl_admin a
             LEFT JOIN tbl_society s ON a.society_id = s.id
             WHERE a.email = ? AND a.role IN ('society_admin','committee_member') AND a.society_id IS NOT NULL"
        );
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            if ($row['status'] !== 'active') {
                $error = 'Your account is inactive. Please contact support.';
            } elseif (verifyPassword($password, $row['password_hash'])) {
                $_SESSION['admin_id'] = $row['id'];
                $_SESSION['admin_name'] = $row['name'];
                $_SESSION['admin_role'] = $row['role'];
                $_SESSION['society_id'] = $row['society_id'];
                $_SESSION['society_name'] = $row['society_name'];

                $update = $conn->prepare("UPDATE tbl_admin SET last_login = NOW() WHERE id = ?");
                $update->bind_param('i', $row['id']);
                $update->execute();
                $update->close();

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

$flash_error = '';
if (isset($_SESSION['flash_error'])) {
    $flash_error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Society Admin - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <div class="login-wrapper">
        <div class="card login-card">
            <div class="card-body text-center">
                <div class="login-logo mb-3">
                    <i class="fas fa-building-shield"></i>
                </div>
                <h4 class="mb-1">Securis Society Admin</h4>
                <p class="text-muted mb-4">Sign in to your society panel</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger py-2"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($flash_error): ?>
                    <div class="alert alert-danger py-2"><?php echo htmlspecialchars($flash_error); ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="mb-3 text-start">
                        <label class="form-label">Email Address</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                            <input type="email" name="email" class="form-control" placeholder="admin@society.com"
                                   value="<?php echo htmlspecialchars($email ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="mb-4 text-start">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2">
                        <i class="fas fa-sign-in-alt me-2"></i>Sign In
                    </button>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
