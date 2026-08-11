<?php
require_once __DIR__ . '/db.php';
session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['user_role'] ?? '';
    redirect($role === 'admin' ? '/admin/dashboard.php' : '/faculty/dashboard.php');
}

$step    = $_SESSION['fp_step']    ?? 1;   // 1 = email, 2 = security question, 3 = done
$error   = '';
$success = '';

/* ------------------------------------------------------------------ */
/* STEP 1 – Find the account by email                                  */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'find_email') {
        $email = trim($_POST['email'] ?? '');
        $role  = trim($_POST['role']  ?? '');

        if (!in_array($role, ['admin', 'faculty'], true)) {
            $error = 'Please select your role.';
        } elseif (empty($email)) {
            $error = 'Please enter your email address.';
        } else {
            $stmt = $conn->prepare(
                "SELECT id, name, role, security_question, security_answer
                 FROM users
                 WHERE email = ? AND role = ? AND is_active = 1
                 LIMIT 1"
            );
            $stmt->bind_param('ss', $email, $role);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user) {
                $error = 'No active account found with that email and role combination.';
            } elseif (empty($user['security_question']) || empty($user['security_answer'])) {
                $error = 'This account does not have a security question set up. Please contact the administrator to reset your password.';
            } else {
                // Store in session so step 2 can use it
                $_SESSION['fp_step']     = 2;
                $_SESSION['fp_user_id']  = $user['id'];
                $_SESSION['fp_email']    = $email;
                $_SESSION['fp_question'] = $user['security_question'];
                $_SESSION['fp_name']     = $user['name'];
                $step = 2;
                $success = 'Account found! Please answer your security question below.';
            }
        }

    } elseif ($_POST['action'] === 'verify_answer') {
        if ($step !== 2 || empty($_SESSION['fp_user_id'])) {
            redirect('/forgot_password.php');
        }

        $answer = strtolower(trim($_POST['security_answer'] ?? ''));

        if (empty($answer)) {
            $error = 'Please provide your security answer.';
        } else {
            $stmt = $conn->prepare("SELECT security_answer FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $_SESSION['fp_user_id']);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $storedRaw = trim($row['security_answer'] ?? '');
            $isHashedAnswer = password_get_info($storedRaw)['algo'] !== 0;
            $answerMatches = $isHashedAnswer
                ? password_verify(trim($_POST['security_answer'] ?? ''), $storedRaw)
                : ($answer === strtolower($storedRaw));

            if (!$answerMatches) {
                $error = 'Incorrect answer. Please try again.';
            } else {
                // Generate a reset token valid for 15 minutes and email it through PHPMailer.
                $token     = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

                $upd = $conn->prepare(
                    "UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?"
                );
                $upd->bind_param('ssi', $token, $expiresAt, $_SESSION['fp_user_id']);
                $upd->execute();
                $upd->close();

                $resetLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                           . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                           . '/reset_password.php?token=' . urlencode($token);

                if (!sendPasswordResetEmail($_SESSION['fp_email'], $_SESSION['fp_name'], $resetLink)) {
                    logActivity($conn, 'EMAIL_SEND_FAILED', "Password reset email FAILED for {$_SESSION['fp_email']}: " . getLastMailError());
                    $clear = $conn->prepare("UPDATE users SET reset_token = NULL, reset_token_expires = NULL WHERE id = ?");
                    if ($clear) {
                        $clear->bind_param('i', $_SESSION['fp_user_id']);
                        $clear->execute();
                        $clear->close();
                    }
                    $error = 'Unable to send email.';
                } else {
                    logActivity($conn, 'RESET_PASSWORD', "Password reset email sent to {$_SESSION['fp_email']}");

                    // Clean up session keys after the reset link has been sent.
                    unset($_SESSION['fp_step'], $_SESSION['fp_user_id'],
                          $_SESSION['fp_question'], $_SESSION['fp_email'], $_SESSION['fp_name']);

                    $step = 1;
                    $success = 'Password reset link sent. Please check your email.';
                }
            }
        }

    } elseif ($_POST['action'] === 'reset_step') {
        unset($_SESSION['fp_step'], $_SESSION['fp_user_id'],
              $_SESSION['fp_question'], $_SESSION['fp_email'], $_SESSION['fp_name']);
        redirect('/forgot_password.php');
    }
}

