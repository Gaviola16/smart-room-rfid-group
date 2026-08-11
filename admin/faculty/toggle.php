<?php
require_once __DIR__ . '/../../db.php';
session_start(); requireLogin('admin');
$id = intval($_GET['id'] ?? 0);
if (!$id) { setFlash('danger','Invalid ID.'); redirect('/admin/faculty/index.php'); }

$accountStatusSelect = columnExists($conn, 'users', 'account_status') ? 'account_status' : 'NULL AS account_status';
$titleSelect = columnExists($conn, 'users', 'title') ? 'title' : 'NULL AS title';
$stmt = $conn->prepare("SELECT name, {$titleSelect}, is_active, {$accountStatusSelect} FROM users WHERE id=? AND role='faculty'");
$stmt->bind_param('i',$id); $stmt->execute();
$f = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$f) { setFlash('danger','Faculty not found.'); redirect('/admin/faculty/index.php'); }
$displayName = facultyDisplayName($f['title'] ?? null, $f['name']);

$currentStatus = $f['account_status'] ?? (($f['is_active'] ?? 1) ? 'Active' : 'Inactive');
if ($currentStatus === 'Pending Face Registration') $currentStatus = 'Pending Face Verification';
$newStatus = in_array($currentStatus, ['Active', 'Pending Face Verification'], true) ? 'Inactive' : 'Active';
$newState = $newStatus === 'Active' ? 1 : 0;

if (columnExists($conn, 'users', 'account_status')) {
    $upd = $conn->prepare("UPDATE users SET is_active=?, account_status=? WHERE id=?");
    $upd->bind_param('isi',$newState,$newStatus,$id);
} else {
    $upd = $conn->prepare("UPDATE users SET is_active=? WHERE id=?");
    $upd->bind_param('ii',$newState,$id);
}
$upd->execute(); $upd->close();
$label = $newState ? 'activated' : 'deactivated';
logActivity($conn,'TOGGLE_FACULTY',"Account {$label}: {$displayName} (ID:$id)");
setFlash('success',"Account for «{$displayName}» has been {$label}.");
redirect('/admin/faculty/index.php');
