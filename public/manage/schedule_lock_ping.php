<?php
require_once dirname(__DIR__, 2) . '/inc/db.php';
require_once dirname(__DIR__, 2) . '/inc/auth.php';
require_once dirname(__DIR__, 2) . '/inc/helpers.php';
requirePostMethod();
requireAuth();
validateCsrfFromRequestOrDie();

header('Content-Type: application/json; charset=utf-8');

$dayId = postIntInput('day_id');
$userId = (int)($_SESSION['user_id'] ?? 0);
$editorInstanceToken = strtolower(trim(postStringInput('editor_instance_token')));
$documentToken = strtolower(trim(postStringInput('document_token')));

if ($dayId <= 0
    || $userId <= 0
    || !preg_match('/^[0-9a-f]{64}$/', $editorInstanceToken)
    || !preg_match('/^[0-9a-f]{64}$/', $documentToken)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Некорректные данные']);
    exit;
}

try {
    if (!touchScheduleDayLock($pdo, $dayId, $userId, $editorInstanceToken, $documentToken)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'Блокировка потеряна']);
        exit;
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Не удалось обновить блокировку']);
}
