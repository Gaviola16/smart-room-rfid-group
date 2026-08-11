<?php
/**
 * Admin review for one-time faculty registration requests.
 * Approval creates the faculty account and copies the permanent face template.
 */
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

$pageTitle = 'Registration Requests';

function generateTempPassword(int $len = 14): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
    $pass = '';
    for ($i = 0; $i < $len; $i++) $pass .= $chars[random_int(0, strlen($chars) - 1)];
    return $pass;
}

function generateUniqueFacultyUsername(mysqli $conn, string $firstName, string $lastName): string {
    $base = strtolower(preg_replace('/[^a-zA-Z0-9._-]/', '', substr($firstName, 0, 1) . $lastName));
    if ($base === '') $base = 'faculty';
    $candidate = $base;
    $counter = 1;

    while (columnExists($conn, 'users', 'username')) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        if (!$stmt) break;
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$exists) break;
        $counter++;
        $candidate = $base . $counter;
    }
    return $candidate;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['bulk_action'])) {
    $action = $_POST['action'] ?? '';
    $requestId = (int)($_POST['request_id'] ?? 0);
    $adminNote = trim($_POST['admin_notes'] ?? '');
    $adminId = (int)$_SESSION['user_id'];

    if (!tableExists($conn, 'registration_requests')) {
        setFlash('danger', 'Registration requests table not found. Please run migration.sql.');
        redirect('/admin/registration_requests.php');
    }

    $reqStmt = $conn->prepare("SELECT * FROM registration_requests WHERE id = ? LIMIT 1");
    $reqStmt->bind_param('i', $requestId);
    $reqStmt->execute();
    $req = $reqStmt->get_result()->fetch_assoc();
    $reqStmt->close();

    if (!$req) {
        setFlash('danger', 'Request not found.');
        redirect('/admin/registration_requests.php');
    }

    if ($action === 'approve' && $req['status'] === 'Pending') {
        $dupErrors = [];

        $checks = [
            ["SELECT id FROM users WHERE email = ? LIMIT 1", $req['email'], 'Email is already in use.'],
            ["SELECT id FROM users WHERE employee_id = ? AND role = 'faculty' LIMIT 1", $req['employee_id'], 'Employee ID is already in use.'],
        ];
        foreach ($checks as [$sql, $value, $message]) {
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('s', $value);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) $dupErrors[] = $message;
                $stmt->close();
            }
        }

        $descriptor = json_decode($req['face_embedding'], true);
        if (!is_array($descriptor) || count($descriptor) !== 128) {
            $dupErrors[] = 'Face template is invalid.';
        } elseif (checkDuplicateFace($conn, array_map('floatval', $descriptor), 0) !== null) {
            $dupErrors[] = 'Face is already registered to another faculty account.';
            logActivity($conn, 'DUPLICATE_FACE', "Duplicate face detected during approval of request #{$requestId}");
        }

        $username = generateUniqueFacultyUsername($conn, $req['first_name'], $req['last_name']);
        if (columnExists($conn, 'users', 'username')) {
            $chkUser = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            if ($chkUser) {
                $chkUser->bind_param('s', $username);
                $chkUser->execute();
                if ($chkUser->get_result()->num_rows > 0) $dupErrors[] = 'Generated username already exists.';
                $chkUser->close();
            }
        }

        if ($dupErrors) {
            setFlash('danger', 'Cannot approve: ' . implode(' ', $dupErrors));
            redirect('/admin/registration_requests.php');
        }

        $tempPass = 'Password2026';
        $passHash = password_hash($tempPass, PASSWORD_DEFAULT);

        $columns = ['name', 'email', 'password', 'role', 'department', 'employee_id', 'phone', 'is_active', 'first_login'];
        $vals = [$req['full_name'], $req['email'], $passHash, 'faculty', $req['department'], $req['employee_id'], $req['contact_number'], 1, 1];
        $types = 'sssssssii';

        if (columnExists($conn, 'users', 'username')) {
            $columns[] = 'username'; $vals[] = $username; $types .= 's';
        }
        if (columnExists($conn, 'users', 'security_question')) {
            $columns[] = 'security_question'; $vals[] = $req['security_question']; $types .= 's';
        }
        if (columnExists($conn, 'users', 'security_answer')) {
            $columns[] = 'security_answer'; $vals[] = $req['security_answer']; $types .= 's';
        }
        if (columnExists($conn, 'users', 'account_status')) {
            $columns[] = 'account_status'; $vals[] = 'Pending Face Verification'; $types .= 's';
        }
        if (columnExists($conn, 'users', 'face_descriptor')) {
            $columns[] = 'face_descriptor'; $vals[] = $req['face_embedding']; $types .= 's';
        }
        if (columnExists($conn, 'users', 'face_verified')) {
            $columns[] = 'face_verified'; $vals[] = 1; $types .= 'i';
        }
        if (columnExists($conn, 'users', 'face_registered_at')) {
            $columns[] = 'face_registered_at'; $vals[] = date('Y-m-d H:i:s'); $types .= 's';
        }
        if (columnExists($conn, 'users', 'must_change_password')) {
            $columns[] = 'must_change_password'; $vals[] = 1; $types .= 'i';
        }
        if (!empty($req['face_image']) && columnExists($conn, 'users', 'face_front_path')) {
            $columns[] = 'face_front_path'; $vals[] = $req['face_image']; $types .= 's';
        }

        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $ins = $conn->prepare("INSERT INTO users (" . implode(',', $columns) . ") VALUES ($placeholders)");
        $ins->bind_param($types, ...$vals);

        if ($ins->execute()) {
            $newUserId = $conn->insert_id;
            $ins->close();

            $upd = $conn->prepare("
                UPDATE registration_requests
                SET status = 'Approved', reviewed_at = NOW(), reviewed_by = ?, created_user_id = ?, admin_notes = ?
                WHERE id = ?
            ");
            $upd->bind_param('iisi', $adminId, $newUserId, $adminNote, $requestId);
            $upd->execute();
            $upd->close();

            $emailSent = sendFacultyTemporaryPasswordEmail($req['email'], $req['full_name'], $username, $tempPass);
            if ($emailSent) {
                logActivity($conn, 'REG_APPROVAL_EMAIL_SENT', "Approval email sent to {$req['email']}");
            } else {
                logActivity($conn, 'EMAIL_SEND_FAILED', "Approval email FAILED for {$req['email']}: " . getLastMailError());
            }

            logActivity($conn, 'REGISTRATION_APPROVED', "Faculty registration approved for {$req['full_name']} (request #{$requestId})");
            logActivity($conn, 'CREATE_FACULTY', "Faculty account created from one-time face registration: {$req['full_name']} ({$req['email']})");

            setFlash($emailSent ? 'success' : 'danger', $emailSent
                ? "Account created for {$req['full_name']}. Login details emailed."
                : 'Unable to send email. Account was created, but the faculty member must be notified manually.');
        } else {
            $ins->close();
            setFlash('danger', 'Could not create account: ' . $conn->error);
        }

        redirect('/admin/registration_requests.php');
    }

    if ($action === 'reject' && $req['status'] === 'Pending') {
        $upd = $conn->prepare("
            UPDATE registration_requests
            SET status = 'Rejected', reviewed_at = NOW(), reviewed_by = ?, admin_notes = ?
            WHERE id = ?
        ");
        $upd->bind_param('isi', $adminId, $adminNote, $requestId);
        $upd->execute();
        $upd->close();

        if (!sendRegistrationRejectionEmail($req['email'], $req['full_name'], $adminNote)) {
            logActivity($conn, 'EMAIL_SEND_FAILED', "Rejection email FAILED for {$req['email']}: " . getLastMailError());
        }
        logActivity($conn, 'REGISTRATION_REJECTED', "Faculty registration rejected for {$req['full_name']} (request #{$requestId})");
        setFlash('warning', "Request from {$req['full_name']} has been rejected.");
        redirect('/admin/registration_requests.php');
    }

    // 'delete' only ever removes rows from registration_requests. It never
    // touches the `users` table, so an already-approved faculty account is
    // never affected by deleting its originating request.
    if ($action === 'delete') {
        deleteRegistrationRequestRow($conn, $req);
        logActivity($conn, 'REGISTRATION_REQUEST_DELETED',
            "Registration request #{$requestId} for {$req['full_name']} ({$req['email']}) deleted");
        setFlash('success', "Request from {$req['full_name']} has been deleted.");
        redirect('/admin/registration_requests.php');
    }
}

