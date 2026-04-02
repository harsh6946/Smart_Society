<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';
require_once __DIR__ . '/../include/security.php';

$pageTitle = 'Societies';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $pincode = trim($_POST['pincode'] ?? '');

        if (empty($name)) {
            $_SESSION['flash_error'] = 'Society name is required.';
        } else {
            $inviteCode = generateInviteCode();
            $checkStmt = $conn->prepare("SELECT id FROM tbl_society WHERE invite_code = ?");
            $checkStmt->bind_param('s', $inviteCode);
            $checkStmt->execute();
            while ($checkStmt->get_result()->num_rows > 0) {
                $inviteCode = generateInviteCode();
                $checkStmt->bind_param('s', $inviteCode);
                $checkStmt->execute();
            }
            $checkStmt->close();

            $stmt = $conn->prepare("INSERT INTO tbl_society (name, address, city, state, pincode, invite_code, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
            $stmt->bind_param('ssssss', $name, $address, $city, $state, $pincode, $inviteCode);

            if ($stmt->execute()) {
                $newId = $stmt->insert_id;
                $logStmt = $conn->prepare("INSERT INTO tbl_audit_log (society_id, admin_id, action, entity_type, entity_id, ip_address, details) VALUES (?, ?, 'create_society', 'society', ?, ?, ?)");
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $details = json_encode(['name' => $name, 'invite_code' => $inviteCode]);
                $logStmt->bind_param('iiiss', $newId, $_SESSION['admin_id'], $newId, $ip, $details);
                $logStmt->execute();
                $logStmt->close();
                $_SESSION['flash_success'] = 'Society created successfully. Invite Code: ' . $inviteCode;
            } else {
                $_SESSION['flash_error'] = 'Failed to create society: ' . $conn->error;
            }
            $stmt->close();
        }
        header('Location: societies');
        exit;
    }

    if ($action === 'toggle_status') {
        $societyId = intval($_POST['society_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        if ($societyId > 0 && in_array($newStatus, ['active', 'suspended'])) {
            $stmt = $conn->prepare("UPDATE tbl_society SET status = ? WHERE id = ?");
            $stmt->bind_param('si', $newStatus, $societyId);
            if ($stmt->execute()) {
                $logStmt = $conn->prepare("INSERT INTO tbl_audit_log (society_id, admin_id, action, entity_type, entity_id, ip_address, details) VALUES (?, ?, 'toggle_society_status', 'society', ?, ?, ?)");
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $details = json_encode(['new_status' => $newStatus]);
                $logStmt->bind_param('iiiss', $societyId, $_SESSION['admin_id'], $societyId, $ip, $details);
                $logStmt->execute();
                $logStmt->close();
                $_SESSION['flash_success'] = 'Society status updated to ' . $newStatus . '.';
            } else {
                $_SESSION['flash_error'] = 'Failed to update status.';
            }
            $stmt->close();
        }
        header('Location: societies');
        exit;
    }
}

$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = '';
$params = [];
$types = '';
if ($search !== '') {
    $where = " WHERE (s.name LIKE ? OR s.city LIKE ?)";
    $searchParam = '%' . $search . '%';
    $params = [$searchParam, $searchParam];
    $types = 'ss';
}

$countSql = "SELECT COUNT(*) AS cnt FROM tbl_society s" . $where;
$countStmt = $conn->prepare($countSql);
if ($types) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRows = $countStmt->get_result()->fetch_assoc()['cnt'];
$countStmt->close();
$totalPages = max(1, ceil($totalRows / $perPage));

$sql = "SELECT s.*,
    (SELECT COUNT(*) FROM tbl_flat f JOIN tbl_tower t ON f.tower_id = t.id WHERE t.society_id = s.id) AS flat_count,
    (SELECT COUNT(*) FROM tbl_resident r WHERE r.society_id = s.id AND r.status = 'approved') AS resident_count,
    (SELECT sub.plan_name FROM tbl_subscription sub WHERE sub.society_id = s.id AND sub.status = 'active' ORDER BY sub.end_date DESC LIMIT 1) AS active_plan
    FROM tbl_society s" . $where . " ORDER BY s.created_at DESC LIMIT ? OFFSET ?";

