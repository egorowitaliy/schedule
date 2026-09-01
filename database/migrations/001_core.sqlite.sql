PRAGMA foreign_keys = ON;

CREATE TABLE schema_migrations (
    version INTEGER PRIMARY KEY,
    applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE app_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE groups_list (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE CHECK (length(name) BETWEEN 1 AND 100),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    sort_order INTEGER NOT NULL DEFAULT 0 CHECK (sort_order >= 0)
);

CREATE TABLE lesson_times (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lesson_number INTEGER NOT NULL CHECK (lesson_number > 0),
    weekdays_mask INTEGER NOT NULL CHECK (weekdays_mask BETWEEN 1 AND 127),
    time_start TEXT NOT NULL CHECK (
        length(time_start) = 5
        AND substr(time_start, 3, 1) = ':'
        AND time(time_start) IS NOT NULL
        AND strftime('%H:%M', time_start) = time_start
    ),
    time_end TEXT NOT NULL CHECK (
        length(time_end) = 5
        AND substr(time_end, 3, 1) = ':'
        AND time(time_end) IS NOT NULL
        AND strftime('%H:%M', time_end) = time_end
        AND time_end > time_start
    ),
    UNIQUE (lesson_number, weekdays_mask)
);

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE CHECK (length(username) BETWEEN 3 AND 100),
    full_name TEXT NOT NULL DEFAULT '' CHECK (length(full_name) <= 255),
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
, auth_version INTEGER NOT NULL DEFAULT 1 CHECK (auth_version > 0));

CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address TEXT NOT NULL CHECK (length(ip_address) <= 45),
    username TEXT NULL CHECK (username IS NULL OR length(username) <= 100),
    attempt_time INTEGER NOT NULL,
    is_success INTEGER NOT NULL DEFAULT 0 CHECK (is_success IN (0, 1)),
    user_agent TEXT NULL CHECK (user_agent IS NULL OR length(user_agent) <= 255)
);

CREATE TABLE rooms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE CHECK (length(name) BETWEEN 1 AND 100),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
);

CREATE TABLE subjects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE CHECK (length(name) BETWEEN 1 AND 255),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
);

CREATE TABLE teachers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name TEXT NOT NULL UNIQUE CHECK (length(full_name) BETWEEN 1 AND 255),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
);

