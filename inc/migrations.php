<?php

declare(strict_types=1);

function scheduleMigrationFiles(string $migrationDir): array
{
    $files = glob(rtrim($migrationDir, '/') . '/*.sqlite.sql');
    if ($files === false) {
        throw new RuntimeException('Не удалось прочитать каталог миграций');
    }

    $migrations = [];
    foreach ($files as $file) {
        $name = basename($file);
        if (!preg_match('/^(\d{3})_[A-Za-z0-9_.-]+\.sqlite\.sql$/', $name, $matches)) {
            continue;
        }

        $version = (int)$matches[1];
        if ($version < 1 || isset($migrations[$version])) {
            throw new RuntimeException('Некорректный набор миграций');
        }

        $migrations[$version] = $file;
    }

    if ($migrations === []) {
        throw new RuntimeException('Миграции SQLite не найдены');
    }

    ksort($migrations, SORT_NUMERIC);
    $expected = 1;
    foreach (array_keys($migrations) as $version) {
        if ($version !== $expected) {
            throw new RuntimeException('В наборе миграций есть пропуск версий');
        }
        $expected++;
    }

    return $migrations;
}

function scheduleLatestMigrationVersion(string $migrationDir): int
{
    $versions = array_keys(scheduleMigrationFiles($migrationDir));
    return (int)end($versions);
}

function scheduleCurrentSchemaVersion(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations' LIMIT 1"
    );
    if (!$stmt->fetchColumn()) {
        return 0;
    }

    return (int)$pdo->query(
        'SELECT COALESCE(MAX(version), 0) FROM schema_migrations'
    )->fetchColumn();
}

function scheduleApplyPendingMigrations(PDO $pdo, string $migrationDir, int $currentVersion): int
{
    $migrations = scheduleMigrationFiles($migrationDir);

    foreach ($migrations as $version => $file) {
        if ($version <= $currentVersion) {
            continue;
        }

        $sql = file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Файл миграции пуст или не читается');
        }

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            // Другой процесс мог применить миграцию, пока этот процесс ожидал write-lock.
            $actualVersion = scheduleCurrentSchemaVersion($pdo);
            if ($actualVersion >= $version) {
                $pdo->exec('COMMIT');
                $currentVersion = $actualVersion;
                continue;
            }
            if ($version !== $actualVersion + 1) {
                throw new RuntimeException('Нельзя пропустить версию схемы');
            }

            $pdo->exec($sql);
            $appliedVersion = scheduleCurrentSchemaVersion($pdo);
            if ($appliedVersion !== $version) {
                throw new RuntimeException('Миграция не зафиксировала свою версию');
            }
            $pdo->exec('COMMIT');
            $currentVersion = $version;
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $rollbackError) {
                // Исходная ошибка важнее ошибки отката.
            }
            throw $e;
        }
    }

    return $currentVersion;
}
