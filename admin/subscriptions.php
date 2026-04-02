<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';

$pageTitle = 'Subscriptions';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $socId    = intval($_POST['society_id'] ?? 0);
        $planName = trim($_POST['plan_name'] ?? '');
        $maxFlats = intval($_POST['max_flats'] ?? 50);
        $amount   = floatval($_POST['amount'] ?? 0);
        $startDate = $_POST['start_date'] ?? '';
        $endDate   = $_POST['end_date'] ?? '';

        if ($socId <= 0 || empty($planName) || empty($startDate) || empty($endDate)) {
            $_SESSION['flash_error'] = 'All fields are required.';
        } elseif (strtotime($endDate) <= strtotime($startDate)) {
            $_SESSION['flash_error'] = 'End date must be after start date.';
        } else {
            $status = 'active';
            $stmt = $conn->prepare("INSERT INTO tbl_subscription (society_id, plan_name, max_flats, amount, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isidsss', $socId, $planName, $maxFlats, $amount, $startDate, $endDate, $status);
            if ($stmt->execute()) {
                $upStmt = $conn->prepare("UPDATE tbl_society SET subscription_plan = ? WHERE id = ?");
                $upStmt->bind_param('si', $planName, $socId);
                $upStmt->execute(); $upStmt->close();
                $logStmt = $conn->prepare("INSERT INTO tbl_audit_log (society_id, admin_id, action, entity_type, entity_id, ip_address, details) VALUES (?, ?, 'create_subscription', 'subscription', ?, ?, ?)");
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $newId = $stmt->insert_id;
                $details = json_encode(['plan_name' => $planName, 'amount' => $amount]);
                $logStmt->bind_param('iiiss', $socId, $_SESSION['admin_id'], $newId, $ip, $details);
                $logStmt->execute(); $logStmt->close();
                $_SESSION['flash_success'] = 'Subscription created successfully.';
            } else {
                $_SESSION['flash_error'] = 'Failed to create subscription.';
            }
            $stmt->close();
        }
        header('Location: subscriptions'); exit;
    }

    if ($action === 'update') {
        $subId    = intval($_POST['sub_id'] ?? 0);
        $planName = trim($_POST['plan_name'] ?? '');
        $maxFlats = intval($_POST['max_flats'] ?? 50);
        $amount   = floatval($_POST['amount'] ?? 0);
        $startDate = $_POST['start_date'] ?? '';
        $endDate   = $_POST['end_date'] ?? '';
        $status    = $_POST['status'] ?? 'active';

        if ($subId <= 0 || empty($planName) || empty($startDate) || empty($endDate)) {
            $_SESSION['flash_error'] = 'All fields are required.';
        } elseif (!in_array($status, ['active','expired','cancelled','trial'])) {
            $_SESSION['flash_error'] = 'Invalid status.';
        } else {
            $stmt = $conn->prepare("UPDATE tbl_subscription SET plan_name=?, max_flats=?, amount=?, start_date=?, end_date=?, status=? WHERE id=?");
            $stmt->bind_param('sidssi', $planName, $maxFlats, $amount, $startDate, $endDate, $status, $subId);
            if ($stmt->execute()) {
                if ($status === 'active') {
                    $fetchSocStmt = $conn->prepare("SELECT society_id FROM tbl_subscription WHERE id = ?");
                    $fetchSocStmt->bind_param('i', $subId);
                    $fetchSocStmt->execute();
                    $socRow = $fetchSocStmt->get_result()->fetch_assoc();
                    $fetchSocStmt->close();
                    if ($socRow) {
                        $upStmt = $conn->prepare("UPDATE tbl_society SET subscription_plan = ? WHERE id = ?");
                        $upStmt->bind_param('si', $planName, $socRow['society_id']);
                        $upStmt->execute(); $upStmt->close();
                    }
                }
                $_SESSION['flash_success'] = 'Subscription updated successfully.';
            } else {
                $_SESSION['flash_error'] = 'Failed to update subscription.';
            }
            $stmt->close();
        }
        header('Location: subscriptions'); exit;
    }

    if ($action === 'change_status') {
        $subId     = intval($_POST['sub_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        if ($subId > 0 && in_array($newStatus, ['active','expired','cancelled','trial'])) {
            $stmt = $conn->prepare("UPDATE tbl_subscription SET status = ? WHERE id = ?");
            $stmt->bind_param('si', $newStatus, $subId);
            if ($stmt->execute()) $_SESSION['flash_success'] = 'Subscription status updated.';
            $stmt->close();
        }
        header('Location: subscriptions'); exit;
    }
}

