<?php
require_once __DIR__ . '/../../db.php';
session_start(); requireLogin('admin');
$pageTitle = 'Add Faculty';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name']        ?? '');
    $title       = trim($_POST['title']       ?? '');
    $email       = trim($_POST['email']       ?? '');
    $username    = trim($_POST['username']    ?? '');
    $password    = trim($_POST['password']    ?? '');
    $department  = trim($_POST['department']  ?? '');
    $employee_id = trim($_POST['employee_id'] ?? '');
    $phone       = trim($_POST['phone']       ?? '');

    if (empty($name))     $errors[] = 'Full name is required.';
    if ($title !== '' && !in_array($title, getFacultyTitleOptions(), true)) $errors[] = 'Invalid title selected.';
    if (empty($email))    $errors[] = 'Email is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if (columnExists($conn, 'users', 'username') && empty($username)) $errors[] = 'Username is required.';
    if (empty($employee_id)) $errors[] = 'Employee ID is required.';
    if ($username !== '' && !preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) $errors[] = 'Username may only contain letters, numbers, dots, underscores, and hyphens.';
    if ($employee_id !== '' && !preg_match('/^[A-Za-z0-9._-]{2,50}$/', $employee_id)) $errors[] = 'Employee ID may only contain letters, numbers, dots, underscores, and hyphens.';
    $errors = array_merge($errors, validatePasswordStrength($password, ['name' => $name, 'username' => $username, 'email' => $email]));

    // Duplicate email check
    $chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $chk->bind_param('s', $email); $chk->execute();
    if ($chk->get_result()->num_rows > 0) $errors[] = 'Email address already exists.';
    $chk->close();

    if ($username !== '' && columnExists($conn, 'users', 'username')) {
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $chk->bind_param('s', $username);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) $errors[] = 'Username already exists.';
        $chk->close();
    }

    if ($employee_id !== '') {
        $chk = $conn->prepare("SELECT id FROM users WHERE employee_id = ? AND role = 'faculty'");
        $chk->bind_param('s', $employee_id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) $errors[] = 'Employee ID already exists.';
        $chk->close();
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $columns = ['name', 'email', 'password', 'role', 'department', 'employee_id', 'phone', 'is_active'];
        $values = [$name, $email, $hash, 'faculty', $department, $employee_id, $phone, 1];
        $types = 'sssssssi';

        if (columnExists($conn, 'users', 'username')) {
            $columns[] = 'username';
            $values[] = $username;
            $types .= 's';
        }
        if (columnExists($conn, 'users', 'account_status')) {
            $columns[] = 'account_status';
            $values[] = 'Pending Face Verification';
            $types .= 's';
        }
        if (columnExists($conn, 'users', 'title') && $title !== '') {
            $columns[] = 'title';
            $values[] = $title;
            $types .= 's';
        }

        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $stmt = $conn->prepare("INSERT INTO users (" . implode(',', $columns) . ") VALUES ($placeholders)");
        $stmt->bind_param($types, ...$values);
        if ($stmt->execute()) {
            $stmt->close();
            $displayName = facultyDisplayName($title, $name);
            logActivity($conn, 'CREATE_FACULTY', "Created faculty account: $displayName ($email)");
            pushNotification($conn, 'New Faculty Added', "Faculty account for $displayName has been created.", 'success', '/admin/faculty/index.php');
            setFlash('success', "Faculty account for {$displayName} created successfully.");
            redirect('/admin/faculty/index.php');
        }
        $errors[] = ($conn->errno === 1062)
            ? 'Duplicate account information detected. Email, username, and employee ID must be unique.'
            : 'Unable to create faculty account. Please try again.';
        $stmt->close();
    }
}
include __DIR__ . '/../../authentication.php';
?>
<div class="row justify-content-center">
  <div class="col-lg-7">
    <div class="d-flex align-items-center gap-2 mb-3">
      <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
      <h5 class="mb-0 fw-bold"><i class="bi bi-person-plus text-primary me-2"></i>Add Faculty Account</h5>
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
                <option value="<?= htmlspecialchars($opt) ?>" <?= ($_POST['title'] ?? '') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-9">
              <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
            </div>
            <?php else: ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
            </div>
            <?php endif; ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
            </div>
            <?php if (columnExists($conn, 'users', 'username')): ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
              <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
            </div>
            <?php endif; ?>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Temporary Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" name="password" id="pw" class="form-control" placeholder="Min. 8 chars, upper, lower, number, special" required>
                <button class="btn btn-outline-secondary" type="button" onclick="togglePw()"><i class="bi bi-eye" id="pwIcon"></i></button>
              </div>
              <div class="progress mt-2" style="height:4px;">
                <div class="progress-bar" id="pwStrengthBar" role="progressbar" style="width:0%"></div>
              </div>
              <div class="small mt-1" id="pwStrengthLabel">&nbsp;</div>
              <ul class="list-unstyled small mt-1 mb-0" id="pwReqList">
                <li id="req-len"><i class="bi bi-x-circle-fill text-danger me-1"></i>8+ characters</li>
                <li id="req-upper"><i class="bi bi-x-circle-fill text-danger me-1"></i>Uppercase</li>
                <li id="req-lower"><i class="bi bi-x-circle-fill text-danger me-1"></i>Lowercase</li>
                <li id="req-num"><i class="bi bi-x-circle-fill text-danger me-1"></i>Number</li>
                <li id="req-special"><i class="bi bi-x-circle-fill text-danger me-1"></i>Special character</li>
              </ul>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Department</label>
              <input type="text" name="department" class="form-control" value="<?= htmlspecialchars($_POST['department'] ?? '') ?>" placeholder="e.g. Computer Science">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Employee ID <span class="text-danger">*</span></label>
              <input type="text" name="employee_id" class="form-control" value="<?= htmlspecialchars($_POST['employee_id'] ?? '') ?>" placeholder="e.g. EMP-001" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone Number</label>
              <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="e.g. 09XX-XXX-XXXX">
            </div>
          </div>
          <hr class="my-3">
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Create Account</button>
            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
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
function togglePw(){const p=document.getElementById('pw');const i=document.getElementById('pwIcon');p.type=p.type==='password'?'text':'password';i.className=p.type==='password'?'bi bi-eye':'bi bi-eye-slash';}
document.getElementById('facultyForm').addEventListener('submit', function () {
  const btn = this.querySelector('button[type="submit"]');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
});