$fpQuestion = $_SESSION['fp_question'] ?? '';
$fpName     = $_SESSION['fp_name']     ?? '';
$fpEmail    = $_SESSION['fp_email']    ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot Password – Smart Room System</title>
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
  .fp-card {
    width: 100%;
    max-width: 480px;
    background: #fff;
    border-radius: 16px;
    padding: 2.25rem 2rem;
    box-shadow: 0 20px 60px rgba(0,0,0,0.35);
  }
  .fp-icon {
    width: 56px; height: 56px;
    background: linear-gradient(135deg, var(--brand-blue), #1552a0);
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 1.5rem;
    margin-bottom: 1rem;
  }
  .step-indicator {
    display: flex;
    gap: .5rem;
    margin-bottom: 1.5rem;
  }
  .step-dot {
    height: 4px;
    flex: 1;
    border-radius: 2px;
    background: #e2e8f0;
    transition: background .3s;
  }
  .step-dot.active   { background: var(--brand-blue); }
  .step-dot.complete { background: #198754; }
  .form-control:focus { border-color: var(--brand-blue); box-shadow: 0 0 0 .25rem rgba(26,107,204,.15); }
  .btn-primary { background: var(--brand-blue); border-color: var(--brand-blue); }
  .btn-primary:hover { background: #1552a0; border-color: #1552a0; }
  .question-box {
    background: #f0f6ff;
    border: 1px solid #c8dff7;
    border-radius: 10px;
    padding: .9rem 1rem;
    margin-bottom: 1.25rem;
    font-style: italic;
    color: #1a3657;
  }
  .back-link {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    color: #6c757d;
    font-size: .875rem;
    text-decoration: none;
    margin-bottom: 1.25rem;
  }
  .back-link:hover { color: var(--brand-blue); }
  @media (max-width: 480px) {
    .fp-card { padding: 1.5rem 1.25rem; }
  }
</style>
</head>
<body>

<div class="fp-card">
  <div class="fp-icon"><i class="bi bi-shield-lock"></i></div>

  <!-- Step Indicator -->
  <div class="step-indicator">
    <div class="step-dot <?= $step >= 1 ? ($step > 1 ? 'complete' : 'active') : '' ?>"></div>
    <div class="step-dot <?= $step >= 2 ? ($step > 2 ? 'complete' : 'active') : '' ?>"></div>
    <div class="step-dot <?= $step >= 3 ? 'active' : '' ?>"></div>
  </div>

  <h2 class="h5 fw-bold mb-1">Forgot Password</h2>

  <?php if ($step === 1): ?>
  <p class="text-muted small mb-4">Enter your registered email and role to find your account.</p>
  <?php elseif ($step === 2): ?>
  <p class="text-muted small mb-4">Answer your security question to verify your identity.</p>
  <?php endif; ?>

  <!-- Alerts -->
  <?php if ($error): ?>
  <div class="alert alert-danger d-flex align-items-center gap-2 py-2">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
    <span><?= htmlspecialchars($error) ?></span>
  </div>
  <?php endif; ?>

  <?php if ($success): ?>
  <div class="alert alert-success d-flex align-items-center gap-2 py-2">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i>
    <span><?= htmlspecialchars($success) ?></span>
  </div>
  <?php endif; ?>

  <!-- ============================================================ -->
  <!-- STEP 1: Email + Role                                          -->
  <!-- ============================================================ -->
  <?php if ($step === 1): ?>
  <form method="POST" novalidate>
    <input type="hidden" name="action" value="find_email">

    <div class="mb-3">
      <label class="form-label fw-semibold"><i class="bi bi-person-badge me-1"></i>Role</label>
      <div class="d-flex gap-3">
        <div class="form-check">
          <input class="form-check-input" type="radio" name="role" id="roleAdmin" value="admin"
                 <?= (($_POST['role'] ?? '') === 'admin') ? 'checked' : '' ?> required>
          <label class="form-check-label" for="roleAdmin">Admin</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="role" id="roleFaculty" value="faculty"
                 <?= (($_POST['role'] ?? '') === 'faculty') ? 'checked' : '' ?> required>
          <label class="form-check-label" for="roleFaculty">Faculty</label>
        </div>
      </div>
    </div>

    <div class="mb-4">
      <label class="form-label fw-semibold" for="email">
        <i class="bi bi-envelope me-1"></i>Email Address
      </label>
      <input type="email" class="form-control" id="email" name="email"
             value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
             placeholder="you@school.edu" autocomplete="email" required>
    </div>

    <div class="d-grid mb-3">
      <button type="submit" class="btn btn-primary py-2">
        <i class="bi bi-search me-1"></i>Find My Account
      </button>
    </div>
  </form>

  <!-- ============================================================ -->
  <!-- STEP 2: Security Question                                     -->
  <!-- ============================================================ -->
  <?php elseif ($step === 2): ?>
  <div class="mb-3 text-muted small">
    <i class="bi bi-person-circle me-1"></i>
    Signed in as: <strong><?= htmlspecialchars($fpName) ?></strong>
    (<?= htmlspecialchars($fpEmail) ?>)
  </div>

  <div class="question-box">
    <i class="bi bi-question-circle me-1 text-primary"></i>
    <strong>Security Question:</strong><br>
    <?= htmlspecialchars($fpQuestion) ?>
  </div>

  <form method="POST" novalidate>
    <input type="hidden" name="action" value="verify_answer">

    <div class="mb-4">
      <label class="form-label fw-semibold" for="security_answer">
        <i class="bi bi-key me-1"></i>Your Answer
      </label>
      <input type="text" class="form-control" id="security_answer" name="security_answer"
             placeholder="Enter your answer (case-insensitive)" autocomplete="off" required>
      <div class="form-text">Answers are not case-sensitive.</div>
    </div>

    <div class="d-grid mb-3">
      <button type="submit" class="btn btn-primary py-2">
        <i class="bi bi-check-circle me-1"></i>Verify Answer
      </button>
    </div>
  </form>

  <form method="POST">
    <input type="hidden" name="action" value="reset_step">
    <button type="submit" class="back-link border-0 bg-transparent p-0">
      <i class="bi bi-arrow-left"></i> Use a different email
    </button>
  </form>
  <?php endif; ?>

  <div class="text-center mt-3">
    <a href="/index.php" class="text-decoration-none text-muted small">
      <i class="bi bi-arrow-left me-1"></i>Back to Login
    </a>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
