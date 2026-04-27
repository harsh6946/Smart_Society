<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';
require_once __DIR__ . '/../include/security.php';

$pageTitle = 'Admin Users';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name      = trim($_POST['name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $role      = $_POST['role'] ?? 'society_admin';
        $societyId = intval($_POST['society_id'] ?? 0);
        $phone     = trim($_POST['phone'] ?? '');

        if (empty($name) || empty($email) || empty($password)) {
            $_SESSION['flash_error'] = 'Name, email, and password are required.';
        } elseif (!validateEmail($email)) {
            $_SESSION['flash_error'] = 'Invalid email address.';
        } elseif (strlen($password) < 6) {
            $_SESSION['flash_error'] = 'Password must be at least 6 characters.';
        } elseif (!in_array($role, ['super_admin', 'society_admin', 'committee_member'])) {
            $_SESSION['flash_error'] = 'Invalid role.';
        } else {
            $checkStmt = $conn->prepare("SELECT id FROM tbl_admin WHERE email = ?");
            $checkStmt->bind_param('s', $email);    // it binds email as string 
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                $_SESSION['flash_error'] = 'An admin with this email already exists.';
            } else {
                $hashedPassword = hashPassword($password);
                $socIdVal = $societyId > 0 ? $societyId : null;
                $stmt = $conn->prepare("INSERT INTO tbl_admin (name, email, password_hash, role, society_id, phone, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                $stmt->bind_param('ssssis', $name, $email, $hashedPassword, $role, $socIdVal, $phone);
                if ($stmt->execute()) {      // this checks if admin has inserted successfully if yes run code if no go to else
                    $newId = $stmt->insert_id;
                    $logStmt = $conn->prepare("INSERT INTO tbl_audit_log (admin_id, action, entity_type, entity_id, ip_address, details) VALUES (?, 'create_admin', 'admin', ?, ?, ?)");
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $details = json_encode(['email' => $email, 'role' => $role]);
                    $logStmt->bind_param('iiss', $_SESSION['admin_id'], $newId, $ip, $details);
                    $logStmt->execute(); $logStmt->close();
                    $_SESSION['flash_success'] = 'Admin user created successfully.';
                } else {
                    $_SESSION['flash_error'] = 'Failed to create admin.';
                }
                $stmt->close();
            }
            $checkStmt->close();
        }
        header('Location: admins'); exit;
    }

    if ($action === 'reset_password') {
        $adminId     = intval($_POST['admin_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        if ($adminId <= 0 || strlen($newPassword) < 6) {
            $_SESSION['flash_error'] = 'Valid admin and password (min 6 chars) required.';
        } else {
            $hashed = hashPassword($newPassword);
            $stmt = $conn->prepare("UPDATE tbl_admin SET password_hash = ? WHERE id = ?");
            $stmt->bind_param('si', $hashed, $adminId);
            if ($stmt->execute()) $_SESSION['flash_success'] = 'Password reset successfully.';
            else $_SESSION['flash_error'] = 'Failed to reset password.';
            $stmt->close();
        }
        header('Location: admins'); exit;
    }

    if ($action === 'toggle_status') {
        $adminId   = intval($_POST['admin_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        if ($adminId > 0 && in_array($newStatus, ['active', 'inactive'])) {
            if ($adminId === (int)$_SESSION['admin_id'] && $newStatus !== 'active') {
                $_SESSION['flash_error'] = 'You cannot deactivate your own account.';
            } else {
                $stmt = $conn->prepare("UPDATE tbl_admin SET status = ? WHERE id = ?");
                $stmt->bind_param('si', $newStatus, $adminId);
                if ($stmt->execute()) $_SESSION['flash_success'] = 'Admin status updated.';
                $stmt->close();
            }
        }
        header('Location: admins'); exit;
    }
}

// Stats
$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_admin WHERE status='active'");   $activeAdmins = (int)$res->fetch_assoc()['cnt'];
$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_admin WHERE role='super_admin'"); $superCount   = (int)$res->fetch_assoc()['cnt'];
$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_admin WHERE role='society_admin'"); $socAdminCount = (int)$res->fetch_assoc()['cnt'];
$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_admin WHERE role='committee_member'"); $committeeCount = (int)$res->fetch_assoc()['cnt'];
$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_admin WHERE status='inactive'"); $inactiveCount = (int)$res->fetch_assoc()['cnt'];

// Pagination
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$countRes  = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_admin");
$totalRows = $countRes->fetch_assoc()['cnt'];
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $conn->prepare("SELECT a.*, s.name AS society_name
    FROM tbl_admin a
    LEFT JOIN tbl_society s ON a.society_id = s.id
    ORDER BY a.created_at DESC LIMIT ? OFFSET ?");
$stmt->bind_param('ii', $perPage, $offset);
$stmt->execute();
$adminList = $stmt->get_result();
$stmt->close();

$societyList = $conn->query("SELECT id, name FROM tbl_society ORDER BY name");

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600&display=swap');

:root {
  --accent:      #e94560;
  --accent-dim:  rgba(233,69,96,.10);
  --accent-glow: rgba(233,69,96,.22);
  --blue:    #3b82f6; --blue-s:   #eff6ff;
  --green:   #10b981; --green-s:  #ecfdf5;
  --amber:   #f59e0b; --amber-s:  #fffbeb;
  --purple:  #8b5cf6; --purple-s: #f5f3ff;
  --teal:    #0ea5e9; --teal-s:   #f0f9ff;
  --rose:    #f43f5e; --rose-s:   #fff1f2;
  --indigo:  #6366f1; --indigo-s: #eef2ff;
  --bg:      #f0f2f8;
  --card:    #ffffff;
  --border:  #e4e7f0;
  --txt1:    #0f1729;
  --txt2:    #4b5563;
  --txt3:    #9ca3af;
  --shadow:  0 1px 3px rgba(15,23,41,.06), 0 4px 16px rgba(15,23,41,.06);
  --r: 14px;
}

*, *::before, *::after { box-sizing: border-box; }

.adm-page {
  font-family: 'DM Sans', sans-serif;
  padding: 28px 32px 72px;
  background: var(--bg);
  min-height: 100vh;
  color: var(--txt1);
}

/* ── Page Header ── */
.pg-head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 24px; flex-wrap: wrap; gap: 14px;
}
.pg-head-left { display: flex; align-items: center; gap: 14px; }
.pg-head-icon {
  width: 46px; height: 46px; background: var(--accent);
  border-radius: 13px; display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 14px var(--accent-glow); flex-shrink: 0;
}
.pg-head-icon i { color: #fff; font-size: 16px; }
.pg-head-text h1 {
  font-family: 'Outfit', sans-serif; font-size: 20px; font-weight: 800;
  color: var(--txt1); letter-spacing: -.3px; line-height: 1.1; margin: 0;
}
.pg-breadcrumb {
  display: flex; align-items: center; gap: 6px;
  font-size: 12px; font-weight: 500; color: var(--txt3); margin-top: 3px;
}
.pg-breadcrumb a { color: var(--txt3); text-decoration: none; transition: color .14s; }
.pg-breadcrumb a:hover { color: var(--accent); }

.btn-add {
  display: inline-flex; align-items: center; gap: 7px;
  background: var(--accent); color: #fff; border: none; border-radius: 10px;
  padding: 10px 20px; font-family: 'Outfit', sans-serif;
  font-size: 13px; font-weight: 700; cursor: pointer;
  box-shadow: 0 4px 14px var(--accent-glow); transition: all .18s;
}
.btn-add:hover { background: #c73550; transform: translateY(-1px); box-shadow: 0 6px 20px var(--accent-glow); }

/* ── Stats Strip ── */
.stats-strip {
  display: grid; grid-template-columns: repeat(5, 1fr);
  gap: 14px; margin-bottom: 22px;
}
@media(max-width:1100px) { .stats-strip { grid-template-columns: repeat(3,1fr); } }
@media(max-width:640px)  { .stats-strip { grid-template-columns: repeat(2,1fr); } }

.ssc {
  background: var(--card); border: 1px solid var(--border);
  border-radius: var(--r); padding: 18px 18px 16px;
  position: relative; overflow: hidden;
  box-shadow: var(--shadow);
  transition: transform .2s, box-shadow .2s;
  animation: fadeUp .4s ease both; cursor: default;
}
.ssc:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(15,23,41,.10); }
@keyframes fadeUp {
  from { opacity:0; transform:translateY(14px); }
  to   { opacity:1; transform:translateY(0); }
}
.ssc:nth-child(1){ animation-delay:.04s }
.ssc:nth-child(2){ animation-delay:.08s }
.ssc:nth-child(3){ animation-delay:.12s }
.ssc:nth-child(4){ animation-delay:.16s }
.ssc:nth-child(5){ animation-delay:.20s }
.ssc::before {
  content:''; position:absolute; left:0; top:12px; bottom:12px;
  width:3px; border-radius:0 3px 3px 0; background:var(--c);
}
.ssc-icon {
  width: 38px; height: 38px; border-radius: 10px;
  background: var(--cs); color: var(--c);
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; margin-bottom: 12px;
}
.ssc-val {
  font-family: 'Outfit', sans-serif; font-size: 26px; font-weight: 800;
  line-height: 1; letter-spacing: -.5px; color: var(--txt1); margin-bottom: 3px;
}
.ssc-lbl {
  font-size: 10.5px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .08em; color: var(--txt3);
}

/* ── Table Panel ── */
.tbl-panel {
  background: var(--card); border: 1px solid var(--border);
  border-radius: var(--r); overflow: hidden; box-shadow: var(--shadow);
}
.tbl-panel-hdr {
  display: flex; align-items: center; justify-content: space-between;
  padding: 15px 22px; border-bottom: 1px solid #f0f2f8;
}
.tbl-panel-title {
  font-family: 'Outfit', sans-serif; font-size: 13.5px; font-weight: 700;
  color: var(--txt1); display: flex; align-items: center; gap: 9px;
}
.tbl-panel-title-icon {
  width: 28px; height: 28px; border-radius: 7px;
  background: var(--accent-dim); color: var(--accent);
  display: flex; align-items: center; justify-content: center; font-size: 11px;
}
.tbl-count {
  font-size: 11px; font-weight: 700;
  background: #f0f2f8; color: var(--txt3); padding: 3px 9px; border-radius: 20px;
}

table.st { width: 100%; border-collapse: collapse; font-size: 13px; }
table.st thead tr { background: #f7f8fc; }
table.st thead th {
  padding: 11px 16px; font-size: 10px; font-weight: 800;
  letter-spacing: .09em; text-transform: uppercase;
  color: var(--txt3); text-align: left; white-space: nowrap;
  border-bottom: 1.5px solid var(--border);
}
table.st thead th:first-child { padding-left: 22px; }
table.st thead th:last-child  { padding-right: 22px; }
table.st tbody tr { border-bottom: 1px solid #f2f4fb; transition: background .1s; }
table.st tbody tr:last-child { border-bottom: none; }
table.st tbody tr:hover { background: #fafbfe; }
table.st tbody td { padding: 13px 16px; vertical-align: middle; }
table.st tbody td:first-child { padding-left: 22px; }
table.st tbody td:last-child  { padding-right: 22px; }

/* admin avatar row */
.av-cell { display: flex; align-items: center; gap: 10px; }
.av-init {
  width: 36px; height: 36px; border-radius: 10px;
  font-family: 'Outfit', sans-serif; font-size: 11px; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; letter-spacing: .3px;
}
.av-name  { font-size: 13px; font-weight: 700; color: var(--txt1); }
.av-phone { font-size: 11px; color: var(--txt3); font-weight: 500; margin-top: 1px; }

/* email cell */
.email-cell { font-size: 12.5px; color: var(--txt2); font-weight: 500; }

/* role badges */
.role-badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px; border-radius: 8px;
  font-size: 11.5px; font-weight: 700; white-space: nowrap;
}
.role-super     { background: var(--rose-s);   color: var(--rose); }
.role-society   { background: var(--blue-s);   color: var(--blue); }
.role-committee { background: var(--teal-s);   color: var(--teal); }

/* society link */
.soc-link {
  font-size: 12.5px; font-weight: 600; color: var(--txt2);
  text-decoration: none; transition: color .13s;
}
.soc-link:hover { color: var(--accent); }

/* status pill */
.st-pill {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700;
}
.st-pill .dot { width: 6px; height: 6px; border-radius: 50%; }
.s-active   { background: #ecfdf5; color: #059669; }
.s-active .dot   { background: #10b981; }
.s-inactive { background: #f2f3f8; color: #6b7280; }
.s-inactive .dot { background: #9ca3af; }

/* last login */
.login-cell      { font-size: 12px; color: var(--txt2); }
.login-cell .never { color: var(--txt3); font-style: italic; }

/* you badge */
.you-badge {
  font-size: 9.5px; font-weight: 800; text-transform: uppercase;
  letter-spacing: .08em; background: var(--accent-dim); color: var(--accent);
  padding: 2px 6px; border-radius: 5px; margin-left: 5px;
  vertical-align: middle;
}

/* action buttons */
.act-btns { display: flex; align-items: center; gap: 6px; }
.act-btn {
  width: 32px; height: 32px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; border: 1.5px solid var(--border);
  cursor: pointer; transition: all .16s; background: #f7f8fc; color: var(--txt2);
}
.act-btn:hover { transform: translateY(-1px); }
.act-btn.key:hover      { background: var(--amber-s);  border-color: var(--amber); color: var(--amber); }
.act-btn.deactivate:hover{ background: var(--rose-s);  border-color: var(--rose);  color: var(--rose); }
.act-btn.activate:hover  { background: var(--green-s); border-color: var(--green); color: var(--green); }
.act-btn-form { margin: 0; padding: 0; display: inline; }

/* empty */
.empty-state { text-align: center; padding: 70px 20px; }
.empty-state i { font-size: 36px; opacity: .15; margin-bottom: 14px; display: block; }
.empty-state h3 { font-family: 'Outfit', sans-serif; font-size: 16px; font-weight: 700; color: var(--txt2); margin-bottom: 6px; }
.empty-state p  { font-size: 13px; color: var(--txt3); font-weight: 500; margin: 0; }

/* pagination */
.tbl-footer {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 22px; border-top: 1px solid #f0f2f8; flex-wrap: wrap; gap: 10px;
}
.pg-info { font-size: 12px; color: var(--txt3); font-weight: 500; }
.pg-links { display: flex; gap: 4px; }
.pg-link {
  min-width: 32px; height: 32px; padding: 0 10px; border-radius: 8px;
  border: 1.5px solid var(--border); background: var(--card); color: var(--txt2);
  font-size: 12px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  text-decoration: none; transition: all .15s;
}
.pg-link:hover:not(.disabled):not(.active) { border-color: var(--accent); color: var(--accent); }
.pg-link.active   { background: var(--accent); border-color: var(--accent); color: #fff; }
.pg-link.disabled { opacity: .35; pointer-events: none; }

/* ── Modal ── */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(15,23,41,.45); backdrop-filter: blur(4px);
  z-index: 9999; align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-box {
  background: var(--card); border-radius: 18px;
  width: 100%; max-width: 480px; margin: 16px; overflow: hidden;
  box-shadow: 0 20px 60px rgba(15,23,41,.25); animation: modalIn .25s ease;
}
.modal-box.sm { max-width: 380px; }
@keyframes modalIn {
  from { opacity:0; transform:scale(.95) translateY(10px); }
  to   { opacity:1; transform:scale(1) translateY(0); }
}
.modal-hdr {
  display: flex; align-items: center; justify-content: space-between;
  padding: 20px 24px 18px; border-bottom: 1px solid #f0f2f8;
}
.modal-title {
  font-family: 'Outfit', sans-serif; font-size: 16px; font-weight: 800;
  color: var(--txt1); display: flex; align-items: center; gap: 10px;
}
.modal-title-icon {
  width: 32px; height: 32px; border-radius: 8px;
  background: var(--accent-dim); color: var(--accent);
  display: flex; align-items: center; justify-content: center; font-size: 12px;
}
.modal-close {
  width: 30px; height: 30px; border-radius: 7px; background: #f2f3f8;
  border: none; cursor: pointer; display: flex; align-items: center; justify-content: center;
  font-size: 13px; color: var(--txt3); transition: all .14s;
}
.modal-close:hover { background: var(--accent-dim); color: var(--accent); }

.modal-body { padding: 22px 24px; max-height: 72vh; overflow-y: auto; }
.modal-note {
  background: #f7f8fc; border: 1px solid var(--border); border-radius: 9px;
  padding: 10px 14px; font-size: 12.5px; color: var(--txt2); font-weight: 500;
  margin-bottom: 16px; display: flex; align-items: center; gap: 8px;
}
.modal-note strong { color: var(--txt1); }
.modal-note i { color: var(--amber); flex-shrink: 0; }

.form-group { margin-bottom: 15px; }
.form-label-sm {
  font-size: 11px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .06em; color: var(--txt2); margin-bottom: 6px; display: block;
}
.form-req { color: var(--accent); }
.form-ctrl {
  width: 100%; height: 40px; border: 1.5px solid var(--border); border-radius: 9px;
  padding: 0 14px; font-family: 'DM Sans', sans-serif;
  font-size: 13.5px; color: var(--txt1); background: #f7f8fc; outline: none;
  transition: border-color .15s, background .15s; appearance: none;
}
.form-ctrl:focus { border-color: var(--accent); background: #fff; }
.form-ctrl::placeholder { color: var(--txt3); }
.form-ctrl-select {
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%239ca3af' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 12px center; padding-right: 34px;
}
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
/* hide society group by default for super_admin role */
#societyGroup.hidden { display: none; }

.modal-ftr {
  display: flex; align-items: center; justify-content: flex-end; gap: 10px;
  padding: 16px 24px 20px; border-top: 1px solid #f0f2f8;
}
.btn-cancel {
  height: 38px; padding: 0 18px; background: #f2f3f8; border: none; border-radius: 9px;
  font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 600;
  color: var(--txt2); cursor: pointer; transition: background .15s;
}
.btn-cancel:hover { background: #e5e7ef; }
.btn-submit {
  height: 38px; padding: 0 22px; background: var(--accent); border: none; border-radius: 9px;
  font-family: 'Outfit', sans-serif; font-size: 13px; font-weight: 700; color: #fff;
  cursor: pointer; box-shadow: 0 3px 12px var(--accent-glow); transition: all .16s;
}
.btn-submit:hover { background: #c73550; transform: translateY(-1px); }
.btn-submit.amber { background: var(--amber); box-shadow: 0 3px 12px rgba(245,158,11,.28); }
.btn-submit.amber:hover { background: #d97706; }

@media(max-width:768px) { .adm-page { padding: 18px 16px 60px; } }
</style>

<div class="adm-page">

  <!-- Page Header -->
  <div class="pg-head">
    <div class="pg-head-left">
      <div class="pg-head-icon"><i class="fas fa-users-cog"></i></div>
      <div class="pg-head-text">
        <h1>Admin Users</h1>
        <div class="pg-breadcrumb">
          <a href="dashboard">Dashboard</a>
          <span>/</span>
          <span>Admin Users</span>
        </div>
      </div>
    </div>
    <button class="btn-add" onclick="document.getElementById('addModal').classList.add('open')">
      <i class="fas fa-plus"></i> Add Admin
    </button>
  </div>

  <!-- Stats Strip -->
  <div class="stats-strip">
    <div class="ssc" style="--c:var(--green);--cs:var(--green-s);">
      <div class="ssc-icon"><i class="fas fa-check-circle"></i></div>
      <div class="ssc-val"><?php echo $activeAdmins; ?></div>
      <div class="ssc-lbl">Active Admins</div>
    </div>
    <div class="ssc" style="--c:var(--rose);--cs:var(--rose-s);">
      <div class="ssc-icon"><i class="fas fa-shield-alt"></i></div>
      <div class="ssc-val"><?php echo $superCount; ?></div>
      <div class="ssc-lbl">Super Admins</div>
    </div>
    <div class="ssc" style="--c:var(--blue);--cs:var(--blue-s);">
      <div class="ssc-icon"><i class="fas fa-user-tie"></i></div>
      <div class="ssc-val"><?php echo $socAdminCount; ?></div>
      <div class="ssc-lbl">Society Admins</div>
    </div>
    <div class="ssc" style="--c:var(--teal);--cs:var(--teal-s);">
      <div class="ssc-icon"><i class="fas fa-users"></i></div>
      <div class="ssc-val"><?php echo $committeeCount; ?></div>
      <div class="ssc-lbl">Committee Members</div>
    </div>
    <div class="ssc" style="--c:var(--txt3);--cs:#f2f3f8;">
      <div class="ssc-icon"><i class="fas fa-user-slash"></i></div>
      <div class="ssc-val"><?php echo $inactiveCount; ?></div>
      <div class="ssc-lbl">Inactive</div>
    </div>
  </div>

  <!-- Table Panel -->
  <div class="tbl-panel">
    <div class="tbl-panel-hdr">
      <div class="tbl-panel-title">
        <span class="tbl-panel-title-icon"><i class="fas fa-users-cog"></i></span>
        All Admin Users
      </div>
      <span class="tbl-count"><?php echo $totalRows; ?> total</span>
    </div>

    <div style="overflow-x:auto;">
      <table class="st">
        <thead>
          <tr>
            <th>#</th>
            <th>Admin</th>
            <th>Email</th>
            <th>Role</th>
            <th>Society</th>
            <th>Status</th>
            <th>Last Login</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($adminList->num_rows > 0): $i = $offset + 1;
            while ($row = $adminList->fetch_assoc()):
              $isSelf = ((int)$row['id'] === (int)$_SESSION['admin_id']);
              // Avatar color per role
              $avBg = $row['role'] === 'super_admin' ? 'var(--rose-s)' : ($row['role'] === 'society_admin' ? 'var(--blue-s)' : 'var(--teal-s)');
              $avClr = $row['role'] === 'super_admin' ? 'var(--rose)' : ($row['role'] === 'society_admin' ? 'var(--blue)' : 'var(--teal)');
          ?>
          <tr>
            <td style="color:var(--txt3);font-size:11px;font-weight:700;width:30px;"><?php echo $i++; ?></td>
            <td>
              <div class="av-cell">
                <div class="av-init" style="background:<?php echo $avBg; ?>;color:<?php echo $avClr; ?>;">
                  <?php echo strtoupper(mb_substr(trim($row['name']),0,2)); ?>
                </div>
                <div>
                  <div class="av-name">
                    <?php echo htmlspecialchars($row['name']); ?>
                    <?php if ($isSelf): ?><span class="you-badge">You</span><?php endif; ?>
                  </div>
                  <?php if ($row['phone']): ?>
                    <div class="av-phone"><i class="fas fa-phone" style="font-size:9px;margin-right:3px;"></i><?php echo htmlspecialchars($row['phone']); ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td class="email-cell"><?php echo htmlspecialchars($row['email']); ?></td>
            <td>
              <?php if ($row['role'] === 'super_admin'): ?>
                <span class="role-badge role-super"><i class="fas fa-shield-alt" style="font-size:9px;"></i> Super Admin</span>
              <?php elseif ($row['role'] === 'society_admin'): ?>
                <span class="role-badge role-society"><i class="fas fa-user-tie" style="font-size:9px;"></i> Society Admin</span>
              <?php else: ?>
                <span class="role-badge role-committee"><i class="fas fa-users" style="font-size:9px;"></i> Committee</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($row['society_name']): ?>
                <a href="society_detail?id=<?php echo (int)$row['society_id']; ?>" class="soc-link">
                  <?php echo htmlspecialchars($row['society_name']); ?>
                </a>
              <?php else: ?>
                <span style="color:var(--txt3);font-size:12px;">— Platform</span>
              <?php endif; ?>
            </td>
            <td>
              <?php $s = strtolower($row['status']); ?>
              <span class="st-pill s-<?php echo $s; ?>">
                <span class="dot"></span><?php echo ucfirst($row['status']); ?>
              </span>
            </td>
            <td class="login-cell">
              <?php if ($row['last_login']): ?>
                <?php echo date('d M Y', strtotime($row['last_login'])); ?>
                <div style="font-size:11px;color:var(--txt3);"><?php echo date('H:i', strtotime($row['last_login'])); ?></div>
              <?php else: ?>
                <span class="never">Never</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="act-btns">
                <button class="act-btn key reset-btn"
                  data-id="<?php echo (int)$row['id']; ?>"
                  data-name="<?php echo htmlspecialchars($row['name']); ?>"
                  title="Reset Password">
                  <i class="fas fa-key"></i>
                </button>
                <?php if (!$isSelf): ?>
                  <?php if ($row['status'] === 'active'): ?>
                    <form method="POST" class="act-btn-form" onsubmit="return confirm('Deactivate <?php echo htmlspecialchars(addslashes($row['name'])); ?>?');">
                      <input type="hidden" name="action" value="toggle_status">
                      <input type="hidden" name="admin_id" value="<?php echo (int)$row['id']; ?>">
                      <input type="hidden" name="new_status" value="inactive">
                      <button type="submit" class="act-btn deactivate" title="Deactivate">
                        <i class="fas fa-user-slash"></i>
                      </button>
                    </form>
                  <?php else: ?>
                    <form method="POST" class="act-btn-form" onsubmit="return confirm('Activate <?php echo htmlspecialchars(addslashes($row['name'])); ?>?');">
                      <input type="hidden" name="action" value="toggle_status">
                      <input type="hidden" name="admin_id" value="<?php echo (int)$row['id']; ?>">
                      <input type="hidden" name="new_status" value="active">
                      <button type="submit" class="act-btn activate" title="Activate">
                        <i class="fas fa-user-check"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr>
            <td colspan="8">
              <div class="empty-state">
                <i class="fas fa-users-cog"></i>
                <h3>No admin users found</h3>
                <p>Add an admin to get started</p>
              </div>
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="tbl-footer">
      <div class="pg-info">
        Showing <?php echo $offset+1; ?>–<?php echo min($offset+$perPage,$totalRows); ?> of <?php echo $totalRows; ?>
      </div>
      <div class="pg-links">
        <a class="pg-link <?php echo $page<=1?'disabled':''; ?>" href="?page=<?php echo $page-1; ?>">
          <i class="fas fa-chevron-left" style="font-size:10px;"></i>
        </a>
        <?php for ($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?>
          <a class="pg-link <?php echo $p===$page?'active':''; ?>" href="?page=<?php echo $p; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
        <a class="pg-link <?php echo $page>=$totalPages?'disabled':''; ?>" href="?page=<?php echo $page+1; ?>">
          <i class="fas fa-chevron-right" style="font-size:10px;"></i>
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /adm-page -->

<!-- ── Add Admin Modal ── -->
<div class="modal-overlay" id="addModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-box">
    <div class="modal-hdr">
      <div class="modal-title">
        <span class="modal-title-icon"><i class="fas fa-user-plus"></i></span>
        Add Admin User
      </div>
      <button class="modal-close" onclick="document.getElementById('addModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="create">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label-sm">Full Name <span class="form-req">*</span></label>
            <input type="text" name="name" class="form-ctrl" placeholder="Enter full name" required>
          </div>
          <div class="form-group">
            <label class="form-label-sm">Phone</label>
            <input type="text" name="phone" class="form-ctrl" placeholder="Phone number">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label-sm">Email Address <span class="form-req">*</span></label>
          <input type="email" name="email" class="form-ctrl" placeholder="admin@example.com" required>
        </div>
        <div class="form-group">
          <label class="form-label-sm">Password <span class="form-req">*</span></label>
          <input type="password" name="password" class="form-ctrl" placeholder="Min. 6 characters" minlength="6" required>
        </div>
        <div class="form-group">
          <label class="form-label-sm">Role <span class="form-req">*</span></label>
          <select name="role" class="form-ctrl form-ctrl-select" id="roleSelect" required>
            <option value="society_admin">Society Admin</option>
            <option value="committee_member">Committee Member</option>
            <option value="super_admin">Super Admin</option>
          </select>
        </div>
        <div class="form-group" id="societyGroup">
          <label class="form-label-sm">Society</label>
          <select name="society_id" class="form-ctrl form-ctrl-select">
            <option value="0">— None (Platform Level) —</option>
            <?php $societyList->data_seek(0); while ($s = $societyList->fetch_assoc()): ?>
              <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
            <?php endwhile; ?>
          </select>
        </div>
      </div>
      <div class="modal-ftr">
        <button type="button" class="btn-cancel" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn-submit"><i class="fas fa-user-plus" style="margin-right:6px;font-size:11px;"></i>Create Admin</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Reset Password Modal ── -->
<div class="modal-overlay" id="resetModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-box sm">
    <div class="modal-hdr">
      <div class="modal-title">
        <span class="modal-title-icon" style="background:var(--amber-s);color:var(--amber);"><i class="fas fa-key"></i></span>
        Reset Password
      </div>
      <button class="modal-close" onclick="document.getElementById('resetModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="admin_id" id="reset_admin_id">
        <div class="modal-note">
          <i class="fas fa-user-circle"></i>
          Resetting password for <strong id="reset_admin_name">—</strong>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label-sm">New Password <span class="form-req">*</span></label>
          <input type="password" name="new_password" class="form-ctrl" placeholder="Min. 6 characters" minlength="6" required>
        </div>
      </div>
      <div class="modal-ftr">
        <button type="button" class="btn-cancel" onclick="document.getElementById('resetModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn-submit amber"><i class="fas fa-key" style="margin-right:6px;font-size:11px;"></i>Reset Password</button>
      </div>
    </form>
  </div>
</div>

<script>
// Role select — hide society field for super_admin
document.getElementById('roleSelect').addEventListener('change', function() {
  var grp = document.getElementById('societyGroup');
  grp.style.display = this.value === 'super_admin' ? 'none' : 'block';
});

// Reset password modal populate
document.querySelectorAll('.reset-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.getElementById('reset_admin_id').value  = this.dataset.id;
    document.getElementById('reset_admin_name').textContent = this.dataset.name;
    document.getElementById('resetModal').classList.add('open');
  });
});
</script>

<?php
$conn->close();
include __DIR__ . '/includes/footer.php';
?>