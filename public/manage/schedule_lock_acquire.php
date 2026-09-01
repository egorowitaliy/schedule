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
    $dayStmt = $pdo->prepare('SELECT 1 FROM schedule_days WHERE id = ?');
    $dayStmt->execute([$dayId]);
    if (!$dayStmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'День расписания не найден']);
        exit;
    }

    $userStmt = $pdo->prepare('SELECT username, full_name FROM users WHERE id = ?');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    if (!$user) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Пользователь не найден']);
        exit;
    }

    $result = acquireScheduleDayLock(
        $pdo,
        $dayId,
        $userId,
        $editorInstanceToken,
        $documentToken,
        (string)$user['username'],
        trim((string)$user['full_name']),
        getClientIp(),
        180
    );

    if (!$result['ok']) {
        $lock = $result['lock'];
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'message' => 'Этот день уже редактируется.',
            'lock' => [
                'name' => trim((string)$lock['full_name']) !== ''
                    ? (string)$lock['full_name']
                    : (string)$lock['username'],
                'username' => (string)$lock['username'],
                'ip_address' => (string)$lock['ip_address'],
                'locked_at' => formatDateTimeRu((int)$lock['locked_at']),
                'last_seen_at' => formatDateTimeRu((int)$lock['last_seen_at']),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Не удалось получить блокировку']);
}
