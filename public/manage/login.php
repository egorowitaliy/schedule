<?php
require_once dirname(__DIR__, 2) . '/inc/db.php';
require_once dirname(__DIR__, 2) . '/inc/auth.php';
require_once dirname(__DIR__, 2) . '/inc/helpers.php';

$settings = loadSettings($pdo);
$error = '';
$notice = queryIntInput('password_changed') === 1
    ? 'Пароль изменён. Войдите снова с новым паролем.'
    : '';

$ip = getClientIp();
$userAgent = getUserAgent();

$maxAttemptsPerIp = 7;
$maxAttemptsPerUser = 5;
$lockMinutes = 15;
$delaySeconds = 2;

function reserveLoginAttempt(
    PDO $pdo,
    string $ip,
    string $username,
    string $userAgent,
    int $maxAttemptsPerIp,
    int $maxAttemptsPerUser,
    int $lockMinutes
): array {
    $now = time();
    $threshold = $now - ($lockMinutes * 60);

    // SQLite BEGIN IMMEDIATE сериализует параллельные проверки лимита.
    $pdo->exec('BEGIN IMMEDIATE');

    try {
        $cleanup = $pdo->prepare('DELETE FROM login_attempts WHERE attempt_time < ?');
        $cleanup->execute([$now - 86400]);

        $stmt = $pdo->prepare('
            SELECT COUNT(*)
            FROM login_attempts
            WHERE ip_address = ?
              AND is_success = 0
              AND attempt_time >= ?
        ');
        $stmt->execute([$ip, $threshold]);
        $ipFailures = (int)$stmt->fetchColumn();

        if ($ipFailures >= $maxAttemptsPerIp) {
            $pdo->exec('COMMIT');
            return ['ok' => false, 'reason' => 'ip_limit', 'attempt_id' => 0];
        }

        if ($username !== '') {
            $stmt = $pdo->prepare('
                SELECT COUNT(*)
                FROM login_attempts
                WHERE username = ?
                  AND is_success = 0
                  AND attempt_time >= ?
            ');
            $stmt->execute([$username, $threshold]);
            $userFailures = (int)$stmt->fetchColumn();

            if ($userFailures >= $maxAttemptsPerUser) {
                $pdo->exec('COMMIT');
                return ['ok' => false, 'reason' => 'user_limit', 'attempt_id' => 0];
            }
        }

        $stmt = $pdo->prepare('
            INSERT INTO login_attempts
                (ip_address, username, attempt_time, is_success, user_agent)
            VALUES (?, ?, ?, 0, ?)
        ');
        $stmt->execute([$ip, $username !== '' ? $username : null, $now, $userAgent]);
        $attemptId = (int)$pdo->lastInsertId();

        $pdo->exec('COMMIT');
        return ['ok' => true, 'reason' => '', 'attempt_id' => $attemptId];
    } catch (Throwable $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable $rollbackError) {
            // Исходная ошибка важнее ошибки отката.
        }
        throw $e;
    }
}

function markLoginAttemptSuccessful(PDO $pdo, int $attemptId): void
{
    $stmt = $pdo->prepare('UPDATE login_attempts SET is_success = 1 WHERE id = ?');
    $stmt->execute([$attemptId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfOrDie();
    $username = trim(postStringInput('username'));
    $password = postStringInput('password');

    if ($username === '' || $password === '') {
        $error = 'Введите логин и пароль';
    } elseif (mb_strlen($username, 'UTF-8') > 64 || mb_strlen($password, 'UTF-8') > 4096) {
        $error = 'Неверный логин или пароль';
    } else {
        try {
            $reservation = reserveLoginAttempt(
                $pdo,
                $ip,
                $username,
                $userAgent,
                $maxAttemptsPerIp,
                $maxAttemptsPerUser,
                $lockMinutes
            );
        } catch (Throwable $e) {
            writeAuthLog('LOGIN_ERROR reason=rate_limit_db');
            $reservation = ['ok' => false, 'reason' => 'db_error', 'attempt_id' => 0];
        }

        if (!$reservation['ok']) {
            if ($reservation['reason'] === 'ip_limit') {
                $error = 'Слишком много неудачных попыток с этого IP. Подождите 15 минут.';
            } elseif ($reservation['reason'] === 'user_limit') {
                $error = 'Слишком много попыток для этого логина. Подождите 15 минут.';
            } else {
                $error = 'Вход временно недоступен. Повторите попытку позже.';
            }
        } else {
            sleep($delaySeconds);

            $stmt = $pdo->prepare('SELECT id, username, password_hash, auth_version FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && schedulePasswordVerify($password, (string)$user['password_hash'])) {
                markLoginAttemptSuccessful($pdo, (int)$reservation['attempt_id']);

                if (schedulePasswordNeedsRehash($password, (string)$user['password_hash'])) {
                    $newHash = schedulePasswordHash($password);
                    if ($newHash !== '') {
                        $rehash = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                        $rehash->execute([$newHash, (int)$user['id']]);
                    }
                }

                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = (string)$user['username'];
                $_SESSION['auth_version'] = (int)$user['auth_version'];

                writeAuthLog("LOGIN_SUCCESS username=\"{$username}\"");

                header('Location: /manage/dashboard.php', true, 303);
                exit;
            }

            writeAuthLog("LOGIN_FAILED username=\"{$username}\"");
            $error = 'Неверный логин или пароль';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход — <?= htmlspecialchars((string)$settings['site_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" type="image/svg+xml" href="/assets/icon.svg">
</head>
<body>
<div class="auth-page">
    <div class="page page--narrow" style="width:100%; padding:0;">
        <div class="card login-box">
            <div class="login-logo"><?= htmlspecialchars((string)$settings['site_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <div class="subtitle" style="margin:0 0 18px;">Вход в панель управления</div>

            <?php if ($error): ?>
                <div class="notice notice--error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($notice): ?>
                <div class="notice notice--success"><?= htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <?= csrfInput() ?>

                <div class="field">
                    <label for="username">Логин</label>
                    <input id="username" type="text" name="username" required autocomplete="username">
                </div>

                <div class="field" style="margin-top:14px;">
                    <label for="password">Пароль</label>
                    <input id="password" type="password" name="password" required autocomplete="current-password">
                </div>

                <div style="margin-top:18px;">
                    <button type="submit" class="btn btn-primary">Войти</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php require_once dirname(__DIR__, 2) . '/inc/manage_message_modal.php'; ?>
</body>
</html>
