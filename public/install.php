<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$storageDir = $projectRoot . '/storage';
$logDir = $storageDir . '/logs';
$dbFile = $storageDir . '/schedule.sqlite3';
$enableFile = $storageDir . '/install.enabled';
$installedFile = $storageDir . '/installed.lock';
$installLockFile = $storageDir . '/install.running.lock';
$migrationDir = $projectRoot . '/database/migrations';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
require_once $projectRoot . '/inc/migrations.php';
$config = require $projectRoot . '/inc/config.php';
require_once $projectRoot . '/inc/request.php';
require_once $projectRoot . '/inc/passwords.php';

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cache-Control: no-store, private');
}

final class InstallerVisibleException extends RuntimeException
{
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function installerPostString(string $key): string
{
    $value = $_POST[$key] ?? null;
    return is_string($value) ? $value : '';
}

function failVisible(string $message, int $status = 400): never
{
    http_response_code($status);
    throw new InstallerVisibleException($message);
}

function installerLog(string $message): void
{
    global $logDir;

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0770, true);
    }

    $logFile = $logDir . '/install.log';
    $newFile = !is_file($logFile);
    @error_log(
        sprintf("[%s] %s\n", date('c'), str_replace(["\r", "\n"], ' ', $message)),
        3,
        $logFile
    );
    if ($newFile) {
        @chmod($logFile, 0600);
    }
}

function installerOpenDatabase(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 60000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = FULL');

    return $pdo;
}

function installerDatabaseIsRecoverable(PDO $pdo, int $latestVersion): bool
{
    if ((string)$pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
        return false;
    }
    if ($pdo->query('PRAGMA foreign_key_check')->fetchAll()) {
        return false;
    }

    $version = scheduleCurrentSchemaVersion($pdo);
    if ($version > $latestVersion) {
        failVisible('Найдена база с более новой версией схемы. Данные и схема не изменялись.', 409);
    }
    if ($version < 1) {
        return false;
    }

    $hasUsers = (bool)$pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users' LIMIT 1"
    )->fetchColumn();
    if (!$hasUsers || (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() < 1) {
        return false;
    }

    if ($version < $latestVersion) {
        failVisible(
            'Найдена незавершённая установка со старой версией базы. Данные и схема не изменялись. '
            . 'Сначала выполните миграцию вручную командой php tools/migrate.php.',
            409
        );
    }

    return $version === $latestVersion;
}

function installerWriteInstalledMarker(string $path, int $schemaVersion, bool $demoData, bool $recovered): void
{
    $payload = json_encode([
        'installed_at' => gmdate('c'),
        'schema_version' => $schemaVersion,
        'demo_data' => $demoData,
        'recovered' => $recovered,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($payload)) {
        throw new RuntimeException('failed to encode installed.lock');
    }

    $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
    $handle = @fopen($temporary, 'xb');
    if ($handle === false) {
        throw new RuntimeException('failed to write temporary installed.lock');
    }
    $data = $payload . PHP_EOL;
    $written = 0;
    while ($written < strlen($data)) {
        $result = fwrite($handle, substr($data, $written));
        if ($result === false || $result === 0) {
            fclose($handle);
            @unlink($temporary);
            throw new RuntimeException('failed to write temporary installed.lock');
        }
        $written += $result;
    }
    if (function_exists('fsync') && !fsync($handle)) {
        fclose($handle);
        @unlink($temporary);
        throw new RuntimeException('failed to sync temporary installed.lock');
    }
    fclose($handle);
    @chmod($temporary, 0600);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('failed to publish installed.lock');
    }
}

function installerQuarantineDatabase(string $dbFile): ?string
{
    if (!is_file($dbFile)) {
        return null;
    }

    $suffix = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $target = $dbFile . '.failed-' . $suffix;
    $movedSidecars = [];
    foreach (['-wal', '-shm'] as $extension) {
        if (is_file($dbFile . $extension)) {
            if (!rename($dbFile . $extension, $target . $extension)) {
                foreach (array_reverse($movedSidecars, true) as $source => $destination) {
                    @rename($destination, $source);
                }
                throw new RuntimeException('failed to quarantine database sidecar');
            }
            $movedSidecars[$dbFile . $extension] = $target . $extension;
        }
    }

    if (!rename($dbFile, $target)) {
        foreach (array_reverse($movedSidecars, true) as $source => $destination) {
            @rename($destination, $source);
        }
        throw new RuntimeException('failed to quarantine incomplete database');
    }

    @chmod($target, 0600);
    return $target;
}

