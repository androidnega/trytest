<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/trytest_urls.php';

$dataDir = dirname(__DIR__) . '/data';
$dbFile = $dataDir . '/quiz.sqlite';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

$dbFileExisted = is_file($dbFile);

$db = new PDO('sqlite:' . $dbFile, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Better read/write concurrency under many simultaneous students (single SQLite file).
try {
    $db->exec('PRAGMA journal_mode=WAL;');
    $db->exec('PRAGMA synchronous=NORMAL;');
    $db->exec('PRAGMA busy_timeout=8000;');
    $db->exec('PRAGMA temp_store=MEMORY;');
} catch (Throwable $e) {
    // ignore if pragma unsupported
}

$db->exec('
CREATE TABLE IF NOT EXISTS quizzes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    duration_minutes INTEGER,
    level TEXT,
    course_id INTEGER
);

CREATE TABLE IF NOT EXISTS questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    quiz_id INTEGER NOT NULL,
    question_type TEXT NOT NULL DEFAULT \'mcq\',
    question TEXT NOT NULL,
    option_a TEXT,
    option_b TEXT,
    option_c TEXT,
    option_d TEXT,
    correct_answer TEXT NOT NULL,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id)
);

CREATE TABLE IF NOT EXISTS scores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    quiz_id INTEGER NOT NULL,
    user_id INTEGER,
    score INTEGER NOT NULL,
    total INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id)
);

CREATE TABLE IF NOT EXISTS score_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    quiz_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    score INTEGER NOT NULL,
    total INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    index_number TEXT NOT NULL UNIQUE,
    level TEXT NOT NULL,
    password_hash TEXT,
    otp_code TEXT,
    otp_expires_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
    last_login_at TEXT
);

CREATE TABLE IF NOT EXISTS courses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL,
    title TEXT NOT NULL,
    level TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS quiz_courses (
    quiz_id INTEGER NOT NULL,
    course_id INTEGER NOT NULL,
    PRIMARY KEY (quiz_id, course_id),
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id),
    FOREIGN KEY (course_id) REFERENCES courses(id)
);
');

$db->exec('CREATE INDEX IF NOT EXISTS idx_score_attempts_user_quiz_id ON score_attempts(user_id, quiz_id, id DESC)');

$quizCol = [];
foreach ($db->query('PRAGMA table_info(quizzes)')->fetchAll() as $column) {
    $n = (string) ($column['name'] ?? '');
    if ($n !== '') {
        $quizCol[$n] = true;
    }
}
if (!isset($quizCol['duration_minutes'])) {
    $db->exec('ALTER TABLE quizzes ADD COLUMN duration_minutes INTEGER');
    $quizCol['duration_minutes'] = true;
}

$scoreCol = [];
foreach ($db->query('PRAGMA table_info(scores)')->fetchAll() as $column) {
    $n = (string) ($column['name'] ?? '');
    if ($n !== '') {
        $scoreCol[$n] = true;
    }
}
if (!isset($scoreCol['user_id'])) {
    $db->exec('ALTER TABLE scores ADD COLUMN user_id INTEGER');
    $scoreCol['user_id'] = true;
}

if (!isset($quizCol['level'])) {
    $db->exec('ALTER TABLE quizzes ADD COLUMN level TEXT');
    $quizCol['level'] = true;
}
if (!isset($quizCol['course_id'])) {
    $db->exec('ALTER TABLE quizzes ADD COLUMN course_id INTEGER');
    $quizCol['course_id'] = true;
}

$userCol = [];
foreach ($db->query('PRAGMA table_info(users)')->fetchAll() as $column) {
    $n = (string) ($column['name'] ?? '');
    if ($n !== '') {
        $userCol[$n] = true;
    }
}
if (!isset($userCol['password_hash'])) {
    $db->exec('ALTER TABLE users ADD COLUMN password_hash TEXT');
    $userCol['password_hash'] = true;
}