// Bulk actions: delete selected / delete all approved / delete all rejected.
// Handled as a separate POST branch (own form) so it can't be confused with
// the single-row approve/reject/delete actions above.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $bulkAction = $_POST['bulk_action'];
    $adminId = (int)$_SESSION['user_id'];

    if (!tableExists($conn, 'registration_requests')) {
        setFlash('danger', 'Registration requests table not found.');
        redirect('/admin/registration_requests.php');
    }

    $rowsToDelete = [];

    if ($bulkAction === 'delete_selected') {
        $ids = array_filter(array_map('intval', $_POST['selected_ids'] ?? []));
        if ($ids) {
            $in = implode(',', $ids);
            $res = $conn->query("SELECT * FROM registration_requests WHERE id IN ($in)");
            while ($row = $res->fetch_assoc()) $rowsToDelete[] = $row;
        }
    } elseif ($bulkAction === 'delete_all_approved') {
        $res = $conn->query("SELECT * FROM registration_requests WHERE status = 'Approved'");
        while ($row = $res->fetch_assoc()) $rowsToDelete[] = $row;
    } elseif ($bulkAction === 'delete_all_rejected') {
        $res = $conn->query("SELECT * FROM registration_requests WHERE status = 'Rejected'");
        while ($row = $res->fetch_assoc()) $rowsToDelete[] = $row;
    }

    if ($rowsToDelete) {
        foreach ($rowsToDelete as $row) {
            deleteRegistrationRequestRow($conn, $row);
        }
        $count = count($rowsToDelete);
        $label = [
            'delete_selected'      => 'Deleted selected requests',
            'delete_all_approved'  => 'Deleted all approved requests',
            'delete_all_rejected'  => 'Deleted all rejected requests',
        ][$bulkAction] ?? 'Deleted requests';
        logActivity($conn, 'REGISTRATION_REQUESTS_BULK_DELETED',
            "{$label}: {$count} registration request(s) removed by admin #{$adminId}");
        setFlash('success', "{$count} request(s) deleted.");
    } else {
        setFlash('warning', 'No matching requests to delete.');
    }

    redirect('/admin/registration_requests.php?status=' . urlencode($_GET['status'] ?? 'Pending'));
}

