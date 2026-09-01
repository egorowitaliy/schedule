<?php
require_once dirname(__DIR__, 2) . '/inc/db.php';
require_once dirname(__DIR__, 2) . '/inc/auth.php';

requirePostMethod();
requireAuth();
validateCsrfOrDie();
logoutUser();

header('Location: /manage/login.php', true, 303);
exit;
