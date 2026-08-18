<?php
$servername = "localhost";
$username = "leijigaviola_backsmart_room_user";
$password = "_7@tT]_3Vfa87(9O";
$database = "leijigaviola_back_smart_room_db";

date_default_timezone_set('Asia/Manila');

mysqli_report(MYSQLI_REPORT_OFF);

require_once __DIR__ . '/mail_helper.php';

require_once __DIR__ . '/rfid_helpers.php';

$conn = mysqli_connect($servername, $username, $password, $database);
if (!$conn) {
    die('<div style="font-family:sans-serif;padding:2rem;color:#dc3545;">
        <h2>⚠️ Database Connection Failed</h2>
        <p>' . htmlspecialchars(mysqli_connect_error(), ENT_QUOTES, 'UTF-8') . '</p>
        <p>Please check your database settings in <strong>db.php</strong>.</p>
    </div>');
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function getTodayName() {
    return date('l');
}

function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function requireLogin($role = null) {
    global $conn;

    if (!isset($_SESSION['user_id'])) {
        redirect('/index.php');
    }
    if ($role && ($_SESSION['user_role'] ?? null) !== $role) {
        redirect('/index.php');
    }

    if (($_SESSION['user_role'] ?? '') === 'faculty' && isset($conn)) {
        $currentPath = str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? '');
        $allowed = [
            '/faculty/face_registration.php',
            '/faculty/face_verification.php',
            '/faculty/email_otp.php',
            '/faculty/complete_profile.php',
            '/faculty/profile.php',
            '/faculty/change_password.php',
            '/logout.php',
        ];

        // Block access if login OTP is pending (2FA step)
        if (isset($_SESSION['pending_otp_user_id']) && $currentPath !== '/faculty/email_otp.php') {
            redirect('/faculty/email_otp.php?mode=login');
        }

        // Block access if credential login is waiting for live face verification.
        if (isset($_SESSION['pending_face_user_id']) && $currentPath !== '/faculty/face_verification.php') {
            redirect('/faculty/face_verification.php?mode=login');
        }

        $faculty = getFacultyOnboarding($conn, (int)$_SESSION['user_id']);
        if (!$faculty || in_array($faculty['account_status'], ['Inactive', 'Disabled'], true)) {
            session_destroy();
            redirect('/index.php?role=faculty');
        }

        if ($faculty['account_status'] === 'Pending Face Registration') {
            $faculty['account_status'] = 'Pending Face Verification';
        }

        if ($faculty['account_status'] === 'Pending Face Verification' && !in_array($currentPath, $allowed, true)) {
            redirect('/faculty/face_verification.php');
        }

        if ($faculty['account_status'] === 'Pending Email Verification' && !in_array($currentPath, $allowed, true)) {
            redirect('/faculty/email_otp.php');
        }

        if ($faculty['account_status'] === 'Active' && !isFacultyProfileComplete($conn, $faculty) && !in_array($currentPath, $allowed, true)) {
            redirect('/faculty/complete_profile.php');
        }
    }
}

// ── Password strength validation ────────────────────────────────────────────
//
// Single source of truth for the "new password" strength rule used by every
// PERMANENT password creation/change/reset form (admin change password,
// faculty change password — including the forced change after the temporary
// password — admin add faculty, admin reset faculty password, and the shared
// forgot-password reset flow). This intentionally does NOT touch the
// system-generated temporary password used during faculty registration
// approval (PASSWORD2026) — that value stays exactly as-is and is never
// passed through this validator; the rule only applies once the faculty
// member (or admin) chooses their own password.

/**
 * A short blocklist of the most common/weak passwords. This intentionally
 * stays small and generic (not a full "top 10,000 passwords" dictionary) —
 * the point is to catch the obvious ones; the composition rules below
 * (length/upper/lower/number/special) already rule out most weak passwords
 * on their own.
 */
function getCommonWeakPasswords() {
    return [
        '12345678', '123456', '1234567', '123456789', '1234567890',
        'password', 'password1', 'password123', 'passw0rd',
        'qwerty', 'qwerty123', 'qwertyuiop',
        '11111111', '00000000', '87654321',
        'letmein', 'welcome', 'admin123', 'iloveyou',
        'abc12345', 'abcd1234', 'changeme',
    ];
}

/**
 * Validates a NEW permanent password against the system's strength policy:
 *   - Minimum 8 characters
 *   - At least 1 uppercase letter
 *   - At least 1 lowercase letter
 *   - At least 1 number
 *   - At least 1 special character
 *   - Not a common/weak password (case-insensitive match against a short blocklist)
 *   - Not obviously based on the account's own name/username/email, when that
 *     context is available
 *
 * Returns an array of human-readable error strings — empty array means the
 * password passes. Callers should treat "no errors" as the only success
 * condition (do not rely on a boolean return).
 *
 * $context (all optional) lets callers pass along whatever identifying info
 * they already have on hand so the "not based on your name/username" check
 * can run; omit fields that aren't available and that check is simply
 * skipped.
 */
function validatePasswordStrength(string $password, array $context = []) {
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must contain at least one special character (e.g. ! @ # $ % ^ & *).';
    }

    $lowerPw = strtolower($password);
    foreach (getCommonWeakPasswords() as $weak) {
        if ($lowerPw === strtolower($weak)) {
            $errors[] = 'This password is too common. Please choose a stronger password.';
            break;
        }
    }

    // Reject passwords obviously built from the account's own identifying
    // info (e.g. username "jdelacruz" + password "Jdelacruz1!"). Only runs
    // for name/username/email pieces that are at least 3 characters, to
    // avoid false positives on short/common substrings.
    $identityPieces = [];
    if (!empty($context['name']))     $identityPieces = array_merge($identityPieces, preg_split('/\s+/', trim($context['name'])));
    if (!empty($context['username'])) $identityPieces[] = $context['username'];
    if (!empty($context['email']))    $identityPieces[] = strtok($context['email'], '@');
    foreach ($identityPieces as $piece) {
        $piece = trim((string)$piece);
        if (strlen($piece) >= 3 && stripos($password, $piece) !== false) {
            $errors[] = 'Password must not be based on your name, username, or email.';
            break;
        }
    }

    return $errors;
}

// ── Faculty title helpers ─────────────────────────────────────────────────────

/**
 * Allowed faculty titles. Admin picks one of these (or blank) when
 * creating/editing a faculty account. Stored as-is in users.title.
 */
function getFacultyTitleOptions() {
    return ['Dr.', 'Prof.', 'Mr.', 'Mrs.', 'Ms.',];
}

/**
 * Build the "Title Full Name" display string for a faculty member.
 * Falls back to the plain name when no title is set, so nothing is
 * ever hardcoded — every prefix shown comes from the database.
 */
function facultyDisplayName($title, $name) {
    $title = trim((string)($title ?? ''));
    $name  = trim((string)($name ?? ''));
    return $title !== '' ? $title . ' ' . $name : $name;
}

function statusBadge($status) {
    $map = [
        'Available'           => 'success',
        'Scheduled'           => 'primary',
        'Reserved'            => 'warning',
        'Occupied'            => 'danger',
        'Unconfirmed'         => 'secondary',
        'Missed Confirmation' => 'dark',
        'No Show'             => 'danger',
    ];
    $color = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . htmlspecialchars($status) . '</span>';
}

/**
 * tableExists()/columnExists() are called many times per request (every
 * ensure*Table() self-provisioning helper, every optional-column check in
 * onboarding/profile completion, every RFID lookup) but the schema cannot
 * change mid-request under normal operation, so results are memoized in
 * $GLOBALS for the lifetime of the request. This removes dozens of redundant
 * information_schema round-trips per page load (a single RFID scan alone
 * used to run 6-10 of these). markTableCreated() lets a self-provisioning
 * helper update the cache directly right after a successful CREATE TABLE,
 * instead of paying for another information_schema query to confirm it.
 */
function tableExists($conn, $table) {
    if (array_key_exists($table, $GLOBALS['__schema_cache_tables'] ?? [])) {
        return $GLOBALS['__schema_cache_tables'][$table];
    }
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    $GLOBALS['__schema_cache_tables'][$table] = $exists;
    return $exists;
}

function markTableCreated(string $table): void {
    $GLOBALS['__schema_cache_tables'][$table] = true;
}

function columnExists($conn, $table, $column) {
    $key = $table . '.' . $column;
    if (array_key_exists($key, $GLOBALS['__schema_cache_columns'] ?? [])) {
        return $GLOBALS['__schema_cache_columns'][$key];
    }
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    $GLOBALS['__schema_cache_columns'][$key] = $exists;
    return $exists;
}


function deleteRegistrationRequestRow($conn, array $row): bool {
    if (empty($row['id'])) return false;

    if (!empty($row['face_image'])) {
        // face_image is stored as a relative path like 'uploads/requests/xxx.jpg'
        $abs = __DIR__ . DIRECTORY_SEPARATOR . $row['face_image'];
        if (is_file($abs)) @unlink($abs);
    }

    $del = $conn->prepare("DELETE FROM registration_requests WHERE id = ?");
    if (!$del) return false;
    $del->bind_param('i', $row['id']);
    $ok = $del->execute();
    $del->close();
    return $ok;
}

function getFacultyOnboarding($conn, $userId) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'faculty' LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $faculty = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$faculty) return null;

    if (!columnExists($conn, 'users', 'account_status') || empty($faculty['account_status'])) {
        $faculty['account_status'] = ((int)($faculty['is_active'] ?? 1) === 1) ? 'Active' : 'Inactive';
    } elseif ($faculty['account_status'] === 'Pending Face Registration') {
        $faculty['account_status'] = 'Pending Face Verification';
    }

    return $faculty;
}