$filterStatus = $_GET['status'] ?? 'Pending';
$validStatuses = ['Pending', 'Approved', 'Rejected', 'all'];
if (!in_array($filterStatus, $validStatuses, true)) $filterStatus = 'Pending';

$requests = null;
$pendingCount = 0;
if (tableExists($conn, 'registration_requests')) {
    if ($filterStatus === 'all') {
        $requests = $conn->query("SELECT * FROM registration_requests ORDER BY registration_date DESC");
    } else {
        $stmt = $conn->prepare("SELECT * FROM registration_requests WHERE status = ? ORDER BY registration_date DESC");
        $stmt->bind_param('s', $filterStatus);
        $stmt->execute();
        $requests = $stmt->get_result();
    }
    $pendingCount = (int)$conn->query("SELECT COUNT(*) FROM registration_requests WHERE status = 'Pending'")->fetch_row()[0];
}

include __DIR__ . '/../authentication.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold"><i class="bi bi-person-check me-2 text-primary"></i>Registration Requests</h5>
    <div class="text-muted small">Review one-time face registration requests</div>
  </div>
  <?php if ($pendingCount > 0): ?><span class="badge bg-warning text-dark fs-6"><?= $pendingCount ?> Pending</span><?php endif; ?>
</div>

<?php $flash = getFlash(); if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
  <?= $flash['message'] ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!tableExists($conn, 'registration_requests')): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle me-1"></i>
  The <code>registration_requests</code> table does not exist yet. Please run <code>migration.sql</code>.
</div>
<?php else: ?>

<ul class="nav nav-tabs mb-3">
  <?php foreach (['Pending','Approved','Rejected','all'] as $s): ?>
  <li class="nav-item"><a class="nav-link <?= $filterStatus === $s ? 'active' : '' ?>" href="?status=<?= $s ?>"><?= ucfirst($s) ?></a></li>
  <?php endforeach; ?>
</ul>

