<?php
// ============================================================
// admin/notifications.php — Notification Center (Admin)
// PLACE AT: public_html/admin/notifications.php
//
// Admins see: notifications addressed to them directly (user_id
// = their own id) PLUS all broadcast notifications (user_id IS NULL).
// Requires module7_notifications.sql + the pushNotification()
// patch in db.php (see db_patch_instructions.txt).
// ============================================================
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

$pageTitle = 'Notifications';
$adminId   = $_SESSION['user_id'];

if (!tableExists($conn, 'notifications')) {
    include __DIR__ . '/../authentication.php';
    echo '<div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            The <code>notifications</code> table does not exist yet.
            Please run <code>module7_notifications.sql</code> first.
          </div></div></div>
          <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
          </body></html>';
    exit;
}

// ── Mark single as read ──────────────────────────────────────
if (isset($_GET['mark_read'])) {
    $nid = intval($_GET['mark_read']);
    $upd = $conn->prepare("UPDATE notifications SET status='read' WHERE id=? AND (user_id=? OR user_id IS NULL)");
    $upd->bind_param('ii', $nid, $adminId);
    $upd->execute(); $upd->close();
    redirect('/admin/notifications.php');
}

// ── Mark all as read ──────────────────────────────────────────
if (isset($_GET['mark_all_read'])) {
    $upd = $conn->prepare("UPDATE notifications SET status='read' WHERE status='unread' AND (user_id=? OR user_id IS NULL)");
    $upd->bind_param('i', $adminId);
    $upd->execute(); $upd->close();
    setFlash('success', 'All notifications marked as read.');
    redirect('/admin/notifications.php');
}

// ── Filter ────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all'; // all | unread | read

$where = "WHERE (user_id = ? OR user_id IS NULL)";
if ($filter === 'unread') $where .= " AND status = 'unread'";
if ($filter === 'read')   $where .= " AND status = 'read'";

$stmt = $conn->prepare("SELECT * FROM notifications $where ORDER BY created_at DESC LIMIT 100");
$stmt->bind_param('i', $adminId);
$stmt->execute();
$notifs = $stmt->get_result();
$stmt->close();

// Counts for tabs
$cntAll    = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id=? OR user_id IS NULL)");
$cntAll->bind_param('i', $adminId); $cntAll->execute();
$totalAll = $cntAll->get_result()->fetch_row()[0]; $cntAll->close();

$cntUnread = countUnreadNotifications($conn, $adminId, 'admin');

function notifIcon($type) {
    $map = [
        'success' => ['bi-check-circle-fill', 'success'],
        'warning' => ['bi-exclamation-triangle-fill', 'warning'],
        'danger'  => ['bi-x-circle-fill', 'danger'],
        'info'    => ['bi-info-circle-fill', 'primary'],
    ];
    return $map[$type] ?? ['bi-bell-fill', 'secondary'];
}

include __DIR__ . '/../authentication.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold"><i class="bi bi-bell me-2 text-primary"></i>Notification Center</h5>
    <div class="text-muted small">System alerts — faculty responses, room status changes, schedule updates</div>
  </div>
  <?php if ($cntUnread > 0): ?>
  <a href="?mark_all_read=1" class="btn btn-sm btn-outline-primary">
    <i class="bi bi-check2-all me-1"></i>Mark All as Read
  </a>
  <?php endif; ?>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $filter==='all' ? 'active fw-semibold':'' ?>" href="?filter=all">
      All <span class="badge bg-secondary ms-1"><?= $totalAll ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $filter==='unread' ? 'active fw-semibold':'' ?>" href="?filter=unread">
      Unread <span class="badge bg-danger ms-1"><?= $cntUnread ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $filter==='read' ? 'active fw-semibold':'' ?>" href="?filter=read">
      Read
    </a>
  </li>
</ul>

<div class="card">
  <div class="card-body p-0">
    <?php if ($notifs->num_rows === 0): ?>
    <div class="text-center text-muted py-5">
      <i class="bi bi-bell-slash fs-1 d-block mb-2"></i>No notifications to show.
    </div>
    <?php else: ?>
    <ul class="list-group list-group-flush">
      <?php while ($n = $notifs->fetch_assoc()):
        [$icon, $color] = notifIcon($n['type']);
        $isUnread = $n['status'] === 'unread';
      ?>
      <li class="list-group-item d-flex align-items-start gap-3 py-3 <?= $isUnread ? 'bg-light' : '' ?>">
        <div class="rounded-circle bg-<?= $color ?>-subtle text-<?= $color ?> d-flex align-items-center justify-content-center flex-shrink-0"
             style="width:40px;height:40px;">
          <i class="bi <?= $icon ?>"></i>
        </div>
        <div class="flex-grow-1">
          <div class="d-flex justify-content-between align-items-start">
            <div class="fw-semibold <?= $isUnread ? 'text-dark' : 'text-muted' ?>">
              <?= htmlspecialchars($n['title']) ?>
              <?php if ($isUnread): ?><span class="badge bg-danger ms-1" style="font-size:.6rem;">NEW</span><?php endif; ?>
            </div>
            <span class="text-muted small text-nowrap ms-2"><?= date('M d, h:i A', strtotime($n['created_at'])) ?></span>
          </div>
          <div class="text-muted small mt-1"><?= htmlspecialchars($n['message']) ?></div>
          <div class="mt-2 d-flex gap-2">
            <?php if ($n['link']): ?>
            <a href="<?= htmlspecialchars($n['link']) ?>" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.72rem;">
              <i class="bi bi-box-arrow-up-right me-1"></i>View
            </a>
            <?php endif; ?>
            <?php if ($isUnread): ?>
            <a href="?mark_read=<?= $n['id'] ?>&filter=<?= $filter ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:.72rem;">
              <i class="bi bi-check2 me-1"></i>Mark as Read
            </a>
            <?php endif; ?>
          </div>
        </div>
      </li>
      <?php endwhile; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>

  </div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('show');document.getElementById('sidebarOverlay').classList.toggle('show');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('show');document.getElementById('sidebarOverlay').classList.remove('show');}
</script>
</body></html>