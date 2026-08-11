<?php
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('faculty');

$pageTitle = 'Complete Profile';
$userId = (int)$_SESSION['user_id'];
$errors = [];

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'faculty'");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) redirect('/logout.php');
if (isFacultyProfileComplete($conn, $user)) redirect('/faculty/dashboard.php');

$hasEmergencyFields = columnExists($conn, 'users', 'emergency_name');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id           = trim($_POST['employee_id']           ?? '');
    $department            = trim($_POST['department']            ?? '');
    $phone                 = trim($_POST['phone']                 ?? '');
    $office                = trim($_POST['office']                ?? '');
    $emergency_name        = trim($_POST['emergency_name']        ?? '');
    $emergency_relationship = trim($_POST['emergency_relationship'] ?? '');
    $emergency_phone       = trim($_POST['emergency_phone']       ?? '');
    // Legacy fallback
    $emergency_contact     = trim($_POST['emergency_contact']     ?? '');

    if ($employee_id === '')  $errors[] = 'Employee ID is required.';
    if ($department === '')   $errors[] = 'Department is required.';
    if ($phone === '')        $errors[] = 'Contact Number is required.';
    if (columnExists($conn, 'users', 'office') && $office === '') $errors[] = 'Office is required.';

    if ($hasEmergencyFields) {
        if ($emergency_name === '')         $errors[] = 'Emergency Contact Name is required.';
        if ($emergency_relationship === '') $errors[] = 'Relationship is required.';
        if ($emergency_phone === '')        $errors[] = 'Emergency Contact Number is required.';
    } elseif (columnExists($conn, 'users', 'emergency_contact') && $emergency_contact === '') {
        $errors[] = 'Emergency Contact is required.';
    }

    // Employee ID uniqueness check
    if ($employee_id !== '') {
        $chk = $conn->prepare("SELECT id FROM users WHERE employee_id = ? AND id != ? AND role = 'faculty' LIMIT 1");
        $chk->bind_param('si', $employee_id, $userId); $chk->execute();
        if ($chk->get_result()->num_rows > 0) $errors[] = 'Employee ID "' . htmlspecialchars($employee_id) . '" is already assigned to another faculty member.';
        $chk->close();
    }

    if (!$errors) {
        $fields = ['employee_id=?', 'department=?', 'phone=?'];
        $values = [$employee_id, $department, $phone];
        $types  = 'sss';

        if (columnExists($conn, 'users', 'office')) {
            $fields[] = 'office=?'; $values[] = $office; $types .= 's';
        }
        if ($hasEmergencyFields) {
            $fields[] = 'emergency_name=?';         $values[] = $emergency_name;         $types .= 's';
            $fields[] = 'emergency_phone=?';        $values[] = $emergency_phone;        $types .= 's';
            if (columnExists($conn, 'users', 'emergency_relationship')) {
                $fields[] = 'emergency_relationship=?'; $values[] = $emergency_relationship; $types .= 's';
            }
        } elseif (columnExists($conn, 'users', 'emergency_contact')) {
            $fields[] = 'emergency_contact=?'; $values[] = $emergency_contact; $types .= 's';
        }

        $values[] = $userId; $types .= 'i';
        $upd = $conn->prepare("UPDATE users SET " . implode(',', $fields) . " WHERE id=?");
        $upd->bind_param($types, ...$values);
        $upd->execute(); $upd->close();

        logActivity($conn, 'UPDATE_PROFILE', "Faculty completed required profile information: {$user['name']}");
        setFlash('success', 'Profile completed successfully. Welcome to Smart Room!');
        redirect('/faculty/dashboard.php');
    }

    $user = array_merge($user, $_POST);
}

include __DIR__ . '/../authentication.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-7">
    <div class="mb-3">
      <h5 class="mb-0 fw-bold"><i class="bi bi-person-lines-fill text-primary me-2"></i>Complete Your Profile</h5>
      <div class="text-muted small">Please fill in all required fields before accessing your dashboard.</div>
    </div>

    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Please fix the following:</strong>
      <ul class="mb-0 ps-3 mt-1"><?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?></ul>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-body">
        <form method="POST">
          <!-- Basic Info -->
          <h6 class="fw-bold text-primary mb-3"><i class="bi bi-person-badge me-1"></i>Basic Information</h6>
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Employee ID <span class="text-danger">*</span></label>
              <input type="text" name="employee_id" class="form-control"
                     value="<?= htmlspecialchars($user['employee_id'] ?? '') ?>" required
                     placeholder="e.g. EMP-001">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Department <span class="text-danger">*</span></label>
              <input type="text" name="department" class="form-control"
                     value="<?= htmlspecialchars($user['department'] ?? '') ?>" required
                     placeholder="e.g. Computer Science">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Contact Number <span class="text-danger">*</span></label>
              <input type="text" name="phone" class="form-control"
                     value="<?= htmlspecialchars($user['phone'] ?? '') ?>" required
                     placeholder="e.g. 09XX-XXX-XXXX">
            </div>
            <?php if (columnExists($conn, 'users', 'office')): ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Office <span class="text-danger">*</span></label>
              <input type="text" name="office" class="form-control"
                     value="<?= htmlspecialchars($user['office'] ?? '') ?>" required
                     placeholder="e.g. Faculty Room 2">
            </div>
            <?php endif; ?>
          </div>

          <!-- Emergency Contact -->
          <h6 class="fw-bold text-danger mb-3"><i class="bi bi-telephone-fill me-1"></i>Emergency Contact Information</h6>
          <?php if ($hasEmergencyFields): ?>
          <div class="row g-3 mb-3">
            <div class="col-md-5">
              <label class="form-label fw-semibold">Contact Name <span class="text-danger">*</span></label>
              <input type="text" name="emergency_name" class="form-control"
                     value="<?= htmlspecialchars($user['emergency_name'] ?? '') ?>" required
                     placeholder="e.g. Maria Cruz">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Relationship <span class="text-danger">*</span></label>
              <select name="emergency_relationship" class="form-select" required>
                <option value="">— Select —</option>
                <?php
                $rels = ['Spouse', 'Parent', 'Sibling', 'Child', 'Relative', 'Friend', 'Colleague', 'Other'];
                $selected = $user['emergency_relationship'] ?? '';
                foreach ($rels as $r) echo '<option value="' . $r . '"' . ($selected === $r ? ' selected' : '') . '>' . $r . '</option>';
                ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Contact Number <span class="text-danger">*</span></label>
              <input type="text" name="emergency_phone" class="form-control"
                     value="<?= htmlspecialchars($user['emergency_phone'] ?? '') ?>" required
                     placeholder="09XX-XXX-XXXX">
            </div>
          </div>
          <?php elseif (columnExists($conn, 'users', 'emergency_contact')): ?>
          <div class="row g-3 mb-3">
            <div class="col-md-12">
              <label class="form-label fw-semibold">Emergency Contact <span class="text-danger">*</span></label>
              <input type="text" name="emergency_contact" class="form-control"
                     value="<?= htmlspecialchars($user['emergency_contact'] ?? '') ?>" required
                     placeholder="Name / number">
            </div>
          </div>
          <?php endif; ?>

          <hr class="my-3">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-save me-1"></i>Save and Continue to Dashboard
          </button>
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