<form method="POST" id="bulkForm">
<div class="card mb-3">
  <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
    <span class="small text-muted me-2"><span id="selectedCount">0</span> selected</span>
    <button type="button" class="btn btn-sm btn-outline-danger" id="deleteSelectedBtn" disabled
            onclick="submitBulk('delete_selected', 'Delete the selected registration request(s)? This cannot be undone.')">
      <i class="bi bi-trash me-1"></i>Delete Selected
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary"
            onclick="submitBulk('delete_all_approved', 'Delete ALL approved registration requests? Faculty accounts already created from them will NOT be affected.')">
      <i class="bi bi-trash3 me-1"></i>Delete All Approved
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary"
            onclick="submitBulk('delete_all_rejected', 'Delete ALL rejected registration requests?')">
      <i class="bi bi-trash3 me-1"></i>Delete All Rejected
    </button>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-dark">
          <tr>
            <th style="width:36px;"><input type="checkbox" class="form-check-input" id="selectAll"></th>
            <th>#</th>
            <th>Faculty</th>
            <th>Employee ID</th>
            <th>Department</th>
            <th>Email</th>
            <th>Face Preview</th>
            <th>Status</th>
            <th>Submitted</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($requests && $requests->num_rows > 0): $i = 0; while ($r = $requests->fetch_assoc()): $i++; ?>
          <tr>
            <td><input type="checkbox" class="form-check-input row-check" name="selected_ids[]" value="<?= $r['id'] ?>"></td>
            <td><?= $i ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($r['full_name']) ?><div class="small text-muted"><?= htmlspecialchars($r['contact_number']) ?></div></td>
            <td><code><?= htmlspecialchars($r['employee_id']) ?></code></td>
            <td><?= htmlspecialchars($r['department']) ?></td>
            <td><?= htmlspecialchars($r['email']) ?></td>
            <td class="text-center">
              <?php if (!empty($r['face_image'])): ?>
                <img src="/<?= htmlspecialchars($r['face_image']) ?>" class="rounded" style="width:54px;height:54px;object-fit:cover;" alt="Face preview">
              <?php else: ?>
                <span class="badge bg-success">Template Saved</span>
              <?php endif; ?>
            </td>
            <td><?php $sc=['Pending'=>'warning text-dark','Approved'=>'success','Rejected'=>'danger']; ?><span class="badge bg-<?= $sc[$r['status']] ?? 'secondary' ?>"><?= $r['status'] ?></span></td>
            <td class="small text-muted"><?= date('M j, Y g:i A', strtotime($r['registration_date'])) ?></td>
            <td class="text-center">
              <button type="button" class="btn btn-sm btn-outline-info me-1" onclick="viewDetails(<?= $r['id'] ?>, <?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)"><i class="bi bi-eye"></i> View</button>
              <?php if ($r['status'] === 'Pending'): ?>
              <button type="button" class="btn btn-sm btn-success me-1" onclick="confirmAction(<?= $r['id'] ?>,'approve',<?= htmlspecialchars(json_encode($r['full_name']), ENT_QUOTES) ?>)"><i class="bi bi-check-lg"></i> Approve</button>
              <button type="button" class="btn btn-sm btn-danger me-1" onclick="confirmAction(<?= $r['id'] ?>,'reject',<?= htmlspecialchars(json_encode($r['full_name']), ENT_QUOTES) ?>)"><i class="bi bi-x-lg"></i> Reject</button>
              <?php endif; ?>
              <button type="button" class="btn btn-sm btn-outline-danger" title="Delete this request"
                      onclick="confirmAction(<?= $r['id'] ?>,'delete',<?= htmlspecialchars(json_encode($r['full_name']), ENT_QUOTES) ?>)">
                <i class="bi bi-trash"></i>
              </button>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="10" class="text-center text-muted py-4">No <?= strtolower($filterStatus) ?> requests found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</form>
<?php endif; ?>

<div class="modal fade" id="detailsModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-lines-fill me-2"></i>Request Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="detailsBody"></div>
    </div>
  </div>
</div>

<div class="modal fade" id="actionModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" id="actionHeader">
        <h5 class="modal-title" id="actionTitle"></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="request_id" id="modalRequestId">
        <input type="hidden" name="action" id="modalAction">
        <div class="modal-body">
          <p id="actionMessage"></p>
          <div class="mb-3">
            <label class="form-label fw-semibold">Notes (optional)</label>
            <textarea name="admin_notes" class="form-control" rows="3" placeholder="Add a note for this request"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" id="actionConfirmBtn">Confirm</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('show');document.getElementById('sidebarOverlay').classList.toggle('show');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('show');document.getElementById('sidebarOverlay').classList.remove('show');}

