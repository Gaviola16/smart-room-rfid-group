<?php

require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

$pageTitle = 'Admin Dashboard';
$today = getTodayName();

runScheduleAutomation($conn);

$totalRooms     = $conn->query("SELECT COUNT(*) FROM rooms")->fetch_row()[0];
$availableRooms = $conn->query("SELECT COUNT(*) FROM rooms WHERE status='Available'")->fetch_row()[0];
$occupiedRooms  = $conn->query("SELECT COUNT(*) FROM rooms WHERE status='Occupied'")->fetch_row()[0];
$reservedRooms  = $conn->query("SELECT COUNT(*) FROM rooms WHERE status='Reserved'")->fetch_row()[0];
$unconfirmedRooms = $conn->query("SELECT COUNT(*) FROM rooms WHERE status='Unconfirmed'")->fetch_row()[0];
$totalFaculty   = $conn->query("SELECT COUNT(*) FROM users WHERE role='faculty'")->fetch_row()[0];
$pendingFace = columnExists($conn, 'users', 'account_status')
    ? $conn->query("SELECT COUNT(*) FROM users WHERE role='faculty' AND account_status IN ('Pending Face Verification','Pending Face Registration')")->fetch_row()[0]
    : 0;
$missedConfirmations = $conn->query("SELECT COUNT(*) FROM room_logs WHERE log_date=CURDATE() AND status='Missed Confirmation'")->fetch_row()[0];
$noShowsToday = $conn->query("SELECT COUNT(*) FROM room_logs WHERE log_date=CURDATE() AND status='No Show'")->fetch_row()[0];
$facultyCheckedIn = $conn->query("SELECT COUNT(DISTINCT faculty_id) FROM room_logs WHERE log_date=CURDATE() AND checkin_at IS NOT NULL AND checkout_at IS NULL")->fetch_row()[0];
$facultyNotYetCheckedIn = $conn->query("
    SELECT COUNT(DISTINCT l.faculty_id)
    FROM room_logs l
    JOIN schedules s ON s.id = l.schedule_id
    WHERE l.log_date = CURDATE()
      AND l.confirmation = 'yes'
      AND l.status = 'Reserved'
      AND l.checkin_at IS NULL
      AND CONCAT(l.log_date, ' ', s.time_start) <= NOW()
")->fetch_row()[0];

$todaySchedules = $conn->query("SELECT COUNT(*) FROM schedules s
    WHERE s.day_of_week = '{$today}' AND s.is_active = 1
      AND CONCAT(CURDATE(), ' ', s.time_end) >= NOW()
      AND NOT EXISTS (
          SELECT 1
          FROM room_logs done
          WHERE done.schedule_id = s.id
            AND done.log_date = CURDATE()
            AND done.checkout_at IS NOT NULL
      )")->fetch_row()[0];

$rooms = $conn->query("SELECT * FROM rooms ORDER BY room_code ASC");

$feed  = $conn->query("
    SELECT s.*, u.name AS faculty_name, u.title AS faculty_title, r.room_code, r.room_name,
           l.confirmation, l.status AS log_status, l.checkin_at, l.checkout_at
    FROM schedules s
    JOIN users u ON s.faculty_id = u.id
    JOIN rooms r ON s.room_id = r.id
    LEFT JOIN (
        SELECT schedule_id,
               COALESCE(
                   MAX(CASE WHEN status = 'Occupied' AND checkout_at IS NULL THEN id END),
                   MAX(CASE WHEN status = 'Reserved' AND checkout_at IS NULL THEN id END),
                   MAX(CASE WHEN confirmation = 'yes' AND checkout_at IS NULL THEN id END),
                   MAX(CASE WHEN confirmation = 'no' THEN id END),
                   MAX(id)
               ) AS log_id
        FROM room_logs
        WHERE log_date = CURDATE()
        GROUP BY schedule_id
    ) chosen_log ON chosen_log.schedule_id = s.id
    LEFT JOIN room_logs l ON l.id = chosen_log.log_id
    WHERE s.day_of_week = '$today' AND s.is_active = 1
      AND CONCAT(CURDATE(), ' ', s.time_end) >= NOW()
      AND NOT EXISTS (
          SELECT 1
          FROM room_logs done
          WHERE done.schedule_id = s.id
            AND done.log_date = CURDATE()
            AND done.checkout_at IS NOT NULL
      )
    ORDER BY s.time_start ASC
");

include __DIR__ . '/../authentication.php';
?>

<style>
/* Scoped to this page only — does not affect shared layout or other pages */
.dashboard-page .card {
  box-shadow: 0 1px 2px rgba(0,0,0,0.06);
  border-radius: 8px;
  border: 1px solid #e3e6ea;
}
.dashboard-page .card-header {
  border-radius: 7px 7px 0 0 !important;
}
.dashboard-page .stat-card {
  border-radius: 8px;
  padding: 1.1rem;
}
.dashboard-page .stat-card .stat-icon {
  width: 38px;
  height: 38px;
  border-radius: 7px;
  font-size: 1.05rem;
}
.dashboard-page .stat-card .stat-value {
  font-size: 1.5rem;
}
.dashboard-page .room-tile {
  border-radius: 6px;
}
</style>

<div class="dashboard-page">

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card card h-100">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="stat-label text-muted" style="font-size:.85rem;">Total Rooms</span>
          <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-door-open"></i></div>
        </div>
        <div class="stat-value text-primary"><?= $totalRooms ?></div>
        <div class="stat-label">Rooms in system</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card card h-100">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="stat-label text-muted" style="font-size:.85rem;">Available</span>
          <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-check-circle"></i></div>
        </div>
        <div class="stat-value text-success"><?= $availableRooms ?></div>
        <div class="stat-label">Ready to use</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card card h-100">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="stat-label text-muted" style="font-size:.85rem;">Occupied</span>
          <div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-person-fill-lock"></i></div>
        </div>
        <div class="stat-value text-danger"><?= $occupiedRooms ?></div>
        <div class="stat-label">In use now</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card card h-100">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="stat-label text-muted" style="font-size:.85rem;">Reserved</span>
          <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-bookmark-fill"></i></div>
        </div>
        <div class="stat-value text-warning"><?= $reservedRooms ?></div>
        <div class="stat-label">Confirmed today</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <?php
  $extraCards = [
    ['Pending Face Verification', 'secondary', 'bi-person-bounding-box', $pendingFace],
    ['Missed Confirmations', 'danger', 'bi-exclamation-triangle', $missedConfirmations],
    ['No Shows Today', 'danger', 'bi-person-fill-x', $noShowsToday],
    ['Unconfirmed Rooms', 'secondary', 'bi-question-circle', $unconfirmedRooms],
    ['Faculty Checked In', 'primary', 'bi-person-check', $facultyCheckedIn],
    ['Faculty Not Yet Checked In', 'secondary', 'bi-person-dash', $facultyNotYetCheckedIn],
  ];
  foreach ($extraCards as [$label, $color, $icon, $value]):
  ?>
  <div class="col-6 col-md-3">
    <div class="stat-card card h-100">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="stat-label text-muted" style="font-size:.85rem;"><?= $label ?></span>
          <div class="stat-icon bg-<?= $color ?>-subtle text-<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
        </div>
        <div class="stat-value text-<?= $color ?>"><?= $value ?></div>
        <div class="stat-label">Today</div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
 
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header bg-dark text-white d-flex align-items-center justify-content-between">
        <span><i class="bi bi-grid-3x3-gap me-2"></i>Live Room Status</span>
        <span class="badge bg-secondary"><?= date('h:i A') ?></span>
      </div>
      <div class="card-body">
        <div class="row g-2">
          <?php while ($room = $rooms->fetch_assoc()): ?>
          <?php
            $bgMap = [
              'Available'   => 'success',
              'Scheduled'   => 'primary',
              'Reserved'    => 'warning',
              'Occupied'    => 'danger',
              'Unconfirmed' => 'secondary',
              'Missed Confirmation' => 'dark',
              'No Show' => 'danger',
            ];
            $bg = $bgMap[$room['status']] ?? 'secondary';
          ?>
          <div class="col-6 col-md-4">
            <div class="card room-tile border-0 bg-<?= $bg ?>-subtle border border-<?= $bg ?>-subtle h-100">
              <div class="card-body p-2 text-center">
                <div class="fw-bold text-<?= $bg ?>" style="font-size:.95rem;"><?= htmlspecialchars($room['room_code']) ?></div>
                <div class="small text-muted" style="font-size:.72rem;"><?= htmlspecialchars($room['room_name']) ?></div>
                <div class="mt-1"><?= statusBadge($room['status']) ?></div>
                <?php if ($room['status'] === 'Available'): ?>
                <form method="POST" action="release_room.php" class="mt-1">
                  <input type="hidden" name="room_id" value="<?= $room['id'] ?>">
                  <!-- Already available, no release needed -->
                </form>
                <?php elseif (in_array($room['status'], ['Reserved','Occupied','Unconfirmed'])): ?>
                <form method="POST" action="release_room.php" class="mt-1">
                  <input type="hidden" name="room_id" value="<?= $room['id'] ?>">
                  <button class="btn btn-sm btn-outline-<?= $bg ?> py-0 px-2" style="font-size:.7rem;"
                    onclick="return confirm('Release this room to Available?')">
                    <i class="bi bi-unlock"></i> Release
                  </button>
                </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endwhile; ?>
        </div>
      </div>
    </div>
  </div>

  
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header bg-primary text-white">
        <i class="bi bi-calendar-day me-2"></i>Today's Schedule — <?= $today ?>
        <span class="badge bg-white text-primary ms-2"><?= $todaySchedules ?></span>
      </div>
      <div class="card-body p-0" style="max-height:420px;overflow-y:auto;">
        <?php if ($feed->num_rows === 0): ?>
        <div class="text-center text-muted py-4">
          <i class="bi bi-calendar-x fs-2 d-block mb-2"></i>No schedules for today.
        </div>
        <?php endif; ?>
        <ul class="list-group list-group-flush">
        <?php while ($row = $feed->fetch_assoc()):
          $status = $row['log_status'] ?? 'Unconfirmed';
          $badge  = statusBadge($status);
        ?>
        <li class="list-group-item px-3 py-2">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="fw-semibold" style="font-size:.88rem;"><?= htmlspecialchars($row['subject']) ?></div>
              <div class="text-muted" style="font-size:.75rem;">
                <i class="bi bi-person me-1"></i><?= htmlspecialchars(facultyDisplayName($row['faculty_title'] ?? null, $row['faculty_name'])) ?>
              </div>
              <div class="text-muted" style="font-size:.75rem;">
                <i class="bi bi-door-open me-1"></i><?= htmlspecialchars($row['room_code']) ?>
                &nbsp;|&nbsp;
                <i class="bi bi-clock me-1"></i><?= date('h:i A', strtotime($row['time_start'])) ?> – <?= date('h:i A', strtotime($row['time_end'])) ?>
              </div>
            </div>
            <div class="ms-2"><?= $badge ?></div>
          </div>
          <?php if ($row['checkin_at']): ?>
          <div class="mt-1 small text-success"><i class="bi bi-box-arrow-in-right me-1"></i>In: <?= date('h:i A', strtotime($row['checkin_at'])) ?></div>
          <?php endif; ?>
          <?php if ($row['checkout_at']): ?>
          <div class="mt-0 small text-secondary"><i class="bi bi-box-arrow-right me-1"></i>Out: <?= date('h:i A', strtotime($row['checkout_at'])) ?></div>
          <?php endif; ?>
        </li>
        <?php endwhile; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

</div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('show');
  document.getElementById('sidebarOverlay').classList.toggle('show');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('show');
  document.getElementById('sidebarOverlay').classList.remove('show');
}

</script>
</body>
</html>
