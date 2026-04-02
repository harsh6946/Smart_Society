<?php
session_start();

if (!isset($_SESSION['admin_id']) || !isset($_SESSION['society_id'])) {
    $_SESSION['flash_error'] = 'Please login to continue.';
    header('Location: index');
    exit;
}

if (!in_array($_SESSION['admin_role'], ['society_admin', 'committee_member'])) {
    session_destroy();
    header('Location: index');
    exit;
}

$admin_id = $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'];
$admin_role = $_SESSION['admin_role'];
$society_id = $_SESSION['society_id'];
