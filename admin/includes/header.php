<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - ' : ''; ?>Securis Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-dark bg-xrda3-primary fixed-top">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <button class="sidebar-toggle-btn" id="sidebarToggle" aria-label="Toggle sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <a class="navbar-brand" href="dashboard">
                    <i class="fas fa-shield-halved"></i>
                    <span>Securis</span>
                </a>
            </div>
            <div class="d-flex align-items-center">
                <div class="dropdown">
                    <a class="nav-link dropdown-toggle text-white d-flex align-items-center gap-2" href="#" role="button" data-bs-toggle="dropdown" style="font-size:0.9rem;">
                        <div class="d-none d-sm-flex align-items-center justify-content-center rounded-circle bg-white bg-opacity-10" style="width:32px;height:32px;">
                            <i class="fas fa-user-shield" style="font-size:0.8rem;"></i>
                        </div>
                        <span class="d-none d-sm-inline"><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?></span>
                        <i class="fas fa-user-shield d-sm-none"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text text-muted small"><i class="fas fa-crown me-1 text-warning"></i>Super Admin</span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="d-flex" id="wrapper">
