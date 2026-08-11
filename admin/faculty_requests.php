<?php
// Compatibility redirect to the new one-time face registration request review page.
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('admin');
redirect('/admin/registration_requests.php');