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

$quizColumns = $db->query('PRAGMA table_info(quizzes)')->fetchAll();
$hasDurationMinutes = false;
foreach ($quizColumns as $column) {
    if (($column['name'] ?? '') === 'duration_minutes') {
        $hasDurationMinutes = true;
        break;
    }
}
if (!$hasDurationMinutes) {
    $db->exec('ALTER TABLE quizzes ADD COLUMN duration_minutes INTEGER');
}

$scoreColumns = $db->query('PRAGMA table_info(scores)')->fetchAll();
$hasScoreUserId = false;
foreach ($scoreColumns as $column) {
    if (($column['name'] ?? '') === 'user_id') {
        $hasScoreUserId = true;
        break;
    }
}
if (!$hasScoreUserId) {
    $db->exec('ALTER TABLE scores ADD COLUMN user_id INTEGER');
}

$quizColumns = $db->query('PRAGMA table_info(quizzes)')->fetchAll();
$hasLevel = false;
$hasCourseId = false;
foreach ($quizColumns as $column) {
    if (($column['name'] ?? '') === 'level') {
        $hasLevel = true;
    }
    if (($column['name'] ?? '') === 'course_id') {
        $hasCourseId = true;
    }
}
if (!$hasLevel) {
    $db->exec('ALTER TABLE quizzes ADD COLUMN level TEXT');
}
if (!$hasCourseId) {
    $db->exec('ALTER TABLE quizzes ADD COLUMN course_id INTEGER');
}

$userColumns = $db->query('PRAGMA table_info(users)')->fetchAll();
$hasPasswordHash = false;
foreach ($userColumns as $column) {
    if (($column['name'] ?? '') === 'password_hash') {
        $hasPasswordHash = true;
        break;
    }
}
if (!$hasPasswordHash) {
    $db->exec('ALTER TABLE users ADD COLUMN password_hash TEXT');
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

$userCols2 = $db->query('PRAGMA table_info(users)')->fetchAll();
$hasUserDepartment = false;
foreach ($userCols2 as $column) {
    if (($column['name'] ?? '') === 'department') {
        $hasUserDepartment = true;
        break;
    }
}
if (!$hasUserDepartment) {
    $db->exec('ALTER TABLE users ADD COLUMN department TEXT NOT NULL DEFAULT \'\'');
}

$questionCols = $db->query('PRAGMA table_info(questions)')->fetchAll();
$hasQuestionStatus = false;
foreach ($questionCols as $column) {
    if (($column['name'] ?? '') === 'status') {
        $hasQuestionStatus = true;
        break;
    }
}
if (!$hasQuestionStatus) {
    $db->exec('ALTER TABLE questions ADD COLUMN status TEXT NOT NULL DEFAULT \'approved\'');
}

$quizColsSchedule = $db->query('PRAGMA table_info(quizzes)')->fetchAll();
$hasQuizStartsAt = false;
$hasQuizEndsAt = false;
foreach ($quizColsSchedule as $column) {
    $n = (string) ($column['name'] ?? '');
    if ($n === 'quiz_starts_at') {
        $hasQuizStartsAt = true;
    }
    if ($n === 'quiz_ends_at') {
        $hasQuizEndsAt = true;
    }
}
if (!$hasQuizStartsAt) {
    $db->exec('ALTER TABLE quizzes ADD COLUMN quiz_starts_at TEXT');
}
if (!$hasQuizEndsAt) {
    $db->exec('ALTER TABLE quizzes ADD COLUMN quiz_ends_at TEXT');
}

$db->exec('
CREATE TABLE IF NOT EXISTS admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
);
');

$userColsYt = $db->query('PRAGMA table_info(users)')->fetchAll();
$hasYoutubeRefresh = false;
foreach ($userColsYt as $column) {
    if (($column['name'] ?? '') === 'youtube_refresh_token') {
        $hasYoutubeRefresh = true;
        break;
    }
}
if (!$hasYoutubeRefresh) {
    $db->exec('ALTER TABLE users ADD COLUMN youtube_refresh_token TEXT');
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

$userColsSeen = $db->query('PRAGMA table_info(users)')->fetchAll();
$hasDownloadsLastSeen = false;
foreach ($userColsSeen as $column) {
    if (($column['name'] ?? '') === 'downloads_last_seen_at') {
        $hasDownloadsLastSeen = true;
        break;
    }
}
if (!$hasDownloadsLastSeen) {
    $db->exec('ALTER TABLE users ADD COLUMN downloads_last_seen_at TEXT');
}

$userColsQuizFeed = $db->query('PRAGMA table_info(users)')->fetchAll();
$hasQuizzesFeedLastSeen = false;
foreach ($userColsQuizFeed as $column) {
    if (($column['name'] ?? '') === 'quizzes_feed_last_seen_at') {
        $hasQuizzesFeedLastSeen = true;
        break;
    }
}
if (!$hasQuizzesFeedLastSeen) {
    $db->exec('ALTER TABLE users ADD COLUMN quizzes_feed_last_seen_at TEXT');
}

$quizColsCreated = $db->query('PRAGMA table_info(quizzes)')->fetchAll();
$hasQuizCreatedAt = false;
foreach ($quizColsCreated as $column) {
    if (($column['name'] ?? '') === 'created_at') {
        $hasQuizCreatedAt = true;
        break;
    }
}
if (!$hasQuizCreatedAt) {
    $db->exec('ALTER TABLE quizzes ADD COLUMN created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))');
}

$db->exec('
CREATE TABLE IF NOT EXISTS departments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE,
    created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
);
');

// Keep SQLite file writable for the web server user in local XAMPP setups.
if (!$dbFileExisted && is_file($dbFile)) {
    @chmod($dbFile, 0666);
}
@chmod($dataDir, 0777);
@chmod($dbFile, 0666);
