<?php
require_once __DIR__ . '/../../db.php';
session_start(); requireLogin('admin');
$pageTitle = 'Faculty Management';

$search = trim($_GET['search'] ?? '');
$where  = "WHERE role='faculty'";
$params = [];
$types  = '';

if ($search !== '') {
    $where   .= " AND (name LIKE ? OR email LIKE ? OR department LIKE ? OR employee_id LIKE ?)";
    $like     = "%$search%";
    $params   = [$like, $like, $like, $like];
    $types    = 'ssss';
}

$stmt = $conn->prepare("SELECT * FROM users $where ORDER BY name ASC");
if ($types) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$faculty = $stmt->get_result();
$stmt->close();

// Map email => most recent registration_requests row, so the delete
// confirmation modal can offer "also delete the associated registration
// request" only when one actually exists for that faculty member.
$facultyRequestMap = [];
if (tableExists($conn, 'registration_requests')) {
    $reqRes = $conn->query("SELECT id, email, status FROM registration_requests ORDER BY id DESC");
    while ($rr = $reqRes->fetch_assoc()) {
        $emailKey = strtolower($rr['email']);
        if (!isset($facultyRequestMap[$emailKey])) $facultyRequestMap[$emailKey] = $rr;
    }
}

include __DIR__ . '/../../authentication.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold"><i class="bi bi-people me-2 text-primary"></i>Faculty Management</h5>
    <div class="text-muted small">Create, manage and monitor faculty accounts</div>
  </div>
  <a href="add.php" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Add Faculty</a>
</div>


