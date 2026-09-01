<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/passwords.php';

$timezone = (string)($config['app']['timezone'] ?? 'UTC');
if (in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
    date_default_timezone_set($timezone);
}

$httpsEnabled = requestIsHttps();
$sessionName = (string)($config['app']['session_name'] ?? 'schedule_admin_session');
if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $sessionName)) {
    $sessionName = 'schedule_admin_session';
}

ini_set('session.use_strict_mode', '1');
session_name($sessionName);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $httpsEnabled,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
}

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']) && is_int($_SESSION['user_id']);
}

function requireAuth(): void
{
    if (!isLoggedIn()) {
        header('Location: /manage/login.php');
        exit;
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare('SELECT auth_version FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $authVersion = $stmt->fetchColumn();
        $sessionAuthVersion = $_SESSION['auth_version'] ?? null;

        if ($authVersion === false
            || !is_int($sessionAuthVersion)
            || $sessionAuthVersion !== (int)$authVersion) {
            logoutUser();
            header('Location: /manage/login.php');
            exit;
        }
    }
}

function logoutUser(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => (bool)$params['secure'],
            'httponly' => (bool)$params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

function getUserAgent(): string
{
    return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255, 'UTF-8');
}

function writeAuthLog(string $message): void
{
    $ip = getClientIp();
    $ua = getUserAgent();
    $line = sprintf(
        "[%s] ip=%s ua=\"%s\" %s\n",
        date('Y-m-d H:i:s'),
        $ip,
        str_replace(["\n", "\r"], ' ', $ua),
        str_replace(["\n", "\r"], ' ', $message)
    );

    $logDir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0770, true);
    }

    $logFile = $logDir . '/auth.log';
    $newFile = !is_file($logFile);
    @error_log($line, 3, $logFile);
    if ($newFile) {
        @chmod($logFile, 0600);
    }
}

function csrfToken(): string
{
    return (string)($_SESSION['csrf_token'] ?? '');
}

function csrfInput(): string
{
    return '<input type="hidden" name="csrf_token" value="' .
        htmlspecialchars(csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
        '">';
}

function logoutForm(): string
{
    return '<form method="post" action="/manage/logout.php" class="inline logout-form">' .
        csrfInput() .
        '<button type="submit" class="topbar-link-button">Выйти</button>' .
        '</form>';
}

function requirePostMethod(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        die('Метод не поддерживается');
    }
}

function postStringInput(string $key): string
{
    $value = $_POST[$key] ?? null;
    return is_string($value) ? $value : '';
}

function postIntInput(string $key): int
{
    if (!array_key_exists($key, $_POST)) {
        return PHP_INT_MIN + 1;
    }

    $value = $_POST[$key];
    if (!is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1) {
        return PHP_INT_MIN;
    }

    $validated = filter_var($value, FILTER_VALIDATE_INT);
    if ($validated === false || $validated === PHP_INT_MIN || $validated === PHP_INT_MIN + 1) {
        return PHP_INT_MIN;
    }

    return $validated;
}

function postIntInputIsMalformed(int $value): bool
{
    return $value === PHP_INT_MIN;
}

function postIntInputIsMissing(int $value): bool
{
    return $value === PHP_INT_MIN + 1;
}

function queryIntInput(string $key): int
{
    $value = $_GET[$key] ?? null;
    if (!is_string($value) || preg_match('/^-?[0-9]+$/', $value) !== 1) {
        return 0;
    }
    return (int)$value;
}

function validateCsrfOrDie(): void
{
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $formToken = postStringInput('csrf_token');

    if ($sessionToken === '' || $formToken === '' || !hash_equals($sessionToken, $formToken)) {
        http_response_code(403);
        die('Некорректный CSRF-токен');
    }
}

function validateCsrfFromRequestOrDie(): void
{
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $requestToken = '';

    if (is_string($_POST['csrf_token'] ?? null)) {
        $requestToken = $_POST['csrf_token'];
    } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $requestToken = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    }

    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        http_response_code(403);
        die('Некорректный CSRF-токен');
    }
}

function setFlashMessage(string $type, string $message): void
{
    if (!in_array($type, ['success', 'error'], true)) {
        throw new InvalidArgumentException('Некорректный тип сообщения');
    }

    $_SESSION['flash_message'] = ['type' => $type, 'message' => $message];
}

function pullFlashMessage(): ?array
{
    $flash = $_SESSION['flash_message'] ?? null;
    unset($_SESSION['flash_message']);

    if (!is_array($flash)
        || !in_array($flash['type'] ?? null, ['success', 'error'], true)
        || !is_string($flash['message'] ?? null)) {
        return null;
    }

    return $flash;
}

function redirectSeeOther(string $path): never
{
    if (!str_starts_with($path, '/')) {
        throw new InvalidArgumentException('Некорректный путь перенаправления');
    }

    header('Location: ' . $path, true, 303);
    exit;
}
