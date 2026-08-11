<?php
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('faculty');

$pageTitle  = 'My Profile';
$userId     = $_SESSION['user_id'];
$errors     = [];

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) { redirect('/logout.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name                   = trim($_POST['name']                   ?? '');
    $email                  = trim($_POST['email']                  ?? '');
    $department             = trim($_POST['department']             ?? '');
    $phone                  = trim($_POST['phone']                  ?? '');
    $employee_id            = trim($_POST['employee_id']            ?? '');
    $office                 = trim($_POST['office']                 ?? '');
    $emergency_name         = trim($_POST['emergency_name']         ?? '');
    $emergency_relationship = trim($_POST['emergency_relationship'] ?? '');
    $emergency_phone        = trim($_POST['emergency_phone']        ?? '');
    $emergency_contact      = trim($_POST['emergency_contact']      ?? ''); // legacy

    if (empty($name))  $errors[] = 'Full name is required.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if (empty($employee_id)) $errors[] = 'Employee ID is required.';

    $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $chk->bind_param('si', $email, $userId);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) $errors[] = 'That email is already used by another account.';
    $chk->close();

    if ($employee_id !== '') {
        $chkEmp = $conn->prepare("SELECT id FROM users WHERE employee_id = ? AND role = 'faculty' AND id != ? LIMIT 1");
        if ($chkEmp) {
            $chkEmp->bind_param('si', $employee_id, $userId);
            $chkEmp->execute();
            if ($chkEmp->get_result()->num_rows > 0) $errors[] = 'That Employee ID is already used by another faculty account.';
            $chkEmp->close();
        }
    }

    if (empty($errors)) {
        $fields = ['name=?', 'email=?', 'department=?', 'phone=?', 'employee_id=?'];
        $values = [$name, $email, $department, $phone, $employee_id];
        $types  = 'sssss';

        if (columnExists($conn, 'users', 'office')) {
            $fields[] = 'office=?'; $values[] = $office; $types .= 's';
        }

        if (columnExists($conn, 'users', 'emergency_name')) {
            $fields[] = 'emergency_name=?';  $values[] = $emergency_name;  $types .= 's';
            $fields[] = 'emergency_phone=?'; $values[] = $emergency_phone; $types .= 's';
            if (columnExists($conn, 'users', 'emergency_relationship')) {
                $fields[] = 'emergency_relationship=?'; $values[] = $emergency_relationship; $types .= 's';
            }
        } elseif (columnExists($conn, 'users', 'emergency_contact')) {
            $fields[] = 'emergency_contact=?'; $values[] = $emergency_contact; $types .= 's';
        }

        $values[] = $userId; $types .= 'i';
        $upd = $conn->prepare("UPDATE users SET " . implode(',', $fields) . " WHERE id=?");
        $upd->bind_param($types, ...$values);
        $upd->execute();
        $upd->close();

        $_SESSION['user_name'] = facultyDisplayName($user['title'] ?? null, $name);
        logActivity($conn, 'UPDATE_PROFILE', "Faculty updated their own profile: " . facultyDisplayName($user['title'] ?? null, $name));
        setFlash('success', 'Your profile has been updated successfully.');
        redirect('/faculty/profile.php');
    }

    $user = array_merge($user, $_POST);
}

