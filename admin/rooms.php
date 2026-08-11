<?php
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

$pageTitle = 'Manage Rooms';
$rooms = $conn->query("SELECT * FROM rooms ORDER BY room_code ASC");

include __DIR__ . '/../authentication.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h5 class="mb-0 fw-bold"><i class="bi bi-door-open me-2 text-primary"></i>Room Directory</h5>
    <div class="text-muted small">Manage all classrooms and labs</div>
  </div>
  <a href="add_room.php" class="btn btn-primary">
    <i class="bi bi-plus-circle me-1"></i>Add Room
  </a>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-dark">
          <tr>
            <th>#</th>
            <th>Code</th>
            <th>Room Name</th>
            <th>Building</th>
            <th>Floor</th>
            <th>Capacity</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($rooms->num_rows === 0): ?>
        <tr><td colspan="8" class="text-center py-4 text-muted">No rooms found. <a href="add_room.php">Add the first room.</a></td></tr>
        <?php endif; ?>
        <?php $i = 1; while ($row = $rooms->fetch_assoc()): ?>
        <tr>
          <td class="text-muted"><?= $i++ ?></td>
          <td><span class="badge bg-dark fs-6"><?= htmlspecialchars($row['room_code']) ?></span></td>
          <td class="fw-semibold"><?= htmlspecialchars($row['room_name']) ?></td>
          <td><?= htmlspecialchars($row['building'] ?? '—') ?></td>
          <td><?= htmlspecialchars($row['floor'] ?? '—') ?></td>
          <td><i class="bi bi-people me-1 text-muted"></i><?= $row['capacity'] ?></td>
          <td><?= statusBadge($row['status']) ?></td>
          <td class="text-center">
            <div class="btn-group btn-group-sm">
              <a href="edit_room.php?id=<?= $row['id'] ?>" class="btn btn-outline-primary" title="Edit">
                <i class="bi bi-pencil"></i>
              </a>
              <a href="room_timeline.php?id=<?= $row['id'] ?>" class="btn btn-outline-info" title="Timeline">
                <i class="bi bi-clock-history"></i>
              </a>
              <a href="delete_room.php?id=<?= $row['id'] ?>" class="btn btn-outline-danger" title="Delete"
                 onclick="return confirm('Delete room <?= htmlspecialchars($row['room_code']) ?>? This cannot be undone.')">
                <i class="bi bi-trash"></i>
              </a>
              <?php if ($row['status'] !== 'Available'): ?>
              <a href="release_room.php?id=<?= $row['id'] ?>" class="btn btn-outline-success" title="Release to Available"
                 onclick="return confirm('Release this room back to Available?')">
                <i class="bi bi-unlock"></i>
              </a>
              <?php endif; ?>
            </div>
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
