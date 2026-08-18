<?php
/**
 * Activity & Reports — consolidated Admin module.
 *
 * Combines the former separate pages:
 *   - admin/activity_logs.php               (view=history, source filter "System Activity")
 *   - admin/reports/index.php               (view=history, the default landing tab)
 *   - admin/reports/daily.php               (view=daily)
 *   - admin/reports/weekly.php              (view=weekly)
 *   - admin/reports/monthly.php             (view=monthly)
 *   - admin/reports/faculty_attendance.php  (view=faculty_attendance)
 *   - admin/reports/room_occupancy.php      (view=room_occupancy)
 *   - admin/reports/room_utilization.php    (view=room_utilization)
 *   - admin/responses.php                   (view=history, "Room Activity" filter group)
 *
 * None of the database tables, calculations, or query logic from those pages
 * were removed — every report tab below runs the SAME queries the original
 * standalone file ran (see comments per section). This file only changes
 * WHERE that logic lives, and (per the professor's simplification request)
 * removes the page-level tab switcher so there is exactly ONE visible
 * Activity & Reports screen: the Unified History, with a Filter button and a
 * separate Generate Report button. The specialized report code
 * (daily/weekly/monthly/faculty attendance/room occupancy/room utilization)
 * is kept, unchanged, and still reachable at its original ?view=... URL —
 * it's just no longer linked from the UI, since Unified History + Generate
 * Report already covers what an admin needs day to day.
 *
 * The original files still exist and still work — they now redirect here
 * (see e.g. admin/activity_logs.php, admin/responses.php) so old
 * bookmarks/notification links never 404.
 */

require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

$pageTitle = 'Activity & Reports';
runScheduleAutomation($conn);

$allowedViews = ['history', 'daily', 'weekly', 'monthly', 'faculty_attendance', 'room_occupancy', 'room_utilization'];
$view = $_GET['view'] ?? 'history';
if (!in_array($view, $allowedViews, true)) {
    $view = 'history';
}

$hasActivityLogs = tableExists($conn, 'activity_logs');
$hasRoomLogs     = tableExists($conn, 'room_logs');

// ─────────────────────────────────────────────────────────────────────────
// Shared filter inputs (used mainly by the Unified History tab, Step 4/5).
// ─────────────────────────────────────────────────────────────────────────
$search    = trim($_GET['search'] ?? '');
$actionF   = trim($_GET['activity'] ?? '');   // e.g. "sys:login_success" or "room:checkin"
$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to']   ?? '');
$roomF     = trim($_GET['room'] ?? '');       // rooms.id
$userF     = trim($_GET['user'] ?? '');       // users.id
$queryError = null;

// "Generate Report" and "Filter" submit the exact same form/filters — the
// only difference is this flag, which switches the page into a distinct
// report presentation (a labeled, print-ready summary of the currently
// selected filters) instead of the plain working table. Both always run the
// SAME filtered query, so a report can never contain records outside what
// was selected.
$isReport = isset($_GET['report']) && $_GET['report'] === '1';

$isValidDate = function ($d) {
    if ($d === '') return true;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
};
if (!$isValidDate($dateFrom)) { $queryError = 'The "From" date was ignored because it was not a valid date.'; $dateFrom = ''; }
if (!$isValidDate($dateTo))   { $queryError = 'The "To" date was ignored because it was not a valid date.'; $dateTo = ''; }
if ($roomF !== '' && !ctype_digit($roomF)) $roomF = '';
if ($userF !== '' && !ctype_digit($userF)) $userF = '';

// Dropdown option sources — reused as-is from admin/rooms.php and
// admin/faculty/index.php's existing query patterns.
$roomOptions = [];
if (tableExists($conn, 'rooms')) {
    $rr = $conn->query("SELECT id, room_code, room_name FROM rooms ORDER BY room_code ASC");
    if ($rr) while ($r = $rr->fetch_assoc()) $roomOptions[] = $r;
}
$userOptions = [];
$ur = $conn->query("SELECT id, name, title, role FROM users WHERE role IN ('faculty','admin') ORDER BY name ASC");
if ($ur) while ($u = $ur->fetch_assoc()) $userOptions[] = $u;

$sysActionList = [];
if ($hasActivityLogs) {
    $res = $conn->query("SELECT DISTINCT action_type FROM activity_logs WHERE action_type IS NOT NULL AND action_type <> '' ORDER BY action_type ASC");
    if ($res) while ($r = $res->fetch_assoc()) {
        $val = trim((string)$r['action_type']);
        if ($val !== '') $sysActionList[] = $val;
    }
}
// Room-activity event keys — derived from the same statuses/columns the
// original reports (daily.php, faculty_attendance.php, etc.) already use.
// No invented statuses; these mirror room_logs.status / confirmation /
// checkin_at / checkout_at exactly.
$roomActionList = [
    'checkin'         => 'Check-In',
    'checkout'        => 'Check-Out',
    'confirmed_yes'   => 'Class Confirmed (YES)',
    'confirmed_no'    => 'Class Declined (NO)',
    'missed_confirmation' => 'Missed Confirmation',
    'no_show'         => 'No Show',
    'pending'         => 'Pending Response',
];

// Human-readable summary of the active filters, shown on the Generate
// Report header so it's clear exactly what the report covers.
$reportFilterParts = [];
if ($search !== '')   $reportFilterParts[] = 'Search: "' . $search . '"';
if ($dateFrom !== '' || $dateTo !== '') {
    $reportFilterParts[] = 'Date: ' . ($dateFrom !== '' ? $dateFrom : 'earliest') . ' to ' . ($dateTo !== '' ? $dateTo : 'latest');
}
if ($roomF !== '') {
    foreach ($roomOptions as $r) if ((string)$r['id'] === $roomF) $reportFilterParts[] = 'Room: ' . $r['room_code'];
}
if ($userF !== '') {
    foreach ($userOptions as $u) if ((string)$u['id'] === $userF) $reportFilterParts[] = 'User: ' . facultyDisplayName($u['title'] ?? null, $u['name']);
}
if ($actionF !== '') {
    if (substr($actionF, 0, 4) === 'sys:') $reportFilterParts[] = 'Activity: ' . actionLabel(substr($actionF, 4));
    if (substr($actionF, 0, 5) === 'room:') $reportFilterParts[] = 'Activity: ' . ($roomActionList[substr($actionF, 5)] ?? substr($actionF, 5));
}
$reportFilterSummary = $reportFilterParts ? implode(' · ', $reportFilterParts) : 'All records (no filters applied)';

function actionLabel($action) {
    $labels = [
        'login_success'          => 'Login Successful',
        'login_failed'           => 'Login Failed',
        'logout'                 => 'Logged Out',
        'checkin'                => 'Checked In',
        'checkout'                => 'Checked Out',
        'class_confirmed'        => 'Class Confirmed',
        'class_declined'         => 'Class Declined',
        'confirm_yes'            => 'Class Confirmed',
        'confirm_no'             => 'Class Declined',
        'room_status_change'     => 'Room Status Changed',
        'room_created'           => 'Room Added',
        'room_updated'           => 'Room Updated',
        'room_deleted'           => 'Room Deleted',
        'room_released'          => 'Room Released',
        'schedule_created'       => 'Schedule Added',
        'schedule_updated'       => 'Schedule Updated',
        'schedule_deleted'       => 'Schedule Deleted',
        'faculty_created'        => 'Faculty Added',
        'faculty_updated'        => 'Faculty Updated',
        'faculty_deleted'        => 'Faculty Deleted',
        'faculty_password_reset' => 'Faculty Password Reset',
        'profile_updated'        => 'Profile Updated',
        'password_changed'       => 'Password Changed',
        'settings_updated'       => 'Settings Updated',
        'rfid_assigned'          => 'RFID Assigned',
        'rfid_replaced'          => 'RFID Replaced',
        'rfid_removed'           => 'RFID Removed',
        'rfid_lost'              => 'RFID Marked Lost',
        'rfid_reactivated'       => 'RFID Reactivated',
        'rfid_checkin'           => 'RFID Check-In',
        'rfid_checkout'          => 'RFID Check-Out',
        'rfid_scan_failed'       => 'RFID Scan Failed',
    ];
    return $labels[$action] ?? ucwords(str_replace('_', ' ', $action));
}

