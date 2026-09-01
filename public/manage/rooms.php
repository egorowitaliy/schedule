<?php
require_once dirname(__DIR__, 2) . '/inc/db.php';
require_once dirname(__DIR__, 2) . '/inc/auth.php';
requireAuth();

$error = '';
$success = '';
$editRoom = null;
$flash = pullFlashMessage();
if ($flash !== null) {
    ${$flash['type']} = $flash['message'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfOrDie();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim(postStringInput('name'));

        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            $error = 'Название аудитории должно содержать от 1 до 100 символов';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO rooms (name) VALUES (?)');
                $stmt->execute([$name]);
                setFlashMessage('success', 'Аудитория добавлена');
                redirectSeeOther('/manage/rooms.php');
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    $error = 'Такая аудитория уже существует';
                } else {
                    $error = 'Не удалось добавить аудиторию';
                }
            }
        }
    }

    if ($action === 'update') {
        $id = postIntInput('id');
        $name = trim(postStringInput('name'));

        if ($id <= 0) {
            $error = 'Некорректный ID аудитории';
        } elseif ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            $error = 'Название аудитории должно содержать от 1 до 100 символов';
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE rooms SET name = ? WHERE id = ?');
                $stmt->execute([$name, $id]);
                $success = $stmt->rowCount() === 1 ? 'Аудитория обновлена' : '';
                if ($success === '') {
                    $error = 'Аудитория не найдена';
                } else {
                    setFlashMessage('success', $success);
                    redirectSeeOther('/manage/rooms.php');
                }
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    $error = 'Такая аудитория уже существует';
                } else {
                    $error = 'Не удалось обновить аудиторию';
                }
            }
        }
    }

    if ($action === 'toggle') {
        $id = postIntInput('id');

        if ($id <= 0) {
            $error = 'Некорректный ID аудитории';
        } else {
            $stmt = $pdo->prepare('UPDATE rooms SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE id = ?');
            $stmt->execute([$id]);
            $success = $stmt->rowCount() === 1 ? 'Статус аудитории изменён' : '';
            if ($success === '') {
                $error = 'Аудитория не найдена';
            } else {
                setFlashMessage('success', $success);
                redirectSeeOther('/manage/rooms.php');
            }
        }
    }

    if ($action === 'delete') {
        $id = postIntInput('id');

        if ($id <= 0) {
            $error = 'Некорректный ID аудитории';
        } else try {
            $stmt = $pdo->prepare('DELETE FROM rooms WHERE id = ?');
            $stmt->execute([$id]);
            $success = $stmt->rowCount() === 1 ? 'Аудитория удалена' : '';
            if ($success === '') {
                $error = 'Аудитория не найдена';
            } else {
                setFlashMessage('success', $success);
                redirectSeeOther('/manage/rooms.php');
            }
        } catch (PDOException $e) {
            $error = 'Не удалось удалить аудиторию';
        }
    }
}

$editId = queryIntInput('edit_id');
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE id = ?');
    $stmt->execute([$editId]);
    $editRoom = $stmt->fetch();
}

$stmt = $pdo->query('SELECT * FROM rooms ORDER BY name ASC');
$rooms = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Аудитории</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" type="image/svg+xml" href="/assets/icon.svg">
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <h1 class="page-title">Аудитории</h1>
            <div class="subtitle">Справочник кабинетов, лабораторий и других помещений</div>
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
        <?php if ($editRoom): ?>
            <form method="post" class="form-row">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$editRoom['id'] ?>">

                <div class="field" style="min-width:260px; flex:1;">
                    <label for="name">Название аудитории</label>
                    <input id="name" type="text" name="name" required maxlength="100" value="<?= htmlspecialchars($editRoom['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Сохранить</button>
                </div>

                <div>
                    <a class="btn-link" href="/manage/rooms.php">Отмена</a>
                </div>
            </form>
        <?php else: ?>
            <form method="post" class="form-row">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="add">

                <div class="field" style="min-width:260px; flex:1;">
                    <label for="name">Название аудитории</label>
                    <input id="name" type="text" name="name" required maxlength="100">
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Добавить аудиторию</button>
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
                <?php if (!$rooms): ?>
                    <tr>
                        <td colspan="4">Аудиторий пока нет</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rooms as $room): ?>
                        <tr class="<?= (int)$room['active'] === 1 ? '' : 'row-inactive' ?>">
                            <td><?= (int)$room['id'] ?></td>
                            <td><?= htmlspecialchars($room['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= (int)$room['active'] === 1 ? 'Да' : 'Нет' ?></td>
                            <td>
                                <div class="action-group">
                                    <form method="get" action="/manage/rooms.php" class="inline">
                                        <input type="hidden" name="edit_id" value="<?= (int)$room['id'] ?>">
                                        <button type="submit" class="btn-sm">Изменить</button>
                                    </form>

                                    <form method="post" class="inline">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$room['id'] ?>">
                                        <button type="submit" class="btn-sm"><?= (int)$room['active'] === 1 ? 'Выключить' : 'Включить' ?></button>
                                    </form>

                                    <form method="post" class="inline" onsubmit="return confirm('Удалить аудиторию?');">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$room['id'] ?>">
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