$courseCols = $db->query('PRAGMA table_info(courses)')->fetchAll();
$hasCourseDepartment = false;
foreach ($courseCols as $column) {
    if (($column['name'] ?? '') === 'department') {
        $hasCourseDepartment = true;
        break;
    }
}
if (!$hasCourseDepartment) {
    $db->exec('ALTER TABLE courses ADD COLUMN department TEXT NOT NULL DEFAULT \'\'');
}

if (!isset($userCol['department'])) {
    $db->exec('ALTER TABLE users ADD COLUMN department TEXT NOT NULL DEFAULT \'\'');
    $userCol['department'] = true;
}

$questionCol = [];
foreach ($db->query('PRAGMA table_info(questions)')->fetchAll() as $column) {
    $n = (string) ($column['name'] ?? '');
    if ($n !== '') {
        $questionCol[$n] = true;
    }
}
if (!isset($questionCol['status'])) {
    $db->exec('ALTER TABLE questions ADD COLUMN status TEXT NOT NULL DEFAULT \'approved\'');
    $questionCol['status'] = true;
}
if (!isset($questionCol['theory_rubric'])) {
    $db->exec('ALTER TABLE questions ADD COLUMN theory_rubric TEXT');
    $questionCol['theory_rubric'] = true;
}
if (!isset($questionCol['sql_practice'])) {
    $db->exec('ALTER TABLE questions ADD COLUMN sql_practice TEXT');
    $questionCol['sql_practice'] = true;
}

try {
    $db->exec("DELETE FROM questions WHERE LOWER(TRIM(COALESCE(question_type, ''))) = 'sql'");
} catch (Throwable $e) {
    // ignore
}

if (!isset($scoreCol['review_json'])) {
    $db->exec('ALTER TABLE scores ADD COLUMN review_json TEXT');
    $scoreCol['review_json'] = true;
}

if (!isset($quizCol['quiz_starts_at'])) {
    $db->exec('ALTER TABLE quizzes ADD COLUMN quiz_starts_at TEXT');
    $quizCol['quiz_starts_at'] = true;
}
if (!isset($quizCol['quiz_ends_at'])) {
    $db->exec('ALTER TABLE quizzes ADD COLUMN quiz_ends_at TEXT');
    $quizCol['quiz_ends_at'] = true;
}

$db->exec('
CREATE TABLE IF NOT EXISTS admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
);
');

if (!isset($userCol['youtube_refresh_token'])) {
    $db->exec('ALTER TABLE users ADD COLUMN youtube_refresh_token TEXT');
    $userCol['youtube_refresh_token'] = true;
}

