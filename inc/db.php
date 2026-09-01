<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

function scheduleRuntimeLog(Throwable $error): void
{
    $logDir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0770, true);
    }

    $logFile = $logDir . '/php_error.log';
    $newFile = !is_file($logFile);
    @error_log(
        sprintf(
            "[%s] %s: %s in %s:%d\n",
            date('c'),
            get_class($error),
            str_replace(["\r", "\n"], ' ', $error->getMessage()),
            $error->getFile(),
            $error->getLine()
        ),
        3,
        $logFile
    );
    if ($newFile) {
        @chmod($logFile, 0600);
    }
}

set_exception_handler(static function (Throwable $error): never {
    scheduleRuntimeLog($error);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Внутренняя ошибка приложения';
    exit;
});

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/migrations.php';

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'self'; base-uri 'none'; object-src 'none'");
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

if (PHP_SAPI !== 'cli') {
    $requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($requestMethod, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        http_response_code(405);
        exit('Метод не поддерживается');
    }
}

$timezone = (string)($config['app']['timezone'] ?? 'UTC');
if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
    $timezone = 'UTC';
}
date_default_timezone_set($timezone);

$db = $config['db'] ?? [];
$driver = (string)($db['driver'] ?? 'sqlite');

if ($driver !== 'sqlite') {
    http_response_code(500);
    die('Поддерживается только SQLite');
}

$dbPath = (string)($db['path'] ?? '');
if ($dbPath === '') {
    http_response_code(500);
    die('Не задан путь к SQLite');
}

if (!is_file(dirname(__DIR__) . '/storage/installed.lock') || !is_file($dbPath)) {
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Location: /install.php', true, 302);
        exit;
    }

    http_response_code(503);
    die('Приложение не установлено. Откройте /install.php');
}

try {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $busyTimeout = max(1000, min(60000, (int)($db['busy_timeout_ms'] ?? 5000)));

    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = ' . $busyTimeout);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');

    $requiredSchemaVersion = max(1, (int)($db['schema_version'] ?? 1));
    $currentSchemaVersion = scheduleCurrentSchemaVersion($pdo);
    if ($currentSchemaVersion !== $requiredSchemaVersion) {
        http_response_code(503);
        die('Требуется обновление базы данных. Запустите: php tools/migrate.php');
    }
} catch (Throwable $e) {
    scheduleRuntimeLog($e);

    http_response_code(500);
    die('Ошибка подключения к базе данных');
}
