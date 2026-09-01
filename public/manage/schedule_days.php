<?php
require_once dirname(__DIR__, 2) . '/inc/db.php';
require_once dirname(__DIR__, 2) . '/inc/auth.php';
require_once dirname(__DIR__, 2) . '/inc/helpers.php';
requireAuth();

$settings = loadSettings($pdo);
$adminDaysBack = (int)($settings['admin_days_back'] ?? 30);

$error = '';
$success = '';
$flash = pullFlashMessage();
if ($flash !== null) {
    ${$flash['type']} = $flash['message'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfOrDie();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $date = trim(postStringInput('study_date'));

        if ($date === '') {
            $error = 'Дата не указана';
        } elseif (!isValidIsoDate($date)) {
            $error = 'Некорректная дата';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO schedule_days (study_date) VALUES (?)');
                $stmt->execute([$date]);

                $newDayId = (int)$pdo->lastInsertId();

                header('Location: /manage/schedule_edit.php?day_id=' . $newDayId, true, 303);
                exit;
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    $error = 'День с такой датой уже существует';
                } else {
                    $error = 'Не удалось создать день расписания';
                }
            }
        }
    }

    if ($action === 'delete') {
        $id = postIntInput('id');

        if ($id <= 0) {
            $error = 'Некорректный ID дня';
        } else {
            try {
                $stmt = $pdo->prepare('DELETE FROM schedule_days WHERE id = ?');
                $stmt->execute([$id]);
                $success = $stmt->rowCount() === 1 ? 'День удалён' : '';
                if ($success === '') {
                    $error = 'День не найден';
                } else {
                    setFlashMessage('success', $success);
                    redirectSeeOther('/manage/schedule_days.php');
                }
            } catch (PDOException $e) {
                $error = 'Не удалось удалить день';
            }
        }
    }

    if ($action === 'toggle') {
        $id = postIntInput('id');

        if ($id <= 0) {
            $error = 'Некорректный ID дня';
        } else {
            try {
                $stmt = $pdo->prepare('
                    UPDATE schedule_days
                    SET is_published = CASE WHEN is_published = 1 THEN 0 ELSE 1 END, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ');
                $stmt->execute([$id]);
                $success = $stmt->rowCount() === 1 ? 'Статус публикации изменён' : '';
                if ($success === '') {
                    $error = 'День не найден';
                } else {
                    setFlashMessage('success', $success);
                    redirectSeeOther('/manage/schedule_days.php');
                }
            } catch (PDOException $e) {
                $error = 'Не удалось изменить статус публикации';
            }
        }
    }

    if ($action === 'copy') {
        $id = postIntInput('id');
        $newDate = trim(postStringInput('new_date'));

        if ($id <= 0) {
            $error = 'Некорректный ID дня';
        } elseif ($newDate === '') {
            $error = 'Не указана новая дата';
        } elseif (!isValidIsoDate($newDate)) {
            $error = 'Некорректная новая дата';
        } else {
            try {
                $pdo->exec('BEGIN IMMEDIATE');

                $sourceStmt = $pdo->prepare('SELECT study_date FROM schedule_days WHERE id = ?');
                $sourceStmt->execute([$id]);
                $sourceDate = $sourceStmt->fetchColumn();
                if (!is_string($sourceDate)) {
                    throw new InvalidArgumentException('Исходный день не найден');
                }

                $stmt = $pdo->prepare('INSERT INTO schedule_days (study_date) VALUES (?)');
                $stmt->execute([$newDate]);
                $newDayId = (int)$pdo->lastInsertId();

                copyScheduleEntriesForDate($pdo, $id, $newDayId, $sourceDate, $newDate);

                $pdo->exec('COMMIT');

                header('Location: /manage/schedule_edit.php?day_id=' . $newDayId, true, 303);
                exit;
            } catch (Throwable $e) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable $rollbackError) {
                    // Исходная ошибка важнее ошибки отката.
                }

                if ($e instanceof InvalidArgumentException) {
                    $error = $e->getMessage();
                } elseif ($e instanceof PDOException && (string)$e->getCode() === '23000') {
                    $error = 'День с такой датой уже существует';
                } else {
                    $error = 'Не удалось скопировать день';
                }
            }
        }
    }
}

$cutoffDate = date('Y-m-d', strtotime('-' . (int)$adminDaysBack . ' days'));

$stmt = $pdo->prepare('
    SELECT *
    FROM schedule_days
    WHERE study_date >= ?
    ORDER BY study_date DESC
');
$stmt->execute([$cutoffDate]);
$days = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Дни расписания</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" type="image/svg+xml" href="/assets/icon.svg">
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <h1 class="page-title">Дни расписания</h1>
            <div class="subtitle">Создание, публикация, копирование и переход к редактированию</div>
        </div>

        <div class="topbar-links">
            <a href="/manage/dashboard.php">Панель управления</a>
            <?= logoutForm() ?>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="notice notice--success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="notice notice--error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="post" class="form-row">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="add">

            <div class="field" style="min-width:220px;">
                <label for="study_date">Новая дата</label>
                <input id="study_date" type="date" name="study_date" required>
            </div>

            <div>
                <button class="btn btn-primary" type="submit">Создать день</button>
            </div>
        </form>
    </div>

    <div class="card card--tight">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Дата</th>
                        <th>Опубликовано</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$days): ?>
                    <tr>
                        <td colspan="4">Дней пока нет</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($days as $d): ?>
                        <tr class="<?= $d['is_published'] ? '' : 'row-inactive' ?>">
                            <td><?= (int)$d['id'] ?></td>
                            <td><?= htmlspecialchars($d['study_date']) ?></td>
                            <td><?= $d['is_published'] ? 'Да' : 'Нет' ?></td>
                            <td class="actions-cell">
                                <div class="day-actions">
                                    <div class="day-actions-top">
                                        <a class="btn-link btn-sm" href="/manage/schedule_edit.php?day_id=<?= (int)$d['id'] ?>">Редактировать</a>

                                        <form method="post" class="inline">
                                            <?= csrfInput() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                            <button type="submit" class="btn-sm"><?= $d['is_published'] ? 'Скрыть' : 'Публиковать' ?></button>
                                        </form>

                                        <form method="post" class="inline" onsubmit="return confirm('Удалить день?');">
                                            <?= csrfInput() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                            <button type="submit" class="btn-danger btn-sm">Удалить</button>
                                        </form>
                                    </div>

                                    <form method="post" class="day-copy-form">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="copy">
                                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                        <input type="date" name="new_date" required>
                                        <button type="submit" class="btn-sm">Копировать</button>
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
