<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';

$pageTitle = 'Audit Logs';

// Filters
$filterSociety  = intval($_GET['society_id'] ?? 0);
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo   = $_GET['date_to'] ?? '';
$filterAction   = trim($_GET['action_filter'] ?? '');

// Pagination
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// Build WHERE
$where  = ' WHERE 1=1';
$params = [];
$types  = '';

if ($filterSociety > 0) {
    $where   .= ' AND al.society_id = ?';
    $params[] = $filterSociety; $types .= 'i';
}
if ($filterDateFrom !== '') {
    $where   .= ' AND al.created_at >= ?';
    $params[] = $filterDateFrom . ' 00:00:00'; $types .= 's';
}
if ($filterDateTo !== '') {
    $where   .= ' AND al.created_at <= ?';
    $params[] = $filterDateTo . ' 23:59:59'; $types .= 's';
}
if ($filterAction !== '') {
    $where   .= ' AND al.action LIKE ?';
    $params[] = '%' . $filterAction . '%'; $types .= 's';
}

// Count
$countStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM tbl_audit_log al" . $where);
if ($types) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRows  = $countStmt->get_result()->fetch_assoc()['cnt'];
$countStmt->close();
$totalPages = max(1, ceil($totalRows / $perPage));

// Fetch logs
$sql = "SELECT al.*, s.name AS society_name,
    adm.name AS admin_name,
    u.name AS user_name, u.phone AS user_phone
    FROM tbl_audit_log al
    LEFT JOIN tbl_society s   ON al.society_id = s.id
    LEFT JOIN tbl_admin adm   ON al.admin_id   = adm.id
    LEFT JOIN tbl_user  u     ON al.user_id    = u.id"
    . $where . " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";

$fetchTypes  = $types . 'ii';
$fetchParams = array_merge($params, [$perPage, $offset]);
$fetchStmt   = $conn->prepare($sql);
$fetchStmt->bind_param($fetchTypes, ...$fetchParams);
$fetchStmt->execute();
$logs = $fetchStmt->get_result();
$fetchStmt->close();

// Today's logins stat
$res = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_audit_log WHERE action='login' AND DATE(created_at)=CURDATE()");
$loginsToday = (int)$res->fetch_assoc()['cnt'];

// Unique actors today
$res = $conn->query("SELECT COUNT(DISTINCT COALESCE(admin_id, user_id)) AS cnt FROM tbl_audit_log WHERE DATE(created_at)=CURDATE()");
$actorsToday = (int)$res->fetch_assoc()['cnt'];

// Society dropdown
$societyList = $conn->query("SELECT id, name FROM tbl_society ORDER BY name");

// Pagination query string
$qsParams = [];
if ($filterSociety > 0) $qsParams['society_id']    = $filterSociety;
if ($filterDateFrom)    $qsParams['date_from']      = $filterDateFrom;
if ($filterDateTo)      $qsParams['date_to']        = $filterDateTo;
if ($filterAction)      $qsParams['action_filter']  = $filterAction;
$qsBase = http_build_query($qsParams);

// Action color map
$actionMeta = [
    'login'                 => ['#10b981','#ecfdf5','fa-sign-in-alt'],
    'logout'                => ['#9ca3af','#f3f4f6','fa-sign-out-alt'],
    'create_society'        => ['#3b82f6','#eff6ff','fa-building'],
    'create_society_admin'  => ['#8b5cf6','#f5f3ff','fa-user-shield'],
    'create_admin'          => ['#8b5cf6','#f5f3ff','fa-user-plus'],
    'create_subscription'   => ['#e94560','#fff0f2','fa-credit-card'],
    'toggle_society_status' => ['#f59e0b','#fffbeb','fa-toggle-on'],
    'toggle_status'         => ['#f59e0b','#fffbeb','fa-toggle-on'],
    'reset_password'        => ['#f59e0b','#fffbeb','fa-key'],
    'delete_override'       => ['#ef4444','#fef2f2','fa-trash'],
    'add_override'          => ['#0ea5e9','#f0f9ff','fa-plus-circle'],
];

