<?php
/**
 * rfid_helpers.php
 * ---------------------------------------------------------------------------
 * Support functions for the 13.56 MHz USB "keyboard-emulation" RFID reader
 * feature. The reader itself needs NO driver/Arduino code — it just types the
 * card's UID followed by Enter into whatever input is focused, so all of the
 * "hardware integration" here is really just: (1) know which faculty a UID
 * belongs to, (2) figure out today's active class for that faculty, and
 * (3) toggle Check-In / Check-Out on the SAME room_logs rows the existing
 * manual dashboard buttons already use — so Reports, Room Status and
 * Notifications all stay perfectly in sync with zero extra work.
 *
 * This file self-provisions its own tables (rfid_cards, rfid_scan_logs) the
 * first time they're needed, exactly like ensureFacultyTrustedDeviceTable()
 * already does in db.php for faculty_trusted_devices. That means there is
 * NO manual migration.sql step required for deployment — uploading the
 * updated project to Hostinger and loading any RFID page is enough.
 */

/**
 * Create the rfid_cards / rfid_scan_logs tables if they don't already exist.
 * Safe to call on every request — tableExists() short-circuits once created.
 */
function ensureRfidTables(mysqli $conn): bool {
    $ok = true;

    if (!tableExists($conn, 'rfid_cards')) {
        $created = $conn->query("
            CREATE TABLE IF NOT EXISTS rfid_cards (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                uid            VARCHAR(64) NOT NULL,
                faculty_id     INT NOT NULL,
                status         ENUM('Active','Deactivated','Lost') NOT NULL DEFAULT 'Active',
                notes          VARCHAR(255) DEFAULT NULL,
                issued_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deactivated_at DATETIME DEFAULT NULL,
                KEY idx_rfid_cards_uid      (uid),
                KEY idx_rfid_cards_faculty  (faculty_id),
                KEY idx_rfid_cards_status   (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        if ($created) markTableCreated('rfid_cards');
        $ok = $created && $ok;
    }

    if (!tableExists($conn, 'rfid_scan_logs')) {
        $created = $conn->query("
            CREATE TABLE IF NOT EXISTS rfid_scan_logs (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                rfid_uid   VARCHAR(64) NOT NULL,
                faculty_id INT DEFAULT NULL,
                room_id    INT DEFAULT NULL,
                log_id     INT DEFAULT NULL,
                action     VARCHAR(20) DEFAULT NULL,
                result     ENUM('Success','Failed','Duplicate') NOT NULL DEFAULT 'Failed',
                reason     VARCHAR(255) DEFAULT NULL,
                scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_rfid_scan_uid     (rfid_uid),
                KEY idx_rfid_scan_faculty (faculty_id),
                KEY idx_rfid_scan_time    (scanned_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        if ($created) markTableCreated('rfid_scan_logs');
        $ok = $created && $ok;
    }

    $ok = $ok && tableExists($conn, 'rfid_cards') && tableExists($conn, 'rfid_scan_logs');
    if ($ok) repairRfidTableColumns($conn);
    return $ok;
}

/**
 * Self-heals rfid_cards / rfid_scan_logs if either table already exists
 * (from an earlier or partial deployment) but is missing a column the
 * current code relies on.
 *
 * This is the actual root cause behind "Recent RFID Scan Logs stays empty
 * even though Check-In/Check-Out work fine": ensureRfidTables() only ever
 * runs CREATE TABLE IF NOT EXISTS, so once a table exists — even with the
 * wrong columns — it is never looked at again. logRfidScan()'s INSERT then
 * calls $conn->prepare(), which fails whenever a column it references is
 * missing. Because mysqli_report(MYSQLI_REPORT_OFF) is intentionally set
 * (see db.php) so raw SQL errors are never shown to users, that prepare()
 * failure is completely silent: `if (!$stmt) return;` just quietly does
 * nothing. Check-In/Check-Out still work perfectly because they write to
 * room_logs/activity_logs/notifications — separate, unaffected tables —
 * so nothing else looks broken. Reproduced and confirmed against a live
 * MySQL instance with a deliberately incomplete rfid_scan_logs table before
 * this fix (0 rows written, no error anywhere); confirmed fixed after.
 */
function repairRfidTableColumns(mysqli $conn): void {
    $rfidCardsColumns = [
        'status'         => "ADD COLUMN status ENUM('Active','Deactivated','Lost') NOT NULL DEFAULT 'Active'",
        'notes'          => "ADD COLUMN notes VARCHAR(255) DEFAULT NULL",
        'issued_at'      => "ADD COLUMN issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'deactivated_at' => "ADD COLUMN deactivated_at DATETIME DEFAULT NULL",
    ];
    foreach ($rfidCardsColumns as $column => $ddl) {
        if (!columnExists($conn, 'rfid_cards', $column)) {
            $conn->query("ALTER TABLE rfid_cards $ddl");
        }
    }

    $scanLogColumns = [
        'faculty_id' => "ADD COLUMN faculty_id INT DEFAULT NULL",
        'room_id'    => "ADD COLUMN room_id INT DEFAULT NULL",
        'log_id'     => "ADD COLUMN log_id INT DEFAULT NULL",
        'action'     => "ADD COLUMN action VARCHAR(20) DEFAULT NULL",
        'result'     => "ADD COLUMN result ENUM('Success','Failed','Duplicate') NOT NULL DEFAULT 'Failed'",
        'reason'     => "ADD COLUMN reason VARCHAR(255) DEFAULT NULL",
        'scanned_at' => "ADD COLUMN scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    foreach ($scanLogColumns as $column => $ddl) {
        if (!columnExists($conn, 'rfid_scan_logs', $column)) {
            $conn->query("ALTER TABLE rfid_scan_logs $ddl");
        }
    }
}

/**
 * Normalize a raw RFID UID exactly as it arrives from the keyboard-emulation
 * reader (or a manual admin form field) before it touches any query or
 * comparison: trims surrounding whitespace, then strips control/invisible
 * characters some USB readers inject (stray NULs, BOM bytes, stray tabs from
 * a double keystroke) that plain trim() does not remove.
 */
function sanitizeRfidUid(string $raw): string {
    $uid = trim($raw);
    // Strip ASCII control characters (0x00-0x1F, 0x7F) anywhere in the string.
    $uid = preg_replace('/[\x00-\x1F\x7F]/u', '', $uid) ?? '';
    return trim($uid);
}

/**
 * Reject UIDs that are structurally impossible for a real RFID card (empty,
 * absurdly long, or containing characters no known 13.56MHz reader emits).
 * Real UIDs are hex or decimal digit strings, sometimes with reader-added
 * separators. This is intentionally permissive about separators so it never
 * rejects a legitimate card, while still catching stray junk/garbage input.
 */
function isValidRfidUidFormat(string $uid): bool {
    if ($uid === '') return false;
    if (strlen($uid) > 64) return false;
    return (bool)preg_match('/^[A-Za-z0-9:\-]+$/', $uid);
}

/**
 * Record one scan attempt (success, failure, or ignored duplicate) into
 * rfid_scan_logs, shown on the RFID Management page's "Recent Scan Logs".
 */
function logRfidScan(
    mysqli $conn,
    string $uid,
    ?int $facultyId,
    ?int $roomId,
    ?int $logId,
    ?string $action,
    string $result,
    string $reason
): void {
    if (!ensureRfidTables($conn)) return;

    $stmt = $conn->prepare("
        INSERT INTO rfid_scan_logs (rfid_uid, faculty_id, room_id, log_id, action, result, reason)
        VALUES (?,?,?,?,?,?,?)
    ");
    if (!$stmt) {
        // prepare() failing here (even after repairRfidTableColumns()) means
        // something about the live table still doesn't match what this
        // query expects — surface that fact somewhere visible instead of
        // disappearing without a trace, without exposing the raw SQL error.
        if (function_exists('logActivity')) {
            logActivity($conn, 'RFID_SCAN_FAILED', "RFID scan log could not be recorded for UID $uid (scan log table unavailable — check rfid_scan_logs columns).");
        }
        return;
    }
    $stmt->bind_param('siiisss', $uid, $facultyId, $roomId, $logId, $action, $result, $reason);
    $stmt->execute();
    $stmt->close();
}

/**
 * Debounce guard: some USB RFID readers fire the Enter keystroke twice, or a
 * card left sitting on the reader gets re-read every second or so. Either
 * one would otherwise be processed as a brand-new scan every time.
 *
 * This checks for ANY prior scan of the same UID within the cooldown window
 * regardless of whether that prior scan succeeded, failed, or was itself a
 * duplicate — not just successful ones. Limiting the check to 'Success'
 * only (as before) meant a card that failed once (e.g. "No Scheduled
 * Class") and was then held on the reader would spam a brand-new failed
 * scan/activity-log/notification entry every ~1 second for as long as the
 * card sat there. Cooldown is configurable and defaults to 3 seconds, the
 * middle of the requested 2-3 second range.
 */
function isDuplicateRfidScan(mysqli $conn, string $uid, int $withinSeconds = 3): bool {
    if ($uid === '' || !tableExists($conn, 'rfid_scan_logs')) return false;

    $stmt = $conn->prepare("
        SELECT id FROM rfid_scan_logs
        WHERE rfid_uid = ?
          AND scanned_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ORDER BY id DESC LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param('si', $uid, $withinSeconds);
    $stmt->execute();
    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $found;
}

/**
 * Resolve an RFID UID to its currently-assigned, active faculty account.
 * Returns null if the card is unknown, deactivated/lost, or the faculty
 * account itself is inactive.
 */
function findFacultyByRfid(mysqli $conn, string $uid): ?array {
    if (!ensureRfidTables($conn)) return null;

    $stmt = $conn->prepare("
        SELECT c.id AS card_id, c.uid, c.faculty_id,
               u.name, u.title, u.employee_id, u.department
        FROM rfid_cards c
        JOIN users u ON u.id = c.faculty_id
        WHERE c.uid = ? AND c.status = 'Active'
          AND u.role = 'faculty' AND u.is_active = 1
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param('s', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
