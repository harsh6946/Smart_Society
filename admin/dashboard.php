<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';

$pageTitle = 'Dashboard';

/* ── Stats ── */
$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_society");
$totalSocieties = (int)$res->fetch_assoc()['cnt'];

$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_society WHERE status = 'active'");
$activeSocieties = (int)$res->fetch_assoc()['cnt'];

$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_resident WHERE status = 'approved'");
$totalResidents = (int)$res->fetch_assoc()['cnt'];

$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_flat");
$totalFlats = (int)$res->fetch_assoc()['cnt'];

$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_subscription WHERE status = 'active'");
$activeSubscriptions = (int)$res->fetch_assoc()['cnt'];

$res = $conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM tbl_subscription WHERE status IN ('active', 'expired')");
$totalRevenue = (float)$res->fetch_assoc()['total'];

$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_resident WHERE status = 'pending'");
$pendingResidents = (int)$res->fetch_assoc()['cnt'];

$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_society WHERE status = 'suspended'");
$suspendedSocieties = (int)$res->fetch_assoc()['cnt'];

$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_subscription WHERE status = 'active' AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$expiringCount = (int)$res->fetch_assoc()['cnt'];

$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_admin WHERE status = 'active'");
$totalAdmins = (int)$res->fetch_assoc()['cnt'];

$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_audit_log WHERE action = 'login' AND DATE(created_at) = CURDATE()");
$loginsToday = (int)$res->fetch_assoc()['cnt'];

$res = $conn->query("SELECT COUNT(DISTINCT flat_id) AS cnt FROM tbl_resident WHERE status = 'approved' AND flat_id IS NOT NULL");
$occupiedFlats = (int)$res->fetch_assoc()['cnt'];
$occupancyRate = $totalFlats > 0 ? round(($occupiedFlats / $totalFlats) * 100) : 0;

