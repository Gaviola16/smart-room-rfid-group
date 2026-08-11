<?php
require_once __DIR__ . '/../db.php';
session_start();
requireLogin('faculty');
redirect('/faculty/face_verification.php');
