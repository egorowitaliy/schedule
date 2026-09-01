<?php
require_once dirname(__DIR__, 2) . '/inc/db.php';
require_once dirname(__DIR__, 2) . '/inc/auth.php';
requireAuth();

$error = '';
$success = '';
$passwordModalError = '';
$openPasswordModal = false;
$editUser = null;
$flash = pullFlashMessage();
if ($flash !== null) {
    ${$flash['type']} = $flash['message'];
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$editId = queryIntInput('edit_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfOrDie();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if (in_array($action, ['update_profile', 'update_password'], true)) {
        $postedId = postIntInput('id');
        if ($postedId > 0) {
            $editId = $postedId;
        }
    }

    if ($action === 'add') {
        $fullName = trim(postStringInput('full_name'));
        $username = trim(postStringInput('username'));
        $password = postStringInput('password');
        $passwordConfirm = postStringInput('password_confirm');

        if ($fullName === '' || mb_strlen($fullName, 'UTF-8') > 120) {
            $error = 'Укажите ФИО длиной до 120 символов';
        } elseif (!preg_match('/^[\p{L}\p{N}_.-]{3,64}$/u', $username)) {
            $error = 'Логин: 3–64 символа; буквы, цифры, точка, дефис и подчёркивание';
        } elseif ($password === '') {
            $error = 'Пароль пустой';
        } elseif ($password !== $passwordConfirm) {
            $error = 'Пароли не совпадают';
        } elseif (mb_strlen($password, 'UTF-8') < 12 || mb_strlen($password, 'UTF-8') > 4096) {
            $error = 'Пароль должен содержать от 12 до 4096 символов';
        } else {
            try {
                $passwordHash = schedulePasswordHash($password);

                $stmt = $pdo->prepare('
                    INSERT INTO users (username, full_name, password_hash)
                    VALUES (?, ?, ?)
                ');
                $stmt->execute([$username, $fullName, $passwordHash]);

                setFlashMessage('success', 'Пользователь создан');
                redirectSeeOther('/manage/users.php');
            } catch (Throwable $e) {
                if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
                    $error = 'Пользователь с таким логином уже существует';
                } else {
                    $error = 'Не удалось создать пользователя';
                }
            }
        }
    }

    if ($action === 'update_profile') {
        $id = postIntInput('id');
        $fullName = trim(postStringInput('full_name'));
        $username = trim(postStringInput('username'));

        if ($id <= 0) {
            $error = 'Некорректный ID пользователя';
        } elseif ($fullName === '' || mb_strlen($fullName, 'UTF-8') > 120) {
            $error = 'Укажите ФИО длиной до 120 символов';
        } elseif (!preg_match('/^[\p{L}\p{N}_.-]{3,64}$/u', $username)) {
            $error = 'Логин: 3–64 символа; буквы, цифры, точка, дефис и подчёркивание';
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE users SET username = ?, full_name = ? WHERE id = ?');
                $stmt->execute([$username, $fullName, $id]);

                $exists = $pdo->prepare('SELECT 1 FROM users WHERE id = ?');
                $exists->execute([$id]);
                if (!$exists->fetchColumn()) {
                    $error = 'Пользователь не найден';
                } else {
                    if ($id === $currentUserId) {
                        $_SESSION['username'] = $username;
                    }
                    setFlashMessage('success', 'Данные пользователя сохранены');
                    redirectSeeOther('/manage/users.php?edit_id=' . $id);
                }
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    $error = 'Пользователь с таким логином уже существует';
                } else {
                    $error = 'Не удалось сохранить данные пользователя';
                }
            }
        }
    }

    if ($action === 'update_password') {
        $id = postIntInput('id');
        $password = postStringInput('password');
        $passwordConfirm = postStringInput('password_confirm');
        $openPasswordModal = true;

        if ($id <= 0) {
            $passwordModalError = 'Некорректный ID пользователя';
        } elseif ($password === '') {
            $passwordModalError = 'Новый пароль пустой';
        } elseif ($password !== $passwordConfirm) {
            $passwordModalError = 'Пароли не совпадают';
        } elseif (mb_strlen($password, 'UTF-8') < 12 || mb_strlen($password, 'UTF-8') > 4096) {
            $passwordModalError = 'Пароль должен содержать от 12 до 4096 символов';
        } else {
            try {
                $passwordHash = schedulePasswordHash($password);

                $stmt = $pdo->prepare('
                    UPDATE users
                    SET password_hash = ?, auth_version = auth_version + 1
                    WHERE id = ?
                ');
                $stmt->execute([$passwordHash, $id]);

                if ($stmt->rowCount() !== 1) {
                    $passwordModalError = 'Пользователь не найден';
                } elseif ($id === $currentUserId) {
                    logoutUser();
                    header('Location: /manage/login.php?password_changed=1', true, 303);
                    exit;
                } else {
                    setFlashMessage('success', 'Пароль обновлён. Ранее открытые сеансы пользователя завершены.');
                    redirectSeeOther('/manage/users.php?edit_id=' . $id);
                }
            } catch (Throwable $e) {
                $passwordModalError = 'Не удалось обновить пароль';
            }
        }
    }

    if ($action === 'delete') {
        $id = postIntInput('id');

        if ($id <= 0) {
            $error = 'Некорректный ID пользователя';
        } elseif ($id === $currentUserId) {
            $error = 'Нельзя удалить самого себя';
        } else {
            try {
                $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([$id]);
                if ($stmt->rowCount() !== 1) {
                    $error = 'Пользователь не найден';
                } else {
                    setFlashMessage('success', 'Пользователь удалён');
                    redirectSeeOther('/manage/users.php');
                }
            } catch (PDOException $e) {
                $error = 'Не удалось удалить пользователя';
            }
        }
    }
}

