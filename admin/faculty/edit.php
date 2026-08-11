<?php
require_once __DIR__ . '/../../db.php';
session_start(); requireLogin('admin');
$pageTitle = 'Edit Faculty';
$errors = [];
$id = intval($_GET['id'] ?? 0);
if (!$id) { setFlash('danger','Invalid ID.'); redirect('/admin/faculty/index.php'); }

$stmt = $conn->prepare("SELECT * FROM users WHERE id=? AND role='faculty'");
$stmt->bind_param('i',$id); $stmt->execute();
$f = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$f) { setFlash('danger','Faculty not found.'); redirect('/admin/faculty/index.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name']        ?? '');
    $title       = trim($_POST['title']       ?? '');
    $email       = trim($_POST['email']       ?? '');
    $username    = trim($_POST['username']    ?? '');
    $department  = trim($_POST['department']  ?? '');
    $employee_id = trim($_POST['employee_id'] ?? '');
    $phone       = trim($_POST['phone']       ?? '');
    $account_status = trim($_POST['account_status'] ?? (($f['is_active'] ?? 1) ? 'Active' : 'Inactive'));
    $validStatuses = ['Pending Face Verification', 'Active', 'Inactive', 'Disabled'];
    if (!in_array($account_status, $validStatuses, true)) $account_status = 'Inactive';
    $is_active = in_array($account_status, ['Pending Face Verification', 'Active'], true) ? 1 : 0;
    $rfid_uid = trim($_POST['rfid_uid'] ?? '');

    if (empty($name))  $errors[] = 'Full name is required.';
    if ($title !== '' && !in_array($title, getFacultyTitleOptions(), true)) $errors[] = 'Invalid title selected.';
    if (empty($email)) $errors[] = 'Email is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if (columnExists($conn, 'users', 'username') && empty($username)) $errors[] = 'Username is required.';
    if (empty($employee_id)) $errors[] = 'Employee ID is required.';
    if ($username !== '' && !preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) $errors[] = 'Username may only contain letters, numbers, dots, underscores, and hyphens.';
    if ($employee_id !== '' && !preg_match('/^[A-Za-z0-9._-]{2,50}$/', $employee_id)) $errors[] = 'Employee ID may only contain letters, numbers, dots, underscores, and hyphens.';

    $chk = $conn->prepare("SELECT id FROM users WHERE email=? AND id!=?");
    $chk->bind_param('si',$email,$id); $chk->execute();
    if ($chk->get_result()->num_rows > 0) $errors[] = 'Email already used by another account.';
    $chk->close();

    if ($username !== '' && columnExists($conn, 'users', 'username')) {
        $chk = $conn->prepare("SELECT id FROM users WHERE username=? AND id!=?");
        $chk->bind_param('si', $username, $id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) $errors[] = 'Username already used by another account.';
        $chk->close();
    }

    if ($employee_id !== '') {
        $chk = $conn->prepare("SELECT id FROM users WHERE employee_id=? AND id!=? AND role='faculty'");
        $chk->bind_param('si', $employee_id, $id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) $errors[] = 'Employee ID already used by another faculty account.';
        $chk->close();
    }

    if (empty($errors)) {
        $fields = ['name=?', 'email=?', 'department=?', 'employee_id=?', 'phone=?', 'is_active=?'];
        $values = [$name, $email, $department, $employee_id, $phone, $is_active];
        $types = 'sssssi';

        if (columnExists($conn, 'users', 'username')) {
            $fields[] = 'username=?';
            $values[] = $username;
            $types .= 's';
        }
        if (columnExists($conn, 'users', 'account_status')) {
            $fields[] = 'account_status=?';
            $values[] = $account_status;
            $types .= 's';
        }
        if (columnExists($conn, 'users', 'rfid_uid')) {
            $fields[] = 'rfid_uid=?';
            $values[] = ($rfid_uid === '' ? null : $rfid_uid);
            $types .= 's';
        }
        if (columnExists($conn, 'users', 'title')) {
            $fields[] = 'title=?';
            $values[] = ($title === '' ? null : $title);
            $types .= 's';
        }

        $values[] = $id;
        $types .= 'i';
        $stmt = $conn->prepare("UPDATE users SET " . implode(',', $fields) . " WHERE id=?");
        $stmt->bind_param($types, ...$values);
        if ($stmt->execute()) {
            $stmt->close();
            $displayName = facultyDisplayName($title, $name);
            logActivity($conn,'EDIT_FACULTY',"Updated faculty account: $displayName (ID:$id)");
            setFlash('success',"Faculty {$displayName} updated successfully.");
            redirect('/admin/faculty/index.php');
        }
        $errors[] = ($conn->errno === 1062)
            ? 'Duplicate account information detected. Email, username, and employee ID must be unique.'
            : 'Unable to update faculty account. Please try again.';
        $stmt->close();
    }
    $f = array_merge($f, $_POST);
}
include __DIR__ . '/../../authentication.php';
?>
<div class="row justify-content-center">
  <div class="col-lg-7">
    <div class="d-flex align-items-center gap-2 mb-3">
      <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
      <h5 class="mb-0 fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Faculty Account</h5>
    </div>
    <div class="card">
      <div class="card-body">
        <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><ul class="mb-0 ps-3"><?php foreach($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div>
        <?php endif; ?>
        <form method="POST" id="facultyForm">
          <div class="row g-3">
            <?php if (columnExists($conn, 'users', 'title')): ?>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Title</label>
              <select name="title" class="form-select">
                <option value="">— No Title —</option>
                <?php foreach (getFacultyTitleOptions() as $opt): ?>
                <option value="<?= htmlspecialchars($opt) ?>" <?= ($f['title'] ?? '') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-9">
              <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($f['name']) ?>" required>
            </div>
            <?php else: ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($f['name']) ?>" required>
            </div>
            <?php endif; ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($f['email']) ?>" required>
            </div>
            <?php if (columnExists($conn, 'users', 'username')): ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
              <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($f['username'] ?? '') ?>" required>
            </div>
            <?php endif; ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Department</label>
              <input type="text" name="department" class="form-control" value="<?= htmlspecialchars($f['department'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Employee ID <span class="text-danger">*</span></label>
              <input type="text" name="employee_id" class="form-control" value="<?= htmlspecialchars($f['employee_id'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($f['phone'] ?? '') ?>">
            </div>
            <?php if (columnExists($conn, 'users', 'rfid_uid')): ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold">RFID UID</label>
              <input type="text" name="rfid_uid" class="form-control" value="<?= htmlspecialchars($f['rfid_uid'] ?? '') ?>" placeholder="RFID UID">
              <div class="form-text">RFID Status: <?= !empty($f['rfid_uid']) ? 'Assigned' : 'Not Assigned' ?>. RFID logic is not enabled yet.</div>
            </div>
            <?php endif; ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Face Verification Status</label>
              <div class="form-control-plaintext">
                <?php $faceVerified = columnExists($conn, 'users', 'face_verified') ? (int)($f['face_verified'] ?? 0) : 0; ?>
                <?php if ($faceVerified): ?>
                  <span class="badge bg-success-subtle text-success border border-success-subtle">Verified</span>
                <?php else: ?>
                  <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Not Verified</span>
                <?php endif; ?>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Account Status</label>
              <?php $currentStatus = $f['account_status'] ?? (($f['is_active'] ?? 1) ? 'Active' : 'Inactive'); ?>
              <select name="account_status" class="form-select">
                <?php foreach (['Pending Face Verification', 'Active', 'Inactive', 'Disabled'] as $status): ?>
                <option value="<?= $status ?>" <?= $currentStatus === $status ? 'selected' : '' ?>><?= $status ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <hr class="my-3">
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>
            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            <a href="reset_password.php?id=<?= $id ?>" class="btn btn-outline-warning ms-auto"
               onclick="return confirm('Reset password for this faculty?')">
              <i class="bi bi-key me-1"></i>Reset Password
            </a>
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
document.getElementById('facultyForm').addEventListener('submit', function () {
  const btn = this.querySelector('button[type="submit"]');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
});
</script></body></html>
