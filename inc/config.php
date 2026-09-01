<?php

declare(strict_types=1);

$base = [
    'db' => [
        'driver' => 'sqlite',
        'path' => dirname(__DIR__) . '/storage/schedule.sqlite3',
        'busy_timeout_ms' => 5000,
        'schema_version' => 1,
    ],
    'app' => [
        'session_name' => 'schedule_admin_session',
        'timezone' => 'Europe/Moscow',
    ],
    'proxy' => [
        // Укажите IP/CIDR доверенных reverse proxy в inc/config.local.php.
        // Пока список пуст, X-Real-IP/X-Forwarded-Proto игнорируются.
        'trusted_proxies' => [],
    ],
];

$localFile = __DIR__ . '/config.local.php';

if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        $base = array_replace_recursive($base, $local);
    }
}

return $base;
