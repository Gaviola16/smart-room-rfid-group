<?php
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

header('Content-Type: application/json');

runScheduleAutomation($conn);

$hasUpdatedAt = false;
$colCheck = $conn->query("SHOW COLUMNS FROM rooms LIKE 'updated_at'");
if ($colCheck && $colCheck->num_rows > 0) $hasUpdatedAt = true;
$updatedAtSelect = $hasUpdatedAt ? "r.updated_at" : "NULL AS updated_at";

$sql = "
    SELECT r.id, r.room_code, r.room_name, r.building, r.floor, r.capacity, r.status,
           {$updatedAtSelect},
           u.name AS faculty_name, u.title AS faculty_title,
           s.subject, s.section, s.time_start, s.time_end,
           l.confirmation, l.checkin_at, l.checkout_at
    FROM rooms r
    LEFT JOIN (
        SELECT room_id,
               COALESCE(
                   MAX(CASE WHEN status = 'Occupied' AND checkout_at IS NULL THEN id END),
                   MAX(CASE WHEN status = 'Reserved' AND checkout_at IS NULL THEN id END),
                   MAX(CASE WHEN status = 'Unconfirmed' THEN id END),
                   MAX(CASE WHEN confirmation = 'yes' AND checkout_at IS NULL THEN id END),
                   MAX(id)
               ) AS log_id
        FROM room_logs
        WHERE log_date = CURDATE()
        GROUP BY room_id
    ) chosen ON chosen.room_id = r.id
    LEFT JOIN room_logs l   ON l.id = chosen.log_id
    LEFT JOIN schedules  s  ON s.id = l.schedule_id
    LEFT JOIN users      u  ON u.id = COALESCE(l.faculty_id, s.faculty_id)
    ORDER BY r.room_code ASC
";

$result = $conn->query($sql);
$rooms = [];
$counts = ['Available'=>0,'Scheduled'=>0,'Reserved'=>0,'Occupied'=>0,'Unconfirmed'=>0];
while ($row = $result->fetch_assoc()) {
    $rooms[] = $row;
    if (isset($counts[$row['status']])) $counts[$row['status']]++;
}

ob_start();
$colorMap = [
  'Available'   => ['success', 'check-circle-fill'],
  'Scheduled'   => ['primary',  'calendar3'],
  'Reserved'    => ['warning',  'bookmark-fill'],
  'Occupied'    => ['danger',   'person-fill-lock'],
  'Unconfirmed' => ['secondary','question-circle'],
];
foreach ($rooms as $room):
  [$color, $icon] = $colorMap[$room['status']] ?? ['secondary','question-circle'];
  $searchBlob = strtolower($room['room_code'].' '.$room['room_name'].' '.($room['building']??'').' '.($room['faculty_name']??''));
?>
<div class="col-12 col-sm-6 col-lg-4 col-xl-3 room-item"
     data-status="<?= htmlspecialchars($room['status']) ?>"
     data-search="<?= htmlspecialchars($searchBlob) ?>">
  <div class="card avail-card h-100 border-<?= $color ?> border-2">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
          <a href="room_timeline.php?id=<?= (int)$room['id'] ?>" class="room-code text-<?= $color ?> text-decoration-none">
            <?= htmlspecialchars($room['room_code']) ?>
          </a>
          <div class="room-name"><?= htmlspecialchars($room['room_name']) ?></div>
        </div>
        <span class="status-pill bg-<?= $color ?>-subtle text-<?= $color ?> border border-<?= $color ?>-subtle">
          <span class="status-dot bg-<?= $color ?>"></span><?= htmlspecialchars($room['status']) ?>
        </span>
      </div>
      <div class="meta-row mb-2">
        <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($room['building'] ?? 'N/A') ?>
        <?php if ($room['floor']): ?> &middot; <?= htmlspecialchars($room['floor']) ?><?php endif; ?>
        &nbsp;|&nbsp;<i class="bi bi-people me-1"></i><?= (int)$room['capacity'] ?> seats
      </div>
      <hr class="my-2">
      <?php if ($room['faculty_name']): ?>
      <div class="mb-1"><i class="bi bi-person-badge text-muted me-1"></i><span class="fw-semibold small"><?= htmlspecialchars(facultyDisplayName($room['faculty_title'] ?? null, $room['faculty_name'])) ?></span></div>
      <?php if ($room['subject']): ?>
      <div class="small text-muted mb-1"><i class="bi bi-journal-text me-1"></i><?= htmlspecialchars($room['subject']) ?><?= $room['section'] ? '('.htmlspecialchars($room['section']).')' : '' ?></div>
      <?php endif; ?>
      <?php if ($room['time_start']): ?>
      <div class="small text-muted mb-1"><i class="bi bi-clock me-1"></i><?= date('h:i A', strtotime($room['time_start'])) ?> - <?= date('h:i A', strtotime($room['time_end'])) ?></div>
      <?php endif; ?>
      <?php else: ?>
      <div class="small text-muted mb-1"><i class="bi bi-dash-circle me-1"></i>No faculty assigned</div>
      <?php endif; ?>
      <div class="small text-muted mt-2 pt-2 border-top">
        <i class="bi bi-arrow-repeat me-1"></i>Last update:
        <?= $room['updated_at'] ? date('h:i A', strtotime($room['updated_at'])) : date('h:i A') ?>
      </div>
      <div class="d-flex gap-2 mt-2">
        <a href="room_timeline.php?id=<?= (int)$room['id'] ?>" class="btn btn-sm btn-outline-secondary flex-fill">
          <i class="bi bi-clock-history me-1"></i>Timeline
        </a>
        <?php if ($room['status'] !== 'Available'): ?>
        <form method="POST" action="release_room.php" class="flex-fill">
          <input type="hidden" name="room_id" value="<?= (int)$room['id'] ?>">
          <button class="btn btn-sm btn-outline-<?= $color ?> w-100"
                  onclick="return confirm('Release <?= htmlspecialchars($room['room_code']) ?> to Available?')">
            <i class="bi bi-unlock me-1"></i>Release
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach;
$html = ob_get_clean();

echo json_encode([
    'counts' => $counts,
    'html' => $html,
    'refreshed_at' => date('h:i:s A'),
]);
