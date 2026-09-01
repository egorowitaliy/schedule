<?php
require_once dirname(__DIR__, 2) . '/inc/db.php';
require_once dirname(__DIR__, 2) . '/inc/auth.php';

if (isLoggedIn()) {
    header('Location: /manage/dashboard.php');
    exit;
}

header('Location: /manage/login.php');
exit;