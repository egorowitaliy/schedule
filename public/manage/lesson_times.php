<?php
require_once dirname(__DIR__, 2) . '/inc/db.php';
require_once dirname(__DIR__, 2) . '/inc/auth.php';
requireAuth();

$error = '';
$success = '';
$editLesson = null;
$flash = pullFlashMessage();
if ($flash !== null) {
    ${$flash['type']} = $flash['message'];
}

$weekdayLabels = [
    1 => 'Пн',
    2 => 'Вт',
    3 => 'Ср',
    4 => 'Чт',
    5 => 'Пт',
    6 => 'Сб',
    7 => 'Вс',
];

function buildWeekdaysMask(array $days): int
{
    $mask = 0;

    foreach ($days as $day) {
        if (!is_string($day) || preg_match('/^[1-7]$/', $day) !== 1) {
            return 0;
        }
        $dayNumber = (int)$day;
        $mask |= (1 << ($dayNumber - 1));
    }

    return $mask;
}

function maskHasDay(int $mask, int $day): bool
{
    return ($mask & (1 << ($day - 1))) !== 0;
}

function formatWeekdays(int $mask, array $labels): string
{
    $result = [];

    foreach ($labels as $day => $label) {
        if (maskHasDay($mask, $day)) {
            $result[] = $label;
        }
    }

    return implode(', ', $result);
}