$hasFilters = ($filterSociety > 0 || $filterDateFrom || $filterDateTo || $filterAction);

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

.al-page {
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

/* ── Stats Strip ── */
.stats-strip {
  display: grid; grid-template-columns: repeat(4, 1fr);
  gap: 14px; margin-bottom: 20px;
}
@media(max-width:900px)  { .stats-strip { grid-template-columns: repeat(2,1fr); } }
@media(max-width:480px)  { .stats-strip { grid-template-columns: 1fr 1fr; } }

.ssc {
  background: var(--card); border: 1px solid var(--border);
  border-radius: var(--r); padding: 18px 18px 16px;
  position: relative; overflow: hidden; box-shadow: var(--shadow);
  transition: transform .2s, box-shadow .2s; animation: fadeUp .4s ease both;
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

/* ── Filter Bar ── */
.filter-panel {
  background: var(--card); border: 1px solid var(--border);
  border-radius: var(--r); padding: 18px 22px;
  margin-bottom: 18px; box-shadow: var(--shadow);
}
.filter-row {
  display: grid;
  grid-template-columns: 1.6fr 1fr 1fr 1.2fr auto;
  gap: 12px; align-items: flex-end;
}
@media(max-width:1000px) { .filter-row { grid-template-columns: 1fr 1fr; } }
@media(max-width:560px)  { .filter-row { grid-template-columns: 1fr; } }

.filter-label {
  font-size: 10.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: var(--txt3); margin-bottom: 6px; display: block;
}
.filter-ctrl {
  width: 100%; height: 38px; border: 1.5px solid var(--border); border-radius: 9px;
  padding: 0 12px; font-family: 'DM Sans', sans-serif;
  font-size: 13px; color: var(--txt1); background: #f7f8fc; outline: none;
  transition: border-color .15s, background .15s; appearance: none;
}
.filter-ctrl:focus { border-color: var(--accent); background: #fff; }
.filter-ctrl::placeholder { color: var(--txt3); }
.filter-select {
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%239ca3af' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 10px center; padding-right: 30px;
}

.filter-btns { display: flex; gap: 8px; align-items: center; padding-bottom: 1px; }
.btn-filter {
  height: 38px; padding: 0 18px;
  background: var(--txt1); color: #fff; border: none; border-radius: 9px;
  font-family: 'Outfit', sans-serif; font-size: 12.5px; font-weight: 700;
  cursor: pointer; display: flex; align-items: center; gap: 6px;
  transition: background .15s; white-space: nowrap;
}
.btn-filter:hover { background: #1f2d47; }
.btn-clear {
  height: 38px; padding: 0 14px;
  background: transparent; border: 1.5px solid var(--border); border-radius: 9px;
  font-family: 'DM Sans', sans-serif; font-size: 12.5px; font-weight: 600;
  color: var(--txt3); cursor: pointer; transition: all .15s;
  text-decoration: none; display: flex; align-items: center; gap: 5px;
}
.btn-clear:hover { border-color: var(--accent); color: var(--accent); }

/* Active filter chips */
.filter-chips {
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 12px;
}
.f-chip {
  display: inline-flex; align-items: center; gap: 5px;
  background: var(--accent-dim); color: var(--accent);
  border: 1px solid rgba(233,69,96,.2); border-radius: 20px;
  font-size: 11px; font-weight: 700; padding: 3px 9px;
}
.f-chip i { font-size: 9px; }

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

table.lg { width: 100%; border-collapse: collapse; font-size: 12.5px; }
table.lg thead tr { background: #f7f8fc; }
table.lg thead th {
  padding: 10px 14px; font-size: 10px; font-weight: 800;
  letter-spacing: .09em; text-transform: uppercase;
  color: var(--txt3); text-align: left; white-space: nowrap;
  border-bottom: 1.5px solid var(--border);
}
table.lg thead th:first-child { padding-left: 20px; }
table.lg thead th:last-child  { padding-right: 20px; }
table.lg tbody tr { border-bottom: 1px solid #f2f4fb; transition: background .1s; }
table.lg tbody tr:last-child { border-bottom: none; }
table.lg tbody tr:hover { background: #fafbfe; }
table.lg tbody td { padding: 11px 14px; vertical-align: middle; }
table.lg tbody td:first-child { padding-left: 20px; }
table.lg tbody td:last-child  { padding-right: 20px; }

/* Timestamp cell */
.ts-cell { white-space: nowrap; }
.ts-date { font-size: 12px; font-weight: 700; color: var(--txt1); }
.ts-time { font-size: 10.5px; color: var(--txt3); font-weight: 500; margin-top: 1px; }

/* Society cell */
.soc-link {
  font-size: 12px; font-weight: 600; color: var(--txt2); text-decoration: none;
  transition: color .13s;
}
.soc-link:hover { color: var(--accent); }
.platform-tag { font-size: 11px; color: var(--txt3); font-style: italic; }

/* Actor cell */
.actor-cell { display: flex; align-items: center; gap: 7px; }
.actor-av {
  width: 28px; height: 28px; border-radius: 7px;
  font-family: 'Outfit', sans-serif; font-size: 9px; font-weight: 800;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.actor-av.admin { background: var(--purple-s); color: var(--purple); }
.actor-av.user  { background: var(--green-s);  color: var(--green); }
.actor-av.sys   { background: #f2f3f8; color: var(--txt3); }
.actor-name { font-size: 12.5px; font-weight: 700; color: var(--txt1); }
.actor-sub  { font-size: 10.5px; color: var(--txt3); }

/* Action badge */
.act-chip {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 3px 9px; border-radius: 7px; white-space: nowrap;
  font-size: 11px; font-weight: 700;
}

/* Entity */
.entity-cell { font-size: 12px; color: var(--txt2); }
.entity-id   { color: var(--txt3); font-size: 10.5px; font-family: monospace; margin-left: 3px; }

/* Details */
.details-cell {
  font-size: 11.5px; color: var(--txt3); max-width: 220px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: default;
}

/* IP */
.ip-cell { font-size: 11px; font-family: monospace; color: var(--txt3); }

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

@media(max-width:768px) { .al-page { padding: 18px 16px 60px; } }
</style>

<div class="al-page">

  <!-- Page Header -->
  <div class="pg-head">
    <div class="pg-head-left">
      <div class="pg-head-icon"><i class="fas fa-clipboard-list"></i></div>
      <div class="pg-head-text">
        <h1>Audit Logs</h1>
        <div class="pg-breadcrumb">
          <a href="dashboard">Dashboard</a>
          <span>/</span>
          <span>Audit Logs</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Stats Strip -->
  <div class="stats-strip">
    <div class="ssc" style="--c:var(--blue);--cs:var(--blue-s);">
      <div class="ssc-icon"><i class="fas fa-list-alt"></i></div>
      <div class="ssc-val"><?php echo number_format($totalRows); ?></div>
      <div class="ssc-lbl"><?php echo $hasFilters ? 'Filtered Logs' : 'Total Logs'; ?></div>
    </div>
    <div class="ssc" style="--c:var(--green);--cs:var(--green-s);">
      <div class="ssc-icon"><i class="fas fa-sign-in-alt"></i></div>
      <div class="ssc-val"><?php echo $loginsToday; ?></div>
      <div class="ssc-lbl">Logins Today</div>
    </div>
    <div class="ssc" style="--c:var(--purple);--cs:var(--purple-s);">
      <div class="ssc-icon"><i class="fas fa-users"></i></div>
      <div class="ssc-val"><?php echo $actorsToday; ?></div>
      <div class="ssc-lbl">Active Users Today</div>
    </div>
    <div class="ssc" style="--c:var(--amber);--cs:var(--amber-s);">
      <div class="ssc-icon"><i class="fas fa-filter"></i></div>
      <div class="ssc-val"><?php echo $perPage; ?></div>
      <div class="ssc-lbl">Per Page</div>
    </div>
  </div>

  <!-- Filter Bar -->
  <div class="filter-panel">
    <form method="GET">
      <div class="filter-row">
        <div>
          <label class="filter-label">Society</label>
          <select name="society_id" class="filter-ctrl filter-select">
            <option value="0">All Societies</option>
            <?php while ($s = $societyList->fetch_assoc()): ?>
              <option value="<?php echo (int)$s['id']; ?>" <?php echo $filterSociety===(int)$s['id']?'selected':''; ?>>
                <?php echo htmlspecialchars($s['name']); ?>
              </option>
            <?php endwhile; ?>
          </select>
        </div>
        <div>
          <label class="filter-label">From Date</label>
          <input type="date" name="date_from" class="filter-ctrl" value="<?php echo htmlspecialchars($filterDateFrom); ?>">
        </div>
        <div>
          <label class="filter-label">To Date</label>
          <input type="date" name="date_to" class="filter-ctrl" value="<?php echo htmlspecialchars($filterDateTo); ?>">
        </div>
        <div>
          <label class="filter-label">Action</label>
          <input type="text" name="action_filter" class="filter-ctrl" placeholder="e.g. login, create..."
                 value="<?php echo htmlspecialchars($filterAction); ?>">
        </div>
        <div class="filter-btns">
          <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Filter</button>
          <?php if ($hasFilters): ?>
            <a href="audit_logs" class="btn-clear"><i class="fas fa-times" style="font-size:10px;"></i> Clear</a>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($hasFilters): ?>
      <div class="filter-chips">
        <span style="font-size:11px;font-weight:700;color:var(--txt3);">Filters:</span>
        <?php if ($filterSociety > 0): ?>
          <span class="f-chip"><i class="fas fa-building"></i> Society #<?php echo $filterSociety; ?></span>
        <?php endif; ?>
        <?php if ($filterDateFrom): ?>
          <span class="f-chip"><i class="fas fa-calendar"></i> From <?php echo $filterDateFrom; ?></span>
        <?php endif; ?>
        <?php if ($filterDateTo): ?>
          <span class="f-chip"><i class="fas fa-calendar"></i> To <?php echo $filterDateTo; ?></span>
        <?php endif; ?>
        <?php if ($filterAction): ?>
          <span class="f-chip"><i class="fas fa-bolt"></i> "<?php echo htmlspecialchars($filterAction); ?>"</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </form>
  </div>

  <!-- Log Table -->
  <div class="tbl-panel">
    <div class="tbl-panel-hdr">
      <div class="tbl-panel-title">
        <span class="tbl-panel-title-icon"><i class="fas fa-clipboard-list"></i></span>
        Activity Log
      </div>
      <span class="tbl-count"><?php echo number_format($totalRows); ?> record<?php echo $totalRows!=1?'s':''; ?></span>
    </div>

    <div style="overflow-x:auto;">
      <table class="lg">
        <thead>
          <tr>
            <th>Timestamp</th>
            <th>Society</th>
            <th>User / Admin</th>
            <th>Action</th>
            <th>Entity</th>
            <th>Details</th>
            <th>IP</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($logs->num_rows > 0):
            while ($row = $logs->fetch_assoc()):
              $m = $actionMeta[$row['action']] ?? ['#9ca3af','#f3f4f6','fa-circle'];
          ?>
          <tr>
            <!-- Timestamp -->
            <td class="ts-cell">
              <div class="ts-date"><?php echo date('d M Y', strtotime($row['created_at'])); ?></div>
              <div class="ts-time"><?php echo date('H:i:s', strtotime($row['created_at'])); ?></div>
            </td>

            <!-- Society -->
            <td>
              <?php if ($row['society_name']): ?>
                <a href="society_detail?id=<?php echo (int)$row['society_id']; ?>" class="soc-link">
                  <?php echo htmlspecialchars($row['society_name']); ?>
                </a>
              <?php else: ?>
                <span class="platform-tag">Platform</span>
              <?php endif; ?>
            </td>

            <!-- Actor -->
            <td>
              <?php if ($row['admin_name']): ?>
                <div class="actor-cell">
                  <div class="actor-av admin"><?php echo strtoupper(mb_substr(trim($row['admin_name']),0,2)); ?></div>
                  <div>
                    <div class="actor-name"><?php echo htmlspecialchars($row['admin_name']); ?></div>
                    <div class="actor-sub">Admin</div>
                  </div>
                </div>
              <?php elseif ($row['user_name']): ?>
                <div class="actor-cell">
                  <div class="actor-av user"><?php echo strtoupper(mb_substr(trim($row['user_name']),0,2)); ?></div>
                  <div>
                    <div class="actor-name"><?php echo htmlspecialchars($row['user_name']); ?></div>
                    <?php if ($row['user_phone']): ?>
                      <div class="actor-sub"><?php echo htmlspecialchars($row['user_phone']); ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php else: ?>
                <div class="actor-cell">
                  <div class="actor-av sys"><i class="fas fa-robot" style="font-size:10px;"></i></div>
                  <div class="actor-name" style="color:var(--txt3);">System</div>
                </div>
              <?php endif; ?>
            </td>

            <!-- Action -->
            <td>
              <span class="act-chip" style="background:<?php echo $m[1]; ?>;color:<?php echo $m[0]; ?>;">
                <i class="fas <?php echo $m[2]; ?>" style="font-size:9px;"></i>
                <?php echo htmlspecialchars($row['action']); ?>
              </span>
            </td>

            <!-- Entity -->
            <td class="entity-cell">
              <?php if ($row['entity_type']): ?>
                <?php echo htmlspecialchars($row['entity_type']); ?>
                <?php if ($row['entity_id']): ?>
                  <span class="entity-id">#<?php echo (int)$row['entity_id']; ?></span>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:var(--txt3);">—</span>
              <?php endif; ?>
            </td>

            <!-- Details -->
            <td>
              <?php if ($row['details']): ?>
                <?php
                  $d = json_decode($row['details'], true);
                  if (is_array($d)) {
                    $parts = [];
                    foreach ($d as $k => $v) {
                      $parts[] = htmlspecialchars($k) . ': ' . htmlspecialchars(is_string($v) ? $v : json_encode($v));
                    }
                    $full    = implode(', ', $parts);
                    $preview = mb_strimwidth($full, 0, 55, '…');
                    echo '<span class="details-cell" title="' . htmlspecialchars($full) . '">' . $preview . '</span>';
                  }
                ?>
              <?php else: ?>
                <span style="color:var(--txt3);">—</span>
              <?php endif; ?>
            </td>

            <!-- IP -->
            <td class="ip-cell"><?php echo htmlspecialchars($row['ip_address'] ?? '—'); ?></td>
          </tr>
          <?php endwhile; else: ?>
          <tr>
            <td colspan="7">
              <div class="empty-state">
                <i class="fas fa-clipboard-list"></i>
                <h3>No logs found</h3>
                <p><?php echo $hasFilters ? 'Try adjusting your filters' : 'Activity will appear here once actions are performed'; ?></p>
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
        Showing <?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $perPage, $totalRows)); ?>
        of <?php echo number_format($totalRows); ?> records
      </div>
      <div class="pg-links">
        <a class="pg-link <?php echo $page<=1?'disabled':''; ?>"
           href="?page=<?php echo $page-1; ?><?php echo $qsBase?'&'.$qsBase:''; ?>">
          <i class="fas fa-chevron-left" style="font-size:10px;"></i>
        </a>
        <?php for ($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?>
          <a class="pg-link <?php echo $p===$page?'active':''; ?>"
             href="?page=<?php echo $p; ?><?php echo $qsBase?'&'.$qsBase:''; ?>">
            <?php echo $p; ?>
          </a>
        <?php endfor; ?>
        <a class="pg-link <?php echo $page>=$totalPages?'disabled':''; ?>"
           href="?page=<?php echo $page+1; ?><?php echo $qsBase?'&'.$qsBase:''; ?>">
          <i class="fas fa-chevron-right" style="font-size:10px;"></i>
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /al-page -->

<?php
$conn->close();
include __DIR__ . '/includes/footer.php';
?>