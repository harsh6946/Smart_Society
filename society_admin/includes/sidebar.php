<?php
$pending_count = 0;
$stmt_pending = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_resident WHERE society_id = ? AND status = 'pending'");
$stmt_pending->bind_param('i', $society_id);
$stmt_pending->execute();
$pending_result = $stmt_pending->get_result()->fetch_assoc();
$pending_count = $pending_result['cnt'];
$stmt_pending->close();

$menu_items = [
    ['page' => 'dashboard', 'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'badge' => 0],
    ['page' => 'residents', 'icon' => 'fas fa-users', 'label' => 'Residents', 'badge' => $pending_count],
    ['page' => 'towers', 'icon' => 'fas fa-building', 'label' => 'Towers & Flats', 'badge' => 0],
    ['page' => 'billing', 'icon' => 'fas fa-file-invoice-dollar', 'label' => 'Maintenance Billing', 'badge' => 0],
    ['page' => 'notices', 'icon' => 'fas fa-bullhorn', 'label' => 'Notices & Polls', 'badge' => 0],
    ['page' => 'complaints', 'icon' => 'fas fa-exclamation-triangle', 'label' => 'Complaints', 'badge' => 0],
    ['page' => 'facilities', 'icon' => 'fas fa-swimming-pool', 'label' => 'Facilities', 'badge' => 0],
    ['page' => 'visitors', 'icon' => 'fas fa-id-card', 'label' => 'Visitors & Access', 'badge' => 0],
    ['page' => 'access_points', 'icon' => 'fas fa-door-open', 'label' => 'Access Points', 'badge' => 0],
    ['page' => 'parking', 'icon' => 'fas fa-car', 'label' => 'Parking', 'badge' => 0],
    ['page' => 'settings', 'icon' => 'fas fa-cog', 'label' => 'Settings', 'badge' => 0],
];
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-inner">
        <ul class="sidebar-menu">
            <?php foreach ($menu_items as $item): ?>
                <li class="<?php echo ($current_page === $item['page']) ? 'active' : ''; ?>">
                    <a href="<?php echo $item['page']; ?>.php">
                        <i class="<?php echo $item['icon']; ?>"></i>
                        <span><?php echo $item['label']; ?></span>
                        <?php if ($item['badge'] > 0): ?>
                            <span class="badge bg-danger ms-auto"><?php echo $item['badge']; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</aside>
<main class="main-content">
    <div class="container-fluid py-4">
        <?php if (isset($_SESSION['flash_success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
