<?php

require_once __DIR__ . '/../db.php';
session_start();
requireLogin('faculty');

// RFID is now the ONLY way to record faculty attendance (see
// admin/rfid_scan_action.php). The manual Check-In button has been removed
// from faculty/dashboard.php; this endpoint is kept in place (rather than
// deleted) purely so no dangling route/404 exists, but it no longer performs
// a check-in — it always bounces back with an explanatory message. All the
// original manual check-in logic below is left untouched and unreachable so
// attendance records, reports, and activity logs it used to write remain
// documented and easy to restore if ever needed.
setFlash('info', 'Manual Check-In has been disabled. Please tap your RFID card to check in.');
redirect('/faculty/dashboard.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/faculty/dashboard.php');
}

$log_id     = intval($_POST['log_id']  ?? 0);
$room_id    = intval($_POST['room_id'] ?? 0);
$faculty_id = (int)$_SESSION['user_id'];
$todayDate  = date('Y-m-d');
$todayName  = getTodayName();
$nowTime    = date('H:i:s');

runScheduleAutomation($conn, $faculty_id);

if (!$log_id || !$room_id) {
    setFlash('danger', 'Invalid request.');
    redirect('/faculty/dashboard.php');
}

$conn->begin_transaction();

try {
    $verify = $conn->prepare("
        SELECT l.id, l.schedule_id, l.faculty_id AS log_faculty_id, l.room_id,
               l.log_date, l.confirmation, l.checkin_at, l.checkout_at, l.status AS log_status,
               r.room_code, r.status AS room_status,
               s.faculty_id AS schedule_faculty_id, s.subject, s.day_of_week,
               s.time_start, s.time_end, s.is_active
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

    if ($log['confirmation'] !== 'yes') {
        $conn->rollback();
        setFlash('warning', 'You must confirm YES before checking in.');
        redirect('/faculty/dashboard.php');
    }

    if ($log['checkin_at'] !== null && $log['checkout_at'] === null) {
        $conn->rollback();
        setFlash('warning', 'You are already checked in.');
        redirect('/faculty/dashboard.php');
    }

    if ($log['checkout_at'] !== null) {
        $conn->rollback();
        setFlash('warning', 'This class session has already been completed.');
        redirect('/faculty/dashboard.php');
    }

    if ((int)$log['is_active'] !== 1 || $log['log_date'] !== $todayDate || $log['day_of_week'] !== $todayName) {
        $conn->rollback();
        setFlash('warning', 'You can only check in for an active schedule assigned today.');
        redirect('/faculty/dashboard.php');
    }

    if ($nowTime < $log['time_start']) {
        $conn->rollback();
        setFlash('warning', 'You cannot check in yet. Your class starts at ' . date('h:i A', strtotime($log['time_start'])) . '.');
        redirect('/faculty/dashboard.php');
    }

    if ($nowTime > $log['time_end']) {
        $conn->rollback();
        setFlash('warning', 'You cannot check in because your scheduled class time has already ended.');
        redirect('/faculty/dashboard.php');
    }

    $active = $conn->prepare("
        SELECT l.id, l.faculty_id
        FROM room_logs l
        WHERE l.room_id = ?
          AND l.log_date = CURDATE()
          AND l.checkin_at IS NOT NULL
          AND l.checkout_at IS NULL
          AND l.id != ?
        LIMIT 1
        FOR UPDATE
    ");
    $active->bind_param('ii', $room_id, $log_id);
    $active->execute();
    $activeOccupant = $active->get_result()->fetch_assoc();
    $active->close();

    if ($activeOccupant || $log['room_status'] === 'Occupied') {
        $conn->rollback();
        setFlash('danger', "Room {$log['room_code']} is currently occupied by another faculty member.");
        redirect('/faculty/dashboard.php');
    }

    $updLog = $conn->prepare("
        UPDATE room_logs
        SET checkin_at = NOW(), status = 'Occupied'
        WHERE id = ? AND checkin_at IS NULL AND checkout_at IS NULL
    ");
    $updLog->bind_param('i', $log_id);
    $updLog->execute();
    $updatedLog = $updLog->affected_rows;
    $updLog->close();

    if ($updatedLog !== 1) {
        $conn->rollback();
        setFlash('warning', 'You are already checked in.');
        redirect('/faculty/dashboard.php');
    }

    $updRoom = $conn->prepare("UPDATE rooms SET status = 'Occupied' WHERE id = ?");
    $updRoom->bind_param('i', $room_id);
    $updRoom->execute();
    $updRoom->close();

    logActivity($conn, 'CHECKIN', "Faculty checked in for {$log['subject']} in {$log['room_code']}");
    pushNotification(
        $conn,
        'Room Occupied',
        "{$_SESSION['user_name']} checked in to {$log['room_code']} for {$log['subject']}.",
        'info',
        '/admin/availability.php'
    );

    $conn->commit();
    setFlash('success', 'Checked In! Room is now marked as Occupied. Remember to Check Out after class.');
} catch (Throwable $e) {
    $conn->rollback();
    setFlash('danger', 'Unable to complete check-in. Please try again.');
}

redirect('/faculty/dashboard.php');
