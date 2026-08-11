<?php
// Preserved for backward compatibility — see admin/activity_reports.php (view=monthly).
require_once __DIR__ . '/../../db.php';
session_start();
requireLogin('admin');
$params = ['view' => 'monthly'];
if (isset($_GET['month'])) $params['month'] = $_GET['month'];
redirect('/admin/activity_reports.php?' . http_build_query($params));
