<?php
require_once dirname(__DIR__, 2) . '/inc/db.php';
require_once dirname(__DIR__, 2) . '/inc/auth.php';
require_once dirname(__DIR__, 2) . '/inc/helpers.php';
requireAuth();

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Метод не поддерживается');
}

$error = '';
$success = '';
$flash = pullFlashMessage();
if ($flash !== null) {
    ${$flash['type']} = $flash['message'];
}

$dayId = queryIntInput('day_id');
if ($dayId <= 0) {
    http_response_code(400);
    exit('Не указан day_id');
}

$pageToken = bin2hex(random_bytes(32));
$editorInstanceToken = '';
$documentToken = bin2hex(random_bytes(32));

if ($requestMethod === 'POST') {
    validateCsrfOrDie();
    $editorInstanceToken = strtolower(trim(postStringInput('editor_instance_token')));
    $documentToken = strtolower(trim(postStringInput('document_token')));
    if (!preg_match('/^[0-9a-f]{64}$/', $editorInstanceToken)
        || !preg_match('/^[0-9a-f]{64}$/', $documentToken)) {
        http_response_code(400);
        exit('Некорректные данные экземпляра редактора');
    }
}

$stmt = $pdo->prepare('SELECT * FROM schedule_days WHERE id = ?');
$stmt->execute([$dayId]);
$day = $stmt->fetch();

