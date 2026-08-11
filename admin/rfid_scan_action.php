<?php
/**
 * admin/rfid_scan_action.php
 * ---------------------------------------------------------------------------
 * JSON endpoint called by admin/rfid_attendance.php every time the RFID
 * reader "types" a card UID + Enter into the page.
 *
 * RFID is used for ONE thing only: automatically toggling a faculty member's
 * Check-In / Check-Out on today's already-scheduled class. It never creates
 * a session, never logs anyone in, and never touches Face Verification,
 * Email OTP, or Trusted Device — those remain the only way to actually sign
 * in to the system. This endpoint requires an already-authenticated admin
 * session (the kiosk device itself is expected to be signed in as admin),
 * exactly like every other admin/*.php page.
 *
 * Flow:
 *   1. Identify the faculty from the scanned UID (rfid_cards).
 *   2. Run the same schedule-automation pass the dashboard uses, so
 *      today's room_logs rows exist and stale states are already resolved.
 *   3. Look at today's schedule(s) for that faculty and decide automatically:
 *        - currently checked in, not checked out  -> Check Out
 *        - not checked in, within class time window -> Check In
 *        - otherwise -> explain why (no class / window closed / already done)
 *   4. Reuse the exact same room_logs/rooms updates as the manual
 *      faculty/checkin.php + faculty/checkout.php flows, so Reports, Room
 *      Status, Activity Logs and Notifications all update automatically
 *      with no separate/duplicate code path.
 */

require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

header('Content-Type: application/json');

function rfid_respond(array $payload): void {
    echo json_encode($payload);
    exit;
}

ensureRfidTables($conn);

// Normalize the raw POST value first (strip stray control/invisible bytes
// some USB readers inject, on top of the plain trim already done inside).
$uid = sanitizeRfidUid((string)($_POST['uid'] ?? ''));

if ($uid === '') {
    rfid_respond(['status' => 'error', 'title' => 'RFID Not Registered', 'message' => 'No RFID UID received.']);
}

if (!isValidRfidUidFormat($uid)) {
    logRfidScan($conn, $uid, null, null, null, null, 'Failed', 'Invalid RFID value');
    rfid_respond(['status' => 'error', 'title' => 'RFID Not Registered', 'message' => 'Invalid RFID value.']);
}

