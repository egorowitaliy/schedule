<?php
require_once dirname(__DIR__, 2) . '/inc/db.php';
require_once dirname(__DIR__, 2) . '/inc/auth.php';
requireAuth();

$error = '';
$success = '';
$editGroup = null;
$flash = pullFlashMessage();
if ($flash !== null) {
    ${$flash['type']} = $flash['message'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfOrDie();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim(postStringInput('name'));
        $sortOrderValue = postIntInput('sort_order');

        if (postIntInputIsMalformed($sortOrderValue)) {
            $error = 'Некорректный порядок сортировки';
        } elseif ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            $error = 'Название группы должно содержать от 1 до 100 символов';
        } else {
            try {
                $stmt = $pdo->prepare('
                    INSERT INTO groups_list (name, sort_order)
                    SELECT ?, CASE WHEN ? <= 0 THEN COALESCE(MAX(sort_order), 0) + 1 ELSE ? END
                    FROM groups_list
                ');
                $stmt->execute([$name, $sortOrderValue, $sortOrderValue]);
                setFlashMessage('success', 'Группа добавлена');
                redirectSeeOther('/manage/groups.php');
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    $error = 'Группа "' . $name . '" уже существует';
                } else {
                    $error = 'Не удалось добавить группу';
                }
            }
        }
    }

    if ($action === 'update') {
        $id = postIntInput('id');
        $name = trim(postStringInput('name'));
        $sortOrder = postIntInput('sort_order');

        if ($id <= 0 || $sortOrder < 0) {
            $error = 'Некорректные параметры группы';
        } elseif ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            $error = 'Название группы должно содержать от 1 до 100 символов';
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE groups_list SET name = ?, sort_order = ? WHERE id = ?');
                $stmt->execute([$name, $sortOrder, $id]);
                $success = $stmt->rowCount() === 1 ? 'Группа обновлена' : '';
                if ($success === '') {
                    $error = 'Группа не найдена';
                } else {
                    setFlashMessage('success', $success);
                    redirectSeeOther('/manage/groups.php');
                }
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    $error = 'Группа "' . $name . '" уже существует';
                } else {
                    $error = 'Не удалось обновить группу';
                }
            }
        }
    }

    if ($action === 'toggle') {
        $id = postIntInput('id');

        if ($id <= 0) {
            $error = 'Некорректный ID группы';
        } else try {
            $stmt = $pdo->prepare('UPDATE groups_list SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE id = ?');
            $stmt->execute([$id]);
            $success = $stmt->rowCount() === 1 ? 'Статус группы изменён' : '';
            if ($success === '') {
                $error = 'Группа не найдена';
            } else {
                setFlashMessage('success', $success);
                redirectSeeOther('/manage/groups.php');
            }
        } catch (PDOException $e) {
            $error = 'Не удалось изменить статус группы';
        }
    }

    if ($action === 'delete') {
        $id = postIntInput('id');

        if ($id <= 0) {
            $error = 'Некорректный ID группы';
        } else try {
            $stmt = $pdo->prepare('DELETE FROM groups_list WHERE id = ?');
            $stmt->execute([$id]);
            $success = $stmt->rowCount() === 1 ? 'Группа удалена' : '';
            if ($success === '') {
                $error = 'Группа не найдена';
            } else {
                setFlashMessage('success', $success);
                redirectSeeOther('/manage/groups.php');
            }
        } catch (PDOException $e) {
            $error = 'Не удалось удалить группу';
        }
    }

    if ($action === 'normalize_sort_alpha') {
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $stmt = $pdo->query('SELECT id, name FROM groups_list');
            $allGroups = $stmt->fetchAll();

            usort($allGroups, function ($a, $b) {
                $aName = trim((string)$a['name']);
                $bName = trim((string)$b['name']);

                $aMatched = preg_match('/^([^\d]+?)-?(\d+)$/u', $aName, $aParts);
                $bMatched = preg_match('/^([^\d]+?)-?(\d+)$/u', $bName, $bParts);

                if ($aMatched && $bMatched) {
                    $aPrefix = mb_strtolower(trim($aParts[1]), 'UTF-8');
                    $bPrefix = mb_strtolower(trim($bParts[1]), 'UTF-8');

                    $prefixCompare = strcmp($aPrefix, $bPrefix);
                    if ($prefixCompare !== 0) {
                        return $prefixCompare;
                    }

                    return ((int)$aParts[2]) <=> ((int)$bParts[2]);
                }

                return strcasecmp($aName, $bName);
            });

            $stmtUpdate = $pdo->prepare('UPDATE groups_list SET sort_order = ? WHERE id = ?');

            foreach ($allGroups as $index => $group) {
                $stmtUpdate->execute([$index + 1, (int)$group['id']]);
            }

            $pdo->exec('COMMIT');
            setFlashMessage('success', 'Сортировка групп перестроена по алфавиту');
            redirectSeeOther('/manage/groups.php');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $rollbackError) {
                // Исходная ошибка важнее ошибки отката.
            }
            $error = 'Не удалось перестроить сортировку';
        }
    }
}