<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="d-flex gap-2">
      <input type="text" name="search" class="form-control form-control-sm" placeholder="Search name, email, department, employee ID…" value="<?= htmlspecialchars($search) ?>">
      <button class="btn btn-sm btn-primary px-3"><i class="bi bi-search"></i></button>
      <?php if ($search): ?><a href="index.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-dark">
          <tr>
            <th>#</th>
            <th>Faculty</th>
            <th>Employee ID</th>
            <th>Department</th>
            <th>Phone</th>
            <th>Status</th>
            <th>RFID</th>
            <th>Face</th>
            <th>Email</th>
            <th>Last Login</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($faculty->num_rows === 0): ?>
        <tr><td colspan="11" class="text-center py-4 text-muted">No faculty found. <a href="add.php">Add the first one.</a></td></tr>
        <?php endif; ?>
        <?php $i=1; while($f = $faculty->fetch_assoc()): ?>
        <tr>
          <td class="text-muted"><?= $i++ ?></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center fw-bold flex-shrink-0"
                   style="width:36px;height:36px;font-size:.85rem;">
                <?= strtoupper(substr($f['name'],0,1)) ?>
              </div>
              <div>
                <div class="fw-semibold"><?= htmlspecialchars(facultyDisplayName($f['title'] ?? null, $f['name'])) ?></div>
                <div class="text-muted small"><?= htmlspecialchars($f['email']) ?></div>
              </div>
            </div>
          </td>
          <td><?= htmlspecialchars($f['employee_id'] ?? '—') ?></td>
          <td><?= htmlspecialchars($f['department'] ?? '—') ?></td>
          <td><?= htmlspecialchars($f['phone'] ?? '—') ?></td>
          <td>
            <?php
              $accountStatus = $f['account_status'] ?? (($f['is_active'] ?? 1) ? 'Active' : 'Inactive');
              if ($accountStatus === 'Pending Face Registration') $accountStatus = 'Pending Face Verification';
              $statusClass = [
                'Pending Face Verification' => 'warning text-dark',
                'Active' => 'success',
                'Inactive' => 'secondary',
                'Disabled' => 'danger',
              ][$accountStatus] ?? 'secondary';
            ?>
            <span class="badge bg-<?= $statusClass ?>"><?= htmlspecialchars($accountStatus) ?></span>
          </td>
          <td>
            <?php if (columnExists($conn, 'users', 'rfid_uid') && !empty($f['rfid_uid'])): ?>
              <span class="badge bg-success-subtle text-success border border-success-subtle">Assigned</span>
              <div class="text-muted small"><?= htmlspecialchars($f['rfid_uid']) ?></div>
            <?php else: ?>
              <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Not Assigned</span>
            <?php endif; ?>
          </td>
          <td>
            <?php $faceVerified = columnExists($conn, 'users', 'face_verified') ? (int)($f['face_verified'] ?? 0) : ($accountStatus === 'Active' ? 1 : 0); ?>
            <?php if ($faceVerified): ?>
              <span class="badge bg-success-subtle text-success border border-success-subtle">Verified</span>
            <?php else: ?>
              <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Not Verified</span>
            <?php endif; ?>
          </td>
          <td>
            <?php $emailVerified = columnExists($conn, 'users', 'email_verified') ? (int)($f['email_verified'] ?? 0) : ($accountStatus === 'Active' ? 1 : 0); ?>
            <?php if ($emailVerified): ?>
              <span class="badge bg-success-subtle text-success border border-success-subtle">Verified</span>
            <?php else: ?>
              <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Not Verified</span>
            <?php endif; ?>
          </td>
          <td class="small text-muted">
            <?= $f['last_login'] ? date('M d, Y h:i A', strtotime($f['last_login'])) : 'Never' ?>
          </td>
          <td class="text-center">
            <div class="btn-group btn-group-sm">
              <a href="edit.php?id=<?= $f['id'] ?>" class="btn btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
              <a href="reset_password.php?id=<?= $f['id'] ?>" class="btn btn-outline-warning" title="Reset Password"
                 onclick="return confirm('Reset password for <?= htmlspecialchars(facultyDisplayName($f['title'] ?? null, $f['name'])) ?>?')">
                <i class="bi bi-key"></i>
              </a>
              <a href="toggle.php?id=<?= $f['id'] ?>" class="btn btn-outline-<?= in_array($accountStatus, ['Active','Pending Face Verification'], true) ? 'secondary' : 'success' ?>" title="<?= in_array($accountStatus, ['Active','Pending Face Verification'], true) ? 'Deactivate' : 'Activate' ?>"
                 onclick="return confirm('<?= in_array($accountStatus, ['Active','Pending Face Verification'], true) ? 'Deactivate' : 'Activate' ?> this account?')">
                <i class="bi bi-<?= in_array($accountStatus, ['Active','Pending Face Verification'], true) ? 'slash-circle' : 'check-circle' ?>"></i>
              </a>
              <?php
                $assocRequest = $facultyRequestMap[strtolower($f['email'])] ?? null;
              ?>
              <button type="button" class="btn btn-outline-danger" title="Delete"
                 onclick='openDeleteModal(<?= (int)$f["id"] ?>, <?= json_encode($displayNameJs = facultyDisplayName($f['title'] ?? null, $f['name']), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, <?= $assocRequest ? 'true' : 'false' ?>)'>
                <i class="bi bi-trash"></i>
              </button>
            </div>
          </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

  </div></div><!-- close main content from authentication.php -->

<div class="modal fade" id="deleteFacultyModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Faculty</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="delete.php">
        <input type="hidden" name="id" id="delFacultyId">
        <div class="modal-body">
          <p id="delFacultyMsg"></p>
          <div class="form-check d-none" id="delRequestWrap">
            <input class="form-check-input" type="checkbox" name="delete_request" value="1" id="delRequestCheck">
            <label class="form-check-label" for="delRequestCheck">
              This faculty has an associated registration request. Also delete the associated registration request.
            </label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Delete Faculty</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('show');document.getElementById('sidebarOverlay').classList.toggle('show');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('show');document.getElementById('sidebarOverlay').classList.remove('show');}

function openDeleteModal(id, name, hasRequest) {
  document.getElementById('delFacultyId').value = id;
  document.getElementById('delFacultyMsg').textContent =
    `Delete ${name}? All their schedules and logs will also be deleted.`;
  const wrap = document.getElementById('delRequestWrap');
  const check = document.getElementById('delRequestCheck');
  check.checked = false;
  wrap.classList.toggle('d-none', !hasRequest);
  new bootstrap.Modal(document.getElementById('deleteFacultyModal')).show();
}
</script>
</body></html>