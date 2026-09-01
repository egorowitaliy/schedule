<?php
require_once dirname(__DIR__, 2) . '/inc/db.php';
require_once dirname(__DIR__, 2) . '/inc/auth.php';
requireAuth();

$error = '';
$success = '';
$editSubject = null;
$flash = pullFlashMessage();
if ($flash !== null) {
    ${$flash['type']} = $flash['message'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfOrDie();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim(postStringInput('name'));

        if ($name === '' || mb_strlen($name, 'UTF-8') > 255) {
            $error = 'Название дисциплины должно содержать от 1 до 255 символов';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO subjects (name) VALUES (?)');
                $stmt->execute([$name]);
                setFlashMessage('success', 'Дисциплина добавлена');
                redirectSeeOther('/manage/subjects.php');
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    $error = 'Такая дисциплина уже существует';
                } else {
                    $error = 'Не удалось добавить дисциплину';
                }
            }
        }
    }

    if ($action === 'update') {
        $id = postIntInput('id');
        $name = trim(postStringInput('name'));

        if ($id <= 0) {
            $error = 'Некорректный ID дисциплины';
        } elseif ($name === '' || mb_strlen($name, 'UTF-8') > 255) {
            $error = 'Название дисциплины должно содержать от 1 до 255 символов';
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE subjects SET name = ? WHERE id = ?');
                $stmt->execute([$name, $id]);
                $success = $stmt->rowCount() === 1 ? 'Дисциплина обновлена' : '';
                if ($success === '') {
                    $error = 'Дисциплина не найдена';
                } else {
                    setFlashMessage('success', $success);
                    redirectSeeOther('/manage/subjects.php');
                }
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    $error = 'Такая дисциплина уже существует';
                } else {
                    $error = 'Не удалось обновить дисциплину';
                }
            }
        }
    }

    if ($action === 'toggle') {
        $id = postIntInput('id');

        if ($id <= 0) {
            $error = 'Некорректный ID дисциплины';
        } else {
            $stmt = $pdo->prepare('UPDATE subjects SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE id = ?');
            $stmt->execute([$id]);
            $success = $stmt->rowCount() === 1 ? 'Статус дисциплины изменён' : '';
            if ($success === '') {
                $error = 'Дисциплина не найдена';
            } else {
                setFlashMessage('success', $success);
                redirectSeeOther('/manage/subjects.php');
            }
        }
    }

    if ($action === 'delete') {
        $id = postIntInput('id');

        if ($id <= 0) {
            $error = 'Некорректный ID дисциплины';
        } else try {
            $stmt = $pdo->prepare('DELETE FROM subjects WHERE id = ?');
            $stmt->execute([$id]);
            $success = $stmt->rowCount() === 1 ? 'Дисциплина удалена' : '';
            if ($success === '') {
                $error = 'Дисциплина не найдена';
            } else {
                setFlashMessage('success', $success);
                redirectSeeOther('/manage/subjects.php');
            }
        } catch (PDOException $e) {
            $error = 'Не удалось удалить дисциплину';
        }
    }
}

$editId = queryIntInput('edit_id');
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM subjects WHERE id = ?');
    $stmt->execute([$editId]);
    $editSubject = $stmt->fetch();
}

$stmt = $pdo->query('SELECT * FROM subjects ORDER BY name ASC');
$subjects = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Дисциплины</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" type="image/svg+xml" href="/assets/icon.svg">
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <h1 class="page-title">Дисциплины</h1>
            <div class="subtitle">Справочник учебных предметов и дисциплин</div>
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
        <?php if ($editSubject): ?>
            <form method="post" class="form-row">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$editSubject['id'] ?>">

                <div class="field" style="min-width:320px; flex:1;">
                    <label for="name">Название дисциплины</label>
                    <input id="name" type="text" name="name" required maxlength="255" value="<?= htmlspecialchars($editSubject['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Сохранить</button>
                </div>

                <div>
                    <a class="btn-link" href="/manage/subjects.php">Отмена</a>
                </div>
            </form>
        <?php else: ?>
            <form method="post" class="form-row">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="add">

                <div class="field" style="min-width:320px; flex:1;">
                    <label for="name">Название дисциплины</label>
                    <input id="name" type="text" name="name" required maxlength="255">
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Добавить дисциплину</button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <div class="card card--tight">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Активна</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$subjects): ?>
                    <tr>
                        <td colspan="4">Дисциплин пока нет</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($subjects as $subject): ?>
                        <tr class="<?= (int)$subject['active'] === 1 ? '' : 'row-inactive' ?>">
                            <td><?= (int)$subject['id'] ?></td>
                            <td><?= htmlspecialchars($subject['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= (int)$subject['active'] === 1 ? 'Да' : 'Нет' ?></td>
                            <td>
                                <div class="action-group">
                                    <form method="get" action="/manage/subjects.php" class="inline">
                                        <input type="hidden" name="edit_id" value="<?= (int)$subject['id'] ?>">
                                        <button type="submit" class="btn-sm">Изменить</button>
                                    </form>

                                    <form method="post" class="inline">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$subject['id'] ?>">
                                        <button type="submit" class="btn-sm"><?= (int)$subject['active'] === 1 ? 'Выключить' : 'Включить' ?></button>
                                    </form>

                                    <form method="post" class="inline" onsubmit="return confirm('Удалить дисциплину?');">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$subject['id'] ?>">
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
