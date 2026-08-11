
<?php

require_once __DIR__ . '/db.php';
session_start();

if (isset($_SESSION['user_id'])) {
    if (($_SESSION['user_role'] ?? '') === 'admin') redirect('/admin/dashboard.php');
    redirect('/faculty/dashboard.php');
}

$error        = '';
$success      = '';
$selectedRole = $_POST['role'] ?? $_GET['role'] ?? '';
$validRoles   = ['admin', 'faculty'];

if (!in_array($selectedRole, $validRoles, true)) {
    $selectedRole = '';
}

if (isset($_GET['logout'])) {
    $success = 'You have been logged out successfully.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');
    $role     = trim($_POST['role']     ?? '');
    $selectedRole = in_array($role, $validRoles, true) ? $role : '';

    if (!in_array($role, $validRoles, true)) {
        $error = 'Please select your role (Admin or Faculty).';
    } elseif (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        $statusSelect = columnExists($conn, 'users', 'account_status') ? "account_status" : "NULL AS account_status";
        $titleSelect  = columnExists($conn, 'users', 'title') ? "title" : "NULL AS title";
        $usernameSelect = columnExists($conn, 'users', 'username') ? "username" : "NULL AS username";
        $identifierWhere = columnExists($conn, 'users', 'username') ? "(email = ? OR username = ?)" : "email = ?";
        $stmt = $conn->prepare("SELECT id, name, {$titleSelect}, {$usernameSelect}, email, password, role, is_active, {$statusSelect} FROM users WHERE {$identifierWhere} AND role = ?");
        if (columnExists($conn, 'users', 'username')) {
            $stmt->bind_param('sss', $email, $email, $role);
        } else {
            $stmt->bind_param('ss', $email, $role);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $user   = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $chkSql = columnExists($conn, 'users', 'username') ? "SELECT role FROM users WHERE email = ? OR username = ?" : "SELECT role FROM users WHERE email = ?";
            $chk = $conn->prepare($chkSql);
            if (columnExists($conn, 'users', 'username')) {
                $chk->bind_param('ss', $email, $email);
            } else {
                $chk->bind_param('s', $email);
            }
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (!$existing) {
                $error = 'No account found with that username or email address.';
                logActivity($conn, 'LOGIN_FAILED', "Failed login for unknown account: {$email}");
            } else {
                $existingRole = htmlspecialchars(ucfirst($existing['role']));
                $selectedRoleLabel = htmlspecialchars(ucfirst($role));
                $error = "This email is registered as <strong>{$existingRole}</strong>, not as {$selectedRoleLabel}. Please select the correct role.";
                logActivity($conn, 'LOGIN_FAILED', "Failed login role mismatch for {$email}; selected {$role}");
            }
        } elseif (!password_verify($password, $user['password'])) {
            $error = 'Incorrect password. Please try again.';
            logActivity($conn, 'LOGIN_FAILED', "Failed login due to incorrect password: {$email}");
        } elseif (isset($user['is_active']) && (int)$user['is_active'] !== 1) {
            $error = 'Your account is inactive. Please contact the administrator.';
            logActivity($conn, 'LOGIN_FAILED', "Failed login for inactive account: {$email}");
        } elseif ($user['role'] === 'faculty' && in_array($user['account_status'] ?? 'Active', ['Inactive', 'Disabled'], true)) {
            $error = 'Your account is inactive. Please contact the administrator.';
            logActivity($conn, 'LOGIN_FAILED', "Failed login for {$user['account_status']} faculty account: {$email}");
        } else {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = facultyDisplayName($user['title'] ?? null, $user['name']);
            $_SESSION['user_role'] = $user['role'];

            $loginUpdate = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            if ($loginUpdate) {
                $loginUpdate->bind_param('i', $user['id']);
                $loginUpdate->execute();
                $loginUpdate->close();
            }

            logActivity($conn, 'LOGIN', "Successful login: " . facultyDisplayName($user['title'] ?? null, $user['name']) . " ({$user['role']})");

            if ($user['role'] === 'admin') redirect('/admin/dashboard.php');
            $accountStatus = $user['account_status'] ?? 'Active';
            if ($accountStatus === 'Pending Face Verification') {
                redirect('/faculty/face_verification.php');
            }
            $emailFailed = false;
            if ($accountStatus === 'Pending Email Verification') {
                // Re-send OTP on each login if still pending
                if (tableExists($conn, 'email_otps')) {
                    $otp = generateOtp($conn, (int)$user['id'], 'email_verification');
                    if (sendOtpEmail($user['email'], $user['name'], $otp)) {
                        logActivity($conn, 'EMAIL_OTP_SENT', "OTP sent to {$user['email']} on login");
                    } else {
                        logActivity($conn, 'EMAIL_SEND_FAILED', "OTP email FAILED for {$user['email']}: " . getLastMailError());
                        session_unset();
                        session_destroy();
                        session_start();
                        $error = 'Unable to send email.';
                        $emailFailed = true;
                    }
                }
                if (!$emailFailed) {
                    redirect('/faculty/email_otp.php');
                }
            }
            // Active faculty only repeat Face + OTP when this browser is not trusted.
            if (!$emailFailed && $accountStatus === 'Active') {
                if (isFacultyTrustedDevice($conn, (int)$user['id'])) {
                    redirect('/faculty/dashboard.php');
                }
                $_SESSION['pending_face_user_id'] = (int)$user['id'];
                redirect('/faculty/face_verification.php?mode=login');
            }
            if (!$emailFailed) {
                redirect('/faculty/dashboard.php');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login - Smart Room System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  :root {
    --brand-dark:  #0d1b2a;
    --brand-blue:  #1a6bcc;
    --brand-light: #e8f0fb;
    --brand-line:  #dce4ef;
  }
  * { box-sizing: border-box; }
  body {
    min-height: 100vh;
    margin: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    font-family: 'Segoe UI', system-ui, sans-serif;
    background:
      linear-gradient(rgba(13,27,42,0.78), rgba(13,27,42,0.86)),
      url('https://images.unsplash.com/photo-1497366754035-f200968a6e72?auto=format&fit=crop&w=1600&q=80') center/cover fixed;
  }
  .login-shell {
    width: min(100%, 980px);
    display: grid;
    grid-template-columns: minmax(0, 1fr) 460px;
    overflow: hidden;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 24px 64px rgba(0,0,0,0.45);
  }
  .login-panel {
    min-height: 620px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 2rem;
    color: #fff;
    background: rgba(13,27,42,0.94);
  }
  .login-brand {
    display: flex;
    align-items: center;
    gap: 0.8rem;
  }
  .icon-wrap {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    background: var(--brand-blue);
    color: #fff;
  }
  .login-brand .icon-wrap {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    font-size: 1.45rem;
  }
  .login-brand h1 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 800;
  }
  .login-brand p {
    margin: 0.15rem 0 0;
    font-size: 0.78rem;
    color: rgba(255,255,255,0.68);
  }
  .login-panel-copy h2 {
    max-width: 34rem;
    margin-bottom: 0.75rem;
    font-size: 2rem;
    font-weight: 800;
    line-height: 1.12;
  }
  .login-panel-copy p {
    max-width: 28rem;
    margin: 0;
    color: rgba(255,255,255,0.72);
  }
  .system-points {
    display: grid;
    gap: 0.65rem;
    margin-top: 1.5rem;
  }
  .system-point {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    color: rgba(255,255,255,0.82);
    font-size: 0.88rem;
  }
  .system-point i { color: #7fb3ff; }
  .login-card {
    padding: 2rem;
    background: #fff;
  }
  .mobile-brand {
    display: none;
    margin-bottom: 1.5rem;
    text-align: center;
  }
  .mobile-brand .icon-wrap {
    width: 58px;
    height: 58px;
    margin-bottom: 0.75rem;
    border-radius: 14px;
    font-size: 1.7rem;
  }
  .mobile-brand h1 {
    margin: 0;
    color: var(--brand-dark);
    font-size: 1.25rem;
    font-weight: 800;
  }
  .mobile-brand p {
    margin: 0.25rem 0 0;
    color: #6c757d;
    font-size: 0.8rem;
  }
  .section-label {
    margin-bottom: 0.6rem;
    color: #4b5563;
    font-size: .84rem;
    font-weight: 700;
  }
  .section-label .badge { border-radius: 5px; }
  .role-selector {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
    margin-bottom: 1.35rem;
  }
  .role-btn {
    min-height: 88px;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    position: relative;
    padding: 0.85rem;
    border: 1px solid var(--brand-line);
    border-radius: 8px;
    background: #f8fafc;
    cursor: pointer;
    transition: border-color .2s, background .2s, box-shadow .2s;
  }
  .role-btn input[type="radio"] { display: none; }
  .role-icon {
    width: 42px;
    height: 42px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    border-radius: 8px;
    font-size: 1.25rem;
    transition: all .2s;
  }
  .role-label {
    color: #374151;
    font-size: 0.85rem;
    font-weight: 800;
    transition: color .2s;
  }
  .role-sub {
    margin-top: 2px;
    color: #999;
    font-size: 0.7rem;
  }
  .role-btn:hover:not(.selected-admin):not(.selected-faculty) {
    border-color: #adb5bd;
    background: #fff;
  }
  .role-btn.selected-admin {
    border-color: #dc3545;
    background: #fff5f5;
    box-shadow: 0 0 0 3px rgba(220,53,69,0.12);
  }
  .role-btn.selected-admin .role-icon {
    background: #dc3545 !important;
    color: #fff !important;
  }
  .role-btn.selected-admin .role-label { color: #dc3545; }
  .role-btn.selected-faculty {
    border-color: var(--brand-blue);
    background: var(--brand-light);
    box-shadow: 0 0 0 3px rgba(26,107,204,0.12);
  }
  .role-btn.selected-faculty .role-icon {
    background: var(--brand-blue) !important;
    color: #fff !important;
  }
  .role-btn.selected-faculty .role-label { color: var(--brand-blue); }
  .check-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 20px;
    height: 20px;
    display: none;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    color: #fff;
    font-size: 0.7rem;
  }
  .role-btn.selected-admin .check-badge {
    display: flex;
    background: #dc3545;
  }
  .role-btn.selected-faculty .check-badge {
    display: flex;
    background: var(--brand-blue);
  }
  #loginFields { display: none; }
  #loginFields.visible { display: block; }
  .form-label {
    color: #333;
    font-size: 0.85rem;
    font-weight: 600;
  }
  .form-control {
    border-color: var(--brand-line);
    border-radius: 8px;
    padding: 0.65rem 0.75rem;
  }
  .form-control:focus {
    border-color: var(--brand-blue);
    box-shadow: 0 0 0 3px rgba(26,107,204,0.13);
  }
  .input-group .form-control {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
  }
  .input-group .btn {
    border-top-right-radius: 8px;
    border-bottom-right-radius: 8px;
  }
  .btn-login {
    min-height: 44px;
    border: none;
    font-weight: 700;
  }
  .role-prompt {
    padding: 0.5rem 0;
    color: #8a94a6;
    text-align: center;
    font-size: 0.82rem;
  }
  .helper-text {
    margin-top: 0.4rem;
    color: #6b7280;
    font-size: 0.76rem;
  }
  @media (max-width: 860px) {
    body {
      align-items: flex-start;
      padding: 1rem;
    }
    .login-shell {
      display: block;
      max-width: 460px;
    }
    .login-panel { display: none; }
    .login-card { padding: 1.5rem; }
    .mobile-brand { display: block; }
  }
  @media (max-width: 430px) {
    .role-selector { grid-template-columns: 1fr; }
    .login-card { padding: 1.2rem; }
  }
</style>
</head>
<body>

<div class="login-shell">
  <section class="login-panel">
    <div class="login-brand">
      <div class="icon-wrap"><i class="bi bi-building-lock"></i></div>
      <div>
        <h1>Smart Room System</h1>
        <p>RFID-Based Room Availability Monitor</p>
      </div>
    </div>

    <div class="login-panel-copy">
      <h2>Room access and class confirmation in one place.</h2>
      <p>Monitor availability, faculty responses, check-ins, and room usage from a secure dashboard.</p>
      <div class="system-points">
        <div class="system-point"><i class="bi bi-check-circle-fill"></i><span>Live room status tracking</span></div>
        <div class="system-point"><i class="bi bi-check-circle-fill"></i><span>Faculty confirmation workflow</span></div>
        <div class="system-point"><i class="bi bi-check-circle-fill"></i><span>Admin reports and attendance records</span></div>
      </div>
    </div>

    <div class="small text-white-50">
      <i class="bi bi-shield-check me-1"></i>Authorized users only
    </div>
  </section>

  <main class="login-card">
    <div class="mobile-brand">
      <div class="icon-wrap"><i class="bi bi-building-lock"></i></div>
      <h1>Smart Room System</h1>
      <p>RFID-Based Room Availability Monitor</p>
    </div>

    <div class="mb-4">
      <h2 class="h4 fw-bold text-dark mb-1">Sign in</h2>
      <div class="text-muted small">Choose your role and enter your credentials.</div>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success d-flex align-items-center gap-2 py-2 mb-3">
      <i class="bi bi-check-circle-fill"></i>
      <span><?= htmlspecialchars($success) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-3" role="alert">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
      <span><?= $error ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" id="loginForm" novalidate>
      <p class="section-label">
        <span class="badge bg-secondary me-1">1</span> Select your role
      </p>

      <div class="role-selector">
        <label class="role-btn <?= $selectedRole === 'admin' ? 'selected-admin' : '' ?>" id="btnAdmin">
          <input type="radio" name="role" value="admin" <?= $selectedRole === 'admin' ? 'checked' : '' ?>>
          <span class="check-badge"><i class="bi bi-check"></i></span>
          <span class="role-icon bg-light text-danger"><i class="bi bi-shield-lock-fill"></i></span>
          <span>
            <span class="role-label d-block">Admin</span>
            <span class="role-sub d-block">System Administrator</span>
          </span>
        </label>

        <label class="role-btn <?= $selectedRole === 'faculty' ? 'selected-faculty' : '' ?>" id="btnFaculty">
          <input type="radio" name="role" value="faculty" <?= $selectedRole === 'faculty' ? 'checked' : '' ?>>
          <span class="check-badge"><i class="bi bi-check"></i></span>
          <span class="role-icon bg-light text-primary"><i class="bi bi-person-badge-fill"></i></span>
          <span>
            <span class="role-label d-block">Faculty</span>
            <span class="role-sub d-block">Teaching Staff</span>
          </span>
        </label>
      </div>

      <div id="loginFields" class="<?= $selectedRole ? 'visible' : '' ?>">
        <p class="section-label">
          <span class="badge bg-secondary me-1">2</span> Enter your credentials
        </p>

        <div class="mb-3">
          <label class="form-label" for="email"><i class="bi bi-envelope me-1"></i>Username or Email Address</label>
          <input type="email" class="form-control" id="email" name="email"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                 placeholder="you@school.edu" autocomplete="email" inputmode="email">
          <div class="helper-text">Use the username or email provided after approval.</div>
        </div>

        <div class="mb-4">
          <label class="form-label" for="password"><i class="bi bi-lock me-1"></i>Password</label>
          <div class="input-group">
            <input type="password" class="form-control" id="password" name="password"
                   placeholder="Enter your password" autocomplete="current-password">
            <button class="btn btn-outline-secondary" type="button" id="togglePw" aria-label="Show password">
              <i class="bi bi-eye" id="eyeIcon"></i>
            </button>
          </div>
        </div>

        <div class="d-grid">
          <button type="submit" class="btn btn-primary btn-login py-2 text-white" id="submitBtn">
            <i class="bi bi-box-arrow-in-right me-1" id="submitIcon"></i>
            <span id="submitLabel">Sign In</span>
          </button>
        </div>
        <div class="text-center mt-2">
    <a href="/forgot_password.php" class="text-muted small text-decoration-none">
        <i class="bi bi-question-circle me-1"></i>Forgot your password?
    </a>
</div>
        <div class="text-center mt-2">
          <a href="/request_registration.php" class="text-muted small text-decoration-none">
            <i class="bi bi-person-plus me-1"></i>Request Faculty Registration
          </a>
        </div>
      </div>

      <?php if (!$selectedRole): ?>
      <div class="role-prompt" id="rolePrompt">Choose Admin or Faculty to continue.</div>
      <?php endif; ?>
    </form>

  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const btnAdmin    = document.getElementById('btnAdmin');
const btnFaculty  = document.getElementById('btnFaculty');
const loginFields = document.getElementById('loginFields');
const rolePrompt  = document.getElementById('rolePrompt');
const submitBtn   = document.getElementById('submitBtn');
const submitLabel = document.getElementById('submitLabel');
const submitIcon  = document.getElementById('submitIcon');

function selectRole(role) {
  btnAdmin.classList.remove('selected-admin');
  btnFaculty.classList.remove('selected-faculty');

  if (role === 'admin') {
    btnAdmin.classList.add('selected-admin');
    btnAdmin.querySelector('input').checked = true;
    submitBtn.className = 'btn btn-danger btn-login py-2 text-white w-100';
    submitLabel.textContent = 'Sign In as Admin';
  } else {
    btnFaculty.classList.add('selected-faculty');
    btnFaculty.querySelector('input').checked = true;
    submitBtn.className = 'btn btn-primary btn-login py-2 text-white w-100';
    submitLabel.textContent = 'Sign In as Faculty';
  }

  loginFields.classList.add('visible');
  if (rolePrompt) rolePrompt.style.display = 'none';
  setTimeout(() => document.getElementById('email').focus(), 100);
}

btnAdmin.addEventListener('click', () => selectRole('admin'));
btnFaculty.addEventListener('click', () => selectRole('faculty'));

<?php if ($selectedRole): ?>
selectRole(<?= json_encode($selectedRole) ?>);
<?php endif; ?>

document.getElementById('togglePw').addEventListener('click', function () {
  const pw = document.getElementById('password');
  const icon = document.getElementById('eyeIcon');
  const isHidden = pw.type === 'password';
  pw.type = isHidden ? 'text' : 'password';
  icon.className = isHidden ? 'bi bi-eye-slash' : 'bi bi-eye';
  this.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
});

document.getElementById('loginForm').addEventListener('submit', function (e) {
  const role = document.querySelector('input[name="role"]:checked');
  const email = document.getElementById('email');
  const pass = document.getElementById('password');

  if (!role) {
    e.preventDefault();
    alert('Please select your role first.');
    return;
  }

  if (!email.value.trim() || !pass.value.trim()) {
    e.preventDefault();
    alert('Please enter your email and password.');
    if (!email.value.trim()) email.focus();
    else pass.focus();
    return;
  }

  submitBtn.disabled = true;
  submitIcon.className = 'spinner-border spinner-border-sm me-2';
  submitLabel.textContent = 'Signing in...';
});
</script>
</body>
</html>
