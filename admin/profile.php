<?php
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

$pageTitle = 'Admin Profile';
$userId    = $_SESSION['user_id'];
$errors    = [];

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    redirect('/logout.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');

    if ($name === '') {
        $errors[] = 'Full name is required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }

    $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $chk->bind_param('si', $email, $userId);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $errors[] = 'That email is already used by another account.';
    }
    $chk->close();

    if (empty($errors)) {
        $upd = $conn->prepare("UPDATE users SET name = ?, email = ?, department = ?, phone = ? WHERE id = ?");
        $upd->bind_param('ssssi', $name, $email, $department, $phone, $userId);
        $upd->execute();
        $upd->close();

        $_SESSION['user_name'] = $name;

        logActivity($conn, 'UPDATE_PROFILE', "Admin updated their own profile: {$name}");
        setFlash('success', 'Your profile has been updated successfully.');
        redirect('/admin/profile.php');
    }

    $user = array_merge($user, $_POST);
}

include __DIR__ . '/../authentication.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-7">
    <div class="d-flex align-items-center gap-2 mb-3">
      <h5 class="mb-0 fw-bold"><i class="bi bi-person-circle text-primary me-2"></i>Admin Profile</h5>
    </div>

    <div class="card mb-3">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center fw-bold flex-shrink-0"
             style="width:64px;height:64px;font-size:1.6rem;">
          <?= strtoupper(substr($user['name'], 0, 1)) ?>
        </div>
        <div>
          <div class="fw-bold fs-5"><?= htmlspecialchars($user['name']) ?></div>
          <div class="text-muted small"><?= htmlspecialchars($user['email']) ?></div>
          <span class="badge bg-primary mt-1"><i class="bi bi-shield-lock me-1"></i>Administrator</span>
          <?php if (!empty($user['department'])): ?>
          <span class="badge bg-secondary mt-1"><?= htmlspecialchars($user['department']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0 ps-3">
        <?php foreach ($errors as $error): ?>
        <li><?= htmlspecialchars($error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <div class="card mb-3">
      <div class="card-header bg-primary text-white">
        <i class="bi bi-pencil-square me-2"></i>Update Profile
      </div>
      <div class="card-body">
        <form method="POST">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Department / Office</label>
              <input type="text" name="department" class="form-control" value="<?= htmlspecialchars($user['department'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone Number</label>
              <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Account Role</label>
              <div class="form-control-plaintext">
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Admin</span>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Account Status</label>
              <div class="form-control-plaintext">
                <?php if (($user['is_active'] ?? 1) && (($user['status'] ?? 'active') === 'active')): ?>
                  <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                <?php else: ?>
                  <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Inactive</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <hr class="my-3">
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>
        </form>
      </div>
    </div>
    
    <div class="card">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
          <div class="fw-semibold"><i class="bi bi-shield-lock me-2 text-warning"></i>Password & Security</div>
          <div class="text-muted small">Update your administrator password</div>
        </div>
        <a href="change_password.php" class="btn btn-outline-warning">
          <i class="bi bi-key me-1"></i>Change Password
        </a>
        <a href="/setup_security_question.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-shield-check me-1"></i>Security Question
</a>
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