$fetchStmt = $conn->prepare($sql);
$fetchTypes = $types . 'ii';
$fetchParams = array_merge($params, [$perPage, $offset]);
$fetchStmt->bind_param($fetchTypes, ...$fetchParams);
$fetchStmt->execute();
$societies = $fetchStmt->get_result();
$fetchStmt->close();

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
  --red:     #ef4444; --red-s:    #fef2f2;
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

.soc-page {
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
  width: 46px; height: 46px;
  background: var(--accent);
  border-radius: 13px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 14px rgba(233,69,96,.28);
  flex-shrink: 0;
}
.pg-head-icon i { color: #fff; font-size: 16px; }
.pg-head-text h1 {
  font-family: 'Outfit', sans-serif;
  font-size: 20px; font-weight: 800;
  color: var(--txt1); letter-spacing: -.3px; line-height: 1.1; margin: 0;
}
.pg-breadcrumb {
  display: flex; align-items: center; gap: 6px;
  font-size: 12px; font-weight: 500; color: var(--txt3);
  margin-top: 3px;
}
.pg-breadcrumb a { color: var(--txt3); text-decoration: none; transition: color .14s; }
.pg-breadcrumb a:hover { color: var(--accent); }
.pg-breadcrumb .sep { opacity: .4; }

.btn-add {
  display: inline-flex; align-items: center; gap: 7px;
  background: var(--accent);
  color: #fff;
  border: none; border-radius: 10px;
  padding: 10px 20px;
  font-family: 'Outfit', sans-serif;
  font-size: 13px; font-weight: 700;
  cursor: pointer;
  box-shadow: 0 4px 14px rgba(233,69,96,.30);
  transition: all .18s;
  text-decoration: none;
}
.btn-add:hover {
  background: #c73550; color: #fff;
  box-shadow: 0 6px 20px rgba(233,69,96,.40);
  transform: translateY(-1px);
}

/* ── Search Bar ── */
.search-bar {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: 18px 22px;
  margin-bottom: 18px;
  box-shadow: var(--shadow);
  display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap;
}
.search-field { flex: 1; min-width: 220px; }
.search-label {
  font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .07em;
  color: var(--txt3); margin-bottom: 6px; display: block;
}
.search-input {
  width: 100%; height: 40px;
  border: 1.5px solid var(--border);
  border-radius: 9px;
  padding: 0 14px;
  font-family: 'DM Sans', sans-serif;
  font-size: 13.5px; color: var(--txt1);
  background: #f7f8fc;
  outline: none; transition: border-color .15s, background .15s;
}
.search-input:focus { border-color: var(--accent); background: #fff; }
.search-input::placeholder { color: var(--txt3); }

.btn-search {
  height: 40px; padding: 0 20px;
  background: var(--txt1); color: #fff;
  border: none; border-radius: 9px;
  font-family: 'Outfit', sans-serif;
  font-size: 13px; font-weight: 700;
  cursor: pointer; transition: background .16s;
  display: flex; align-items: center; gap: 7px;
  white-space: nowrap;
}
.btn-search:hover { background: #1f2d47; }

.btn-clear {
  height: 40px; padding: 0 16px;
  background: transparent; color: var(--txt3);
  border: 1.5px solid var(--border); border-radius: 9px;
  font-family: 'DM Sans', sans-serif;
  font-size: 13px; font-weight: 600;
  cursor: pointer; transition: all .16s;
  text-decoration: none; display: flex; align-items: center;
}
.btn-clear:hover { border-color: var(--accent); color: var(--accent); }

/* ── Table Panel ── */
.tbl-panel {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--r);
  overflow: hidden;
  box-shadow: var(--shadow);
}

.tbl-panel-hdr {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 22px;
  border-bottom: 1px solid #f0f2f8;
}
.tbl-panel-title {
  font-family: 'Outfit', sans-serif;
  font-size: 13.5px; font-weight: 700; color: var(--txt1);
  display: flex; align-items: center; gap: 8px;
}
.tbl-panel-title-icon {
  width: 28px; height: 28px; border-radius: 7px;
  background: var(--accent-dim); color: var(--accent);
  display: flex; align-items: center; justify-content: center;
  font-size: 11px;
}
.tbl-count {
  font-size: 11px; font-weight: 700;
  background: #f0f2f8; color: var(--txt3);
  padding: 3px 9px; border-radius: 20px;
}

table.st { width: 100%; border-collapse: collapse; font-size: 13px; }
table.st thead tr { background: #f7f8fc; }
table.st thead th {
  padding: 11px 16px;
  font-size: 10px; font-weight: 800;
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

/* avatar cell */
.av-cell { display: flex; align-items: center; gap: 10px; }
.av-init {
  width: 36px; height: 36px; border-radius: 10px;
  font-family: 'Outfit', sans-serif;
  font-size: 11px; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
  background: var(--accent-dim); color: var(--accent);
  flex-shrink: 0; letter-spacing: .3px;
}
.av-name-lnk {
  font-size: 13.5px; font-weight: 700; color: var(--txt1);
  text-decoration: none; transition: color .13s;
}
.av-name-lnk:hover { color: var(--accent); }
.av-sub { font-size: 11px; color: var(--txt3); font-weight: 500; margin-top: 1px; }

/* status pill */
.st-pill {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 11px; border-radius: 20px;
  font-size: 11.5px; font-weight: 700;
}
.st-pill .dot { width: 6px; height: 6px; border-radius: 50%; }
.st-active   { background: #ecfdf5; color: #059669; }
.st-active .dot   { background: #10b981; }
.st-suspended { background: #fff1f2; color: #be123c; }
.st-suspended .dot { background: #e94560; }

/* badges */
.b { border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 700; display:inline-block; white-space:nowrap; }
.b-purple { background:#f5f3ff; color:#7c3aed; }
.b-grey   { background:#f2f3f8; color:#6b7280; }

/* action buttons */
.act-btns { display: flex; align-items: center; gap: 6px; }
.act-btn {
  width: 32px; height: 32px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; border: 1.5px solid var(--border);
  cursor: pointer; transition: all .16s; text-decoration: none;
  background: #f7f8fc; color: var(--txt2);
}
.act-btn:hover { transform: translateY(-1px); }
.act-btn.view:hover   { background: var(--blue-s); border-color: var(--blue); color: var(--blue); }
.act-btn.suspend:hover{ background: var(--red-s);  border-color: var(--red);  color: var(--red); }
.act-btn.activate:hover { background: var(--green-s); border-color: var(--green); color: var(--green); }
.act-btn-form { margin: 0; padding: 0; display: inline; }

/* empty */
.empty-state {
  text-align: center; padding: 60px 20px;
}
.empty-state i { font-size: 32px; color: var(--txt3); opacity: .3; margin-bottom: 12px; display: block; }
.empty-state p { font-size: 14px; font-weight: 600; color: var(--txt3); margin: 0; }

/* pagination */
.tbl-footer {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 22px;
  border-top: 1px solid #f0f2f8;
  flex-wrap: wrap; gap: 10px;
}
.pg-info { font-size: 12px; color: var(--txt3); font-weight: 500; }
.pg-links { display: flex; gap: 4px; align-items: center; }
.pg-link {
  min-width: 32px; height: 32px; padding: 0 10px;
  border-radius: 8px; border: 1.5px solid var(--border);
  background: var(--card); color: var(--txt2);
  font-size: 12px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  text-decoration: none; transition: all .15s; cursor: pointer;
}
.pg-link:hover:not(.disabled):not(.active) { border-color: var(--accent); color: var(--accent); }
.pg-link.active   { background: var(--accent); border-color: var(--accent); color: #fff; }
.pg-link.disabled { opacity: .35; pointer-events: none; }

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
  background: var(--card);
  border-radius: 18px;
  width: 100%; max-width: 480px;
  margin: 16px;
  overflow: hidden;
  box-shadow: 0 20px 60px rgba(15,23,41,.25);
  animation: modalIn .25s ease;
}
@keyframes modalIn {
  from { opacity:0; transform: scale(.95) translateY(10px); }
  to   { opacity:1; transform: scale(1) translateY(0); }
}

.modal-hdr {
  display: flex; align-items: center; justify-content: space-between;
  padding: 20px 24px 18px;
  border-bottom: 1px solid #f0f2f8;
}
.modal-title {
  font-family: 'Outfit', sans-serif;
  font-size: 16px; font-weight: 800; color: var(--txt1);
  display: flex; align-items: center; gap: 10px;
}
.modal-title-icon {
  width: 32px; height: 32px; border-radius: 8px;
  background: var(--accent-dim); color: var(--accent);
  display: flex; align-items: center; justify-content: center;
  font-size: 12px;
}
.modal-close {
  width: 30px; height: 30px; border-radius: 7px;
  background: #f2f3f8; border: none; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; color: var(--txt3);
  transition: background .14s, color .14s;
}
.modal-close:hover { background: var(--accent-dim); color: var(--accent); }

.modal-body { padding: 22px 24px; }

.form-group { margin-bottom: 16px; }
.form-label-sm {
  font-size: 12px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .06em;
  color: var(--txt2); margin-bottom: 6px; display: block;
}
.form-req { color: var(--accent); margin-left: 2px; }
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
textarea.form-ctrl { height: auto; padding: 10px 14px; resize: vertical; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

.modal-ftr {
  display: flex; align-items: center; justify-content: flex-end; gap: 10px;
  padding: 16px 24px 20px;
  border-top: 1px solid #f0f2f8;
}
.btn-cancel {
  height: 38px; padding: 0 18px;
  background: #f2f3f8; border: none; border-radius: 9px;
  font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 600;
  color: var(--txt2); cursor: pointer; transition: background .15s;
}
.btn-cancel:hover { background: #e5e7ef; }
.btn-submit {
  height: 38px; padding: 0 22px;
  background: var(--accent); border: none; border-radius: 9px;
  font-family: 'Outfit', sans-serif; font-size: 13px; font-weight: 700;
  color: #fff; cursor: pointer;
  box-shadow: 0 3px 12px rgba(233,69,96,.28);
  transition: all .16s;
}
.btn-submit:hover { background: #c73550; box-shadow: 0 5px 18px rgba(233,69,96,.35); transform: translateY(-1px); }

@media(max-width:768px) { .soc-page { padding: 18px 16px 60px; } }
</style>

<div class="soc-page">

  <!-- Page Header -->
  <div class="pg-head">
    <div class="pg-head-left">
      <div class="pg-head-icon"><i class="fas fa-building"></i></div>
      <div class="pg-head-text">
        <h1>Societies</h1>
        <div class="pg-breadcrumb">
          <a href="dashboard">Dashboard</a>
          <span class="sep">/</span>
          <span>Societies</span>
        </div>
      </div>
    </div>
    <button class="btn-add" onclick="document.getElementById('addModal').classList.add('open')">
      <i class="fas fa-plus"></i> Add Society
    </button>
  </div>

  <!-- Search Bar -->
  <form method="GET">
    <div class="search-bar">
      <div class="search-field">
        <label class="search-label">Search by Name or City</label>
        <input type="text" name="search" class="search-input" placeholder="Type to search..."
               value="<?php echo htmlspecialchars($search); ?>">
      </div>
      <button type="submit" class="btn-search"><i class="fas fa-search"></i> Search</button>
      <?php if ($search): ?>
        <a href="societies" class="btn-clear"><i class="fas fa-times" style="margin-right:5px;font-size:11px;"></i> Clear</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- Table Panel -->
  <div class="tbl-panel">
    <div class="tbl-panel-hdr">
      <div class="tbl-panel-title">
        <span class="tbl-panel-title-icon"><i class="fas fa-building"></i></span>
        All Societies
      </div>
      <span class="tbl-count"><?php echo $totalRows; ?> total</span>
    </div>

    <div style="overflow-x:auto;">
      <table class="st">
        <thead>
          <tr>
            <th>#</th>
            <th>Society</th>
            <th>City</th>
            <th>Status</th>
            <th>Flats</th>
            <th>Residents</th>
            <th>Plan</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($societies->num_rows > 0): $i = $offset + 1;
            while ($row = $societies->fetch_assoc()): ?>
          <tr>
            <td style="color:var(--txt3);font-size:11px;font-weight:700;width:30px;"><?php echo $i++; ?></td>
            <td>
              <div class="av-cell">
                <div class="av-init"><?php echo strtoupper(mb_substr(trim($row['name']),0,2)); ?></div>
                <div>
                  <a href="society_detail?id=<?php echo (int)$row['id']; ?>" class="av-name-lnk">
                    <?php echo htmlspecialchars($row['name']); ?>
                  </a>
                  <div class="av-sub"><?php echo htmlspecialchars($row['address'] ?? ''); ?></div>
                </div>
              </div>
            </td>
            <td style="font-size:12.5px;color:var(--txt2);"><?php echo htmlspecialchars($row['city'] ?? '—'); ?></td>
            <td>
              <?php $s = strtolower($row['status']); ?>
              <span class="st-pill st-<?php echo $s; ?>">
                <span class="dot"></span>
                <?php echo ucfirst($row['status']); ?>
              </span>
            </td>
            <td style="font-family:'Outfit',sans-serif;font-weight:700;color:var(--txt1);"><?php echo (int)$row['flat_count']; ?></td>
            <td style="font-family:'Outfit',sans-serif;font-weight:700;color:var(--txt1);"><?php echo (int)$row['resident_count']; ?></td>
            <td>
              <?php if ($row['active_plan']): ?>
                <span class="b b-purple"><?php echo htmlspecialchars($row['active_plan']); ?></span>
              <?php else: ?>
                <span class="b b-grey">None</span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;color:var(--txt2);"><?php echo date('d M Y', strtotime($row['created_at'])); ?></td>
            <td>
              <div class="act-btns">
                <a href="society_detail?id=<?php echo (int)$row['id']; ?>" class="act-btn view" title="View Detail">
                  <i class="fas fa-eye"></i>
                </a>
                <?php if ($row['status'] === 'active'): ?>
                  <form method="POST" class="act-btn-form" onsubmit="return confirm('Suspend this society?');">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="society_id" value="<?php echo (int)$row['id']; ?>">
                    <input type="hidden" name="new_status" value="suspended">
                    <button type="submit" class="act-btn suspend" title="Suspend"><i class="fas fa-ban"></i></button>
                  </form>
                <?php else: ?>
                  <form method="POST" class="act-btn-form" onsubmit="return confirm('Activate this society?');">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="society_id" value="<?php echo (int)$row['id']; ?>">
                    <input type="hidden" name="new_status" value="active">
                    <button type="submit" class="act-btn activate" title="Activate"><i class="fas fa-check"></i></button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr>
            <td colspan="9">
              <div class="empty-state">
                <i class="fas fa-building"></i>
                <p>No societies found<?php echo $search ? ' for "'.htmlspecialchars($search).'"' : ''; ?></p>
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
        Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $totalRows); ?> of <?php echo $totalRows; ?>
      </div>
      <div class="pg-links">
        <a class="pg-link <?php echo $page <= 1 ? 'disabled' : ''; ?>"
           href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>">
          <i class="fas fa-chevron-left" style="font-size:10px;"></i>
        </a>
        <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
          <a class="pg-link <?php echo $p===$page?'active':''; ?>"
             href="?page=<?php echo $p; ?>&search=<?php echo urlencode($search); ?>">
            <?php echo $p; ?>
          </a>
        <?php endfor; ?>
        <a class="pg-link <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"
           href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>">
          <i class="fas fa-chevron-right" style="font-size:10px;"></i>
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /soc-page -->

<!-- Add Society Modal -->
<div class="modal-overlay" id="addModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-box">
    <div class="modal-hdr">
      <div class="modal-title">
        <span class="modal-title-icon"><i class="fas fa-plus"></i></span>
        Add New Society
      </div>
      <button class="modal-close" onclick="document.getElementById('addModal').classList.remove('open')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="create">
        <div class="form-group">
          <label class="form-label-sm">Society Name <span class="form-req">*</span></label>
          <input type="text" name="name" class="form-ctrl" placeholder="Enter society name" required>
        </div>
        <div class="form-group">
          <label class="form-label-sm">Address</label>
          <textarea name="address" class="form-ctrl" rows="2" placeholder="Full address..."></textarea>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label-sm">City</label>
            <input type="text" name="city" class="form-ctrl" placeholder="City">
          </div>
          <div class="form-group">
            <label class="form-label-sm">State</label>
            <input type="text" name="state" class="form-ctrl" placeholder="State">
          </div>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label-sm">Pincode</label>
          <input type="text" name="pincode" class="form-ctrl" maxlength="10" placeholder="Pincode">
        </div>
      </div>
      <div class="modal-ftr">
        <button type="button" class="btn-cancel" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn-submit"><i class="fas fa-plus" style="margin-right:6px;font-size:11px;"></i>Create Society</button>
      </div>
    </form>
  </div>
</div>

<?php
$conn->close();
include __DIR__ . '/includes/footer.php';
?>