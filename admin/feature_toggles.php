<?php
require_once 'includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';

// Handle toggle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        $featureId = intval($_POST['feature_id'] ?? 0);
        $newValue  = intval($_POST['new_value'] ?? 0);
        if ($featureId > 0) {
            $stmt = $conn->prepare("UPDATE tbl_feature_toggle SET is_enabled = ?, updated_by = ? WHERE id = ?");
            $stmt->bind_param('iii', $newValue, $_SESSION['admin_id'], $featureId);
            $stmt->execute(); $stmt->close();
            $_SESSION['flash_success'] = 'Feature toggle updated.';
        }
    } elseif ($action === 'add_override') {
        $societyId  = intval($_POST['society_id'] ?? 0);
        $featureKey = trim($_POST['feature_key'] ?? '');
        $isEnabled  = intval($_POST['is_enabled'] ?? 0);
        if ($societyId > 0 && $featureKey !== '') {
            $stmt = $conn->prepare(
                "INSERT INTO tbl_feature_toggle (society_id, feature_key, is_enabled, updated_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE is_enabled = ?, updated_by = ?"
            );
            $stmt->bind_param('isiiii', $societyId, $featureKey, $isEnabled, $_SESSION['admin_id'], $isEnabled, $_SESSION['admin_id']);
            $stmt->execute(); $stmt->close();
            $_SESSION['flash_success'] = "Override added for society #$societyId.";
        }
    } elseif ($action === 'delete_override') {
        $featureId = intval($_POST['feature_id'] ?? 0);
        if ($featureId > 0) {
            $stmt = $conn->prepare("DELETE FROM tbl_feature_toggle WHERE id = ? AND society_id IS NOT NULL");
            $stmt->bind_param('i', $featureId); $stmt->execute(); $stmt->close();
            $_SESSION['flash_success'] = 'Override removed.';
        }
    }
    header('Location: feature_toggles'); exit;
}

// Fetch global toggles
$globals = $conn->query(
    "SELECT ft.*, a.name AS updated_by_name
     FROM tbl_feature_toggle ft
     LEFT JOIN tbl_admin a ON ft.updated_by = a.id
     WHERE ft.society_id IS NULL ORDER BY ft.feature_key"
)->fetch_all(MYSQLI_ASSOC);

// Fetch society overrides
$overrides = $conn->query(
    "SELECT ft.*, s.name AS society_name, a.name AS updated_by_name
     FROM tbl_feature_toggle ft
     JOIN tbl_society s ON ft.society_id = s.id
     LEFT JOIN tbl_admin a ON ft.updated_by = a.id
     WHERE ft.society_id IS NOT NULL ORDER BY s.name, ft.feature_key"
)->fetch_all(MYSQLI_ASSOC);

