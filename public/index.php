<?php
require_once dirname(__DIR__) . '/inc/db.php';
require_once dirname(__DIR__) . '/inc/helpers.php';

$settings = loadSettings($pdo);

[$startDate, $endDate] = publicDateRange($settings);

$stmt = $pdo->prepare('
    SELECT *
    FROM schedule_days
    WHERE is_published = 1
      AND study_date >= ?
      AND study_date <= ?
    ORDER BY study_date ASC
');
$stmt->execute([$startDate, $endDate]);
$days = $stmt->fetchAll();

$groups = $pdo->query('
    SELECT *
    FROM groups_list
    WHERE active = 1
    ORDER BY sort_order ASC, id ASC
')->fetchAll();

$date = isset($_GET['date']) && is_string($_GET['date'])
    ? trim($_GET['date'])
    : ($days[0]['study_date'] ?? '');
$groupId = isset($_GET['group_id']) && is_scalar($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars((string)$settings['site_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/public.css">
    <link rel="icon" type="image/svg+xml" href="/assets/icon.svg">
    <script src="/assets/public.js" defer></script>
</head>
<body>
    <div class="layout">
        <div class="container container--narrow">
            <header class="page-head page-head--single">
                <div class="page-head__title-wrap">
                    <h1 class="page-title"><?= htmlspecialchars((string)$settings['public_title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
                    <div class="page-subtitle"><?= htmlspecialchars((string)$settings['public_subtitle'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>
            </header>

            <section class="toolbar-block">
                <?php if (!$days): ?>
                    <div class="notice notice--error">Нет опубликованных дней</div>
                <?php elseif (!$groups): ?>
                    <div class="notice notice--error">Нет доступных групп</div>
                <?php else: ?>
                    <form method="get" action="/day.php" id="scheduleForm" class="toolbar toolbar--compact" data-schedule-filter>
                        <div class="control">
                            <label for="date">Дата</label>
                            <select id="date" name="date" required>
                                <?php foreach ($days as $d): ?>
                                    <option value="<?= htmlspecialchars($d['study_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $date === $d['study_date'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(formatDateRu($d['study_date']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="control">
                            <label for="group_id">Группа</label>
                            <select id="group_id" name="group_id" required data-group-select>
                                <option value="0" data-all-groups <?= $groupId === 0 ? 'selected' : '' ?>>Все группы</option>

                                <?php foreach ($groups as $g): ?>
                                    <option value="<?= (int)$g['id'] ?>" <?= $groupId === (int)$g['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($g['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="toolbar__actions">
                            <button type="submit" class="btn btn--primary">Показать</button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    </div>
</body>
</html>