function isValidHourMinute(string $value): bool
{
    return preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $value) === 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfOrDie();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $id = postIntInput('id');
        $lessonNumber = postIntInput('lesson_number');
        $timeStart = trim(postStringInput('time_start'));
        $timeEnd = trim(postStringInput('time_end'));
        $weekdays = $_POST['weekdays'] ?? [];
        $weekdaysMask = buildWeekdaysMask(is_array($weekdays) ? $weekdays : []);

        if ($lessonNumber <= 0) {
            $error = 'Номер пары должен быть больше нуля';
        } elseif (!isValidHourMinute($timeStart) || !isValidHourMinute($timeEnd)) {
            $error = 'Время должно быть указано в формате ЧЧ:ММ';
        } elseif ($timeStart >= $timeEnd) {
            $error = 'Время окончания должно быть позже времени начала';
        } elseif ($weekdaysMask === 0) {
            $error = 'Выбери хотя бы один день недели';
        } elseif ($action === 'update' && $id <= 0) {
            $error = 'Некорректный ID пары';
        } else {
            try {
                $pdo->exec('BEGIN IMMEDIATE');
                $overlapStmt = $pdo->prepare('
                    SELECT 1
                    FROM lesson_times
                    WHERE lesson_number = ?
                      AND (weekdays_mask & ?) != 0
                      AND id != ?
                    LIMIT 1
                ');
                $overlapStmt->execute([$lessonNumber, $weekdaysMask, $action === 'update' ? $id : 0]);
                if ($overlapStmt->fetchColumn()) {
                    throw new InvalidArgumentException('Номер пары уже используется хотя бы в один из выбранных дней');
                }

                if ($action === 'update') {
                    $existingStmt = $pdo->prepare('SELECT lesson_number, weekdays_mask FROM lesson_times WHERE id = ?');
                    $existingStmt->execute([$id]);
                    $existingLesson = $existingStmt->fetch();
                    if (!$existingLesson) {
                        throw new InvalidArgumentException('Пара не найдена');
                    }

                    if ((int)$existingLesson['lesson_number'] !== $lessonNumber
                        || (int)$existingLesson['weekdays_mask'] !== $weekdaysMask) {
                        $compatibilityStmt = $pdo->prepare('
                            SELECT DISTINCT d.study_date
                            FROM schedule_entries e
                            JOIN schedule_days d ON d.id = e.schedule_day_id
                            WHERE e.lesson_time_id = ?
                              AND (? & CASE CAST(strftime(\'%w\', d.study_date) AS INTEGER)
                                  WHEN 0 THEN 64
                                  ELSE (1 << (CAST(strftime(\'%w\', d.study_date) AS INTEGER) - 1))
                              END) = 0
                            ORDER BY d.study_date
                            LIMIT 5
                        ');
                        $compatibilityStmt->execute([$id, $weekdaysMask]);
                        $incompatibleDates = $compatibilityStmt->fetchAll(PDO::FETCH_COLUMN);
                        if ($incompatibleDates !== []) {
                            throw new InvalidArgumentException(
                                'Изменение отменено: эта пара уже используется в расписании на '
                                . implode(', ', array_map('strval', $incompatibleDates))
                                . '. Сначала перенесите или удалите эти занятия.'
                            );
                        }
                    }
                }

                if ($action === 'add') {
                    $stmt = $pdo->prepare('
                        INSERT INTO lesson_times (lesson_number, weekdays_mask, time_start, time_end)
                        VALUES (?, ?, ?, ?)
                    ');
                    $stmt->execute([$lessonNumber, $weekdaysMask, $timeStart, $timeEnd]);
                    $success = 'Пара добавлена';
                } else {
                    $stmt = $pdo->prepare('
                        UPDATE lesson_times
                        SET lesson_number = ?, weekdays_mask = ?, time_start = ?, time_end = ?
                        WHERE id = ?
                    ');
                    $stmt->execute([$lessonNumber, $weekdaysMask, $timeStart, $timeEnd, $id]);
                    $success = $stmt->rowCount() === 1 ? 'Пара обновлена' : '';
                    if ($success === '') {
                        $error = 'Пара не найдена';
                    }
                }
                $pdo->exec('COMMIT');
                setFlashMessage('success', $success);
                redirectSeeOther('/manage/lesson_times.php');
            } catch (Throwable $e) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable $rollbackError) {
                    // Исходная ошибка важнее ошибки отката.
                }

                if ($e instanceof InvalidArgumentException) {
                    $error = $e->getMessage();
                } elseif ($e instanceof PDOException && (string)$e->getCode() === '23000') {
                    $error = 'Номер пары пересекается с существующей сеткой дней';
                } else {
                    $error = $action === 'add'
                        ? 'Не удалось добавить пару'
                        : 'Не удалось обновить пару';
                }
            }
        }
    }

    if ($action === 'delete') {
        $id = postIntInput('id');

        if ($id <= 0) {
            $error = 'Некорректный ID пары';
        } else try {
            $stmt = $pdo->prepare('DELETE FROM lesson_times WHERE id = ?');
            $stmt->execute([$id]);
            $success = $stmt->rowCount() === 1 ? 'Пара удалена' : '';
            if ($success === '') {
                $error = 'Пара не найдена';
            } else {
                setFlashMessage('success', $success);
                redirectSeeOther('/manage/lesson_times.php');
            }
        } catch (PDOException $e) {
            $error = 'Не удалось удалить пару. Возможно, она уже используется в расписании';
        }
    }
}

$editId = queryIntInput('edit_id');
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM lesson_times WHERE id = ?');
    $stmt->execute([$editId]);
    $editLesson = $stmt->fetch();
}

$stmt = $pdo->query('SELECT * FROM lesson_times ORDER BY lesson_number ASC, weekdays_mask ASC');
$lessonTimes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Пары и время</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" type="image/svg+xml" href="/assets/icon.svg">
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <h1 class="page-title">Пары и время</h1>
            <div class="subtitle">Настройка сетки занятий по дням недели</div>
        </div>

        <div class="topbar-links">
            <a href="/manage/dashboard.php">Панель управления</a>
            <?= logoutForm() ?>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="notice notice--success"><?= htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="notice notice--error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card">
        <?php
        $currentLessonNumber = $editLesson ? (int)$editLesson['lesson_number'] : '';
        $currentTimeStart = $editLesson ? substr($editLesson['time_start'], 0, 5) : '';
        $currentTimeEnd = $editLesson ? substr($editLesson['time_end'], 0, 5) : '';
        $currentMask = $editLesson ? (int)$editLesson['weekdays_mask'] : 0;
        ?>
        <form method="post" class="form-row">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="<?= $editLesson ? 'update' : 'add' ?>">
            <?php if ($editLesson): ?>
                <input type="hidden" name="id" value="<?= (int)$editLesson['id'] ?>">
            <?php endif; ?>

            <div class="field" style="min-width:120px;">
                <label for="lesson_number">Номер пары</label>
                <input id="lesson_number" class="number-input" type="number" name="lesson_number" min="1" step="1" inputmode="numeric" required value="<?= htmlspecialchars((string)$currentLessonNumber, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>

            <div class="field" style="min-width:160px;">
                <label for="time_start">Начало</label>
                <input id="time_start" class="time-input" type="time" name="time_start" required value="<?= htmlspecialchars($currentTimeStart, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>

            <div class="field" style="min-width:160px;">
                <label for="time_end">Окончание</label>
                <input id="time_end" class="time-input" type="time" name="time_end" required value="<?= htmlspecialchars($currentTimeEnd, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>

            <div class="field" style="min-width:320px;">
                <label>Дни недели</label>
                <div class="weekdays-box">
                    <?php foreach ($weekdayLabels as $day => $label): ?>
                        <label>
                            <input
                                type="checkbox"
                                name="weekdays[]"
                                value="<?= $day ?>"
                                <?= $currentMask && maskHasDay($currentMask, $day) ? 'checked' : '' ?>
                            >
                            <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <button type="submit" class="btn btn-primary">
                    <?= $editLesson ? 'Сохранить' : 'Добавить пару' ?>
                </button>
            </div>

            <?php if ($editLesson): ?>
                <div>
                    <a class="btn-link" href="/manage/lesson_times.php">Отмена</a>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <div class="card card--tight">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Пара</th>
                        <th>Дни</th>
                        <th>Начало</th>
                        <th>Окончание</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$lessonTimes): ?>
                    <tr>
                        <td colspan="6">Пар пока нет</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($lessonTimes as $lesson): ?>
                        <tr>
                            <td><?= (int)$lesson['id'] ?></td>
                            <td><?= (int)$lesson['lesson_number'] ?></td>
                            <td><?= htmlspecialchars(formatWeekdays((int)$lesson['weekdays_mask'], $weekdayLabels), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(substr($lesson['time_start'], 0, 5), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(substr($lesson['time_end'], 0, 5), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td>
                                <div class="action-group">
                                    <form method="get" action="/manage/lesson_times.php" class="inline">
                                        <input type="hidden" name="edit_id" value="<?= (int)$lesson['id'] ?>">
                                        <button type="submit" class="btn-sm">Изменить</button>
                                    </form>

                                    <form method="post" class="inline" onsubmit="return confirm('Удалить пару?');">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$lesson['id'] ?>">
                                        <button type="submit" class="btn-danger btn-sm">Удалить</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php require_once dirname(__DIR__, 2) . '/inc/manage_footer.php'; ?>
</div>
</body>
</html>