include __DIR__ . '/../authentication.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-7">

    <div class="d-flex align-items-center gap-2 mb-3">
      <h5 class="mb-0 fw-bold"><i class="bi bi-person-circle text-primary me-2"></i>My Profile</h5>
    </div>

    <div class="card mb-3">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center fw-bold flex-shrink-0"
             style="width:64px;height:64px;font-size:1.6rem;">
          <?= strtoupper(substr($user['name'], 0, 1)) ?>
        </div>
        <div>
          <div class="fw-bold fs-5"><?= htmlspecialchars(facultyDisplayName($user['title'] ?? null, $user['name'])) ?></div>
          <div class="text-muted small"><?= htmlspecialchars($user['email']) ?></div>
          <span class="badge bg-primary mt-1"><i class="bi bi-person-badge me-1"></i>Faculty</span>
          <?php if (!empty($user['department'])): ?>
          <span class="badge bg-secondary mt-1"><?= htmlspecialchars($user['department']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0 ps-3"><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul>
    </div>
    <?php endif; ?>

    <div class="card mb-3">
      <div class="card-header bg-primary text-white">
        <i class="bi bi-pencil-square me-2"></i>Update Personal Information
      </div>
      <div class="card-body">
        <form method="POST">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Title</label>
              <input type="text" class="form-control" value="<?= htmlspecialchars($user['title'] ?: '— No Title —') ?>" disabled>
              <div class="form-text">Your title is set by the Administrator.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Department</label>
              <input type="text" name="department" class="form-control" value="<?= htmlspecialchars($user['department'] ?? '') ?>" placeholder="e.g. Computer Science">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Employee ID</label>
              <input type="text" name="employee_id" class="form-control" value="<?= htmlspecialchars($user['employee_id'] ?? '') ?>" placeholder="e.g. EMP-001">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone Number</label>
              <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="e.g. 09XX-XXX-XXXX">
            </div>
            <?php if (columnExists($conn, 'users', 'office')): ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Office</label>
              <input type="text" name="office" class="form-control" value="<?= htmlspecialchars($user['office'] ?? '') ?>" placeholder="e.g. Faculty Room 2">
            </div>
            <?php endif; ?>
            <?php if (columnExists($conn, 'users', 'emergency_name')): ?>
            <div class="col-12"><hr class="my-1"><small class="text-muted fw-semibold text-uppercase">Emergency Contact</small></div>
            <div class="col-md-5">
              <label class="form-label fw-semibold">Contact Name</label>
              <input type="text" name="emergency_name" class="form-control"
                     value="<?= htmlspecialchars($user['emergency_name'] ?? '') ?>"
                     placeholder="e.g. Maria Cruz">
            </div>
            <?php if (columnExists($conn, 'users', 'emergency_relationship')): ?>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Relationship</label>
              <select name="emergency_relationship" class="form-select">
                <option value="">— Select —</option>
                <?php
                $rels = ['Spouse','Parent','Sibling','Child','Relative','Friend','Colleague','Other'];
                $sel  = $user['emergency_relationship'] ?? '';
                foreach ($rels as $r) echo '<option value="'.$r.'"'.($sel===$r?' selected':'').'>'.$r.'</option>';
                ?>
              </select>
            </div>
            <?php endif; ?>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Contact Number</label>
              <input type="text" name="emergency_phone" class="form-control"
                     value="<?= htmlspecialchars($user['emergency_phone'] ?? '') ?>"
                     placeholder="09XX-XXX-XXXX">
            </div>
            <?php elseif (columnExists($conn, 'users', 'emergency_contact')): ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Emergency Contact</label>
              <input type="text" name="emergency_contact" class="form-control"
                     value="<?= htmlspecialchars($user['emergency_contact'] ?? '') ?>"
                     placeholder="Name / number">
            </div>
            <?php endif; ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Account Status</label>
              <div class="form-control-plaintext">
                <?php $accountStatus = $user['account_status'] ?? (($user['is_active'] ?? 1) ? 'Active' : 'Inactive'); ?>
                <span class="badge bg-<?= $accountStatus === 'Active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars($accountStatus) ?></span>
              </div>
            </div>
          </div>
          <hr class="my-3">
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
          <div class="fw-semibold"><i class="bi bi-shield-lock me-2 text-warning"></i>Password & Security</div>
          <div class="text-muted small">Update your account password</div>
        </div>
        <a href="change_password.php" class="btn btn-outline-warning">
          <i class="bi bi-key me-1"></i>Change Password
        </a>
        <a href="/setup_security_question.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-shield-check me-1"></i>Security Question
</a>
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
