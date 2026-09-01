<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__);
$migrationDir = $projectRoot . '/database/migrations';
$installedFile = $projectRoot . '/storage/installed.lock';
$config = require $projectRoot . '/inc/config.php';
require_once $projectRoot . '/inc/migrations.php';

$db = $config['db'] ?? [];
if (($db['driver'] ?? '') !== 'sqlite') {
    fwrite(STDERR, "Поддерживается только SQLite\n");
    exit(1);
}

$dbPath = (string)($db['path'] ?? '');
if ($dbPath === '' || !is_file($dbPath) || !is_file($installedFile)) {
    fwrite(STDERR, "SQLite-БД не найдена\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 60000');

    $currentVersion = scheduleCurrentSchemaVersion($pdo);
    $latestVersion = scheduleLatestMigrationVersion($migrationDir);

    if ($currentVersion < 1) {
        throw new RuntimeException('База не является установленной Schedule-БД');
    }
    if ($currentVersion > $latestVersion) {
        throw new RuntimeException('Схема БД новее этой версии приложения');
    }

    $newVersion = scheduleApplyPendingMigrations(
        $pdo,
        $migrationDir,
        $currentVersion
    );

    if ((string)$pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
        throw new RuntimeException('Не пройдена проверка целостности SQLite');
    }
    if ($pdo->query('PRAGMA foreign_key_check')->fetchAll()) {
        throw new RuntimeException('Обнаружены нарушения внешних ключей');
    }

    $pdo->exec('PRAGMA journal_mode = WAL');

    $marker = json_decode((string)file_get_contents($installedFile), true);
    if (!is_array($marker)) {
        $marker = [];
    }
    $marker['schema_version'] = $newVersion;
    $marker['migrated_at'] = gmdate('c');
    $markerJson = json_encode($marker, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($markerJson)) {
        throw new RuntimeException('Не удалось обновить installed.lock');
    }
    $markerTemp = $installedFile . '.tmp-' . bin2hex(random_bytes(8));
    if (file_put_contents($markerTemp, $markerJson . PHP_EOL, LOCK_EX) === false
        || !rename($markerTemp, $installedFile)) {
        @unlink($markerTemp);
        throw new RuntimeException('Не удалось обновить installed.lock');
    }
    @chmod($installedFile, 0600);

    echo "Версия схемы: {$currentVersion} -> {$newVersion}\n";
    echo "integrity_check: ok\nforeign_key_check: ok\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка миграции: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
