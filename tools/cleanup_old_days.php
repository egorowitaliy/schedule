<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers.php';

$settings = loadSettings($pdo);
$deleteDaysAfter = (int)($settings['delete_days_after'] ?? 90);

if ($deleteDaysAfter < 1) {
    fwrite(STDERR, "Некорректная настройка delete_days_after\n");
    exit(1);
}

$cutoffDate = date('Y-m-d', strtotime('-' . $deleteDaysAfter . ' days'));

$stmt = $pdo->prepare('
    DELETE FROM schedule_days
    WHERE study_date < ?
');
$stmt->execute([$cutoffDate]);

echo "Удалено старых дней: " . $stmt->rowCount() . PHP_EOL;