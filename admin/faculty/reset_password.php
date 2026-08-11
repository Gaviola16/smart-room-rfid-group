<?php
require_once __DIR__ . '/../../db.php';
session_start(); requireLogin('admin');
$pageTitle = 'Reset Faculty Password';
$errors = [];
$id = intval($_GET['id'] ?? 0);
if (!$id) { setFlash('danger','Invalid ID.'); redirect('/admin/faculty/index.php'); }

$titleSelect = columnExists($conn, 'users', 'title') ? ',title' : '';
$stmt = $conn->prepare("SELECT id,name,email{$titleSelect} FROM users WHERE id=? AND role='faculty'");
$stmt->bind_param('i',$id); $stmt->execute();
$f = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$f) { setFlash('danger','Faculty not found.'); redirect('/admin/faculty/index.php'); }
$displayName = facultyDisplayName($f['title'] ?? null, $f['name']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw  = trim($_POST['password']  ?? '');
    $pw2 = trim($_POST['password2'] ?? '');
    $errors = array_merge($errors, validatePasswordStrength($pw, ['name' => $f['name'], 'email' => $f['email']]));
    if ($pw !== $pw2)       $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $fields = ['password=?'];
        if (columnExists($conn, 'users', 'first_login')) $fields[] = 'first_login=1';
        if (columnExists($conn, 'users', 'must_change_password')) $fields[] = 'must_change_password=1';
        if (columnExists($conn, 'users', 'password_changed_at')) $fields[] = 'password_changed_at=NULL';
        $upd = $conn->prepare("UPDATE users SET " . implode(',', $fields) . " WHERE id=?");
        $upd->bind_param('si',$hash,$id); $upd->execute(); $upd->close();
        invalidateFacultyTrustedDevices($conn, $id);
        logActivity($conn,'RESET_PASSWORD',"Password reset for: {$displayName} (ID:$id)");
        setFlash('success',"Password for «{$displayName}» has been reset successfully.");
        redirect('/admin/faculty/index.php');
    }
}
include __DIR__ . '/../../authentication.php';
?>
<div class="row justify-content-center">
  <div class="col-lg-5">
    <div class="d-flex align-items-center gap-2 mb-3">
      <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
      <h5 class="mb-0 fw-bold"><i class="bi bi-key text-warning me-2"></i>Reset Password</h5>
    </div>
    <div class="alert alert-warning d-flex gap-2">
      <i class="bi bi-exclamation-triangle-fill mt-1 flex-shrink-0"></i>
      <div>You are resetting the password for <strong><?= htmlspecialchars($displayName) ?></strong> (<?= htmlspecialchars($f['email']) ?>). Make sure to inform them of their new password.</div>
    </div>
    <div class="card">
      <div class="card-body">
        <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><ul class="mb-0 ps-3"><?php foreach($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div>
        <?php endif; ?>
        <form method="POST">
          <div class="mb-3">
            <label class="form-label fw-semibold">New Password <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="password" id="pw1" name="password" class="form-control" placeholder="Min. 8 chars, upper, lower, number, special" required>
              <button class="btn btn-outline-secondary" type="button" onclick="togglePw('pw1','i1')"><i class="bi bi-eye" id="i1"></i></button>
            </div>
            <div class="form-text">✓ 8+ characters &nbsp; ✓ Uppercase &nbsp; ✓ Lowercase &nbsp; ✓ Number &nbsp; ✓ Special character</div>
          </div>
          <div class="mb-4">
            <label class="form-label fw-semibold">Confirm New Password <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="password" id="pw2" name="password2" class="form-control" placeholder="Re-enter password" required>
              <button class="btn btn-outline-secondary" type="button" onclick="togglePw('pw2','i2')"><i class="bi bi-eye" id="i2"></i></button>
            </div>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-warning text-dark fw-semibold"><i class="bi bi-key me-1"></i>Reset Password</button>
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
function togglePw(id,iid){const p=document.getElementById(id);const i=document.getElementById(iid);p.type=p.type==='password'?'text':'password';i.className=p.type==='password'?'bi bi-eye':'bi bi-eye-slash';}
</script></body></html>
