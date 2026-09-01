<?php

declare(strict_types=1);

/**
 * Bcrypt учитывает только первые 72 байта. Предварительное SHA-384-хеширование
 * даёт фиксированный ASCII-вход и сохраняет поддержку длинных Unicode-паролей.
 */
function schedulePasswordMaterial(string $password): string
{
    return base64_encode(hash('sha384', $password, true));
}

function schedulePasswordHash(string $password): string
{
    $hash = password_hash(schedulePasswordMaterial($password), PASSWORD_DEFAULT);
    if (!is_string($hash)) {
        throw new RuntimeException('Не удалось создать хеш пароля');
    }

    return $hash;
}

function schedulePasswordVerify(string $password, string $storedHash): bool
{
    if (password_verify(schedulePasswordMaterial($password), $storedHash)) {
        return true;
    }

    // Совместимость с локальными установками, созданными промежуточными сборками до публикации.
    return password_verify($password, $storedHash);
}

function schedulePasswordNeedsRehash(string $password, string $storedHash): bool
{
    if (password_verify(schedulePasswordMaterial($password), $storedHash)) {
        return password_needs_rehash($storedHash, PASSWORD_DEFAULT);
    }

    // Старый прямой bcrypt необходимо заменить на новый формат после успешного входа.
    return password_verify($password, $storedHash);
}
