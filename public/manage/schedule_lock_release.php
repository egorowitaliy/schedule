<?php
require_once dirname(__DIR__, 2) . '/inc/db.php';
require_once dirname(__DIR__, 2) . '/inc/auth.php';
require_once dirname(__DIR__, 2) . '/inc/helpers.php';
requirePostMethod();
requireAuth();
validateCsrfFromRequestOrDie();

$dayId = postIntInput('day_id');
$userId = (int)($_SESSION['user_id'] ?? 0);
$editorInstanceToken = strtolower(trim(postStringInput('editor_instance_token')));
$documentToken = strtolower(trim(postStringInput('document_token')));

if ($dayId > 0
    && $userId > 0
    && preg_match('/^[0-9a-f]{64}$/', $editorInstanceToken)
    && preg_match('/^[0-9a-f]{64}$/', $documentToken)) {
    try {
        releaseScheduleDayLock($pdo, $dayId, $userId, $editorInstanceToken, $documentToken);
    } catch (Throwable $e) {
        // молча
    }
}

http_response_code(204);