$editId = queryIntInput('edit_id');
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM groups_list WHERE id = ?');
    $stmt->execute([$editId]);
    $editGroup = $stmt->fetch();
}

$stmt = $pdo->query('SELECT * FROM groups_list ORDER BY sort_order ASC, id ASC');
$groups = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Группы</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" type="image/svg+xml" href="/assets/icon.svg">
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <h1 class="page-title">Группы</h1>
            <div class="subtitle">Справочник учебных групп и порядок их вывода в расписании</div>
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
        <?php if ($editGroup): ?>
            <form method="post" class="groups-toolbar-form">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$editGroup['id'] ?>">

                <div class="groups-toolbar">
                    <div class="field group-name-field">
                        <label for="name">Название группы</label>
                        <input id="name" type="text" name="name" required maxlength="100" value="<?= htmlspecialchars($editGroup['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>

                    <div class="field group-sort-field">
                        <label for="sort_order">Сортировка</label>
                        <input id="sort_order" type="number" name="sort_order" min="0" value="<?= (int)$editGroup['sort_order'] ?>">
                    </div>

                    <div class="groups-toolbar-actions">
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                        <a class="btn-link" href="/manage/groups.php">Отмена</a>
                    </div>
                </div>
            </form>
        <?php else: ?>
            <form method="post" class="groups-toolbar-form">
                <?= csrfInput() ?>

                <div class="groups-toolbar">
                    <div class="field group-name-field">
                        <label for="name">Название группы</label>
                        <input id="name" type="text" name="name" required maxlength="100">
                    </div>

                    <div class="field group-sort-field">
                        <label for="sort_order">Сортировка (0 — авто)</label>
                        <input id="sort_order" type="number" name="sort_order" value="0" min="0">
                    </div>

                    <div class="groups-toolbar-actions">
                        <button type="submit" class="btn btn-primary" name="action" value="add">Добавить группу</button>
                        <button type="submit" class="btn" name="action" value="normalize_sort_alpha" formnovalidate>Сортировать по алфавиту</button>
                    </div>
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
                        <th>Сортировка</th>
                        <th>Активна</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$groups): ?>
                    <tr>
                        <td colspan="5">Групп пока нет</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($groups as $group): ?>
                        <tr class="<?= (int)$group['active'] === 1 ? '' : 'row-inactive' ?>">
                            <td><?= (int)$group['id'] ?></td>
                            <td><?= htmlspecialchars($group['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= (int)$group['sort_order'] ?></td>
                            <td><?= (int)$group['active'] === 1 ? 'Да' : 'Нет' ?></td>
                            <td>
                                <div class="action-group">
                                    <form method="get" action="/manage/groups.php" class="inline">
                                        <input type="hidden" name="edit_id" value="<?= (int)$group['id'] ?>">
                                        <button type="submit" class="btn-sm">Изменить</button>
                                    </form>

                                    <form method="post" class="inline">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$group['id'] ?>">
                                        <button type="submit" class="btn-sm">
                                            <?= (int)$group['active'] === 1 ? 'Выключить' : 'Включить' ?>
                                        </button>
                                    </form>

                                    <form method="post" class="inline" onsubmit="return confirm('Удалить группу?');">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$group['id'] ?>">
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