function esc(v) {
  return String(v ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}

function viewDetails(id, r) {
  const pic = r.face_image
    ? `<img src="/${esc(r.face_image)}" class="rounded" style="width:96px;height:96px;object-fit:cover;">`
    : `<div class="rounded bg-secondary d-flex align-items-center justify-content-center text-white" style="width:96px;height:96px;font-size:2rem;"><i class="bi bi-person-bounding-box"></i></div>`;
  document.getElementById('detailsBody').innerHTML = `
    <div class="d-flex gap-3 align-items-start mb-3">${pic}
      <div><h5 class="mb-0 fw-bold">${esc(r.full_name)}</h5>
      <small class="text-muted">${esc(r.department)}</small></div>
    </div>
    <table class="table table-sm table-bordered">
      <tr><th width="35%">Employee ID</th><td><code>${esc(r.employee_id)}</code></td></tr>
      <tr><th>Email</th><td>${esc(r.email)}</td></tr>
      <tr><th>Contact</th><td>${esc(r.contact_number)}</td></tr>
      <tr><th>Security Question</th><td>${esc(r.security_question)}</td></tr>
      <tr><th>Face Template</th><td><span class="badge bg-success">Saved once during registration</span></td></tr>
      <tr><th>Status</th><td>${esc(r.status)}</td></tr>
      <tr><th>Submitted</th><td>${esc(r.registration_date)}</td></tr>
      ${r.admin_notes ? `<tr><th>Admin Notes</th><td>${esc(r.admin_notes)}</td></tr>` : ''}
    </table>`;
  new bootstrap.Modal(document.getElementById('detailsModal')).show();
}

function confirmAction(id, action, name) {
  document.getElementById('modalRequestId').value = id;
  document.getElementById('modalAction').value = action;
  const isApprove = action === 'approve';
  const isDelete = action === 'delete';
  const headerClass = isApprove ? 'bg-success text-white' : (isDelete ? 'bg-danger text-white' : 'bg-danger text-white');
  document.getElementById('actionTitle').textContent = isApprove ? 'Approve Request' : (isDelete ? 'Delete Request' : 'Reject Request');
  document.getElementById('actionHeader').className = 'modal-header ' + headerClass;
  document.getElementById('actionMessage').innerHTML = isApprove
    ? `<strong>${esc(name)}</strong>'s account will be created. The saved one-time face template will be copied to the faculty account.`
    : (isDelete
        ? `Delete the registration request from <strong>${esc(name)}</strong>? This only removes the request record — it will never delete an existing faculty account. This cannot be undone.`
        : `<strong>${esc(name)}</strong>'s request will be rejected.`);
  const btn = document.getElementById('actionConfirmBtn');
  btn.className = 'btn ' + (isApprove ? 'btn-success' : 'btn-danger');
  btn.textContent = isApprove ? 'Approve & Create Account' : (isDelete ? 'Delete Request' : 'Reject Request');
  new bootstrap.Modal(document.getElementById('actionModal')).show();
}

/* ── Bulk selection ──────────────────────────────────────────────────────── */
const selectAll = document.getElementById('selectAll');
const selectedCount = document.getElementById('selectedCount');
const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');

function rowChecks() { return Array.from(document.querySelectorAll('.row-check')); }

function refreshSelectionUI() {
  const checked = rowChecks().filter(c => c.checked).length;
  selectedCount.textContent = checked;
  deleteSelectedBtn.disabled = checked === 0;
}

selectAll?.addEventListener('change', () => {
  rowChecks().forEach(c => { c.checked = selectAll.checked; });
  refreshSelectionUI();
});

document.addEventListener('change', (e) => {
  if (e.target.classList.contains('row-check')) refreshSelectionUI();
});

function submitBulk(action, confirmMsg) {
  if (action === 'delete_selected' && rowChecks().filter(c => c.checked).length === 0) return;
  if (!confirm(confirmMsg)) return;
  const form = document.getElementById('bulkForm');
  let hidden = form.querySelector('input[name="bulk_action"]');
  if (!hidden) {
    hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'bulk_action';
    form.appendChild(hidden);
  }
  hidden.value = action;
  form.submit();
}
</script>
</body></html>
