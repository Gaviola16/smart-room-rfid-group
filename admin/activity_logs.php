<?php
/**
 * Preserved for backward compatibility — this page's functionality now
 * lives in the consolidated "Activity & Reports" module (Step 11 of the
 * consolidation: don't break existing bookmarks/links).
 *
 * See admin/activity_reports.php (view=history).
 */
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

$params = ['view' => 'history'];
if (isset($_GET['search']))    $params['search']    = $_GET['search'];
if (isset($_GET['action']) && $_GET['action'] !== '') $params['activity'] = 'sys:' . $_GET['action'];
if (isset($_GET['date_from'])) $params['date_from']  = $_GET['date_from'];
if (isset($_GET['date_to']))   $params['date_to']    = $_GET['date_to'];
if (isset($_GET['page']))      $params['page']       = $_GET['page'];

redirect('/admin/activity_reports.php?' . http_build_query($params));
