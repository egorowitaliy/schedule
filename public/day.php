<?php
require_once dirname(__DIR__) . '/inc/db.php';
require_once dirname(__DIR__) . '/inc/helpers.php';

$date = isset($_GET['date']) && is_string($_GET['date']) ? trim($_GET['date']) : '';
$selectedGroupId = isset($_GET['group_id']) && is_scalar($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
$settings = loadSettings($pdo);
[$startDate, $endDate] = publicDateRange($settings);

if (!isValidIsoDate($date)) {
    http_response_code(404);
    die('Расписание не найдено или не опубликовано');
}

$stmt = $pdo->prepare('
    SELECT *
    FROM schedule_days
    WHERE study_date = ?
      AND is_published = 1
      AND study_date >= ?
      AND study_date <= ?
');
$stmt->execute([$date, $startDate, $endDate]);
$day = $stmt->fetch();

if (!$day) {
    http_response_code(404);
    exit('Расписание не найдено или не опубликовано');
}

$allGroups = $pdo->query('
    SELECT *
    FROM groups_list
    WHERE active = 1
    ORDER BY sort_order, name
')->fetchAll();

$selectedGroup = null;
foreach ($allGroups as $group) {
    if ((int)$group['id'] === $selectedGroupId) {
        $selectedGroup = $group;
        break;
    }
}

if ($selectedGroupId > 0 && $selectedGroup === null) {
    $selectedGroupId = 0;
}

$weekday = (int)date('N', strtotime($day['study_date']));
$weekdayMask = 1 << ($weekday - 1);

$stmt = $pdo->prepare('
    SELECT *
    FROM lesson_times
    WHERE (weekdays_mask & ?) != 0
    ORDER BY lesson_number ASC
');
$stmt->execute([$weekdayMask]);
$times = $stmt->fetchAll();

$stmt = $pdo->prepare('
    SELECT e.*, s.name AS subject, t.full_name AS teacher, r.name AS room
    FROM schedule_entries e
    LEFT JOIN subjects s ON s.id = e.subject_id
    LEFT JOIN teachers t ON t.id = e.teacher_id
    LEFT JOIN rooms r ON r.id = e.room_id
    WHERE e.schedule_day_id = ?
');
$stmt->execute([$day['id']]);
$data = $stmt->fetchAll();

$map = [];
foreach ($data as $row) {
    $map[(int)$row['group_id']][(int)$row['lesson_time_id']] = $row;
}

$desktopGroups = $allGroups;
if ($selectedGroupId > 0) {
    $desktopGroups = array_values(array_filter($allGroups, function ($g) use ($selectedGroupId) {
        return (int)$g['id'] === $selectedGroupId;
    }));
}
$mobileGroups = $selectedGroup !== null ? [$selectedGroup] : [];

$groupChunks = array_chunk($desktopGroups, 3);

$stmt = $pdo->prepare('
    SELECT study_date
    FROM schedule_days
    WHERE is_published = 1
      AND study_date >= ?
      AND study_date <= ?
    ORDER BY study_date ASC
');
$stmt->execute([$startDate, $endDate]);
$availableDays = $stmt->fetchAll();

function renderLessonContent(?array $entry): string
{
    ob_start();
    ?>
    <?php if ($entry): ?>
        <?php if ((int)$entry['is_cancelled'] === 1): ?>
            <div class="lesson-status lesson-status--cancelled">Отмена</div>

            <?php if (!empty($entry['note'])): ?>
                <div class="lesson-note">
                    <?= nl2br(htmlspecialchars($entry['note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <?php if (!empty($entry['subject'])): ?>
                <div class="lesson-subject"><?= htmlspecialchars($entry['subject'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if (!empty($entry['teacher'])): ?>
                <div class="lesson-line"><?= htmlspecialchars($entry['teacher'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if (!empty($entry['room'])): ?>
                <div class="lesson-line">Кабинет: <?= htmlspecialchars($entry['room'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if (!empty($entry['lesson_type'])): ?>
                <div class="lesson-meta"><?= htmlspecialchars($entry['lesson_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if (!empty($entry['note'])): ?>
                <div class="lesson-note">
                    <?= nl2br(htmlspecialchars($entry['note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
                </div>
            <?php endif; ?>

            <?php if ((int)$entry['is_distance'] === 1): ?>
                <div class="lesson-status">ДОТ</div>
            <?php endif; ?>
        <?php endif; ?>
    <?php else: ?>
        <div class="lesson-empty">—</div>
    <?php endif; ?>
    <?php
    return trim(ob_get_clean());
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars((string)$settings['site_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> — <?= htmlspecialchars(formatDateRu($day['study_date']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/public.css">
    <link rel="icon" type="image/svg+xml" href="/assets/icon.svg">
    <script src="/assets/public.js" defer></script>
</head>
<body>
    <div class="layout">
        <div class="container">
            <header class="page-head page-head--with-actions">
                <div class="page-head__title-wrap">
                    <h1 class="page-title">
                        Расписание на <?= htmlspecialchars(formatDateRu($day['study_date']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </h1>
                    <div class="page-subtitle">
                        <?php if ($selectedGroup): ?>
                            <?= htmlspecialchars($selectedGroup['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        <?php else: ?>
                            Все группы
                        <?php endif; ?>
                    </div>
                </div>

                <div class="page-head__actions">
                    <a href="/" class="btn btn--secondary">На главную</a>
                </div>
            </header>

            <section class="toolbar-block">
                <form method="get" id="dayForm" class="toolbar" data-schedule-filter>
                    <div class="control">
                        <label for="date">Дата</label>
                        <select id="date" name="date" required>
                            <?php foreach ($availableDays as $availableDay): ?>
                                <option value="<?= htmlspecialchars($availableDay['study_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $day['study_date'] === $availableDay['study_date'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(formatDateRu($availableDay['study_date']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="control">
                        <label for="group_id">Группа</label>
                        <select id="group_id" name="group_id" required data-group-select>
                            <option value="0" data-all-groups <?= $selectedGroupId === 0 ? 'selected' : '' ?>>Все группы</option>

                            <?php foreach ($allGroups as $g): ?>
                                <option value="<?= (int)$g['id'] ?>" <?= $selectedGroupId === (int)$g['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($g['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="toolbar__actions">
                        <button type="submit" class="btn btn--primary">Показать</button>
                    </div>
                </form>
            </section>

            <section class="mobile-only-block">
                <?php if ($selectedGroup === null): ?>
                    <div class="notice">Выберите группу, чтобы посмотреть расписание.</div>
                <?php elseif (!$mobileGroups): ?>
                    <div class="notice">Нет групп для отображения.</div>
                <?php else: ?>
                    <?php foreach ($mobileGroups as $mobileGroup): ?>
                        <section class="mobile-group">
                            <?php if ($selectedGroup === null): ?>
                                <h2 class="mobile-group__title"><?= htmlspecialchars($mobileGroup['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
                            <?php endif; ?>
                            <div class="mobile-schedule">
                                <?php foreach ($times as $time): ?>
                                    <?php $entry = $map[(int)$mobileGroup['id']][(int)$time['id']] ?? null; ?>
                                    <article class="mobile-row">
                                        <div class="mobile-row__head">
                                            <div class="mobile-row__pair"><?= (int)$time['lesson_number'] ?> пара</div>
                                            <div class="mobile-row__time">
                                                <?= htmlspecialchars(substr($time['time_start'], 0, 5), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                —
                                                <?= htmlspecialchars(substr($time['time_end'], 0, 5), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                            </div>
                                        </div>
                                        <div class="mobile-row__body">
                                            <?= renderLessonContent($entry) ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <section class="desktop-only-block">
                <?php if (!$desktopGroups): ?>
                    <div class="notice">Нет групп для отображения.</div>
                <?php else: ?>
                    <?php foreach ($groupChunks as $chunk): ?>
                        <section class="table-wrap">
                            <div class="table-scroll">
                                <table class="schedule-table">
                                    <thead>
                                        <tr>
                                            <th class="schedule-table__time-col">Пара</th>
                                            <?php foreach ($chunk as $group): ?>
                                                <th><?= htmlspecialchars($group['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($times as $time): ?>
                                            <tr>
                                                <td class="schedule-table__time-col">
                                                    <div class="pair-title"><?= (int)$time['lesson_number'] ?> пара</div>
                                                    <div class="pair-time">
                                                        <?= htmlspecialchars(substr($time['time_start'], 0, 5), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                        —
                                                        <?= htmlspecialchars(substr($time['time_end'], 0, 5), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                    </div>
                                                </td>

                                                <?php foreach ($chunk as $group): ?>
                                                    <?php $entry = $map[(int)$group['id']][(int)$time['id']] ?? null; ?>
                                                    <td>
                                                        <?= renderLessonContent($entry) ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </div>
    </div>
</body>
</html>
