<?php
/**
 * faculty/email_otp.php
 * Handles two modes:
 *   mode=login  — 2FA OTP after a successful credential login (login_otp purpose)
 *   (default)   — Initial email verification for new accounts (email_verification purpose)
 *
 * The dashboard remains locked until the OTP is verified.
 * OTP expires in 5 minutes. Resend allowed after 60 seconds.
 */
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('faculty');

$facultyId = (int)$_SESSION['user_id'];
$faculty   = getFacultyOnboarding($conn, $facultyId);
if (!$faculty) redirect('/logout.php');

// Determine mode
$mode = $_GET['mode'] ?? $_POST['mode'] ?? 'verify';
// login mode: active faculty who need 2FA
// verify mode: new faculty completing email_verification onboarding

$isLoginOtp = ($mode === 'login');
$purpose    = $isLoginOtp ? 'login_otp' : 'email_verification';

// Guard: non-login-otp mode must have Pending Email Verification status
if (!$isLoginOtp && ($faculty['account_status'] ?? '') !== 'Pending Email Verification') {
    redirect('/faculty/dashboard.php');
}

// Guard: login-otp mode must have pending_otp_user_id in session
if ($isLoginOtp && !isset($_SESSION['pending_otp_user_id'])) {
    redirect('/faculty/dashboard.php');
}

$pageTitle = $isLoginOtp ? 'Two-Factor Verification' : 'Email Verification';
$errors    = [];
$success   = '';

// ── Resend ────────────────────────────────────────────────────────────────────
if (isset($_GET['resend'])) {
    if (!tableExists($conn, 'email_otps')) {
        setFlash('danger', 'OTP system not initialized. Contact administrator.');
        redirect("/faculty/email_otp.php?mode=$mode");
    }
    // Enforce 60-second cooldown: check latest OTP created_at
    $cool = $conn->prepare("SELECT created_at FROM email_otps WHERE user_id=? AND purpose=? ORDER BY id DESC LIMIT 1");
    $cool->bind_param('is', $facultyId, $purpose);
    $cool->execute();
    $lastRow = $cool->get_result()->fetch_assoc();
    $cool->close();
    if ($lastRow && (time() - strtotime($lastRow['created_at'])) < 60) {
        setFlash('warning', 'Please wait 60 seconds before requesting a new code.');
        redirect("/faculty/email_otp.php?mode=$mode");
    }
    $otp = generateOtp($conn, $facultyId, $purpose);
    if (!sendOtpEmail($faculty['email'], $faculty['name'], $otp)) {
        logActivity($conn, 'EMAIL_SEND_FAILED', "OTP resend FAILED for {$faculty['email']}: " . getLastMailError());
        setFlash('danger', 'Unable to send email. [DEBUG] ' . getLastMailError());
        redirect("/faculty/email_otp.php?mode=$mode");
    }
    logActivity($conn, 'EMAIL_OTP_RESENT', "OTP resent ({$purpose}) to {$faculty['email']}");
    setFlash('info', 'A new 6-digit code has been sent to your email.');
    redirect("/faculty/email_otp.php?mode=$mode");
}

// ── Verify ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedOtp = trim(preg_replace('/\D/', '', $_POST['otp_code'] ?? ''));

    if (strlen($submittedOtp) !== 6) {
        $errors[] = 'Please enter the 6-digit code.';
    } elseif (!tableExists($conn, 'email_otps')) {
        $errors[] = 'OTP system not initialized. Contact administrator.';
    } else {
        $result = verifyOtp($conn, $facultyId, $submittedOtp, $purpose);

        if ($result === 'ok') {
            logActivity($conn, 'EMAIL_OTP_SUCCESS', "OTP verified ({$purpose}) for {$faculty['name']} ({$faculty['email']})");

            if ($isLoginOtp) {
                // Clear the pending flag and proceed to dashboard
                unset($_SESSION['pending_otp_user_id']);
                rememberFacultyTrustedDevice($conn, $facultyId);
                if (facultyMustChangePassword($conn, $faculty)) {
                    setFlash('warning', 'Please change your temporary password before opening the dashboard.');
                    redirect('/faculty/change_password.php');
                }
                setFlash('success', 'Identity verified. Welcome, ' . $faculty['name'] . '!');
                redirect('/faculty/dashboard.php');
            } else {
                // Mark email verified and activate account
                $fields = [];
                if (columnExists($conn, 'users', 'email_verified'))    $fields[] = 'email_verified = 1';
                if (columnExists($conn, 'users', 'email_verified_at')) $fields[] = 'email_verified_at = NOW()';
                if (columnExists($conn, 'users', 'account_status'))    $fields[] = "account_status = 'Active'";
                $fields[] = 'is_active = 1';

                if ($fields) {
                    $upd = $conn->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?");
                    $upd->bind_param('i', $facultyId);
                    $upd->execute();
                    $upd->close();
                }

                logActivity($conn, 'FACULTY_ACTIVATED', "Faculty account activated: {$faculty['name']}");
                pushNotification($conn, 'Faculty Account Activated',
                    "{$faculty['name']} completed email verification and is now active.",
                    'success', '/admin/faculty/index.php');

                rememberFacultyTrustedDevice($conn, $facultyId);
                if (facultyMustChangePassword($conn, $faculty)) {
                    setFlash('warning', 'Email verified. Please change your temporary password before opening the dashboard.');
                    redirect('/faculty/change_password.php');
                }
                setFlash('success', 'Email verified successfully! Welcome to Smart Room.');
                redirect('/faculty/dashboard.php');
            }

        } elseif ($result === 'expired') {
            $errors[] = 'Your verification code has expired. Please request a new one.';
            logActivity($conn, 'EMAIL_OTP_EXPIRED', "Expired OTP ({$purpose}) used by {$faculty['name']}");
        } else {
            $errors[] = 'Invalid verification code. Please check your email and try again.';
            logActivity($conn, 'EMAIL_OTP_FAILED', "Wrong OTP ({$purpose}) by {$faculty['name']} ({$faculty['email']})");
        }
    }
}

