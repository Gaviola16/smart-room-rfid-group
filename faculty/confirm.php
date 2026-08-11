<?php

require_once __DIR__ . '/../db.php';
session_start();
requireLogin('faculty');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/faculty/dashboard.php');
}

$log_id      = intval($_POST['log_id']      ?? 0);
$room_id     = intval($_POST['room_id']     ?? 0);
$confirmation = $_POST['confirmation']      ?? '';
$faculty_id  = (int)$_SESSION['user_id'];
$todayDate   = date('Y-m-d');
$todayName   = getTodayName();
$nowTime     = date('H:i:s');

runScheduleAutomation($conn, $faculty_id);

if (!$log_id || !$room_id || !in_array($confirmation, ['yes','no'])) {
    setFlash('danger', 'Invalid request. Please try again.');
    redirect('/faculty/dashboard.php');
}

$conn->begin_transaction();

try {
    $verify = $conn->prepare("
        SELECT l.id, l.room_id, l.log_date, l.confirmation,
               r.room_code,
               s.subject, s.faculty_id AS schedule_faculty_id, s.day_of_week,
               s.time_start, s.time_end, s.is_active,
               TIME(DATE_SUB(CONCAT(CURDATE(), ' ', s.time_start), INTERVAL 10 MINUTE)) AS confirmation_start
        FROM room_logs l
        JOIN rooms r ON l.room_id = r.id
        JOIN schedules s ON l.schedule_id = s.id
        WHERE l.id = ? AND l.faculty_id = ?
        FOR UPDATE
    ");
    $verify->bind_param('ii', $log_id, $faculty_id);
    $verify->execute();
    $log = $verify->get_result()->fetch_assoc();
    $verify->close();

    if (!$log) {
        $conn->rollback();
        setFlash('danger', 'Log entry not found or unauthorized.');
        redirect('/faculty/dashboard.php');
    }

    if ((int)$log['room_id'] !== $room_id || (int)$log['schedule_faculty_id'] !== $faculty_id) {
        $conn->rollback();
        setFlash('danger', 'You are not assigned to this schedule.');
        redirect('/faculty/dashboard.php');
    }

    if ($log['confirmation'] !== null) {
        $conn->rollback();
        setFlash('warning', 'You have already responded to this schedule.');
        redirect('/faculty/dashboard.php');
    }

    if ((int)$log['is_active'] !== 1 || $log['log_date'] !== $todayDate || $log['day_of_week'] !== $todayName) {
        $conn->rollback();
        setFlash('warning', 'You can only confirm today\'s active schedules.');
        redirect('/faculty/dashboard.php');
    }

    if ($nowTime < $log['confirmation_start']) {
        $conn->rollback();
        setFlash('warning', 'You cannot respond yet. Confirmation opens at ' . date('h:i A', strtotime($log['confirmation_start'])) . '.');
        redirect('/faculty/dashboard.php');
    }

    if ($nowTime >= $log['time_start']) {
        $conn->rollback();
        setFlash('warning', 'This class has already ended. The confirmation window is closed.');
        redirect('/faculty/dashboard.php');
    }

    $activePrompt = $conn->prepare("
        SELECT l.id
        FROM schedules s
        JOIN room_logs l ON l.schedule_id = s.id AND l.log_date = CURDATE()
        WHERE s.faculty_id = ?
          AND s.day_of_week = ?
          AND s.is_active = 1
          AND l.confirmation IS NULL
          AND l.status = 'Unconfirmed'
          AND l.checkin_at IS NULL
          AND l.checkout_at IS NULL
          AND CURTIME() >= TIME(DATE_SUB(CONCAT(CURDATE(), ' ', s.time_start), INTERVAL 10 MINUTE))
          AND CURTIME() < s.time_start
        ORDER BY s.time_start ASC
        LIMIT 1
    ");
    $activePrompt->bind_param('is', $faculty_id, $todayName);
    $activePrompt->execute();
    $activePromptLog = $activePrompt->get_result()->fetch_assoc();
    $activePrompt->close();

    if (!$activePromptLog || (int)$activePromptLog['id'] !== $log_id) {
        $conn->rollback();
        setFlash('warning', 'This schedule is not in the active confirmation window.');
        redirect('/faculty/dashboard.php');
    }

    $newLogStatus = ($confirmation === 'yes') ? 'Reserved' : 'Available';

    // Re-checked confirmation IS NULL in the WHERE clause below as a second
    // line of defense: if two requests from the same double-click race past
    // the checks above, only the first UPDATE can actually affect a row.
    $updLog = $conn->prepare("
        UPDATE room_logs SET confirmation = ?, confirmed_at = NOW(), status = ?
        WHERE id = ? AND confirmation IS NULL
    ");
    $updLog->bind_param('ssi', $confirmation, $newLogStatus, $log_id);
    $updLog->execute();
    $updatedLog = $updLog->affected_rows;
    $updLog->close();

    if ($updatedLog !== 1) {
        $conn->rollback();
        setFlash('warning', 'You have already responded to this schedule.');
        redirect('/faculty/dashboard.php');
    }

    if ($confirmation === 'yes') {
        logActivity($conn, 'CONFIRM_YES', "Faculty confirmed YES for {$log['subject']} in {$log['room_code']}");
        pushNotification(
            $conn,
            'Faculty Confirmed Class',
            "{$_SESSION['user_name']} confirmed YES for {$log['subject']} in {$log['room_code']}.",
            'success',
            '/admin/responses.php'
        );
        $updRoom = $conn->prepare("UPDATE rooms SET status = 'Reserved' WHERE id = ?");
        $updRoom->bind_param('i', $room_id);
        $updRoom->execute();
        $updRoom->close();
    } else {
        logActivity($conn, 'CONFIRM_NO', "Faculty confirmed NO for {$log['subject']} in {$log['room_code']}");
        pushNotification(
            $conn,
            'Faculty Declined Class',
            "{$_SESSION['user_name']} will NOT conduct {$log['subject']} in {$log['room_code']}.",
            'warning',
            '/admin/responses.php'
        );
        $chk = $conn->prepare("
            SELECT COUNT(*) FROM room_logs
            WHERE room_id = ? AND log_date = CURDATE()
              AND id != ?
              AND confirmation = 'yes'
              AND status IN ('Reserved', 'Occupied')
        ");
        $chk->bind_param('ii', $room_id, $log_id);
        $chk->execute();
        $otherActive = $chk->get_result()->fetch_row()[0];
        $chk->close();

        if ($otherActive == 0) {
            $updRoom = $conn->prepare("UPDATE rooms SET status = 'Available' WHERE id = ?");
            $updRoom->bind_param('i', $room_id);
            $updRoom->execute();
            $updRoom->close();
        }
    }

    $conn->commit();

    if ($confirmation === 'yes') {
        setFlash('success', '✅ Confirmed! Room has been Reserved for your class. Please tap your RFID card when you arrive to check in.');
    } else {
        setFlash('info', '❌ Noted. Room has been marked Available since you will not conduct class.');
    }
} catch (Throwable $e) {
    $conn->rollback();
    setFlash('danger', 'Unable to record your response. Please try again.');
}

redirect('/faculty/dashboard.php');
