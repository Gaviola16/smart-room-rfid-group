<?php
$currentFile = basename($_SERVER['PHP_SELF']);
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));
$role        = $_SESSION['user_role'] ?? '';
$userName    = $_SESSION['user_name'] ?? 'User';
$flash       = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $pageTitle ?? 'Smart Room System' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  :root {
    --brand-dark:  #0d1b2a;
    --brand-blue:  #1a6bcc;
    --brand-light: #e8f0fb;
    --sidebar-w:   240px;
  }
  body { background: #f4f6f9; font-family: 'Segoe UI', system-ui, sans-serif; }

  #sidebar {
    width: var(--sidebar-w);
    height: 100vh;
    background: var(--brand-dark);
    position: fixed; top: 0; left: 0; z-index: 100;
    display: flex; flex-direction: column;
    overflow-y: auto;
    overflow-x: hidden;
    transition: transform .25s ease;
  }
  #sidebar::-webkit-scrollbar { width: 8px; }
  #sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.04); }
  #sidebar::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.2);
    border-radius: 999px;
  }
  #sidebar::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.32); }
  .sidebar-brand {
    padding: 1.25rem 1rem;
    border-bottom: 1px solid rgba(255,255,255,0.08);
  }
  .sidebar-brand .brand-icon {
    width: 40px; height: 40px;
    background: var(--brand-blue);
    border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    color: #fff; font-size: 1.1rem; flex-shrink: 0;
  }
  .sidebar-brand .brand-text { color: #fff; font-size: 0.9rem; font-weight: 700; line-height: 1.2; }
  .sidebar-brand .brand-sub  { color: rgba(255,255,255,0.45); font-size: 0.7rem; }

  .sidebar-section-label {
    padding: 1rem 1rem 0.3rem;
    color: rgba(255,255,255,0.35);
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
  }
  .nav-sidebar .nav-link {
    color: rgba(255,255,255,0.7);
    padding: 0.55rem 1rem;
    border-radius: 8px;
    margin: 0 0.5rem 0.1rem;
    font-size: 0.875rem;
    display: flex; align-items: center; gap: 0.6rem;
    transition: background .15s, color .15s;
  }
  .nav-sidebar .nav-link:hover,
  .nav-sidebar .nav-link.active {
    background: rgba(26,107,204,0.35);
    color: #fff;
  }
  .nav-sidebar .nav-link .bi { font-size: 1rem; flex-shrink: 0; }
  .nav-sidebar .nav-link.sub-link {
    margin-left: 1.25rem;
    padding-top: 0.42rem;
    padding-bottom: 0.42rem;
    font-size: 0.8rem;
  }
  .nav-sidebar .nav-link.sub-link .bi { font-size: 0.85rem; }

  .sidebar-footer {
    margin-top: auto;
    padding: 1rem;
    border-top: 1px solid rgba(255,255,255,0.08);
  }
  .sidebar-user-name { color: #fff; font-size: 0.82rem; font-weight: 600; }
  .sidebar-user-role { color: rgba(255,255,255,0.45); font-size: 0.72rem; text-transform: capitalize; }
  .btn-logout {
    color: rgba(255,255,255,0.6);
    font-size: 0.8rem;
    padding: 0.3rem 0.6rem;
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 6px;
    text-decoration: none;
    transition: all .15s;
  }
  .btn-logout:hover { background: rgba(220,53,69,0.3); color: #fff; border-color: transparent; }

  #main-content {
    margin-left: var(--sidebar-w);
    min-height: 100vh;
    display: flex; flex-direction: column;
  }
  .topbar {
    background: #fff;
    border-bottom: 1px solid #e0e4ea;
    padding: 0.75rem 1.5rem;
    position: sticky; top: 0; z-index: 99;
    display: flex; align-items: center; justify-content: space-between;
  }
  .topbar-title { font-weight: 700; font-size: 1.05rem; color: var(--brand-dark); }
  .page-body { padding: 1.5rem; flex: 1; }

  .card { border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.07); border-radius: 12px; }
  .card-header { border-radius: 12px 12px 0 0 !important; font-weight: 600; }
  .stat-card { border-radius: 12px; padding: 1.25rem; }
  .stat-card .stat-icon {
    width: 48px; height: 48px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem;
  }
  .stat-card .stat-value { font-size: 1.75rem; font-weight: 800; line-height: 1; }
  .stat-card .stat-label { font-size: 0.78rem; color: #6c757d; margin-top: 0.25rem; }

  #sidebar-toggle { display: none; }
  @media (max-width: 991.98px) {
    #sidebar { transform: translateX(-100%); }
    #sidebar.show { transform: translateX(0); }
    #main-content { margin-left: 0; }
    #sidebar-toggle { display: inline-flex; }
    .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99; }
    .sidebar-overlay.show { display: block; }
  }
</style>
</head>
<body>


<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>


<nav id="sidebar">
  <div class="sidebar-brand d-flex align-items-center gap-2">
    <div class="brand-icon"><i class="bi bi-building-lock"></i></div>
    <div>
      <div class="brand-text">Smart Room</div>
      <div class="brand-sub">RFID Monitor</div>
    </div>
  </div>

  <?php if ($role === 'admin'): ?>
  <div class="sidebar-section-label">Administration</div>
  <ul class="nav flex-column nav-sidebar">
    <li class="nav-item">
      <a class="nav-link <?= (in_array($currentFile, ['profile.php','change_password.php']) && $currentDir === 'admin') ? 'active' : '' ?>"
         href="/admin/profile.php">
        <i class="bi bi-person-circle"></i> My Profile
      </a>
    </li>
    <li class="nav-item">
  <a class="nav-link <?= ($currentFile === 'notifications.php' && $currentDir === 'admin') ? 'active' : '' ?>"
     href="/admin/notifications.php">
    <i class="bi bi-bell"></i> Notifications

    <?php
      $adminUnread = countUnreadNotifications(
          $conn,
          $_SESSION['user_id'] ?? 0,
          'admin'
      );

      if ($adminUnread > 0):
    ?>
        <span class="badge bg-danger ms-auto">
            <?= $adminUnread ?>
        </span>
    <?php endif; ?>
  </a>
</li>
    <li class="nav-item">
      <a class="nav-link <?= ($currentFile === 'dashboard.php' && $currentDir === 'admin') ? 'active' : '' ?>"
         href="/admin/dashboard.php">
        <i class="bi bi-speedometer2"></i> Dashboard
      </a>
    </li>
    <li class="nav-item">
  <a class="nav-link <?= ($currentFile === 'availability.php') ? 'active' : '' ?>"
     href="/admin/availability.php">
    <i class="bi bi-broadcast"></i> Availability Board
  </a>
</li>
    <li class="nav-item">
      <a class="nav-link <?= (in_array($currentFile, ['rooms.php','add_room.php','edit_room.php','room_timeline.php'])) ? 'active' : '' ?>"
         href="/admin/rooms.php">
        <i class="bi bi-door-open"></i> Manage Rooms
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= ($currentFile === 'schedules.php') ? 'active' : '' ?>"
         href="/admin/schedules.php">
        <i class="bi bi-calendar3"></i> Schedules
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= ($currentDir === 'faculty') ? 'active' : '' ?>"
         href="/admin/faculty/index.php">
        <i class="bi bi-people"></i> Faculty Management
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= ($currentFile === 'registration_requests.php') ? 'active' : '' ?>"
         href="/admin/registration_requests.php">
        <i class="bi bi-person-check"></i> Registration Requests
        <?php if (tableExists($conn,'registration_requests')):
              $pCount=(int)($conn->query("SELECT COUNT(*) FROM registration_requests WHERE status='Pending'")->fetch_row()[0]??0);
              if($pCount>0): ?>
        <span class="badge bg-warning text-dark ms-auto"><?= $pCount ?></span>
        <?php endif; endif; ?>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= ($currentFile === 'rfid_attendance.php') ? 'active' : '' ?>"
         href="/admin/rfid_attendance.php">
        <i class="bi bi-wifi"></i> RFID Attendance
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= (in_array($currentFile,['rfid_management.php','rfid_test.php','rfid_lookup.php'])) ? 'active' : '' ?>"
         href="/admin/rfid_management.php">
        <i class="bi bi-credit-card-2-front"></i> RFID Management
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= ($currentFile === 'activity_reports.php') ? 'active' : '' ?>"
         href="/admin/activity_reports.php">
        <i class="bi bi-journal-richtext"></i> Activity &amp; Reports
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= ($currentFile === 'responses.php') ? 'active' : '' ?>"
         href="/admin/responses.php">
        <i class="bi bi-chat-square-check"></i> Faculty Responses
      </a>
    </li>
  </ul>

  <?php else: ?>
  <div class="sidebar-section-label">Faculty Portal</div>
  <ul class="nav flex-column nav-sidebar">
    <li class="nav-item">
      <a class="nav-link <?= ($currentFile === 'dashboard.php' && $currentDir === 'faculty') ? 'active' : '' ?>"
         href="/faculty/dashboard.php">
        <i class="bi bi-grid-1x2"></i> My Dashboard
      </a>
    </li>
    <li class="nav-item">
  <a class="nav-link <?= ($currentFile === 'profile.php' && $currentDir === 'faculty') ? 'active' : '' ?>"
     href="/faculty/profile.php">
    <i class="bi bi-person-circle"></i> My Profile
  </a>
</li>
  </ul>
  <?php endif; ?>

  <div class="sidebar-footer">
    <div class="d-flex align-items-center justify-content-between gap-2">
      <div>
        <div class="sidebar-user-name"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($userName) ?></div>
        <div class="sidebar-user-role"><?= htmlspecialchars($role) ?></div>
      </div>
      <a href="/logout.php" class="btn-logout" title="Logout">
        <i class="bi bi-box-arrow-right"></i>
      </a>
    </div>
  </div>
</nav>

<div id="main-content">

  <div class="topbar">
    <div class="d-flex align-items-center gap-3">
      <button class="btn btn-sm btn-outline-secondary" id="sidebar-toggle" onclick="toggleSidebar()">
        <i class="bi bi-list fs-5"></i>
      </button>
      <span class="topbar-title"><?= $pageTitle ?? 'Dashboard' ?></span>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
        <i class="bi bi-circle-fill me-1" style="font-size:0.4rem;vertical-align:middle;"></i>
        <?= ucfirst($role) ?>
      </span>
      <span class="text-muted small d-none d-md-inline"><?= date('D, M d Y') ?></span>
    </div>
  </div>


  <?php if ($flash): ?>
  <div class="mx-3 mt-3">
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
      <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
      <?= htmlspecialchars($flash['message']) ?>
      <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
  </div>
  <?php endif; ?>


  <div class="page-body">
<?php
?>
