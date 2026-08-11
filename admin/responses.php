<?php
require_once __DIR__ . '/../db.php';
session_start(); requireLogin('admin');
$pageTitle = 'Faculty Responses';

runScheduleAutomation($conn);

$filter    = $_GET['filter'] ?? 'all';  
$dateFrom  = $_GET['date_from'] ?? date('Y-m-d');
$dateTo    = $_GET['date_to']   ?? date('Y-m-d');

$where = "WHERE l.log_date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];
$types  = 'ss';

if ($filter === 'yes')     { $where .= " AND l.confirmation='yes'"; }
elseif ($filter === 'no')  { $where .= " AND l.confirmation='no'"; }
elseif ($filter === 'pending') { $where .= " AND l.confirmation IS NULL AND l.status='Unconfirmed'"; }
elseif ($filter === 'missed') { $where .= " AND l.status='Missed Confirmation'"; }
elseif ($filter === 'no_show') { $where .= " AND l.status='No Show'"; }

$stmt = $conn->prepare("
    SELECT l.*, u.name AS faculty_name, u.title AS faculty_title, u.department,
           r.room_code, r.room_name,
           s.subject, s.section, s.time_start, s.time_end
    FROM room_logs l
    JOIN users u    ON l.faculty_id  = u.id
    JOIN rooms r    ON l.room_id     = r.id
    JOIN schedules s ON l.schedule_id = s.id
    $where
    ORDER BY l.log_date DESC, s.time_start ASC
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$logs = $stmt->get_result();
$stmt->close();

$counts = [];
foreach (['all','yes','no','pending','missed','no_show'] as $f) {
    $w2 = "WHERE l.log_date BETWEEN ? AND ?";
    if ($f==='yes') $w2 .= " AND l.confirmation='yes'";
    elseif ($f==='no') $w2 .= " AND l.confirmation='no'";
    elseif ($f==='pending') $w2 .= " AND l.confirmation IS NULL AND l.status='Unconfirmed'";
    elseif ($f==='missed') $w2 .= " AND l.status='Missed Confirmation'";
    elseif ($f==='no_show') $w2 .= " AND l.status='No Show'";
    $s2 = $conn->prepare("SELECT COUNT(*) FROM room_logs l JOIN schedules s ON l.schedule_id=s.id $w2");
    $s2->bind_param('ss',$dateFrom,$dateTo); $s2->execute();
    $counts[$f] = $s2->get_result()->fetch_row()[0]; $s2->close();
}

include __DIR__ . '/../authentication.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold"><i class="bi bi-chat-square-check me-2 text-primary"></i>Faculty Responses</h5>
    <div class="text-muted small">Monitor YES / NO / Pending confirmations</div>
  </div>
</div>

<!-- Date Filter -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="d-flex gap-2 flex-wrap align-items-end">
      <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
      <div>
        <label class="form-label small fw-semibold mb-1">From</label>
        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= $dateFrom ?>">
      </div>
      <div>
        <label class="form-label small fw-semibold mb-1">To</label>
        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= $dateTo ?>">
      </div>
      <button class="btn btn-sm btn-primary mt-auto"><i class="bi bi-funnel me-1"></i>Filter</button>
      <a href="responses.php" class="btn btn-sm btn-outline-secondary mt-auto">Reset</a>
    </form>
  </div>
</div>

<ul class="nav nav-tabs mb-3">
  <?php
  $tabs = [
    'all'     => ['All', 'secondary'],
    'yes'     => ['YES', 'success'],
    'no'      => ['NO', 'danger'],
    'pending' => ['Pending', 'warning'],
    'missed'  => ['Missed Confirmation', 'dark'],
    'no_show' => ['No Show', 'danger'],
  ];
  foreach ($tabs as $key => [$label, $color]):
  ?>
  <li class="nav-item">
    <a class="nav-link <?= $filter===$key ? 'active fw-semibold':'' ?>"
       href="?filter=<?= $key ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>">
      <?= $label ?>
      <span class="badge bg-<?= $color ?> ms-1"><?= $counts[$key] ?></span>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-dark">
          <tr>
            <th>Date</th><th>Faculty</th><th>Subject / Section</th>
            <th>Room</th><th>Time</th><th>Response</th>
            <th>Confirmed At</th><th>Check-In</th><th>Check-Out</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($logs->num_rows === 0): ?>
        <tr><td colspan="9" class="text-center py-4 text-muted">No responses found for the selected range.</td></tr>
        <?php endif; ?>
        <?php while ($row = $logs->fetch_assoc()): ?>
        <tr>
          <td class="text-nowrap"><?= date('M d, Y', strtotime($row['log_date'])) ?></td>
          <td>
            <div class="fw-semibold"><?= htmlspecialchars(facultyDisplayName($row['faculty_title'] ?? null, $row['faculty_name'])) ?></div>
            <div class="text-muted small"><?= htmlspecialchars($row['department'] ?? '') ?></div>
          </td>
          <td>
            <div><?= htmlspecialchars($row['subject']) ?></div>
            <?php if ($row['section']): ?>
            <div class="text-muted small"><?= htmlspecialchars($row['section']) ?></div>
            <?php endif; ?>
          </td>
          <td><span class="badge bg-dark"><?= htmlspecialchars($row['room_code']) ?></span></td>
          <td class="text-nowrap small">
            <?= date('h:i A',strtotime($row['time_start'])) ?> –<br>
            <?= date('h:i A',strtotime($row['time_end'])) ?>
          </td>
          <td>
            <?php if ($row['confirmation'] === 'yes'): ?>
              <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>YES</span>
            <?php elseif ($row['confirmation'] === 'no'): ?>
              <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>NO</span>
            <?php elseif ($row['status'] === 'Missed Confirmation'): ?>
              <span class="badge bg-dark"><i class="bi bi-clock-history me-1"></i>Missed Confirmation</span>
            <?php elseif ($row['status'] === 'No Show'): ?>
              <span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>No Show</span>
            <?php else: ?>
              <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Pending</span>
            <?php endif; ?>
          </td>
          <td class="small text-muted"><?= $row['confirmed_at'] ? date('h:i A',strtotime($row['confirmed_at'])) : '—' ?></td>
          <td class="small <?= $row['checkin_at'] ? 'text-success' : 'text-muted' ?>">
            <?= $row['checkin_at'] ? date('h:i A',strtotime($row['checkin_at'])) : '—' ?>
          </td>
          <td class="small <?= $row['checkout_at'] ? 'text-secondary' : 'text-muted' ?>">
            <?= $row['checkout_at'] ? date('h:i A',strtotime($row['checkout_at'])) : '—' ?>
          </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
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
