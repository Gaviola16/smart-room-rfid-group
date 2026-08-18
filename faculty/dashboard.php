<?php
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('faculty');

$pageTitle  = 'My Dashboard';
$faculty_id = $_SESSION['user_id'];
$today      = getTodayName();
$todayDate  = date('Y-m-d');

runScheduleAutomation($conn, $faculty_id);

$sql = <<<'SQL'
SELECT s.id AS schedule_id, s.subject, s.section, s.time_start, s.time_end,
       TIME(DATE_SUB(CONCAT(CURDATE(), ' ', s.time_start), INTERVAL 10 MINUTE)) AS confirmation_start,
       r.id AS room_id, r.room_code, r.room_name, r.building, r.capacity,
       l.id AS log_id, l.confirmation, l.status AS log_status,
       l.confirmed_at, l.checkin_at, l.checkout_at
  FROM schedules s
  JOIN rooms r ON s.room_id = r.id
  LEFT JOIN room_logs l ON l.id = (
      SELECT rl2.id
      FROM room_logs rl2
      WHERE rl2.schedule_id = s.id AND rl2.log_date = ?
      ORDER BY (rl2.status = 'Unconfirmed') ASC, rl2.id DESC
      LIMIT 1
  )
  WHERE s.faculty_id = ? AND s.day_of_week = ? AND s.is_active = 1
  ORDER BY s.time_start ASC
SQL;
$schedules = $conn->prepare($sql);
$schedules->bind_param('sis', $todayDate, $faculty_id, $today);
$schedules->execute();
$scheduleResult = $schedules->get_result();
$schedules->close();

$scheduleRows = [];
while ($r = $scheduleResult->fetch_assoc()) {
    $scheduleRows[] = $r;
}


$totalToday     = count($scheduleRows);
$confirmedCount = 0;   
$pendingCount   = 0;   
$completedCount = 0;   
$missedCount    = 0;
$noShowCount    = 0;
$currentClass   = null;
$upcoming       = null; 
$confirmationPromptLogId = null;

$nowTime = date('H:i:s');

foreach ($scheduleRows as $row) {
    if ($row['log_status'] === 'Missed Confirmation') {
        $missedCount++;
    } elseif ($row['log_status'] === 'No Show') {
        $noShowCount++;
    } elseif ($row['confirmation'] !== null) {
        $confirmedCount++;
    } elseif ($nowTime >= $row['confirmation_start'] && $nowTime < $row['time_start']) {
        $pendingCount++;
    }
    if (!empty($row['checkout_at'])) {
        $completedCount++;
    }
    if ($currentClass === null && $nowTime >= $row['time_start'] && $nowTime <= $row['time_end'] && empty($row['checkout_at'])) {
        $currentClass = $row;
    }
    if ($upcoming === null && empty($row['checkout_at']) && $row['time_end'] >= $nowTime) {
        $upcoming = $row;
    }
    if (
        $confirmationPromptLogId === null &&
        !empty($row['log_id']) &&
        $row['confirmation'] === null &&
        $row['log_status'] === 'Unconfirmed' &&
        empty($row['checkin_at']) &&
        empty($row['checkout_at']) &&
        $nowTime >= $row['confirmation_start'] &&
        $nowTime < $row['time_start']
    ) {
        $confirmationPromptLogId = (int)$row['log_id'];
    }
}

