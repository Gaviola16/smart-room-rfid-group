<?php
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

$pageTitle = 'Change Password';
$userId    = $_SESSION['user_id'];
$errors    = [];

$stmt = $conn->prepare("SELECT password, name FROM users WHERE id = ? AND role = 'admin'");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    redirect('/logout.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = trim($_POST['current_password'] ?? '');
    $new     = trim($_POST['new_password'] ?? '');
    $confirm = trim($_POST['confirm_password'] ?? '');

    if ($current === '' || !password_verify($current, $user['password'])) {
        $errors[] = 'Current password is incorrect.';
    }
    $errors = array_merge($errors, validatePasswordStrength($new, ['name' => $user['name']]));
    if ($new !== $confirm) {
        $errors[] = 'New password and confirmation do not match.';
    }
    if ($current === $new && empty($errors)) {
        $errors[] = 'New password must be different from your current password.';
    }

    if (empty($errors)) {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $upd->bind_param('si', $hash, $userId);
        $upd->execute();
        $upd->close();

        logActivity($conn, 'CHANGE_PASSWORD', "Admin changed their own password: {$user['name']}");
        setFlash('success', 'Your password has been changed successfully.');
        redirect('/admin/profile.php');
    }
}

include __DIR__ . '/../authentication.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-5">
    <div class="d-flex align-items-center gap-2 mb-3">
      <a href="profile.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
      <h5 class="mb-0 fw-bold"><i class="bi bi-shield-lock text-warning me-2"></i>Change Password</h5>
    </div>

    <div class="card">
      <div class="card-body">
        <?php if ($errors): ?>
        <div class="alert alert-danger py-2">
          <ul class="mb-0 ps-3">
            <?php foreach ($errors as $error): ?>
            <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <form method="POST">
          <div class="mb-3">
            <label class="form-label fw-semibold">Current Password <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="password" id="cur" name="current_password" class="form-control" required>
              <button class="btn btn-outline-secondary" type="button" onclick="togglePw('cur','i0')"><i class="bi bi-eye" id="i0"></i></button>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">New Password <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="password" id="pw1" name="new_password" class="form-control" placeholder="Min. 8 chars, upper, lower, number, special" required>
              <button class="btn btn-outline-secondary" type="button" onclick="togglePw('pw1','i1')"><i class="bi bi-eye" id="i1"></i></button>
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
          <div class="mb-4">
            <label class="form-label fw-semibold">Confirm New Password <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="password" id="pw2" name="confirm_password" class="form-control" placeholder="Re-enter new password" required>
              <button class="btn btn-outline-secondary" type="button" onclick="togglePw('pw2','i2')"><i class="bi bi-eye" id="i2"></i></button>
            </div>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-warning text-dark fw-semibold"><i class="bi bi-key me-1"></i>Update Password</button>
            <a href="profile.php" class="btn btn-outline-secondary">Cancel</a>
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
function togglePw(id,iid){const p=document.getElementById(id);const i=document.getElementById(iid);p.type=p.type==='password'?'text':'password';i.className=p.type==='password'?'bi bi-eye':'bi bi-eye-slash';}

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
initPwChecklist('pw1');
</script>
</body></html>
