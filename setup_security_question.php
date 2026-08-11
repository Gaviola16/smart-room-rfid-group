<?php
require_once __DIR__ . '/db.php';
session_start();
requireLogin(); 

$userId   = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];
$error    = '';
$success  = '';

$dashboardUrl = $userRole === 'admin'
    ? '/admin/dashboard.php'
    : '/faculty/dashboard.php';

$profileUrl = $userRole === 'admin'
    ? '/admin/profile.php'
    : '/faculty/profile.php';

$questions = [
    "What was the name of your first pet?",
    "What is your mother's maiden name?",
    "What city were you born in?",
    "What was the name of your elementary school?",
    "What is the name of the street you grew up on?",
    "What was your childhood nickname?",
    "What is your oldest sibling's middle name?",
    "What was the make and model of your first car?",
    "What is the name of the hospital where you were born?",
    "What was the name of your favorite childhood friend?",
];

$stmt = $conn->prepare("SELECT security_question, security_answer FROM users WHERE id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$currentQuestion = $row['security_question'] ?? '';
$hasAnswer       = !empty($row['security_answer']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $question = trim($_POST['security_question'] ?? '');
    $answer   = trim($_POST['security_answer']   ?? '');
    $confirm  = trim($_POST['confirm_answer']    ?? '');

    if (empty($question) || !in_array($question, $questions, true)) {
        $error = 'Please select a valid security question.';
    } elseif (strlen($answer) < 2) {
        $error = 'Your answer must be at least 2 characters.';
    } elseif (strtolower($answer) !== strtolower($confirm)) {
        $error = 'Answers do not match. Please re-enter.';
    } else {
        // Store new security answers hashed; forgot_password.php still supports older plain answers.
        $stored = password_hash($answer, PASSWORD_DEFAULT);
        $upd = $conn->prepare(
            "UPDATE users SET security_question = ?, security_answer = ? WHERE id = ?"
        );
        $upd->bind_param('ssi', $question, $stored, $userId);
        $upd->execute();
        $upd->close();

        logActivity($conn, 'UPDATE_PROFILE', 'Security question updated.');
        $success         = 'Security question saved successfully!';
        $currentQuestion = $question;
        $hasAnswer       = true;
    }
}

$pageTitle = 'Security Question Setup';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $pageTitle ?> – Smart Room System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  :root { --brand-blue: #1a6bcc; }
  body { background: #f4f6f9; font-family: 'Segoe UI', system-ui, sans-serif; }
  .sq-card {
    max-width: 600px;
    margin: 3rem auto;
    background: #fff;
    border-radius: 14px;
    padding: 2rem;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
  }
  .sq-icon {
    width: 52px; height: 52px;
    background: linear-gradient(135deg, var(--brand-blue), #1040a0);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 1.4rem;
    margin-bottom: 1rem;
  }
  .form-select:focus,
  .form-control:focus { border-color: var(--brand-blue); box-shadow: 0 0 0 .2rem rgba(26,107,204,.15); }
  .status-badge {
    display: inline-flex; align-items: center; gap: .35rem;
    font-size: .8rem; padding: .3rem .7rem; border-radius: 999px;
  }
</style>
</head>
<body>

<div class="sq-card">
  <div class="sq-icon"><i class="bi bi-shield-check"></i></div>
  <h2 class="h5 fw-bold mb-1">Security Question Setup</h2>
  <p class="text-muted small mb-3">
    Set a security question to enable password recovery if you forget your password.
  </p>

  <!-- Current Status -->
  <div class="mb-3">
    <?php if ($hasAnswer): ?>
    <span class="status-badge bg-success-subtle text-success border border-success-subtle">
      <i class="bi bi-check-circle-fill"></i> Security question is set
    </span>
    <?php else: ?>
    <span class="status-badge bg-warning-subtle text-warning border border-warning-subtle">
      <i class="bi bi-exclamation-circle-fill"></i> No security question set – you cannot use Forgot Password yet
    </span>
    <?php endif; ?>
  </div>

  <!-- Alerts -->
  <?php if ($error): ?>
  <div class="alert alert-danger py-2 d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?= htmlspecialchars($error) ?>
  </div>
  <?php endif; ?>

  <?php if ($success): ?>
  <div class="alert alert-success py-2 d-flex align-items-center gap-2">
    <i class="bi bi-check-circle-fill"></i>
    <?= htmlspecialchars($success) ?>
  </div>
  <?php endif; ?>

  <form method="POST" novalidate>
    <div class="mb-3">
      <label class="form-label fw-semibold" for="security_question">
        <i class="bi bi-question-circle me-1"></i>Security Question
      </label>
      <select class="form-select" id="security_question" name="security_question" required>
        <option value="">— Select a question —</option>
        <?php foreach ($questions as $q): ?>
        <option value="<?= htmlspecialchars($q) ?>"
          <?= ($currentQuestion === $q) ? 'selected' : '' ?>>
          <?= htmlspecialchars($q) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="mb-3">
      <label class="form-label fw-semibold" for="security_answer">
        <i class="bi bi-key me-1"></i>Your Answer
      </label>
      <input type="text" class="form-control" id="security_answer" name="security_answer"
             placeholder="Enter your answer" autocomplete="off" required>
      <div class="form-text">Answers are not case-sensitive.</div>
    </div>

    <div class="mb-4">
      <label class="form-label fw-semibold" for="confirm_answer">
        <i class="bi bi-key-fill me-1"></i>Confirm Answer
      </label>
      <input type="text" class="form-control" id="confirm_answer" name="confirm_answer"
             placeholder="Re-enter your answer" autocomplete="off" required>
      <div class="form-text" id="matchMsg"></div>
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-primary px-4">
        <i class="bi bi-save me-1"></i>Save Security Question
      </button>
      <a href="<?= $profileUrl ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Profile
      </a>
    </div>
  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const answerInput  = document.getElementById('security_answer');
const confirmInput = document.getElementById('confirm_answer');
const matchMsg     = document.getElementById('matchMsg');

function checkMatch() {
  if (!confirmInput.value) { matchMsg.textContent = ''; return; }
  const match = answerInput.value.toLowerCase() === confirmInput.value.toLowerCase();
  matchMsg.textContent = match ? '✓ Answers match' : '✗ Answers do not match';
  matchMsg.style.color = match ? '#198754' : '#dc3545';
}

answerInput.addEventListener('input', checkMatch);
confirmInput.addEventListener('input', checkMatch);
</script>
</body>
</html>