function actionBadge($action) {
    $map = [
        'login_success'          => 'primary',
        'login_failed'           => 'danger',
        'logout'                 => 'secondary',
        'checkin'                => 'success',
        'checkout'                => 'info',
        'class_confirmed'        => 'success',
        'class_declined'         => 'warning',
        'confirm_yes'            => 'success',
        'confirm_no'             => 'warning',
        'room_status_change'     => 'dark',
        'room_created'           => 'success',
        'room_updated'           => 'primary',
        'room_deleted'           => 'danger',
        'room_released'          => 'secondary',
        'faculty_created'        => 'success',
        'faculty_updated'        => 'primary',
        'faculty_deleted'        => 'danger',
        'faculty_password_reset' => 'warning',
        'password_changed'       => 'info',
        'profile_updated'        => 'primary',
        'rfid_assigned'          => 'success',
        'rfid_replaced'          => 'primary',
        'rfid_removed'           => 'secondary',
        'rfid_lost'              => 'warning',
        'rfid_reactivated'       => 'success',
        'rfid_checkin'           => 'success',
        'rfid_checkout'          => 'info',
        'rfid_scan_failed'       => 'danger',
    ];
    $color = $map[$action] ?? 'secondary';
    return "<span class=\"badge bg-{$color}\">" . htmlspecialchars(actionLabel($action)) . "</span>";
}

function roomActionBadge($key) {
    $map = [
        'checkin'              => ['success', 'Check-In'],
        'checkout'             => ['info', 'Check-Out'],
        'confirmed_yes'        => ['success', 'Confirmed (YES)'],
        'confirmed_no'         => ['warning', 'Declined (NO)'],
        'missed_confirmation'  => ['dark', 'Missed Confirmation'],
        'no_show'              => ['danger', 'No Show'],
        'pending'              => ['secondary', 'Pending'],
    ];
    [$color, $label] = $map[$key] ?? ['secondary', ucwords(str_replace('_', ' ', $key))];
    return "<span class=\"badge bg-{$color}\">" . htmlspecialchars($label) . "</span>";
}

// ─────────────────────────────────────────────────────────────────────────
// STEP 3/4 — Unified History: combine activity_logs (system/user actions)
// and room_logs (actual room/class activity) into one filterable feed.
// The two tables are NOT merged — this is a read-only display/query layer
// built with UNION ALL over the existing tables, exactly as the task asked.
// ─────────────────────────────────────────────────────────────────────────
$historyRows  = [];
$historyTotal = 0;
$summary = [
    'total' => 0, 'checkins' => 0, 'checkouts' => 0,
    'confirmed_yes' => 0, 'confirmed_no' => 0,
    'no_shows' => 0, 'missed' => 0, 'room_usage' => 0,
];

if ($view === 'history' && $queryError === null) {

    $includeSystem = $hasActivityLogs && $actionF === '' || ($hasActivityLogs && substr($actionF, 0, 4) === 'sys:');
    $includeRoom   = $hasRoomLogs && $actionF === '' || ($hasRoomLogs && substr($actionF, 0, 5) === 'room:');
    // A room filter only makes sense against room-activity records, since
    // system actions (login, faculty management, etc.) aren't tied to a room.
    if ($roomF !== '') $includeSystem = false;

    $sysSql = '';  $sysParams = [];  $sysTypes = '';
    $roomSql = ''; $roomParams = []; $roomTypes = '';

    if ($includeSystem) {
        $sysWhere = "WHERE 1=1";
        if ($search !== '') {
            $likeSafe = addcslashes($search, '%_\\');
            $like = "%$likeSafe%";
            $sysWhere .= " AND (COALESCE(u.name, al.user_name) LIKE ? OR al.description LIKE ? OR al.action_type LIKE ?)";
            $sysParams = array_merge($sysParams, [$like, $like, $like]);
            $sysTypes .= 'sss';
        }
        if (substr($actionF, 0, 4) === 'sys:') {
            $sysWhere .= " AND al.action_type = ?";
            $sysParams[] = substr($actionF, 4);
            $sysTypes .= 's';
        }
        if ($dateFrom !== '') { $sysWhere .= " AND DATE(al.created_at) >= ?"; $sysParams[] = $dateFrom; $sysTypes .= 's'; }
        if ($dateTo   !== '') { $sysWhere .= " AND DATE(al.created_at) <= ?"; $sysParams[] = $dateTo;   $sysTypes .= 's'; }
        if ($userF !== '')    { $sysWhere .= " AND al.user_id = ?";           $sysParams[] = (int)$userF; $sysTypes .= 'i'; }

        $sysSql = "
            SELECT al.id AS log_id, 'system' AS source, al.created_at AS event_time,
                   COALESCE(u.title,'') AS user_title, COALESCE(u.name, al.user_name) AS user_name,
                   COALESCE(u.role, al.user_role) AS user_role,
                   NULL AS room_code, NULL AS room_name,
                   al.action_type AS action_key, al.description AS description, NULL AS status
            FROM activity_logs al
            LEFT JOIN users u ON al.user_id = u.id
            $sysWhere
        ";
    }

    if ($includeRoom) {
        $roomWhere = "WHERE 1=1";
        if ($search !== '') {
            $likeSafe = addcslashes($search, '%_\\');
            $like = "%$likeSafe%";
            $roomWhere .= " AND (u.name LIKE ? OR s.subject LIKE ? OR r.room_code LIKE ? OR r.room_name LIKE ?)";
            $roomParams = array_merge($roomParams, [$like, $like, $like, $like]);
            $roomTypes .= 'ssss';
        }
        if (substr($actionF, 0, 5) === 'room:') {
            $key = substr($actionF, 5);
            switch ($key) {
                case 'checkin':
                    $roomWhere .= " AND rl.checkin_at IS NOT NULL AND rl.checkout_at IS NULL AND rl.status NOT IN ('Missed Confirmation','No Show')";
                    break;
                case 'checkout':
                    $roomWhere .= " AND rl.checkout_at IS NOT NULL";
                    break;
                case 'confirmed_yes':
                    $roomWhere .= " AND rl.confirmation = 'yes'";
                    break;
                case 'confirmed_no':
                    $roomWhere .= " AND rl.confirmation = 'no'";
                    break;
                case 'missed_confirmation':
                    $roomWhere .= " AND rl.status = 'Missed Confirmation'";
                    break;
                case 'no_show':
                    $roomWhere .= " AND rl.status = 'No Show'";
                    break;
                case 'pending':
                    $roomWhere .= " AND rl.confirmation IS NULL AND rl.checkin_at IS NULL AND rl.status NOT IN ('Missed Confirmation','No Show')";
                    break;
            }
        }
        // Which column represents "the date" for this record depends on the
        // record type: a check-in's real-world date is checkin_at, a
        // check-out's is checkout_at (a session that starts before midnight
        // and checks out after it shouldn't be excluded because log_date
        // still says the earlier day). Everything else — pending,
        // confirmations, missed confirmation, no-show — doesn't have an
        // event timestamp of its own, so log_date (the day the class was
        // scheduled) is the correct and only sensible date to filter on.
        $roomActionKey = substr($actionF, 0, 5) === 'room:' ? substr($actionF, 5) : '';
        $roomDateColumn = 'rl.log_date';
        if ($roomActionKey === 'checkin')  $roomDateColumn = 'DATE(rl.checkin_at)';
        if ($roomActionKey === 'checkout') $roomDateColumn = 'DATE(rl.checkout_at)';

        if ($dateFrom !== '') { $roomWhere .= " AND $roomDateColumn >= ?"; $roomParams[] = $dateFrom; $roomTypes .= 's'; }
        if ($dateTo   !== '') { $roomWhere .= " AND $roomDateColumn <= ?"; $roomParams[] = $dateTo;   $roomTypes .= 's'; }
        if ($userF !== '')    { $roomWhere .= " AND rl.faculty_id = ?"; $roomParams[] = (int)$userF; $roomTypes .= 'i'; }
        if ($roomF !== '')    { $roomWhere .= " AND rl.room_id = ?";    $roomParams[] = (int)$roomF; $roomTypes .= 'i'; }

        $roomSql = "
            SELECT rl.id AS log_id, 'room' AS source,
                   COALESCE(rl.checkout_at, rl.checkin_at, CONCAT(rl.log_date, ' ', s.time_start)) AS event_time,
                   COALESCE(u.title,'') AS user_title, u.name AS user_name, 'faculty' AS user_role,
                   r.room_code AS room_code, r.room_name AS room_name,
                   CASE
                       WHEN rl.status = 'Missed Confirmation' THEN 'missed_confirmation'
                       WHEN rl.status = 'No Show' THEN 'no_show'
                       WHEN rl.checkout_at IS NOT NULL THEN 'checkout'
                       WHEN rl.checkin_at IS NOT NULL THEN 'checkin'
                       WHEN rl.confirmation = 'yes' THEN 'confirmed_yes'
                       WHEN rl.confirmation = 'no' THEN 'confirmed_no'
                       ELSE 'pending'
                   END AS action_key,
                   CONCAT(s.subject, IF(s.section IS NOT NULL AND s.section <> '', CONCAT(' (', s.section, ')'), ''), ' — ', r.room_code) AS description,
                   rl.status AS status
            FROM room_logs rl
            JOIN schedules s ON s.id = rl.schedule_id
            JOIN users     u ON u.id = rl.faculty_id
            JOIN rooms     r ON r.id = rl.room_id
            $roomWhere
        ";
    }

    $unionParts = array_filter([$sysSql, $roomSql]);
    if (empty($unionParts)) {
        $queryError = $hasActivityLogs || $hasRoomLogs ? null : 'Neither activity_logs nor room_logs tables are available yet.';
    } else {
        $unionSql = implode("\nUNION ALL\n", $unionParts);
        $allParams = array_merge($sysParams, $roomParams);
        $allTypes  = $sysTypes . $roomTypes;

        // Count
        $countSql = "SELECT COUNT(*) AS c FROM ($unionSql) unified";
        $cs = $conn->prepare($countSql);
        if ($cs) {
            if ($allTypes) $cs->bind_param($allTypes, ...$allParams);
            if ($cs->execute()) {
                $historyTotal = (int)$cs->get_result()->fetch_assoc()['c'];
            } else {
                $queryError = 'Unable to count activity records. Please adjust your filters and try again.';
            }
            $cs->close();
        } else {
            $queryError = 'Unable to prepare the activity query.';
        }

        $page    = max(1, intval($_GET['page'] ?? 1));
        $perPage = 25;
        $totalPages = max(1, (int)ceil($historyTotal / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        if ($queryError === null) {
            $dataSql = "SELECT * FROM ($unionSql) unified ORDER BY event_time DESC LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($dataSql);
            if ($stmt) {
                $dTypes  = $allTypes . 'ii';
                $dParams = array_merge($allParams, [$perPage, $offset]);
                $stmt->bind_param($dTypes, ...$dParams);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) $historyRows[] = $row;
                } else {
                    $queryError = 'Unable to load activity records. Please adjust your filters and try again.';
                }
                $stmt->close();
            } else {
                $queryError = 'Unable to prepare the activity query.';
            }
        }

        // Step 5 — Summary, computed from the SAME filtered result set (not
        // just the current page). Room-only metrics (check-ins, check-outs,
        // confirmations, no-shows) are only meaningful for room_logs rows,
        // so they're computed with the room-side WHERE only.
        if ($queryError === null) {
            $summary['total'] = $historyTotal;
            if ($includeRoom) {
                $sumSql = "
                    SELECT
                        SUM(rl.checkin_at IS NOT NULL) AS checkins,
                        SUM(rl.checkout_at IS NOT NULL) AS checkouts,
                        SUM(rl.confirmation='yes') AS confirmed_yes,
                        SUM(rl.confirmation='no') AS confirmed_no,
                        SUM(rl.status='No Show') AS no_shows,
                        SUM(rl.status='Missed Confirmation') AS missed,
                        COUNT(*) AS room_usage
                    FROM room_logs rl
                    JOIN schedules s ON s.id = rl.schedule_id
                    JOIN users     u ON u.id = rl.faculty_id
                    JOIN rooms     r ON r.id = rl.room_id
                    $roomWhere
                ";
                $ss = $conn->prepare($sumSql);
                if ($ss) {
                    if ($roomTypes) $ss->bind_param($roomTypes, ...$roomParams);
                    if ($ss->execute()) {
                        $srow = $ss->get_result()->fetch_assoc();
                        $summary['checkins']       = (int)($srow['checkins'] ?? 0);
                        $summary['checkouts']      = (int)($srow['checkouts'] ?? 0);
                        $summary['confirmed_yes']  = (int)($srow['confirmed_yes'] ?? 0);
                        $summary['confirmed_no']   = (int)($srow['confirmed_no'] ?? 0);
                        $summary['no_shows']       = (int)($srow['no_shows'] ?? 0);
                        $summary['missed']         = (int)($srow['missed'] ?? 0);
                        $summary['room_usage']     = (int)($srow['room_usage'] ?? 0);
                    }
                    $ss->close();
                }
            }
        }
    }
}

