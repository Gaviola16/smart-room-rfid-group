<?php
/**
 * admin/rfid_lookup.php
 * JSON endpoint: look up an RFID UID and return card/faculty info.
 */
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

header('Content-Type: application/json');

ensureRfidTables($conn);

$uid = sanitizeRfidUid($_GET['uid'] ?? '');
if (!$uid || !isValidRfidUidFormat($uid) || !tableExists($conn, 'rfid_cards')) {
    echo json_encode(['found' => false]);
    exit;
}

$stmt = $conn->prepare("
    SELECT c.status AS card_status, u.name AS faculty_name, u.employee_id, u.department
    FROM rfid_cards c
    LEFT JOIN users u ON u.id = c.faculty_id
    WHERE c.uid = ?
    LIMIT 1
");
$stmt->bind_param('s', $uid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row) {
    echo json_encode(['found' => true] + $row);
} else {
    echo json_encode(['found' => false]);
}
