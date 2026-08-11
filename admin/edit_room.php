<?php

require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

$pageTitle = 'Edit Room';
$errors = [];

$id = intval($_GET['id'] ?? 0);
if (!$id) { setFlash('danger', 'Invalid room.'); redirect('/admin/rooms.php'); }

$stmt = $conn->prepare("SELECT * FROM rooms WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$room) { setFlash('danger', 'Room not found.'); redirect('/admin/rooms.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $room_code = strtoupper(trim($_POST['room_code'] ?? ''));
    $room_name = trim($_POST['room_name'] ?? '');
    $building  = trim($_POST['building'] ?? '');
    $floor     = trim($_POST['floor'] ?? '');
    $capacity  = intval($_POST['capacity'] ?? 0);
    $status    = $_POST['status'] ?? 'Available';

    if (empty($room_code)) $errors[] = 'Room code is required.';
    if (empty($room_name)) $errors[] = 'Room name is required.';

    $chk = $conn->prepare("SELECT id FROM rooms WHERE room_code = ? AND id != ?");
    $chk->bind_param('si', $room_code, $id);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) $errors[] = 'Room code already in use by another room.';
    $chk->close();

    if (empty($errors)) {
        $stmt = $conn->prepare("UPDATE rooms SET room_code=?, room_name=?, building=?, floor=?, capacity=?, status=? WHERE id=?");
        $stmt->bind_param('ssssisi', $room_code, $room_name, $building, $floor, $capacity, $status, $id);
        $stmt->execute();
        $stmt->close();
        $action = ($room['status'] ?? '') !== $status ? 'ROOM_STATUS' : 'EDIT_ROOM';
        logActivity($conn, $action, "Updated room {$room_code}; status {$room['status']} to {$status}");
        setFlash('success', "Room «{$room_code}» updated successfully.");
        redirect('/admin/rooms.php');
    }

    $room = array_merge($room, $_POST);
}

include __DIR__ . '/../authentication.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-6">
    <div class="d-flex align-items-center gap-2 mb-3">
      <a href="rooms.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
      <h5 class="mb-0 fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Room</h5>
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
                     value="<?= htmlspecialchars($room['room_code']) ?>" required>
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Room Name <span class="text-danger">*</span></label>
              <input type="text" name="room_name" class="form-control"
                     value="<?= htmlspecialchars($room['room_name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Building</label>
              <input type="text" name="building" class="form-control"
                     value="<?= htmlspecialchars($room['building'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Floor</label>
              <input type="text" name="floor" class="form-control"
                     value="<?= htmlspecialchars($room['floor'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Capacity</label>
              <input type="number" name="capacity" class="form-control" min="0"
                     value="<?= intval($room['capacity']) ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" class="form-select">
                <?php foreach(['Available','Scheduled','Reserved','Occupied','Unconfirmed'] as $s): ?>
                <option value="<?= $s ?>" <?= $room['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Manually changing status overrides the system flow.</div>
            </div>
          </div>
          <hr class="my-3">
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Update Room</button>
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
