<?php
// Preserved for backward compatibility — see admin/activity_reports.php (view=weekly).
require_once __DIR__ . '/../../db.php';
session_start();
requireLogin('admin');
$params = ['view' => 'weekly'];
if (isset($_GET['week_start'])) $params['week_start'] = $_GET['week_start'];
redirect('/admin/activity_reports.php?' . http_build_query($params));
