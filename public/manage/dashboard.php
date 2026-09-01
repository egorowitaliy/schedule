<?php
require_once dirname(__DIR__, 2) . '/inc/db.php';
require_once dirname(__DIR__, 2) . '/inc/auth.php';
require_once dirname(__DIR__, 2) . '/inc/helpers.php';
requireAuth();
$settings = loadSettings($pdo);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Панель управления — <?= htmlspecialchars((string)$settings['site_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" type="image/svg+xml" href="/assets/icon.svg">
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <h1 class="page-title">Панель управления</h1>
            <div class="subtitle">Расписание, справочники и системные параметры</div>
        </div>

        <div class="topbar-links">
            <a href="/">Публичная страница</a>
            <?= logoutForm() ?>
        </div>
    </div>

    <div class="menu-grid">
        <a class="menu-card" href="/manage/schedule_days.php">
            <div class="menu-card-title">Дни расписания</div>
            <div class="menu-card-text">Создание, копирование, публикация и переход к редактированию дней</div>
        </a>

        <a class="menu-card" href="/manage/groups.php">
            <div class="menu-card-title">Группы</div>
            <div class="menu-card-text">Список учебных групп и порядок вывода</div>
        </a>

        <a class="menu-card" href="/manage/lesson_times.php">
            <div class="menu-card-title">Пары и время</div>
            <div class="menu-card-text">Сетка занятий, время начала и окончания по дням недели</div>
        </a>

        <a class="menu-card" href="/manage/subjects.php">
            <div class="menu-card-title">Дисциплины</div>
            <div class="menu-card-text">Предметы и учебные дисциплины</div>
        </a>

        <a class="menu-card" href="/manage/teachers.php">
            <div class="menu-card-title">Преподаватели</div>
            <div class="menu-card-text">Добавление и редактирование преподавателей</div>
        </a>

        <a class="menu-card" href="/manage/rooms.php">
            <div class="menu-card-title">Аудитории</div>
            <div class="menu-card-text">Кабинеты, лаборатории и другие помещения</div>
        </a>

        <a class="menu-card" href="/manage/users.php">
            <div class="menu-card-title">Пользователи</div>
            <div class="menu-card-text">Учётные записи, пароли и доступ в панель управления</div>
        </a>

        <a class="menu-card" href="/manage/settings.php">
            <div class="menu-card-title">Настройки</div>
            <div class="menu-card-text">Публичный диапазон, история в панели управления и срок хранения</div>
        </a>
    </div>

    <?php require_once dirname(__DIR__, 2) . '/inc/manage_footer.php'; ?>
</div>
</body>
</html>
