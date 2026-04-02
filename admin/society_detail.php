<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';
require_once __DIR__ . '/../include/security.php';

$pageTitle = 'Society Detail';
$activePage = 'societies';

$societyId = intval($_GET['id'] ?? 0);
if ($societyId <= 0) {
    $_SESSION['flash_error'] = 'Invalid society ID.';
    header('Location: societies');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_admin') {
        $adminName = trim($_POST['admin_name'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $adminPassword = $_POST['admin_password'] ?? '';
        $adminPhone = trim($_POST['admin_phone'] ?? '');

        if (empty($adminName) || empty($adminEmail) || empty($adminPassword)) {
            $_SESSION['flash_error'] = 'Name, email, and password are required.';
        } elseif (!validateEmail($adminEmail)) {
            $_SESSION['flash_error'] = 'Invalid email address.';
        } elseif (strlen($adminPassword) < 6) {
            $_SESSION['flash_error'] = 'Password must be at least 6 characters.';
        } else {
            $checkStmt = $conn->prepare("SELECT id FROM tbl_admin WHERE email = ?");
            $checkStmt->bind_param('s', $adminEmail);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                $_SESSION['flash_error'] = 'An admin with this email already exists.';
            } else {
                $hashedPassword = hashPassword($adminPassword);
                $role = 'society_admin';
                $stmt = $conn->prepare("INSERT INTO tbl_admin (name, email, password_hash, role, society_id, phone, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                $stmt->bind_param('ssssis', $adminName, $adminEmail, $hashedPassword, $role, $societyId, $adminPhone);
                if ($stmt->execute()) {
                    $newAdminId = $stmt->insert_id;
                    $logStmt = $conn->prepare("INSERT INTO tbl_audit_log (society_id, admin_id, action, entity_type, entity_id, ip_address, details) VALUES (?, ?, 'create_society_admin', 'admin', ?, ?, ?)");
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $details = json_encode(['admin_email' => $adminEmail, 'society_id' => $societyId]);
                    $logStmt->bind_param('iiiss', $societyId, $_SESSION['admin_id'], $newAdminId, $ip, $details);
                    $logStmt->execute();
                    $logStmt->close();
                    $_SESSION['flash_success'] = 'Society admin created successfully.';
                } else {
                    $_SESSION['flash_error'] = 'Failed to create admin: ' . $conn->error;
                }
                $stmt->close();
            }
            $checkStmt->close();
        }
        header('Location: society_detail?id=' . $societyId);
        exit;
    }
}

$stmt = $conn->prepare("SELECT * FROM tbl_society WHERE id = ?");
$stmt->bind_param('i', $societyId);
$stmt->execute();
$society = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$society) {
    $_SESSION['flash_error'] = 'Society not found.';
    header('Location: societies');
    exit;
}

$towerStmt = $conn->prepare("SELECT * FROM tbl_tower WHERE society_id = ? ORDER BY name");
$towerStmt->bind_param('i', $societyId);
$towerStmt->execute();
$towers = $towerStmt->get_result();
$towerStmt->close();

$flatStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM tbl_flat f JOIN tbl_tower t ON f.tower_id = t.id WHERE t.society_id = ?");
$flatStmt->bind_param('i', $societyId);
$flatStmt->execute();
$flatCount = $flatStmt->get_result()->fetch_assoc()['cnt'];
$flatStmt->close();

$resStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM tbl_resident WHERE society_id = ? AND status = 'approved'");
$resStmt->bind_param('i', $societyId);
$resStmt->execute();
$residentCount = $resStmt->get_result()->fetch_assoc()['cnt'];
$resStmt->close();

$subStmt = $conn->prepare("SELECT * FROM tbl_subscription WHERE society_id = ? ORDER BY end_date DESC LIMIT 1");
$subStmt->bind_param('i', $societyId);
$subStmt->execute();
$subscription = $subStmt->get_result()->fetch_assoc();
$subStmt->close();

$adminStmt = $conn->prepare("SELECT * FROM tbl_admin WHERE society_id = ? ORDER BY created_at DESC");
$adminStmt->bind_param('i', $societyId);
$adminStmt->execute();
$admins = $adminStmt->get_result();
$adminStmt->close();

$pageTitle = htmlspecialchars($society['name']);
$towerCount = $towers->num_rows;

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600&display=swap');

