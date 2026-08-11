<?php
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('faculty');

$pageTitle = 'Change Password';
$userId    = $_SESSION['user_id'];
$errors    = [];
$success   = false;

$stmt = $conn->prepare("SELECT password, name FROM users WHERE id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = trim($_POST['current_password'] ?? '');
    $new     = trim($_POST['new_password']     ?? '');
    $confirm = trim($_POST['confirm_password'] ?? '');

    if (empty($current) || !password_verify($current, $user['password'])) {
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
        $fields = ['password = ?'];
        $types = 's';
        $values = [$hash];
        if (columnExists($conn, 'users', 'first_login')) {
            $fields[] = 'first_login = 0';
        }
        if (columnExists($conn, 'users', 'must_change_password')) {
            $fields[] = 'must_change_password = 0';
        }
        if (columnExists($conn, 'users', 'password_changed_at')) {
            $fields[] = 'password_changed_at = NOW()';
        }
        $values[] = $userId;
        $types .= 'i';
        $upd  = $conn->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?");
        $upd->bind_param($types, ...$values);
        $upd->execute();
        $upd->close();

        unset($_SESSION['pending_otp_user_id']);
        unset($_SESSION['pending_face_user_id']);
        if (!isFacultyTrustedDevice($conn, (int)$userId)) {
            rememberFacultyTrustedDevice($conn, (int)$userId);
        }
        logActivity($conn, 'CHANGE_PASSWORD', "Faculty changed their own password: {$user['name']}");
        setFlash('success', 'Your password has been changed successfully.');
        redirect('/faculty/dashboard.php');
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
          <ul class="mb-0 ps-3"><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul>
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
            <div class="form-text">✓ 8+ characters &nbsp; ✓ Uppercase &nbsp; ✓ Lowercase &nbsp; ✓ Number &nbsp; ✓ Special character</div>
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
</script>
</body></html>