// Everything past this point talks to the database; wrap it so any
// unexpected failure (a query error, a missing column, etc.) always comes
// back as a clean JSON error instead of a raw PHP warning/fatal leaking to
// the kiosk screen or a blank HTTP 500.
try {

$now       = new DateTime();
$todayDate = $now->format('Y-m-d');
$todayName = getTodayName();
$nowTime   = $now->format('H:i:s');

// ── 1. Debounce: ignore an immediate repeat read of the same card ──────────
if (isDuplicateRfidScan($conn, $uid)) {
    logRfidScan($conn, $uid, null, null, null, null, 'Duplicate', 'Duplicate scan ignored (debounce)');
    rfid_respond([
        'status'  => 'duplicate',
        'title'   => 'Please Wait',
        'message' => 'Duplicate scan ignored. Please wait a moment before tapping again.',
    ]);
}

// ── 2. Identify the faculty from the card ───────────────────────────────────
$card = findFacultyByRfid($conn, $uid);

if (!$card) {
    logActivity($conn, 'RFID_SCAN_FAILED', "Unregistered RFID card scanned: $uid");
    logRfidScan($conn, $uid, null, null, null, null, 'Failed', 'RFID Card Not Registered');
    rfid_respond([
        'status'  => 'error',
        'title'   => 'RFID Not Registered',
        'message' => 'This RFID card is not assigned to any faculty account.',
    ]);
}

$facultyId   = (int)$card['faculty_id'];
$facultyName = facultyDisplayName($card['title'] ?? null, $card['name'] ?? '');

$identity = [
    'faculty_name' => $facultyName,
    'employee_id'  => $card['employee_id'] ?? '—',
    'department'   => $card['department'] ?? '—',
    'rfid_uid'     => $uid,
    'current_time' => $now->format('h:i:s A'),
    'current_date' => $now->format('F j, Y'),
];

// ── 3. Make sure today's room_logs rows exist / stale states are resolved ──
runScheduleAutomation($conn, $facultyId);

// ── 4. Pull every schedule this faculty has TODAY, with its room_log row ───
$stmt = $conn->prepare("
    SELECT l.id AS log_id, l.room_id, l.confirmation, l.checkin_at, l.checkout_at, l.status AS log_status,
           r.room_code, r.room_name, r.status AS room_status,
           s.subject, s.section, s.time_start, s.time_end, s.day_of_week, s.is_active
    FROM room_logs l
    JOIN schedules s ON s.id = l.schedule_id
    JOIN rooms r     ON r.id = l.room_id
    WHERE l.faculty_id = ? AND l.log_date = ? AND s.day_of_week = ? AND s.is_active = 1
    ORDER BY s.time_start ASC
");
$stmt->bind_param('iss', $facultyId, $todayDate, $todayName);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($rows)) {
    logActivity($conn, 'RFID_SCAN_FAILED', "{$facultyName} scanned RFID but has no scheduled class today");
    logRfidScan($conn, $uid, $facultyId, null, null, null, 'Failed', 'No Scheduled Class');
    rfid_respond(array_merge($identity, [
        'status'  => 'error',
        'title'   => 'No Scheduled Class',
        'message' => 'There is no scheduled class assigned at this time.',
    ]));
}

// Priority 1: a class the faculty is currently checked into (not yet checked out) -> toggle to Check-Out.
$checkoutRow = null;
foreach ($rows as $row) {
    if ($row['checkin_at'] !== null && $row['checkout_at'] === null) {
        $checkoutRow = $row;
        break;
    }
}

// Priority 2: a class that hasn't been checked into yet, wasn't declined via
// NO, and is still inside its attendance window -> Check-In.
//
// The attendance window is [time_start, time_start + 15 minutes) — the same
// grace period closeExpiredConfirmations()/markNoShows() use in db.php to
// flip an un-tapped class to Missed/No Show. Once that deadline passes for
// a given schedule, RFID must never check that schedule in again, even
// though class is technically still in session until time_end. This is
// re-verified again under the row lock below (race-safety), so this first
// pass only needs to get today's *candidate* schedule right.
$checkinRow = null;
foreach ($rows as $row) {
    if ($row['checkin_at'] === null && $row['checkout_at'] === null
        && $row['confirmation'] !== 'no') {
        $attendanceDeadline = date('H:i:s', strtotime($row['time_start']) + 15 * 60);
        if ($nowTime >= $row['time_start'] && $nowTime < $attendanceDeadline) {
            $checkinRow = $row;
            break;
        }
    }
}

// ── 5a. CHECK OUT ────────────────────────────────────────────────────────────
if ($checkoutRow) {
    $logId  = (int)$checkoutRow['log_id'];
    $roomId = (int)$checkoutRow['room_id'];

    $conn->begin_transaction();
    try {
        $verify = $conn->prepare("
            SELECT checkin_at, checkout_at FROM room_logs WHERE id = ? AND faculty_id = ? FOR UPDATE
        ");
        $verify->bind_param('ii', $logId, $facultyId);
        $verify->execute();
        $lockRow = $verify->get_result()->fetch_assoc();
        $verify->close();

        if (!$lockRow || $lockRow['checkin_at'] === null) {
            $conn->rollback();
            logRfidScan($conn, $uid, $facultyId, $roomId, $logId, 'CheckOut', 'Failed', 'Not checked in');
            rfid_respond(array_merge($identity, [
                'status'  => 'error',
                'title'   => 'Attendance Completed',
                'message' => 'You have already checked out for this schedule.',
            ]));
        }
        if ($lockRow['checkout_at'] !== null) {
            $conn->rollback();
            logRfidScan($conn, $uid, $facultyId, $roomId, $logId, 'CheckOut', 'Failed', 'Already Checked Out');
            rfid_respond(array_merge($identity, [
                'status'  => 'error',
                'title'   => 'Attendance Completed',
                'message' => 'You have already checked out for this schedule.',
            ]));
        }

        $upd = $conn->prepare("
            UPDATE room_logs SET checkout_at = NOW(), status = 'Available'
            WHERE id = ? AND checkin_at IS NOT NULL AND checkout_at IS NULL
        ");
        $upd->bind_param('i', $logId);
        $upd->execute();
        $affected = $upd->affected_rows;
        $upd->close();

        if ($affected !== 1) {
            $conn->rollback();
            logRfidScan($conn, $uid, $facultyId, $roomId, $logId, 'CheckOut', 'Failed', 'Race condition / already checked out');
            rfid_respond(array_merge($identity, [
                'status'  => 'error',
                'title'   => 'Attendance Completed',
                'message' => 'You have already checked out for this schedule.',
            ]));
        }

        // Room becomes Available again unless another active class still occupies it.
        $stillOccupied = $conn->prepare("
            SELECT id FROM room_logs
            WHERE room_id = ? AND log_date = CURDATE() AND checkin_at IS NOT NULL AND checkout_at IS NULL
            LIMIT 1
        ");
        $stillOccupied->bind_param('i', $roomId);
        $stillOccupied->execute();
        $occupied = $stillOccupied->get_result()->fetch_assoc();
        $stillOccupied->close();

        if (!$occupied) {
            $roomUpd = $conn->prepare("UPDATE rooms SET status = 'Available' WHERE id = ?");
            $roomUpd->bind_param('i', $roomId);
            $roomUpd->execute();
            $roomUpd->close();
        }

        logActivity($conn, 'RFID_CHECKOUT', "{$facultyName} checked out via RFID from {$checkoutRow['subject']} in {$checkoutRow['room_code']}");
        pushNotification(
            $conn,
            'Attendance Recorded',
            "{$facultyName} checked out (RFID) from {$checkoutRow['room_code']} — {$checkoutRow['subject']}.",
            'success',
            '/admin/availability.php'
        );
        logRfidScan($conn, $uid, $facultyId, $roomId, $logId, 'CheckOut', 'Success', 'Checked out successfully');

        $conn->commit();

        rfid_respond(array_merge($identity, [
            'status'  => 'success',
            'action'  => 'checkout',
            'message' => 'Checked Out Successfully',
            'subject' => $checkoutRow['subject'],
            'section' => $checkoutRow['section'],
            'room'    => $checkoutRow['room_code'] . (!empty($checkoutRow['room_name']) ? ' — ' . $checkoutRow['room_name'] : ''),
        ]));
    } catch (Throwable $e) {
        $conn->rollback();
        logRfidScan($conn, $uid, $facultyId, $roomId, $logId, 'CheckOut', 'Failed', 'Server error');
        rfid_respond(array_merge($identity, [
            'status'  => 'error',
            'title'   => 'Error',
            'message' => 'Unable to complete check-out. Please try again.',
        ]));
    }
}

// ── 5b. CHECK IN ─────────────────────────────────────────────────────────────
if ($checkinRow) {
    $logId  = (int)$checkinRow['log_id'];
    $roomId = (int)$checkinRow['room_id'];

    $conn->begin_transaction();
    try {
        $verify = $conn->prepare("
            SELECT l.confirmation, l.checkin_at, l.checkout_at, r.status AS room_status, s.time_start
            FROM room_logs l
            JOIN rooms r ON r.id = l.room_id
            JOIN schedules s ON s.id = l.schedule_id
            WHERE l.id = ? AND l.faculty_id = ?
            FOR UPDATE
        ");
        $verify->bind_param('ii', $logId, $facultyId);
        $verify->execute();
        $lockRow = $verify->get_result()->fetch_assoc();
        $verify->close();

        if (!$lockRow) {
            $conn->rollback();
            rfid_respond(array_merge($identity, ['status' => 'error', 'title' => 'Error', 'message' => 'Invalid schedule record.']));
        }

        // Validation order (re-checked here under the FOR UPDATE lock, not
        // just in the selection loop above, so nothing submitted between the
        // SELECT and this point — a NO answer, a second scan, or the clock
        // crossing the deadline — can slip through):
        //   1. RFID registered      -> already verified (findFacultyByRfid)
        //   2. Faculty exists       -> already verified (join to active users)
        //   3. Active schedule      -> already verified ($rows not empty)
        //   4. Faculty did NOT answer NO
        //   5. Not already checked in
        //   6. Not already checked out
        //   7. Attendance deadline has NOT expired

        // 4. Faculty already answered NO for this class -> RFID must not revive it.
        if ($lockRow['confirmation'] === 'no') {
            $conn->rollback();
            logRfidScan($conn, $uid, $facultyId, $roomId, $logId, 'CheckIn', 'Failed', 'Class marked as not conducted (confirmation = no)');
            rfid_respond(array_merge($identity, [
                'status'  => 'error',
                'title'   => 'Class Not Conducted',
                'message' => 'You previously selected NO for this schedule. RFID attendance has been disabled for this class.',
            ]));
        }

        // 5. Already checked in (and not yet checked out).
        if ($lockRow['checkin_at'] !== null && $lockRow['checkout_at'] === null) {
            $conn->rollback();
            logRfidScan($conn, $uid, $facultyId, $roomId, $logId, 'CheckIn', 'Failed', 'Already Checked In');
            rfid_respond(array_merge($identity, [
                'status'  => 'error',
                'title'   => 'Already Checked In',
                'message' => 'Attendance has already been recorded for this schedule.',
            ]));
        }

        // 6. Already checked out.
        if ($lockRow['checkout_at'] !== null) {
            $conn->rollback();
            logRfidScan($conn, $uid, $facultyId, $roomId, $logId, 'CheckIn', 'Failed', 'Already Checked Out');
            rfid_respond(array_merge($identity, [
                'status'  => 'error',
                'title'   => 'Attendance Completed',
                'message' => 'You have already checked out for this schedule.',
            ]));
        }

        // 7. Attendance deadline has NOT expired. This is the actual bug fix:
        // the selection loop above already excludes rows past their deadline,
        // but the clock can cross the deadline in the gap between that SELECT
        // and this FOR UPDATE lock, so it must be re-verified here too. If it
        // has expired, reject outright — no attendance record, no room/report
        // update, just a logged rejection. The class was already flipped to
        // Missed/No Show by closeExpiredConfirmations()/markNoShows() in
        // db.php (run just above via runScheduleAutomation()), so this is
        // purely a defensive re-check, not the primary enforcement point.
        $attendanceDeadline = date('H:i:s', strtotime($lockRow['time_start']) + 15 * 60);
        if ($nowTime >= $attendanceDeadline) {
            $conn->rollback();
            logRfidScan($conn, $uid, $facultyId, $roomId, $logId, 'CheckIn', 'Failed', 'Attendance deadline expired');
            rfid_respond(array_merge($identity, [
                'status'  => 'error',
                'title'   => 'Attendance Rejected',
                'message' => 'The attendance period for this class has already expired. This class has been marked as Missed. RFID Check-In is no longer allowed.',
            ]));
        }

        // Someone else physically occupying the room -> block, same rule as the manual flow.
        $other = $conn->prepare("
            SELECT id FROM room_logs
            WHERE room_id = ? AND log_date = CURDATE() AND checkin_at IS NOT NULL AND checkout_at IS NULL AND id != ?
            LIMIT 1
        ");
        $other->bind_param('ii', $roomId, $logId);
        $other->execute();
        $otherOccupant = $other->get_result()->fetch_assoc();
        $other->close();

        if ($otherOccupant || $lockRow['room_status'] === 'Occupied') {
            $conn->rollback();
            logRfidScan($conn, $uid, $facultyId, $roomId, $logId, 'CheckIn', 'Failed', 'Room already occupied');
            rfid_respond(array_merge($identity, [
                'status'  => 'error',
                'title'   => 'Room Occupied',
                'message' => "Room {$checkinRow['room_code']} is currently occupied by another faculty member.",
            ]));
        }

        // RFID tap at the room IS the physical confirmation — auto-confirm if not already confirmed.
        if ($lockRow['confirmation'] === null) {
            $confirm = $conn->prepare("UPDATE room_logs SET confirmation = 'yes', confirmed_at = NOW() WHERE id = ?");
            $confirm->bind_param('i', $logId);
            $confirm->execute();
            $confirm->close();
            logActivity($conn, 'CONFIRM_YES', "{$facultyName} auto-confirmed {$checkinRow['subject']} via RFID tap");
        }

        $upd = $conn->prepare("
            UPDATE room_logs SET checkin_at = NOW(), status = 'Occupied'
            WHERE id = ? AND checkin_at IS NULL AND checkout_at IS NULL
        ");
        $upd->bind_param('i', $logId);
        $upd->execute();
        $affected = $upd->affected_rows;
        $upd->close();

        if ($affected !== 1) {
            $conn->rollback();
            logRfidScan($conn, $uid, $facultyId, $roomId, $logId, 'CheckIn', 'Failed', 'Race condition / already checked in');
            rfid_respond(array_merge($identity, [
                'status'  => 'error',
                'title'   => 'Already Checked In',
                'message' => 'Attendance has already been recorded for this schedule.',
            ]));
        }

        $roomUpd = $conn->prepare("UPDATE rooms SET status = 'Occupied' WHERE id = ?");
        $roomUpd->bind_param('i', $roomId);
        $roomUpd->execute();
        $roomUpd->close();

        logActivity($conn, 'RFID_CHECKIN', "{$facultyName} checked in via RFID for {$checkinRow['subject']} in {$checkinRow['room_code']}");
        pushNotification(
            $conn,
            'Attendance Recorded',
            "{$facultyName} checked in (RFID) to {$checkinRow['room_code']} — {$checkinRow['subject']}.",
            'info',
            '/admin/availability.php'
        );
        logRfidScan($conn, $uid, $facultyId, $roomId, $logId, 'CheckIn', 'Success', 'Checked in successfully');

        $conn->commit();

        rfid_respond(array_merge($identity, [
            'status'  => 'success',
            'action'  => 'checkin',
            'message' => 'Checked In Successfully',
            'subject' => $checkinRow['subject'],
            'section' => $checkinRow['section'],
            'room'    => $checkinRow['room_code'] . (!empty($checkinRow['room_name']) ? ' — ' . $checkinRow['room_name'] : ''),
        ]));
    } catch (Throwable $e) {
        $conn->rollback();
        logRfidScan($conn, $uid, $facultyId, $roomId, $logId, 'CheckIn', 'Failed', 'Server error');
        rfid_respond(array_merge($identity, [
            'status'  => 'error',
            'title'   => 'Error',
            'message' => 'Unable to complete check-in. Please try again.',
        ]));
    }
}

// ── 6. Neither check-in nor check-out applies right now — explain why ──────
// Determine the ACTIVE/relevant schedule first, per faculty + schedule +
// date — never a single "faculty-level" fact. Picking purely by which
// schedule's start time is numerically closest to "now" (the old logic)
// could pick an already-finished earlier class over a still-pending later
// one just because its start time happened to be closer on the clock,
// wrongly reporting "Already Checked Out" for the whole day when another
// schedule is actually still available later. Prefer any schedule that
// isn't fully completed yet; only fall back to a completed one if every
// schedule for today is actually done.
$incompleteRows = array_values(array_filter($rows, function ($row) {
    return !($row['checkin_at'] !== null && $row['checkout_at'] !== null);
}));
$candidateRows = $incompleteRows ?: $rows;

$closest = null;
foreach ($candidateRows as $row) {
    if ($closest === null
        || abs(strtotime($row['time_start']) - strtotime($nowTime)) < abs(strtotime($closest['time_start']) - strtotime($nowTime))) {
        $closest = $row;
    }
}

if ($closest && $closest['checkin_at'] !== null && $closest['checkout_at'] !== null) {
    // Only reachable when every schedule today is completed (candidateRows
    // fell back to $rows because $incompleteRows was empty).
    $title   = 'Attendance Completed';
    $reason  = 'You have already checked out for this schedule.';
    $logNote = 'Attendance already completed for today';
} elseif ($closest && ($closest['confirmation'] ?? null) === 'no') {
    $title   = 'Class Not Conducted';
    $reason  = 'You previously selected NO for this schedule. RFID attendance has been disabled for this class.';
    $logNote = 'Class declined by faculty (confirmation = no)';
} elseif ($closest && $nowTime < $closest['time_start']) {
    $title   = 'Too Early';
    $reason  = 'The attendance window has not opened yet. Please scan your RFID card when attendance becomes available.';
    $logNote = 'Too early — class has not started yet';
} else {
    // Not too early, not declined, not already completed -> the only case
    // left is that the 15-minute attendance deadline has passed (the
    // selection loop above only ever picks a $checkinRow while nowTime is
    // still inside [time_start, time_start + 15min)). The class was already
    // flipped to Missed/No Show by db.php's automation, so this is purely
    // informational — no attendance record, room status, or report is
    // touched here.
    $title   = 'Attendance Rejected';
    $reason  = 'The attendance period for this class has already expired. This class has been marked as Missed. RFID Check-In is no longer allowed.';
    $logNote = 'Too late — attendance deadline has expired';
}

logActivity($conn, 'RFID_SCAN_FAILED', "{$facultyName} scanned RFID — {$reason} ({$logNote})");
logRfidScan($conn, $uid, $facultyId, $closest['room_id'] ?? null, $closest['log_id'] ?? null, null, 'Failed', $logNote);

rfid_respond(array_merge($identity, [
    'status'  => 'error',
    'title'   => $title,
    'message' => $reason,
]));

} catch (Throwable $e) {
    // Never expose the raw PHP/SQL error to the kiosk screen. rfid_respond()
    // already exit()s, so this only runs for failures not already handled
    // by the try/catch blocks around the individual Check-In/Check-Out
    // transactions above (e.g. an error while resolving the schedule query
    // itself, before either transaction started).
    // Rolling back with no open transaction is a harmless no-op in mysqli,
    // so this is safe to call unconditionally as a final safety net.
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    rfid_respond(['status' => 'error', 'title' => 'Error', 'message' => 'Unexpected System Error. Please try again.']);
}
