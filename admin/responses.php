<?php
/**
 * Preserved for backward compatibility — "Faculty Responses" is now part of
 * the consolidated "Activity & Reports" module's Unified History (the
 * "Room Activity" filter group already covers Confirmed/Declined/Pending/
 * Missed Confirmation/No Show, which is exactly what this page showed).
 *
 * This also retires this page's own unvalidated date_from/date_to handling
 * (no format check, no empty-string guard, no from>to correction), which was
 * the root cause of the "Room Activity date filter" bug — an empty or
 * malformed date reached the SQL query unguarded and silently zeroed out
 * results. The Unified History query this now redirects to already validates
 * dates before using them. See admin/activity_reports.php (view=history).
 *
 * Old links (sidebar bookmarks, notification links from db.php and
 * faculty/confirm.php that point here) keep working via this redirect.
 */
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');

$filterMap = [
    'yes'     => 'room:confirmed_yes',
    'no'      => 'room:confirmed_no',
    'pending' => 'room:pending',
    'missed'  => 'room:missed_confirmation',
    'no_show' => 'room:no_show',
];

$params = ['view' => 'history'];
$filter = $_GET['filter'] ?? 'all';
if (isset($filterMap[$filter])) $params['activity'] = $filterMap[$filter];
if (isset($_GET['date_from']) && $_GET['date_from'] !== '') $params['date_from'] = $_GET['date_from'];
if (isset($_GET['date_to'])   && $_GET['date_to']   !== '') $params['date_to']   = $_GET['date_to'];

redirect('/admin/activity_reports.php?' . http_build_query($params));