$db->exec('
CREATE TABLE IF NOT EXISTS youtube_app_settings (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    gate_enabled INTEGER NOT NULL DEFAULT 0,
    client_id TEXT NOT NULL DEFAULT \'\',
    client_secret TEXT NOT NULL DEFAULT \'\',
    redirect_uri TEXT NOT NULL DEFAULT \'\',
    channel_id TEXT NOT NULL DEFAULT \'\',
    pdf_unlock_code TEXT NOT NULL DEFAULT \'\',
    dashboard_videos_enabled INTEGER NOT NULL DEFAULT 0,
    dashboard_video_urls TEXT NOT NULL DEFAULT \'\',
    quiz_ad_enabled INTEGER NOT NULL DEFAULT 0,
    quiz_ad_every_n INTEGER NOT NULL DEFAULT 20,
    quiz_ad_watch_seconds INTEGER NOT NULL DEFAULT 20,
    quiz_ad_video_urls TEXT NOT NULL DEFAULT \'\',
    updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
);
');
$db->exec('INSERT OR IGNORE INTO youtube_app_settings (id, gate_enabled) VALUES (1, 0)');

$ytCols = $db->query('PRAGMA table_info(youtube_app_settings)')->fetchAll();
$hasYtPdfCode = false;
$hasDashboardVideosEnabled = false;
$hasDashboardVideoUrls = false;
$hasQuizAdEnabled = false;
$hasQuizAdEveryN = false;
$hasQuizAdWatchSeconds = false;
$hasQuizAdVideoUrls = false;
foreach ($ytCols as $column) {
    $n = (string) ($column['name'] ?? '');
    if ($n === 'pdf_unlock_code') {
        $hasYtPdfCode = true;
    }
    if ($n === 'dashboard_videos_enabled') {
        $hasDashboardVideosEnabled = true;
    }
    if ($n === 'dashboard_video_urls') {
        $hasDashboardVideoUrls = true;
    }
    if ($n === 'quiz_ad_enabled') {
        $hasQuizAdEnabled = true;
    }
    if ($n === 'quiz_ad_every_n') {
        $hasQuizAdEveryN = true;
    }
    if ($n === 'quiz_ad_watch_seconds') {
        $hasQuizAdWatchSeconds = true;
    }
    if ($n === 'quiz_ad_video_urls') {
        $hasQuizAdVideoUrls = true;
    }
}
if (!$hasYtPdfCode) {
    $db->exec('ALTER TABLE youtube_app_settings ADD COLUMN pdf_unlock_code TEXT NOT NULL DEFAULT \'\'');
}
if (!$hasDashboardVideosEnabled) {
    $db->exec('ALTER TABLE youtube_app_settings ADD COLUMN dashboard_videos_enabled INTEGER NOT NULL DEFAULT 0');
}
if (!$hasDashboardVideoUrls) {
    $db->exec('ALTER TABLE youtube_app_settings ADD COLUMN dashboard_video_urls TEXT NOT NULL DEFAULT \'\'');
}
if (!$hasQuizAdEnabled) {
    $db->exec('ALTER TABLE youtube_app_settings ADD COLUMN quiz_ad_enabled INTEGER NOT NULL DEFAULT 0');
}
if (!$hasQuizAdEveryN) {
    $db->exec('ALTER TABLE youtube_app_settings ADD COLUMN quiz_ad_every_n INTEGER NOT NULL DEFAULT 20');
}
if (!$hasQuizAdWatchSeconds) {
    $db->exec('ALTER TABLE youtube_app_settings ADD COLUMN quiz_ad_watch_seconds INTEGER NOT NULL DEFAULT 20');
}
if (!$hasQuizAdVideoUrls) {
    $db->exec('ALTER TABLE youtube_app_settings ADD COLUMN quiz_ad_video_urls TEXT NOT NULL DEFAULT \'\'');
}

$db->exec('
CREATE TABLE IF NOT EXISTS student_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    department TEXT NOT NULL DEFAULT \'\',
    level TEXT NOT NULL DEFAULT \'\',
    stored_name TEXT NOT NULL,
    original_name TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
);
');

$db->exec('
CREATE TABLE IF NOT EXISTS student_document_downloads (
    user_id INTEGER NOT NULL,
    document_id INTEGER NOT NULL,
    downloaded_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
    PRIMARY KEY (user_id, document_id)
);
');

if (!isset($userCol['downloads_last_seen_at'])) {
    $db->exec('ALTER TABLE users ADD COLUMN downloads_last_seen_at TEXT');
    $userCol['downloads_last_seen_at'] = true;
}

if (!isset($userCol['quizzes_feed_last_seen_at'])) {
    $db->exec('ALTER TABLE users ADD COLUMN quizzes_feed_last_seen_at TEXT');
    $userCol['quizzes_feed_last_seen_at'] = true;
}

if (!isset($userCol['nickname'])) {
    $db->exec('ALTER TABLE users ADD COLUMN nickname TEXT NOT NULL DEFAULT \'\'');
    $userCol['nickname'] = true;
}

if (!isset($quizCol['created_at'])) {
    $db->exec('ALTER TABLE quizzes ADD COLUMN created_at TEXT');
    $quizCol['created_at'] = true;
    // Legacy rows: do not flood the “new quiz” home badge after this migration ships.
    $db->exec("UPDATE quizzes SET created_at = '2000-01-01 00:00:00' WHERE created_at IS NULL OR TRIM(COALESCE(created_at, '')) = ''");
}

$db->exec('
CREATE TABLE IF NOT EXISTS departments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE,
    created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
);
');

$db->exec('
CREATE TABLE IF NOT EXISTS levels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    value TEXT NOT NULL COLLATE NOCASE UNIQUE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
);
');

foreach ([['100', 100], ['200', 200], ['300', 300], ['400', 400]] as $lvSeed) {
    try {
        $db->prepare('INSERT OR IGNORE INTO levels (value, sort_order) VALUES (?, ?)')->execute($lvSeed);
    } catch (Throwable $e) {
        // ignore
    }
}

if (!isset($quizCol['share_code'])) {
    $db->exec('ALTER TABLE quizzes ADD COLUMN share_code TEXT NOT NULL DEFAULT \'\'');
    $quizCol['share_code'] = true;
}
$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_quizzes_share_code ON quizzes(share_code) WHERE share_code != \'\'');

$db->exec('
CREATE TABLE IF NOT EXISTS quiz_presence_ping (
    user_id INTEGER NOT NULL,
    quiz_id INTEGER NOT NULL,
    last_seen INTEGER NOT NULL,
    PRIMARY KEY (user_id, quiz_id)
);
');
$db->exec('CREATE INDEX IF NOT EXISTS idx_quiz_presence_last_seen ON quiz_presence_ping(last_seen)');

$db->exec('
CREATE TABLE IF NOT EXISTS student_system_feedback (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    stars INTEGER NOT NULL,
    body TEXT NOT NULL,
    quiz_ref TEXT NOT NULL DEFAULT \'\',
    created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
);
');
$db->exec('CREATE INDEX IF NOT EXISTS idx_student_feedback_created ON student_system_feedback(created_at DESC)');
try {
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_student_feedback_user_id ON student_system_feedback(user_id)');
} catch (Throwable $e) {
    // Legacy DBs may contain multiple rows per student. Keep only the latest row, then enforce uniqueness.
    try {
        $db->exec(
            'DELETE FROM student_system_feedback
             WHERE id NOT IN (
                 SELECT MAX(id) FROM student_system_feedback GROUP BY user_id
             )'
        );
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_student_feedback_user_id ON student_system_feedback(user_id)');
    } catch (Throwable $e2) {
        // Do not block app boot.
    }
}

$db->exec('
CREATE TABLE IF NOT EXISTS trytest_boot_flags (
    flag TEXT PRIMARY KEY,
    applied_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
);
');

// One-time bulk fill for quiz share codes. Previously this ran on every HTTP request and could
// take minutes with many quizzes (N UPDATE attempts). Lazy assignment still runs via quiz_share.php
// when a quiz is opened or listed where needed.
require_once dirname(__DIR__) . '/includes/quiz_share.php';
try {
    $flagDone = (bool) $db->query(
        "SELECT 1 FROM trytest_boot_flags WHERE flag = 'quiz_share_code_bulk_v1' LIMIT 1"
    )->fetchColumn();
    if (!$flagDone) {
        $missing = (int) $db->query(
            "SELECT COUNT(*) FROM quizzes WHERE share_code IS NULL OR TRIM(share_code) = ''"
        )->fetchColumn();
        if ($missing > 0) {
            foreach (
                $db->query(
                    'SELECT id FROM quizzes WHERE share_code IS NULL OR TRIM(share_code) = \'\''
                )->fetchAll() as $row
            ) {
                $rid = (int) ($row['id'] ?? 0);
                if ($rid > 0) {
                    trytest_quiz_ensure_share_code($db, $rid);
                }
            }
        }
        $db->prepare('INSERT OR IGNORE INTO trytest_boot_flags (flag) VALUES (?)')->execute(['quiz_share_code_bulk_v1']);
    }
} catch (Throwable $e) {
    // Do not block app boot if migration helper fails.
}

// Keep SQLite file writable for the web server user in local XAMPP setups (avoid chmod every request).
if (!$dbFileExisted && is_file($dbFile)) {
    @chmod($dbFile, 0666);
}
if (!is_writable($dataDir)) {
    @chmod($dataDir, 0777);
}
if (is_file($dbFile) && !is_writable($dbFile)) {
    @chmod($dbFile, 0666);
}
