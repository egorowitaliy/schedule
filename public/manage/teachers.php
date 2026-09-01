<?php
require_once dirname(__DIR__, 2) . '/inc/db.php';
require_once dirname(__DIR__, 2) . '/inc/auth.php';
requireAuth();

$error = '';
$success = '';
$editTeacher = null;
$flash = pullFlashMessage();
if ($flash !== null) {
    ${$flash['type']} = $flash['message'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfOrDie();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $fullName = trim(postStringInput('full_name'));

        if ($fullName === '' || mb_strlen($fullName, 'UTF-8') > 255) {
            $error = 'ФИО преподавателя должно содержать от 1 до 255 символов';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO teachers (full_name) VALUES (?)');
                $stmt->execute([$fullName]);
                setFlashMessage('success', 'Преподаватель добавлен');
                redirectSeeOther('/manage/teachers.php');
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    $error = 'Такой преподаватель уже существует';
                } else {
                    $error = 'Не удалось добавить преподавателя';
                }
            }
        }
    }

    if ($action === 'update') {
        $id = postIntInput('id');
        $fullName = trim(postStringInput('full_name'));

        if ($id <= 0) {
            $error = 'Некорректный ID преподавателя';
        } elseif ($fullName === '' || mb_strlen($fullName, 'UTF-8') > 255) {
            $error = 'ФИО преподавателя должно содержать от 1 до 255 символов';
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE teachers SET full_name = ? WHERE id = ?');
                $stmt->execute([$fullName, $id]);
                $success = $stmt->rowCount() === 1 ? 'Преподаватель обновлён' : '';
                if ($success === '') {
                    $error = 'Преподаватель не найден';
                } else {
                    setFlashMessage('success', $success);
                    redirectSeeOther('/manage/teachers.php');
                }
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    $error = 'Такой преподаватель уже существует';
                } else {
                    $error = 'Не удалось обновить преподавателя';
                }
            }
        }
    }

    if ($action === 'toggle') {
        $id = postIntInput('id');

        if ($id <= 0) {
            $error = 'Некорректный ID преподавателя';
        } else {
            $stmt = $pdo->prepare('UPDATE teachers SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE id = ?');
            $stmt->execute([$id]);
            $success = $stmt->rowCount() === 1 ? 'Статус преподавателя изменён' : '';
            if ($success === '') {
                $error = 'Преподаватель не найден';
            } else {
                setFlashMessage('success', $success);
                redirectSeeOther('/manage/teachers.php');
            }
        }
    }

    if ($action === 'delete') {
        $id = postIntInput('id');

        if ($id <= 0) {
            $error = 'Некорректный ID преподавателя';
        } else try {
            $stmt = $pdo->prepare('DELETE FROM teachers WHERE id = ?');
            $stmt->execute([$id]);
            $success = $stmt->rowCount() === 1 ? 'Преподаватель удалён' : '';
            if ($success === '') {
                $error = 'Преподаватель не найден';
            } else {
                setFlashMessage('success', $success);
                redirectSeeOther('/manage/teachers.php');
            }
        } catch (PDOException $e) {
            $error = 'Не удалось удалить преподавателя';
        }
    }
}

$editId = queryIntInput('edit_id');
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM teachers WHERE id = ?');
    $stmt->execute([$editId]);
    $editTeacher = $stmt->fetch();
}

$stmt = $pdo->query('SELECT * FROM teachers ORDER BY full_name ASC');
$teachers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Преподаватели</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" type="image/svg+xml" href="/assets/icon.svg">
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <h1 class="page-title">Преподаватели</h1>
            <div class="subtitle">Справочник преподавателей и их отображение в расписании</div>
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
        <?php if ($editTeacher): ?>
            <form method="post" class="form-row">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$editTeacher['id'] ?>">

                <div class="field" style="min-width:320px; flex:1;">
                    <label for="full_name">ФИО преподавателя</label>
                    <input id="full_name" type="text" name="full_name" required maxlength="255" value="<?= htmlspecialchars($editTeacher['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Сохранить</button>
                </div>

                <div>
                    <a class="btn-link" href="/manage/teachers.php">Отмена</a>
                </div>
            </form>
        <?php else: ?>
            <form method="post" class="form-row">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="add">

                <div class="field" style="min-width:320px; flex:1;">
                    <label for="full_name">ФИО преподавателя</label>
                    <input id="full_name" type="text" name="full_name" required maxlength="255">
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Добавить преподавателя</button>
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
                        <th>ФИО</th>
                        <th>Активен</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$teachers): ?>
                    <tr>
                        <td colspan="4">Преподавателей пока нет</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($teachers as $teacher): ?>
                        <tr class="<?= (int)$teacher['active'] === 1 ? '' : 'row-inactive' ?>">
                            <td><?= (int)$teacher['id'] ?></td>
                            <td><?= htmlspecialchars($teacher['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= (int)$teacher['active'] === 1 ? 'Да' : 'Нет' ?></td>
                            <td>
                                <div class="action-group">
                                    <form method="get" action="/manage/teachers.php" class="inline">
                                        <input type="hidden" name="edit_id" value="<?= (int)$teacher['id'] ?>">
                                        <button type="submit" class="btn-sm">Изменить</button>
                                    </form>

                                    <form method="post" class="inline">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$teacher['id'] ?>">
                                        <button type="submit" class="btn-sm"><?= (int)$teacher['active'] === 1 ? 'Выключить' : 'Включить' ?></button>
                                    </form>

                                    <form method="post" class="inline" onsubmit="return confirm('Удалить преподавателя?');">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$teacher['id'] ?>">
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