$recentActivity = [];
if (tableExists($conn, 'activity_logs')) {
    $actStmt = $conn->prepare("
        SELECT action_type, description, created_at
        FROM activity_logs
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $actStmt->bind_param('i', $faculty_id);
    $actStmt->execute();
    $actResult = $actStmt->get_result();
    while ($a = $actResult->fetch_assoc()) $recentActivity[] = $a;
    $actStmt->close();
}

function activityIcon($actionType) {
    $map = [
        'login_success'   => ['bi-box-arrow-in-right', 'primary'],
        'checkin'         => ['bi-box-arrow-in-right', 'success'],
        'checkout'        => ['bi-box-arrow-right',    'secondary'],
        'class_confirmed' => ['bi-check-circle',       'success'],
        'class_declined'  => ['bi-x-circle',           'danger'],
        'profile_updated' => ['bi-person-gear',        'info'],
        'password_changed'=> ['bi-shield-lock',        'warning'],
    ];
    return $map[$actionType] ?? ['bi-info-circle', 'secondary'];
}

include __DIR__ . '/../authentication.php';
?>

<style>
/* Scoped to this page only — does not affect shared layout or other pages.
   Simple, flat, academic style: white cards, thin borders, no shadows,
   no gradients, one accent blue, and color used only where it carries
   meaning (green/red/yellow/gray for status). */
.faculty-dashboard-page { color: #1f2937; }

.faculty-dashboard-page .card {
  box-shadow: none;
  border: 1px solid #dfe3e8;
  border-radius: 6px;
}

.faculty-dashboard-page h5,
.faculty-dashboard-page h6 { color: #111827; }

.fd-label { font-size: .75rem; color: #6b7280; margin-bottom: .25rem; }
.fd-value { font-size: 1rem; font-weight: 600; color: #111827; }
.fd-sub   { font-size: .8rem; color: #6b7280; }

.sched-card { border-radius: 6px; }
.time-badge { font-size: .95rem; font-weight: 600; color: #111827; }
.room-chip  {
  background: #fff; color: var(--brand-blue, #1a6bcc);
  border: 1px solid var(--brand-blue, #1a6bcc);
  border-radius: 4px; padding: 2px 10px; font-size: .8rem; font-weight: 600;
}
.action-area { border-top: 1px solid #eee; padding-top: .85rem; margin-top: .85rem; }
.status-trail { font-size: .75rem; color: #6b7280; }

.btn-yes, .btn-no, .btn-checkin, .btn-checkout {
  border: none; font-weight: 600; border-radius: 5px;
}
.btn-yes  { background: #198754; color:#fff; }
.btn-yes:hover  { background: #146c43; color:#fff; }
.btn-no   { background: #dc3545; color:#fff; }
.btn-no:hover   { background: #b02a37; color:#fff; }
.btn-checkin  { background: var(--brand-blue, #1a6bcc); color:#fff; }
.btn-checkin:hover { background: #15579f; color:#fff; }
.btn-checkout { background: #6c757d; color:#fff; }
.btn-checkout:hover { background: #565e64; color:#fff; }

.stat-mini .stat-num  { font-size: 1.3rem; font-weight: 700; line-height:1; }
.stat-mini .stat-lbl  { font-size: .72rem; color:#6b7280; }

.upcoming-card { border-left: 3px solid var(--brand-blue, #1a6bcc); }
.activity-item { font-size: .8rem; padding: .5rem 0; border-bottom: 1px solid #f0f0f0; }
.activity-item:last-child { border-bottom: none; }

/* Hide the generic role/date line in the shared topbar only while this
   page is loaded — the faculty schedule below already shows the date
   in context, so it doesn't need repeating in the header. No other
   page or file is changed. */
body:has(.faculty-dashboard-page) .topbar .badge.bg-primary-subtle,
body:has(.faculty-dashboard-page) .topbar .text-muted.small.d-none.d-md-inline {
  display: none;
}
</style>

<div class="faculty-dashboard-page">

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="fw-bold mb-0">Today's Classes</h5>
  <div class="fd-sub"><?= date('l, F d, Y') ?> &middot; <span id="liveClock"></span></div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="fd-label">Current Subject</div>
        <div class="fd-value"><?= $currentClass ? htmlspecialchars($currentClass['subject']) : 'No current class' ?></div>
        <div class="fd-sub"><?= $currentClass ? date('h:i A', strtotime($currentClass['time_start'])) . ' - ' . date('h:i A', strtotime($currentClass['time_end'])) : 'Nothing in progress' ?></div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="fd-label">Current Room</div>
        <div class="fd-value"><?= $currentClass ? htmlspecialchars($currentClass['room_code']) : 'None' ?></div>
        <div class="fd-sub"><?= $currentClass ? htmlspecialchars($currentClass['room_name']) : 'No room currently assigned' ?></div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="fd-label">Confirmation Window</div>
        <div class="fd-value" id="confirmationCountdown">--:--</div>
        <div class="fd-sub" id="confirmationCountdownLabel">Checking schedule</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md">
    <div class="card stat-mini h-100">
      <div class="card-body">
        <div class="stat-num text-dark"><?= $totalToday ?></div>
        <div class="stat-lbl">Today's Classes</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md">
    <div class="card stat-mini h-100">
      <div class="card-body">
        <div class="stat-num text-success"><?= $confirmedCount ?></div>
        <div class="stat-lbl">Confirmed</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md">
    <div class="card stat-mini h-100">
      <div class="card-body">
        <div class="stat-num text-warning"><?= $pendingCount ?></div>
        <div class="stat-lbl">Pending</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md">
    <div class="card stat-mini h-100">
      <div class="card-body">
        <div class="stat-num text-secondary"><?= $completedCount ?></div>
        <div class="stat-lbl">Completed</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md">
    <div class="card stat-mini h-100">
      <div class="card-body">
        <div class="stat-num text-dark"><?= $missedCount ?></div>
        <div class="stat-lbl">Missed Confirm.</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md">
    <div class="card stat-mini h-100">
      <div class="card-body">
        <div class="stat-num text-danger"><?= $noShowCount ?></div>
        <div class="stat-lbl">No Shows</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">

  <div class="col-lg-6">
    <?php if ($upcoming): ?>
    <div class="card upcoming-card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <span class="fd-label mb-0">Up Next</span>
          <span class="badge bg-light text-dark border"><?= htmlspecialchars($upcoming['room_code']) ?></span>
        </div>
        <div class="fw-bold fs-6 mb-1"><?= htmlspecialchars($upcoming['subject']) ?></div>
        <?php if ($upcoming['section']): ?>
        <div class="fd-sub mb-2"><?= htmlspecialchars($upcoming['section']) ?></div>
        <?php endif; ?>
        <div class="fd-value">
          <?= date('h:i A', strtotime($upcoming['time_start'])) ?> – <?= date('h:i A', strtotime($upcoming['time_end'])) ?>
        </div>
        <div class="fd-sub mt-1"><?= htmlspecialchars($upcoming['room_name']) ?></div>
      </div>
    </div>
    <?php else: ?>
    <div class="card h-100">
      <div class="card-body d-flex align-items-center justify-content-center text-center text-muted py-4">
        <div class="small">No more upcoming classes today.</div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-body">
        <div class="fw-semibold mb-2">Recent Activity</div>
        <?php if (empty($recentActivity)): ?>
        <div class="text-muted small text-center py-4">No recent activity yet.</div>
        <?php else: ?>
        <?php foreach ($recentActivity as $act): ?>
        <div class="activity-item">
          <div class="text-dark"><?= htmlspecialchars($act['description'] ?? ucfirst(str_replace('_',' ',$act['action_type']))) ?></div>
          <div class="text-muted" style="font-size:.7rem;"><?= date('M d, h:i A', strtotime($act['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php if (empty($scheduleRows)): ?>
<div class="card text-center py-4">
  <div class="card-body">
    <h6 class="text-muted mb-1">No Classes Today</h6>
    <p class="text-muted small mb-0">You have no scheduled classes for <?= $today ?>.</p>
  </div>
</div>
<?php endif; ?>

<div class="row g-3">
<?php foreach ($scheduleRows as $row):
  $logStatus   = $row['log_status']  ?? 'Unconfirmed';
  $confirm     = $row['confirmation'] ?? null;
  $hasCheckIn  = !empty($row['checkin_at']);
  $hasCheckOut = !empty($row['checkout_at']);
  $logId       = $row['log_id'];
  $canConfirm  = $confirm === null && $logId && (int)$logId === $confirmationPromptLogId;
  $promptExpired = $confirm === null && $nowTime >= $row['time_start'];
  // RFID attendance grace period: the class stays UNCONFIRMED (and RFID
  // check-in keeps working) for 15 minutes after the scheduled start even if
  // the faculty never answers Yes/No — this must match the grace period
  // closeExpiredConfirmations() uses in db.php so the dashboard and the
  // automatic "Missed Confirmation" cutoff never disagree.
  $rfidGraceDeadline = date('H:i:s', strtotime($row['time_start']) + 15 * 60);
  $awaitingRfid = $confirm === null && !$hasCheckIn && !$hasCheckOut
                  && $nowTime >= $row['time_start'] && $nowTime < $rfidGraceDeadline;

  $borderColor = [
    'Unconfirmed'         => 'secondary',
    'Reserved'            => 'warning',
    'Available'           => 'success',
    'Occupied'            => 'danger',
    'Missed Confirmation' => 'dark',
    'No Show'             => 'danger',
  ][$logStatus] ?? 'secondary';
?>
<div class="col-12 col-md-6 col-xl-4">
  <div class="card sched-card h-100 border-start border-4 border-<?= $borderColor ?>">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
          <div class="fw-bold fs-6"><?= htmlspecialchars($row['subject']) ?></div>
          <?php if ($row['section']): ?>
          <div class="text-muted small"><?= htmlspecialchars($row['section']) ?></div>
          <?php endif; ?>
        </div>
        <?= statusBadge($logStatus) ?>
      </div>

      <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <span class="room-chip"><?= htmlspecialchars($row['room_code']) ?></span>
        <span class="fd-sub"><?= htmlspecialchars($row['building'] ?? 'N/A') ?> &middot; <?= $row['capacity'] ?> seats</span>
      </div>
      <div class="time-badge mb-3">
        <?= date('h:i A', strtotime($row['time_start'])) ?> &mdash; <?= date('h:i A', strtotime($row['time_end'])) ?>
      </div>

      <div class="status-trail mb-1">
        <?php if ($row['confirmed_at']): ?>
        <?= $confirm==='yes' ? 'Confirmed YES' : 'Declined' ?> at <?= date('h:i A', strtotime($row['confirmed_at'])) ?>
        <?php endif; ?>
        <?php if ($hasCheckIn): ?>
        <br>Checked in at <?= date('h:i A', strtotime($row['checkin_at'])) ?>
        <?php endif; ?>
        <?php if ($hasCheckOut): ?>
        <br>Checked out at <?= date('h:i A', strtotime($row['checkout_at'])) ?>
        <?php endif; ?>
      </div>

      <div class="action-area">

        <?php if ($hasCheckOut): ?>
        <div class="alert alert-success py-2 mb-0 text-center small fw-semibold">Session Complete</div>

        <?php elseif ($hasCheckIn): ?>
        <div class="alert alert-primary py-2 mb-0 text-center small fw-semibold">Occupied &mdash; Tap your RFID card again to Check Out</div>

        <?php elseif ($confirm === 'no'): ?>
        <div class="alert alert-warning py-2 mb-0 text-center small fw-semibold">You declined this class &mdash; RFID attendance is disabled for it today</div>

        <?php elseif ($logStatus === 'No Show'): ?>
        <div class="alert alert-danger py-2 mb-0 text-center small fw-semibold">No Show</div>

        <?php elseif ($logStatus === 'Missed Confirmation'): ?>
        <div class="alert alert-dark py-2 mb-0 text-center small fw-semibold">Attendance window closed &mdash; room released</div>

        <?php elseif ($canConfirm): ?>
        <p class="small text-muted mb-2 fw-semibold">Will you conduct this class? <span class="fw-normal">(optional)</span></p>
        <div class="d-flex gap-2 mb-2">
          <form method="POST" action="confirm.php" class="flex-fill no-double">
            <input type="hidden" name="log_id"      value="<?= $logId ?>">
            <input type="hidden" name="room_id"     value="<?= $row['room_id'] ?>">
            <input type="hidden" name="confirmation" value="yes">
            <div class="d-grid">
              <button type="submit" class="btn btn-yes py-2">YES</button>
            </div>
          </form>
          <form method="POST" action="confirm.php" class="flex-fill no-double">
            <input type="hidden" name="log_id"      value="<?= $logId ?>">
            <input type="hidden" name="room_id"     value="<?= $row['room_id'] ?>">
            <input type="hidden" name="confirmation" value="no">
            <div class="d-grid">
              <button type="submit" class="btn btn-no py-2">NO</button>
            </div>
          </form>
        </div>
        <div class="text-center text-muted" style="font-size:.72rem;">Your RFID card works automatically too &mdash; a response here isn't required.</div>

        <?php elseif ($awaitingRfid): ?>
        <div class="alert alert-secondary py-2 mb-0 text-center small fw-semibold">UNCONFIRMED &mdash; Tap your RFID card now to Check In</div>

        <?php elseif ($promptExpired): ?>
        <div class="alert alert-secondary py-2 mb-0 text-center small fw-semibold">Attendance window closed</div>

        <?php else: ?>
        <div class="alert alert-light border py-2 mb-0 text-center small fw-semibold text-muted">
          Confirmation opens at <?= date('h:i A', strtotime($row['confirmation_start'])) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

</div>

  </div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('show');document.getElementById('sidebarOverlay').classList.toggle('show');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('show');document.getElementById('sidebarOverlay').classList.remove('show');}
function updateClock(){
  const now = new Date();
  document.getElementById('liveClock').textContent = now.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
setInterval(updateClock, 1000);
updateClock();

const scheduleWindows = <?= json_encode(array_map(function ($row) use ($todayDate) {
  return [
    'subject' => $row['subject'],
    'start' => $todayDate . 'T' . $row['time_start'],
    'confirmationStart' => $todayDate . 'T' . $row['confirmation_start'],
    'confirmation' => $row['confirmation'],
    'status' => $row['log_status'] ?? 'Unconfirmed',
  ];
}, $scheduleRows)) ?>;

function formatDuration(ms) {
  const total = Math.max(0, Math.floor(ms / 1000));
  const minutes = String(Math.floor(total / 60)).padStart(2, '0');
  const seconds = String(total % 60).padStart(2, '0');
  return `${minutes}:${seconds}`;
}

function updateConfirmationCountdown() {
  const value = document.getElementById('confirmationCountdown');
  const label = document.getElementById('confirmationCountdownLabel');
  if (!value || !label) return;

  const now = new Date();
  const next = scheduleWindows.find(item => item.confirmation === null && item.status === 'Unconfirmed' && new Date(item.start) > now);
  if (!next) {
    value.textContent = '--:--';
    label.textContent = 'No open confirmation window';
    return;
  }

  const opensAt = new Date(next.confirmationStart);
  const closesAt = new Date(next.start);
  if (now < opensAt) {
    value.textContent = formatDuration(opensAt - now);
    label.textContent = 'Confirmation starts in';
  } else if (now < closesAt) {
    value.textContent = formatDuration(closesAt - now);
    label.textContent = 'Confirmation window closes in';
  } else {
    value.textContent = 'Closed';
    label.textContent = 'Confirmation Window Closed';
  }
}
setInterval(updateConfirmationCountdown, 1000);
updateConfirmationCountdown();
</script>
</body></html>
