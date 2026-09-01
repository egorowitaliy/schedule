<?php

declare(strict_types=1);

function formatDateRu(string $date): string
{
    $months = [
        1 => 'января',
        2 => 'февраля',
        3 => 'марта',
        4 => 'апреля',
        5 => 'мая',
        6 => 'июня',
        7 => 'июля',
        8 => 'августа',
        9 => 'сентября',
        10 => 'октября',
        11 => 'ноября',
        12 => 'декабря',
    ];

    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }

    return date('d', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}


function isValidIsoDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();

    return $parsed !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d') === $date;
}

function displaySetting(array $rows, string $key, string $default, int $maxLength): string
{
    $value = trim((string)($rows[$key] ?? ''));
    if ($value === '') {
        return $default;
    }

    if (mb_strlen($value, 'UTF-8') > $maxLength) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    return $value;
}

function loadSettings(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM app_settings');
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    return [
        'site_name'           => displaySetting($rows, 'site_name', 'Schedule', 120),
        'public_title'        => displaySetting($rows, 'public_title', 'Расписание занятий', 150),
        'public_subtitle'     => displaySetting($rows, 'public_subtitle', 'Образовательная организация', 180),
        'public_days_forward' => isset($rows['public_days_forward']) ? (int)$rows['public_days_forward'] : 7,
        'public_show_today'   => isset($rows['public_show_today']) ? (int)$rows['public_show_today'] : 1,
        'admin_days_back'     => isset($rows['admin_days_back']) ? (int)$rows['admin_days_back'] : 30,
        'delete_days_after'   => isset($rows['delete_days_after']) ? (int)$rows['delete_days_after'] : 90,
    ];
}

function publicDateRange(array $settings): array
{
    $today = new DateTimeImmutable('today');
    $showToday = !empty($settings['public_show_today']);
    $daysForward = max(0, min(60, (int)($settings['public_days_forward'] ?? 7)));

    $start = $showToday ? $today : $today->modify('+1 day');
    $end = $today->modify('+' . $daysForward . ' days');

    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function formArrayString(array $values, string $key): string
{
    if (!array_key_exists($key, $values)) {
        return '';
    }
    if (!is_string($values[$key])) {
        throw new InvalidArgumentException('Некорректная структура формы');
    }
    return $values[$key];
}

function formArrayOptionalId(array $values, string $key): ?int
{
    $value = formArrayString($values, $key);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
        throw new InvalidArgumentException('Некорректный идентификатор в форме');
    }
    return (int)$value;
}

