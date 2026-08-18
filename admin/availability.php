<?php
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

$pageTitle = 'Room Availability Board';
$today     = getTodayName();

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
$rooms = $conn->query($sql);
$roomList = [];
while ($row = $rooms->fetch_assoc()) $roomList[] = $row;

// Summary counts
$counts = ['Available'=>0,'Scheduled'=>0,'Reserved'=>0,'Occupied'=>0,'Unconfirmed'=>0,'Missed Confirmation'=>0,'No Show'=>0];
foreach ($roomList as $r) {
    if (isset($counts[$r['status']])) $counts[$r['status']]++;
}

include __DIR__ . '/../authentication.php';
?>

<style>
/* Scoped to this page only — flat, restrained style: no colored side
   strips, no shadows, color used only on the status pill itself. */
.avail-card { border: 1px solid #dfe3e8; border-radius: 6px; box-shadow: none; }
.avail-card .room-code { font-size: 1.05rem; font-weight: 700; color: #2b2f33; }
.avail-card .room-name { font-size: .8rem; color: #6c757d; }
.avail-card .meta-row  { font-size: .75rem; color: #6c757d; }
.status-pill {
  font-size: .72rem; font-weight: 600; padding: .25rem .55rem;
  border-radius: 4px; display: inline-flex; align-items: center;
}
.filter-chip {
  cursor: pointer; user-select: none; transition: background-color .15s;
  border: 1px solid #dee2e6; border-radius: 4px; padding: .35rem .85rem; font-size: .82rem; font-weight: 600;
  color: #495057;
}
.filter-chip.active { color: #fff; border-color: transparent; }
.filter-chip[data-status="all"].active        { background:#495057; }
.filter-chip[data-status="Available"].active   { background:#198754; }
.filter-chip[data-status="Scheduled"].active    { background:#0d6efd; }
.filter-chip[data-status="Reserved"].active     { background:#ffc107; color:#212529; }
.filter-chip[data-status="Occupied"].active     { background:#dc3545; }
.filter-chip[data-status="Unconfirmed"].active  { background:#6c757d; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-1">Room Availability Board</h5>
    <div class="text-muted small">Last refreshed: <span id="liveBadge"><?= date('h:i:s A') ?></span> &middot; auto-refreshes every 5s</div>
  </div>
</div>

<div class="row g-2 mb-3">
  <?php
    $summaryCards = [
      ['All',          'all',         'secondary', 'bi-grid-3x3-gap', array_sum($counts)],
      ['Available',    'Available',   'success',   'bi-check-circle', $counts['Available']],
      ['Scheduled',    'Scheduled',   'primary',   'bi-calendar3',    $counts['Scheduled']],
      ['Reserved',     'Reserved',    'warning',   'bi-bookmark-fill',$counts['Reserved']],
      ['Occupied',     'Occupied',    'danger',    'bi-person-fill-lock', $counts['Occupied']],
      ['Unconfirmed',  'Unconfirmed', 'secondary', 'bi-question-circle', $counts['Unconfirmed']],
    ];
    foreach ($summaryCards as [$label, $statusVal, $color, $icon, $count]):
  ?>
  <div class="col-6 col-md-2">
    <div class="card text-center h-100">
      <div class="card-body py-2">
        <div class="fw-bold text-<?= $color ?> fs-5" data-count-status="<?= htmlspecialchars($statusVal) ?>"><?= $count ?></div>
        <div class="text-muted" style="font-size:.72rem;"><?= $label ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card mb-3">
  <div class="card-body py-3">
    <div class="row g-2 align-items-center">
      <div class="col-md-5">
        <div class="input-group">
          <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
          <input type="text" id="searchBox" class="form-control border-start-0"
                 placeholder="Search room code, name, building, or faculty…">
        </div>
      </div>
      <div class="col-md-7">
        <div class="d-flex gap-2 flex-wrap" id="filterChips">
          <span class="filter-chip active" data-status="all">All</span>
          <span class="filter-chip" data-status="Available">Available</span>
          <span class="filter-chip" data-status="Scheduled">Scheduled</span>
          <span class="filter-chip" data-status="Reserved">Reserved</span>
          <span class="filter-chip" data-status="Occupied">Occupied</span>
          <span class="filter-chip" data-status="Unconfirmed">Unconfirmed</span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3" id="roomGrid">
<?php
$colorMap = [
  'Available'   => ['success', 'check-circle-fill'],
  'Scheduled'   => ['primary',  'calendar3'],
  'Reserved'    => ['warning',  'bookmark-fill'],
  'Occupied'    => ['danger',   'person-fill-lock'],
  'Unconfirmed' => ['secondary','question-circle'],
  'Missed Confirmation' => ['dark','exclamation-triangle'],
  'No Show' => ['danger','person-fill-x'],
];

foreach ($roomList as $room):
  [$color, $icon] = $colorMap[$room['status']] ?? ['secondary','question-circle'];
  $searchBlob = strtolower($room['room_code'].' '.$room['room_name'].' '.($room['building']??'').' '.($room['faculty_name']??''));
?>
<div class="col-12 col-sm-6 col-lg-4 col-xl-3 room-item"
     data-status="<?= htmlspecialchars($room['status']) ?>"
     data-search="<?= htmlspecialchars($searchBlob) ?>">
  <div class="card avail-card h-100">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
          <a href="room_timeline.php?id=<?= (int)$room['id'] ?>" class="room-code text-decoration-none"><?= htmlspecialchars($room['room_code']) ?></a>
          <div class="room-name"><?= htmlspecialchars($room['room_name']) ?></div>
        </div>
        <span class="status-pill bg-<?= $color ?>-subtle text-<?= $color ?> border border-<?= $color ?>-subtle">
          <?= htmlspecialchars($room['status']) ?>
        </span>
      </div>

      <div class="meta-row mb-2">
        <?= htmlspecialchars($room['building'] ?? 'N/A') ?>
        <?php if ($room['floor']): ?> · <?= htmlspecialchars($room['floor']) ?><?php endif; ?>
        &nbsp;|&nbsp; <?= $room['capacity'] ?> seats
      </div>

      <hr class="my-2">

      <?php if ($room['faculty_name']): ?>
      <div class="mb-1">
        <span class="fw-semibold small"><?= htmlspecialchars(facultyDisplayName($room['faculty_title'] ?? null, $room['faculty_name'])) ?></span>
      </div>
      <?php if ($room['subject']): ?>
      <div class="small text-muted mb-1">
        <?= htmlspecialchars($room['subject']) ?>
        <?= $room['section'] ? '('.htmlspecialchars($room['section']).')' : '' ?>
      </div>
      <?php endif; ?>
      <?php if ($room['time_start']): ?>
      <div class="small text-muted mb-1">
        <?= date('h:i A', strtotime($room['time_start'])) ?> – <?= date('h:i A', strtotime($room['time_end'])) ?>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <div class="small text-muted mb-1">No faculty assigned</div>
      <?php endif; ?>

      <div class="small text-muted mt-2 pt-2 border-top">
        Last update: <?= $room['updated_at'] ? date('h:i A', strtotime($room['updated_at'])) : '—' ?>
      </div>

      <div class="d-flex gap-2 mt-2">
        <a href="room_timeline.php?id=<?= (int)$room['id'] ?>" class="btn btn-sm btn-outline-secondary flex-fill">Timeline</a>
      <?php if ($room['status'] !== 'Available'): ?>
      <form method="POST" action="release_room.php" class="flex-fill">
        <input type="hidden" name="room_id" value="<?= $room['id'] ?>">
        <button class="btn btn-sm btn-outline-<?= $color ?> w-100"
                onclick="return confirm('Release <?= htmlspecialchars($room['room_code']) ?> to Available?')">Release Room</button>
      </form>
      <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<div id="noResults" class="text-center text-muted py-5 d-none">
  <i class="bi bi-search fs-1 d-block mb-2"></i>No rooms match your search or filter.
</div>

  </div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('show');document.getElementById('sidebarOverlay').classList.toggle('show');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('show');document.getElementById('sidebarOverlay').classList.remove('show');}

const searchBox  = document.getElementById('searchBox');
const chips      = document.querySelectorAll('.filter-chip');
let roomItems  = document.querySelectorAll('.room-item');
const noResults  = document.getElementById('noResults');
const roomGrid = document.getElementById('roomGrid');
let activeStatus = 'all';

function applyFilters() {
  const term = searchBox.value.trim().toLowerCase();
  let visibleCount = 0;

  roomItems.forEach(item => {
    const matchesStatus = (activeStatus === 'all') || (item.dataset.status === activeStatus);
    const matchesSearch = term === '' || item.dataset.search.includes(term);
    const show = matchesStatus && matchesSearch;
    item.classList.toggle('d-none', !show);
    if (show) visibleCount++;
  });

  noResults.classList.toggle('d-none', visibleCount > 0);
}

searchBox.addEventListener('input', applyFilters);

chips.forEach(chip => {
  chip.addEventListener('click', () => {
    chips.forEach(c => c.classList.remove('active'));
    chip.classList.add('active');
    activeStatus = chip.dataset.status;
    applyFilters();
  });
});

function refreshAvailability() {
  fetch('availability_data.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(response => response.json())
    .then(data => {
      roomGrid.innerHTML = data.html;
      roomItems = document.querySelectorAll('.room-item');
      document.getElementById('liveBadge').textContent = data.refreshed_at;
      Object.entries(data.counts).forEach(([status, count]) => {
        const target = document.querySelector(`[data-count-status="${status}"]`);
        if (target) target.textContent = count;
      });
      const allTarget = document.querySelector('[data-count-status="all"]');
      if (allTarget) {
        allTarget.textContent = Object.values(data.counts).reduce((sum, value) => sum + Number(value), 0);
      }
      applyFilters();
    })
    .catch(() => {});
}
setInterval(refreshAvailability, 5000);
</script>
</body></html>
