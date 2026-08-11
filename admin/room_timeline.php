<?php
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

runScheduleAutomation($conn);

$roomId = (int)($_GET['id'] ?? 0);
$date = $_GET['date'] ?? date('Y-m-d');
if (!$roomId) {
    setFlash('danger', 'Invalid room.');
    redirect('/admin/availability.php');
}

$stmt = $conn->prepare("SELECT * FROM rooms WHERE id = ?");
$stmt->bind_param('i', $roomId);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$room) {
    setFlash('danger', 'Room not found.');
    redirect('/admin/availability.php');
}

$pageTitle = 'Room Timeline';

$logs = $conn->prepare("
    SELECT l.*, s.subject, s.section, s.time_start, s.time_end, u.name AS faculty_name, u.title AS faculty_title
    FROM room_logs l
    JOIN schedules s ON s.id = l.schedule_id
    JOIN users u ON u.id = l.faculty_id
    WHERE l.room_id = ? AND l.log_date = ?
    ORDER BY s.time_start ASC, l.id ASC
");
$logs->bind_param('is', $roomId, $date);
$logs->execute();
$result = $logs->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $row['faculty_name'] = facultyDisplayName($row['faculty_title'] ?? null, $row['faculty_name']);
    $rows[] = $row;
}
$logs->close();

$events = [];
if (empty($rows)) {
    $events[] = ['time' => '07:00:00', 'status' => 'Available', 'meta' => 'No room activity recorded.'];
} else {
    foreach ($rows as $row) {
        $events[] = [
            'time' => $row['time_start'],
            'status' => $row['status'] === 'Unconfirmed' ? 'Unconfirmed' : ($row['confirmation'] === 'yes' ? 'Reserved' : ($row['confirmation'] === 'no' ? 'Available' : $row['status'])),
            'meta' => trim($row['subject'] . ' ' . ($row['section'] ? '(' . $row['section'] . ')' : '') . ' - ' . $row['faculty_name']),
        ];
        if (!empty($row['checkin_at'])) {
            $events[] = ['time' => date('H:i:s', strtotime($row['checkin_at'])), 'status' => 'Occupied', 'meta' => $row['faculty_name'] . ' checked in.'];
        }
        if (!empty($row['checkout_at'])) {
            $events[] = ['time' => date('H:i:s', strtotime($row['checkout_at'])), 'status' => 'Available', 'meta' => $row['faculty_name'] . ' checked out.'];
        } elseif (in_array($row['status'], ['Missed Confirmation', 'No Show', 'Available'], true)) {
            $events[] = ['time' => $row['time_start'], 'status' => $row['status'], 'meta' => $row['faculty_name'] . ' - ' . $row['status']];
        }
    }
}

usort($events, fn($a, $b) => strcmp($a['time'], $b['time']));

include __DIR__ . '/../authentication.php';
?>

<style>
.timeline { position:relative; padding-left:1.5rem; }
.timeline:before { content:""; position:absolute; left:.45rem; top:.25rem; bottom:.25rem; width:2px; background:#dee2e6; }
.timeline-item { position:relative; padding:0 0 1rem 1rem; }
.timeline-dot { position:absolute; left:-1.32rem; top:.2rem; width:.85rem; height:.85rem; border-radius:50%; border:2px solid #fff; box-shadow:0 0 0 2px #dee2e6; }
</style>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-1"><i class="bi bi-clock-history text-primary me-2"></i><?= htmlspecialchars($room['room_code']) ?> Timeline</h5>
    <div class="text-muted small"><?= htmlspecialchars($room['room_name']) ?> &middot; <?= date('F d, Y', strtotime($date)) ?></div>
  </div>
  <div class="d-flex gap-2">
    <a href="availability.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Availability</a>
    <a href="rooms.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-door-open me-1"></i>Rooms</a>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="d-flex gap-2 align-items-end flex-wrap">
      <input type="hidden" name="id" value="<?= $roomId ?>">
      <div>
        <label class="form-label small fw-semibold mb-1">Date</label>
        <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($date) ?>">
      </div>
      <button class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>View</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="timeline">
      <?php foreach ($events as $event):
        $color = [
          'Available' => 'success',
          'Reserved' => 'warning',
          'Occupied' => 'danger',
          'Unconfirmed' => 'secondary',
          'Missed Confirmation' => 'dark',
          'No Show' => 'danger',
          'Scheduled' => 'primary',
        ][$event['status']] ?? 'secondary';
      ?>
      <div class="timeline-item">
        <span class="timeline-dot bg-<?= $color ?>"></span>
        <div class="d-flex justify-content-between flex-wrap gap-2">
          <div>
            <div class="fw-bold"><?= date('h:i A', strtotime($event['time'])) ?></div>
            <div><?= statusBadge($event['status']) ?></div>
          </div>
          <div class="text-muted small text-md-end"><?= htmlspecialchars($event['meta']) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

  </div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('show');document.getElementById('sidebarOverlay').classList.toggle('show');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('show');document.getElementById('sidebarOverlay').classList.remove('show');}
</script>
</body></html>
