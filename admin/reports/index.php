<?php
/**
 * Preserved for backward compatibility — the Reports Center now lives inside
 * the consolidated "Activity & Reports" module (Step 11 of the consolidation:
 * don't break existing bookmarks/links).
 *
 * See admin/activity_reports.php.
 */
require_once __DIR__ . '/../../db.php';
session_start();
requireLogin('admin');

redirect('/admin/activity_reports.php?view=history');