/* ── Recent Societies ── */
$recentSocieties = $conn->query("
    SELECT s.*,
        (SELECT COUNT(*) FROM tbl_flat f JOIN tbl_tower t ON f.tower_id = t.id WHERE t.society_id = s.id) AS flat_count,
        (SELECT COUNT(*) FROM tbl_resident r WHERE r.society_id = s.id AND r.status = 'approved') AS resident_count,
        (SELECT sub.plan_name FROM tbl_subscription sub WHERE sub.society_id = s.id AND sub.status = 'active' ORDER BY sub.end_date DESC LIMIT 1) AS active_plan
    FROM tbl_society s ORDER BY s.created_at DESC LIMIT 8
");

/* ── Expiring Subs ── */
$expiringSubs = $conn->query("
    SELECT sub.*, s.name AS society_name, DATEDIFF(sub.end_date, CURDATE()) AS days_left
    FROM tbl_subscription sub
    JOIN tbl_society s ON sub.society_id = s.id
    WHERE sub.status = 'active' AND sub.end_date >= CURDATE()
    ORDER BY sub.end_date ASC LIMIT 5
");

/* ── Activity Feed ── */
$recentActivity = $conn->query("
    SELECT al.*, adm.name AS admin_name, s.name AS society_name
    FROM tbl_audit_log al
    LEFT JOIN tbl_admin adm ON al.admin_id = adm.id
    LEFT JOIN tbl_society s ON al.society_id = s.id
    ORDER BY al.created_at DESC LIMIT 7
");

/* ── Donut data ── */
$inactiveSocCount = max(0, $totalSocieties - $activeSocieties - $suspendedSocieties);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600&display=swap');

:root {
  --accent:      #e94560;
  --accent-dim:  rgba(233,69,96,.10);
  --accent-glow: rgba(233,69,96,.18);

  --blue:    #3b82f6; --blue-s:   #eff6ff;
  --green:   #10b981; --green-s:  #ecfdf5;
  --amber:   #f59e0b; --amber-s:  #fffbeb;
  --purple:  #8b5cf6; --purple-s: #f5f3ff;
  --teal:    #0ea5e9; --teal-s:   #f0f9ff;
  --rose:    #f43f5e; --rose-s:   #fff1f2;
  --indigo:  #6366f1; --indigo-s: #eef2ff;

  --bg:     #f0f2f8;
  --card:   #ffffff;
  --border: #e4e7f0;
  --txt1:   #0f1729;
  --txt2:   #4b5563;
  --txt3:   #9ca3af;
  --shadow: 0 1px 3px rgba(15,23,41,.06), 0 4px 16px rgba(15,23,41,.06);
  --shadow-md: 0 4px 20px rgba(15,23,41,.10);
  --r: 14px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

.dash {
  font-family: 'DM Sans', sans-serif;
  padding: 28px 32px 72px;
  background: var(--bg);
  min-height: 100vh;
  color: var(--txt1);
}

/* ═══════════════════════════════════
   PAGE HEADER
═══════════════════════════════════ */
.ph {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 28px;
  gap: 16px;
  flex-wrap: wrap;
}

.ph-left { display: flex; align-items: center; gap: 14px; }

.ph-icon-wrap {
  width: 46px; height: 46px;
  background: var(--accent);
  border-radius: 13px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 14px var(--accent-glow);
  flex-shrink: 0;
}
.ph-icon-wrap i { color: #fff; font-size: 16px; }

.ph-text h1 {
  font-family: 'Outfit', sans-serif;
  font-size: 20px;
  font-weight: 800;
  color: var(--txt1);
  letter-spacing: -.3px;
  line-height: 1.1;
}
.ph-text p {
  font-size: 12.5px;
  color: var(--txt3);
  font-weight: 500;
  margin-top: 2px;
}

.ph-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.ph-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 14px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
  text-decoration: none;
  transition: all .18s;
}
.ph-chip-neutral {
  background: var(--card);
  border: 1px solid var(--border);
  color: var(--txt2);
}
.ph-chip-warn {
  background: #fffbeb;
  border: 1px solid #fbbf24;
  color: #92400e;
}
.ph-chip-warn:hover { background: #fde68a; }
.ph-chip-danger {
  background: #fff1f2;
  border: 1px solid #fca5a5;
  color: #be123c;
}
.ph-chip-danger:hover { background: #fecdd3; }

/* ═══════════════════════════════════
   STAT CARDS  — ROW 1 & 2
═══════════════════════════════════ */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  margin-bottom: 16px;
}

@media(max-width:1200px) { .stats-grid { grid-template-columns: repeat(2,1fr); } }
@media(max-width:560px)  { .stats-grid { grid-template-columns: 1fr; } }

.sc {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: 22px 22px 18px;
  position: relative;
  overflow: hidden;
  transition: transform .22s, box-shadow .22s;
  cursor: default;
  animation: fadeUp .45s ease both;
}
.sc:hover {
  transform: translateY(-4px);
  box-shadow: var(--shadow-md);
}

@keyframes fadeUp {
  from { opacity:0; transform:translateY(18px); }
  to   { opacity:1; transform:translateY(0); }
}
.sc:nth-child(1){ animation-delay:.05s }
.sc:nth-child(2){ animation-delay:.10s }
.sc:nth-child(3){ animation-delay:.15s }
.sc:nth-child(4){ animation-delay:.20s }

/* left accent stripe */
.sc::before {
  content: '';
  position: absolute;
  left: 0; top: 14px; bottom: 14px;
  width: 3px;
  border-radius: 0 3px 3px 0;
  background: var(--c);
}

.sc-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 16px; }

.sc-icon {
  width: 44px; height: 44px;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 17px;
  background: var(--cs);
  color: var(--c);
  flex-shrink: 0;
}

.sc-tag {
  font-size: 11px; font-weight: 700;
  padding: 3px 9px;
  border-radius: 20px;
  background: var(--cs);
  color: var(--c);
  white-space: nowrap;
}

.sc-val {
  font-family: 'Outfit', sans-serif;
  font-size: 32px; font-weight: 800;
  line-height: 1;
  letter-spacing: -.5px;
  color: var(--txt1);
  margin-bottom: 3px;
}

.sc-label {
  font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .08em;
  color: var(--txt3);
}

.sc-footer {
  margin-top: 12px; padding-top: 12px;
  border-top: 1px solid #f0f2f8;
  font-size: 12px; font-weight: 500;
  color: var(--txt3);
  display: flex; align-items: center; gap: 6px;
}
.sc-footer strong { color: var(--txt2); font-weight: 700; }

/* Progress bar inside stat card */
.sc-bar { height: 4px; background: #ebebf3; border-radius: 50px; overflow: hidden; margin-top: 10px; }
.sc-bar-fill { height: 100%; border-radius: 50px; background: var(--c); transition: width 1.2s ease; }

/* ── Filled (revenue) card ── */
.sc.fill {
  background: linear-gradient(135deg, #e94560 0%, #c0203c 100%);
  border-color: transparent;
  box-shadow: 0 8px 28px rgba(233,69,96,.28);
}
.sc.fill::before { background: rgba(255,255,255,.3); }
.sc.fill .sc-icon { background: rgba(255,255,255,.18); color: #fff; }
.sc.fill .sc-val,
.sc.fill .sc-label { color: #fff; }
.sc.fill .sc-label { opacity: .7; }
.sc.fill .sc-footer { border-color: rgba(255,255,255,.15); color: rgba(255,255,255,.65); }
.sc.fill .sc-footer strong { color: #fff; }
.sc.fill .sc-tag { background: rgba(255,255,255,.2); color: #fff; }
.sc.fill:hover { box-shadow: 0 12px 36px rgba(233,69,96,.4); }

/* ═══════════════════════════════════
   MIDDLE SECTION
═══════════════════════════════════ */
.mid-section {
  display: grid;
  grid-template-columns: 260px 1fr 300px;
  gap: 16px;
  margin-bottom: 20px;
  align-items: stretch;  /* all panels grow to same height */
}

@media(max-width:1200px) { .mid-section { grid-template-columns: 1fr 1fr; } }
@media(max-width:740px)  { .mid-section { grid-template-columns: 1fr; } }

/* ═══════════════════════════════════
   PANEL SHELL
═══════════════════════════════════ */
.panel {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--r);
  overflow: hidden;
  box-shadow: var(--shadow);
  animation: fadeUp .45s .2s ease both;
  display: flex;
  flex-direction: column;
  height: 100%;
}

.panel-hdr {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid #f0f2f8;
}

.panel-title {
  display: flex; align-items: center; gap: 10px;
  font-family: 'Outfit', sans-serif;
  font-size: 13.5px; font-weight: 700;
  color: var(--txt1);
  letter-spacing: -.1px;
}

.panel-title-icon {
  width: 30px; height: 30px;
  border-radius: 8px;
  background: var(--accent-dim);
  color: var(--accent);
  display: flex; align-items: center; justify-content: center;
  font-size: 11px;
  flex-shrink: 0;
}

.view-all {
  font-size: 11.5px; font-weight: 700;
  color: var(--accent);
  text-decoration: none;
  display: flex; align-items: center; gap: 4px;
  padding: 5px 12px;
  border-radius: 7px;
  background: var(--accent-dim);
  border: 1px solid rgba(233,69,96,.15);
  transition: all .16s;
  white-space: nowrap;
}
.view-all:hover { background: var(--accent); color: #fff; }
.view-all i { font-size: 9px; }

/* ═══════════════════════════════════
   DONUT CHART
═══════════════════════════════════ */
.donut-body {
  padding: 22px 22px 24px;
  display: flex; flex-direction: column; align-items: center; gap: 20px;
  flex: 1;
  justify-content: center;
}

.donut-wrap {
  position: relative; width: 148px; height: 148px;
}

.donut-center {
  position: absolute; inset: 0;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  pointer-events: none;
}

.donut-center-num {
  font-family: 'Outfit', sans-serif;
  font-size: 30px; font-weight: 800;
  color: var(--txt1); line-height: 1;
}
.donut-center-lbl {
  font-size: 10px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .08em;
  color: var(--txt3); margin-top: 2px;
}

.donut-legend { width: 100%; display: flex; flex-direction: column; gap: 10px; }

.dl-item { display: flex; align-items: center; gap: 10px; }
.dl-dot  { width: 9px; height: 9px; border-radius: 3px; flex-shrink: 0; }
.dl-lbl  { font-size: 12.5px; font-weight: 600; color: var(--txt2); flex: 1; }
.dl-cnt  { font-family: 'Outfit', sans-serif; font-size: 14px; font-weight: 800; color: var(--txt1); }
.dl-pct  { font-size: 11px; font-weight: 600; color: var(--txt3); min-width: 38px; text-align: right; }

/* ═══════════════════════════════════
   QUICK ACTIONS
═══════════════════════════════════ */
.qa-grid {
  padding: 16px;
  grid-template-columns: repeat(3, 1fr);
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  flex: 1; 
grid-auto-rows: 1fr; 
  height: 100%;           /* 🔥 IMPORTANT */
  align-content: stretch; 
}


.qa-item {
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 9px;
  padding: 18px 10px;
  background: #f7f8fc;
  border: 1.5px solid var(--border);
  border-radius: 12px;
  text-decoration: none;
  transition: all .2s;
  text-align: center;
  min-height: 90px;
    height: 100%;              /* 🔥 fill grid cell */         /* optional */
  justify-content: center;
}
.qa-item:hover {
  background: var(--card);
  border-color: var(--c);
  transform: translateY(-3px);
  box-shadow: 0 6px 20px rgba(0,0,0,.07);
}

.qa-icon {
  width: 42px; height: 42px;
  border-radius: 11px;
  background: var(--cs);
  color: var(--c);
  display: flex; align-items: center; justify-content: center;
  font-size: 15px;
  transition: transform .2s;
}
.qa-item:hover .qa-icon { transform: scale(1.1); }

.qa-lbl {
  font-size: 11.5px; font-weight: 700;
  line-height: 1.3;
  color: var(--txt2);
}
.qa-item:hover .qa-lbl { color: var(--c); }

/* ═══════════════════════════════════
   ACTIVITY FEED
═══════════════════════════════════ */
.feed-item {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 13px 20px;
  border-bottom: 1px solid #f4f5fb;
  transition: background .12s;
}
.feed-item:last-child { border-bottom: none; }
.feed-item:hover { background: #fafbfe; }

.feed-icon {
  width: 32px; height: 32px;
  border-radius: 9px;
  flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px;
}

.feed-body { flex: 1; min-width: 0; }
.feed-action { font-size: 12.5px; font-weight: 700; color: var(--txt1); }
.feed-who    { font-size: 11.5px; color: var(--txt3); font-weight: 500; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.feed-time   { font-size: 10.5px; color: var(--txt3); font-weight: 600; white-space: nowrap; flex-shrink: 0; padding-top: 2px; }

/* ═══════════════════════════════════
   BOTTOM ROW
═══════════════════════════════════ */
.bot-row {
  display: grid;
  grid-template-columns: 1fr 360px;
  gap: 16px;
  align-items: stretch;
}

@media(max-width:1000px) { .bot-row { grid-template-columns: 1fr; } }

/* ═══════════════════════════════════
   DATA TABLE
═══════════════════════════════════ */
.dt { width: 100%; border-collapse: collapse; font-size: 13px; }

.dt thead tr { background: #f7f8fc; }
.dt thead th {
  padding: 11px 14px;
  font-size: 10px; font-weight: 800;
  letter-spacing: .09em; text-transform: uppercase;
  color: var(--txt3); text-align: left;
  white-space: nowrap;
  border-bottom: 1.5px solid var(--border);
}
.dt thead th:first-child { padding-left: 22px; }
.dt thead th:last-child  { padding-right: 22px; }

.dt tbody tr { border-bottom: 1px solid #f2f4fb; transition: background .1s; }
.dt tbody tr:last-child { border-bottom: none; }
.dt tbody tr:hover { background: #fafbfe; }
.dt tbody td { padding: 13px 14px; vertical-align: middle; }
.dt tbody td:first-child { padding-left: 22px; }
.dt tbody td:last-child  { padding-right: 22px; }

/* avatar in table */
.av-row { display: flex; align-items: center; gap: 10px; }
.av {
  width: 34px; height: 34px;
  border-radius: 9px;
  font-family: 'Outfit', sans-serif;
  font-size: 11px; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
  background: var(--accent-dim);
  color: var(--accent);
  flex-shrink: 0;
}
.av-name {
  font-size: 13px; font-weight: 700;
  color: var(--txt1); text-decoration: none;
  transition: color .13s;
}
.av-name:hover { color: var(--accent); }
.av-sub { font-size: 11px; color: var(--txt3); font-weight: 500; margin-top: 1px; }

/* badges */
.b { border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 700; display:inline-block; white-space:nowrap; }
.b-green  { background:#edfaf5; color:#059669; }
.b-red    { background:#fff0f2; color:#e02044; }
.b-amber  { background:#fff8ec; color:#d97706; }
.b-grey   { background:#f2f3f8; color:#6b7280; }
.b-purple { background:#f5f3ff; color:#7c3aed; }
.b-blue   { background:#eff6ff; color:#2563eb; }

/* Expiring subs list */
.sub-item {
  display: flex; align-items: center; gap: 12px;
  padding: 13px 20px;
  border-bottom: 1px solid #f4f5fb;
  transition: background .12s;
}
.sub-item:last-child { border-bottom: none; }
.sub-item:hover { background: #fafbfe; }

.sub-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}

.sub-info { flex: 1; min-width: 0; }
.sub-name { font-size: 13px; font-weight: 700; color: var(--txt1); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sub-plan { font-size: 11px; color: var(--txt3); font-weight: 500; margin-top: 1px; }

.sub-days {
  text-align: right; flex-shrink: 0;
}
.sub-days-num {
  font-family: 'Outfit', sans-serif;
  font-size: 15px; font-weight: 800; line-height: 1;
}
.sub-days-lbl { font-size: 10px; color: var(--txt3); font-weight: 600; }

/* empty state */
.empty { text-align:center; padding:44px 20px; color:var(--txt3); }
.empty i { font-size:26px; margin-bottom:10px; display:block; opacity:.25; }
.empty p { font-size:13px; font-weight:600; margin:0; }

/* section label divider */
.section-label {
  font-size: 10px; font-weight: 800;
  text-transform: uppercase; letter-spacing: .1em;
  color: var(--txt3);
  margin: 24px 0 10px;
  display: flex; align-items: center; gap: 8px;
}
.section-label::after {
  content: '';
  flex: 1; height: 1px;
  background: var(--border);
}

@media(max-width:768px) { .dash { padding: 18px 16px 60px; } }
</style>

<div class="dash">

  <!-- ── PAGE HEADER ── -->
  <div class="ph">
    <div class="ph-left">
      <div class="ph-icon-wrap"><i class="fas fa-tachometer-alt"></i></div>
      <div class="ph-text">
        <h1>Dashboard</h1>
        <p>Good <?php echo (date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening')); ?>, <?php echo htmlspecialchars(explode(' ', $_SESSION['admin_name'] ?? 'Admin')[0]); ?> &mdash; <?php echo date('l, d F Y'); ?></p>
      </div>
    </div>
    <div class="ph-right">
      <?php if ($expiringCount > 0): ?>
        <a href="subscriptions" class="ph-chip ph-chip-warn">
          <i class="fas fa-exclamation-triangle"></i> <?php echo $expiringCount; ?> expiring soon
        </a>
      <?php endif; ?>
      <?php if ($pendingResidents > 0): ?>
        <a href="societies" class="ph-chip ph-chip-danger">
          <i class="fas fa-user-clock"></i> <?php echo $pendingResidents; ?> pending
        </a>
      <?php endif; ?>
      <div class="ph-chip ph-chip-neutral">
        <i class="fas fa-sign-in-alt" style="color:var(--accent);"></i>
        <?php echo $loginsToday; ?> login<?php echo $loginsToday!==1?'s':''; ?> today
      </div>
    </div>
  </div>

  <!-- ── PRIMARY STATS ── -->
  <div class="section-label">Overview</div>
  <div class="stats-grid" style="margin-bottom:16px;">

    <div class="sc" style="--c:var(--blue);--cs:var(--blue-s);">
      <div class="sc-header">
        <div class="sc-icon"><i class="fas fa-building"></i></div>
        <span class="sc-tag"><?php echo $activeSocieties; ?> active</span>
      </div>
      <div class="sc-val"><?php echo $totalSocieties; ?></div>
      <div class="sc-label">Total Societies</div>
      <div class="sc-footer">
        <i class="fas fa-ban" style="color:var(--accent);font-size:10px;"></i>
        <strong><?php echo $suspendedSocieties; ?></strong> suspended
      </div>
    </div>

    <div class="sc" style="--c:var(--teal);--cs:var(--teal-s);">
      <div class="sc-header">
        <div class="sc-icon"><i class="fas fa-users"></i></div>
        <?php if ($pendingResidents > 0): ?>
          <span class="sc-tag" style="background:#fffbeb;color:#d97706;">⚠ <?php echo $pendingResidents; ?> pending</span>
        <?php else: ?>
          <span class="sc-tag">All approved</span>
        <?php endif; ?>
      </div>
      <div class="sc-val"><?php echo $totalResidents; ?></div>
      <div class="sc-label">Approved Residents</div>
      <div class="sc-footer">
        <i class="fas fa-home" style="color:var(--teal);font-size:10px;"></i>
        <strong><?php echo $occupiedFlats; ?></strong> flats occupied
      </div>
    </div>

    <div class="sc" style="--c:var(--amber);--cs:var(--amber-s);">
      <div class="sc-header">
        <div class="sc-icon"><i class="fas fa-door-open"></i></div>
        <span class="sc-tag"><?php echo $occupancyRate; ?>% full</span>
      </div>
      <div class="sc-val"><?php echo $totalFlats; ?></div>
      <div class="sc-label">Total Flats</div>
      <div class="sc-bar"><div class="sc-bar-fill" style="width:<?php echo $occupancyRate; ?>%;"></div></div>
    </div>

    <div class="sc fill">
      <div class="sc-header">
        <div class="sc-icon"><i class="fas fa-indian-rupee-sign"></i></div>
        <span class="sc-tag"><?php echo $activeSubscriptions; ?> active subs</span>
      </div>
      <div class="sc-val">₹<?php echo number_format($totalRevenue, 0); ?></div>
      <div class="sc-label">Total Revenue</div>
      <div class="sc-footer">
        <i class="fas fa-credit-card" style="font-size:10px;"></i>
        <strong><?php echo $activeSubscriptions; ?></strong> subscriptions running
      </div>
    </div>

  </div>

  <!-- ── SECONDARY STATS ── -->
  <div class="stats-grid" style="margin-bottom:24px;">

    <div class="sc" style="--c:var(--green);--cs:var(--green-s);">
      <div class="sc-header">
        <div class="sc-icon"><i class="fas fa-check-circle"></i></div>
      </div>
      <div class="sc-val"><?php echo $activeSocieties; ?></div>
      <div class="sc-label">Active Societies</div>
    </div>

    <div class="sc" style="--c:var(--purple);--cs:var(--purple-s);">
      <div class="sc-header">
        <div class="sc-icon"><i class="fas fa-credit-card"></i></div>
      </div>
      <div class="sc-val"><?php echo $activeSubscriptions; ?></div>
      <div class="sc-label">Active Subscriptions</div>
    </div>

    <div class="sc" style="--c:var(--indigo);--cs:var(--indigo-s);">
      <div class="sc-header">
        <div class="sc-icon"><i class="fas fa-users-cog"></i></div>
      </div>
      <div class="sc-val"><?php echo $totalAdmins; ?></div>
      <div class="sc-label">Admin Users</div>
    </div>

    <?php if ($expiringCount > 0): ?>
    <div class="sc" style="--c:var(--amber);--cs:var(--amber-s);border-color:#fde68a;">
      <div class="sc-header">
        <div class="sc-icon"><i class="fas fa-exclamation-triangle"></i></div>
        <span class="sc-tag" style="background:#fffbeb;color:#d97706;">⚠ Alert</span>
      </div>
      <div class="sc-val"><?php echo $expiringCount; ?></div>
      <div class="sc-label">Expiring (30 Days)</div>
    </div>
    <?php else: ?>
    <div class="sc" style="--c:var(--rose);--cs:var(--rose-s);">
      <div class="sc-header">
        <div class="sc-icon"><i class="fas fa-home"></i></div>
      </div>
      <div class="sc-val"><?php echo $occupiedFlats; ?></div>
      <div class="sc-label">Occupied Flats</div>
    </div>
    <?php endif; ?>

  </div>

  <!-- ── MIDDLE SECTION ── -->
  <div class="section-label">Analytics &amp; Actions</div>
  <div class="mid-section">

    <!-- Society Status Donut -->
    <div class="panel">
      <div class="panel-hdr">
        <div class="panel-title">
          <span class="panel-title-icon"><i class="fas fa-chart-pie"></i></span>
          Society Status
        </div>
      </div>
      <div class="donut-body">
        <div class="donut-wrap">
          <canvas id="donutChart" width="148" height="148"></canvas>
          <div class="donut-center">
            <div class="donut-center-num"><?php echo $totalSocieties; ?></div>
            <div class="donut-center-lbl">Total</div>
          </div>
        </div>
        <div class="donut-legend">
          <?php
            $legendItems = [
              ['Active',    '#10b981', $activeSocieties],
              ['Suspended', '#f59e0b', $suspendedSocieties],
              ['Inactive',  '#d1d5db', $inactiveSocCount],
            ];
            foreach($legendItems as $li):
              $pct = $totalSocieties > 0 ? round(($li[2]/$totalSocieties)*100) : 0;
          ?>
          <div class="dl-item">
            <div class="dl-dot" style="background:<?php echo $li[1]; ?>;"></div>
            <div class="dl-lbl"><?php echo $li[0]; ?></div>
            <div class="dl-cnt"><?php echo $li[2]; ?></div>
            <div class="dl-pct"><?php echo $pct; ?>%</div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="panel">
      <div class="panel-hdr">
        <div class="panel-title">
          <span class="panel-title-icon"><i class="fas fa-bolt"></i></span>
          Quick Actions
        </div>
      </div>
      <div class="qa-grid">

        <a href="societies" class="qa-item" style="--c:var(--blue);--cs:var(--blue-s);">
          <div class="qa-icon"><i class="fas fa-plus"></i></div>
          <span class="qa-lbl">Add Society</span>
        </a>

        <a href="subscriptions" class="qa-item" style="--c:var(--green);--cs:var(--green-s);">
          <div class="qa-icon"><i class="fas fa-credit-card"></i></div>
          <span class="qa-lbl">New Subscription</span>
        </a>

        <a href="admins" class="qa-item" style="--c:var(--purple);--cs:var(--purple-s);">
          <div class="qa-icon"><i class="fas fa-user-plus"></i></div>
          <span class="qa-lbl">Add Admin</span>
        </a>

        <a href="feature_toggles" class="qa-item" style="--c:var(--teal);--cs:var(--teal-s);">
          <div class="qa-icon"><i class="fas fa-toggle-on"></i></div>
          <span class="qa-lbl">Feature Toggles</span>
        </a>

        <a href="audit_logs" class="qa-item" style="--c:var(--amber);--cs:var(--amber-s);">
          <div class="qa-icon"><i class="fas fa-clipboard-list"></i></div>
          <span class="qa-lbl">Audit Logs</span>
        </a>

        <a href="admins" class="qa-item" style="--c:var(--indigo);--cs:var(--indigo-s);">
          <div class="qa-icon"><i class="fas fa-key"></i></div>
          <span class="qa-lbl">Admin Permissions</span>
        </a>

      </div>
    </div>

    <!-- Recent Activity -->
    <div class="panel">
      <div class="panel-hdr">
        <div class="panel-title">
          <span class="panel-title-icon"><i class="fas fa-history"></i></span>
          Recent Activity
        </div>
        <a href="audit_logs" class="view-all">All Logs <i class="fas fa-chevron-right"></i></a>
      </div>
      <?php
        $actMeta = [
          'login'                 => ['#10b981','#ecfdf5','fa-sign-in-alt',  'Logged in'],
          'logout'                => ['#9ca3af','#f3f4f6','fa-sign-out-alt', 'Logged out'],
          'create_society'        => ['#3b82f6','#eff6ff','fa-building',     'Society created'],
          'create_society_admin'  => ['#8b5cf6','#f5f3ff','fa-user-shield',  'Society admin added'],
          'create_admin'          => ['#8b5cf6','#f5f3ff','fa-user-plus',    'Admin created'],
          'create_subscription'   => ['#e94560','#fff0f2','fa-credit-card',  'Subscription added'],
          'toggle_society_status' => ['#f59e0b','#fffbeb','fa-toggle-on',    'Status changed'],
          'toggle_status'         => ['#f59e0b','#fffbeb','fa-toggle-on',    'Status updated'],
        ];
        if ($recentActivity && $recentActivity->num_rows > 0):
          while($act = $recentActivity->fetch_assoc()):
            $m = $actMeta[$act['action']] ?? ['#9ca3af','#f3f4f6','fa-circle','Activity'];
      ?>
      <div class="feed-item">
        <div class="feed-icon" style="background:<?php echo $m[1]; ?>;color:<?php echo $m[0]; ?>;">
          <i class="fas <?php echo $m[2]; ?>"></i>
        </div>
        <div class="feed-body">
          <div class="feed-action"><?php echo $m[3]; ?></div>
          <div class="feed-who">
            <?php if($act['admin_name']): ?>by <?php echo htmlspecialchars($act['admin_name']); ?><?php endif; ?>
            <?php if($act['society_name']): ?> &mdash; <?php echo htmlspecialchars($act['society_name']); ?><?php endif; ?>
          </div>
        </div>
        <div class="feed-time"><?php echo date('d M, H:i', strtotime($act['created_at'])); ?></div>
      </div>
      <?php endwhile; else: ?>
      <div class="empty"><i class="fas fa-history"></i><p>No activity yet</p></div>
      <?php endif; ?>
    </div>

  </div>

  <!-- ── BOTTOM ROW ── -->
  <div class="section-label">Societies &amp; Subscriptions</div>
  <div class="bot-row">

    <!-- Recent Societies -->
    <div class="panel">
      <div class="panel-hdr">
        <div class="panel-title">
          <span class="panel-title-icon"><i class="fas fa-building"></i></span>
          Recent Societies
        </div>
        <a href="societies" class="view-all">View All <i class="fas fa-chevron-right"></i></a>
      </div>
      <div style="overflow-x:auto;">
        <table class="dt">
          <thead>
            <tr>
              <th>#</th>
              <th>Society</th>
              <th>City</th>
              <th>Flats</th>
              <th>Residents</th>
              <th>Plan</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($recentSocieties && $recentSocieties->num_rows > 0): $i=1;
              while($row=$recentSocieties->fetch_assoc()): ?>
            <tr>
              <td style="color:var(--txt3);font-size:11px;font-weight:700;width:30px;"><?php echo $i++; ?></td>
              <td>
                <div class="av-row">
                  <div class="av"><?php echo strtoupper(mb_substr(trim($row['name']),0,2)); ?></div>
                  <div>
                    <a href="society_detail?id=<?php echo (int)$row['id']; ?>" class="av-name">
                      <?php echo htmlspecialchars($row['name']); ?>
                    </a>
                    <div class="av-sub"><?php echo date('d M Y', strtotime($row['created_at'])); ?></div>
                  </div>
                </div>
              </td>
              <td style="font-size:12.5px;color:var(--txt2);"><?php echo htmlspecialchars($row['city'] ?? '—'); ?></td>
              <td style="font-family:'Outfit',sans-serif;font-weight:700;color:var(--txt1);"><?php echo (int)$row['flat_count']; ?></td>
              <td style="font-family:'Outfit',sans-serif;font-weight:700;color:var(--txt1);"><?php echo (int)$row['resident_count']; ?></td>
              <td>
                <?php if ($row['active_plan']): ?>
                  <span class="b b-purple"><?php echo htmlspecialchars($row['active_plan']); ?></span>
                <?php else: ?>
                  <span class="b b-grey">None</span>
                <?php endif; ?>
              </td>
              <td>
                <?php $s=strtolower($row['status']); ?>
                <span class="b <?php echo $s==='active'?'b-green':($s==='suspended'?'b-red':'b-grey'); ?>">
                  <?php echo ucfirst($row['status']); ?>
                </span>
              </td>
            </tr>
            <?php endwhile; else: ?>
            <tr>
              <td colspan="7"><div class="empty"><i class="fas fa-building"></i><p>No societies yet</p></div></td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Expiring Subscriptions -->
    <div class="panel">
      <div class="panel-hdr">
        <div class="panel-title">
          <span class="panel-title-icon" style="background:#fff8ec;color:var(--amber);"><i class="fas fa-clock"></i></span>
          Expiring Soon
        </div>
        <a href="subscriptions" class="view-all">View All <i class="fas fa-chevron-right"></i></a>
      </div>
      <?php
        if ($expiringSubs && $expiringSubs->num_rows > 0):
          while($sub = $expiringSubs->fetch_assoc()):
            $d = (int)$sub['days_left'];
            $dotColor  = $d <= 7 ? '#e94560' : ($d <= 14 ? '#f59e0b' : '#10b981');
            $numColor  = $d <= 7 ? 'var(--accent)' : ($d <= 14 ? 'var(--amber)' : 'var(--green)');
      ?>
      <div class="sub-item">
        <div class="sub-dot" style="background:<?php echo $dotColor; ?>;"></div>
        <div class="sub-info">
          <div class="sub-name"><?php echo htmlspecialchars($sub['society_name']); ?></div>
          <div class="sub-plan"><?php echo htmlspecialchars($sub['plan_name'] ?? 'Subscription'); ?></div>
        </div>
        <div class="sub-days">
          <div class="sub-days-num" style="color:<?php echo $numColor; ?>;"><?php echo $d; ?>d</div>
          <div class="sub-days-lbl">left</div>
        </div>
      </div>
      <?php endwhile; else: ?>
      <div class="empty">
        <i class="fas fa-check-circle" style="color:var(--green);opacity:.5;"></i>
        <p>No expiring subscriptions</p>
      </div>
      <?php endif; ?>
    </div>

  </div>

</div><!-- /dash -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function(){
  const active    = <?php echo $activeSocieties; ?>;
  const suspended = <?php echo $suspendedSocieties; ?>;
  const inactive  = <?php echo $inactiveSocCount; ?>;
  const total     = active + suspended + inactive;

  new Chart(document.getElementById('donutChart'), {
    type: 'doughnut',
    data: {
      labels: ['Active','Suspended','Inactive'],
      datasets:[{
        data: total > 0 ? [active, suspended, inactive] : [1,0,0],
        backgroundColor: ['#10b981','#f59e0b','#e2e4ef'],
        borderColor: '#ffffff',
        borderWidth: 5,
        hoverBorderWidth: 5,
        hoverOffset: 6,
      }]
    },
    options: {
      responsive: true,
      cutout: '76%',
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#0f1729',
          titleColor: '#fff',
          bodyColor: 'rgba(255,255,255,.7)',
          padding: 12,
          cornerRadius: 10,
          titleFont: { family: 'Outfit', weight: '700', size: 13 },
          bodyFont: { family: 'DM Sans', size: 12 },
        }
      },
      animation: {
        animateRotate: true,
        duration: 900,
        easing: 'easeOutQuart',
      }
    }
  });
})();
</script>

<?php
$conn->close();
include __DIR__ . '/includes/footer.php';
?>