CREATE TABLE schedule_days (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    study_date TEXT NOT NULL UNIQUE CHECK (
        length(study_date) = 10
        AND substr(study_date, 5, 1) = '-'
        AND substr(study_date, 8, 1) = '-'
        AND date(study_date) IS NOT NULL
        AND date(study_date) = study_date
    ),
    title TEXT NULL CHECK (title IS NULL OR length(title) <= 255),
    is_published INTEGER NOT NULL DEFAULT 0 CHECK (is_published IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE schedule_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    schedule_day_id INTEGER NOT NULL,
    group_id INTEGER NOT NULL,
    lesson_time_id INTEGER NOT NULL,
    subject_id INTEGER NULL,
    teacher_id INTEGER NULL,
    room_id INTEGER NULL,
    lesson_type TEXT NULL CHECK (lesson_type IS NULL OR length(lesson_type) <= 100),
    note TEXT NULL,
    is_distance INTEGER NOT NULL DEFAULT 0 CHECK (is_distance IN (0, 1)),
    is_cancelled INTEGER NOT NULL DEFAULT 0 CHECK (is_cancelled IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE (schedule_day_id, group_id, lesson_time_id),

    FOREIGN KEY (schedule_day_id) REFERENCES schedule_days(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES groups_list(id) ON DELETE RESTRICT,
    FOREIGN KEY (lesson_time_id) REFERENCES lesson_times(id) ON DELETE RESTRICT,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT
);

CREATE TABLE schedule_day_locks (
    schedule_day_id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    editor_instance_token TEXT NOT NULL CHECK (
        length(editor_instance_token) = 64
        AND editor_instance_token NOT GLOB '*[^0-9a-f]*'
    ),
    document_token TEXT NOT NULL CHECK (
        length(document_token) = 64
        AND document_token NOT GLOB '*[^0-9a-f]*'
    ),
    username TEXT NOT NULL CHECK (length(username) <= 100),
    full_name TEXT NOT NULL DEFAULT '' CHECK (length(full_name) <= 255),
    ip_address TEXT NOT NULL CHECK (length(ip_address) <= 45),
    locked_at INTEGER NOT NULL,
    last_seen_at INTEGER NOT NULL,

    FOREIGN KEY (schedule_day_id) REFERENCES schedule_days(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_schedule_entries_group ON schedule_entries (group_id);

CREATE INDEX idx_schedule_entries_time ON schedule_entries (lesson_time_id);

CREATE INDEX idx_schedule_entries_subject ON schedule_entries (subject_id);

CREATE INDEX idx_schedule_entries_teacher ON schedule_entries (teacher_id);

CREATE INDEX idx_schedule_entries_room ON schedule_entries (room_id);

CREATE INDEX idx_login_attempts_ip_time
    ON login_attempts (ip_address, is_success, attempt_time);

CREATE INDEX idx_login_attempts_user_time
    ON login_attempts (username, is_success, attempt_time);

CREATE INDEX idx_login_attempts_cleanup
    ON login_attempts (attempt_time);

CREATE INDEX idx_schedule_day_locks_user ON schedule_day_locks (user_id);

CREATE TRIGGER lesson_times_no_overlap_insert
BEFORE INSERT ON lesson_times
WHEN EXISTS (
    SELECT 1 FROM lesson_times
    WHERE lesson_number = NEW.lesson_number
      AND (weekdays_mask & NEW.weekdays_mask) != 0
)
BEGIN
    SELECT RAISE(ABORT, 'lesson time weekdays overlap');
END;

CREATE TRIGGER lesson_times_no_overlap_update
BEFORE UPDATE OF lesson_number, weekdays_mask ON lesson_times
WHEN EXISTS (
    SELECT 1 FROM lesson_times
    WHERE id != NEW.id
      AND lesson_number = NEW.lesson_number
      AND (weekdays_mask & NEW.weekdays_mask) != 0
)
BEGIN
    SELECT RAISE(ABORT, 'lesson time weekdays overlap');
END;

CREATE TRIGGER schedule_entries_compatibility_insert
BEFORE INSERT ON schedule_entries
WHEN NOT EXISTS (
    SELECT 1
    FROM schedule_days d
    JOIN lesson_times l
    WHERE d.id = NEW.schedule_day_id
      AND l.id = NEW.lesson_time_id
      AND (
          l.weekdays_mask & CASE CAST(strftime('%w', d.study_date) AS INTEGER)
              WHEN 0 THEN 64
              ELSE (1 << (CAST(strftime('%w', d.study_date) AS INTEGER) - 1))
          END
      ) != 0
)
BEGIN
    SELECT RAISE(ABORT, 'schedule entry is incompatible with lesson time');
END;

CREATE TRIGGER schedule_entries_compatibility_update
BEFORE UPDATE OF schedule_day_id, lesson_time_id ON schedule_entries
WHEN NOT EXISTS (
    SELECT 1
    FROM schedule_days d
    JOIN lesson_times l
    WHERE d.id = NEW.schedule_day_id
      AND l.id = NEW.lesson_time_id
      AND (
          l.weekdays_mask & CASE CAST(strftime('%w', d.study_date) AS INTEGER)
              WHEN 0 THEN 64
              ELSE (1 << (CAST(strftime('%w', d.study_date) AS INTEGER) - 1))
          END
      ) != 0
)
BEGIN
    SELECT RAISE(ABORT, 'schedule entry is incompatible with lesson time');
END;

CREATE TRIGGER schedule_days_schedule_compatibility_update
BEFORE UPDATE OF study_date ON schedule_days
WHEN EXISTS (
    SELECT 1
    FROM schedule_entries e
    JOIN lesson_times l ON l.id = e.lesson_time_id
    WHERE e.schedule_day_id = OLD.id
      AND (
          l.weekdays_mask & CASE CAST(strftime('%w', NEW.study_date) AS INTEGER)
              WHEN 0 THEN 64
              ELSE (1 << (CAST(strftime('%w', NEW.study_date) AS INTEGER) - 1))
          END
      ) = 0
)
BEGIN
    SELECT RAISE(ABORT, 'schedule day is incompatible with existing entries');
END;

CREATE TRIGGER lesson_times_schedule_compatibility_update
BEFORE UPDATE OF lesson_number, weekdays_mask ON lesson_times
WHEN EXISTS (
    SELECT 1
    FROM schedule_entries e
    JOIN schedule_days d ON d.id = e.schedule_day_id
    WHERE e.lesson_time_id = OLD.id
      AND (
          NEW.weekdays_mask & CASE CAST(strftime('%w', d.study_date) AS INTEGER)
              WHEN 0 THEN 64
              ELSE (1 << (CAST(strftime('%w', d.study_date) AS INTEGER) - 1))
          END
      ) = 0
)
BEGIN
    SELECT RAISE(ABORT, 'lesson time is incompatible with existing schedule entries');
END;

INSERT INTO schema_migrations (version) VALUES (1);
