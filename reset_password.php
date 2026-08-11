<?php
require_once __DIR__ . '/db.php';
session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['user_role'] ?? '';
    redirect($role === 'admin' ? '/admin/dashboard.php' : '/faculty/dashboard.php');
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error   = '';
$success = '';
$user    = null;

// Validate the token
if (empty($token)) {
    redirect('/forgot_password.php');
}

$stmt = $conn->prepare(
    "SELECT id, name, email, role, reset_token_expires
     FROM users
     WHERE reset_token = ? AND is_active = 1
     LIMIT 1"
);
$stmt->bind_param('s', $token);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    $tokenError = 'This password reset link is invalid or has already been used.';
} elseif (strtotime($user['reset_token_expires']) < time()) {
    // Expire the token
    $exp = $conn->prepare("UPDATE users SET reset_token = NULL, reset_token_expires = NULL WHERE id = ?");
    $exp->bind_param('i', $user['id']);
    $exp->execute();
    $exp->close();
    $tokenError = 'This reset link has expired (valid for 15 minutes). Please request a new one.';
} else {
    $tokenError = null;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$tokenError && $user) {
    $newPass    = $_POST['new_password']     ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    $pwErrors = validatePasswordStrength($newPass, ['name' => $user['name'], 'email' => $user['email']]);
    if ($pwErrors) {
        $error = implode(' ', $pwErrors);
    } elseif ($newPass !== $confirmPass) {
        $error = 'Passwords do not match.';
    } else {
        $hashed = password_hash($newPass, PASSWORD_DEFAULT);

        $upd = $conn->prepare(
            "UPDATE users
             SET password = ?, reset_token = NULL, reset_token_expires = NULL
             WHERE id = ?"
        );
        $upd->bind_param('si', $hashed, $user['id']);
        $upd->execute();
        $upd->close();

        logActivity($conn, 'CHANGE_PASSWORD', "Password reset via security question for user: {$user['name']}");

        setFlash('success', 'Your password has been reset successfully. Please log in with your new password.');
        redirect('/index.php?role=' . urlencode($user['role']));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset Password – Smart Room System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  :root {
    --brand-dark: #0d1b2a;
    --brand-blue: #1a6bcc;
  }
  body {
    min-height: 100vh;
    background: linear-gradient(135deg, #0d1b2a 0%, #1a3657 50%, #0f2944 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Segoe UI', system-ui, sans-serif;
    padding: 1rem;
  }
  .rp-card {
    width: 100%;
    max-width: 480px;
    background: #fff;
    border-radius: 16px;
    padding: 2.25rem 2rem;
    box-shadow: 0 20px 60px rgba(0,0,0,0.35);
  }
  .rp-icon {
    width: 56px; height: 56px;
    background: linear-gradient(135deg, #198754, #0f5b38);
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 1.5rem;
    margin-bottom: 1rem;
  }
  .rp-icon.error-icon {
    background: linear-gradient(135deg, #dc3545, #9b1c2a);
  }
  .form-control:focus { border-color: var(--brand-blue); box-shadow: 0 0 0 .25rem rgba(26,107,204,.15); }
  .btn-success { background: #198754; border-color: #198754; }
  .strength-bar {
    height: 4px;
    border-radius: 2px;
    margin-top: .35rem;
    transition: width .3s, background .3s;
    background: #e2e8f0;
    width: 0%;
  }
  .strength-label { font-size: .75rem; }
  .password-requirements li { font-size: .8rem; }
  .req-met   { color: #198754; }
  .req-unmet { color: #adb5bd; }
  @media (max-width: 480px) {
    .rp-card { padding: 1.5rem 1.25rem; }
  }
</style>
</head>
<body>

<div class="rp-card">

  <?php if ($tokenError): ?>
  <!-- Invalid / Expired Token -->
  <div class="rp-icon error-icon"><i class="bi bi-x-circle"></i></div>
  <h2 class="h5 fw-bold mb-2">Link Invalid or Expired</h2>
  <p class="text-muted small mb-4"><?= htmlspecialchars($tokenError) ?></p>
  <a href="/forgot_password.php" class="btn btn-primary w-100 py-2">
    <i class="bi bi-arrow-counterclockwise me-1"></i>Request a New Reset Link
  </a>

  <?php else: ?>
  <!-- Valid Token – Show Reset Form -->
  <div class="rp-icon"><i class="bi bi-key-fill"></i></div>
  <h2 class="h5 fw-bold mb-1">Set New Password</h2>
  <p class="text-muted small mb-1">
    Hi, <strong><?= htmlspecialchars($user['name']) ?></strong>.
    Choose a strong new password.
  </p>
  <div class="alert alert-warning py-2 small mb-3">
    <i class="bi bi-clock me-1"></i>
    This link expires at <strong><?= date('h:i A', strtotime($user['reset_token_expires'])) ?></strong>.
  </div>

  <?php if ($error): ?>
  <div class="alert alert-danger d-flex align-items-center gap-2 py-2">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
    <span><?= htmlspecialchars($error) ?></span>
  </div>
  <?php endif; ?>

  <form method="POST" id="resetForm" novalidate>
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

    <div class="mb-3">
      <label class="form-label fw-semibold" for="new_password">
        <i class="bi bi-lock me-1"></i>New Password
      </label>
      <div class="input-group">
        <input type="password" class="form-control" id="new_password" name="new_password"
               placeholder="Min. 8 chars, upper, lower, number, special" autocomplete="new-password" required>
        <button class="btn btn-outline-secondary" type="button" id="toggleNew" aria-label="Show">
          <i class="bi bi-eye" id="eyeNew"></i>
        </button>
      </div>
      <div class="strength-bar" id="strengthBar"></div>
      <div class="strength-label text-muted mt-1" id="strengthLabel"></div>
    </div>

    <!-- Password Requirements Checklist -->
    <ul class="list-unstyled password-requirements mb-3 ps-1">
      <li id="req-len">   <i class="bi bi-circle me-1"></i>At least 8 characters</li>
      <li id="req-upper"> <i class="bi bi-circle me-1"></i>At least one uppercase letter</li>
      <li id="req-lower"> <i class="bi bi-circle me-1"></i>At least one lowercase letter</li>
      <li id="req-num">   <i class="bi bi-circle me-1"></i>At least one number</li>
      <li id="req-special"><i class="bi bi-circle me-1"></i>At least one special character</li>
    </ul>

    <div class="mb-4">
      <label class="form-label fw-semibold" for="confirm_password">
        <i class="bi bi-lock-fill me-1"></i>Confirm New Password
      </label>
      <div class="input-group">
        <input type="password" class="form-control" id="confirm_password" name="confirm_password"
               placeholder="Repeat your new password" autocomplete="new-password" required>
        <button class="btn btn-outline-secondary" type="button" id="toggleConfirm" aria-label="Show">
          <i class="bi bi-eye" id="eyeConfirm"></i>
        </button>
      </div>
      <div class="form-text" id="matchMsg"></div>
    </div>

    <div class="d-grid mb-3">
      <button type="submit" class="btn btn-success py-2" id="submitBtn">
        <i class="bi bi-check-lg me-1"></i>Reset Password
      </button>
    </div>
  </form>
  <?php endif; ?>

  <div class="text-center mt-2">
    <a href="/index.php" class="text-decoration-none text-muted small">
      <i class="bi bi-arrow-left me-1"></i>Back to Login
    </a>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Toggle show/hide password */
function togglePw(btnId, inputId, iconId) {
  document.getElementById(btnId).addEventListener('click', function () {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    const show  = input.type === 'password';
    input.type  = show ? 'text' : 'password';
    icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
  });
}
togglePw('toggleNew',     'new_password',     'eyeNew');
togglePw('toggleConfirm', 'confirm_password', 'eyeConfirm');

/* Strength indicator */
const newPwInput  = document.getElementById('new_password');
const strengthBar = document.getElementById('strengthBar');
const strengthLbl = document.getElementById('strengthLabel');
const reqLen     = document.getElementById('req-len');
const reqUpper   = document.getElementById('req-upper');
const reqLower   = document.getElementById('req-lower');
const reqNum     = document.getElementById('req-num');
const reqSpecial = document.getElementById('req-special');

function checkReq(el, met) {
  el.classList.toggle('req-met',   met);
  el.classList.toggle('req-unmet', !met);
  el.querySelector('i').className = met ? 'bi bi-check-circle-fill me-1' : 'bi bi-circle me-1';
}

newPwInput.addEventListener('input', function () {
  const v = this.value;
  const hasLen     = v.length >= 8;
  const hasUpper   = /[A-Z]/.test(v);
  const hasLower   = /[a-z]/.test(v);
  const hasNum     = /[0-9]/.test(v);
  const hasSpecial = /[^A-Za-z0-9]/.test(v);
  checkReq(reqLen,     hasLen);
  checkReq(reqUpper,   hasUpper);
  checkReq(reqLower,   hasLower);
  checkReq(reqNum,     hasNum);
  checkReq(reqSpecial, hasSpecial);

  const score = [hasLen, hasUpper, hasLower, hasNum, hasSpecial].filter(Boolean).length;
  const colors = ['#dc3545','#fd7e14','#ffc107','#20c997','#198754'];
  const labels = ['Very Weak','Weak','Fair','Strong','Very Strong'];
  const pct    = (score / 5) * 100;

  strengthBar.style.width      = pct + '%';
  strengthBar.style.background = colors[score - 1] || '#e2e8f0';
  strengthLbl.textContent      = score > 0 ? labels[score - 1] : '';
  strengthLbl.style.color      = colors[score - 1] || '#adb5bd';
  checkMatch();
});

/* Match check */
const confirmInput = document.getElementById('confirm_password');
const matchMsg     = document.getElementById('matchMsg');

function checkMatch() {
  if (!confirmInput.value) { matchMsg.textContent = ''; return; }
  const match = newPwInput.value === confirmInput.value;
  matchMsg.textContent  = match ? '✓ Passwords match' : '✗ Passwords do not match';
  matchMsg.style.color  = match ? '#198754' : '#dc3545';
}

confirmInput.addEventListener('input', checkMatch);

/* Prevent submit if validation fails */
document.getElementById('resetForm')?.addEventListener('submit', function (e) {
  const v = newPwInput.value;
  const meetsPolicy = v.length >= 8 && /[A-Z]/.test(v) && /[a-z]/.test(v)
                    && /[0-9]/.test(v) && /[^A-Za-z0-9]/.test(v);
  const ok = meetsPolicy && v === confirmInput.value;
  if (!ok) {
    e.preventDefault();
    if (!meetsPolicy) {
      newPwInput.focus();
    } else {
      confirmInput.focus();
    }
    return;
  }
  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Resetting…';
});
</script>
</body>
</html>