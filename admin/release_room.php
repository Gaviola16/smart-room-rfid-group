<?php

require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

$id = intval($_GET['id'] ?? $_POST['room_id'] ?? 0);
if (!$id) { setFlash('danger', 'Invalid room ID.'); redirect('/admin/rooms.php'); }

$stmt = $conn->prepare("SELECT room_code, status FROM rooms WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$room) {
    setFlash('danger', 'Room not found.');
    redirect('/admin/rooms.php');
}

if ($room['status'] === 'Available') {
    setFlash('info', "Room «{$room['room_code']}» is already Available.");
    redirect('/admin/rooms.php');
}

$upd = $conn->prepare("UPDATE rooms SET status = 'Available' WHERE id = ?");
$upd->bind_param('i', $id);
$upd->execute();
$upd->close();

$logUpd = $conn->prepare("
    UPDATE room_logs
    SET checkout_at = NOW(), status = 'Available'
    WHERE room_id = ? AND log_date = CURDATE() AND checkout_at IS NULL
");
$logUpd->bind_param('i', $id);
$logUpd->execute();
$logUpd->close();

logActivity($conn, 'RELEASE_ROOM', "Released room {$room['room_code']} from {$room['status']} to Available");
pushNotification(
    $conn,
    'Room Released',
    "{$room['room_code']} was manually released to Available by {$_SESSION['user_name']}.",
    'info',
    '/admin/availability.php'
);
setFlash('success', "Room «{$room['room_code']}» has been released and is now Available.");

// FIX #6: Validate HTTP_REFERER against a whitelist before redirecting
$allowed = [
    '/admin/rooms.php',
    '/admin/dashboard.php',
    '/admin/availability.php',
];
$refPath = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH);
$target  = in_array($refPath, $allowed) ? $_SERVER['HTTP_REFERER'] : '/admin/rooms.php';
redirect($target);