function saveSettings(PDO $pdo, array $settings): void
{
    $stmt = $pdo->prepare('
        INSERT INTO app_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(setting_key) DO UPDATE SET
            setting_value = excluded.setting_value,
            updated_at = CURRENT_TIMESTAMP
    ');

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        foreach ($settings as $key => $value) {
            $stmt->execute([$key, (string)$value]);
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
}

function copyScheduleEntriesForDate(
    PDO $pdo,
    int $sourceDayId,
    int $targetDayId,
    string $sourceDate,
    string $targetDate
): int {
    if (!isValidIsoDate($sourceDate) || !isValidIsoDate($targetDate)) {
        throw new InvalidArgumentException('Некорректная дата копирования');
    }

    $sourceWeekday = (int)(new DateTimeImmutable($sourceDate))->format('N');
    $targetWeekday = (int)(new DateTimeImmutable($targetDate))->format('N');
    $sourceMask = 1 << ($sourceWeekday - 1);
    $targetMask = 1 << ($targetWeekday - 1);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM schedule_entries WHERE schedule_day_id = ?');
    $countStmt->execute([$sourceDayId]);
    $sourceCount = (int)$countStmt->fetchColumn();

    $incompatibleSourceStmt = $pdo->prepare('
        SELECT DISTINCT source_time.lesson_number
        FROM schedule_entries e
        JOIN lesson_times source_time ON source_time.id = e.lesson_time_id
        WHERE e.schedule_day_id = ?
          AND (source_time.weekdays_mask & ?) = 0
        ORDER BY source_time.lesson_number
    ');
    $incompatibleSourceStmt->execute([$sourceDayId, $sourceMask]);
    if ($incompatibleSourceStmt->fetchColumn() !== false) {
        throw new InvalidArgumentException('Исходное расписание несовместимо с сеткой своего дня. Копирование отменено.');
    }

    $missingStmt = $pdo->prepare('
        SELECT DISTINCT source_time.lesson_number
        FROM schedule_entries e
        JOIN lesson_times source_time ON source_time.id = e.lesson_time_id
        WHERE e.schedule_day_id = ?
          AND NOT EXISTS (
              SELECT 1
              FROM lesson_times target_time
              WHERE target_time.lesson_number = source_time.lesson_number
                AND (target_time.weekdays_mask & ?) != 0
          )
        ORDER BY source_time.lesson_number
    ');
    $missingStmt->execute([$sourceDayId, $targetMask]);
    $missingNumbers = array_map('intval', $missingStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($missingNumbers !== []) {
        $label = count($missingNumbers) === 1 ? 'пара №' : 'пары №';
        throw new InvalidArgumentException(
            'Расписание не скопировано: для целевого дня недели отсутствует '
            . $label . implode(', №', $missingNumbers) . '.'
        );
    }

    $stmt = $pdo->prepare('
        INSERT INTO schedule_entries
        (
            schedule_day_id,
            group_id,
            lesson_time_id,
            subject_id,
            teacher_id,
            room_id,
            lesson_type,
            note,
            is_distance,
            is_cancelled
        )
        SELECT
            ?,
            e.group_id,
            target_time.id,
            e.subject_id,
            e.teacher_id,
            e.room_id,
            e.lesson_type,
            e.note,
            e.is_distance,
            e.is_cancelled
        FROM schedule_entries e
        JOIN lesson_times source_time ON source_time.id = e.lesson_time_id
        JOIN lesson_times target_time
          ON target_time.lesson_number = source_time.lesson_number
         AND (target_time.weekdays_mask & ?) != 0
        WHERE e.schedule_day_id = ?
          AND (source_time.weekdays_mask & ?) != 0
    ');
    $stmt->execute([$targetDayId, $targetMask, $sourceDayId, $sourceMask]);

    $copiedCount = $stmt->rowCount();
    if ($copiedCount !== $sourceCount) {
        throw new RuntimeException('Количество скопированных занятий не совпало с исходным расписанием');
    }

    return $copiedCount;
}

function acquireScheduleDayLock(
    PDO $pdo,
    int $dayId,
    int $userId,
    string $editorInstanceToken,
    string $documentToken,
    string $username,
    string $fullName,
    string $ip,
    int $ttlSeconds = 180
): array {
    if (!preg_match('/^[0-9a-f]{64}$/', $editorInstanceToken)
        || !preg_match('/^[0-9a-f]{64}$/', $documentToken)) {
        throw new InvalidArgumentException('Некорректный токен блокировки');
    }

    $now = time();
    $expiresBefore = $now - max(30, $ttlSeconds);

    // BEGIN IMMEDIATE сериализует конкурирующие попытки захвата блокировки.
    $pdo->exec('BEGIN IMMEDIATE');

    try {
        $stmt = $pdo->prepare('SELECT * FROM schedule_day_locks WHERE schedule_day_id = ?');
        $stmt->execute([$dayId]);
        $lock = $stmt->fetch();

        if (!$lock) {
            $stmt = $pdo->prepare('
                INSERT INTO schedule_day_locks
                (schedule_day_id, user_id, editor_instance_token, document_token, username, full_name, ip_address, locked_at, last_seen_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $dayId,
                $userId,
                $editorInstanceToken,
                $documentToken,
                $username,
                $fullName,
                $ip,
                $now,
                $now,
            ]);
            $pdo->exec('COMMIT');

            return ['ok' => true, 'owner' => true];
        }

        $expired = (int)$lock['last_seen_at'] < $expiresBefore;

        $sameEditor = (int)$lock['user_id'] === $userId
            && hash_equals((string)$lock['editor_instance_token'], $editorInstanceToken);
        if ($sameEditor || $expired) {
            $lockedAt = $sameEditor ? (int)$lock['locked_at'] : $now;
            $stmt = $pdo->prepare('
                UPDATE schedule_day_locks
                SET user_id = ?, editor_instance_token = ?, document_token = ?, username = ?, full_name = ?, ip_address = ?, locked_at = ?, last_seen_at = ?
                WHERE schedule_day_id = ?
            ');
            $stmt->execute([
                $userId,
                $editorInstanceToken,
                $documentToken,
                $username,
                $fullName,
                $ip,
                $lockedAt,
                $now,
                $dayId,
            ]);
            $pdo->exec('COMMIT');

            return ['ok' => true, 'owner' => true];
        }

        $pdo->exec('COMMIT');
        return ['ok' => false, 'owner' => false, 'lock' => $lock];
    } catch (Throwable $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable $rollbackError) {
            // Исходная ошибка важнее ошибки отката.
        }
        throw $e;
    }
}

function touchScheduleDayLock(
    PDO $pdo,
    int $dayId,
    int $userId,
    string $editorInstanceToken,
    string $documentToken
): bool
{
    $stmt = $pdo->prepare('
        UPDATE schedule_day_locks
        SET last_seen_at = ?
        WHERE schedule_day_id = ?
          AND user_id = ?
          AND editor_instance_token = ?
          AND document_token = ?
    ');
    $stmt->execute([time(), $dayId, $userId, $editorInstanceToken, $documentToken]);

    return $stmt->rowCount() === 1;
}

function releaseScheduleDayLock(
    PDO $pdo,
    int $dayId,
    int $userId,
    string $editorInstanceToken,
    string $documentToken
): void
{
    $stmt = $pdo->prepare('
        DELETE FROM schedule_day_locks
        WHERE schedule_day_id = ?
          AND user_id = ?
          AND editor_instance_token = ?
          AND document_token = ?
    ');
    $stmt->execute([$dayId, $userId, $editorInstanceToken, $documentToken]);
}

function formatDateTimeRu(string|int $dateTime): string
{
    $raw = (string)$dateTime;
    $ts = ctype_digit($raw) ? (int)$raw : strtotime($raw);

    if ($ts === false || $ts <= 0) {
        return $raw;
    }

    return date('d.m.Y H:i:s', $ts);
}