if (!$day) {
    http_response_code(404);
    exit('День расписания не найден');
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

$lockOwned = false;
if ($requestMethod === 'POST') {
    $lockOwned = touchScheduleDayLock(
        $pdo,
        $dayId,
        $currentUserId,
        $editorInstanceToken,
        $documentToken
    );
    if (!$lockOwned) {
        http_response_code(409);
        $error = 'Блокировка редактора потеряна. Данные не изменены; откройте день заново.';
    }
}

$groups = $pdo->query('
    SELECT *
    FROM groups_list
    WHERE active = 1
    ORDER BY sort_order ASC, id ASC
')->fetchAll();

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

$gridPayload = [
    'groups' => array_map(static fn(array $row): int => (int)$row['id'], $groups),
    'times' => array_map(static fn(array $row): int => (int)$row['id'], $times),
];
$gridJson = json_encode($gridPayload, JSON_UNESCAPED_SLASHES);
if (!is_string($gridJson)) {
    throw new RuntimeException('Не удалось подготовить структуру редактора');
}
$gridSignature = hash('sha256', $gridJson);
$maxInputVars = max(0, (int)ini_get('max_input_vars'));
$estimatedInputVars = count($groups) * count($times) * 7 + 16;
$inputVarsWarning = $maxInputVars > 0 && $estimatedInputVars > $maxInputVars;

$stmt = $pdo->prepare('
    SELECT *
    FROM subjects
    WHERE active = 1
       OR id IN (
           SELECT subject_id FROM schedule_entries
           WHERE schedule_day_id = ? AND subject_id IS NOT NULL
       )
    ORDER BY name ASC
');
$stmt->execute([$dayId]);
$subjects = $stmt->fetchAll();

$stmt = $pdo->prepare('
    SELECT *
    FROM teachers
    WHERE active = 1
       OR id IN (
           SELECT teacher_id FROM schedule_entries
           WHERE schedule_day_id = ? AND teacher_id IS NOT NULL
       )
    ORDER BY full_name ASC
');
$stmt->execute([$dayId]);
$teachers = $stmt->fetchAll();

$stmt = $pdo->prepare('
    SELECT *
    FROM rooms
    WHERE active = 1
       OR id IN (
           SELECT room_id FROM schedule_entries
           WHERE schedule_day_id = ? AND room_id IS NOT NULL
       )
    ORDER BY name ASC
');
$stmt->execute([$dayId]);
$rooms = $stmt->fetchAll();

if ($lockOwned && $requestMethod === 'POST') {
    if (postStringInput('form_complete') !== '1') {
        http_response_code(400);
        $error = 'Форма получена не полностью. Данные не изменены; увеличьте max_input_vars в PHP.';
    }

    $postedGridSignature = strtolower(trim(postStringInput('grid_signature')));
    if ($error === ''
        && (!preg_match('/^[0-9a-f]{64}$/', $postedGridSignature)
            || !hash_equals($gridSignature, $postedGridSignature))) {
        http_response_code(409);
        $error = 'Состав групп или сетка пар изменились после открытия редактора. Данные не изменены; обновите страницу.';
    }

    $subjectInput = is_array($_POST['subject_id'] ?? null) ? $_POST['subject_id'] : [];
    $teacherInput = is_array($_POST['teacher_id'] ?? null) ? $_POST['teacher_id'] : [];
    $roomInput = is_array($_POST['room_id'] ?? null) ? $_POST['room_id'] : [];
    $lessonTypeInput = is_array($_POST['lesson_type'] ?? null) ? $_POST['lesson_type'] : [];
    $noteInput = is_array($_POST['note'] ?? null) ? $_POST['note'] : [];
    $distanceInput = is_array($_POST['is_distance'] ?? null) ? $_POST['is_distance'] : [];
    $cancelledInput = is_array($_POST['is_cancelled'] ?? null) ? $_POST['is_cancelled'] : [];

    if ($error === '' && $groups && $times) {
        foreach (['subject_id', 'teacher_id', 'room_id', 'lesson_type', 'note'] as $requiredArray) {
            if (!is_array($_POST[$requiredArray] ?? null)) {
                http_response_code(400);
                $error = 'Некорректная структура формы. Данные не изменены.';
                break;
            }
        }
    }
    if ($error === '') {
        foreach (['is_distance', 'is_cancelled'] as $optionalArray) {
            if (isset($_POST[$optionalArray]) && !is_array($_POST[$optionalArray])) {
                http_response_code(400);
                $error = 'Некорректная структура формы. Данные не изменены.';
                break;
            }
        }
    }

    if ($error === '') {
        try {
            $pdo->exec('BEGIN IMMEDIATE');

            foreach ($groups as $group) {
                foreach ($times as $time) {
                    $groupId = (int)$group['id'];
                    $timeId = (int)$time['id'];
                    $key = $groupId . '_' . $timeId;

                    $subjectId = formArrayOptionalId($subjectInput, $key);
                    $teacherId = formArrayOptionalId($teacherInput, $key);
                    $roomId = formArrayOptionalId($roomInput, $key);
                    $lessonType = trim(formArrayString($lessonTypeInput, $key));
                    $note = trim(formArrayString($noteInput, $key));
                    $isDistance = isset($distanceInput[$key]) ? 1 : 0;
                    $isCancelled = isset($cancelledInput[$key]) ? 1 : 0;

                    if (mb_strlen($lessonType, 'UTF-8') > 100 || mb_strlen($note, 'UTF-8') > 5000) {
                        throw new InvalidArgumentException('Слишком длинное значение в ячейке расписания');
                    }

                    $hasAnyData =
                        $subjectId !== null ||
                        $teacherId !== null ||
                        $roomId !== null ||
                        $lessonType !== '' ||
                        $note !== '' ||
                        $isDistance === 1 ||
                        $isCancelled === 1;

                    $stmt = $pdo->prepare('
                        SELECT id
                        FROM schedule_entries
                        WHERE schedule_day_id = ? AND group_id = ? AND lesson_time_id = ?
                    ');
                    $stmt->execute([$dayId, $groupId, $timeId]);
                    $existing = $stmt->fetch();

                    if ($existing && !$hasAnyData) {
                        $deleteStmt = $pdo->prepare('DELETE FROM schedule_entries WHERE id = ?');
                        $deleteStmt->execute([$existing['id']]);
                        continue;
                    }

                    if ($existing && $hasAnyData) {
                        $updateStmt = $pdo->prepare('
                            UPDATE schedule_entries
                            SET subject_id = ?, teacher_id = ?, room_id = ?, lesson_type = ?, note = ?, is_distance = ?, is_cancelled = ?, updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ');
                        $updateStmt->execute([
                            $subjectId,
                            $teacherId,
                            $roomId,
                            $lessonType !== '' ? $lessonType : null,
                            $note !== '' ? $note : null,
                            $isDistance,
                            $isCancelled,
                            $existing['id']
                        ]);
                        continue;
                    }

                    if (!$existing && $hasAnyData) {
                        $insertStmt = $pdo->prepare('
                            INSERT INTO schedule_entries
                            (schedule_day_id, group_id, lesson_time_id, subject_id, teacher_id, room_id, lesson_type, note, is_distance, is_cancelled)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ');
                        $insertStmt->execute([
                            $dayId,
                            $groupId,
                            $timeId,
                            $subjectId,
                            $teacherId,
                            $roomId,
                            $lessonType !== '' ? $lessonType : null,
                            $note !== '' ? $note : null,
                            $isDistance,
                            $isCancelled
                        ]);
                    }
                }
            }

            $pdo->exec('COMMIT');
            try {
                releaseScheduleDayLock(
                    $pdo,
                    $dayId,
                    $currentUserId,
                    $editorInstanceToken,
                    $documentToken
                );
            } catch (Throwable $releaseError) {
                // Истёкшая блокировка не отменяет уже сохранённые данные.
            }
            setFlashMessage('success', 'Расписание сохранено');
            redirectSeeOther('/manage/schedule_edit.php?day_id=' . $dayId);
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $rollbackError) {
                // Исходная ошибка важнее ошибки отката.
            }
            $error = $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : 'Не удалось сохранить расписание';
        }
    }
}

$stmt = $pdo->prepare('SELECT * FROM schedule_entries WHERE schedule_day_id = ?');
$stmt->execute([$dayId]);
$entriesRaw = $stmt->fetchAll();

$entries = [];
foreach ($entriesRaw as $entry) {
    $entries[$entry['group_id'] . '_' . $entry['lesson_time_id']] = $entry;
}

function selected($value, $current): string
{
    return ((string)$value === (string)$current) ? 'selected' : '';
}

function weekdayNameRu(int $weekday): string
{
    $map = [
        1 => 'понедельник',
        2 => 'вторник',
        3 => 'среда',
        4 => 'четверг',
        5 => 'пятница',
        6 => 'суббота',
        7 => 'воскресенье',
    ];

    return $map[$weekday] ?? 'неизвестный день';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Редактор расписания</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" type="image/svg+xml" href="/assets/icon.svg">
</head>
<body>
<div class="page page--wide">
    <div class="topbar">
        <div>
            <h1 class="page-title">Редактор расписания</h1>
            <div class="subtitle">
                <?= htmlspecialchars($day['study_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> ·
                <?= htmlspecialchars(weekdayNameRu($weekday), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
        </div>

        <div class="topbar-links">
            <a href="/manage/schedule_days.php">К дням</a>
            <a href="/manage/dashboard.php">В панель управления</a>
            <?= logoutForm() ?>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="notice notice--success"><?= htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="notice notice--error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($inputVarsWarning): ?>
        <div class="notice notice--error" data-inline-notice>
            Текущая таблица может превысить ограничение PHP max_input_vars
            (нужно примерно <?= (int)$estimatedInputVars ?>, настроено <?= (int)$maxInputVars ?>).
            Увеличьте max_input_vars перед редактированием большого расписания.
        </div>
    <?php endif; ?>

        <div id="editor-lock-status" class="notice">Подключение к редактору…</div>
        <noscript>
            <div class="notice notice--error" data-inline-notice>Для безопасной блокировки редактора требуется JavaScript.</div>
        </noscript>

        <form method="post" id="schedule-edit-form">
            <?= csrfInput() ?>
            <input type="hidden" name="editor_instance_token" id="editor-instance-token" value="">
            <input type="hidden" name="document_token" id="editor-document-token" value="<?= htmlspecialchars($documentToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="grid_signature" value="<?= htmlspecialchars($gridSignature, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <fieldset id="editor-fields" class="editor-fieldset" disabled>

            <div class="card">
                <div class="editor-actions-bar">
                    <div class="muted">Пары расположены сверху, группы слева. Показываются только пары этого дня недели.</div>
                    <div>
                        <button type="submit" class="btn btn-primary">Сохранить расписание</button>
                    </div>
                </div>
            </div>

            <div class="card card--tight">
                <div class="table-wrap">
                    <table class="table schedule-table">
                        <thead>
                            <tr>
                                <th class="group-col">Группа</th>
                                <?php foreach ($times as $time): ?>
                                    <th class="time-head">
                                        <strong><?= (int)$time['lesson_number'] ?> пара</strong><br>
                                        <span class="small">
                                            <?= htmlspecialchars(substr($time['time_start'], 0, 5), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                            —
                                            <?= htmlspecialchars(substr($time['time_end'], 0, 5), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        </span>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$groups): ?>
                            <tr>
                                <td colspan="<?= count($times) + 1 ?>">Нет активных групп</td>
                            </tr>
                        <?php elseif (!$times): ?>
                            <tr>
                                <td colspan="<?= count($times) + 1 ?>">Для этого дня недели не настроена сетка занятий</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($groups as $group): ?>
                                <tr>
                                    <td class="group-col">
                                        <strong><?= htmlspecialchars($group['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                    </td>

                                    <?php foreach ($times as $time): ?>
                                        <?php
                                        $key = $group['id'] . '_' . $time['id'];
                                        $entry = $entries[$key] ?? null;
                                        ?>
                                        <td>
                                            <div class="cell-box">
                                                <select name="subject_id[<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]">
                                                    <option value="">-- Дисциплина --</option>
                                                    <?php foreach ($subjects as $subject): ?>
                                                        <option value="<?= (int)$subject['id'] ?>" <?= selected($subject['id'], $entry['subject_id'] ?? '') ?>>
                                                            <?= htmlspecialchars($subject['name'] . ((int)$subject['active'] === 1 ? '' : ' (неактивно)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>

                                                <select name="teacher_id[<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]">
                                                    <option value="">-- Преподаватель --</option>
                                                    <?php foreach ($teachers as $teacher): ?>
                                                        <option value="<?= (int)$teacher['id'] ?>" <?= selected($teacher['id'], $entry['teacher_id'] ?? '') ?>>
                                                            <?= htmlspecialchars($teacher['full_name'] . ((int)$teacher['active'] === 1 ? '' : ' (неактивно)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>

                                                <select name="room_id[<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]">
                                                    <option value="">-- Аудитория --</option>
                                                    <?php foreach ($rooms as $room): ?>
                                                        <option value="<?= (int)$room['id'] ?>" <?= selected($room['id'], $entry['room_id'] ?? '') ?>>
                                                            <?= htmlspecialchars($room['name'] . ((int)$room['active'] === 1 ? '' : ' (неактивно)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>

                                                <input
                                                    type="text"
                                                    name="lesson_type[<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]"
                                                    placeholder="Тип занятия"
                                                    maxlength="100"
                                                    value="<?= htmlspecialchars($entry['lesson_type'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                                >

                                                <textarea
                                                    name="note[<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]"
                                                    placeholder="Примечание"
                                                    maxlength="5000"
                                                ><?= htmlspecialchars($entry['note'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>

                                                <div class="flags">
                                                    <label>
                                                        <input
                                                            type="checkbox"
                                                            name="is_distance[<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]"
                                                            <?= !empty($entry['is_distance']) ? 'checked' : '' ?>
                                                        >
                                                        ДОТ
                                                    </label>

                                                    <label>
                                                        <input
                                                            type="checkbox"
                                                            name="is_cancelled[<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]"
                                                            <?= !empty($entry['is_cancelled']) ? 'checked' : '' ?>
                                                        >
                                                        Отмена
                                                    </label>
                                                </div>
                                            </div>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div style="text-align:right;">
                    <button type="submit" class="btn btn-primary">Сохранить расписание</button>
                </div>
            </div>
            <input type="hidden" name="form_complete" value="1">
            </fieldset>
        </form>

<script>
const csrfToken = <?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const dayId = <?= (int)$dayId ?>;
const pageToken = <?= json_encode($pageToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const documentToken = <?= json_encode($documentToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const tokenPattern = /^[0-9a-f]{64}$/;
const storageKey = 'schedule.editor.instance.' + dayId;
const signalKey = 'schedule.editor.signal';
const form = document.getElementById('schedule-edit-form');
const fields = document.getElementById('editor-fields');
const statusBox = document.getElementById('editor-lock-status');
const instanceInput = document.getElementById('editor-instance-token');
const documentInput = document.getElementById('editor-document-token');
let formSubmitting = false;
let lockOwned = false;
let editorInstanceToken = '';
let channel = null;

function randomToken() {
    const bytes = new Uint8Array(32);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, function (byte) {
        return byte.toString(16).padStart(2, '0');
    }).join('');
}

function setStatus(message, type) {
    statusBox.textContent = message;
    statusBox.className = 'notice' + (type ? ' notice--' + type : '');
}

function sendSignal(message) {
    const payload = Object.assign({}, message, {nonce: randomToken()});
    if (channel) {
        channel.postMessage(payload);
    }
    try {
        localStorage.setItem(signalKey, JSON.stringify(payload));
        localStorage.removeItem(signalKey);
    } catch (error) {
        // BroadcastChannel остаётся основным способом связи между вкладками.
    }
}

function onSignal(message) {
    if (!message || message.dayId !== dayId || message.documentToken === documentToken) {
        return;
    }

    if (message.type === 'probe'
        && editorInstanceToken !== ''
        && message.editorInstanceToken === editorInstanceToken) {
        sendSignal({
            type: 'collision',
            dayId: dayId,
            editorInstanceToken: editorInstanceToken,
            documentToken: documentToken,
            requestToken: message.requestToken
        });
    }
}

if ('BroadcastChannel' in window) {
    channel = new BroadcastChannel('schedule-editor-instances');
    channel.addEventListener('message', function (event) {
        onSignal(event.data);
    });
}
window.addEventListener('storage', function (event) {
    if (event.key !== signalKey || !event.newValue) {
        return;
    }
    try {
        onSignal(JSON.parse(event.newValue));
    } catch (error) {
        // Некорректный локальный сигнал игнорируется.
    }
});

async function chooseEditorInstanceToken() {
    let tokenValue = '';
    try {
        tokenValue = sessionStorage.getItem(storageKey) || '';
    } catch (error) {
        tokenValue = '';
    }
    if (!tokenPattern.test(tokenValue)) {
        tokenValue = pageToken;
    }

    editorInstanceToken = tokenValue;
    const requestToken = randomToken();
    let collision = false;

    function collisionListener(event) {
        const message = event.detail;
        if (message
            && message.type === 'collision'
            && message.requestToken === requestToken
            && message.editorInstanceToken === tokenValue) {
            collision = true;
        }
    }

    window.addEventListener('schedule-editor-collision', collisionListener);
    const originalOnSignal = onSignal;
    onSignal = function (message) {
        originalOnSignal(message);
        window.dispatchEvent(new CustomEvent('schedule-editor-collision', {detail: message}));
    };

    sendSignal({
        type: 'probe',
        dayId: dayId,
        editorInstanceToken: tokenValue,
        documentToken: documentToken,
        requestToken: requestToken
    });
    await new Promise(function (resolve) { setTimeout(resolve, 180); });

    onSignal = originalOnSignal;
    window.removeEventListener('schedule-editor-collision', collisionListener);
    if (collision) {
        editorInstanceToken = randomToken();
    }

    try {
        sessionStorage.setItem(storageKey, editorInstanceToken);
    } catch (error) {
        // Токен всё равно остаётся действительным до закрытия документа.
    }
}

function lockRequest(path, keepalive) {
    const data = new URLSearchParams();
    data.append('day_id', String(dayId));
    data.append('csrf_token', csrfToken);
    data.append('editor_instance_token', editorInstanceToken);
    data.append('document_token', documentToken);

    return fetch(path, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: data.toString(),
        credentials: 'same-origin',
        keepalive: Boolean(keepalive)
    });
}

async function acquireLock() {
    await chooseEditorInstanceToken();
    instanceInput.value = editorInstanceToken;
    documentInput.value = documentToken;

    try {
        const response = await lockRequest('/manage/schedule_lock_acquire.php', false);
        const payload = await response.json().catch(function () { return {}; });
        if (!response.ok) {
            if (response.status === 409 && payload.lock) {
                setStatus(
                    'Редактирование занято: ' + payload.lock.name
                    + ' (' + payload.lock.username + '), последняя активность '
                    + payload.lock.last_seen_at + '.',
                    'error'
                );
            } else {
                setStatus(payload.message || 'Не удалось открыть редактор для записи.', 'error');
            }
            return;
        }

        lockOwned = true;
        fields.disabled = false;
        setStatus('Редактор готов. Изменения можно сохранять.', 'success');
    } catch (error) {
        setStatus('Не удалось связаться с сервером. Данные нельзя сохранять.', 'error');
    }
}

form.addEventListener('submit', function (event) {
    if (!lockOwned) {
        event.preventDefault();
        setStatus('Блокировка редактора не получена. Данные не отправлены.', 'error');
        return;
    }
    formSubmitting = true;
});

setInterval(function () {
    if (!lockOwned) {
        return;
    }
    lockRequest('/manage/schedule_lock_ping.php', false).then(function (response) {
        if (response.status === 409) {
            lockOwned = false;
            fields.disabled = true;
            setStatus('Блокировка редактора потеряна. Откройте день заново.', 'error');
        }
    }).catch(function () {
        // Следующая проверка повторится автоматически; сохранение также перепроверит блокировку.
    });
}, 30000);

window.addEventListener('beforeunload', function () {
    if (formSubmitting || !lockOwned) {
        return;
    }

    const data = new FormData();
    data.append('day_id', String(dayId));
    data.append('csrf_token', csrfToken);
    data.append('editor_instance_token', editorInstanceToken);
    data.append('document_token', documentToken);
    navigator.sendBeacon('/manage/schedule_lock_release.php', data);
});

acquireLock();
</script>
    <?php require_once dirname(__DIR__, 2) . '/inc/manage_footer.php'; ?>
</div>
</body>
</html>
