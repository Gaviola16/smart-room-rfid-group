<?php
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

$pageTitle = 'Add Room';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $room_code = strtoupper(trim($_POST['room_code'] ?? ''));
    $room_name = trim($_POST['room_name'] ?? '');
    $building  = trim($_POST['building'] ?? '');
    $floor     = trim($_POST['floor'] ?? '');
    $capacity  = intval($_POST['capacity'] ?? 0);

    if (empty($room_code)) $errors[] = 'Room code is required.';
    if (empty($room_name)) $errors[] = 'Room name is required.';
    if ($capacity < 0)     $errors[] = 'Capacity must be 0 or more.';

    $chk = $conn->prepare("SELECT id FROM rooms WHERE room_code = ?");
    $chk->bind_param('s', $room_code);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) $errors[] = 'Room code already exists.';
    $chk->close();

    if (empty($errors)) {
        $stmt = $conn->prepare("INSERT INTO rooms (room_code, room_name, building, floor, capacity) VALUES (?,?,?,?,?)");
        $stmt->bind_param('ssssi', $room_code, $room_name, $building, $floor, $capacity);
        $stmt->execute();
        $stmt->close();
        logActivity($conn, 'CREATE_ROOM', "Created room {$room_code}: {$room_name}");
        setFlash('success', "Room «{$room_code}» added successfully.");
        redirect('/admin/rooms.php');
    }
}

include __DIR__ . '/../authentication.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-6">
    <div class="d-flex align-items-center gap-2 mb-3">
      <a href="rooms.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
      <h5 class="mb-0 fw-bold"><i class="bi bi-plus-circle text-primary me-2"></i>Add New Room</h5>
    </div>

    <div class="card">
      <div class="card-body">
        <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div>
        <?php endif; ?>
        <form method="POST">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Room Code <span class="text-danger">*</span></label>
              <input type="text" name="room_code" class="form-control text-uppercase"
                     value="<?= htmlspecialchars($_POST['room_code'] ?? '') ?>"
                     placeholder="e.g. CS-101" required>
              <div class="form-text">Unique identifier (auto-uppercased)</div>
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Room Name <span class="text-danger">*</span></label>
              <input type="text" name="room_name" class="form-control"
                     value="<?= htmlspecialchars($_POST['room_name'] ?? '') ?>"
                     placeholder="e.g. Computer Lab 1" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Building</label>
              <input type="text" name="building" class="form-control"
                     value="<?= htmlspecialchars($_POST['building'] ?? '') ?>"
                     placeholder="e.g. Main Building">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Floor</label>
              <input type="text" name="floor" class="form-control"
                     value="<?= htmlspecialchars($_POST['floor'] ?? '') ?>"
                     placeholder="e.g. 2nd">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Capacity</label>
              <input type="number" name="capacity" class="form-control" min="0"
                     value="<?= intval($_POST['capacity'] ?? 0) ?>">
            </div>
          </div>
          <hr class="my-3">
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Room</button>
            <a href="rooms.php" class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>
      </div>
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