function isFacultyProfileComplete($conn, array $faculty) {
    $requiredColumns = ['employee_id', 'department', 'phone'];

    // office is required if the column exists
    if (columnExists($conn, 'users', 'office')) $requiredColumns[] = 'office';

    // New split emergency contact columns (preferred)
    if (columnExists($conn, 'users', 'emergency_name')) {
        $requiredColumns[] = 'emergency_name';
        $requiredColumns[] = 'emergency_phone';
        // relationship is required too
        if (columnExists($conn, 'users', 'emergency_relationship')) {
            $requiredColumns[] = 'emergency_relationship';
        }
    } elseif (columnExists($conn, 'users', 'emergency_contact')) {
        // Legacy single-field fallback
        $requiredColumns[] = 'emergency_contact';
    }

    foreach ($requiredColumns as $column) {
        if (trim((string)($faculty[$column] ?? '')) === '') return false;
    }
    return true;
}

function ensureTodayRoomLogs($conn, $facultyId = null) {
    if (!tableExists($conn, 'schedules') || !tableExists($conn, 'room_logs')) return;

    $todayDate = date('Y-m-d');
    $todayName = getTodayName();
    if ($facultyId) {
        $missingLogs = $conn->prepare("
            SELECT s.id AS schedule_id, s.faculty_id, s.room_id
            FROM schedules s
            LEFT JOIN room_logs rl ON rl.schedule_id = s.id AND rl.log_date = ?
            WHERE s.faculty_id = ? AND s.day_of_week = ? AND s.is_active = 1
              AND rl.id IS NULL
        ");
        if (!$missingLogs) return;
        $missingLogs->bind_param('sis', $todayDate, $facultyId, $todayName);
    } else {
        $missingLogs = $conn->prepare("
            SELECT s.id AS schedule_id, s.faculty_id, s.room_id
            FROM schedules s
            LEFT JOIN room_logs rl ON rl.schedule_id = s.id AND rl.log_date = ?
            WHERE s.day_of_week = ? AND s.is_active = 1
              AND rl.id IS NULL
        ");
        if (!$missingLogs) return;
        $missingLogs->bind_param('ss', $todayDate, $todayName);
    }

    $missingLogs->execute();
    $missingResult = $missingLogs->get_result();
    $autoInsert = $conn->prepare("
        INSERT INTO room_logs (schedule_id, faculty_id, room_id, log_date, status)
        VALUES (?, ?, ?, ?, 'Unconfirmed')
    ");
    // Re-check immediately before each insert. This is called on nearly every
    // page load and from every RFID scan (via runScheduleAutomation), so two
    // requests can race between the SELECT above and the INSERT below and
    // both try to create the same day's room_logs row. A fresh, targeted
    // existence check right here shrinks that window from "one query's worth
    // of PHP + network time" down to "one query" without touching the
    // room_logs schema (no unique index to add/verify since no .sql schema
    // ships with this project).
    $recheck = $conn->prepare("SELECT id FROM room_logs WHERE schedule_id = ? AND log_date = ? LIMIT 1");
    if ($autoInsert && $recheck) {
        while ($missing = $missingResult->fetch_assoc()) {
            $scheduleId = (int)$missing['schedule_id'];
            $missingFacultyId = (int)$missing['faculty_id'];
            $roomId = (int)$missing['room_id'];

            $recheck->bind_param('is', $scheduleId, $todayDate);
            $recheck->execute();
            $already = $recheck->get_result()->fetch_assoc();
            if ($already) continue;

            $autoInsert->bind_param('iiis', $scheduleId, $missingFacultyId, $roomId, $todayDate);
            $autoInsert->execute();
        }
        $recheck->close();
        $autoInsert->close();
    }
    $missingLogs->close();
}

/**
 * ensureTodayRoomLogs() (above) only ever creates room_logs rows for
 * TODAY. It is called from nearly every admin/faculty page load, so in
 * normal day-to-day use each day's rows get created the first time anyone
 * touches the system that day. But if nobody opens the app on a given
 * calendar day, that day's scheduled classes never get a room_logs row at
 * all — there is nothing wrong with the Activity & Reports query in that
 * case, there is simply no row to find. This is the root cause behind
 * "wider date range still shows nothing": the gap is in row creation, not
 * in the filter.
 *
 * This fills in ONLY those gaps, for a bounded recent window (default 14
 * days) so it stays cheap to run on every admin page load — it is not a
 * full historical backfill back to whenever the system went live, since
 * schedules don't carry a creation date we could safely anchor that to.
 *
 * Backfilled rows start as 'Unconfirmed', exactly like ensureTodayRoomLogs()
 * does for today. runScheduleAutomation() calls closeExpiredConfirmations()
 * and markNoShows() right after this, and both already operate on any
 * log_date <= CURDATE() — so a backfilled row for a past date is correctly
 * swept into 'Missed Confirmation' or 'No Show' on the same request,
 * exactly as it would have been if the admin had visited that day. Nothing
 * is fabricated: this only records that a class was scheduled and nobody
 * ever confirmed/checked in, which is true regardless of when we notice it.
 */
function backfillMissingRoomLogs($conn, $days = 14) {
    if (!tableExists($conn, 'schedules') || !tableExists($conn, 'room_logs')) return;

    $days = max(1, min(14, (int)$days));

    $missingLogs = $conn->prepare("
        SELECT s.id AS schedule_id, s.faculty_id, s.room_id, d.log_date
        FROM schedules s
        JOIN (
            SELECT CURDATE() - INTERVAL n.n DAY AS log_date,
                   DAYNAME(CURDATE() - INTERVAL n.n DAY) AS day_name
            FROM (
                SELECT 1 AS n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
                UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10
                UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14
            ) n
            WHERE n.n <= ?
        ) d ON d.day_name = s.day_of_week
        LEFT JOIN room_logs rl ON rl.schedule_id = s.id AND rl.log_date = d.log_date
        WHERE s.is_active = 1 AND rl.id IS NULL
    ");
    if (!$missingLogs) return;
    $missingLogs->bind_param('i', $days);
    $missingLogs->execute();
    $missingResult = $missingLogs->get_result();

    $autoInsert = $conn->prepare("
        INSERT INTO room_logs (schedule_id, faculty_id, room_id, log_date, status)
        VALUES (?, ?, ?, ?, 'Unconfirmed')
    ");
    // Same defensive re-check pattern as ensureTodayRoomLogs(): guards
    // against two concurrent admin requests both trying to backfill the
    // same gap, without needing a unique index / schema change.
    $recheck = $conn->prepare("SELECT id FROM room_logs WHERE schedule_id = ? AND log_date = ? LIMIT 1");
    if ($autoInsert && $recheck) {
        while ($missing = $missingResult->fetch_assoc()) {
            $scheduleId = (int)$missing['schedule_id'];
            $missingFacultyId = (int)$missing['faculty_id'];
            $roomId = (int)$missing['room_id'];
            $logDate = $missing['log_date'];

            $recheck->bind_param('is', $scheduleId, $logDate);
            $recheck->execute();
            $already = $recheck->get_result()->fetch_assoc();
            if ($already) continue;

            $autoInsert->bind_param('iiis', $scheduleId, $missingFacultyId, $roomId, $logDate);
            $autoInsert->execute();
        }
        $recheck->close();
        $autoInsert->close();
    }
    $missingLogs->close();
}

function refreshRoomStatus($conn, $roomId) {
    $roomId = (int)$roomId;
    if ($roomId <= 0) return;

    $stmt = $conn->prepare("
        UPDATE rooms r
        LEFT JOIN (
            SELECT room_logs.room_id,
                   MAX(room_logs.status = 'Occupied' AND room_logs.checkout_at IS NULL) AS has_occupied,
                   MAX(room_logs.status = 'Reserved' AND room_logs.checkout_at IS NULL) AS has_reserved,
                   MAX(room_logs.status = 'Unconfirmed'
                       AND room_logs.checkout_at IS NULL
                       AND NOW() >= DATE_SUB(CONCAT(room_logs.log_date, ' ', active_s.time_start), INTERVAL 10 MINUTE)
                       AND NOW() < CONCAT(room_logs.log_date, ' ', active_s.time_start)) AS has_unconfirmed
            FROM room_logs
            JOIN schedules active_s ON active_s.id = room_logs.schedule_id
            WHERE room_logs.room_id = ? AND room_logs.log_date = CURDATE()
            GROUP BY room_logs.room_id
        ) active_logs ON active_logs.room_id = r.id
        LEFT JOIN (
            SELECT s.room_id, 1 AS has_upcoming
            FROM schedules s
            WHERE s.room_id = ? AND s.day_of_week = ? AND s.is_active = 1
              AND CONCAT(CURDATE(), ' ', s.time_end) >= NOW()
              AND NOT EXISTS (
                  SELECT 1 FROM room_logs done
                  WHERE done.schedule_id = s.id
                    AND done.log_date = CURDATE()
                    AND (done.checkout_at IS NOT NULL OR done.status IN ('Available', 'Missed Confirmation', 'No Show'))
              )
            GROUP BY s.room_id
        ) s ON s.room_id = r.id
        SET r.status = CASE
            WHEN active_logs.has_occupied = 1 THEN 'Occupied'
            WHEN active_logs.has_reserved = 1 THEN 'Reserved'
            WHEN active_logs.has_unconfirmed = 1 THEN 'Unconfirmed'
            WHEN s.has_upcoming = 1 THEN 'Scheduled'
            ELSE 'Available'
        END
        WHERE r.id = ?
    ");
    if (!$stmt) return;
    $today = getTodayName();
    $stmt->bind_param('iisi', $roomId, $roomId, $today, $roomId);
    $stmt->execute();
    $stmt->close();
}

function refreshAllRoomStatuses($conn) {
    $stmt = $conn->prepare("
        UPDATE rooms r
        LEFT JOIN (
            SELECT room_logs.room_id,
                   MAX(room_logs.status = 'Occupied' AND room_logs.checkout_at IS NULL) AS has_occupied,
                   MAX(room_logs.status = 'Reserved' AND room_logs.checkout_at IS NULL) AS has_reserved,
                   MAX(room_logs.status = 'Unconfirmed'
                       AND room_logs.checkout_at IS NULL
                       AND NOW() >= DATE_SUB(CONCAT(room_logs.log_date, ' ', active_s.time_start), INTERVAL 10 MINUTE)
                       AND NOW() < CONCAT(room_logs.log_date, ' ', active_s.time_start)) AS has_unconfirmed
            FROM room_logs
            JOIN schedules active_s ON active_s.id = room_logs.schedule_id
            WHERE room_logs.log_date = CURDATE()
            GROUP BY room_logs.room_id
        ) active_logs ON active_logs.room_id = r.id
        LEFT JOIN (
            SELECT s.room_id, 1 AS has_upcoming
            FROM schedules s
            WHERE s.day_of_week = ? AND s.is_active = 1
              AND CONCAT(CURDATE(), ' ', s.time_end) >= NOW()
              AND NOT EXISTS (
                  SELECT 1 FROM room_logs done
                  WHERE done.schedule_id = s.id
                    AND done.log_date = CURDATE()
                    AND (done.checkout_at IS NOT NULL OR done.status IN ('Available', 'Missed Confirmation', 'No Show'))
              )
            GROUP BY s.room_id
        ) s ON s.room_id = r.id
        SET r.status = CASE
            WHEN active_logs.has_occupied = 1 THEN 'Occupied'
            WHEN active_logs.has_reserved = 1 THEN 'Reserved'
            WHEN active_logs.has_unconfirmed = 1 THEN 'Unconfirmed'
            WHEN s.has_upcoming = 1 THEN 'Scheduled'
            ELSE 'Available'
        END
    ");
    if (!$stmt) return;
    $today = getTodayName();
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $stmt->close();
}

/**
 * RFID is now the primary (and implicit) attendance confirmation method, so
 * the Yes/No prompt is optional and must NOT expire the moment class starts
 * — that would flip the room away from UNCONFIRMED before the faculty ever
 * gets a chance to tap their RFID card. Instead this now waits until the
 * same attendance grace period markNoShows() uses (default 15 minutes after
 * the scheduled start) before giving up on an un-tapped, un-answered class.
 * A log that already has checkin_at set has already moved to status
 * 'Occupied' (see admin/rfid_scan_action.php), so the `l.status =
 * 'Unconfirmed'` filter below already excludes it — the explicit
 * checkin_at IS NULL check is just a second line of defense.
 */
function closeExpiredConfirmations($conn, $graceMinutes = 15) {
    if (!tableExists($conn, 'room_logs')) return;

    $graceMinutes = max(0, (int)$graceMinutes);
    $titleSelect = columnExists($conn, 'users', 'title') ? 'u.title AS faculty_title,' : '';
    $expired = $conn->query("
        SELECT l.id, l.room_id, {$titleSelect} u.name AS faculty_name, r.room_code, s.subject
        FROM room_logs l
        JOIN schedules s ON s.id = l.schedule_id
        JOIN rooms r ON r.id = l.room_id
        JOIN users u ON u.id = l.faculty_id
        WHERE l.log_date <= CURDATE()
          AND l.confirmation IS NULL
          AND l.status = 'Unconfirmed'
          AND l.checkin_at IS NULL
          AND DATE_ADD(CONCAT(l.log_date, ' ', s.time_start), INTERVAL {$graceMinutes} MINUTE) <= NOW()
    ");
    if (!$expired) return;

    $ids = [];
    $rooms = [];
    while ($row = $expired->fetch_assoc()) {
        $ids[] = (int)$row['id'];
        $rooms[(int)$row['room_id']] = true;
        $facultyDisplay = facultyDisplayName($row['faculty_title'] ?? null, $row['faculty_name']);
        logActivity($conn, 'MISSED_CONFIRMATION', "Missed confirmation: {$facultyDisplay} did not respond for {$row['subject']} in {$row['room_code']}");
        logActivity($conn, 'WINDOW_CLOSED', "Confirmation window closed for {$row['subject']} in {$row['room_code']}");
        pushNotification($conn, 'Missed Confirmation', "{$facultyDisplay} did not respond for {$row['subject']} in {$row['room_code']}.", 'warning', '/admin/responses.php');
    }

    if ($ids) {
        $conn->query("UPDATE room_logs SET status = 'Missed Confirmation' WHERE id IN (" . implode(',', $ids) . ")");
        foreach (array_keys($rooms) as $roomId) refreshRoomStatus($conn, (int)$roomId);
    }
}

function markNoShows($conn, $graceMinutes = 15) {
    if (!tableExists($conn, 'room_logs')) return;

    $graceMinutes = max(0, (int)$graceMinutes);
    $titleSelect = columnExists($conn, 'users', 'title') ? 'u.title AS faculty_title,' : '';
    $noShows = $conn->query("
        SELECT l.id, l.room_id, {$titleSelect} u.name AS faculty_name, r.room_code, s.subject
        FROM room_logs l
        JOIN schedules s ON s.id = l.schedule_id
        JOIN rooms r ON r.id = l.room_id
        JOIN users u ON u.id = l.faculty_id
        WHERE l.log_date <= CURDATE()
          AND l.confirmation = 'yes'
          AND l.checkin_at IS NULL
          AND l.checkout_at IS NULL
          AND l.status = 'Reserved'
          AND DATE_ADD(CONCAT(l.log_date, ' ', s.time_start), INTERVAL {$graceMinutes} MINUTE) < NOW()
    ");
    if (!$noShows) return;

    $ids = [];
    $rooms = [];
    while ($row = $noShows->fetch_assoc()) {
        $ids[] = (int)$row['id'];
        $rooms[(int)$row['room_id']] = true;
        $facultyDisplay = facultyDisplayName($row['faculty_title'] ?? null, $row['faculty_name']);
        logActivity($conn, 'NO_SHOW', "No Show: {$facultyDisplay} did not check in for {$row['subject']} in {$row['room_code']}");
        logActivity($conn, 'RELEASE_ROOM', "Room released after no-show: {$row['room_code']} for {$row['subject']}");
        pushNotification($conn, 'No Show Detected', "{$facultyDisplay} did not check in for {$row['subject']} in {$row['room_code']}. Room released.", 'danger', '/admin/responses.php');
    }

    if ($ids) {
        $conn->query("UPDATE room_logs SET status = 'No Show' WHERE id IN (" . implode(',', $ids) . ")");
        foreach (array_keys($rooms) as $roomId) refreshRoomStatus($conn, (int)$roomId);
    }
}

function runScheduleAutomation($conn, $facultyId = null) {
    ensureTodayRoomLogs($conn, $facultyId);
    // Only run the bounded historical backfill on the broad admin-context
    // calls (no specific faculty), not on the per-scan/per-checkin faculty
    // calls, so RFID/check-in/confirm requests stay fast.
    if ($facultyId === null) {
        backfillMissingRoomLogs($conn);
    }
    closeExpiredConfirmations($conn);
    markNoShows($conn);
    refreshAllRoomStatuses($conn);
}

function logActivity($conn, $action, $description) {
    if (!tableExists($conn, 'activity_logs')) return;

    $actionMap = [
        'LOGIN'           => 'login_success',
        'LOGIN_FAILED'    => 'login_failed',
        'LOGOUT'          => 'logout',
        'CHECKIN'         => 'checkin',
        'CHECKOUT'        => 'checkout',
        'CONFIRM_YES'     => 'class_confirmed',
        'CONFIRM_NO'      => 'class_declined',
        'ROOM_STATUS'     => 'room_status_change',
        'CREATE_ROOM'     => 'room_created',
        'EDIT_ROOM'       => 'room_updated',
        'DELETE_ROOM'     => 'room_deleted',
        'CREATE_SCHEDULE' => 'schedule_created',
        'EDIT_SCHEDULE'   => 'schedule_updated',
        'DELETE_SCHEDULE' => 'schedule_deleted',
        'RELEASE_ROOM'    => 'room_released',
        'CREATE_FACULTY'  => 'faculty_created',
        'EDIT_FACULTY'    => 'faculty_updated',
        'DELETE_FACULTY'  => 'faculty_deleted',
        'TOGGLE_FACULTY'  => 'faculty_updated',
        'RESET_PASSWORD'  => 'faculty_password_reset',
        'CHANGE_PASSWORD' => 'password_changed',
        'UPDATE_PROFILE'  => 'profile_updated',
        'FACE_REGISTERED'      => 'face_registration_completed',
        'FACE_VERIFIED'        => 'face_verification_completed',
        'DUPLICATE_FACE'       => 'duplicate_face_attempt',
        'EMAIL_OTP_SENT'       => 'email_otp_sent',
        'EMAIL_OTP_RESENT'     => 'email_otp_resent',
        'EMAIL_OTP_SUCCESS'    => 'email_verification_success',
        'EMAIL_OTP_FAILED'     => 'email_verification_failed',
        'EMAIL_OTP_EXPIRED'    => 'email_otp_expired',
        'FACULTY_ACTIVATED'        => 'faculty_activated',
        'MISSED_CONFIRMATION'      => 'missed_confirmation',
        'WINDOW_CLOSED'            => 'confirmation_window_closed',
        'NO_SHOW'                  => 'no_show',
        // Faculty Registration Request events
        'FACULTY_REG_SUBMITTED'    => 'faculty_reg_submitted',
        'REGISTRATION_APPROVED'    => 'faculty_reg_approved',
        'REGISTRATION_REJECTED'    => 'faculty_reg_rejected',
        'REGISTRATION_REQUEST_DELETED'         => 'faculty_reg_request_deleted',
        'REGISTRATION_REQUESTS_BULK_DELETED'   => 'faculty_reg_requests_bulk_deleted',
        'TEMP_PASSWORD_GENERATED'  => 'temp_password_generated',
        'REG_APPROVAL_EMAIL_SENT'  => 'approval_email_sent',
        'EMAIL_SEND_FAILED'        => 'email_send_failed',
        // OTP events
        'OTP_VERIFIED'             => 'otp_verified',
        'OTP_FAILED'               => 'otp_failed',
        // RFID events
        'RFID_ASSIGNED'            => 'rfid_assigned',
        'RFID_REPLACED'            => 'rfid_replaced',
        'RFID_REMOVED'             => 'rfid_removed',
        'RFID_DEACTIVATED'         => 'rfid_deactivated',
        'RFID_REACTIVATED'         => 'rfid_reactivated',
        'RFID_LOST'                => 'rfid_lost',
        'RFID_SCAN'                => 'rfid_scan',
        'RFID_SCAN_DUPLICATE'      => 'rfid_scan_duplicate',
        'RFID_SCAN_FAILED'         => 'rfid_scan_failed',
        'RFID_CHECKIN'             => 'rfid_checkin',
        'RFID_CHECKOUT'            => 'rfid_checkout',
    ];

    $actionType = $actionMap[$action] ?? strtolower($action);
    $userId     = $_SESSION['user_id'] ?? null;
    $userName   = $_SESSION['user_name'] ?? null;
    $userRole   = $_SESSION['user_role'] ?? null;
    $ipAddress  = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = $conn->prepare("
        INSERT INTO activity_logs (user_id, user_name, user_role, action_type, description, ip_address, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) return;
    $stmt->bind_param('isssss', $userId, $userName, $userRole, $actionType, $description, $ipAddress);
    $stmt->execute();
    $stmt->close();
}
function pushNotification($conn, $title, $message, $type = 'info', $link = null, $userId = null) {
    if (!tableExists($conn, 'notifications')) return;

    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, title, message, status, type, link, created_at)
        VALUES (?, ?, ?, 'unread', ?, ?, NOW())
    ");

    if (!$stmt) return;

    $stmt->bind_param('issss', $userId, $title, $message, $type, $link);
    $stmt->execute();
    $stmt->close();
}
// ── OTP / Email Verification helpers ─────────────────────────────────────────

/**
 * Generate a cryptographically random 6-digit OTP, store it, and return it.
 * Any previously unused OTP for the same user + purpose is invalidated first.
 */
function generateOtp(mysqli $conn, int $userId, string $purpose = 'email_verification'): string {
    // Invalidate old unused OTPs for this user+purpose
    $stmt = $conn->prepare("
        DELETE FROM email_otps
        WHERE user_id = ? AND purpose = ? AND used_at IS NULL
    ");
    if ($stmt) { $stmt->bind_param('is', $userId, $purpose); $stmt->execute(); $stmt->close(); }

    $otp       = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', time() + 300); // 5 minutes
    $ip        = $_SERVER['REMOTE_ADDR'] ?? null;

    $ins = $conn->prepare("
        INSERT INTO email_otps (user_id, otp_code, purpose, expires_at, ip_address)
        VALUES (?, ?, ?, ?, ?)
    ");
    if ($ins) {
        $ins->bind_param('issss', $userId, $otp, $purpose, $expiresAt, $ip);
        $ins->execute();
        $ins->close();
    }
    return $otp;
}

/**
 * Verify an OTP. Returns 'ok', 'invalid', or 'expired'.
 */
function verifyOtp(mysqli $conn, int $userId, string $submittedOtp, string $purpose = 'email_verification'): string {
    $stmt = $conn->prepare("
        SELECT id, expires_at, used_at
        FROM email_otps
        WHERE user_id = ? AND otp_code = ? AND purpose = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    if (!$stmt) return 'invalid';
    $stmt->bind_param('iss', $userId, $submittedOtp, $purpose);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return 'invalid';
    if ($row['used_at'] !== null) return 'invalid';
    if (strtotime($row['expires_at']) < time()) {
        logActivity($conn, 'EMAIL_OTP_EXPIRED', "OTP expired for user ID {$userId}");
        return 'expired';
    }

    // Mark used
    $upd = $conn->prepare("UPDATE email_otps SET used_at = NOW() WHERE id = ?");
    if ($upd) { $upd->bind_param('i', $row['id']); $upd->execute(); $upd->close(); }

    return 'ok';
}

/**
 * Send OTP email through the centralized PHPMailer SMTP helper.
 */
function sendOtpEmail(string $toEmail, string $toName, string $otp): bool {
    return sendSmartRoomOtpEmail($toEmail, $toName, $otp);
}

function facultyMustChangePassword(mysqli $conn, array $faculty): bool {
    if (columnExists($conn, 'users', 'must_change_password') && (int)($faculty['must_change_password'] ?? 0) === 1) {
        return true;
    }

    if (columnExists($conn, 'users', 'first_login') && (int)($faculty['first_login'] ?? 0) === 1) {
        return true;
    }

    return false;
}

// ── Faculty trusted-device helpers ───────────────────────────────────────────

function facultyTrustedDeviceCookieName(): string {
    return 'smart_room_faculty_trusted_device';
}

function ensureFacultyTrustedDeviceTable(mysqli $conn): bool {
    if (tableExists($conn, 'faculty_trusted_devices')) return true;

    $ok = $conn->query("
        CREATE TABLE IF NOT EXISTS faculty_trusted_devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            user_agent_hash CHAR(64) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME DEFAULT NULL,
            UNIQUE KEY uq_faculty_trusted_token (token_hash),
            KEY idx_faculty_trusted_user (user_id),
            KEY idx_faculty_trusted_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if ($ok) markTableCreated('faculty_trusted_devices');
    return (bool)$ok;
}

function facultyTrustedDeviceTokenFromCookie(): ?string {
    $token = $_COOKIE[facultyTrustedDeviceCookieName()] ?? '';
    return preg_match('/^[a-f0-9]{64}$/', $token) ? $token : null;
}

function isFacultyTrustedDevice(mysqli $conn, int $facultyId): bool {
    if ($facultyId <= 0 || !ensureFacultyTrustedDeviceTable($conn)) return false;

    $token = facultyTrustedDeviceTokenFromCookie();
    if (!$token) return false;

    $tokenHash = hash('sha256', $token);
    $agentHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt = $conn->prepare("
        SELECT id, user_agent_hash
        FROM faculty_trusted_devices
        WHERE user_id = ?
          AND token_hash = ?
          AND expires_at > NOW()
          AND revoked_at IS NULL
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param('is', $facultyId, $tokenHash);
    $stmt->execute();
    $device = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$device) return false;
    if (!empty($device['user_agent_hash']) && !hash_equals($device['user_agent_hash'], $agentHash)) return false;

    $upd = $conn->prepare("UPDATE faculty_trusted_devices SET last_used_at = NOW() WHERE id = ?");
    if ($upd) {
        $deviceId = (int)$device['id'];
        $upd->bind_param('i', $deviceId);
        $upd->execute();
        $upd->close();
    }

    return true;
}

function rememberFacultyTrustedDevice(mysqli $conn, int $facultyId, int $days = 30): bool {
    if ($facultyId <= 0 || !ensureFacultyTrustedDeviceTable($conn)) return false;

    $days = max(1, min(365, $days));
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $agentHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $expiresAt = date('Y-m-d H:i:s', time() + ($days * 86400));

    $stmt = $conn->prepare("
        INSERT INTO faculty_trusted_devices (user_id, token_hash, user_agent_hash, ip_address, expires_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    if (!$stmt) return false;
    $stmt->bind_param('issss', $facultyId, $tokenHash, $agentHash, $ip, $expiresAt);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) return false;

    setcookie(facultyTrustedDeviceCookieName(), $token, [
        'expires' => time() + ($days * 86400),
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $_COOKIE[facultyTrustedDeviceCookieName()] = $token;
    return true;
}

function invalidateFacultyTrustedDevices(mysqli $conn, int $facultyId): void {
    if ($facultyId <= 0 || !ensureFacultyTrustedDeviceTable($conn)) return;

    $stmt = $conn->prepare("
        UPDATE faculty_trusted_devices
        SET revoked_at = NOW()
        WHERE user_id = ? AND revoked_at IS NULL
    ");
    if ($stmt) {
        $stmt->bind_param('i', $facultyId);
        $stmt->execute();
        $stmt->close();
    }

    setcookie(facultyTrustedDeviceCookieName(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[facultyTrustedDeviceCookieName()]);
}

// ── Face-descriptor helpers ───────────────────────────────────────────────────

/**
 * Euclidean distance between two face descriptor arrays.
 * face-api.js descriptors are 128-element float arrays; distance < 0.6 = same person.
 */
function faceDescriptorDistance(array $a, array $b): float {
    if (count($a) !== count($b)) return PHP_FLOAT_MAX;
    $sum = 0.0;
    for ($i = 0; $i < count($a); $i++) {
        $diff = $a[$i] - $b[$i];
        $sum += $diff * $diff;
    }
    return sqrt($sum);
}

/**
 * Check whether a face descriptor already exists in another faculty account.
 *
 * @param  mysqli  $conn
 * @param  array   $newDescriptor  128-float array from face-api.js
 * @param  int     $excludeUserId  The current user's ID (exclude from comparison)
 * @param  float   $threshold      Match threshold (default 0.55 — stricter than 0.6)
 * @return int|null  The matching user's ID, or null if no match found.
 */
function checkDuplicateFace(mysqli $conn, array $newDescriptor, int $excludeUserId, float $threshold = 0.55): ?int {
    if (!columnExists($conn, 'users', 'face_descriptor')) return null;

    // When excludeUserId is 0 (pre-registration), compare against ALL faculty
    if ($excludeUserId > 0) {
        $stmt = $conn->prepare("
            SELECT id, face_descriptor
            FROM users
            WHERE role = 'faculty'
              AND id != ?
              AND face_descriptor IS NOT NULL
        ");
        if (!$stmt) return null;
        $stmt->bind_param('i', $excludeUserId);
    } else {
        $stmt = $conn->prepare("
            SELECT id, face_descriptor
            FROM users
            WHERE role = 'faculty'
              AND face_descriptor IS NOT NULL
        ");
        if (!$stmt) return null;
    }
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $existing = json_decode($row['face_descriptor'], true);
        if (!is_array($existing) || count($existing) !== 128) continue;
        $distance = faceDescriptorDistance($newDescriptor, $existing);
        if ($distance < $threshold) {
            $stmt->close();
            return (int)$row['id'];
        }
    }
    $stmt->close();
    return null;
}

/**
 * Persist the face descriptor JSON for a user.
 */
function saveFaceDescriptor(mysqli $conn, int $userId, array $descriptor): bool {
    if (!columnExists($conn, 'users', 'face_descriptor')) return false;
    $json = json_encode($descriptor);
    $stmt = $conn->prepare("UPDATE users SET face_descriptor = ? WHERE id = ?");
    if (!$stmt) return false;
    $stmt->bind_param('si', $json, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// ─────────────────────────────────────────────────────────────────────────────

function countUnreadNotifications($conn, $userId, $role) {
    if (!tableExists($conn, 'notifications')) return 0;

    if ($role === 'admin') {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM notifications
            WHERE status = 'unread'
            AND (user_id = ? OR user_id IS NULL)
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM notifications
            WHERE status = 'unread'
            AND user_id = ?
        ");
    }

    if (!$stmt) return 0;

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $count = $stmt->get_result()->fetch_row()[0];

    $stmt->close();

    return (int)$count;
}