// Mask email
$emailParts = explode('@', $faculty['email']);
$masked = substr($emailParts[0], 0, 2) . str_repeat('*', max(2, strlen($emailParts[0]) - 2))
        . '@' . ($emailParts[1] ?? '');

$flash = getFlash();
include __DIR__ . '/../authentication.php';
?>

<div class="row justify-content-center">
  <div class="col-xl-5 col-lg-6">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
      <div>
        <h5 class="mb-0 fw-bold">
          <i class="bi bi-envelope-check text-primary me-2"></i>
          <?= $isLoginOtp ? 'Two-Factor Verification' : 'Email Verification' ?>
        </h5>
        <div class="text-muted small">
          <?= $isLoginOtp ? 'Confirm your identity with a one-time code' : 'Step 2 of 2 — Verify your email address' ?>
        </div>
      </div>
      <span class="badge <?= $isLoginOtp ? 'bg-primary' : 'bg-info text-dark' ?>">
        <?= $isLoginOtp ? '2FA Required' : 'Pending Verification' ?>
      </span>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show py-2" role="alert">
      <i class="bi bi-<?= $flash['type']==='info'?'info-circle':'check-circle' ?> me-1"></i>
      <?= htmlspecialchars($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Verification Failed</strong>
      <ul class="mb-0 ps-3 mt-1"><?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?></ul>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-body text-center py-4">
        <div class="mb-3">
          <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle"
               style="width:72px;height:72px;">
            <i class="bi bi-envelope-open text-primary" style="font-size:2rem;"></i>
          </div>
        </div>
        <h6 class="fw-bold mb-1">Check Your Email</h6>
        <p class="text-muted small mb-3">
          We sent a 6-digit code to<br><strong><?= htmlspecialchars($masked) ?></strong>
        </p>

        <form method="POST" class="text-start">
          <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
          <div class="mb-3">
            <label class="form-label fw-semibold text-center w-100">Enter 6-Digit Code</label>
            <input type="text" name="otp_code" id="otp_input"
                   class="form-control form-control-lg text-center fw-bold"
                   placeholder="000000" maxlength="6" inputmode="numeric"
                   pattern="\d{6}" autocomplete="one-time-code"
                   style="font-size:1.75rem;letter-spacing:.4rem;"
                   autofocus required>
            <div class="form-text text-center mt-1">
              <i class="bi bi-clock me-1"></i>Code expires in
              <span id="countdown" class="fw-semibold text-warning">5:00</span>
            </div>
          </div>
          <div class="d-grid">
            <button type="submit" class="btn btn-primary btn-lg">
              <i class="bi bi-check-circle me-1"></i>Verify Code
            </button>
          </div>
        </form>

        <hr class="my-3">
        <p class="mb-0 small text-muted">Didn't receive the code?</p>
        <a href="email_otp.php?resend=1&mode=<?= htmlspecialchars($mode) ?>" class="btn btn-outline-secondary btn-sm mt-2"
           id="resendBtn"
           onclick="return confirm('Send a new code? The previous code will be invalidated.')">
          <i class="bi bi-arrow-repeat me-1"></i>Resend Code
        </a>
        <div class="small text-muted mt-1" id="resendCooldown" style="display:none;">
          Resend available in <span id="resendTimer">60</span>s
        </div>
      </div>
    </div>
  </div>
</div>

  </div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('show');document.getElementById('sidebarOverlay').classList.toggle('show');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('show');document.getElementById('sidebarOverlay').classList.remove('show');}

// Digits only
document.getElementById('otp_input').addEventListener('input', function(){
  this.value = this.value.replace(/\D/g,'').slice(0,6);
});

// 5-minute countdown
let secs = 300;
const cd = document.getElementById('countdown');
const otpTimer = setInterval(() => {
  secs--;
  if (secs <= 0) {
    clearInterval(otpTimer);
    cd.textContent = 'Expired';
    cd.className = 'fw-semibold text-danger';
    return;
  }
  cd.textContent = Math.floor(secs/60) + ':' + String(secs%60).padStart(2,'0');
  if (secs <= 60) cd.className = 'fw-semibold text-danger';
}, 1000);

// 60-second resend cooldown (client-side only)
let resendSecs = 60;
const resendBtn  = document.getElementById('resendBtn');
const resendCD   = document.getElementById('resendCooldown');
const resendTxt  = document.getElementById('resendTimer');
resendBtn.style.display = 'none';
resendCD.style.display  = 'block';
const resendTimer = setInterval(() => {
  resendSecs--;
  resendTxt.textContent = resendSecs;
  if (resendSecs <= 0) {
    clearInterval(resendTimer);
    resendBtn.style.display = '';
    resendCD.style.display  = 'none';
  }
}, 1000);
</script>
</body></html>