// Fetch societies for override dropdown
$societies = $conn->query("SELECT id, name FROM tbl_society WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Feature key meta
$featureLabels = [
    'fcm'              => ['Firebase Push Notifications', 'fas fa-bell',         'Requires FCM Server Key',           'amber'],
    'razorpay'         => ['Razorpay Payments',           'fas fa-credit-card',   'Requires Razorpay API keys',        'blue'],
    'agora'            => ['Agora Video/Audio Calling',   'fas fa-video',         'Requires Agora App ID',             'purple'],
    'whatsapp'         => ['WhatsApp Notifications',      'fab fa-whatsapp',      'Requires Gupshup/Twilio API key',   'green'],
    'dark_mode'        => ['Dark Mode',                   'fas fa-moon',          'App dark theme support',            'indigo'],
    'multi_language'   => ['Multi-Language (Hindi)',      'fas fa-language',      'Hindi language option in app',      'teal'],
    'visitor_analytics'=> ['Visitor Analytics',           'fas fa-chart-bar',     'Visitor statistics & charts',       'rose'],
    'intercom'         => ['Intercom Calling',            'fas fa-phone',         'Guard-to-resident calls via Agora', 'blue'],
    'auto_billing'     => ['Auto Bill Generation',        'fas fa-file-invoice',  'Monthly auto-generate via cron',    'amber'],
    'guard_shifts'     => ['Guard Shift Management',      'fas fa-user-shield',   'Guard schedule & handover',         'green'],
];

$enabledCount  = count(array_filter($globals, fn($g) => $g['is_enabled']));
$disabledCount = count($globals) - $enabledCount;

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
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

.ft-page {
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
  display: grid; grid-template-columns: repeat(4, 1fr);
  gap: 14px; margin-bottom: 24px;
}
@media(max-width:900px)  { .stats-strip { grid-template-columns: repeat(2,1fr); } }
@media(max-width:480px)  { .stats-strip { grid-template-columns: 1fr 1fr; } }

.ssc {
  background: var(--card); border: 1px solid var(--border);
  border-radius: var(--r); padding: 18px 18px 16px;
  position: relative; overflow: hidden; box-shadow: var(--shadow);
  transition: transform .2s, box-shadow .2s; animation: fadeUp .4s ease both; cursor: default;
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

/* ── Panel ── */
.panel {
  background: var(--card); border: 1px solid var(--border);
  border-radius: var(--r); overflow: hidden; box-shadow: var(--shadow);
  margin-bottom: 20px;
}
.panel-hdr {
  display: flex; align-items: center; justify-content: space-between;
  padding: 15px 22px; border-bottom: 1px solid #f0f2f8;
}
.panel-title {
  font-family: 'Outfit', sans-serif; font-size: 13.5px; font-weight: 700;
  color: var(--txt1); display: flex; align-items: center; gap: 9px;
}
.panel-title-icon {
  width: 28px; height: 28px; border-radius: 7px;
  background: var(--accent-dim); color: var(--accent);
  display: flex; align-items: center; justify-content: center; font-size: 11px;
}
.panel-sub {
  font-size: 11.5px; color: var(--txt3); font-weight: 500; margin-top: 1px;
}

/* ── Feature Cards Grid (Global) ── */
.features-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 1px;
  background: #f0f2f8;
}

.feat-card {
  background: var(--card); padding: 18px 22px;
  display: flex; align-items: center; gap: 14px;
  transition: background .12s;
}
.feat-card:hover { background: #fafbfe; }

.feat-icon-wrap {
  width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px;
}

.feat-info { flex: 1; min-width: 0; }
.feat-name {
  font-size: 13.5px; font-weight: 700; color: var(--txt1);
  margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.feat-key {
  font-size: 10.5px; font-weight: 700; font-family: monospace;
  color: var(--txt3); letter-spacing: .03em; margin-bottom: 3px;
}
.feat-note {
  font-size: 11px; color: var(--txt3); font-weight: 500;
}
.feat-meta {
  font-size: 10.5px; color: var(--txt3); margin-top: 4px;
}

/* Toggle switch */
.feat-toggle { flex-shrink: 0; display: flex; align-items: center; gap: 10px; }

.toggle-wrap { position: relative; }
.toggle-wrap input[type="checkbox"] { display: none; }
.toggle-label {
  display: block; width: 44px; height: 24px;
  background: #e2e4ef; border-radius: 50px; cursor: pointer;
  transition: background .22s; position: relative;
}
.toggle-label::after {
  content: '';
  position: absolute; top: 3px; left: 3px;
  width: 18px; height: 18px; border-radius: 50%;
  background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.18);
  transition: transform .22s, background .22s;
}
.toggle-wrap input:checked + .toggle-label { background: var(--green); }
.toggle-wrap input:checked + .toggle-label::after { transform: translateX(20px); }

/* Status pill (small) */
.st-pill {
  font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em;
  padding: 3px 9px; border-radius: 20px; white-space: nowrap;
}
.st-on  { background: var(--green-s); color: #059669; }
.st-off { background: #f2f3f8; color: #9ca3af; }

/* Submit on toggle change (hidden form) */
.toggle-form { display: inline; }

/* ── Override Table ── */
table.ot { width: 100%; border-collapse: collapse; font-size: 13px; }
table.ot thead tr { background: #f7f8fc; }
table.ot thead th {
  padding: 11px 16px; font-size: 10px; font-weight: 800;
  letter-spacing: .09em; text-transform: uppercase;
  color: var(--txt3); text-align: left; white-space: nowrap;
  border-bottom: 1.5px solid var(--border);
}
table.ot thead th:first-child { padding-left: 22px; }
table.ot thead th:last-child  { padding-right: 22px; }
table.ot tbody tr { border-bottom: 1px solid #f2f4fb; transition: background .1s; }
table.ot tbody tr:last-child { border-bottom: none; }
table.ot tbody tr:hover { background: #fafbfe; }
table.ot tbody td { padding: 13px 16px; vertical-align: middle; }
table.ot tbody td:first-child { padding-left: 22px; }
table.ot tbody td:last-child  { padding-right: 22px; }

.soc-cell { display: flex; align-items: center; gap: 9px; }
.soc-av {
  width: 32px; height: 32px; border-radius: 8px;
  font-family: 'Outfit', sans-serif; font-size: 10px; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
  background: var(--accent-dim); color: var(--accent); flex-shrink: 0;
}

.feat-cell { display: flex; align-items: center; gap: 8px; }
.feat-cell-icon {
  width: 28px; height: 28px; border-radius: 7px;
  display: flex; align-items: center; justify-content: center; font-size: 11px;
}

.act-btns { display: flex; align-items: center; gap: 6px; }
.act-btn {
  width: 32px; height: 32px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; border: 1.5px solid var(--border);
  cursor: pointer; transition: all .16s; background: #f7f8fc; color: var(--txt2);
}
.act-btn:hover { transform: translateY(-1px); }
.act-btn.del:hover { background: var(--rose-s); border-color: var(--rose); color: var(--rose); }
.act-btn-form { margin: 0; padding: 0; display: inline; }

.empty-state { text-align: center; padding: 50px 20px; }
.empty-state i { font-size: 28px; opacity: .15; margin-bottom: 10px; display: block; }
.empty-state p { font-size: 13px; font-weight: 600; color: var(--txt3); margin: 0; }

/* ── Modal ── */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(15,23,41,.45); backdrop-filter: blur(4px);
  z-index: 9999; align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-box {
  background: var(--card); border-radius: 18px;
  width: 100%; max-width: 440px; margin: 16px; overflow: hidden;
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
  width: 30px; height: 30px; border-radius: 7px; background: #f2f3f8;
  border: none; cursor: pointer; display: flex; align-items: center; justify-content: center;
  font-size: 13px; color: var(--txt3); transition: all .14s;
}
.modal-close:hover { background: var(--accent-dim); color: var(--accent); }
.modal-body { padding: 22px 24px; }
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
.form-ctrl-select {
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%239ca3af' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 12px center; padding-right: 34px;
}
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

@media(max-width:768px) { .ft-page { padding: 18px 16px 60px; } }
</style>

<?php
// Color map helper
$colorMap = [
  'amber'  => ['var(--amber)',  'var(--amber-s)'],
  'blue'   => ['var(--blue)',   'var(--blue-s)'],
  'green'  => ['var(--green)',  'var(--green-s)'],
  'purple' => ['var(--purple)', 'var(--purple-s)'],
  'teal'   => ['var(--teal)',   'var(--teal-s)'],
  'rose'   => ['var(--rose)',   'var(--rose-s)'],
  'indigo' => ['var(--indigo)', 'var(--indigo-s)'],
];
?>

<div class="ft-page">

  <!-- Page Header -->
  <div class="pg-head">
    <div class="pg-head-left">
      <div class="pg-head-icon"><i class="fas fa-toggle-on"></i></div>
      <div class="pg-head-text">
        <h1>Feature Toggles</h1>
        <div class="pg-breadcrumb">
          <a href="dashboard">Dashboard</a>
          <span>/</span>
          <span>Feature Toggles</span>
        </div>
      </div>
    </div>
    <button class="btn-add" onclick="document.getElementById('overrideModal').classList.add('open')">
      <i class="fas fa-plus"></i> Add Override
    </button>
  </div>

  <!-- Stats Strip -->
  <div class="stats-strip">
    <div class="ssc" style="--c:var(--green);--cs:var(--green-s);">
      <div class="ssc-icon"><i class="fas fa-check-circle"></i></div>
      <div class="ssc-val"><?php echo $enabledCount; ?></div>
      <div class="ssc-lbl">Enabled</div>
    </div>
    <div class="ssc" style="--c:var(--txt3);--cs:#f2f3f8;">
      <div class="ssc-icon"><i class="fas fa-times-circle"></i></div>
      <div class="ssc-val"><?php echo $disabledCount; ?></div>
      <div class="ssc-lbl">Disabled</div>
    </div>
    <div class="ssc" style="--c:var(--blue);--cs:var(--blue-s);">
      <div class="ssc-icon"><i class="fas fa-globe"></i></div>
      <div class="ssc-val"><?php echo count($globals); ?></div>
      <div class="ssc-lbl">Global Features</div>
    </div>
    <div class="ssc" style="--c:var(--purple);--cs:var(--purple-s);">
      <div class="ssc-icon"><i class="fas fa-building"></i></div>
      <div class="ssc-val"><?php echo count($overrides); ?></div>
      <div class="ssc-lbl">Society Overrides</div>
    </div>
  </div>

  <!-- Global Defaults Panel -->
  <div class="panel">
    <div class="panel-hdr">
      <div>
        <div class="panel-title">
          <span class="panel-title-icon"><i class="fas fa-globe"></i></span>
          Global Defaults
        </div>
        <div class="panel-sub">Apply to all societies unless overridden per-society</div>
      </div>
    </div>

    <div class="features-grid">
      <?php foreach ($globals as $g):
        $meta  = $featureLabels[$g['feature_key']] ?? [$g['feature_key'], 'fas fa-cog', '', 'blue'];
        $clr   = $colorMap[$meta[3] ?? 'blue'] ?? $colorMap['blue'];
        $on    = (bool)$g['is_enabled'];
      ?>
      <div class="feat-card">
        <div class="feat-icon-wrap" style="background:<?php echo $clr[1]; ?>;color:<?php echo $clr[0]; ?>;">
          <i class="<?php echo $meta[1]; ?>"></i>
        </div>
        <div class="feat-info">
          <div class="feat-name"><?php echo htmlspecialchars($meta[0]); ?></div>
          <div class="feat-key"><?php echo $g['feature_key']; ?></div>
          <?php if ($meta[2]): ?>
            <div class="feat-note"><?php echo htmlspecialchars($meta[2]); ?></div>
          <?php endif; ?>
          <?php if ($g['updated_by_name'] || $g['updated_at']): ?>
            <div class="feat-meta">
              <?php if ($g['updated_by_name']): ?>
                <i class="fas fa-user" style="font-size:9px;margin-right:3px;"></i><?php echo htmlspecialchars($g['updated_by_name']); ?>
              <?php endif; ?>
              <?php if ($g['updated_at']): ?>
                &nbsp;· <?php echo date('d M, H:i', strtotime($g['updated_at'])); ?>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="feat-toggle">
          <span class="st-pill <?php echo $on ? 'st-on' : 'st-off'; ?>">
            <?php echo $on ? 'On' : 'Off'; ?>
          </span>
          <form method="POST" class="toggle-form">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="feature_id" value="<?php echo $g['id']; ?>">
            <input type="hidden" name="new_value" value="<?php echo $on ? 0 : 1; ?>">
            <div class="toggle-wrap">
              <input type="checkbox" id="tog_<?php echo $g['id']; ?>"
                     <?php echo $on ? 'checked' : ''; ?>
                     onchange="this.closest('form').submit()">
              <label for="tog_<?php echo $g['id']; ?>" class="toggle-label"></label>
            </div>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Society Overrides Panel -->
  <div class="panel">
    <div class="panel-hdr">
      <div>
        <div class="panel-title">
          <span class="panel-title-icon" style="background:var(--purple-s);color:var(--purple);"><i class="fas fa-building"></i></span>
          Society Overrides
        </div>
        <div class="panel-sub">Per-society feature settings that override global defaults</div>
      </div>
      <span style="font-size:11px;font-weight:700;background:#f0f2f8;color:var(--txt3);padding:3px 9px;border-radius:20px;">
        <?php echo count($overrides); ?> override<?php echo count($overrides) !== 1 ? 's' : ''; ?>
      </span>
    </div>

    <?php if (empty($overrides)): ?>
      <div class="empty-state">
        <i class="fas fa-building"></i>
        <p>No society overrides — all societies use global defaults</p>
      </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="ot">
        <thead>
          <tr>
            <th>Society</th>
            <th>Feature</th>
            <th>Status</th>
            <th>Toggle</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($overrides as $o):
            $meta = $featureLabels[$o['feature_key']] ?? [$o['feature_key'], 'fas fa-cog', '', 'blue'];
            $clr  = $colorMap[$meta[3] ?? 'blue'] ?? $colorMap['blue'];
            $on   = (bool)$o['is_enabled'];
          ?>
          <tr>
            <td>
              <div class="soc-cell">
                <div class="soc-av"><?php echo strtoupper(mb_substr(trim($o['society_name']),0,2)); ?></div>
                <span style="font-size:13px;font-weight:700;color:var(--txt1);"><?php echo htmlspecialchars($o['society_name']); ?></span>
              </div>
            </td>
            <td>
              <div class="feat-cell">
                <div class="feat-cell-icon" style="background:<?php echo $clr[1]; ?>;color:<?php echo $clr[0]; ?>;">
                  <i class="<?php echo $meta[1]; ?>"></i>
                </div>
                <div>
                  <div style="font-size:13px;font-weight:700;color:var(--txt1);"><?php echo htmlspecialchars($meta[0]); ?></div>
                  <div style="font-size:10.5px;font-family:monospace;color:var(--txt3);"><?php echo $o['feature_key']; ?></div>
                </div>
              </div>
            </td>
            <td>
              <span class="st-pill <?php echo $on ? 'st-on' : 'st-off'; ?>">
                <?php echo $on ? 'Enabled' : 'Disabled'; ?>
              </span>
            </td>
            <td>
              <form method="POST" class="toggle-form">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="feature_id" value="<?php echo $o['id']; ?>">
                <input type="hidden" name="new_value" value="<?php echo $on ? 0 : 1; ?>">
                <div class="toggle-wrap">
                  <input type="checkbox" id="otog_<?php echo $o['id']; ?>"
                         <?php echo $on ? 'checked' : ''; ?>
                         onchange="this.closest('form').submit()">
                  <label for="otog_<?php echo $o['id']; ?>" class="toggle-label"></label>
                </div>
              </form>
            </td>
            <td>
              <div class="act-btns">
                <form method="POST" class="act-btn-form"
                      onsubmit="return confirm('Remove override? Society will revert to global default.');">
                  <input type="hidden" name="action" value="delete_override">
                  <input type="hidden" name="feature_id" value="<?php echo $o['id']; ?>">
                  <button type="submit" class="act-btn del" title="Remove Override">
                    <i class="fas fa-trash"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /ft-page -->

<!-- Add Override Modal -->
<div class="modal-overlay" id="overrideModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-box">
    <div class="modal-hdr">
      <div class="modal-title">
        <span class="modal-title-icon"><i class="fas fa-plus"></i></span>
        Add Society Override
      </div>
      <button class="modal-close" onclick="document.getElementById('overrideModal').classList.remove('open')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="add_override">
        <div class="form-group">
          <label class="form-label-sm">Society <span class="form-req">*</span></label>
          <select name="society_id" class="form-ctrl form-ctrl-select" required>
            <option value="">Select Society...</option>
            <?php foreach ($societies as $s): ?>
              <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label-sm">Feature <span class="form-req">*</span></label>
          <select name="feature_key" class="form-ctrl form-ctrl-select" required>
            <?php foreach ($featureLabels as $key => $info): ?>
              <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($info[0]); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label-sm">Override Status <span class="form-req">*</span></label>
          <select name="is_enabled" class="form-ctrl form-ctrl-select">
            <option value="1">Enabled</option>
            <option value="0">Disabled</option>
          </select>
        </div>
      </div>
      <div class="modal-ftr">
        <button type="button" class="btn-cancel" onclick="document.getElementById('overrideModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn-submit"><i class="fas fa-save" style="margin-right:6px;font-size:11px;"></i>Save Override</button>
      </div>
    </form>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>