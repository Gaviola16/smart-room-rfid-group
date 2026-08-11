<?php
/**
 * admin/rfid_management.php
 * RFID Card Management: Assign, Replace, Deactivate, Reactivate, View History
 */
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

$pageTitle = 'RFID Card Management';

// Self-provision rfid_cards / rfid_scan_logs — no manual migration.sql needed.
ensureRfidTables($conn);

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action        = $_POST['action']          ?? '';
    $cardId        = (int)($_POST['card_id']    ?? 0);
    $facultyId     = (int)($_POST['faculty_id'] ?? 0);
    $uid           = sanitizeRfidUid($_POST['rfid_uid'] ?? '');
    $notes         = trim($_POST['notes']       ?? '');
    $replaceCardId = (int)($_POST['replace_of_card_id'] ?? 0); // set when re-issuing a lost/deactivated card

    if (!tableExists($conn, 'rfid_cards')) {
        setFlash('danger', 'RFID table could not be created. Please check database permissions.');
        redirect('/admin/rfid_management.php');
    }

    if ($action === 'assign') {
        if (!$uid || !$facultyId) {
            // Previously this validation gap failed silently (redirect with
            // no flash message at all) whenever the UID or faculty selection
            // didn't come through, leaving the admin with no explanation.
            setFlash('danger', 'Please provide an RFID UID and select a faculty member.');
            redirect('/admin/rfid_management.php');
        }

        if (!isValidRfidUidFormat($uid)) {
            setFlash('danger', 'Invalid RFID value. UIDs may only contain letters, numbers, "-", and ":".');
            redirect('/admin/rfid_management.php');
        }

        // Serialize concurrent assignment attempts for the same UID with a
        // MySQL advisory lock (no schema change — nothing to add/verify
        // against a unique index this project doesn't ship a .sql file
        // for). Without this, two admins (or a double form-submit) racing
        // the "is this UID already active?" check below could both pass it
        // and both insert a row for the same card, violating "one RFID per
        // faculty / one faculty per RFID."
        $lockName = 'rfid_assign_' . md5($uid);
        $gotLock  = $conn->query("SELECT GET_LOCK(" . "'" . $conn->real_escape_string($lockName) . "', 5) AS l")->fetch_assoc()['l'] ?? 0;

        if (!$gotLock) {
            setFlash('danger', 'Another assignment for this card is already in progress. Please try again.');
            redirect('/admin/rfid_management.php');
        }

        try {
            // One faculty per RFID, one RFID per faculty: UID must not already be active elsewhere.
            $chk = $conn->prepare("SELECT id FROM rfid_cards WHERE uid=? AND status='Active'");
            $chk->bind_param('s', $uid); $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                setFlash('danger', 'This RFID UID is already assigned to an active card.');
            } else {
                // Deactivate any existing active card for this faculty (enforces one-active-card-per-faculty)
                $deact = $conn->prepare("UPDATE rfid_cards SET status='Deactivated', deactivated_at=NOW() WHERE faculty_id=? AND status='Active'");
                $deact->bind_param('i', $facultyId); $deact->execute(); $deact->close();

                $ins = $conn->prepare("INSERT INTO rfid_cards (uid, faculty_id, notes) VALUES (?,?,?)");
                $ins->bind_param('sis', $uid, $facultyId, $notes); $ins->execute(); $ins->close();

                // Also update users.rfid_uid if that legacy column exists
                if (columnExists($conn, 'users', 'rfid_uid')) {
                    $upd = $conn->prepare("UPDATE users SET rfid_uid=? WHERE id=?");
                    $upd->bind_param('si', $uid, $facultyId); $upd->execute(); $upd->close();
                }

                $facName = $conn->query("SELECT name FROM users WHERE id=" . (int)$facultyId)->fetch_row()[0] ?? "ID $facultyId";

                if ($replaceCardId > 0) {
                    // Explicit "replace a lost card" flow — old card stays on file as Lost/Deactivated for audit.
                    logActivity($conn, 'RFID_REPLACED', "RFID card replaced for {$facName}: old card #$replaceCardId -> new UID $uid");
                    pushNotification($conn, 'RFID Card Replaced', "A new RFID card ($uid) was issued to {$facName} to replace a lost/deactivated card.", 'info', '/admin/rfid_management.php');
                    setFlash('success', 'RFID card replaced successfully.');
                } else {
                    logActivity($conn, 'RFID_ASSIGNED', "RFID card $uid assigned to {$facName} (faculty ID $facultyId)");
                    pushNotification($conn, 'RFID Assigned', "RFID card ($uid) was assigned to {$facName}.", 'info', '/admin/rfid_management.php');
                    setFlash('success', 'RFID card assigned successfully.');
                }
            }
            $chk->close();
        } finally {
            $conn->query("SELECT RELEASE_LOCK('" . $conn->real_escape_string($lockName) . "')");
        }
        redirect('/admin/rfid_management.php');
    }

    if ($action === 'deactivate' && $cardId) {
        $upd = $conn->prepare("UPDATE rfid_cards SET status='Deactivated', deactivated_at=NOW(), notes=CONCAT(IFNULL(notes,''),' [Removed: ".date('Y-m-d')."]') WHERE id=?");
        $upd->bind_param('i', $cardId); $upd->execute(); $upd->close();

        // Clear rfid_uid from user if column exists
        $fid = $conn->query("SELECT faculty_id FROM rfid_cards WHERE id=$cardId")->fetch_row()[0] ?? 0;
        if ($fid && columnExists($conn, 'users', 'rfid_uid')) {
            $upd2 = $conn->prepare("UPDATE users SET rfid_uid=NULL WHERE id=? AND rfid_uid=(SELECT uid FROM rfid_cards WHERE id=?)");
            $upd2->bind_param('ii', $fid, $cardId); $upd2->execute(); $upd2->close();
        }
        logActivity($conn, 'RFID_REMOVED', "RFID card ID $cardId assignment removed");
        setFlash('warning', 'RFID assignment removed.');
        redirect('/admin/rfid_management.php');
    }

    if ($action === 'reactivate' && $cardId) {
        $upd = $conn->prepare("UPDATE rfid_cards SET status='Active', deactivated_at=NULL WHERE id=?");
        $upd->bind_param('i', $cardId); $upd->execute(); $upd->close();
        logActivity($conn, 'RFID_REACTIVATED', "RFID card ID $cardId reactivated");
        setFlash('success', 'RFID card reactivated.');
        redirect('/admin/rfid_management.php');
    }

    if ($action === 'mark_lost' && $cardId) {
        $upd = $conn->prepare("UPDATE rfid_cards SET status='Lost' WHERE id=?");
        $upd->bind_param('i', $cardId); $upd->execute(); $upd->close();
        logActivity($conn, 'RFID_LOST', "RFID card ID $cardId marked as lost");
        setFlash('warning', 'RFID card marked as lost.');
        redirect('/admin/rfid_management.php');
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────
$cards     = null;
$faculty   = $conn->query("SELECT id, name, employee_id, department FROM users WHERE role='faculty' AND is_active=1 ORDER BY name ASC");
$scanLogs  = null;

if (tableExists($conn, 'rfid_cards')) {
    $cards = $conn->query("
        SELECT c.*, u.name AS faculty_name, u.employee_id, u.department
        FROM rfid_cards c
        LEFT JOIN users u ON u.id = c.faculty_id
        ORDER BY c.issued_at DESC
    ");
}
if (tableExists($conn, 'rfid_scan_logs')) {
    $scanLogs = $conn->query("
        SELECT sl.*, u.name AS faculty_name, r.room_code,
               s.subject AS schedule_subject, s.section AS schedule_section
        FROM rfid_scan_logs sl
        LEFT JOIN users u        ON u.id  = sl.faculty_id
        LEFT JOIN rooms r        ON r.id  = sl.room_id
        LEFT JOIN room_logs rl   ON rl.id = sl.log_id
        LEFT JOIN schedules s    ON s.id  = rl.schedule_id
        ORDER BY sl.scanned_at DESC LIMIT 100
    ");
}

include __DIR__ . '/../authentication.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold"><i class="bi bi-credit-card-2-front me-2 text-primary"></i>RFID Card Management</h5>
    <div class="text-muted small">Assign, manage and track faculty RFID cards</div>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignModal">
    <i class="bi bi-plus-circle me-1"></i>Assign New Card
  </button>
</div>

<?php $flash = getFlash(); if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
  <?= $flash['message'] ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!tableExists($conn, 'rfid_cards')): ?>
<div class="alert alert-warning">
  RFID tables not found. Please run <code>migration.sql</code>.
</div>
<?php else: ?>

<!-- RFID Test Mode Card -->
<div class="card mb-3 border-warning">
  <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
      <h6 class="mb-0 fw-bold"><i class="bi bi-wifi me-1 text-warning"></i>RFID Test Mode</h6>
      <div class="text-muted small">Scan a card to see its UID before assigning it to a faculty member.</div>
    </div>
    <a href="rfid_test.php" class="btn btn-warning">
      <i class="bi bi-play-circle me-1"></i>Open Test Mode
    </a>
  </div>
</div>

<!-- Cards Table -->
<div class="card mb-4">
  <div class="card-header fw-semibold"><i class="bi bi-list-ul me-1"></i>All RFID Cards</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-dark">
          <tr>
            <th>#</th><th>UID</th><th>Faculty</th><th>Department</th>
            <th>Status</th><th>Issued</th><th>Notes</th><th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($cards && $cards->num_rows > 0): $i=0; while ($c = $cards->fetch_assoc()): $i++; ?>
          <tr>
            <td><?= $i ?></td>
            <td><code><?= htmlspecialchars($c['uid']) ?></code></td>
            <td><?= htmlspecialchars($c['faculty_name'] ?? '—') ?></td>
            <td class="small text-muted"><?= htmlspecialchars($c['department'] ?? '—') ?></td>
            <td>
              <?php $sc=['Active'=>'success','Deactivated'=>'secondary','Lost'=>'danger']; ?>
              <span class="badge bg-<?= $sc[$c['status']] ?? 'secondary' ?>"><?= $c['status'] ?></span>
            </td>
            <td class="small text-muted"><?= date('M j, Y', strtotime($c['issued_at'])) ?></td>
            <td class="small"><?= htmlspecialchars($c['notes'] ?? '') ?></td>
            <td class="text-center">
              <?php if ($c['status'] === 'Active'): ?>
              <form method="POST" class="d-inline" onsubmit="return confirm('Mark as lost?')">
                <input type="hidden" name="action"  value="mark_lost">
                <input type="hidden" name="card_id" value="<?= $c['id'] ?>">
                <button class="btn btn-xs btn-outline-warning">Lost</button>
              </form>
              <form method="POST" class="d-inline" onsubmit="return confirm('Remove this RFID assignment?')">
                <input type="hidden" name="action"  value="deactivate">
                <input type="hidden" name="card_id" value="<?= $c['id'] ?>">
                <button class="btn btn-xs btn-outline-danger">Remove</button>
              </form>
              <?php elseif (in_array($c['status'],['Deactivated','Lost'])): ?>
              <button type="button" class="btn btn-xs btn-outline-primary"
                      onclick="openReplaceModal(<?= $c['id'] ?>, '<?= (int)$c['faculty_id'] ?>')">Replace</button>
              <form method="POST" class="d-inline" onsubmit="return confirm('Reactivate this card?')">
                <input type="hidden" name="action"  value="reactivate">
                <input type="hidden" name="card_id" value="<?= $c['id'] ?>">
                <button class="btn btn-xs btn-outline-success">Reactivate</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No RFID cards assigned yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Scan Logs -->
<?php if ($scanLogs): ?>
<div class="card">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-journal-text me-1"></i>Recent RFID Scan Logs</span>
    <span class="small text-muted fw-normal" id="scanLogsUpdated">Live</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-dark">
          <tr><th>Time</th><th>UID</th><th>Faculty</th><th>Schedule</th><th>Room</th><th>Result</th><th>Reason</th></tr>
        </thead>
        <tbody id="scanLogsTableBody">
          <?php while ($sl = $scanLogs->fetch_assoc()): ?>
          <tr>
            <td class="small"><?= date('M j Y, g:i:s A', strtotime($sl['scanned_at'])) ?></td>
            <td><code><?= htmlspecialchars($sl['rfid_uid']) ?></code></td>
            <td><?= htmlspecialchars($sl['faculty_name'] ?? '—') ?></td>
            <td class="small"><?= htmlspecialchars($sl['schedule_subject'] ? ($sl['schedule_subject'] . ($sl['schedule_section'] ? ' — ' . $sl['schedule_section'] : '')) : '—') ?></td>
            <td><?= htmlspecialchars($sl['room_code']    ?? '—') ?></td>
            <td>
              <?php $rc=['Success'=>'success','Failed'=>'danger','Duplicate'=>'warning text-dark']; ?>
              <span class="badge bg-<?= $rc[$sl['result']] ?? 'secondary' ?>"><?= $sl['result'] ?></span>
            </td>
            <td class="small text-muted"><?= htmlspecialchars($sl['reason'] ?? '') ?></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>


<!-- Assign Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="assignModalTitle"><i class="bi bi-credit-card-2-front me-1"></i>Assign RFID Card</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="assignForm">
        <input type="hidden" name="action" value="assign">
        <input type="hidden" name="replace_of_card_id" id="replaceOfCardId" value="0">
        <div class="modal-body">
          <div class="alert alert-info small d-none" id="replaceNotice">
            <i class="bi bi-info-circle me-1"></i>Issuing a replacement card for this faculty member. Their old card stays on file for audit history.
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">RFID UID <span class="text-danger">*</span></label>
            <input type="text" name="rfid_uid" class="form-control font-monospace" required
                   placeholder="Scan card or enter UID manually…">
            <div class="form-text">The unique identifier printed or scanned from the RFID card.</div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Assign to Faculty <span class="text-danger">*</span></label>
            <select name="faculty_id" id="assignFacultySelect" class="form-select" required>
              <option value="">— Select Faculty —</option>
              <?php if ($faculty) while ($f = $faculty->fetch_assoc()): ?>
              <option value="<?= $f['id'] ?>">
                <?= htmlspecialchars($f['name']) ?> (<?= htmlspecialchars($f['employee_id']) ?>)
              </option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Notes</label>
            <input type="text" name="notes" class="form-control" placeholder="Optional notes…">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="assignSubmitBtn"><i class="bi bi-check-lg me-1"></i>Assign Card</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('show');document.getElementById('sidebarOverlay').classList.toggle('show');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('show');document.getElementById('sidebarOverlay').classList.remove('show');}

// "Assign New Card" button always opens a clean form.
document.querySelector('[data-bs-target="#assignModal"]').addEventListener('click', function () {
  document.getElementById('replaceOfCardId').value = '0';
  document.getElementById('replaceNotice').classList.add('d-none');
  document.getElementById('assignFacultySelect').disabled = false;
  document.getElementById('assignModalTitle').innerHTML = '<i class="bi bi-credit-card-2-front me-1"></i>Assign RFID Card';
  document.getElementById('assignSubmitBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i>Assign Card';
  document.getElementById('assignForm').reset();
});

// "Replace" button on a Lost/Deactivated card — prefill the same faculty, mark as a replacement.
function openReplaceModal(cardId, facultyId) {
  document.getElementById('assignForm').reset();
  document.getElementById('replaceOfCardId').value = cardId;
  document.getElementById('replaceNotice').classList.remove('d-none');
  const select = document.getElementById('assignFacultySelect');
  select.value = facultyId;
  document.getElementById('assignModalTitle').innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Replace Lost RFID Card';
  document.getElementById('assignSubmitBtn').innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Replace Card';
  new bootstrap.Modal(document.getElementById('assignModal')).show();
}

// ── Live-updating "Recent RFID Scan Logs" ───────────────────────────────────
// This page is otherwise plain server-rendered PHP, so without this the
// admin would only see new scans after manually reloading the page. Polling
// a small JSON endpoint keeps the table current within a few seconds of
// every tap on the kiosk, satisfying "updates immediately after every scan"
// without needing a full page refresh.
function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, s => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[s]));
}

