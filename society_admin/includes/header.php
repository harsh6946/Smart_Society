<?php
$society_name = 'Society';
$stmt = $conn->prepare("SELECT name FROM tbl_society WHERE id = ?");
$stmt->bind_param('i', $society_id);
$stmt->execute();
$soc_result = $stmt->get_result();
if ($soc_row = $soc_result->fetch_assoc()) {
    $society_name = $soc_row['name'];
}
$stmt->close();

$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($society_name); ?> - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top main-navbar">
        <div class="container-fluid">
            <button class="btn btn-dark me-2 d-lg-none" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
            <a class="navbar-brand" href="dashboard">
                <i class="fas fa-building me-2"></i><?php echo htmlspecialchars($society_name); ?>
            </a>
            <div class="ms-auto d-flex align-items-center">
                <span class="text-light me-3 d-none d-md-inline">
                    <i class="fas fa-user-circle me-1"></i>
                    <?php echo htmlspecialchars($admin_name); ?>
                    <small class="text-muted ms-1">(<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $admin_role))); ?>)</small>
                </span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt me-1"></i>Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="wrapper">
