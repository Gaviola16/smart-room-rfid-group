<?php
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

$pageTitle = 'Manage Schedules';
$errors    = [];
$editSched = null;

$days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];


$facultyArr = [];
$res = $conn->query("SELECT id, name, title FROM users WHERE role='faculty' ORDER BY name");
while ($r = $res->fetch_assoc()) $facultyArr[$r['id']] = facultyDisplayName($r['title'] ?? null, $r['name']);

$roomArr = [];
$res = $conn->query("SELECT id, room_code, room_name FROM rooms ORDER BY room_code");
while ($r = $res->fetch_assoc()) $roomArr[$r['id']] = $r['room_code'] . ' — ' . $r['room_name'];

if (isset($_GET['delete'])) {
    $did = intval($_GET['delete']);
    $info = $conn->prepare("SELECT subject FROM schedules WHERE id = ?");
    $info->bind_param('i', $did);
    $info->execute();
    $deletedSchedule = $info->get_result()->fetch_assoc();
    $info->close();
    // Clean up dependent room_logs the same way room/faculty deletion already
    // does — otherwise those rows become orphaned (schedule_id pointing to a
    // deleted schedule) and silently disappear from every report that JOINs
    // schedules, even though the underlying data still exists in the table.
    $dl = $conn->prepare("DELETE FROM room_logs WHERE schedule_id = ?");
    if ($dl) { $dl->bind_param('i', $did); $dl->execute(); $dl->close(); }

    $ds  = $conn->prepare("DELETE FROM schedules WHERE id = ?");
    $ds->bind_param('i', $did);
    $ds->execute();
    $ds->close();
    logActivity($conn, 'DELETE_SCHEDULE', 'Deleted schedule: ' . ($deletedSchedule['subject'] ?? "ID {$did}"));
    setFlash('success', 'Schedule deleted.');
    redirect('/admin/schedules.php');
}


if (isset($_GET['toggle'])) {
    $tid = intval($_GET['toggle']);
    $ts  = $conn->prepare("UPDATE schedules SET is_active = !is_active WHERE id = ?");
    $ts->bind_param('i', $tid);
    $ts->execute();
    $ts->close();
    setFlash('success', 'Schedule status toggled.');
    redirect('/admin/schedules.php');
}