:root {
  --accent:     #e94560;
  --accent-dim: rgba(233,69,96,.10);
  --blue:    #3b82f6; --blue-s:   #eff6ff;
  --green:   #10b981; --green-s:  #ecfdf5;
  --amber:   #f59e0b; --amber-s:  #fffbeb;
  --purple:  #8b5cf6; --purple-s: #f5f3ff;
  --teal:    #0ea5e9; --teal-s:   #f0f9ff;
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

.det-page {
  font-family: 'DM Sans', sans-serif;
  padding: 28px 32px 72px;
  background: var(--bg);
  min-height: 100vh;
  color: var(--txt1);
}

/* ── Page Header ── */
.pg-head {
  display: flex; align-items: flex-start; justify-content: space-between;
  margin-bottom: 24px; flex-wrap: wrap; gap: 14px;
}
.pg-head-left { display: flex; align-items: center; gap: 14px; }
.pg-head-avatar {
  width: 52px; height: 52px;
  background: var(--accent);
  border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 16px rgba(233,69,96,.28);
  font-family: 'Outfit', sans-serif;
  font-size: 16px; font-weight: 800; color: #fff;
  flex-shrink: 0; letter-spacing: .5px;
}
.pg-head-text h1 {
  font-family: 'Outfit', sans-serif;
  font-size: 20px; font-weight: 800;
  color: var(--txt1); letter-spacing: -.3px; line-height: 1.1; margin: 0;
}
.pg-head-text p {
  font-size: 12px; font-weight: 500; color: var(--txt3); margin-top: 3px;
}
.pg-breadcrumb {
  display: flex; align-items: center; gap: 6px;
  font-size: 12px; font-weight: 500; color: var(--txt3); margin-top: 3px;
}
.pg-breadcrumb a { color: var(--txt3); text-decoration: none; transition: color .14s; }
.pg-breadcrumb a:hover { color: var(--accent); }

.status-badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 5px 13px; border-radius: 20px;
  font-size: 12px; font-weight: 700;
}
.status-badge .dot { width: 6px; height: 6px; border-radius: 50%; }
.s-active    { background: #ecfdf5; color: #059669; }
.s-active .dot { background: #10b981; }
.s-suspended { background: #fff1f2; color: #be123c; }
.s-suspended .dot { background: #e94560; }

/* ── Stat Cards Row ── */
.stats-strip {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 20px;
}
@media(max-width:900px)  { .stats-strip { grid-template-columns: repeat(2,1fr); } }
@media(max-width:480px)  { .stats-strip { grid-template-columns: 1fr 1fr; } }

.ssc {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: 20px 20px 18px;
  position: relative; overflow: hidden;
  box-shadow: var(--shadow);
  transition: transform .2s, box-shadow .2s;
  animation: fadeUp .4s ease both;
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
.ssc::before {
  content:''; position:absolute;
  left:0; top:14px; bottom:14px;
  width:3px; border-radius:0 3px 3px 0;
  background:var(--c);
}
.ssc-icon {
  width: 40px; height: 40px; border-radius: 11px;
  background: var(--cs); color: var(--c);
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; margin-bottom: 14px;
}
.ssc-val {
  font-family: 'Outfit', sans-serif;
  font-size: 30px; font-weight: 800;
  line-height: 1; letter-spacing: -.5px;
  color: var(--txt1); margin-bottom: 3px;
}
.ssc-lbl {
  font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .08em;
  color: var(--txt3);
}

/* ── Main grid ── */
.main-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 16px;
}
@media(max-width:900px) { .main-grid { grid-template-columns: 1fr; } }

/* ── Panel ── */
.panel {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--r);
  overflow: hidden;
  box-shadow: var(--shadow);
}
.panel + .panel { margin-top: 16px; }

.panel-hdr {
  display: flex; align-items: center; justify-content: space-between;
  padding: 15px 20px;
  border-bottom: 1px solid #f0f2f8;
}
.panel-title {
  font-family: 'Outfit', sans-serif;
  font-size: 13.5px; font-weight: 700; color: var(--txt1);
  display: flex; align-items: center; gap: 9px;
}
.panel-icon {
  width: 28px; height: 28px; border-radius: 7px;
  background: var(--accent-dim); color: var(--accent);
  display: flex; align-items: center; justify-content: center;
  font-size: 11px;
}

/* Info table */
.info-tbl { width: 100%; border-collapse: collapse; }
.info-tbl tr { border-bottom: 1px solid #f4f5fb; }
.info-tbl tr:last-child { border-bottom: none; }
.info-tbl th {
  width: 38%; padding: 13px 20px;
  font-size: 11.5px; font-weight: 700;
  color: var(--txt3); text-align: left; vertical-align: middle;
}
.info-tbl td {
  padding: 13px 20px 13px 0;
  font-size: 13px; font-weight: 600; color: var(--txt1);
  vertical-align: middle;
}

.invite-chip {
  display: inline-flex; align-items: center; gap: 8px;
  background: #f7f8fc;
  border: 1.5px dashed var(--border);
  border-radius: 8px;
  padding: 5px 12px;
  font-family: 'Outfit', sans-serif;
  font-size: 13px; font-weight: 800;
  letter-spacing: .12em; color: var(--txt1);
}
.invite-chip button {
  background: none; border: none; cursor: pointer;
  color: var(--txt3); font-size: 11px; padding: 0;
  transition: color .14s;
}
.invite-chip button:hover { color: var(--accent); }

/* Sub details inside panel */
.sub-detail { padding: 18px 20px; }
.sub-detail-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 9px 0; border-bottom: 1px solid #f4f5fb;
  font-size: 13px;
}
.sub-detail-row:last-child { border-bottom: none; }
.sub-detail-key { font-weight: 600; color: var(--txt3); font-size: 12px; }
.sub-detail-val { font-weight: 700; color: var(--txt1); }

/* Towers / Admins table */
.inner-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.inner-tbl thead th {
  padding: 10px 16px;
  font-size: 10px; font-weight: 800;
  letter-spacing: .09em; text-transform: uppercase;
  color: var(--txt3); text-align: left;
  background: #f7f8fc;
  border-bottom: 1.5px solid var(--border);
}
.inner-tbl thead th:first-child { padding-left: 20px; }
.inner-tbl thead th:last-child  { padding-right: 20px; }
.inner-tbl tbody tr { border-bottom: 1px solid #f2f4fb; transition: background .1s; }
.inner-tbl tbody tr:last-child { border-bottom: none; }
.inner-tbl tbody tr:hover { background: #fafbfe; }
.inner-tbl tbody td { padding: 12px 16px; vertical-align: middle; color: var(--txt2); }
.inner-tbl tbody td:first-child { padding-left: 20px; }
.inner-tbl tbody td:last-child  { padding-right: 20px; }

/* badges */
.b { border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 700; display:inline-block; white-space:nowrap; }
.b-green  { background:#ecfdf5; color:#059669; }
.b-red    { background:#fff1f2; color:#be123c; }
.b-blue   { background:#eff6ff; color:#2563eb; }
.b-grey   { background:#f2f3f8; color:#6b7280; }
.b-purple { background:#f5f3ff; color:#7c3aed; }
.b-amber  { background:#fffbeb; color:#d97706; }

.btn-add-sm {
  display: inline-flex; align-items: center; gap: 6px;
  background: var(--accent); color: #fff;
  border: none; border-radius: 8px;
  padding: 7px 14px;
  font-family: 'Outfit', sans-serif;
  font-size: 12px; font-weight: 700;
  cursor: pointer;
  box-shadow: 0 3px 10px rgba(233,69,96,.25);
  transition: all .16s;
}
.btn-add-sm:hover { background: #c73550; transform: translateY(-1px); }

.empty-row { text-align: center; padding: 36px 20px; }
.empty-row i { font-size: 22px; opacity: .2; margin-bottom: 8px; display: block; }
.empty-row p { font-size: 12.5px; font-weight: 600; color: var(--txt3); margin: 0; }

/* Admin row avatar */
.adm-row { display: flex; align-items: center; gap: 9px; }
.adm-av {
  width: 32px; height: 32px; border-radius: 8px;
  font-family: 'Outfit', sans-serif;
  font-size: 10px; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
  background: var(--blue-s); color: var(--blue); flex-shrink: 0;
}
.adm-name { font-size: 13px; font-weight: 700; color: var(--txt1); }
.adm-email { font-size: 11px; color: var(--txt3); margin-top: 1px; }

/* ── Modal ── */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(15,23,41,.45);
  backdrop-filter: blur(4px);
  z-index: 9999;
  align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-box {
  background: var(--card); border-radius: 18px;
  width: 100%; max-width: 460px; margin: 16px;
  overflow: hidden;
  box-shadow: 0 20px 60px rgba(15,23,41,.25);
  animation: modalIn .25s ease;
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
  font-family: 'Outfit', sans-serif;
  font-size: 15px; font-weight: 800; color: var(--txt1);
  display: flex; align-items: center; gap: 10px;
}
.modal-title-icon {
  width: 30px; height: 30px; border-radius: 8px;
  background: var(--accent-dim); color: var(--accent);
  display: flex; align-items: center; justify-content: center; font-size: 12px;
}
.modal-close {
  width: 28px; height: 28px; border-radius: 7px;
  background: #f2f3f8; border: none; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; color: var(--txt3); transition: all .14s;
}
.modal-close:hover { background: var(--accent-dim); color: var(--accent); }
.modal-body { padding: 20px 24px; }
.form-group { margin-bottom: 14px; }
.form-label-sm {
  font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .06em;
  color: var(--txt2); margin-bottom: 6px; display: block;
}
.form-req { color: var(--accent); }
.form-ctrl {
  width: 100%; height: 40px;
  border: 1.5px solid var(--border); border-radius: 9px;
  padding: 0 14px;
  font-family: 'DM Sans', sans-serif;
  font-size: 13.5px; color: var(--txt1);
  background: #f7f8fc; outline: none;
  transition: border-color .15s, background .15s;
}
.form-ctrl:focus { border-color: var(--accent); background: #fff; }
.form-ctrl::placeholder { color: var(--txt3); }
.modal-ftr {
  display: flex; align-items: center; justify-content: flex-end; gap: 10px;
  padding: 14px 24px 20px; border-top: 1px solid #f0f2f8;
}
.btn-cancel {
  height: 38px; padding: 0 18px; background: #f2f3f8; border: none; border-radius: 9px;
  font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 600;
  color: var(--txt2); cursor: pointer; transition: background .15s;
}
.btn-cancel:hover { background: #e5e7ef; }
.btn-submit {
  height: 38px; padding: 0 22px; background: var(--accent); border: none; border-radius: 9px;
  font-family: 'Outfit', sans-serif; font-size: 13px; font-weight: 700;
  color: #fff; cursor: pointer;
  box-shadow: 0 3px 12px rgba(233,69,96,.28);
  transition: all .16s;
}
.btn-submit:hover { background: #c73550; transform: translateY(-1px); }

@media(max-width:768px) { .det-page { padding: 18px 16px 60px; } }
</style>

<div class="det-page">

  <!-- Page Header -->
  <div class="pg-head">
    <div class="pg-head-left">
      <div class="pg-head-avatar"><?php echo strtoupper(mb_substr(trim($society['name']),0,2)); ?></div>
      <div class="pg-head-text">
        <h1><?php echo htmlspecialchars($society['name']); ?></h1>
        <div class="pg-breadcrumb">
          <a href="dashboard">Dashboard</a>
          <span>/</span>
          <a href="societies">Societies</a>
          <span>/</span>
          <span><?php echo htmlspecialchars($society['name']); ?></span>
        </div>
      </div>
    </div>
    <?php $s = strtolower($society['status']); ?>
    <span class="status-badge s-<?php echo $s; ?>">
      <span class="dot"></span>
      <?php echo ucfirst($society['status']); ?>
    </span>
  </div>

  <!-- Stats Strip -->
  <div class="stats-strip">
    <div class="ssc" style="--c:var(--blue);--cs:var(--blue-s);">
      <div class="ssc-icon"><i class="fas fa-layer-group"></i></div>
      <div class="ssc-val"><?php echo $towerCount; ?></div>
      <div class="ssc-lbl">Towers</div>
    </div>
    <div class="ssc" style="--c:var(--teal);--cs:var(--teal-s);">
      <div class="ssc-icon"><i class="fas fa-door-open"></i></div>
      <div class="ssc-val"><?php echo (int)$flatCount; ?></div>
      <div class="ssc-lbl">Flats</div>
    </div>
    <div class="ssc" style="--c:var(--green);--cs:var(--green-s);">
      <div class="ssc-icon"><i class="fas fa-users"></i></div>
      <div class="ssc-val"><?php echo (int)$residentCount; ?></div>
      <div class="ssc-lbl">Residents</div>
    </div>
    <div class="ssc" style="--c:var(--purple);--cs:var(--purple-s);">
      <div class="ssc-icon"><i class="fas fa-credit-card"></i></div>
      <?php if ($subscription): ?>
        <div class="ssc-val" style="font-size:18px;font-weight:800;margin-bottom:5px;"><?php echo htmlspecialchars($subscription['plan_name']); ?></div>
        <div class="ssc-lbl">Active Plan</div>
      <?php else: ?>
        <div class="ssc-val" style="font-size:18px;">None</div>
        <div class="ssc-lbl">Subscription</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Main Grid: Society Info + Subscription -->
  <div class="main-grid">

    <!-- Society Info -->
    <div class="panel">
      <div class="panel-hdr">
        <div class="panel-title">
          <span class="panel-icon"><i class="fas fa-info-circle"></i></span>
          Society Information
        </div>
      </div>
      <table class="info-tbl">
        <tr><th>Name</th><td><?php echo htmlspecialchars($society['name']); ?></td></tr>
        <tr><th>Address</th><td><?php echo htmlspecialchars($society['address'] ?? '—'); ?></td></tr>
        <tr><th>City</th><td><?php echo htmlspecialchars($society['city'] ?? '—'); ?></td></tr>
        <tr><th>State</th><td><?php echo htmlspecialchars($society['state'] ?? '—'); ?></td></tr>
        <tr><th>Pincode</th><td><?php echo htmlspecialchars($society['pincode'] ?? '—'); ?></td></tr>
        <tr>
          <th>Status</th>
          <td>
            <span class="status-badge s-<?php echo strtolower($society['status']); ?>">
              <span class="dot"></span><?php echo ucfirst($society['status']); ?>
            </span>
          </td>
        </tr>
        <tr>
          <th>Invite Code</th>
          <td>
            <div class="invite-chip" id="inviteChip">
              <?php echo htmlspecialchars($society['invite_code'] ?? 'N/A'); ?>
              <button onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($society['invite_code'] ?? ''); ?>');this.innerHTML='<i class=\'fas fa-check\'></i>'" title="Copy">
                <i class="fas fa-copy"></i>
              </button>
            </div>
          </td>
        </tr>
        <tr><th>Created</th><td><?php echo date('d M Y, H:i', strtotime($society['created_at'])); ?></td></tr>
      </table>
    </div>

    <!-- Subscription -->
    <div class="panel">
      <div class="panel-hdr">
        <div class="panel-title">
          <span class="panel-icon" style="background:var(--purple-s);color:var(--purple);"><i class="fas fa-credit-card"></i></span>
          Subscription Details
        </div>
        <?php if (!$subscription): ?>
          <a href="subscriptions" class="btn-add-sm"><i class="fas fa-plus"></i> Add Plan</a>
        <?php endif; ?>
      </div>
      <?php if ($subscription): ?>
      <div class="sub-detail">
        <div class="sub-detail-row">
          <span class="sub-detail-key">Plan Name</span>
          <span class="sub-detail-val"><?php echo htmlspecialchars($subscription['plan_name']); ?></span>
        </div>
        <div class="sub-detail-row">
          <span class="sub-detail-key">Max Flats</span>
          <span class="sub-detail-val"><?php echo (int)$subscription['max_flats']; ?></span>
        </div>
        <div class="sub-detail-row">
          <span class="sub-detail-key">Amount</span>
          <span class="sub-detail-val" style="font-family:'Outfit',sans-serif;">₹<?php echo number_format($subscription['amount'], 2); ?></span>
        </div>
        <div class="sub-detail-row">
          <span class="sub-detail-key">Start Date</span>
          <span class="sub-detail-val"><?php echo date('d M Y', strtotime($subscription['start_date'])); ?></span>
        </div>
        <div class="sub-detail-row">
          <span class="sub-detail-key">End Date</span>
          <span class="sub-detail-val"><?php echo date('d M Y', strtotime($subscription['end_date'])); ?></span>
        </div>
        <div class="sub-detail-row">
          <span class="sub-detail-key">Status</span>
          <span>
            <?php $ss = strtolower($subscription['status']); ?>
            <span class="b <?php echo $ss==='active'?'b-green':($ss==='expired'?'b-red':'b-grey'); ?>">
              <?php echo ucfirst($subscription['status']); ?>
            </span>
          </span>
        </div>
      </div>
      <?php else: ?>
      <div class="empty-row">
        <i class="fas fa-credit-card"></i>
        <p>No subscription assigned to this society</p>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- Towers Table -->
  <div class="panel" style="margin-bottom:16px;">
    <div class="panel-hdr">
      <div class="panel-title">
        <span class="panel-icon" style="background:var(--blue-s);color:var(--blue);"><i class="fas fa-layer-group"></i></span>
        Towers
      </div>
      <span style="font-size:11px;font-weight:700;background:#f0f2f8;color:var(--txt3);padding:3px 9px;border-radius:20px;"><?php echo $towerCount; ?> tower<?php echo $towerCount!=1?'s':''; ?></span>
    </div>
    <div style="overflow-x:auto;">
      <table class="inner-tbl">
        <thead>
          <tr>
            <th>#</th>
            <th>Tower Name</th>
            <th>Floors</th>
            <th>Flats</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($towerCount > 0): $towers->data_seek(0); $i=1;
            while($t = $towers->fetch_assoc()): ?>
          <tr>
            <td style="color:var(--txt3);font-size:11px;font-weight:700;"><?php echo $i++; ?></td>
            <td>
              <div style="font-weight:700;color:var(--txt1);"><?php echo htmlspecialchars($t['name']); ?></div>
            </td>
            <td style="font-family:'Outfit',sans-serif;font-weight:700;color:var(--txt1);"><?php echo (int)$t['total_floors']; ?></td>
            <td style="font-family:'Outfit',sans-serif;font-weight:700;color:var(--txt1);"><?php echo (int)$t['total_flats']; ?></td>
            <td style="font-size:12px;"><?php echo date('d M Y', strtotime($t['created_at'])); ?></td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="5"><div class="empty-row"><i class="fas fa-layer-group"></i><p>No towers added yet</p></div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Society Admins -->
  <div class="panel">
    <div class="panel-hdr">
      <div class="panel-title">
        <span class="panel-icon" style="background:var(--purple-s);color:var(--purple);"><i class="fas fa-users-cog"></i></span>
        Society Admins
      </div>
      <button class="btn-add-sm" onclick="document.getElementById('adminModal').classList.add('open')">
        <i class="fas fa-user-plus"></i> Create Admin
      </button>
    </div>
    <div style="overflow-x:auto;">
      <table class="inner-tbl">
        <thead>
          <tr>
            <th>#</th>
            <th>Admin</th>
            <th>Role</th>
            <th>Status</th>
            <th>Last Login</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($admins->num_rows > 0): $i=1;
            while($a = $admins->fetch_assoc()): ?>
          <tr>
            <td style="color:var(--txt3);font-size:11px;font-weight:700;"><?php echo $i++; ?></td>
            <td>
              <div class="adm-row">
                <div class="adm-av"><?php echo strtoupper(mb_substr(trim($a['name']),0,2)); ?></div>
                <div>
                  <div class="adm-name"><?php echo htmlspecialchars($a['name']); ?></div>
                  <div class="adm-email"><?php echo htmlspecialchars($a['email']); ?></div>
                </div>
              </div>
            </td>
            <td><span class="b b-blue"><?php echo htmlspecialchars($a['role']); ?></span></td>
            <td>
              <?php $as = strtolower($a['status']); ?>
              <span class="b <?php echo $as==='active'?'b-green':'b-red'; ?>"><?php echo ucfirst($a['status']); ?></span>
            </td>
            <td style="font-size:12px;color:var(--txt2);">
              <?php echo $a['last_login'] ? date('d M Y, H:i', strtotime($a['last_login'])) : '<span style="color:var(--txt3);">Never</span>'; ?>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="5"><div class="empty-row"><i class="fas fa-users-cog"></i><p>No admins assigned yet</p></div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /det-page -->

<!-- Create Admin Modal -->
<div class="modal-overlay" id="adminModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-box">
    <div class="modal-hdr">
      <div class="modal-title">
        <span class="modal-title-icon"><i class="fas fa-user-plus"></i></span>
        Create Society Admin
      </div>
      <button class="modal-close" onclick="document.getElementById('adminModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="create_admin">
        <div class="form-group">
          <label class="form-label-sm">Full Name <span class="form-req">*</span></label>
          <input type="text" name="admin_name" class="form-ctrl" placeholder="Enter full name" required>
        </div>
        <div class="form-group">
          <label class="form-label-sm">Email Address <span class="form-req">*</span></label>
          <input type="email" name="admin_email" class="form-ctrl" placeholder="admin@example.com" required>
        </div>
        <div class="form-group">
          <label class="form-label-sm">Password <span class="form-req">*</span></label>
          <input type="password" name="admin_password" class="form-ctrl" placeholder="Min. 6 characters" minlength="6" required>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label-sm">Phone</label>
          <input type="text" name="admin_phone" class="form-ctrl" placeholder="Phone number">
        </div>
      </div>
      <div class="modal-ftr">
        <button type="button" class="btn-cancel" onclick="document.getElementById('adminModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn-submit"><i class="fas fa-user-plus" style="margin-right:6px;font-size:11px;"></i>Create Admin</button>
      </div>
    </form>
  </div>
</div>

<?php
$conn->close();
include __DIR__ . '/includes/footer.php';
?>