function nextStudyDays(int $count, DateTimeZone $timezone): array
{
    $days = [];
    $date = new DateTimeImmutable('today', $timezone);

    while (count($days) < $count) {
        $weekday = (int)$date->format('N');
        if ($weekday <= 5) {
            $days[] = $date;
        }
        $date = $date->modify('+1 day');
    }

    return $days;
}

function createDemoData(PDO $pdo, DateTimeZone $timezone): void
{
    $groups = [['ДЕМО-101', 1], ['ДЕМО-102', 2], ['ДЕМО-103', 3]];
    $teachers = [
        'Демонстрационный преподаватель №1',
        'Демонстрационный преподаватель №2',
        'Демонстрационный преподаватель №3',
    ];
    $subjects = ['Математика (демо)', 'Информатика (демо)', 'Иностранный язык (демо)'];
    $rooms = ['ДЕМО-101', 'ДЕМО-205', 'ДЕМО-307'];
    $lessonTimes = [
        [1, '09:00', '10:30'],
        [2, '10:40', '12:10'],
        [3, '12:40', '14:10'],
        [4, '14:20', '15:50'],
    ];

    $stmt = $pdo->prepare('INSERT INTO groups_list (name, sort_order) VALUES (?, ?)');
    foreach ($groups as [$name, $sortOrder]) {
        $stmt->execute([$name, $sortOrder]);
    }

    $stmt = $pdo->prepare('INSERT INTO teachers (full_name) VALUES (?)');
    foreach ($teachers as $teacher) {
        $stmt->execute([$teacher]);
    }

    $stmt = $pdo->prepare('INSERT INTO subjects (name) VALUES (?)');
    foreach ($subjects as $subject) {
        $stmt->execute([$subject]);
    }

    $stmt = $pdo->prepare('INSERT INTO rooms (name) VALUES (?)');
    foreach ($rooms as $room) {
        $stmt->execute([$room]);
    }

    $stmt = $pdo->prepare('
        INSERT INTO lesson_times (lesson_number, weekdays_mask, time_start, time_end)
        VALUES (?, 31, ?, ?)
    ');
    foreach ($lessonTimes as [$number, $start, $end]) {
        $stmt->execute([$number, $start, $end]);
    }

    $groupIds = $pdo->query('SELECT id FROM groups_list ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN);
    $teacherIds = $pdo->query('SELECT id FROM teachers ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $subjectIds = $pdo->query('SELECT id FROM subjects ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $roomIds = $pdo->query('SELECT id FROM rooms ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $timeIds = $pdo->query('SELECT id FROM lesson_times ORDER BY lesson_number, id')->fetchAll(PDO::FETCH_COLUMN);

    $stmtDay = $pdo->prepare('
        INSERT INTO schedule_days (study_date, title, is_published)
        VALUES (?, ?, 1)
    ');
    $stmtEntry = $pdo->prepare('
        INSERT INTO schedule_entries
            (schedule_day_id, group_id, lesson_time_id, subject_id, teacher_id, room_id,
             lesson_type, note, is_distance, is_cancelled)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    foreach (nextStudyDays(5, $timezone) as $dayIndex => $day) {
        $stmtDay->execute([$day->format('Y-m-d'), 'Демонстрационное расписание']);
        $dayId = (int)$pdo->lastInsertId();

        foreach ($groupIds as $groupIndex => $groupId) {
            foreach ($timeIds as $timeIndex => $timeId) {
                $subjectId = (int)$subjectIds[($dayIndex + $groupIndex + $timeIndex) % count($subjectIds)];
                $teacherId = (int)$teacherIds[($dayIndex + $groupIndex + $timeIndex) % count($teacherIds)];
                $roomId = (int)$roomIds[($dayIndex + $groupIndex + $timeIndex) % count($roomIds)];

                $note = null;
                $isDistance = 0;
                $isCancelled = 0;

                if ($dayIndex === 1 && $groupIndex === 1 && $timeIndex === 2) {
                    $isDistance = 1;
                    $note = 'Демонстрационный пример дистанционного занятия';
                }

                if ($dayIndex === 3 && $groupIndex === 2 && $timeIndex === 3) {
                    $isCancelled = 1;
                    $note = 'Демонстрационный пример отменённого занятия';
                }

                $stmtEntry->execute([
                    $dayId,
                    (int)$groupId,
                    (int)$timeId,
                    $subjectId,
                    $teacherId,
                    $roomId,
                    ($timeIndex % 2 === 0) ? 'Практика' : 'Лекция',
                    $note,
                    $isDistance,
                    $isCancelled,
                ]);
            }
        }
    }
}

if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0770, true);
}
if (!is_dir($logDir)) {
    @mkdir($logDir, 0770, true);
}

$requirements = [
    'PHP >= 8.2' => version_compare(PHP_VERSION, '8.2.0', '>='),
    'PDO' => extension_loaded('pdo'),
    'pdo_sqlite' => extension_loaded('pdo_sqlite'),
    'mbstring' => extension_loaded('mbstring'),
    'random_bytes()' => function_exists('random_bytes'),
    'миграции SQLite' => is_dir($migrationDir),
];
$requirementsOk = !in_array(false, $requirements, true);
$alreadyInstalled = is_file($installedFile);
$orphanDatabasePresent = !$alreadyInstalled && is_file($dbFile);

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Метод не поддерживается');
}

$https = requestIsHttps();
session_name('schedule_installer_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
session_start();

if (empty($_SESSION['install_csrf']) || !is_string($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = false;
$demoCreated = false;
$recoveredInstallation = false;
$quarantinedDatabase = null;

if ($requestMethod === 'POST' && !$alreadyInstalled) {
    $lockHandle = null;
    $temporaryDb = null;
    $needsQuarantine = false;

    try {
        if (!$requirementsOk) {
            failVisible('Сервер не соответствует требованиям.');
        }

        $csrf = installerPostString('csrf_token');
        if ($csrf === '' || !hash_equals((string)$_SESSION['install_csrf'], $csrf)) {
            failVisible('Некорректный CSRF-токен.', 403);
        }

        $serverToken = is_file($enableFile) ? trim((string)@file_get_contents($enableFile)) : '';
        $submittedToken = trim(installerPostString('install_token'));
        if (!preg_match('/^[0-9a-f]{64}$/', $serverToken)
            || !preg_match('/^[0-9a-f]{64}$/', $submittedToken)
            || !hash_equals($serverToken, $submittedToken)) {
            failVisible('Установка не разрешена или указан неверный одноразовый ключ.', 403);
        }

        if (!is_writable($storageDir)) {
            failVisible('Каталог storage недоступен для записи.', 500);
        }

        umask(0077);
        $lockHandle = fopen($installLockFile, 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            failVisible('Установка уже выполняется другим запросом. Повторите позже.', 409);
        }
        @chmod($installLockFile, 0600);

        // Проверяем состояние повторно уже внутри межпроцессной блокировки.
        if (is_file($installedFile)) {
            failVisible('Приложение уже установлено.', 409);
        }

        $latestVersion = scheduleLatestMigrationVersion($migrationDir);

        if (is_file($dbFile)) {
            $recoverable = false;
            try {
                $existingPdo = installerOpenDatabase($dbFile);
                $recoverable = installerDatabaseIsRecoverable($existingPdo, $latestVersion);
                if ($recoverable) {
                    $existingPdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                }
                unset($existingPdo);
            } catch (InstallerVisibleException $e) {
                throw $e;
            } catch (Throwable $e) {
                installerLog('ORPHAN_DATABASE_INVALID ' . get_class($e) . ': ' . $e->getMessage());
                $recoverable = false;
                unset($existingPdo);
            }

            if ($recoverable) {
                installerWriteInstalledMarker($installedFile, $latestVersion, false, true);
                @unlink($enableFile);
                $recoveredInstallation = true;
                $success = true;
            } else {
                $needsQuarantine = true;
            }
        }

        if (!$success) {
            $username = trim(installerPostString('username'));
            $fullName = trim(installerPostString('full_name'));
            $password = installerPostString('password');
            $passwordConfirm = installerPostString('password_confirm');
            $createDemo = installerPostString('create_demo') === '1';

            if (!preg_match('/^[\p{L}\p{N}_.-]{3,64}$/u', $username)) {
                failVisible('Логин: 3–64 символа; буквы, цифры, точка, дефис и подчёркивание.');
            }
            if ($fullName === '' || mb_strlen($fullName, 'UTF-8') > 120) {
                failVisible('Укажите ФИО длиной до 120 символов.');
            }
            $passwordLength = mb_strlen($password, 'UTF-8');
            if ($passwordLength < 12 || $passwordLength > 4096) {
                failVisible('Пароль должен содержать от 12 до 4096 символов.');
            }
            if ($password !== $passwordConfirm) {
                failVisible('Пароли не совпадают.');
            }

            if ($needsQuarantine) {
                $quarantinedDatabase = installerQuarantineDatabase($dbFile);
                installerLog('ORPHAN_DATABASE_QUARANTINED ' . (string)$quarantinedDatabase);
            }

            $temporaryDb = $storageDir . '/.schedule.sqlite3.installing-' . bin2hex(random_bytes(12));
            $pdo = installerOpenDatabase($temporaryDb);
            $currentVersion = scheduleApplyPendingMigrations($pdo, $migrationDir, 0);
            if ($currentVersion !== $latestVersion) {
                throw new RuntimeException('not all migrations were applied');
            }

            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $settings = [
                    'site_name' => 'Schedule',
                    'public_title' => 'Расписание занятий',
                    'public_subtitle' => 'Образовательная организация',
                    'public_days_forward' => '7',
                    'public_show_today' => '1',
                    'admin_days_back' => '30',
                    'delete_days_after' => '90',
                ];
                $stmtSetting = $pdo->prepare(
                    'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)'
                );
                foreach ($settings as $key => $value) {
                    $stmtSetting->execute([$key, $value]);
                }

                $hash = schedulePasswordHash($password);
                $stmtUser = $pdo->prepare(
                    'INSERT INTO users (username, full_name, password_hash) VALUES (?, ?, ?)'
                );
                $stmtUser->execute([$username, $fullName, $hash]);

                if ($createDemo) {
                    $timezoneName = (string)($config['app']['timezone'] ?? 'UTC');
                    if (!in_array($timezoneName, DateTimeZone::listIdentifiers(), true)) {
                        $timezoneName = 'UTC';
                    }
                    createDemoData($pdo, new DateTimeZone($timezoneName));
                    $demoCreated = true;
                }

                $pdo->exec('COMMIT');
            } catch (Throwable $e) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable $rollbackError) {
                    // Исходная ошибка важнее ошибки отката.
                }
                throw $e;
            }

            $integrity = (string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
            if ($integrity !== 'ok') {
                throw new RuntimeException('integrity_check failed: ' . $integrity);
            }
            if ($pdo->query('PRAGMA foreign_key_check')->fetchAll()) {
                throw new RuntimeException('foreign_key_check failed');
            }

            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            unset($pdo);

            @unlink($temporaryDb . '-wal');
            @unlink($temporaryDb . '-shm');

            if (!rename($temporaryDb, $dbFile)) {
                throw new RuntimeException('failed to publish database');
            }
            $temporaryDb = null;
            @chmod($dbFile, 0600);

            installerWriteInstalledMarker($installedFile, $latestVersion, $demoCreated, false);
            @unlink($enableFile);
            $success = true;
        }

        if ($success) {
            $_SESSION = [];
            session_destroy();
        }
    } catch (InstallerVisibleException $e) {
        $errors[] = $e->getMessage();
    } catch (Throwable $e) {
        installerLog('INSTALL_ERROR ' . get_class($e) . ': ' . $e->getMessage());
        $errors[] = 'Внутренняя ошибка установки. Подробности записаны в storage/logs/install.log.';
        http_response_code(500);
    } finally {
        if (is_string($temporaryDb)) {
            @unlink($temporaryDb);
            @unlink($temporaryDb . '-wal');
            @unlink($temporaryDb . '-shm');
        }

        if (is_resource($lockHandle)) {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    }
}

?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Установка Schedule</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 760px; margin: 40px auto; padding: 0 20px; line-height: 1.45; color: #1f2937; }
        fieldset { border: 1px solid #d1d5db; border-radius: 8px; padding: 20px; margin: 20px 0; }
        label { display: block; margin-top: 14px; font-weight: 600; }
        input[type="text"], input[type="password"] { box-sizing: border-box; width: 100%; padding: 10px; margin-top: 5px; }
        button { padding: 10px 18px; cursor: pointer; }
        .ok { color: #166534; } .bad { color: #991b1b; }
        .notice { padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; margin: 16px 0; }
        .check-row { display: flex; gap: 10px; align-items: flex-start; margin-top: 20px; padding: 12px; border: 1px solid #ddd; border-radius: 8px; }
        .check-row input { margin-top: 4px; } .check-row label { margin: 0; }
        .help { font-size: .92rem; color: #4b5563; margin-top: 4px; }
        code, pre { overflow-wrap: anywhere; }
    </style>
</head>
<body>
<h1>Установка Schedule</h1>

<?php if ($alreadyInstalled): ?>
    <div class="notice"><strong>Приложение уже установлено.</strong> Повторная установка заблокирована.</div>
    <p><a href="/manage/login.php">Перейти к входу</a></p>
<?php elseif ($success): ?>
    <div class="notice ok">
        <strong>Установка завершена.</strong>
        <?= $recoveredInstallation
            ? 'Существующая корректная база восстановлена после прерванной финализации.'
            : 'База создана, первый администратор добавлен.' ?>
        <?= $demoCreated ? ' Демонстрационные данные также созданы.' : '' ?>
        <?= $quarantinedDatabase !== null
            ? ' Неполная предыдущая база сохранена в storage с суффиксом .failed-*.'
            : '' ?>
    </div>
    <p><a href="/manage/login.php">Войти в панель управления</a></p>
<?php else: ?>
    <h2>Проверка сервера</h2>
    <ul>
        <?php foreach ($requirements as $name => $state): ?>
            <li class="<?= $state ? 'ok' : 'bad' ?>"><?= h($name) ?> — <?= $state ? 'OK' : 'не выполнено' ?></li>
        <?php endforeach; ?>
        <li class="<?= is_writable($storageDir) ? 'ok' : 'bad' ?>">storage доступен для записи — <?= is_writable($storageDir) ? 'да' : 'нет' ?></li>
        <li class="<?= is_file($enableFile) ? 'ok' : 'bad' ?>">одноразовый ключ установки — <?= is_file($enableFile) ? 'создан' : 'не создан' ?></li>
    </ul>

    <?php foreach ($errors as $error): ?>
        <div class="notice bad"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php if ($orphanDatabasePresent): ?>
        <div class="notice">
            Обнаружена база без installed.lock. После проверки одноразового ключа installer
            восстановит корректную установку либо сохранит неполную базу как .failed-* перед новой установкой.
        </div>
    <?php endif; ?>

    <?php if (!is_file($enableFile)): ?>
        <div class="notice">
            На сервере из корня проекта создайте одноразовый ключ:
            <pre>umask 077
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;' &gt; storage/install.enabled
chown www-data:www-data storage/install.enabled</pre>
            Содержимое файла потребуется в форме ниже. После успешной установки файл будет удалён.
        </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= h((string)$_SESSION['install_csrf']) ?>">
        <fieldset <?= (!$requirementsOk || !is_file($enableFile)) ? 'disabled' : '' ?>>
            <legend>Первый администратор</legend>

            <label for="install_token">Одноразовый ключ установки</label>
            <input id="install_token" name="install_token" type="password" required autocomplete="off">

            <label for="username">Логин</label>
            <input id="username" name="username" type="text" required maxlength="64" autocomplete="username">

            <label for="full_name">ФИО</label>
            <input id="full_name" name="full_name" type="text" required maxlength="120" autocomplete="name">

            <label for="password">Пароль</label>
            <input id="password" type="password" name="password" required minlength="12" maxlength="4096" autocomplete="new-password">

            <label for="password_confirm">Повтор пароля</label>
            <input id="password_confirm" type="password" name="password_confirm" required minlength="12" maxlength="4096" autocomplete="new-password">

            <div class="check-row">
                <input id="create_demo" type="checkbox" name="create_demo" value="1">
                <div>
                    <label for="create_demo">Создать демонстрационный набор данных</label>
                    <div class="help">Будут созданы только вымышленные учебные данные. Для рабочей установки оставьте флажок выключенным.</div>
                </div>
            </div>

            <p><button type="submit">Установить</button></p>
        </fieldset>
    </form>
<?php endif; ?>
</body>
</html>