const scanLogsBody = document.getElementById('scanLogsTableBody');
const scanLogsUpdated = document.getElementById('scanLogsUpdated');
const resultBadgeClass = { Success: 'success', Failed: 'danger', Duplicate: 'warning text-dark' };

async function refreshScanLogs() {
  if (!scanLogsBody) return;
  try {
    const res = await fetch('rfid_scan_logs_data.php');
    if (!res.ok) return;
    const data = await res.json();
    if (!data.logs) return;

    scanLogsBody.innerHTML = data.logs.map(sl => `
      <tr>
        <td class="small">${escapeHtml(sl.scanned_at_display)}</td>
        <td><code>${escapeHtml(sl.rfid_uid)}</code></td>
        <td>${escapeHtml(sl.faculty_name || '—')}</td>
        <td class="small">${escapeHtml(sl.schedule_display || '—')}</td>
        <td>${escapeHtml(sl.room_code || '—')}</td>
        <td><span class="badge bg-${resultBadgeClass[sl.result] || 'secondary'}">${escapeHtml(sl.result)}</span></td>
        <td class="small text-muted">${escapeHtml(sl.reason || '')}</td>
      </tr>
    `).join('') || '<tr><td colspan="7" class="text-center text-muted py-3">No scans recorded yet.</td></tr>';

    if (scanLogsUpdated) {
      scanLogsUpdated.textContent = 'Updated ' + new Date().toLocaleTimeString();
    }
  } catch (err) {
    // Silent — this is a background refresh; the page's own server-rendered
    // rows stay visible if a single poll fails.
  }
}

if (scanLogsBody) {
  setInterval(refreshScanLogs, 4000);
}
</script>
</body></html>
