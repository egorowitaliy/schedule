<?php
require_once dirname(__DIR__, 2) . '/inc/db.php';
require_once dirname(__DIR__, 2) . '/inc/auth.php';
require_once dirname(__DIR__, 2) . '/inc/helpers.php';
requireAuth();

$error = '';
$success = '';
$flash = pullFlashMessage();
if ($flash !== null) {
    ${$flash['type']} = $flash['message'];
}

$settings = loadSettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfOrDie();
    $siteName = trim(postStringInput('site_name'));
    $publicTitle = trim(postStringInput('public_title'));
    $publicSubtitle = trim(postStringInput('public_subtitle'));
    $publicDaysForward = postIntInput('public_days_forward');
    $publicShowToday = postStringInput('public_show_today') === '1' ? 1 : 0;
    $adminDaysBack = postIntInput('admin_days_back');
    $deleteDaysAfter = postIntInput('delete_days_after');

    if ($siteName === '' || mb_strlen($siteName, 'UTF-8') > 120) {
        $error = 'Название сайта должно содержать от 1 до 120 символов';
    } elseif ($publicTitle === '' || mb_strlen($publicTitle, 'UTF-8') > 150) {
        $error = 'Заголовок публичной страницы должен содержать от 1 до 150 символов';
    } elseif ($publicSubtitle === '' || mb_strlen($publicSubtitle, 'UTF-8') > 180) {
        $error = 'Подзаголовок публичной страницы должен содержать от 1 до 180 символов';
    } elseif (postIntInputIsMalformed($publicDaysForward)
        || postIntInputIsMissing($publicDaysForward)
        || postIntInputIsMalformed($adminDaysBack)
        || postIntInputIsMissing($adminDaysBack)
        || postIntInputIsMalformed($deleteDaysAfter)
        || postIntInputIsMissing($deleteDaysAfter)) {
        $error = 'Некорректное числовое значение. Настройки не изменены';
    } elseif ($publicDaysForward < 0 || $publicDaysForward > 60) {
        $error = 'Диапазон публичного показа должен быть от 0 до 60 дней';
    } elseif ($adminDaysBack < 0 || $adminDaysBack > 365) {
        $error = 'Диапазон истории в панели управления должен быть от 0 до 365 дней';
    } elseif ($deleteDaysAfter < 1 || $deleteDaysAfter > 3650) {
        $error = 'Срок удаления должен быть от 1 до 3650 дней';
    } elseif ($deleteDaysAfter < $adminDaysBack) {
        $error = 'Срок удаления из базы не может быть меньше истории, показываемой в панели управления';
    } else {
        try {
            saveSettings($pdo, [
                'site_name'           => $siteName,
                'public_title'        => $publicTitle,
                'public_subtitle'     => $publicSubtitle,
                'public_days_forward' => $publicDaysForward,
                'public_show_today'   => $publicShowToday,
                'admin_days_back'     => $adminDaysBack,
                'delete_days_after'   => $deleteDaysAfter,
            ]);

            setFlashMessage('success', 'Настройки сохранены');
            redirectSeeOther('/manage/settings.php');
        } catch (PDOException $e) {
            $error = 'Не удалось сохранить настройки';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Настройки</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" type="image/svg+xml" href="/assets/icon.svg">
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <h1 class="page-title">Настройки</h1>
            <div class="subtitle">Название, публичное отображение и хранение расписания</div>
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

    <div class="card settings-card">
        <form method="post" class="settings-form">
            <?= csrfInput() ?>

            <div class="settings-row">
                <div class="settings-label">
                    <label for="site_name">Название сайта</label>
                    <div class="settings-help">Отображается на странице входа и в заголовках браузера.</div>
                </div>
                <div class="settings-control settings-control--text">
                    <input id="site_name" type="text" name="site_name" maxlength="120" required value="<?= htmlspecialchars((string)$settings['site_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>
            </div>

            <div class="settings-row">
                <div class="settings-label">
                    <label for="public_title">Заголовок публичной страницы</label>
                    <div class="settings-help">Основной заголовок над выбором даты и группы.</div>
                </div>
                <div class="settings-control settings-control--text">
                    <input id="public_title" type="text" name="public_title" maxlength="150" required value="<?= htmlspecialchars((string)$settings['public_title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>
            </div>

            <div class="settings-row">
                <div class="settings-label">
                    <label for="public_subtitle">Подзаголовок публичной страницы</label>
                    <div class="settings-help">Строка под заголовком, например название образовательной организации.</div>
                </div>
                <div class="settings-control settings-control--text">
                    <input id="public_subtitle" type="text" name="public_subtitle" maxlength="180" required value="<?= htmlspecialchars((string)$settings['public_subtitle'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>
            </div>

            <div class="settings-row">
                <div class="settings-label">
                    <label for="public_days_forward">Показывать в публичной части вперёд</label>
                    <div class="settings-help">Сколько дней вперёд показывать в открытой части сайта.</div>
                </div>
                <div class="settings-control">
                    <input
                        id="public_days_forward"
                        class="number-input"
                        type="number"
                        name="public_days_forward"
                        min="0"
                        max="60"
                        value="<?= (int)$settings['public_days_forward'] ?>"
                    >
                    <span class="settings-suffix">дней</span>
                </div>
            </div>

            <div class="settings-row">
                <div class="settings-label">
                    <label for="public_show_today">Показывать сегодняшний день</label>
                    <div class="settings-help">Если выключено, публичная страница начнётся с завтрашнего дня.</div>
                </div>
                <div class="settings-control settings-control--checkbox">
                    <label class="toggle-check" for="public_show_today">
                        <input
                            id="public_show_today"
                            type="checkbox"
                            name="public_show_today"
                            value="1"
                            <?= !empty($settings['public_show_today']) ? 'checked' : '' ?>
                        >
                        <span>Включено</span>
                    </label>
                </div>
            </div>

            <div class="settings-row">
                <div class="settings-label">
                    <label for="admin_days_back">Показывать историю в панели управления</label>
                    <div class="settings-help">Сколько дней назад оставлять видимыми в панели управления.</div>
                </div>
                <div class="settings-control">
                    <input
                        id="admin_days_back"
                        class="number-input"
                        type="number"
                        name="admin_days_back"
                        min="0"
                        max="365"
                        value="<?= (int)$settings['admin_days_back'] ?>"
                    >
                    <span class="settings-suffix">дней</span>
                </div>
            </div>

            <div class="settings-row">
                <div class="settings-label">
                    <label for="delete_days_after">Удалять из базы старше</label>
                    <div class="settings-help">Через сколько дней старые записи можно автоматически удалять.</div>
                </div>
                <div class="settings-control">
                    <input
                        id="delete_days_after"
                        class="number-input"
                        type="number"
                        name="delete_days_after"
                        min="1"
                        max="3650"
                        value="<?= (int)$settings['delete_days_after'] ?>"
                    >
                    <span class="settings-suffix">дней</span>
                </div>
            </div>

            <div class="settings-actions">
                <button type="submit" class="btn btn-primary">Сохранить настройки</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="settings-note">
            <strong>Рекомендуемый вариант</strong>
            Публичная часть — 7 дней вперёд · Панель управления — 30 дней назад · Удаление — 90 дней
        </div>
    </div>

    <?php require_once dirname(__DIR__, 2) . '/inc/manage_footer.php'; ?>
</div>
</body>
</html>