// Stats
$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_subscription WHERE status = 'active'"); $activeCount = (int)$res->fetch_assoc()['cnt'];
$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_subscription WHERE status = 'expired'"); $expiredCount = (int)$res->fetch_assoc()['cnt'];
$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_subscription WHERE status = 'trial'");  $trialCount = (int)$res->fetch_assoc()['cnt'];
$res = $conn->query("SELECT COALESCE(SUM(amount),0) AS total FROM tbl_subscription WHERE status IN ('active','expired')"); $totalRevenue = (float)$res->fetch_assoc()['total'];
$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_subscription WHERE status='active' AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"); $expiringCount = (int)$res->fetch_assoc()['cnt'];

// Pagination
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$countRes  = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_subscription");
$totalRows = $countRes->fetch_assoc()['cnt'];
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $conn->prepare("SELECT sub.*, s.name AS society_name
    FROM tbl_subscription sub
    JOIN tbl_society s ON sub.society_id = s.id
    ORDER BY sub.created_at DESC LIMIT ? OFFSET ?");
$stmt->bind_param('ii', $perPage, $offset);
$stmt->execute();
$subscriptions = $stmt->get_result();
$stmt->close();

$societyList = $conn->query("SELECT id, name FROM tbl_society ORDER BY name");

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600&display=swap');

:root {
  --accent:     #e94560;
  --accent-dim: rgba(233,69,96,.10);
  --accent-glow:rgba(233,69,96,.22);
  --blue:    #3b82f6; --blue-s:   #eff6ff;
  --green:   #10b981; --green-s:  #ecfdf5;
  --amber:   #f59e0b; --amber-s:  #fffbeb;
  --purple:  #8b5cf6; --purple-s: #f5f3ff;
  --teal:    #0ea5e9; --teal-s:   #f0f9ff;
  --rose:    #f43f5e; --rose-s:   #fff1f2;
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

.sub-page {
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
  background: var(--accent); color: #fff;
  border: none; border-radius: 10px; padding: 10px 20px;
  font-family: 'Outfit', sans-serif; font-size: 13px; font-weight: 700;
  cursor: pointer; box-shadow: 0 4px 14px var(--accent-glow);
  transition: all .18s; text-decoration: none;
}
.btn-add:hover { background: #c73550; color: #fff; transform: translateY(-1px); box-shadow: 0 6px 20px var(--accent-glow); }

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

/* Revenue card filled */
.ssc.fill {
  background: linear-gradient(135deg, #e94560 0%, #c0203c 100%);
  border-color: transparent; box-shadow: 0 6px 22px rgba(233,69,96,.28);
}
.ssc.fill::before { background: rgba(255,255,255,.3); }
.ssc.fill .ssc-icon { background: rgba(255,255,255,.18); color: #fff; }
.ssc.fill .ssc-val, .ssc.fill .ssc-lbl { color: #fff; }
.ssc.fill .ssc-lbl { opacity: .7; }
.ssc.fill:hover { box-shadow: 0 10px 32px rgba(233,69,96,.40); }

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

/* society cell */
.soc-cell { display: flex; align-items: center; gap: 9px; }
.soc-av {
  width: 34px; height: 34px; border-radius: 9px;
  font-family: 'Outfit', sans-serif; font-size: 11px; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
  background: var(--accent-dim); color: var(--accent); flex-shrink: 0;
}
.soc-name {
  font-size: 13px; font-weight: 700; color: var(--txt1);
  text-decoration: none; transition: color .13s;
}
.soc-name:hover { color: var(--accent); }

/* plan badge */
.plan-chip {
  display: inline-flex; align-items: center; gap: 5px;
  background: var(--purple-s); color: var(--purple);
  border-radius: 8px; padding: 4px 10px;
  font-size: 12px; font-weight: 700;
}

/* amount */
.amt {
  font-family: 'Outfit', sans-serif; font-weight: 800;
  font-size: 13.5px; color: var(--txt1);
}

/* dates */
.date-cell { font-size: 12px; color: var(--txt2); font-weight: 500; }

/* days badge */
.days-badge {
  display: inline-block; font-size: 11px; font-weight: 700;
  padding: 2px 8px; border-radius: 20px;
  background: #f0f2f8; color: var(--txt3); margin-top: 2px;
}
.days-badge.warn  { background: var(--amber-s); color: #d97706; }
.days-badge.crit  { background: var(--rose-s);  color: var(--rose); }
.days-badge.past  { background: #f2f3f8; color: var(--txt3); }

/* status pills */
.st-pill {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px; border-radius: 20px;
  font-size: 11.5px; font-weight: 700;
}
.st-pill .dot { width: 6px; height: 6px; border-radius: 50%; }
.s-active    { background: #ecfdf5; color: #059669; }
.s-active .dot    { background: #10b981; }
.s-expired   { background: #f2f3f8; color: #6b7280; }
.s-expired .dot   { background: #9ca3af; }
.s-cancelled { background: #fff1f2; color: #be123c; }
.s-cancelled .dot { background: #e94560; }
.s-trial     { background: var(--blue-s); color: var(--blue); }
.s-trial .dot     { background: var(--blue); }

/* action btn */
.act-btn {
  width: 32px; height: 32px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; border: 1.5px solid var(--border);
  cursor: pointer; transition: all .16s; background: #f7f8fc; color: var(--txt2);
}
.act-btn:hover { background: var(--blue-s); border-color: var(--blue); color: var(--blue); transform: translateY(-1px); }

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
.pg-links { display: flex; gap: 4px; align-items: center; }
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
  width: 100%; max-width: 500px; margin: 16px; overflow: hidden;
  box-shadow: 0 20px 60px rgba(15,23,41,.25); animation: modalIn .25s ease;
}
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
  width: 30px; height: 30px; border-radius: 7px;
  background: #f2f3f8; border: none; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; color: var(--txt3); transition: all .14s;
}
.modal-close:hover { background: var(--accent-dim); color: var(--accent); }
.modal-body { padding: 22px 24px; max-height: 70vh; overflow-y: auto; }
.form-group { margin-bottom: 15px; }
.form-label-sm {
  font-size: 11px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .06em; color: var(--txt2); margin-bottom: 6px; display: block;
}
.form-req { color: var(--accent); }
.form-ctrl {
  width: 100%; height: 40px; border: 1.5px solid var(--border);
  border-radius: 9px; padding: 0 14px;
  font-family: 'DM Sans', sans-serif; font-size: 13.5px; color: var(--txt1);
  background: #f7f8fc; outline: none; transition: border-color .15s, background .15s;
  appearance: none;
}
.form-ctrl:focus { border-color: var(--accent); background: #fff; }
.form-ctrl::placeholder { color: var(--txt3); }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
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

/* select arrow */
.form-ctrl-select {
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%239ca3af' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 12px center; padding-right: 34px;
}

@media(max-width:768px) { .sub-page { padding: 18px 16px 60px; } }
</style>

<div class="sub-page">

  <!-- Page Header -->
  <div class="pg-head">
    <div class="pg-head-left">
      <div class="pg-head-icon"><i class="fas fa-credit-card"></i></div>
      <div class="pg-head-text">
        <h1>Subscriptions</h1>
        <div class="pg-breadcrumb">
          <a href="dashboard">Dashboard</a>
          <span>/</span>
          <span>Subscriptions</span>
        </div>
      </div>
    </div>
    <button class="btn-add" onclick="document.getElementById('addModal').classList.add('open')">
      <i class="fas fa-plus"></i> New Subscription
    </button>
  </div>

  <!-- Stats Strip -->
  <div class="stats-strip">
    <div class="ssc" style="--c:var(--green);--cs:var(--green-s);">
      <div class="ssc-icon"><i class="fas fa-check-circle"></i></div>
      <div class="ssc-val"><?php echo $activeCount; ?></div>
      <div class="ssc-lbl">Active</div>
    </div>
    <div class="ssc" style="--c:var(--blue);--cs:var(--blue-s);">
      <div class="ssc-icon"><i class="fas fa-flask"></i></div>
      <div class="ssc-val"><?php echo $trialCount; ?></div>
      <div class="ssc-lbl">Trial</div>
    </div>
    <div class="ssc" style="--c:var(--txt3);--cs:#f2f3f8;">
      <div class="ssc-icon"><i class="fas fa-clock"></i></div>
      <div class="ssc-val"><?php echo $expiredCount; ?></div>
      <div class="ssc-lbl">Expired</div>
    </div>
    <div class="ssc" style="--c:var(--amber);--cs:var(--amber-s);<?php echo $expiringCount>0?'border-color:#fde68a;':'' ?>">
      <div class="ssc-icon"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="ssc-val"><?php echo $expiringCount; ?></div>
      <div class="ssc-lbl">Expiring (30d)</div>
    </div>
    <div class="ssc fill">
      <div class="ssc-icon"><i class="fas fa-indian-rupee-sign"></i></div>
      <div class="ssc-val">₹<?php echo number_format($totalRevenue, 0); ?></div>
      <div class="ssc-lbl">Total Revenue</div>
    </div>
  </div>

  <!-- Table Panel -->
  <div class="tbl-panel">
    <div class="tbl-panel-hdr">
      <div class="tbl-panel-title">
        <span class="tbl-panel-title-icon"><i class="fas fa-credit-card"></i></span>
        All Subscriptions
      </div>
      <span class="tbl-count"><?php echo $totalRows; ?> total</span>
    </div>

    <div style="overflow-x:auto;">
      <table class="st">
        <thead>
          <tr>
            <th>#</th>
            <th>Society</th>
            <th>Plan</th>
            <th>Max Flats</th>
            <th>Amount</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($subscriptions->num_rows > 0): $i = $offset + 1;
            while ($row = $subscriptions->fetch_assoc()):
              $daysLeft = (int)floor((strtotime($row['end_date']) - time()) / 86400);
              $isExpired = $daysLeft < 0;
          ?>
          <tr>
            <td style="color:var(--txt3);font-size:11px;font-weight:700;width:30px;"><?php echo $i++; ?></td>
            <td>
              <div class="soc-cell">
                <div class="soc-av"><?php echo strtoupper(mb_substr(trim($row['society_name']),0,2)); ?></div>
                <a href="society_detail?id=<?php echo (int)$row['society_id']; ?>" class="soc-name">
                  <?php echo htmlspecialchars($row['society_name']); ?>
                </a>
              </div>
            </td>
            <td>
              <span class="plan-chip">
                <i class="fas fa-tag" style="font-size:9px;"></i>
                <?php echo htmlspecialchars($row['plan_name']); ?>
              </span>
            </td>
            <td style="font-family:'Outfit',sans-serif;font-weight:700;color:var(--txt1);"><?php echo (int)$row['max_flats']; ?></td>
            <td>
              <span class="amt">₹<?php echo number_format($row['amount'], 0); ?></span>
            </td>
            <td class="date-cell"><?php echo date('d M Y', strtotime($row['start_date'])); ?></td>
            <td>
              <div class="date-cell"><?php echo date('d M Y', strtotime($row['end_date'])); ?></div>
              <?php if ($row['status'] === 'active'): ?>
                <?php if ($isExpired): ?>
                  <span class="days-badge past">Expired</span>
                <?php elseif ($daysLeft <= 7): ?>
                  <span class="days-badge crit"><?php echo $daysLeft; ?>d left</span>
                <?php elseif ($daysLeft <= 30): ?>
                  <span class="days-badge warn"><?php echo $daysLeft; ?>d left</span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td>
              <?php $s = strtolower($row['status']); ?>
              <span class="st-pill s-<?php echo $s; ?>">
                <span class="dot"></span>
                <?php echo ucfirst($row['status']); ?>
              </span>
            </td>
            <td>
              <button class="act-btn edit-btn"
                data-id="<?php echo (int)$row['id']; ?>"
                data-plan="<?php echo htmlspecialchars($row['plan_name']); ?>"
                data-maxflats="<?php echo (int)$row['max_flats']; ?>"
                data-amount="<?php echo $row['amount']; ?>"
                data-start="<?php echo $row['start_date']; ?>"
                data-end="<?php echo $row['end_date']; ?>"
                data-status="<?php echo $row['status']; ?>"
                title="Edit Subscription">
                <i class="fas fa-edit"></i>
              </button>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr>
            <td colspan="9">
              <div class="empty-state">
                <i class="fas fa-credit-card"></i>
                <h3>No subscriptions yet</h3>
                <p>Create a subscription to get started</p>
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
        Showing <?php echo $offset+1; ?>–<?php echo min($offset+$perPage, $totalRows); ?> of <?php echo $totalRows; ?>
      </div>
      <div class="pg-links">
        <a class="pg-link <?php echo $page<=1?'disabled':''; ?>" href="?page=<?php echo $page-1; ?>">
          <i class="fas fa-chevron-left" style="font-size:10px;"></i>
        </a>
        <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
          <a class="pg-link <?php echo $p===$page?'active':''; ?>" href="?page=<?php echo $p; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
        <a class="pg-link <?php echo $page>=$totalPages?'disabled':''; ?>" href="?page=<?php echo $page+1; ?>">
          <i class="fas fa-chevron-right" style="font-size:10px;"></i>
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /sub-page -->

<!-- ── Add Subscription Modal ── -->
<div class="modal-overlay" id="addModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-box">
    <div class="modal-hdr">
      <div class="modal-title">
        <span class="modal-title-icon"><i class="fas fa-plus"></i></span>
        New Subscription
      </div>
      <button class="modal-close" onclick="document.getElementById('addModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="create">
        <div class="form-group">
          <label class="form-label-sm">Society <span class="form-req">*</span></label>
          <select name="society_id" class="form-ctrl form-ctrl-select" required>
            <option value="">Select Society...</option>
            <?php $societyList->data_seek(0); while ($s = $societyList->fetch_assoc()): ?>
              <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label-sm">Plan Name <span class="form-req">*</span></label>
          <input type="text" name="plan_name" class="form-ctrl" placeholder="e.g. Basic, Pro, Enterprise" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label-sm">Max Flats</label>
            <input type="number" name="max_flats" class="form-ctrl" value="50" min="1">
          </div>
          <div class="form-group">
            <label class="form-label-sm">Amount (INR)</label>
            <input type="number" name="amount" class="form-ctrl" step="0.01" value="0" min="0" placeholder="0.00">
          </div>
        </div>
        <div class="form-row" style="margin-bottom:0;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label-sm">Start Date <span class="form-req">*</span></label>
            <input type="date" name="start_date" class="form-ctrl" required>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label-sm">End Date <span class="form-req">*</span></label>
            <input type="date" name="end_date" class="form-ctrl" required>
          </div>
        </div>
      </div>
      <div class="modal-ftr">
        <button type="button" class="btn-cancel" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn-submit"><i class="fas fa-plus" style="margin-right:6px;font-size:11px;"></i>Create Subscription</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Subscription Modal ── -->
<div class="modal-overlay" id="editModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-box">
    <div class="modal-hdr">
      <div class="modal-title">
        <span class="modal-title-icon" style="background:var(--blue-s);color:var(--blue);"><i class="fas fa-edit"></i></span>
        Edit Subscription
      </div>
      <button class="modal-close" onclick="document.getElementById('editModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="sub_id" id="edit_sub_id">
        <div class="form-group">
          <label class="form-label-sm">Plan Name <span class="form-req">*</span></label>
          <input type="text" name="plan_name" id="edit_plan_name" class="form-ctrl" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label-sm">Max Flats</label>
            <input type="number" name="max_flats" id="edit_max_flats" class="form-ctrl" min="1">
          </div>
          <div class="form-group">
            <label class="form-label-sm">Amount (INR)</label>
            <input type="number" name="amount" id="edit_amount" class="form-ctrl" step="0.01" min="0">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label-sm">Start Date <span class="form-req">*</span></label>
            <input type="date" name="start_date" id="edit_start_date" class="form-ctrl" required>
          </div>
          <div class="form-group">
            <label class="form-label-sm">End Date <span class="form-req">*</span></label>
            <input type="date" name="end_date" id="edit_end_date" class="form-ctrl" required>
          </div>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label-sm">Status</label>
          <select name="status" id="edit_status" class="form-ctrl form-ctrl-select">
            <option value="active">Active</option>
            <option value="trial">Trial</option>
            <option value="expired">Expired</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </div>
      </div>
      <div class="modal-ftr">
        <button type="button" class="btn-cancel" onclick="document.getElementById('editModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn-submit"><i class="fas fa-save" style="margin-right:6px;font-size:11px;"></i>Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('.edit-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.getElementById('edit_sub_id').value    = this.dataset.id;
    document.getElementById('edit_plan_name').value = this.dataset.plan;
    document.getElementById('edit_max_flats').value = this.dataset.maxflats;
    document.getElementById('edit_amount').value    = this.dataset.amount;
    document.getElementById('edit_start_date').value = this.dataset.start;
    document.getElementById('edit_end_date').value   = this.dataset.end;
    document.getElementById('edit_status').value     = this.dataset.status;
    document.getElementById('editModal').classList.add('open');
  });
});
</script>

<?php
$conn->close();
include __DIR__ . '/includes/footer.php';
?>