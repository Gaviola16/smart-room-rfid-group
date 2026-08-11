<?php
// Preserved for backward compatibility — see admin/activity_reports.php (view=room_utilization).
require_once __DIR__ . '/../../db.php';
session_start();
requireLogin('admin');
$params = ['view' => 'room_utilization'];
if (isset($_GET['date_from'])) $params['date_from'] = $_GET['date_from'];
if (isset($_GET['date_to']))   $params['date_to']   = $_GET['date_to'];
redirect('/admin/activity_reports.php?' . http_build_query($params));
