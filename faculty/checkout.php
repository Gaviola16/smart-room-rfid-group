<?php

require_once __DIR__ . '/../db.php';
session_start();
requireLogin('faculty');

// RFID is now the ONLY way to record faculty attendance (see
// admin/rfid_scan_action.php). The manual Check-Out button has been removed
// from faculty/dashboard.php; this endpoint is kept in place (rather than
// deleted) purely so no dangling route/404 exists, but it no longer performs
// a check-out — it always bounces back with an explanatory message. All the
// original manual check-out logic below is left untouched and unreachable so
// attendance records, reports, and activity logs it used to write remain
// documented and easy to restore if ever needed.
setFlash('info', 'Manual Check-Out has been disabled. Please tap your RFID card to check out.');
redirect('/faculty/dashboard.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/faculty/dashboard.php');
}

$log_id     = intval($_POST['log_id']  ?? 0);
$room_id    = intval($_POST['room_id'] ?? 0);
$faculty_id = (int)$_SESSION['user_id'];

if (!$log_id || !$room_id) {
    setFlash('danger', 'Invalid request.');
    redirect('/faculty/dashboard.php');
}

$conn->begin_transaction();

try {
    $verify = $conn->prepare("
        SELECT l.id, l.schedule_id, l.faculty_id AS log_faculty_id, l.room_id,
               l.checkin_at, l.checkout_at,
               r.room_code,
               s.faculty_id AS schedule_faculty_id, s.subject
        FROM room_logs l
        JOIN rooms r ON l.room_id = r.id
        JOIN schedules s ON l.schedule_id = s.id
        WHERE l.id = ?
        FOR UPDATE
    ");
    $verify->bind_param('i', $log_id);
    $verify->execute();
    $log = $verify->get_result()->fetch_assoc();
    $verify->close();

    if (!$log || (int)$log['room_id'] !== $room_id) {
        $conn->rollback();
        setFlash('danger', 'Invalid request.');
        redirect('/faculty/dashboard.php');
    }

    if ((int)$log['schedule_faculty_id'] !== $faculty_id || (int)$log['log_faculty_id'] !== $faculty_id) {
        $conn->rollback();
        setFlash('danger', 'You are not assigned to this schedule.');
        redirect('/faculty/dashboard.php');
    }

    if ($log['checkin_at'] === null) {
        $conn->rollback();
        setFlash('warning', 'You have not checked in yet.');
        redirect('/faculty/dashboard.php');
    }

    if ($log['checkout_at'] !== null) {
        $conn->rollback();
        setFlash('warning', 'You have already checked out.');
        redirect('/faculty/dashboard.php');
    }

    $updLog = $conn->prepare("
        UPDATE room_logs
        SET checkout_at = NOW(), status = 'Available'
        WHERE id = ? AND checkin_at IS NOT NULL AND checkout_at IS NULL
    ");
    $updLog->bind_param('i', $log_id);
    $updLog->execute();
    $updatedLog = $updLog->affected_rows;
    $updLog->close();

    if ($updatedLog !== 1) {
        $conn->rollback();
        setFlash('warning', 'You have already checked out.');
        redirect('/faculty/dashboard.php');
    }

    $active = $conn->prepare("
        SELECT id
        FROM room_logs
        WHERE room_id = ?
          AND log_date = CURDATE()
          AND checkin_at IS NOT NULL
          AND checkout_at IS NULL
        LIMIT 1
        FOR UPDATE
    ");
    $active->bind_param('i', $room_id);
    $active->execute();
    $stillOccupied = $active->get_result()->fetch_assoc();
    $active->close();

    if (!$stillOccupied) {
        $updRoom = $conn->prepare("UPDATE rooms SET status = 'Available' WHERE id = ?");
        $updRoom->bind_param('i', $room_id);
        $updRoom->execute();
        $updRoom->close();
    }

    logActivity($conn, 'CHECKOUT', "Faculty checked out from {$log['subject']} in {$log['room_code']}");
    pushNotification(
        $conn,
        'Room Marked Available',
        "{$log['room_code']} is now Available after {$_SESSION['user_name']} checked out.",
        'success',
        '/admin/availability.php'
    );

    $conn->commit();
    setFlash('success', 'Checked Out successfully! Room is now Available. Thank you!');
} catch (Throwable $e) {
    $conn->rollback();
    setFlash('danger', 'Unable to complete check-out. Please try again.');
}

redirect('/faculty/dashboard.php');
