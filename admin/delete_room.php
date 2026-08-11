<?php

require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

$id = intval($_GET['id'] ?? 0);
if (!$id) { setFlash('danger', 'Invalid room ID.'); redirect('/admin/rooms.php'); }

$stmt = $conn->prepare("SELECT room_code FROM rooms WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$room) {
    setFlash('danger', 'Room not found.');
    redirect('/admin/rooms.php');
}

$chk = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE room_id = ? AND is_active = 1");
$chk->bind_param('i', $id);
$chk->execute();
$activeCount = $chk->get_result()->fetch_row()[0];
$chk->close();

if ($activeCount > 0) {
    setFlash('danger', "Cannot delete room «{$room['room_code']}» — it has {$activeCount} active schedule(s). Deactivate schedules first.");
    redirect('/admin/rooms.php');
}


$delLogs = $conn->prepare("DELETE FROM room_logs WHERE room_id = ?");
$delLogs->bind_param('i', $id);
$delLogs->execute();
$delLogs->close();

$delSchedules = $conn->prepare("DELETE FROM schedules WHERE room_id = ?");
$delSchedules->bind_param('i', $id);
$delSchedules->execute();
$delSchedules->close();

$del = $conn->prepare("DELETE FROM rooms WHERE id = ?");
$del->bind_param('i', $id);
$del->execute();
$del->close();

logActivity($conn, 'DELETE_ROOM', "Deleted room {$room['room_code']}");

setFlash('success', "Room «{$room['room_code']}» deleted successfully.");
redirect('/admin/rooms.php');
