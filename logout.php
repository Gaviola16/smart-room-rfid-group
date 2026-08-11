<?php
require_once __DIR__ . '/db.php';
session_start();

if (isset($_SESSION['user_id'])) {
    logActivity($conn, 'LOGOUT', "User logged out: " . ($_SESSION['user_name'] ?? 'Unknown'));
}

session_unset();
session_destroy();
 
 
header('Location: /index.php?logout=1');
exit;
