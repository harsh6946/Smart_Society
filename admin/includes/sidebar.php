<?php
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$menuItems = [
    ['file' => 'dashboard', 'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['file' => 'societies', 'icon' => 'fas fa-building', 'label' => 'Societies'],
    ['file' => 'subscriptions', 'icon' => 'fas fa-credit-card', 'label' => 'Subscriptions'],
    ['file' => 'admins', 'icon' => 'fas fa-users-cog', 'label' => 'Admin Users'],
    ['file' => 'feature_toggles', 'icon' => 'fas fa-toggle-on', 'label' => 'Feature Toggles'],
    ['file' => 'audit_logs', 'icon' => 'fas fa-clipboard-list', 'label' => 'Audit Logs'],
];
?>
<div class="sidebar bg-xrda3-sidebar" id="sidebar">
    <div class="sidebar-inner">
        <span class="sidebar-section-label">Main Menu</span>
        <?php foreach ($menuItems as $item): ?>
            <a href="<?php echo $item['file']; ?>.php"
               class="sidebar-link <?php echo ($currentPage === $item['file'] || (isset($activePage) && $activePage === $item['file'])) ? 'active' : ''; ?>">
                <i class="<?php echo $item['icon']; ?>"></i>
                <span><?php echo $item['label']; ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<div class="content-area" id="content-area">
    <div class="container-fluid py-4">
        <?php if (isset($_SESSION['flash_success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($_SESSION['flash_success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($_SESSION['flash_error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>