if (isset($_GET['edit'])) {
    $eid = intval($_GET['edit']);
    $es  = $conn->prepare("SELECT * FROM schedules WHERE id = ?");
    $es->bind_param('i', $eid);
    $es->execute();
    $editSched = $es->get_result()->fetch_assoc();
    $es->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId     = intval($_POST['edit_id']     ?? 0);
    $faculty_id = intval($_POST['faculty_id']  ?? 0);
    $room_id    = intval($_POST['room_id']     ?? 0);
    $subject    = trim($_POST['subject']       ?? '');
    $section    = trim($_POST['section']       ?? '');
    $day        = $_POST['day_of_week']        ?? '';
    $time_start = $_POST['time_start']         ?? '';
    $time_end   = $_POST['time_end']           ?? '';
    $semester   = trim($_POST['semester']      ?? '');
    $school_yr  = trim($_POST['school_year']   ?? '');

    if (!$faculty_id)                    $errors[] = 'Faculty is required.';
    if (!$room_id)                       $errors[] = 'Room is required.';
    if (empty($subject))                 $errors[] = 'Subject is required.';
    if (!in_array($day, $days))          $errors[] = 'Valid day is required.';
    if (empty($time_start) || empty($time_end)) $errors[] = 'Time start and end are required.';
    if ($time_start >= $time_end)        $errors[] = 'End time must be after start time.';

    if (empty($errors)) {
        $cf = $conn->prepare("
            SELECT id FROM schedules
            WHERE room_id = ? AND day_of_week = ? AND is_active = 1
              AND id != ?
              AND time_start < ? AND time_end > ?
        ");
        $cf->bind_param('isiss', $room_id, $day, $editId, $time_end, $time_start);
        $cf->execute();
        if ($cf->get_result()->num_rows > 0)
            $errors[] = 'Room is already scheduled during this time slot.';
        $cf->close();
    }

    if (empty($errors)) {
        $cf = $conn->prepare("
            SELECT id FROM schedules
            WHERE faculty_id = ? AND day_of_week = ? AND is_active = 1
              AND id != ?
              AND time_start < ? AND time_end > ?
        ");
        $cf->bind_param('isiss', $faculty_id, $day, $editId, $time_end, $time_start);
        $cf->execute();
        if ($cf->get_result()->num_rows > 0)
            $errors[] = 'Faculty is already assigned to another schedule during this time slot.';
        $cf->close();
    }

    if (empty($errors)) {
        if ($editId) {
            $stmt = $conn->prepare("
                UPDATE schedules
                SET faculty_id=?, room_id=?, subject=?, section=?,
                    day_of_week=?, time_start=?, time_end=?, semester=?, school_year=?
                WHERE id=?
            ");
            $stmt->bind_param('iisssssssi',
                $faculty_id, $room_id, $subject, $section,
                $day, $time_start, $time_end, $semester, $school_yr, $editId
            );
            $msg = 'Schedule updated successfully.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO schedules
                    (faculty_id, room_id, subject, section, day_of_week, time_start, time_end, semester, school_year)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('iisssssss',
                $faculty_id, $room_id, $subject, $section,
                $day, $time_start, $time_end, $semester, $school_yr
            );
            $msg = 'Schedule added successfully.';
        }
        $stmt->execute();
        $stmt->close();
        logActivity($conn, $editId ? 'EDIT_SCHEDULE' : 'CREATE_SCHEDULE', ($editId ? 'Updated' : 'Created') . " schedule: {$subject}");

       
        $todayName = getTodayName();
        $conn->query("
            UPDATE rooms r
            JOIN schedules s ON s.room_id = r.id
            SET r.status = 'Scheduled'
            WHERE s.day_of_week = '{$todayName}' AND s.is_active = 1
              AND r.status = 'Available'
        ");

        setFlash('success', $msg);
        redirect('/admin/schedules.php');
    } else {
      
        $editSched = $_POST;
        $editSched['id'] = $editId;
    }
}

// ── Schedule list ────────────────────────────────────────────
$schedulesRes = $conn->query("
    SELECT s.*, u.name AS faculty_name, u.title AS faculty_title, u.department,
           r.room_code, r.room_name
    FROM schedules s
    JOIN users u ON s.faculty_id = u.id
    JOIN rooms r ON s.room_id   = r.id
    ORDER BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
             s.time_start ASC
");
// Pulled into a plain array (instead of iterating the mysqli result once)
// so the same data can build the filter dropdown option lists below AND
// render the table further down.
$scheduleRows = $schedulesRes ? $schedulesRes->fetch_all(MYSQLI_ASSOC) : [];

// Distinct option lists for the filter bar, derived only from schedules
// that actually exist (so the dropdowns never show a room/faculty/subject
// with nothing to filter to).
$filterRooms     = [];
$filterFaculty   = [];
$filterSubjects  = [];
$filterYears     = [];
$filterSemesters = [];
foreach ($scheduleRows as $r) {
    $filterRooms[$r['room_id']]   = $r['room_code'] . ' — ' . $r['room_name'];
    $filterFaculty[$r['faculty_id']] = facultyDisplayName($r['faculty_title'] ?? null, $r['faculty_name']);
    if (trim((string)$r['subject']) !== '')      $filterSubjects[$r['subject']] = $r['subject'];
    if (!empty($r['school_year']))               $filterYears[$r['school_year']] = $r['school_year'];
    if (!empty($r['semester']))                  $filterSemesters[$r['semester']] = $r['semester'];
}
asort($filterRooms);
asort($filterFaculty);
ksort($filterSubjects);
ksort($filterYears);
ksort($filterSemesters);

include __DIR__ . '/../authentication.php';
?>


<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h5 class="mb-0 fw-bold"><i class="bi bi-calendar3 me-2 text-primary"></i>Schedule Management</h5>
    <div class="text-muted small">Create and manage faculty class schedules</div>
  </div>
  <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#schedForm">
    <i class="bi bi-plus-circle me-1"></i><?= $editSched ? 'Edit Schedule' : 'Add Schedule' ?>
  </button>
</div>

<div class="collapse <?= ($editSched || !empty($errors)) ? 'show' : '' ?> mb-4" id="schedForm">
  <div class="card border-primary">
    <div class="card-header bg-primary text-white fw-semibold">
      <i class="bi bi-calendar-plus me-2"></i><?= isset($editSched['id']) && $editSched['id'] ? 'Edit' : 'New' ?> Schedule
    </div>
    <div class="card-body">
      <?php if ($errors): ?>
      <div class="alert alert-danger py-2">
        <ul class="mb-0 ps-3">
          <?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?>
        </ul>
      </div>
      <?php endif; ?>
      <form method="POST" id="scheduleForm">
        <input type="hidden" name="edit_id" value="<?= intval($editSched['id'] ?? 0) ?>">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Faculty <span class="text-danger">*</span></label>
            <select name="faculty_id" class="form-select" required>
              <option value="">— Select Faculty —</option>
              <?php foreach ($facultyArr as $fid => $fname): ?>
              <option value="<?= $fid ?>" <?= ($editSched['faculty_id'] ?? '') == $fid ? 'selected' : '' ?>>
                <?= htmlspecialchars($fname) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Room <span class="text-danger">*</span></label>
            <select name="room_id" class="form-select" required>
              <option value="">— Select Room —</option>
              <?php foreach ($roomArr as $rid => $rname): ?>
              <option value="<?= $rid ?>" <?= ($editSched['room_id'] ?? '') == $rid ? 'selected' : '' ?>>
                <?= htmlspecialchars($rname) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Subject <span class="text-danger">*</span></label>
            <input type="text" name="subject" class="form-control"
                   value="<?= htmlspecialchars($editSched['subject'] ?? '') ?>" required
                   placeholder="e.g. Introduction to Programming">
          </div>
          <div class="col-md-2">
            <label class="form-label fw-semibold">Section</label>
            <input type="text" name="section" class="form-control"
                   value="<?= htmlspecialchars($editSched['section'] ?? '') ?>"
                   placeholder="e.g. CS-2A">
          </div>
          <div class="col-md-2">
            <label class="form-label fw-semibold">Day <span class="text-danger">*</span></label>
            <select name="day_of_week" class="form-select" required>
              <option value="">— Day —</option>
              <?php foreach ($days as $d): ?>
              <option value="<?= $d ?>" <?= ($editSched['day_of_week'] ?? '') === $d ? 'selected' : '' ?>><?= $d ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label fw-semibold">Time Start <span class="text-danger">*</span></label>
            <input type="time" name="time_start" class="form-control"
                   value="<?= htmlspecialchars($editSched['time_start'] ?? '') ?>" required>
          </div>
          <div class="col-md-2">
            <label class="form-label fw-semibold">Time End <span class="text-danger">*</span></label>
            <input type="time" name="time_end" class="form-control"
                   value="<?= htmlspecialchars($editSched['time_end'] ?? '') ?>" required>
          </div>
          <div class="col-md-2">
            <label class="form-label fw-semibold">Semester</label>
            <input type="text" name="semester" class="form-control"
                   value="<?= htmlspecialchars($editSched['semester'] ?? '') ?>" placeholder="e.g. 1st">
          </div>
          <div class="col-md-2">
            <label class="form-label fw-semibold">School Year</label>
            <input type="text" name="school_year" class="form-control"
                   value="<?= htmlspecialchars($editSched['school_year'] ?? '') ?>" placeholder="e.g. 2025-2026">
          </div>
        </div>
        <hr class="my-3">
        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-save me-1"></i>
            <?= isset($editSched['id']) && $editSched['id'] ? 'Update' : 'Save' ?> Schedule
          </button>
          <a href="schedules.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>


<div class="card mb-3">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">Search</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
          <input type="text" id="schedSearch" class="form-control"
                 placeholder="Faculty, subject, room, section…">
        </div>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Day</label>
        <select id="schedFilterDay" class="form-select form-select-sm">
          <option value="">All Days</option>
          <?php foreach ($days as $d): if ($d === 'Sunday') continue; ?>
          <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Room</label>
        <select id="schedFilterRoom" class="form-select form-select-sm">
          <option value="">All Rooms</option>
          <?php foreach ($filterRooms as $rid => $rname): ?>
          <option value="<?= $rid ?>"><?= htmlspecialchars($rname) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Faculty</label>
        <select id="schedFilterFaculty" class="form-select form-select-sm">
          <option value="">All Faculty</option>
          <?php foreach ($filterFaculty as $fid => $fname): ?>
          <option value="<?= $fid ?>"><?= htmlspecialchars($fname) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Subject</label>
        <select id="schedFilterSubject" class="form-select form-select-sm">
          <option value="">All Subjects</option>
          <?php foreach ($filterSubjects as $s): ?>
          <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-1 dropdown">
        <label class="form-label small fw-semibold mb-1 d-block">&nbsp;</label>
        <button class="btn btn-sm btn-outline-secondary dropdown-toggle w-100" type="button"
                data-bs-toggle="dropdown" aria-expanded="false" title="More filters">
          More
        </button>
        <div class="dropdown-menu dropdown-menu-end p-3" style="min-width:220px;">
          <label class="form-label small fw-semibold mb-1">School Year</label>
          <select id="schedFilterYear" class="form-select form-select-sm mb-2">
            <option value="">All School Years</option>
            <?php foreach ($filterYears as $y): ?>
            <option value="<?= htmlspecialchars($y) ?>"><?= htmlspecialchars($y) ?></option>
            <?php endforeach; ?>
          </select>
          <label class="form-label small fw-semibold mb-1">Semester</label>
          <select id="schedFilterSemester" class="form-select form-select-sm">
            <option value="">All Semesters</option>
            <?php foreach ($filterSemesters as $sem): ?>
            <option value="<?= htmlspecialchars($sem) ?>"><?= htmlspecialchars($sem) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
      <span class="text-muted small" id="schedCounter">
        Showing <strong><?= count($scheduleRows) ?></strong> of <strong><?= count($scheduleRows) ?></strong> schedules
      </span>
      <div id="schedActiveFilters" class="d-flex flex-wrap gap-1"></div>
      <button type="button" id="schedResetFilters" class="btn btn-sm btn-link text-decoration-none ms-auto d-none">
        <i class="bi bi-x-circle me-1"></i>Reset Filters
      </button>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-dark">
          <tr>
            <th>#</th>
            <th>Day</th>
            <th>Time</th>
            <th>Subject / Section</th>
            <th>Faculty</th>
            <th>Room</th>
            <th>Semester</th>
            <th>Active</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody id="schedTbody">
        <?php if (count($scheduleRows) === 0): ?>
        <tr id="schedEmptyRow"><td colspan="9" class="text-center py-4 text-muted">No schedules yet. Click <strong>Add Schedule</strong> to create one.</td></tr>
        <?php endif; ?>
        <?php $i = 1; foreach ($scheduleRows as $row):
          $isToday   = ($row['day_of_week'] === getTodayName());
          $facName   = facultyDisplayName($row['faculty_title'] ?? null, $row['faculty_name']);
          $searchBlob = strtolower($row['subject'] . ' ' . $row['section'] . ' ' . $facName . ' ' .
                                    $row['room_code'] . ' ' . $row['room_name']);
        ?>
        <tr class="<?= $isToday ? 'table-primary' : '' ?> sched-row"
            data-day="<?= htmlspecialchars($row['day_of_week']) ?>"
            data-room="<?= (int)$row['room_id'] ?>"
            data-faculty="<?= (int)$row['faculty_id'] ?>"
            data-subject="<?= htmlspecialchars($row['subject']) ?>"
            data-year="<?= htmlspecialchars($row['school_year'] ?? '') ?>"
            data-semester="<?= htmlspecialchars($row['semester'] ?? '') ?>"
            data-search="<?= htmlspecialchars($searchBlob) ?>">
          <td class="text-muted"><?= $i++ ?></td>
          <td>
            <span class="badge <?= $isToday ? 'bg-primary' : 'bg-secondary' ?>">
              <?= htmlspecialchars($row['day_of_week']) ?>
            </span>
          </td>
          <td class="text-nowrap">
            <i class="bi bi-clock text-muted me-1"></i>
            <?= date('h:i A', strtotime($row['time_start'])) ?> –
            <?= date('h:i A', strtotime($row['time_end'])) ?>
          </td>
          <td>
            <div class="fw-semibold"><?= htmlspecialchars($row['subject']) ?></div>
            <?php if ($row['section']): ?>
            <div class="text-muted small"><i class="bi bi-people me-1"></i><?= htmlspecialchars($row['section']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <div><?= htmlspecialchars($facName) ?></div>
            <div class="text-muted small"><?= htmlspecialchars($row['department'] ?? '') ?></div>
          </td>
          <td>
            <span class="badge bg-dark"><?= htmlspecialchars($row['room_code']) ?></span>
            <div class="text-muted small"><?= htmlspecialchars($row['room_name']) ?></div>
          </td>
          <td class="small text-muted">
            <?= htmlspecialchars($row['semester'] ?? '—') ?><br>
            <?= htmlspecialchars($row['school_year'] ?? '') ?>
          </td>
          <td>
            <a href="schedules.php?toggle=<?= $row['id'] ?>"
               class="badge <?= $row['is_active'] ? 'bg-success' : 'bg-secondary' ?> text-decoration-none"
               onclick="return confirm('Toggle schedule active status?')">
              <?= $row['is_active'] ? 'Active' : 'Inactive' ?>
            </a>
          </td>
          <td class="text-center">
            <div class="btn-group btn-group-sm">
              <a href="schedules.php?edit=<?= $row['id'] ?>" class="btn btn-outline-primary" title="Edit">
                <i class="bi bi-pencil"></i>
              </a>
              <a href="schedules.php?delete=<?= $row['id'] ?>" class="btn btn-outline-danger" title="Delete"
                 onclick="return confirm('Delete this schedule permanently?')">
                <i class="bi bi-trash"></i>
              </a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <tr id="schedNoMatchRow" class="d-none">
          <td colspan="9" class="text-center py-4 text-muted">
            <i class="bi bi-calendar-x d-block mb-1" style="font-size:1.5rem;"></i>
            No schedules match your current filters.
            <button type="button" class="btn btn-sm btn-link" onclick="document.getElementById('schedResetFilters').click()">Reset filters</button>
          </td>
        </tr>
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
document.getElementById('scheduleForm')?.addEventListener('submit', function () {
  const btn = this.querySelector('button[type="submit"]');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
});

// ── Schedule filters + live search (client-side, no page reload) ──────────
(function () {
  const rows       = Array.from(document.querySelectorAll('#schedTbody .sched-row'));
  const totalCount = rows.length;
  const searchBox  = document.getElementById('schedSearch');
  const selDay     = document.getElementById('schedFilterDay');
  const selRoom    = document.getElementById('schedFilterRoom');
  const selFaculty = document.getElementById('schedFilterFaculty');
  const selSubject = document.getElementById('schedFilterSubject');
  const selYear    = document.getElementById('schedFilterYear');
  const selSem     = document.getElementById('schedFilterSemester');
  const counterEl  = document.getElementById('schedCounter');
  const chipsEl    = document.getElementById('schedActiveFilters');
  const resetBtn   = document.getElementById('schedResetFilters');
  const noMatchRow = document.getElementById('schedNoMatchRow');
  const emptyRow   = document.getElementById('schedEmptyRow'); // only present when there are zero schedules at all

  if (!rows.length && !searchBox) return; // nothing to wire up (defensive)

  const controls = [
    { el: searchBox,  label: 'Search',    getVal: () => searchBox.value.trim() },
    { el: selDay,     label: 'Day',       getVal: () => selDay.value },
    { el: selRoom,    label: 'Room',      getVal: () => selRoom.options[selRoom.selectedIndex]?.text },
    { el: selFaculty, label: 'Faculty',   getVal: () => selFaculty.options[selFaculty.selectedIndex]?.text },
    { el: selSubject, label: 'Subject',   getVal: () => selSubject.value },
    { el: selYear,    label: 'S.Y.',      getVal: () => selYear.value },
    { el: selSem,     label: 'Semester',  getVal: () => selSem.value },
  ];

  function applyFilters() {
    const q    = searchBox ? searchBox.value.trim().toLowerCase() : '';
    const day  = selDay ? selDay.value : '';
    const room = selRoom ? selRoom.value : '';
    const fac  = selFaculty ? selFaculty.value : '';
    const subj = selSubject ? selSubject.value : '';
    const year = selYear ? selYear.value : '';
    const sem  = selSem ? selSem.value : '';

    let visible = 0;
    rows.forEach(row => {
      const matches =
        (!day  || row.dataset.day === day) &&
        (!room || row.dataset.room === room) &&
        (!fac  || row.dataset.faculty === fac) &&
        (!subj || row.dataset.subject === subj) &&
        (!year || row.dataset.year === year) &&
        (!sem  || row.dataset.semester === sem) &&
        (!q    || row.dataset.search.includes(q));
      row.classList.toggle('d-none', !matches);
      if (matches) visible++;
    });

    if (noMatchRow) noMatchRow.classList.toggle('d-none', !(visible === 0 && totalCount > 0));

    if (counterEl) {
      counterEl.innerHTML = totalCount === 0
        ? 'No schedules yet.'
        : `Showing <strong>${visible}</strong> of <strong>${totalCount}</strong> schedules`;
    }

    // Active filter chips
    if (chipsEl) {
      chipsEl.innerHTML = '';
      let anyActive = false;
      controls.forEach(c => {
        if (!c.el || !c.el.value) return;
        const val = c.getVal();
        if (!val) return;
        anyActive = true;
        const chip = document.createElement('span');
        chip.className = 'badge rounded-pill text-bg-light border';
        chip.innerHTML = `${c.label}: ${val} <i class="bi bi-x-circle-fill ms-1" style="cursor:pointer;"></i>`;
        chip.querySelector('i').addEventListener('click', () => {
          c.el.value = '';
          applyFilters();
        });
        chipsEl.appendChild(chip);
      });
      if (resetBtn) resetBtn.classList.toggle('d-none', !anyActive);
    }
  }

  controls.forEach(c => {
    if (!c.el) return;
    c.el.addEventListener(c.el.tagName === 'SELECT' ? 'change' : 'input', applyFilters);
  });

  if (resetBtn) {
    resetBtn.addEventListener('click', () => {
      controls.forEach(c => { if (c.el) c.el.value = ''; });
      applyFilters();
    });
  }

  applyFilters();
})();
</script>
</body></html>
