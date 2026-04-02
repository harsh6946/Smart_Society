<?php
session_start();

if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_role']) || $_SESSION['admin_role'] !== 'super_admin') {
    $_SESSION['flash_error'] = 'Access denied. Super admin login required.';
    header('Location: index');
    exit;
}
