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
.sched-card { border-radius: 14px; border: none; transition: box-shadow .2s; }
.sched-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.12); }
.time-badge { font-size: 1rem; font-weight: 700; color: #0d1b2a; }
.room-chip  { background: #0d1b2a; color: #fff; border-radius: 8px; padding: 2px 10px; font-size: .8rem; font-weight: 600; }
.action-area { border-top: 1px solid #eee; padding-top: 1rem; margin-top: 1rem; }
.status-trail { font-size: .75rem; color: #888; }
.btn-yes  { background: #198754; color:#fff; border:none; font-weight:700; }
.btn-yes:hover  { background: #146c43; color:#fff; }
.btn-no   { background: #dc3545; color:#fff; border:none; font-weight:700; }
.btn-no:hover   { background: #b02a37; color:#fff; }
.btn-checkin  { background: #0d6efd; color:#fff; border:none; font-weight:700; }
.btn-checkin:hover { background: #0b5ed7; color:#fff; }
.btn-checkout { background: #6c757d; color:#fff; border:none; font-weight:700; }
.btn-checkout:hover { background: #565e64; color:#fff; }

.stat-mini { border-radius: 14px; }
.stat-mini .stat-icon { width: 46px; height: 46px; border-radius: 12px; display:flex; align-items:center; justify-content:center; font-size:1.25rem; }
.stat-mini .stat-num  { font-size: 1.6rem; font-weight: 800; line-height:1; }
.stat-mini .stat-lbl  { font-size: .75rem; color:#6c757d; }
.upcoming-card { border-radius: 14px; background: linear-gradient(135deg,#0d6efd 0%,#0a58ca 100%); color:#fff; }
.activity-item { font-size: .8rem; padding: .5rem 0; border-bottom: 1px solid #f0f0f0; }
.activity-item:last-child { border-bottom: none; }
.activity-icon { width: 32px; height: 32px; border-radius: 50%; display:flex; align-items:center; justify-content:center; font-size: .85rem; flex-shrink:0; }
.summary-bar { height: 10px; border-radius: 6px; overflow:hidden; background:#e9ecef; }
</style>

<div class="d-flex justify-content-between align-items-start mb-4">
  <div>
    <h5 class="fw-bold mb-1">
      <i class="bi bi-calendar-day text-primary me-2"></i>Today's Classes
    </h5>
    <div class="text-muted small">
      <i class="bi bi-calendar3 me-1"></i><?= date('l, F d, Y') ?> &nbsp;|&nbsp;
      <i class="bi bi-clock me-1"></i><span id="liveClock"></span>
    </div>
  </div>
  <span class="badge bg-primary fs-6"><?= $today ?></span>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-muted small fw-semibold mb-1"><i class="bi bi-play-circle me-1"></i>Current Subject</div>
        <div class="fw-bold fs-6"><?= $currentClass ? htmlspecialchars($currentClass['subject']) : 'No current class' ?></div>
        <div class="text-muted small"><?= $currentClass ? date('h:i A', strtotime($currentClass['time_start'])) . ' - ' . date('h:i A', strtotime($currentClass['time_end'])) : 'Nothing in progress' ?></div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-muted small fw-semibold mb-1"><i class="bi bi-door-open me-1"></i>Current Room</div>
        <div class="fw-bold fs-6"><?= $currentClass ? htmlspecialchars($currentClass['room_code']) : 'None' ?></div>
        <div class="text-muted small"><?= $currentClass ? htmlspecialchars($currentClass['room_name']) : 'No room currently assigned' ?></div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-muted small fw-semibold mb-1"><i class="bi bi-hourglass-split me-1"></i>Confirmation Window</div>
        <div class="fw-bold fs-6" id="confirmationCountdown">--:--</div>
        <div class="text-muted small" id="confirmationCountdownLabel">Checking schedule</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md">
    <div class="card stat-mini h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-calendar3"></i></div>
        <div>
          <div class="stat-num text-primary"><?= $totalToday ?></div>
          <div class="stat-lbl">Today's Classes</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md">
    <div class="card stat-mini h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-check-circle"></i></div>
        <div>
          <div class="stat-num text-success"><?= $confirmedCount ?></div>
          <div class="stat-lbl">Confirmed Classes</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md">
    <div class="card stat-mini h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-hourglass-split"></i></div>
        <div>
          <div class="stat-num text-warning"><?= $pendingCount ?></div>
          <div class="stat-lbl">Pending Classes</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md">
    <div class="card stat-mini h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-secondary-subtle text-secondary"><i class="bi bi-flag"></i></div>
        <div>
          <div class="stat-num text-secondary"><?= $completedCount ?></div>
          <div class="stat-lbl">Completed Classes</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md">
    <div class="card stat-mini h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-dark-subtle text-dark"><i class="bi bi-exclamation-triangle"></i></div>
        <div>
          <div class="stat-num text-dark"><?= $missedCount ?></div>
          <div class="stat-lbl">Missed Confirmations</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md">
    <div class="card stat-mini h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-person-fill-x"></i></div>
        <div>
          <div class="stat-num text-danger"><?= $noShowCount ?></div>
          <div class="stat-lbl">No Shows</div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">

  <div class="col-lg-4">
    <?php if ($upcoming): ?>
    <div class="card upcoming-card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <span class="badge bg-light text-primary fw-semibold"><i class="bi bi-arrow-up-right-circle me-1"></i>Up Next</span>
          <span class="badge bg-white text-dark"><?= htmlspecialchars($upcoming['room_code']) ?></span>
        </div>
        <div class="fw-bold fs-5 mb-1"><?= htmlspecialchars($upcoming['subject']) ?></div>
        <?php if ($upcoming['section']): ?>
        <div class="small mb-2 opacity-75"><i class="bi bi-people me-1"></i><?= htmlspecialchars($upcoming['section']) ?></div>
        <?php endif; ?>
        <div class="fs-6 fw-semibold">
          <i class="bi bi-clock me-1"></i>
          <?= date('h:i A', strtotime($upcoming['time_start'])) ?> – <?= date('h:i A', strtotime($upcoming['time_end'])) ?>
        </div>
        <div class="small mt-2 opacity-75">
          <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($upcoming['room_name']) ?>
        </div>
      </div>
    </div>
    <?php else: ?>
    <div class="card h-100">
      <div class="card-body d-flex flex-column align-items-center justify-content-center text-center text-muted py-5">
        <i class="bi bi-cup-hot fs-2 mb-2"></i>
        <div class="small">No more upcoming classes today.</div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="fw-semibold mb-3"><i class="bi bi-pie-chart me-2 text-primary"></i>Today's Summary</div>
        <?php
          $pctConfirmed = $totalToday > 0 ? round(($confirmedCount / $totalToday) * 100) : 0;
          $pctCompleted = $totalToday > 0 ? round(($completedCount / $totalToday) * 100) : 0;
          $pctPending   = $totalToday > 0 ? round(($pendingCount   / $totalToday) * 100) : 0;
          $pctMissed    = $totalToday > 0 ? round(($missedCount    / $totalToday) * 100) : 0;
          $pctNoShow    = $totalToday > 0 ? round(($noShowCount    / $totalToday) * 100) : 0;
        ?>
        <div class="mb-2">
          <div class="d-flex justify-content-between small mb-1">
            <span>Confirmed</span><span class="fw-semibold"><?= $confirmedCount ?>/<?= $totalToday ?></span>
          </div>
          <div class="summary-bar"><div class="bg-success h-100" style="width:<?= $pctConfirmed ?>%"></div></div>
        </div>
        <div class="mb-2">
          <div class="d-flex justify-content-between small mb-1">
            <span>Completed</span><span class="fw-semibold"><?= $completedCount ?>/<?= $totalToday ?></span>
          </div>
          <div class="summary-bar"><div class="bg-secondary h-100" style="width:<?= $pctCompleted ?>%"></div></div>
        </div>
        <div class="mb-1">
          <div class="d-flex justify-content-between small mb-1">
            <span>Pending Response</span><span class="fw-semibold"><?= $pendingCount ?>/<?= $totalToday ?></span>
          </div>
          <div class="summary-bar"><div class="bg-warning h-100" style="width:<?= $pctPending ?>%"></div></div>
        </div>
        <div class="mb-1">
          <div class="d-flex justify-content-between small mb-1">
            <span>Missed Confirmation</span><span class="fw-semibold"><?= $missedCount ?>/<?= $totalToday ?></span>
          </div>
          <div class="summary-bar"><div class="bg-dark h-100" style="width:<?= $pctMissed ?>%"></div></div>
        </div>
        <div class="mb-1">
          <div class="d-flex justify-content-between small mb-1">
            <span>No Show</span><span class="fw-semibold"><?= $noShowCount ?>/<?= $totalToday ?></span>
          </div>
          <div class="summary-bar"><div class="bg-danger h-100" style="width:<?= $pctNoShow ?>%"></div></div>
        </div>
        <?php if ($totalToday === 0): ?>
        <div class="text-muted small text-center mt-3">No classes scheduled today.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="fw-semibold mb-2"><i class="bi bi-activity me-2 text-primary"></i>Recent Activity</div>
        <?php if (empty($recentActivity)): ?>
        <div class="text-muted small text-center py-4">
          <i class="bi bi-inbox fs-4 d-block mb-1"></i>No recent activity yet.
        </div>
        <?php else: ?>
        <?php foreach ($recentActivity as $act):
          [$icon, $color] = activityIcon($act['action_type']);
        ?>
        <div class="activity-item d-flex align-items-start gap-2">
          <div class="activity-icon bg-<?= $color ?>-subtle text-<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
          <div class="flex-grow-1">
            <div class="text-dark"><?= htmlspecialchars($act['description'] ?? ucfirst(str_replace('_',' ',$act['action_type']))) ?></div>
            <div class="text-muted" style="font-size:.7rem;"><?= date('M d, h:i A', strtotime($act['created_at'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php if (empty($scheduleRows)): ?>
<div class="card text-center py-5">
  <div class="card-body">
    <i class="bi bi-calendar-x text-muted" style="font-size:3rem;"></i>
    <h5 class="mt-3 text-muted">No Classes Today</h5>
    <p class="text-muted small">You have no scheduled classes for <?= $today ?>.</p>
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
          <div class="text-muted small"><i class="bi bi-people me-1"></i><?= htmlspecialchars($row['section']) ?></div>
          <?php endif; ?>
        </div>
        <?= statusBadge($logStatus) ?>
      </div>

      <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="room-chip"><i class="bi bi-door-open me-1"></i><?= htmlspecialchars($row['room_code']) ?></span>
        <span class="badge bg-light text-dark border">
          <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($row['building'] ?? 'N/A') ?>
        </span>
        <span class="badge bg-light text-dark border">
          <i class="bi bi-people me-1"></i><?= $row['capacity'] ?> seats
        </span>
      </div>
      <div class="time-badge mb-3">
        <i class="bi bi-clock text-primary me-1"></i>
        <?= date('h:i A', strtotime($row['time_start'])) ?> &mdash; <?= date('h:i A', strtotime($row['time_end'])) ?>
      </div>

      <div class="status-trail mb-1">
        <?php if ($row['confirmed_at']): ?>
        <i class="bi bi-check2-circle text-<?= $confirm==='yes'?'success':'danger' ?> me-1"></i>
        <?= $confirm==='yes' ? 'Confirmed YES' : 'Declined' ?> at <?= date('h:i A', strtotime($row['confirmed_at'])) ?>
        <?php endif; ?>
        <?php if ($hasCheckIn): ?>
        <br><i class="bi bi-box-arrow-in-right text-primary me-1"></i>Checked in at <?= date('h:i A', strtotime($row['checkin_at'])) ?>
        <?php endif; ?>
        <?php if ($hasCheckOut): ?>
        <br><i class="bi bi-box-arrow-right text-secondary me-1"></i>Checked out at <?= date('h:i A', strtotime($row['checkout_at'])) ?>
        <?php endif; ?>
      </div>

      <div class="action-area">

        <?php if ($hasCheckOut): ?>
        <div class="alert alert-success py-2 mb-0 text-center small fw-semibold">
          <i class="bi bi-check-circle-fill me-1"></i>Session Complete
        </div>

        <?php elseif ($hasCheckIn): ?>
        <div class="alert alert-primary py-2 mb-0 text-center small fw-semibold">
          <i class="bi bi-wifi me-1"></i>Occupied &mdash; Tap your RFID card again to Check Out
        </div>

        <?php elseif ($confirm === 'no'): ?>
        <div class="alert alert-warning py-2 mb-0 text-center small fw-semibold">
          <i class="bi bi-x-circle me-1"></i>You declined this class &mdash; RFID attendance is disabled for it today
        </div>

        <?php elseif ($logStatus === 'No Show'): ?>
        <div class="alert alert-danger py-2 mb-0 text-center small fw-semibold">
          <i class="bi bi-exclamation-triangle me-1"></i>No Show
        </div>

        <?php elseif ($logStatus === 'Missed Confirmation'): ?>
        <div class="alert alert-dark py-2 mb-0 text-center small fw-semibold">
          <i class="bi bi-clock-history me-1"></i>Attendance window closed &mdash; room released
        </div>

        <?php elseif ($canConfirm): ?>
        <p class="small text-muted mb-2 fw-semibold">Will you conduct this class? <span class="fw-normal">(optional)</span></p>
        <div class="d-flex gap-2 mb-2">
          <form method="POST" action="confirm.php" class="flex-fill no-double">
            <input type="hidden" name="log_id"      value="<?= $logId ?>">
            <input type="hidden" name="room_id"     value="<?= $row['room_id'] ?>">
            <input type="hidden" name="confirmation" value="yes">
            <div class="d-grid">
              <button type="submit" class="btn btn-yes rounded-3 py-2">
                <i class="bi bi-check-circle me-1"></i>YES
              </button>
            </div>
          </form>
          <form method="POST" action="confirm.php" class="flex-fill no-double">
            <input type="hidden" name="log_id"      value="<?= $logId ?>">
            <input type="hidden" name="room_id"     value="<?= $row['room_id'] ?>">
            <input type="hidden" name="confirmation" value="no">
            <div class="d-grid">
              <button type="submit" class="btn btn-no rounded-3 py-2">
                <i class="bi bi-x-circle me-1"></i>NO
              </button>
            </div>
          </form>
        </div>
        <div class="text-center text-muted" style="font-size:.72rem;">
          <i class="bi bi-wifi me-1"></i>Your RFID card works automatically too &mdash; a response here isn't required.
        </div>

        <?php elseif ($awaitingRfid): ?>
        <div class="alert alert-secondary py-2 mb-0 text-center small fw-semibold">
          <i class="bi bi-wifi me-1"></i>UNCONFIRMED &mdash; Tap your RFID card now to Check In
        </div>

        <?php elseif ($promptExpired): ?>
        <div class="alert alert-secondary py-2 mb-0 text-center small fw-semibold">
          <i class="bi bi-clock-history me-1"></i>Attendance window closed
        </div>

        <?php else: ?>
        <div class="alert alert-light border py-2 mb-0 text-center small fw-semibold text-muted">
          <i class="bi bi-hourglass-split me-1"></i>
          Confirmation opens at <?= date('h:i A', strtotime($row['confirmation_start'])) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
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
