<?php
// Preserved for backward compatibility — see admin/activity_reports.php (view=daily).
require_once __DIR__ . '/../../db.php';
session_start();
requireLogin('admin');
$params = ['view' => 'daily'];
if (isset($_GET['date'])) $params['date'] = $_GET['date'];
redirect('/admin/activity_reports.php?' . http_build_query($params));