if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT id, username, full_name, created_at FROM users WHERE id = ?');
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch();

    if (!$editUser && $error === '' && $passwordModalError === '') {
        $error = 'Пользователь не найден';
    }
}

$stmt = $pdo->query('SELECT id, username, full_name, created_at FROM users ORDER BY id ASC');
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Пользователи</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" type="image/svg+xml" href="/assets/icon.svg">
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <h1 class="page-title">Пользователи</h1>
            <div class="subtitle">Учётные записи, ФИО, смена паролей и удаление пользователей</div>
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
        <?php if ($editUser): ?>
            <form method="post" class="user-edit-form">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">

                <div class="field user-edit-field user-edit-field--name">
                    <label for="edit_full_name">ФИО</label>
                    <input id="edit_full_name" type="text" name="full_name" value="<?= htmlspecialchars($editUser['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="120" autocomplete="name">
                </div>

                <div class="field user-edit-field user-edit-field--login">
                    <label for="edit_username">Логин</label>
                    <input id="edit_username" type="text" name="username" value="<?= htmlspecialchars($editUser['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required minlength="3" maxlength="64" autocomplete="username">
                </div>

                <div class="user-edit-actions">
                    <button type="submit" class="btn btn-primary">Сохранить</button>
                    <button type="button" class="btn" id="open-password-modal">Сменить пароль</button>
                    <a class="btn-link" href="/manage/users.php">Отмена</a>
                </div>
            </form>
        <?php else: ?>
            <form method="post" class="form-grid">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="add">

                <div class="field">
                    <label for="username">Логин</label>
                    <input id="username" type="text" name="username" required minlength="3" maxlength="64" autocomplete="username">
                </div>

                <div class="field">
                    <label for="full_name">ФИО</label>
                    <input id="full_name" type="text" name="full_name" required maxlength="120" autocomplete="name">
                </div>

                <div class="field">
                    <label for="password">Пароль</label>
                    <input id="password" type="password" name="password" required minlength="12" maxlength="4096" autocomplete="new-password">
                </div>

                <div class="field">
                    <label for="password_confirm">Повтор пароля</label>
                    <input id="password_confirm" type="password" name="password_confirm" required minlength="12" maxlength="4096" autocomplete="new-password">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Создать пользователя</button>
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
                        <th>Логин</th>
                        <th>Создан</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$users): ?>
                    <tr>
                        <td colspan="5">Пользователей пока нет</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= (int)$user['id'] ?></td>
                            <td><?= htmlspecialchars($user['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$user['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td>
                                <div class="action-group">
                                    <form method="get" action="/manage/users.php" class="inline">
                                        <input type="hidden" name="edit_id" value="<?= (int)$user['id'] ?>">
                                        <button type="submit" class="btn-sm">Редактировать</button>
                                    </form>

                                    <?php if ((int)$user['id'] !== $currentUserId): ?>
                                        <form method="post" class="inline" onsubmit="return confirm('Удалить пользователя?');">
                                            <?= csrfInput() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                                            <button type="submit" class="btn-danger btn-sm">Удалить</button>
                                        </form>
                                    <?php else: ?>
                                        <div class="user-self">
                                            <span class="badge-current">Текущий пользователь</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($editUser): ?>
        <div class="modal-backdrop" id="password-modal" <?= $openPasswordModal ? '' : 'hidden' ?> aria-hidden="<?= $openPasswordModal ? 'false' : 'true' ?>">
            <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="password-modal-title">
                <div class="modal-head">
                    <h2 class="modal-title" id="password-modal-title">Смена пароля</h2>
                    <button type="button" class="modal-close" data-password-modal-close aria-label="Закрыть"></button>
                </div>
                <form method="post" id="password-change-form">
                    <?= csrfInput() ?>
                    <input type="hidden" name="action" value="update_password">
                    <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">

                    <div class="modal-body">
                        <div class="subtitle" style="margin:0 0 14px;">
                            <?= htmlspecialchars($editUser['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= htmlspecialchars($editUser['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </div>

                        <?php if ($passwordModalError !== ''): ?>
                            <div class="password-modal-error"><?= htmlspecialchars($passwordModalError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <?php endif; ?>

                        <div class="field">
                            <label for="modal_password">Новый пароль</label>
                            <input id="modal_password" type="password" name="password" required minlength="12" maxlength="4096" autocomplete="new-password">
                        </div>

                        <div class="field" style="margin-top:14px;">
                            <label for="modal_password_confirm">Повторите пароль</label>
                            <input id="modal_password_confirm" type="password" name="password_confirm" required minlength="12" maxlength="4096" autocomplete="new-password">
                        </div>
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn" data-password-modal-close>Отмена</button>
                        <button type="submit" class="btn btn-primary">Сохранить пароль</button>
                    </div>
                </form>
            </div>
        </div>
        <script>
        (() => {
            const modal = document.getElementById('password-modal');
            const openButton = document.getElementById('open-password-modal');
            if (!modal || !openButton) {
                return;
            }

            const password = document.getElementById('modal_password');

            const open = () => {
                modal.hidden = false;
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('modal-open');
                if (password) {
                    password.focus();
                }
            };

            const close = () => {
                modal.hidden = true;
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('modal-open');
                openButton.focus();
            };

            openButton.addEventListener('click', open);
            modal.addEventListener('click', (event) => {
                if (event.target === modal || event.target.closest('[data-password-modal-close]')) {
                    close();
                }
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !modal.hidden) {
                    close();
                }
            });

            if (!modal.hidden) {
                document.body.classList.add('modal-open');
                if (password) {
                    password.focus();
                }
            }
        })();
        </script>
    <?php endif; ?>

    <?php require_once dirname(__DIR__, 2) . '/inc/manage_footer.php'; ?>
</div>
</body>
</html>
