<?php
/**
 * admin/rfid_scan_logs_data.php
 * ---------------------------------------------------------------------------
 * Read-only JSON endpoint that returns the same "Recent RFID Scan Logs" rows
 * shown on admin/rfid_management.php. Polled from that page so the table
 * reflects new scans within a few seconds without a manual page reload.
 * Does not create, modify, or delete anything.
 */
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

header('Content-Type: application/json');

if (!tableExists($conn, 'rfid_scan_logs')) {
    echo json_encode(['logs' => []]);
    exit;
}

$result = $conn->query("
    SELECT sl.*, u.name AS faculty_name, r.room_code,
           s.subject AS schedule_subject, s.section AS schedule_section
    FROM rfid_scan_logs sl
    LEFT JOIN users u        ON u.id  = sl.faculty_id
    LEFT JOIN rooms r        ON r.id  = sl.room_id
    LEFT JOIN room_logs rl   ON rl.id = sl.log_id
    LEFT JOIN schedules s    ON s.id  = rl.schedule_id
    ORDER BY sl.scanned_at DESC LIMIT 100
");

$logs = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $scheduleDisplay = null;
        if (!empty($row['schedule_subject'])) {
            $scheduleDisplay = $row['schedule_subject'] . (!empty($row['schedule_section']) ? ' — ' . $row['schedule_section'] : '');
        }
        $logs[] = [
            'scanned_at_display' => date('M j Y, g:i:s A', strtotime($row['scanned_at'])),
            'rfid_uid'           => $row['rfid_uid'],
            'faculty_name'       => $row['faculty_name'],
            'schedule_display'   => $scheduleDisplay,
            'room_code'          => $row['room_code'],
            'result'             => $row['result'],
            'reason'             => $row['reason'],
        ];
    }
}

echo json_encode(['logs' => $logs]);
