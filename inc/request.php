<?php

declare(strict_types=1);

function ipMatchesNetwork(string $ip, string $network): bool
{
    if (strpos($network, '/') === false) {
        $ipBin = @inet_pton($ip);
        $networkBin = @inet_pton($network);

        return $ipBin !== false
            && $networkBin !== false
            && strlen($ipBin) === strlen($networkBin)
            && hash_equals($networkBin, $ipBin);
    }

    [$subnet, $prefixRaw] = explode('/', $network, 2);
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);

    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }
    if (!ctype_digit($prefixRaw)) {
        return false;
    }

    $prefix = (int)$prefixRaw;
    $maxBits = strlen($ipBin) * 8;
    if ($prefix < 0 || $prefix > $maxBits) {
        return false;
    }

    $fullBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;
    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
        return false;
    }
    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
}

function isTrustedProxyAddress(string $remoteAddress): bool
{
    global $config;

    if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    $trusted = $config['proxy']['trusted_proxies'] ?? [];
    if (!is_array($trusted)) {
        return false;
    }

    foreach ($trusted as $network) {
        if (is_string($network) && $network !== '' && ipMatchesNetwork($remoteAddress, $network)) {
            return true;
        }
    }

    return false;
}

function requestIsHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!isTrustedProxyAddress($remote)) {
        return false;
    }

    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    return $forwardedProto === 'https';
}

function getClientIp(): string
{
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
        $remote = '0.0.0.0';
    }

    if (!isTrustedProxyAddress($remote)) {
        return $remote;
    }

    $forwardedFor = array_values(array_filter(array_map(
        'trim',
        explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))
    )));

    // Доверенный proxy дописывает адреса справа. Первый недоверенный адрес справа — клиент.
    for ($i = count($forwardedFor) - 1; $i >= 0; $i--) {
        $forwardedAddress = $forwardedFor[$i];
        if (filter_var($forwardedAddress, FILTER_VALIDATE_IP) === false) {
            continue;
        }
        if (!isTrustedProxyAddress($forwardedAddress)) {
            return $forwardedAddress;
        }
    }

    $xRealIp = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if (filter_var($xRealIp, FILTER_VALIDATE_IP) !== false) {
        return $xRealIp;
    }

    return $remote;
}