/* Live password requirement indicator (UI only — server still enforces the real rules) */
function initPwChecklist(inputId) {
  const input = document.getElementById(inputId);
  const bar   = document.getElementById('pwStrengthBar');
  const label = document.getElementById('pwStrengthLabel');
  if (!input || !bar || !label) return;
  const reqs = {
    len: document.getElementById('req-len'),
    upper: document.getElementById('req-upper'),
    lower: document.getElementById('req-lower'),
    num: document.getElementById('req-num'),
    special: document.getElementById('req-special')
  };
  function setReq(el, met, text) {
    el.innerHTML = (met
      ? '<i class="bi bi-check-circle-fill text-success me-1"></i>'
      : '<i class="bi bi-x-circle-fill text-danger me-1"></i>') + text;
  }
  input.addEventListener('input', function () {
    const v = this.value;
    const checks = {
      len: v.length >= 8,
      upper: /[A-Z]/.test(v),
      lower: /[a-z]/.test(v),
      num: /[0-9]/.test(v),
      special: /[^A-Za-z0-9]/.test(v)
    };
    setReq(reqs.len,     checks.len,     '8+ characters');
    setReq(reqs.upper,   checks.upper,   'Uppercase');
    setReq(reqs.lower,   checks.lower,   'Lowercase');
    setReq(reqs.num,     checks.num,     'Number');
    setReq(reqs.special, checks.special, 'Special character');

    const score = Object.values(checks).filter(Boolean).length;
    let pct = 0, color = '#adb5bd', text = '';
    if (v.length > 0) {
      if (score <= 2)      { pct = 33;  color = '#dc3545'; text = 'Weak'; }
      else if (score <= 4) { pct = 66;  color = '#ffc107'; text = 'Medium'; }
      else                 { pct = 100; color = '#198754'; text = 'Strong'; }
    }
    bar.style.width = pct + '%';
    bar.style.background = color;
    label.textContent = text || '\u00A0';
    label.style.color = color;
  });
}
initPwChecklist('pw');
</script></body></html>