include __DIR__ . '/../authentication.php';
?>
<style>
@media print {
  #sidebar,#main-content .topbar,.no-print{display:none!important;}
  #main-content{margin-left:0!important;}
  .page-body{padding:0!important;}
}
.ar-tabs .nav-link { cursor:pointer; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print">
  <div>
    <h5 class="mb-0 fw-bold"><i class="bi bi-journal-richtext me-2 text-primary"></i>Activity &amp; Reports</h5>
    <div class="text-muted small">Search, filter, and generate reports from the system's activity and room history — all in one place.</div>
  </div>
  <button onclick="window.print()" class="btn btn-sm btn-outline-primary"><i class="bi bi-printer me-1"></i>Print</button>
</div>

<?php
// Per the professor's feedback, the page-level tab switcher (History +
// a "Reports" dropdown of 6 separate report pages) has been removed so
// there is exactly ONE visible Activity & Reports interface: the Unified
// History below, with a Filter button and a separate Generate Report
// button. Nothing was deleted — the daily/weekly/monthly/faculty
// attendance/room occupancy/room utilization report code further below is
// untouched and still reachable at its original ?view=... URL for anyone
// with an old bookmark/link ($allowedViews above still accepts them) — it's
// just no longer surfaced as a clickable tab, since the sidebar and every
// in-app link now only ever points to ?view=history.
?>

<?php if ($view === 'history'): ?>

  <!-- ============================================================
       STEP 3/4/5 — Unified History (activity_logs + room_logs)
       ============================================================ -->
  <div class="row g-2 mb-3">
    <?php
    $sum = [
      ['Total Records','secondary','bi-list-check',$summary['total']],
      ['Check-Ins','success','bi-box-arrow-in-right',$summary['checkins']],
      ['Check-Outs','info','bi-box-arrow-right',$summary['checkouts']],
      ['Confirmed (YES)','success','bi-check-circle',$summary['confirmed_yes']],
      ['Declined (NO)','warning','bi-x-circle',$summary['confirmed_no']],
      ['Missed Confirmation','dark','bi-clock-history',$summary['missed']],
      ['No Shows','danger','bi-exclamation-triangle',$summary['no_shows']],
      ['Room Usage','primary','bi-door-open',$summary['room_usage']],
    ];
    foreach ($sum as [$lbl,$c,$ic,$v]):
    ?>
    <div class="col-6 col-md-3 col-xl-1-5" style="flex:1 0 12%;max-width:16.6%;">
      <div class="card text-center h-100">
        <div class="card-body py-2 px-1">
          <div class="fw-bold text-<?= $c ?> fs-6"><?= $v ?></div>
          <div class="text-muted" style="font-size:.65rem;"><?= $lbl ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="card mb-3 no-print">
    <div class="card-body py-2">
      <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="view" value="history">
        <div class="col-md-3">
          <label class="form-label small fw-semibold mb-1">Search</label>
          <input type="text" name="search" class="form-control form-control-sm"
                 placeholder="Name, room, subject, description…" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label small fw-semibold mb-1">Activity</label>
          <select name="activity" class="form-select form-select-sm">
            <option value="">All Activities</option>
            <?php if ($hasActivityLogs && $sysActionList): ?>
            <optgroup label="System Activity">
              <?php foreach ($sysActionList as $a): $v = 'sys:'.$a; ?>
              <option value="<?= htmlspecialchars($v) ?>" <?= $actionF === $v ? 'selected' : '' ?>><?= htmlspecialchars(actionLabel($a)) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <?php if ($hasRoomLogs): ?>
            <optgroup label="Room Activity">
              <?php foreach ($roomActionList as $k => $lbl): $v = 'room:'.$k; ?>
              <option value="<?= htmlspecialchars($v) ?>" <?= $actionF === $v ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small fw-semibold mb-1">Room</label>
          <select name="room" class="form-select form-select-sm">
            <option value="">All Rooms</option>
            <?php foreach ($roomOptions as $r): ?>
            <option value="<?= (int)$r['id'] ?>" <?= $roomF === (string)$r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['room_code']) ?> — <?= htmlspecialchars($r['room_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small fw-semibold mb-1">User / Faculty</label>
          <select name="user" class="form-select form-select-sm">
            <option value="">All Users</option>
            <?php foreach ($userOptions as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= $userF === (string)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars(facultyDisplayName($u['title'] ?? null, $u['name'])) ?> (<?= htmlspecialchars(ucfirst($u['role'])) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1">
          <label class="form-label small fw-semibold mb-1">From</label>
          <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="col-md-1">
          <label class="form-label small fw-semibold mb-1">To</label>
          <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <div class="col-12 d-flex gap-2 justify-content-end">
          <button type="submit" name="report" value="0" class="btn btn-sm btn-primary" title="Apply filters">
            <i class="bi bi-funnel me-1"></i>Filter
          </button>
          <a href="?view=history" class="btn btn-sm btn-outline-secondary" title="Clear all filters">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
          </a>
          <button type="submit" name="report" value="1" class="btn btn-sm btn-success" title="Generate a report from the filters above">
            <i class="bi bi-file-earmark-bar-graph me-1"></i>Generate Report
          </button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($isReport && $queryError === null): ?>
  <div class="card mb-3 border-success">
    <div class="card-body py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <div class="fw-bold text-success"><i class="bi bi-file-earmark-bar-graph me-1"></i>Generated Report</div>
        <div class="text-muted small">
          <?= htmlspecialchars($reportFilterSummary) ?> · Generated <?= date('M d, Y h:i A') ?> · <?= (int)$historyTotal ?> matching record<?= $historyTotal === 1 ? '' : 's' ?>
        </div>
      </div>
      <button onclick="window.print()" class="btn btn-sm btn-outline-success no-print"><i class="bi bi-printer me-1"></i>Print Report</button>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($queryError): ?>
  <div class="alert alert-danger d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-octagon-fill"></i>
    <div><?= htmlspecialchars($queryError) ?></div>
  </div>
  <?php elseif (empty($historyRows)): ?>
  <div class="alert alert-info d-flex align-items-center gap-2">
    <i class="bi bi-info-circle-fill"></i>
    <div>No activity records found for the selected filters. Try widening your date range or clearing a filter.</div>
  </div>
  <?php else: ?>
  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-dark">
            <tr>
              <th>Date / Time</th><th>User</th><th>Activity</th><th>Room</th><th>Details</th><th>Status</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($historyRows as $row): ?>
          <tr>
            <td class="text-nowrap small"><?= date('M d, Y h:i:s A', strtotime($row['event_time'])) ?></td>
            <td>
              <?php if ($row['user_name']): ?>
                <div class="fw-semibold small"><?= htmlspecialchars(facultyDisplayName($row['user_title'] ?? null, $row['user_name'])) ?></div>
                <div class="text-muted" style="font-size:.7rem;"><?= ucfirst($row['user_role'] ?? '') ?></div>
              <?php else: ?>
                <span class="text-muted small">System / Unknown</span>
              <?php endif; ?>
            </td>
            <td><?= $row['source']==='room' ? roomActionBadge($row['action_key']) : actionBadge($row['action_key']) ?></td>
            <td class="small"><?= $row['room_code'] ? '<span class="badge bg-dark">'.htmlspecialchars($row['room_code']).'</span>' : '<span class="text-muted">—</span>' ?></td>
            <td class="small"><?= htmlspecialchars($row['description'] ?? '') ?></td>
            <td class="small"><?= $row['status'] ? htmlspecialchars($row['status']) : '<span class="text-muted">—</span>' ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php if (!empty($totalPages) && $totalPages > 1): ?>
  <nav class="mt-3 no-print">
    <ul class="pagination pagination-sm justify-content-center">
      <?php $qs = $_GET; unset($qs['page']); $baseQs = http_build_query($qs); $sep = $baseQs ? '&' : ''; ?>
      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?<?= $baseQs ?><?= $sep ?>page=<?= max(1,$page-1) ?>">Previous</a></li>
      <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
      <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= $baseQs ?><?= $sep ?>page=<?= $p ?>"><?= $p ?></a></li>
      <?php endfor; ?>
      <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="?<?= $baseQs ?><?= $sep ?>page=<?= min($totalPages,$page+1) ?>">Next</a></li>
    </ul>
  </nav>
  <?php endif; ?>
  <?php endif; ?>

<?php elseif ($view === 'daily'): ?>
  <?php
  // Reused verbatim from the former admin/reports/daily.php.
  $date = $_GET['date'] ?? date('Y-m-d');
  $dateCheck = DateTime::createFromFormat('Y-m-d', $date);
  if (!$dateCheck || $dateCheck->format('Y-m-d') !== $date) $date = date('Y-m-d');

  $logs = $conn->prepare("
      SELECT l.*, u.name AS faculty_name, u.title AS faculty_title, u.department,
             r.room_code, r.room_name,
             s.subject, s.section, s.time_start, s.time_end,
             TIMESTAMPDIFF(MINUTE, l.checkin_at, IFNULL(l.checkout_at, NOW())) AS duration_min
      FROM room_logs l
      JOIN users     u ON l.faculty_id  = u.id
      JOIN rooms     r ON l.room_id     = r.id
      JOIN schedules s ON l.schedule_id = s.id
      WHERE l.log_date = ?
      ORDER BY s.time_start ASC
  ");
  $logs->bind_param('s',$date); $logs->execute();
  $result = $logs->get_result(); $logs->close();

  $total = $result->num_rows;
  $yesCount=$noCount=$pendingCount=$missedCount=$noShowCount=$checkinCount=$checkoutCount=0;
  $rows=[]; while($r=$result->fetch_assoc()){
      $rows[]=$r;
      if($r['confirmation']==='yes') $yesCount++;
      elseif($r['confirmation']==='no') $noCount++;
      elseif($r['status']==='Missed Confirmation') $missedCount++;
      elseif($r['status']==='No Show') $noShowCount++;
      else $pendingCount++;
      if($r['checkin_at']) $checkinCount++;
      if($r['checkout_at']) $checkoutCount++;
  }
  ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="text-muted small"><?= date('l, F d, Y', strtotime($date)) ?></div>
  </div>
  <div class="card mb-3 no-print">
    <div class="card-body py-2">
      <form method="GET" class="d-flex gap-2 align-items-end">
        <input type="hidden" name="view" value="daily">
        <div>
          <label class="form-label small fw-semibold mb-1">Select Date</label>
          <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($date) ?>">
        </div>
        <button class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Generate</button>
      </form>
    </div>
  </div>
  <div class="row g-2 mb-3">
    <?php
    $sum = [
      ['Total Schedules','secondary','bi-calendar3',$total],
      ['YES (Will Conduct)','success','bi-check-circle',$yesCount],
      ['NO (Cancelled)','danger','bi-x-circle',$noCount],
      ['Pending','warning','bi-hourglass',$pendingCount],
      ['Missed Confirmation','dark','bi-clock-history',$missedCount],
      ['No Show','danger','bi-exclamation-triangle',$noShowCount],
      ['Checked In','primary','bi-box-arrow-in-right',$checkinCount],
      ['Checked Out','info','bi-box-arrow-right',$checkoutCount],
    ];
    foreach ($sum as [$lbl,$c,$ic,$v]):
    ?>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="card text-center">
        <div class="card-body py-2 px-1">
          <div class="fw-bold text-<?= $c ?> fs-5"><?= $v ?></div>
          <div class="text-muted" style="font-size:.7rem;"><?= $lbl ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <div class="card-header bg-info text-white">
      <i class="bi bi-table me-1"></i>Schedule &amp; Activity Log — <?= date('M d, Y', strtotime($date)) ?>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-dark">
            <tr>
              <th>#</th><th>Time</th><th>Faculty</th><th>Subject</th>
              <th>Room</th><th>Response</th><th>Check-In</th><th>Check-Out</th><th>Duration</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($rows)): ?>
          <tr><td colspan="9" class="text-center py-4 text-muted">No activity recorded for <?= date('M d, Y',strtotime($date)) ?>.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $i => $row): ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td class="text-nowrap small">
              <?= date('h:i A',strtotime($row['time_start'])) ?>–<?= date('h:i A',strtotime($row['time_end'])) ?>
            </td>
            <td>
              <div class="fw-semibold small"><?= htmlspecialchars(facultyDisplayName($row['faculty_title'] ?? null, $row['faculty_name'])) ?></div>
              <div class="text-muted" style="font-size:.7rem;"><?= htmlspecialchars($row['department']??'') ?></div>
            </td>
            <td class="small"><?= htmlspecialchars($row['subject']) ?><?= $row['section'] ? ' ('.$row['section'].')':'' ?></td>
            <td><span class="badge bg-dark"><?= htmlspecialchars($row['room_code']) ?></span></td>
            <td>
              <?php if ($row['confirmation']==='yes'): ?>
                <span class="badge bg-success">YES</span>
              <?php elseif ($row['confirmation']==='no'): ?>
                <span class="badge bg-danger">NO</span>
              <?php elseif ($row['status']==='Missed Confirmation'): ?>
                <span class="badge bg-dark">Missed Confirmation</span>
              <?php elseif ($row['status']==='No Show'): ?>
                <span class="badge bg-danger">No Show</span>
              <?php else: ?>
                <span class="badge bg-warning text-dark">Pending</span>
              <?php endif; ?>
            </td>
            <td class="small <?= $row['checkin_at']?'text-success':'text-muted' ?>">
              <?= $row['checkin_at'] ? date('h:i A',strtotime($row['checkin_at'])) : '—' ?>
            </td>
            <td class="small <?= $row['checkout_at']?'text-secondary':'text-muted' ?>">
              <?= $row['checkout_at'] ? date('h:i A',strtotime($row['checkout_at'])) : '—' ?>
            </td>
            <td class="small">
              <?php if ($row['checkin_at']): ?>
                <?= $row['duration_min'] > 0 ? $row['duration_min'].' min' : '<1 min' ?>
              <?php else: ?>—<?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<?php elseif ($view === 'weekly'): ?>
  <?php
  // Reused verbatim from the former admin/reports/weekly.php.
  $weekStart = $_GET['week_start'] ?? date('Y-m-d', strtotime('monday this week'));
  $wsCheck = DateTime::createFromFormat('Y-m-d', $weekStart);
  if (!$wsCheck || $wsCheck->format('Y-m-d') !== $weekStart) {
      $weekStart = date('Y-m-d', strtotime('monday this week'));
  }
  $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

  $days = [];
  for ($i=0; $i<7; $i++) $days[] = date('Y-m-d', strtotime($weekStart . " +$i days"));

  $daySummary = [];
  foreach ($days as $day) {
      $stmt = $conn->prepare("
          SELECT COUNT(*) AS total,
                 SUM(confirmation='yes') AS yes_count,
                 SUM(confirmation='no')  AS no_count,
                 SUM(status='Missed Confirmation') AS missed_count,
                 SUM(status='No Show') AS no_show_count,
                 SUM(checkin_at IS NOT NULL) AS checkins,
                 SUM(checkout_at IS NOT NULL) AS checkouts
          FROM room_logs WHERE log_date=?
      ");
      $stmt->bind_param('s',$day); $stmt->execute();
      $daySummary[$day] = $stmt->get_result()->fetch_assoc();
      $stmt->close();
  }

  $roomStats = $conn->prepare("
      SELECT r.room_code, r.room_name,
             COUNT(l.id) AS total_sched,
             SUM(l.checkin_at IS NOT NULL) AS checkins
      FROM rooms r
      LEFT JOIN room_logs l ON l.room_id=r.id AND l.log_date BETWEEN ? AND ?
      GROUP BY r.id ORDER BY checkins DESC
  ");
  $roomStats->bind_param('ss',$weekStart,$weekEnd); $roomStats->execute();
  $roomResult = $roomStats->get_result(); $roomStats->close();
  ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="text-muted small">Week of <?= date('M d',strtotime($weekStart)) ?> – <?= date('M d, Y',strtotime($weekEnd)) ?></div>
  </div>
  <div class="card mb-3 no-print">
    <div class="card-body py-2">
      <form method="GET" class="d-flex gap-2 align-items-end flex-wrap">
        <input type="hidden" name="view" value="weekly">
        <div>
          <label class="form-label small fw-semibold mb-1">Week Starting (Monday)</label>
          <input type="date" name="week_start" class="form-control form-control-sm" value="<?= htmlspecialchars($weekStart) ?>">
        </div>
        <button class="btn btn-sm btn-secondary mt-auto"><i class="bi bi-search me-1"></i>Generate</button>
        <a href="?view=weekly&week_start=<?= date('Y-m-d', strtotime($weekStart.' -7 days')) ?>" class="btn btn-sm btn-outline-secondary mt-auto">← Prev Week</a>
        <a href="?view=weekly&week_start=<?= date('Y-m-d', strtotime($weekStart.' +7 days')) ?>" class="btn btn-sm btn-outline-secondary mt-auto">Next Week →</a>
      </form>
    </div>
  </div>
  <div class="card mb-3">
    <div class="card-header bg-secondary text-white"><i class="bi bi-bar-chart me-2"></i>Daily Activity This Week</div>
    <div class="card-body">
      <div class="row g-2">
      <?php foreach ($days as $day):
        $ds = $daySummary[$day];
        $isToday = $day === date('Y-m-d');
        $maxVal  = max(1, max(array_map(fn($d)=>$d['total']??0,$daySummary)));
        $barH    = $maxVal > 0 ? round((($ds['total']??0)/$maxVal)*100) : 0;
      ?>
      <div class="col text-center">
        <div class="small fw-semibold <?= $isToday?'text-primary':'' ?>"><?= date('D',strtotime($day)) ?></div>
        <div class="small text-muted" style="font-size:.7rem;"><?= date('m/d',strtotime($day)) ?></div>
        <div class="d-flex flex-column justify-content-end bg-light rounded" style="height:80px;overflow:hidden;">
          <?php if (($ds['total']??0) > 0): ?>
          <div class="bg-<?= $isToday?'primary':'secondary' ?> rounded" style="height:<?= $barH ?>%;min-height:4px;"></div>
          <?php endif; ?>
        </div>
        <div class="small fw-bold mt-1"><?= $ds['total']??0 ?></div>
        <div class="d-flex justify-content-center gap-1 mt-1">
          <span class="badge bg-success" style="font-size:.6rem;"><?= $ds['yes_count']??0 ?></span>
          <span class="badge bg-danger"  style="font-size:.6rem;"><?= $ds['no_count']??0 ?></span>
          <span class="badge bg-dark"  style="font-size:.6rem;"><?= $ds['missed_count']??0 ?></span>
        </div>
      </div>
      <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="card mb-3">
    <div class="card-header"><i class="bi bi-table me-2"></i>Daily Breakdown</div>
    <div class="card-body p-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-dark">
          <tr><th>Day</th><th>Date</th><th>Total</th><th>YES</th><th>NO</th><th>Missed</th><th>No Show</th><th>Check-Ins</th><th>Check-Outs</th></tr>
        </thead>
        <tbody>
        <?php foreach ($days as $day):
          $ds = $daySummary[$day];
          $isToday = $day===date('Y-m-d');
        ?>
        <tr class="<?= $isToday?'table-primary':'' ?>">
          <td class="fw-semibold"><?= date('l',strtotime($day)) ?></td>
          <td><?= date('M d, Y',strtotime($day)) ?></td>
          <td><?= $ds['total']??0 ?></td>
          <td><span class="badge bg-success"><?= $ds['yes_count']??0 ?></span></td>
          <td><span class="badge bg-danger"><?= $ds['no_count']??0 ?></span></td>
          <td><span class="badge bg-dark"><?= $ds['missed_count']??0 ?></span></td>
          <td><span class="badge bg-danger"><?= $ds['no_show_count']??0 ?></span></td>
          <td><?= $ds['checkins']??0 ?></td>
          <td><?= $ds['checkouts']??0 ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><i class="bi bi-door-open me-2"></i>Room Usage This Week</div>
    <div class="card-body p-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-dark"><tr><th>Room</th><th>Scheduled</th><th>Check-Ins</th><th>Utilization</th></tr></thead>
        <tbody>
        <?php while ($rr = $roomResult->fetch_assoc()):
          $util = $rr['total_sched']>0 ? round($rr['checkins']/$rr['total_sched']*100) : 0;
          $badge= $util>=75?'success':($util>=40?'warning':'danger');
        ?>
        <tr>
          <td><span class="badge bg-dark me-1"><?= htmlspecialchars($rr['room_code']) ?></span><?= htmlspecialchars($rr['room_name']) ?></td>
          <td><?= $rr['total_sched'] ?></td>
          <td><?= $rr['checkins'] ?></td>
          <td><span class="badge bg-<?= $badge ?>"><?= $util ?>%</span></td>
        </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($view === 'monthly'): ?>
  <?php
  // Reused verbatim from the former admin/reports/monthly.php.
  $month = $_GET['month'] ?? date('Y-m');
  $mCheck = DateTime::createFromFormat('Y-m', $month);
  if (!$mCheck || $mCheck->format('Y-m') !== $month) $month = date('Y-m');
  $monthStart= $month . '-01';
  $monthEnd  = date('Y-m-t', strtotime($monthStart));
  $monthLabel= date('F Y', strtotime($monthStart));

  $weeks = [];
  $cursor = strtotime($monthStart);
  $monthEndTs = strtotime($monthEnd);
  while ($cursor <= $monthEndTs) {
      $wStart = date('Y-m-d', $cursor);
      $wEnd   = date('Y-m-d', min($cursor + 6*86400, $monthEndTs));
      $stmt = $conn->prepare("
          SELECT COUNT(*) AS total,
                 SUM(confirmation='yes') AS yes_c,
                 SUM(confirmation='no')  AS no_c,
                 SUM(status='Missed Confirmation') AS missed_c,
                 SUM(status='No Show') AS no_show_c,
                 SUM(checkin_at IS NOT NULL) AS checkins
          FROM room_logs WHERE log_date BETWEEN ? AND ?
      ");
      $stmt->bind_param('ss',$wStart,$wEnd); $stmt->execute();
      $weeks[] = array_merge(['start'=>$wStart,'end'=>$wEnd], $stmt->get_result()->fetch_assoc());
      $stmt->close();
      $cursor += 7 * 86400;
  }

  $topRooms = $conn->prepare("
      SELECT r.room_code, r.room_name, COUNT(l.id) AS total, SUM(l.checkin_at IS NOT NULL) AS checkins
      FROM rooms r LEFT JOIN room_logs l ON l.room_id=r.id AND l.log_date BETWEEN ? AND ?
      GROUP BY r.id ORDER BY checkins DESC LIMIT 5
  ");
  $topRooms->bind_param('ss',$monthStart,$monthEnd); $topRooms->execute();
  $topResult = $topRooms->get_result(); $topRooms->close();

  $topFaculty = $conn->prepare("
      SELECT u.name, u.title, COUNT(l.id) AS total, SUM(l.confirmation='yes') AS yes_c, SUM(l.checkin_at IS NOT NULL) AS checkins
      FROM users u LEFT JOIN room_logs l ON l.faculty_id=u.id AND l.log_date BETWEEN ? AND ?
      WHERE u.role='faculty' GROUP BY u.id ORDER BY checkins DESC LIMIT 5
  ");
  $topFaculty->bind_param('ss',$monthStart,$monthEnd); $topFaculty->execute();
  $facResult = $topFaculty->get_result(); $topFaculty->close();

  $totals = $conn->prepare("
      SELECT COUNT(*) AS total, SUM(confirmation='yes') AS yes_c,
             SUM(confirmation='no') AS no_c,
             SUM(status='Missed Confirmation') AS missed_c,
             SUM(status='No Show') AS no_show_c,
             SUM(checkin_at IS NOT NULL) AS checkins
      FROM room_logs WHERE log_date BETWEEN ? AND ?
  ");
  $totals->bind_param('ss',$monthStart,$monthEnd); $totals->execute();
  $totRow = $totals->get_result()->fetch_assoc(); $totals->close();
  ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="text-muted small"><?= $monthLabel ?></div>
  </div>
  <div class="card mb-3 no-print">
    <div class="card-body py-2">
      <form method="GET" class="d-flex gap-2 align-items-end flex-wrap">
        <input type="hidden" name="view" value="monthly">
        <div><label class="form-label small fw-semibold mb-1">Select Month</label>
          <input type="month" name="month" class="form-control form-control-sm" value="<?= htmlspecialchars($month) ?>"></div>
        <button class="btn btn-sm btn-danger mt-auto"><i class="bi bi-search me-1"></i>Generate</button>
        <a href="?view=monthly&month=<?= date('Y-m', strtotime($monthStart.' -1 month')) ?>" class="btn btn-sm btn-outline-secondary mt-auto">← Prev</a>
        <a href="?view=monthly&month=<?= date('Y-m', strtotime($monthStart.' +1 month')) ?>" class="btn btn-sm btn-outline-secondary mt-auto">Next →</a>
      </form>
    </div>
  </div>
  <div class="row g-3 mb-3">
    <?php
    $kpis=[
      ['Total Logs','secondary',$totRow['total']??0],
      ['YES Confirmations','success',$totRow['yes_c']??0],
      ['NO / Cancelled','danger',$totRow['no_c']??0],
      ['Missed Confirmations','dark',$totRow['missed_c']??0],
      ['No Shows','danger',$totRow['no_show_c']??0],
      ['Total Check-Ins','primary',$totRow['checkins']??0],
    ];
    foreach($kpis as [$l,$c,$v]):
    ?>
    <div class="col-6 col-md-3">
      <div class="card text-center">
        <div class="card-body py-2">
          <div class="fw-bold text-<?= $c ?> fs-4"><?= $v ?></div>
          <div class="small text-muted"><?= $l ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="row g-3 mb-3">
    <div class="col-md-7">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-table me-2"></i>Weekly Breakdown</div>
        <div class="card-body p-0">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-dark"><tr><th>Week</th><th>Total</th><th>YES</th><th>NO</th><th>Missed</th><th>No Show</th><th>Check-Ins</th></tr></thead>
            <tbody>
            <?php foreach ($weeks as $i=>$w): ?>
            <tr>
              <td class="small">Week <?= $i+1 ?><br><span class="text-muted" style="font-size:.7rem;"><?= date('M d',strtotime($w['start'])) ?>–<?= date('M d',strtotime($w['end'])) ?></span></td>
              <td><?= $w['total']??0 ?></td>
              <td><span class="badge bg-success"><?= $w['yes_c']??0 ?></span></td>
              <td><span class="badge bg-danger"><?= $w['no_c']??0 ?></span></td>
              <td><span class="badge bg-dark"><?= $w['missed_c']??0 ?></span></td>
              <td><span class="badge bg-danger"><?= $w['no_show_c']??0 ?></span></td>
              <td><?= $w['checkins']??0 ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-md-5">
      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-trophy me-2 text-warning"></i>Top Rooms</div>
        <ul class="list-group list-group-flush">
          <?php while($r=$topResult->fetch_assoc()): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center py-2">
            <span><span class="badge bg-dark me-1"><?= htmlspecialchars($r['room_code']) ?></span><?= htmlspecialchars($r['room_name']) ?></span>
            <span class="badge bg-primary"><?= $r['checkins'] ?> CI</span>
          </li>
          <?php endwhile; ?>
        </ul>
      </div>
      <div class="card">
        <div class="card-header"><i class="bi bi-person-check me-2 text-success"></i>Top Faculty</div>
        <ul class="list-group list-group-flush">
          <?php while($f=$facResult->fetch_assoc()): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center py-2">
            <span class="small"><?= htmlspecialchars(facultyDisplayName($f['title'] ?? null, $f['name'])) ?></span>
            <span class="badge bg-success"><?= $f['checkins'] ?> CI</span>
          </li>
          <?php endwhile; ?>
        </ul>
      </div>
    </div>
  </div>

<?php elseif ($view === 'faculty_attendance'): ?>
  <?php
  // Reused verbatim from the former admin/reports/faculty_attendance.php.
  $dateFromR = $_GET['date_from'] ?? date('Y-m-01');
  $dateToR   = $_GET['date_to']   ?? date('Y-m-d');
  $isYmd = function ($d) { $dt = DateTime::createFromFormat('Y-m-d', $d); return $dt && $dt->format('Y-m-d') === $d; };
  if (!$isYmd($dateFromR)) $dateFromR = date('Y-m-01');
  if (!$isYmd($dateToR))   $dateToR   = date('Y-m-d');
  if ($dateFromR > $dateToR) { [$dateFromR, $dateToR] = [$dateToR, $dateFromR]; }

  $data = $conn->prepare("
      SELECT u.id, u.name, u.title, u.department, u.employee_id,
             COUNT(l.id)                            AS total_scheduled,
             SUM(l.confirmation='yes')              AS confirmed_yes,
             SUM(l.confirmation='no')               AS confirmed_no,
             SUM(l.status='Missed Confirmation')    AS missed_confirmations,
             SUM(l.status='No Show')                AS no_shows,
             SUM(l.checkin_at IS NOT NULL)          AS checkins,
             SUM(l.checkout_at IS NOT NULL)         AS checkouts,
             SUM(l.checkin_at IS NOT NULL AND TIME(l.checkin_at) > s.time_start) AS late_checkins,
             ROUND(AVG(CASE WHEN l.checkin_at IS NOT NULL THEN GREATEST(TIMESTAMPDIFF(MINUTE, CONCAT(l.log_date, ' ', s.time_start), l.checkin_at), 0) END),1) AS avg_checkin_delay,
             ROUND(SUM(l.confirmation='yes')/NULLIF(COUNT(l.id),0)*100,1) AS yes_rate,
             ROUND(SUM(l.confirmation IS NOT NULL)/NULLIF(COUNT(l.id),0)*100,1) AS response_rate,
             ROUND(SUM(l.checkin_at IS NOT NULL)/NULLIF(SUM(l.confirmation='yes'),0)*100,1) AS checkin_rate
      FROM users u
      LEFT JOIN room_logs l ON l.faculty_id=u.id AND l.log_date BETWEEN ? AND ?
      LEFT JOIN schedules s ON s.id = l.schedule_id
      WHERE u.role='faculty'
      GROUP BY u.id
      ORDER BY yes_rate DESC
  ");
  $data->bind_param('ss',$dateFromR,$dateToR); $data->execute();
  $result = $data->get_result(); $data->close();
  $rows=[]; while($r=$result->fetch_assoc()) $rows[]=$r;
  ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="text-muted small"><?= date('M d, Y',strtotime($dateFromR)) ?> — <?= date('M d, Y',strtotime($dateToR)) ?></div>
  </div>
  <div class="card mb-3 no-print">
    <div class="card-body py-2">
      <form method="GET" class="d-flex gap-2 flex-wrap align-items-end">
        <input type="hidden" name="view" value="faculty_attendance">
        <div><label class="form-label small fw-semibold mb-1">From</label>
          <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($dateFromR) ?>"></div>
        <div><label class="form-label small fw-semibold mb-1">To</label>
          <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($dateToR) ?>"></div>
        <button class="btn btn-sm btn-success mt-auto"><i class="bi bi-search me-1"></i>Generate</button>
      </form>
    </div>
  </div>
  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-dark">
            <tr>
              <th>#</th><th>Faculty</th><th>Dept.</th>
              <th>Scheduled</th><th>YES</th><th>NO</th><th>Missed</th><th>No Show</th>
              <th>Check-Ins</th><th>Late</th><th>Avg Delay</th><th>Check-Outs</th>
              <th>Response Rate</th><th>YES Rate</th><th>Check-In Rate</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($rows)): ?>
          <tr><td colspan="15" class="text-center py-4 text-muted">No data found.</td></tr>
          <?php endif; ?>
          <?php foreach($rows as $i=>$row):
            $yRate = $row['yes_rate'] ?? 0;
            $rRate = $row['response_rate'] ?? 0;
            $cRate = $row['checkin_rate'] ?? 0;
            $yBadge = $yRate>=75?'success':($yRate>=50?'warning':'danger');
            $rBadge = $rRate>=75?'success':($rRate>=50?'warning':'danger');
            $cBadge = $cRate>=75?'success':($cRate>=50?'warning':'danger');
          ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td>
              <div class="fw-semibold"><?= htmlspecialchars(facultyDisplayName($row['title'] ?? null, $row['name'])) ?></div>
              <?php if ($row['employee_id']): ?>
              <div class="text-muted small"><?= htmlspecialchars($row['employee_id']) ?></div>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= htmlspecialchars($row['department']??'—') ?></td>
            <td><?= $row['total_scheduled'] ?></td>
            <td><span class="badge bg-success"><?= $row['confirmed_yes'] ?></span></td>
            <td><span class="badge bg-danger"><?= $row['confirmed_no'] ?></span></td>
            <td><span class="badge bg-dark"><?= $row['missed_confirmations'] ?></span></td>
            <td><span class="badge bg-danger"><?= $row['no_shows'] ?></span></td>
            <td><?= $row['checkins'] ?></td>
            <td><span class="badge bg-warning text-dark"><?= $row['late_checkins'] ?></span></td>
            <td><?= $row['avg_checkin_delay'] !== null ? $row['avg_checkin_delay'] . ' min' : '0 min' ?></td>
            <td><?= $row['checkouts'] ?></td>
            <td>
              <div class="progress" style="height:16px;min-width:80px;">
                <div class="progress-bar bg-<?= $rBadge ?>" style="width:<?= min(100,$rRate) ?>%"><?= $rRate ?>%</div>
              </div>
            </td>
            <td>
              <div class="progress" style="height:16px;min-width:80px;">
                <div class="progress-bar bg-<?= $yBadge ?>" style="width:<?= min(100,$yRate) ?>%"><?= $yRate ?>%</div>
              </div>
            </td>
            <td>
              <div class="progress" style="height:16px;min-width:80px;">
                <div class="progress-bar bg-<?= $cBadge ?>" style="width:<?= min(100,$cRate??0) ?>%"><?= $cRate ?? 0 ?>%</div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<?php elseif ($view === 'room_occupancy'): ?>
  <?php
  // Reused verbatim from the former admin/reports/room_occupancy.php.
  $dateFromR = $_GET['date_from'] ?? date('Y-m-d');
  $dateToR   = $_GET['date_to']   ?? date('Y-m-d');
  $isYmd = function ($d) { $dt = DateTime::createFromFormat('Y-m-d', $d); return $dt && $dt->format('Y-m-d') === $d; };
  if (!$isYmd($dateFromR)) $dateFromR = date('Y-m-d');
  if (!$isYmd($dateToR))   $dateToR   = date('Y-m-d');
  if ($dateFromR > $dateToR) { [$dateFromR, $dateToR] = [$dateToR, $dateFromR]; }

  $liveRooms = $conn->query("SELECT room_code, room_name, building, floor, capacity, status FROM rooms ORDER BY room_code");

  $hist = $conn->prepare("
      SELECT r.room_code, r.room_name,
             l.log_date,
             COUNT(l.id)                            AS sessions,
             SUM(l.checkin_at IS NOT NULL)          AS occupied_sessions,
             SUM(TIMESTAMPDIFF(MINUTE,l.checkin_at,l.checkout_at)) AS total_minutes
      FROM rooms r
      LEFT JOIN room_logs l ON l.room_id=r.id AND l.log_date BETWEEN ? AND ? AND l.checkin_at IS NOT NULL
      GROUP BY r.id, l.log_date
      ORDER BY r.room_code, l.log_date DESC
  ");
  $hist->bind_param('ss',$dateFromR,$dateToR); $hist->execute();
  $histResult = $hist->get_result(); $hist->close();
  ?>
  <h6 class="fw-bold mb-2"><i class="bi bi-circle-fill text-success me-2" style="font-size:.6rem;"></i>Live Room Status</h6>
  <div class="row g-2 mb-4">
  <?php
  $bgMap = ['Available'=>'success','Scheduled'=>'primary','Reserved'=>'warning','Occupied'=>'danger','Unconfirmed'=>'secondary','Missed Confirmation'=>'dark','No Show'=>'danger'];
  while ($room = $liveRooms->fetch_assoc()):
    $bg = $bgMap[$room['status']] ?? 'secondary';
  ?>
  <div class="col-6 col-md-4 col-lg-3">
    <div class="card border-<?= $bg ?> border-2">
      <div class="card-body p-2 text-center">
        <div class="fw-bold text-<?= $bg ?>"><?= htmlspecialchars($room['room_code']) ?></div>
        <div class="text-muted" style="font-size:.72rem;"><?= htmlspecialchars($room['room_name']) ?></div>
        <div class="my-1"><?= statusBadge($room['status']) ?></div>
        <div class="text-muted" style="font-size:.7rem;">
          <?= htmlspecialchars($room['building']??'') ?> · Cap: <?= $room['capacity'] ?>
        </div>
      </div>
    </div>
  </div>
  <?php endwhile; ?>
  </div>
  <div class="card mb-3 no-print">
    <div class="card-body py-2">
      <form method="GET" class="d-flex gap-2 flex-wrap align-items-end">
        <input type="hidden" name="view" value="room_occupancy">
        <div><label class="form-label small fw-semibold mb-1">From</label>
          <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($dateFromR) ?>"></div>
        <div><label class="form-label small fw-semibold mb-1">To</label>
          <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($dateToR) ?>"></div>
        <button class="btn btn-sm btn-warning text-dark mt-auto"><i class="bi bi-search me-1"></i>Show History</button>
      </form>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><i class="bi bi-clock-history me-2"></i>Historical Occupancy Log</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-dark">
            <tr><th>Room</th><th>Date</th><th>Sessions</th><th>Occupied</th><th>Total Time</th></tr>
          </thead>
          <tbody>
          <?php
          $found = false;
          while ($row = $histResult->fetch_assoc()):
            if (!$row['log_date']) continue;
            $found = true;
            $hrs = $row['total_minutes'] ? round($row['total_minutes']/60,1) : 0;
          ?>
          <tr>
            <td><span class="badge bg-dark"><?= htmlspecialchars($row['room_code']) ?></span> <?= htmlspecialchars($row['room_name']) ?></td>
            <td><?= date('M d, Y',strtotime($row['log_date'])) ?></td>
            <td><?= $row['sessions'] ?></td>
            <td><?= $row['occupied_sessions'] ?></td>
            <td><?= $hrs ?> hrs</td>
          </tr>
          <?php endwhile; ?>
          <?php if (!$found): ?>
          <tr><td colspan="5" class="text-center py-3 text-muted">No occupancy data for selected range.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<?php elseif ($view === 'room_utilization'): ?>
  <?php
  // Reused verbatim from the former admin/reports/room_utilization.php.
  $dateFromR = $_GET['date_from'] ?? date('Y-m-01');
  $dateToR   = $_GET['date_to']   ?? date('Y-m-d');
  $isYmd = function ($d) { $dt = DateTime::createFromFormat('Y-m-d', $d); return $dt && $dt->format('Y-m-d') === $d; };
  if (!$isYmd($dateFromR)) $dateFromR = date('Y-m-01');
  if (!$isYmd($dateToR))   $dateToR   = date('Y-m-d');
  if ($dateFromR > $dateToR) { [$dateFromR, $dateToR] = [$dateToR, $dateFromR]; }

  $roomsStmt = $conn->prepare("
      SELECT r.id, r.room_code, r.room_name, r.capacity,
             COUNT(l.id) AS total_scheduled,
             SUM(l.confirmation='yes') AS confirmed_yes,
             SUM(l.confirmation='no')  AS confirmed_no,
             SUM(l.status='Missed Confirmation') AS missed_confirmations,
             SUM(l.status='No Show') AS no_shows,
             SUM(l.checkin_at IS NOT NULL) AS total_checkins,
             SUM(l.checkout_at IS NOT NULL) AS total_checkouts,
             SUM(l.checkin_at IS NOT NULL AND TIME(l.checkin_at) > s.time_start) AS late_checkins,
             ROUND(AVG(CASE WHEN l.checkin_at IS NOT NULL THEN GREATEST(TIMESTAMPDIFF(MINUTE, CONCAT(l.log_date, ' ', s.time_start), l.checkin_at), 0) END),1) AS avg_checkin_delay,
             SUM(TIMESTAMPDIFF(MINUTE, l.checkin_at, l.checkout_at)) AS total_minutes
      FROM rooms r
      LEFT JOIN room_logs l ON l.room_id = r.id AND l.log_date BETWEEN ? AND ?
      LEFT JOIN schedules s ON s.id = l.schedule_id
      GROUP BY r.id
      ORDER BY total_checkins DESC
  ");
  $roomsStmt->bind_param('ss',$dateFromR,$dateToR); $roomsStmt->execute();
  $result = $roomsStmt->get_result(); $roomsStmt->close();
  $rows = []; while($r=$result->fetch_assoc()) $rows[]=$r;
  $usedRows = array_values(array_filter($rows, fn($row) => (int)($row['total_checkins'] ?? 0) > 0));
  $mostUsed = $usedRows ? $usedRows[0] : null;
  $leastUsed = !empty($rows) ? $rows[count($rows) - 1] : null;
  $avgUtil = 0;
  if (!empty($rows)) {
      $utilTotal = 0;
      foreach ($rows as $row) {
          $utilTotal += $row['total_scheduled'] > 0 ? ($row['total_checkins'] / $row['total_scheduled']) * 100 : 0;
      }
      $avgUtil = round($utilTotal / count($rows), 1);
  }
  ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="text-muted small"><?= date('M d, Y',strtotime($dateFromR)) ?> — <?= date('M d, Y',strtotime($dateToR)) ?></div>
  </div>
  <div class="card mb-3 no-print">
    <div class="card-body py-2">
      <form method="GET" class="d-flex gap-2 flex-wrap align-items-end">
        <input type="hidden" name="view" value="room_utilization">
        <div><label class="form-label small fw-semibold mb-1">From</label>
          <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($dateFromR) ?>"></div>
        <div><label class="form-label small fw-semibold mb-1">To</label>
          <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($dateToR) ?>"></div>
        <button class="btn btn-sm btn-primary mt-auto"><i class="bi bi-search me-1"></i>Generate</button>
      </form>
    </div>
  </div>
  <div class="row g-3 mb-3">
    <?php
    $metricCards = [
      ['Most Used Room', 'success', 'bi-trophy', $mostUsed ? $mostUsed['room_code'] . ' (' . (int)$mostUsed['total_checkins'] . ' check-ins)' : 'No usage'],
      ['Least Used Room', 'secondary', 'bi-arrow-down-circle', $leastUsed ? $leastUsed['room_code'] . ' (' . (int)$leastUsed['total_checkins'] . ' check-ins)' : 'No usage'],
      ['Average Room Utilization', 'primary', 'bi-percent', $avgUtil . '%'],
    ];
    foreach ($metricCards as [$label, $color, $icon, $value]):
    ?>
    <div class="col-md-4">
      <div class="card h-100">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="stat-icon bg-<?= $color ?>-subtle text-<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
          <div>
            <div class="fw-bold text-<?= $color ?>"><?= htmlspecialchars($value) ?></div>
            <div class="stat-label"><?= $label ?></div>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="card mb-3">
    <div class="card-header bg-primary text-white"><i class="bi bi-bar-chart me-2"></i>Check-In Count per Room</div>
    <div class="card-body">
      <?php foreach ($rows as $row):
        $max = max(1, max(array_column($rows,'total_checkins')));
        $pct = $max > 0 ? round(($row['total_checkins']/$max)*100) : 0;
        $util = $row['total_scheduled'] > 0 ? round(($row['total_checkins']/$row['total_scheduled'])*100) : 0;
      ?>
      <div class="mb-2">
        <div class="d-flex justify-content-between small mb-1">
          <span class="fw-semibold"><?= htmlspecialchars($row['room_code']) ?> — <?= htmlspecialchars($row['room_name']) ?></span>
          <span class="text-muted"><?= $row['total_checkins'] ?> check-ins (<?= $util ?>% util)</span>
        </div>
        <div class="progress" style="height:18px;">
          <div class="progress-bar bg-primary" style="width:<?= $pct ?>%"><?= $row['total_checkins'] ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-dark">
            <tr>
              <th>Room</th><th>Capacity</th><th>Scheduled</th>
              <th>YES</th><th>NO</th><th>Missed</th><th>No Show</th><th>Check-Ins</th><th>Late</th><th>Avg Delay</th>
              <th>Check-Outs</th><th>Total Hours</th><th>Utilization %</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $row):
            $util = $row['total_scheduled'] > 0 ? round(($row['total_checkins']/$row['total_scheduled'])*100) : 0;
            $hours = $row['total_minutes'] ? round($row['total_minutes']/60,1) : 0;
            $badge = $util>=75 ? 'success' : ($util>=40 ? 'warning' : 'danger');
          ?>
          <tr>
            <td>
              <span class="badge bg-dark me-1"><?= htmlspecialchars($row['room_code']) ?></span>
              <span class="small"><?= htmlspecialchars($row['room_name']) ?></span>
            </td>
            <td><?= $row['capacity'] ?></td>
            <td><?= $row['total_scheduled'] ?></td>
            <td><span class="badge bg-success"><?= $row['confirmed_yes'] ?></span></td>
            <td><span class="badge bg-danger"><?= $row['confirmed_no'] ?></span></td>
            <td><span class="badge bg-dark"><?= $row['missed_confirmations'] ?></span></td>
            <td><span class="badge bg-danger"><?= $row['no_shows'] ?></span></td>
            <td class="fw-semibold text-primary"><?= $row['total_checkins'] ?></td>
            <td><span class="badge bg-warning text-dark"><?= $row['late_checkins'] ?></span></td>
            <td><?= $row['avg_checkin_delay'] !== null ? $row['avg_checkin_delay'] . ' min' : '0 min' ?></td>
            <td><?= $row['total_checkouts'] ?></td>
            <td><?= $hours ?> hrs</td>
            <td><span class="badge bg-<?= $badge ?>"><?= $util ?>%</span></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<?php endif; ?>

  </div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('show');document.getElementById('sidebarOverlay').classList.toggle('show');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('show');document.getElementById('sidebarOverlay').classList.remove('show');}
</script>
</body></html>
