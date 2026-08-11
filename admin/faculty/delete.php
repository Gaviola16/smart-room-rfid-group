<?php
require_once __DIR__ . '/../../db.php';
session_start(); requireLogin('admin');
$id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
if (!$id) { setFlash('danger','Invalid ID.'); redirect('/admin/faculty/index.php'); }

// Deleting a faculty account must NEVER automatically delete its associated
// registration request unless the administrator explicitly checks the box
// on the confirmation modal (index.php). Only a POST submission of that
// modal can set this to '1'.
$alsoDeleteRequest = ($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['delete_request'] ?? '') === '1');

$titleSelect = columnExists($conn, 'users', 'title') ? 'name, title, email' : 'name, email';
$stmt = $conn->prepare("SELECT {$titleSelect} FROM users WHERE id=? AND role='faculty'");
$stmt->bind_param('i',$id); $stmt->execute();
$f = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$f) { setFlash('danger','Faculty not found.'); redirect('/admin/faculty/index.php'); }
$displayName = facultyDisplayName($f['title'] ?? null, $f['name']);


$chk = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE faculty_id=? AND is_active=1");
$chk->bind_param('i',$id); $chk->execute();
$cnt = $chk->get_result()->fetch_row()[0]; $chk->close();
if ($cnt > 0) {
    setFlash('danger',"Cannot delete «{$displayName}» — they have {$cnt} active schedule(s). Deactivate schedules first.");
    redirect('/admin/faculty/index.php');
}

$delLogs = $conn->prepare("DELETE FROM room_logs WHERE faculty_id=?");
$delLogs->bind_param('i',$id); $delLogs->execute(); $delLogs->close();

$delSchedules = $conn->prepare("DELETE FROM schedules WHERE faculty_id=?");
$delSchedules->bind_param('i',$id); $delSchedules->execute(); $delSchedules->close();

// Remove any biometric / verification data scoped to this faculty so the
// same face can be re-registered under a different account afterward.
// (users.face_descriptor is removed automatically below when the row is
// deleted — everything else lives outside that table and must be cleaned
// up explicitly.)
if (tableExists($conn, 'faculty_trusted_devices')) {
    $delDevices = $conn->prepare("DELETE FROM faculty_trusted_devices WHERE user_id=?");
    if ($delDevices) { $delDevices->bind_param('i',$id); $delDevices->execute(); $delDevices->close(); }
}

if (tableExists($conn, 'email_otps')) {
    $delOtps = $conn->prepare("DELETE FROM email_otps WHERE user_id=?");
    if ($delOtps) { $delOtps->bind_param('i',$id); $delOtps->execute(); $delOtps->close(); }
}

// Only remove notifications addressed specifically to this faculty member —
// broadcast notifications (user_id IS NULL) are shared and must stay.
if (tableExists($conn, 'notifications')) {
    $delNotif = $conn->prepare("DELETE FROM notifications WHERE user_id=?");
    if ($delNotif) { $delNotif->bind_param('i',$id); $delNotif->execute(); $delNotif->close(); }
}

// Delete registered face images + verification snapshots + any other cached
// biometric file stored for this faculty (uploads/faces/faculty_{id}.jpg,
// faculty_{id}_verify.jpg, and any future faculty_{id}_*.* variants).
$facesDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'faces';
if (is_dir($facesDir)) {
    foreach (glob($facesDir . DIRECTORY_SEPARATOR . "faculty_{$id}.*") ?: [] as $imgFile) { @unlink($imgFile); }
    foreach (glob($facesDir . DIRECTORY_SEPARATOR . "faculty_{$id}_*.*") ?: [] as $imgFile) { @unlink($imgFile); }
}

$del = $conn->prepare("DELETE FROM users WHERE id=?");
$del->bind_param('i',$id); $del->execute(); $del->close();
logActivity($conn,'DELETE_FACULTY',"Deleted faculty: {$displayName} (ID:$id) and all associated biometric data");

$msg = "Faculty «{$displayName}» and all associated biometric data deleted. Their face can now be registered to a new account.";

// Only touch registration_requests if the administrator explicitly checked
// "Also delete the associated registration request" on the confirmation
// modal. Deleting a faculty account never implicitly deletes its request.
if ($alsoDeleteRequest && !empty($f['email']) && tableExists($conn, 'registration_requests')) {
    $reqStmt = $conn->prepare("SELECT * FROM registration_requests WHERE email = ? ORDER BY id DESC LIMIT 1");
    if ($reqStmt) {
        $reqStmt->bind_param('s', $f['email']);
        $reqStmt->execute();
        $reqRow = $reqStmt->get_result()->fetch_assoc();
        $reqStmt->close();
        if ($reqRow) {
            deleteRegistrationRequestRow($conn, $reqRow);
            logActivity($conn, 'REGISTRATION_REQUEST_DELETED',
                "Registration request #{$reqRow['id']} for {$displayName} deleted together with faculty account");
            $msg .= ' The associated registration request was also deleted.';
        }
    }
}

setFlash('success', $msg);
redirect('/admin/faculty/index.php');
