<?php

declare(strict_types=1);

require_once __DIR__ . '/quiz_share.php';

function trytest_student_display_name(string $indexNumber): string
{
    $t = trim($indexNumber);
    if ($t === '') {
        return 'Student';
    }
    $parts = preg_split('/[\/\\\\]+/', $t) ?: [];
    $last = $parts ? (string) end($parts) : $t;
    $last = preg_replace('/[^A-Za-z0-9\-]/', '', $last) ?? $last;
    if ($last === '') {
        return substr($t, 0, 12);
    }
    return strlen($last) > 14 ? substr($last, 0, 14) . '…' : $last;
}

/**
 * Whether a student may access an uploaded PDF (department + level rules).
 * Empty document department = any program; empty document level = any level.
 * If the document targets a specific department, the student must have that department set and match.
 */
function trytest_student_document_eligible(string $userDepartment, string $userLevel, string $docDepartment, string $docLevel): bool
{
    $ud = trim($userDepartment);
    $ul = trim($userLevel);
    $dd = trim($docDepartment);
    $dl = trim($docLevel);

    if ($dl !== '' && strcasecmp($ul, $dl) !== 0) {
        return false;
    }

    if ($dd === '') {
        return true;
    }

    return $ud !== '' && strcasecmp($ud, $dd) === 0;
}

/**
 * Best-effort numeric level for matching (e.g. "Level 100", "Lv100" → "100").
 */
function trytest_student_level_canon(string $level): string
{
    $t = trim($level);
    if ($t === '') {
        return '';
    }
    if (preg_match('/(\d{1,4})\b/', $t, $m) === 1) {
        return (string) (int) $m[1];
    }

    return strtolower($t);
}

/**
 * Empty / null quiz level = visible to everyone in that course listing.
 * Otherwise the quiz's level must match the student's level (canonically).
 */
function trytest_quiz_level_visible_to_student(?string $quizLevel, string $userLevel): bool
{
    $raw = trim((string) $quizLevel);
    if ($raw === '') {
        return true;
    }
    $q = trytest_student_level_canon($raw);
    $u = trytest_student_level_canon($userLevel);

    return $q !== '' && $u !== '' && $q === $u;
}

/**
 * SQL WHERE fragment for student_documents rows this student may see (same rules as trytest_student_document_eligible).
 *
 * @return array{sql:string,params:list<string>}
 */
function trytest_student_documents_visibility_sql(string $tableAlias, string $userLevel, string $userDepartment): array
{
    $a = preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableAlias) === 1 ? $tableAlias : 'd';
    $ul = trim($userLevel);
    $ud = trim($userDepartment);
    $sql = '(TRIM(COALESCE(' . $a . '.level, \'\')) = \'\' OR TRIM(COALESCE(' . $a . '.level, \'\')) = ?)'
        . ' AND (TRIM(COALESCE(' . $a . '.department, \'\')) = \'\''
        . ' OR (? != \'\' AND LOWER(TRIM(COALESCE(' . $a . '.department, \'\'))) = LOWER(?)))';

    return ['sql' => $sql, 'params' => [$ul, $ud, $ud]];
}

/**
 * Courses and quizzes for the student dashboard / quizzes hub (level + department scoped).
 *
 * @return list<array<string,mixed>>
 */
function trytest_student_load_courses_with_quizzes(PDO $db, int $userId, string $userLevel, string $userDepartment): array
{
    $coursesWithQuizzes = [];
    $userDepartment = trim($userDepartment);
    $userLevel = trim($userLevel);
    if ($userDepartment === '') {
        return [];
    }

    $courseSql = 'SELECT c.id, c.code, c.title, c.level, c.department FROM courses c
        WHERE c.level = ?
          AND LOWER(TRIM(COALESCE(c.department, \'\'))) = LOWER(TRIM(?))
        ORDER BY c.code ASC';
    $courseStmt = $db->prepare($courseSql);
    $courseStmt->execute([$userLevel, $userDepartment]);
    $courses = $courseStmt->fetchAll();

    $attemptedQuizIds = [];
    if ($userId > 0) {
        $aq = $db->prepare('SELECT DISTINCT quiz_id FROM scores WHERE user_id = ?');
        $aq->execute([$userId]);
        foreach ($aq->fetchAll(PDO::FETCH_COLUMN) as $qid) {
            $attemptedQuizIds[(int) $qid] = true;
        }
    }

    foreach ($courses as $course) {
        $quizStmt = $db->prepare(
            'SELECT DISTINCT q.id, q.title, q.quiz_starts_at, q.quiz_ends_at, q.level AS quiz_level, q.created_at AS quiz_created_at, q.share_code,
             (SELECT COUNT(*) FROM questions qn WHERE qn.quiz_id = q.id AND qn.status = ?) AS question_count
             FROM quizzes q
             LEFT JOIN quiz_courses qc ON qc.quiz_id = q.id
             WHERE (q.course_id = ? OR qc.course_id = ?)
             ORDER BY q.id DESC'
        );
        $quizStmt->execute(['approved', (int) $course['id'], (int) $course['id']]);
        $quizzes = [];
        foreach ($quizStmt->fetchAll() as $qz) {
            if ((int) ($qz['question_count'] ?? 0) < 1) {
                continue;
            }
            if (!trytest_quiz_level_visible_to_student(isset($qz['quiz_level']) ? (string) $qz['quiz_level'] : null, $userLevel)) {
                continue;
            }
            $qid = (int) ($qz['id'] ?? 0);
            if ($qid > 0 && $userId > 0 && !empty($attemptedQuizIds[$qid])) {
                continue;
            }
            $sc = trim((string) ($qz['share_code'] ?? ''));
            if ($sc === '') {
                $sc = trytest_quiz_ensure_share_code($db, $qid);
            }
            $qz['share_code'] = $sc;
            $qz['user_has_attempt'] = false;
            $quizzes[] = $qz;
        }
        $coursesWithQuizzes[] = array_merge($course, ['quizzes' => $quizzes]);
    }

    return $coursesWithQuizzes;
}

/**
 * Quizzes newer than the last time the student opened the quizzes hub (or since account creation if never opened).
 *
 * @param list<array<string,mixed>> $coursesWithQuizzes
 */
function trytest_student_new_quizzes_badge_count(array $coursesWithQuizzes, string $quizzesFeedLastSeenAt, string $userAccountCreatedAt): int
{
    $seen = trim($quizzesFeedLastSeenAt);
    $baseline = $seen !== '' ? $seen : trim($userAccountCreatedAt);
    if ($baseline === '') {
        return 0;
    }
    $n = 0;
    foreach ($coursesWithQuizzes as $course) {
        foreach ((array) ($course['quizzes'] ?? []) as $qz) {
            $cAt = trim((string) ($qz['quiz_created_at'] ?? ''));
            if ($cAt !== '' && strcmp($cAt, $baseline) > 0) {
                $n++;
            }
        }
    }

    return $n;
}

function trytest_student_avatar_svg(string $seed, int $size = 56, int $userId = 0): string
{
    $mix = $seed . "\0#" . (string) max(0, $userId);
    $h = (int) sprintf('%u', crc32($mix));
    $hue = $h % 360;
    $sat = 48 + ($h >> 3) % 22;
    $light = 72 + ($h >> 7) % 18;
    $base = sprintf('hsl(%d,%d%%,%d%%)', $hue, $sat, $light);
    $eyeY = 36 + ($h % 7);
    $eyeOff = ($h % 2) === 0 ? 0 : 1;
    $mouth = ($h % 4) === 0 ? 'M28,48 Q36,54 44,48' : (($h % 4) === 1 ? 'M30,50 Q36,46 42,50' : (($h % 4) === 2 ? 'M30,52 Q36,58 42,52' : 'M32,51 Q36,48 40,51'));
    $cheek = ($h % 5) === 0 ? '<ellipse cx="22" cy="46" rx="5" ry="3" fill="rgba(255,120,140,.22)"/><ellipse cx="50" cy="46" rx="5" ry="3" fill="rgba(255,120,140,.22)"/>' : '';

    return sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 72 72" role="img" aria-hidden="true">'
        . '<circle cx="36" cy="36" r="34" fill="%s"/>'
        . '%s'
        . '<circle cx="%d" cy="%d" r="4.2" fill="rgba(0,0,0,.38)"/><circle cx="%d" cy="%d" r="4.2" fill="rgba(0,0,0,.38)"/>'
        . '<path d="%s" fill="none" stroke="rgba(0,0,0,.34)" stroke-width="2.2" stroke-linecap="round"/>'
        . '</svg>',
        $size,
        $size,
        $base,
        $cheek,
        28 - $eyeOff,
        $eyeY,
        44 + $eyeOff,
        $eyeY,
        $mouth
    );
}

/**
 * @return list<array{user_id:int,index_number:string,department:string,best_score:int,best_total:int,first_best_at:string}>
 */
function trytest_quiz_leaderboard(PDO $db, int $quizId, int $limit = 40, ?string $level = null, ?string $department = null): array
{
    if ($quizId < 1) {
        return [];
    }
    $lim = max(1, min(100, $limit));
    $sql = '
        WITH ranked AS (
            SELECT user_id, score, total, created_at, id,
                   ROW_NUMBER() OVER (
                       PARTITION BY user_id ORDER BY score DESC, created_at ASC, id ASC
                   ) AS rn
            FROM scores
            WHERE quiz_id = ? AND user_id IS NOT NULL
        )
        SELECT u.id AS user_id,
               u.index_number AS index_number,
               u.department AS department,
               r.score AS best_score,
               r.total AS best_total,
               r.created_at AS first_best_at
        FROM ranked r
        INNER JOIN users u ON u.id = r.user_id
        WHERE r.rn = 1';
    $params = [$quizId];
    $lv = trim((string) ($level ?? ''));
    $dp = trim((string) ($department ?? ''));
    if ($lv !== '') {
        $sql .= ' AND LOWER(TRIM(COALESCE(u.level, \'\'))) = LOWER(TRIM(?))';
        $params[] = $lv;
    }
    if ($dp !== '') {
        $sql .= ' AND LOWER(TRIM(COALESCE(u.department, \'\'))) = LOWER(TRIM(?))';
        $params[] = $dp;
    }
    $sql .= '
        ORDER BY CASE WHEN r.total > 0 THEN (CAST(r.score AS REAL) / r.total) ELSE 0 END DESC,
                 r.score DESC,
                 r.created_at ASC,
                 u.index_number ASC
        LIMIT ' . $lim;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'user_id' => (int) ($r['user_id'] ?? 0),
            'index_number' => (string) ($r['index_number'] ?? ''),
            'department' => (string) ($r['department'] ?? ''),
            'best_score' => (int) ($r['best_score'] ?? 0),
            'best_total' => (int) ($r['best_total'] ?? 0),
            'first_best_at' => (string) ($r['first_best_at'] ?? ''),
        ];
    }
    return $out;
}

/**
 * Points ranking for everyone in the same program (department) and canonical level (e.g. 100 vs 200).
 * Includes students who have never scored (0 pts). Uses LEFT JOIN so zero-score rows are kept.
 *
 * @param int $limit Max rows (0 = no cap — whole cohort).
 *
 * @return list<array{user_id:int,index_number:string,department:string,total_points:int}>
 */
function trytest_level_leaderboard(PDO $db, string $level, string $department, int $limit = 0): array
{
    $lv = trim($level);
    $dp = trim($department);
    if ($lv === '' || $dp === '') {
        return [];
    }
    $wantCanon = trytest_student_level_canon($lv);
    if ($wantCanon === '') {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT u.id AS user_id, u.index_number AS index_number, u.department AS department,
                TRIM(COALESCE(u.level, \'\')) AS user_level,
                COALESCE(SUM(s.score), 0) AS total_points
         FROM users u
         LEFT JOIN scores s ON s.user_id = u.id AND s.user_id IS NOT NULL
         WHERE LOWER(TRIM(COALESCE(u.department, \'\'))) = LOWER(TRIM(?))
         GROUP BY u.id, u.index_number, u.department, u.level'
    );
    $stmt->execute([$dp]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $candidates = [];
    foreach ($rows as $r) {
        $ul = trytest_student_level_canon((string) ($r['user_level'] ?? ''));
        if ($ul === '' || $ul !== $wantCanon) {
            continue;
        }
        $candidates[] = [
            'user_id' => (int) ($r['user_id'] ?? 0),
            'index_number' => (string) ($r['index_number'] ?? ''),
            'department' => (string) ($r['department'] ?? ''),
            'total_points' => (int) ($r['total_points'] ?? 0),
        ];
    }
    usort(
        $candidates,
        static function (array $a, array $b): int {
            $ta = $a['total_points'];
            $tb = $b['total_points'];
            if ($ta !== $tb) {
                return $tb <=> $ta;
            }

            return strcmp($a['index_number'], $b['index_number']);
        }
    );

    if ($limit > 0) {
        return array_slice($candidates, 0, max(1, min(5000, $limit)));
    }

    return $candidates;
}

/**
 * Latest saved score per quiz for this student (only quizzes they may open).
 *
 * @return list<array{quiz_id:int,title:string,score:int,total:int,created_at:string}>
 */
function trytest_student_quiz_results_rows(PDO $db, int $userId, string $userLevel, string $userDepartment): array
{
    if ($userId < 1) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT s.quiz_id AS quiz_id, q.title AS title, s.score AS score, s.total AS total, s.created_at AS created_at
         FROM scores s
         INNER JOIN quizzes q ON q.id = s.quiz_id
         INNER JOIN (
             SELECT quiz_id, MAX(id) AS latest_score_id
             FROM scores
             WHERE user_id = ?
             GROUP BY quiz_id
         ) z ON z.latest_score_id = s.id
         WHERE s.user_id = ?
         ORDER BY datetime(s.created_at) DESC, s.id DESC'
    );
    $stmt->execute([$userId, $userId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qid = (int) ($r['quiz_id'] ?? 0);
        if ($qid < 1) {
            continue;
        }
        if (!trytest_student_can_access_quiz($db, $qid, $userLevel, $userDepartment)) {
            continue;
        }
        $out[] = [
            'quiz_id' => $qid,
            'title' => (string) ($r['title'] ?? 'Quiz'),
            'score' => (int) ($r['score'] ?? 0),
            'total' => (int) ($r['total'] ?? 0),
            'created_at' => (string) ($r['created_at'] ?? ''),
        ];
    }

    return $out;
}

/**
 * Normalize per-question review rows from the quiz client for storage on scores.review_json.
 *
 * @param list<mixed>|null $rows
 */
function trytest_quiz_review_json_normalize(?array $rows, int $totalQuestions): ?string
{
    if ($rows === null || $totalQuestions < 1 || $rows === []) {
        return null;
    }
    $out = [];
    $i = 0;
    foreach ($rows as $row) {
        if ($i >= 600) {
            break;
        }
        if (!is_array($row)) {
            continue;
        }
        $verdict = (string) ($row['verdict'] ?? '');
        if (!in_array($verdict, ['correct', 'partial', 'wrong'], true)) {
            continue;
        }
        $stem = (string) ($row['stem'] ?? '');
        if (strlen($stem) > 12000) {
            $stem = substr($stem, 0, 12000);
        }
        $ua = (string) ($row['userAnswer'] ?? '');
        if (strlen($ua) > 8000) {
            $ua = substr($ua, 0, 8000);
        }
        $ca = (string) ($row['correctAnswer'] ?? '');
        if (strlen($ca) > 8000) {
            $ca = substr($ca, 0, 8000);
        }
        $pt = strtolower((string) preg_replace('/[^a-z]/i', '', (string) ($row['playType'] ?? 'mcq')));
        $playType = match ($pt) {
            'theory' => 'theory',
            'fill', 'fillin' => 'fill',
            default => 'mcq',
        };
        $out[] = [
            'questionId' => (int) ($row['questionId'] ?? 0),
            'playType' => $playType,
            'stem' => $stem,
            'userAnswer' => $ua,
            'correctAnswer' => $ca,
            'verdict' => $verdict,
        ];
        $i++;
    }
    if ($out === []) {
        return null;
    }
    $json = json_encode($out, JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > 900000) {
        return null;
    }

    return $json;
}

/**
 * Remove this student’s saved score and attempt history for a quiz (try again).
 */
function trytest_student_wipe_quiz_results(PDO $db, int $userId, int $quizId): void
{
    if ($userId < 1 || $quizId < 1) {
        return;
    }
    $db->prepare('DELETE FROM score_attempts WHERE quiz_id = ? AND user_id = ?')->execute([$quizId, $userId]);
    $db->prepare('DELETE FROM scores WHERE quiz_id = ? AND user_id = ?')->execute([$quizId, $userId]);
}

/**
 * Whether this user may use "Try again" for a quiz. If they already have a saved score for that
 * quiz, allow reset (they are clearing their own attempt). Otherwise use normal quiz access rules.
 */
function trytest_student_may_reset_quiz_attempt(PDO $db, int $userId, int $quizId, string $userLevel, string $userDepartment): bool
{
    if ($userId < 1 || $quizId < 1) {
        return false;
    }
    $st = $db->prepare('SELECT 1 FROM scores WHERE user_id = ? AND quiz_id = ? LIMIT 1');
    $st->execute([$userId, $quizId]);
    if ($st->fetchColumn()) {
        return true;
    }

    return trytest_student_can_access_quiz($db, $quizId, $userLevel, $userDepartment);
}

/**
 * Inline SVG for student dashboard quick-link tiles (stroke icons).
 */
function trytest_student_dashboard_tile_svg(string $kind, int $size = 40): string
{
    $s = max(20, min(56, $size));
    $stroke = '#2C6A7D';
    $sw = '1.75';
    switch ($kind) {
        case 'trophy':
            $path = '<path d="M8 21h8M12 17v4M6 4h12v3a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V4zM10 4V3a2 2 0 1 1 4 0v1" fill="none" stroke="' . $stroke . '" stroke-width="' . $sw . '" stroke-linecap="round" stroke-linejoin="round"/>';
            break;
        case 'folder':
            $path = '<path d="M4 6a2 2 0 0 1 2-2h3l2 2h7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6z" fill="none" stroke="' . $stroke . '" stroke-width="' . $sw . '" stroke-linejoin="round"/>';
            break;
        case 'quiz':
            $path = '<path d="M6 4h12a2 2 0 0 1 2 2v14l-4-2-4 2-4-2-4 2V6a2 2 0 0 1 2-2zM9 9h6M9 13h4" fill="none" stroke="' . $stroke . '" stroke-width="' . $sw . '" stroke-linecap="round" stroke-linejoin="round"/>';
            break;
        case 'results':
            $path = '<path d="M4 19V5M4 19h16M8 17V9m4 8V7m4 10v-4" fill="none" stroke="' . $stroke . '" stroke-width="' . $sw . '" stroke-linecap="round" stroke-linejoin="round"/>';
            break;
        case 'rank':
        case 'podium':
            $path = '<path d="M6 20h4v-8H6v8zm5-12h4v12h-4V8zm5 6h4v6h-4v-6z" fill="none" stroke="' . $stroke . '" stroke-width="' . $sw . '" stroke-linecap="round" stroke-linejoin="round"/>';
            break;
        default:
            $path = '<circle cx="12" cy="12" r="8" fill="none" stroke="' . $stroke . '" stroke-width="' . $sw . '"/>';
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" class="shrink-0" aria-hidden="true">' . $path . '</svg>';
}

/**
 * Whether this student may open the quiz (same course/level/dept rules as the dashboard list).
 */
function trytest_student_can_access_quiz(PDO $db, int $quizId, string $userLevel, string $userDepartment): bool
{
    $quizId = max(0, $quizId);
    $userLevel = trim($userLevel);
    $userDepartment = trim($userDepartment);
    if ($quizId < 1 || $userLevel === '' || $userDepartment === '') {
        return false;
    }

    $sql = 'SELECT q.level FROM quizzes q WHERE q.id = ?
        AND (
            EXISTS (
                SELECT 1 FROM courses c
                WHERE c.id = q.course_id AND c.level = ?
                AND LOWER(TRIM(COALESCE(c.department, \'\'))) = LOWER(TRIM(?))
            )
            OR EXISTS (
                SELECT 1 FROM quiz_courses qc
                INNER JOIN courses c ON c.id = qc.course_id
                WHERE qc.quiz_id = q.id AND c.level = ?
                AND LOWER(TRIM(COALESCE(c.department, \'\'))) = LOWER(TRIM(?))
            )
        )
        LIMIT 1';
    $st = $db->prepare($sql);
    $st->execute([$quizId, $userLevel, $userDepartment, $userLevel, $userDepartment]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    return trytest_quiz_level_visible_to_student(isset($row['level']) ? (string) $row['level'] : null, $userLevel);
}

/**
 * After sign-in: dashboard, or quiz if a share link was pending and access is allowed.
 */
function trytest_student_post_login_redirect_url(PDO $db): string
{
    $qid = (int) ($_SESSION['pending_shared_quiz_id'] ?? 0);
    if ($qid < 1) {
        return trytest_url('dashboard');
    }
    $level = trim((string) ($_SESSION['user_level'] ?? ''));
    $dept = trim((string) ($_SESSION['user_department'] ?? ''));
    unset($_SESSION['pending_shared_quiz_id']);
    if (trytest_student_can_access_quiz($db, $qid, $level, $dept)) {
        return trytest_url('quiz?quiz_id=' . $qid);
    }

    return trytest_url('dashboard');
}

function trytest_render_quiz_podium_html(array $board, int $userId, callable $h): string
{
    ob_start();
    trytest_render_podium_inner($board, $userId, $h, true);
    return ob_get_clean() ?: '';
}

function trytest_render_level_podium_html(array $rows, int $userId, callable $h): string
{
    $norm = [];
    foreach ($rows as $r) {
        $norm[] = [
            'user_id' => (int) ($r['user_id'] ?? 0),
            'index_number' => (string) ($r['index_number'] ?? ''),
            'department' => (string) ($r['department'] ?? ''),
            'best_score' => (int) ($r['total_points'] ?? 0),
            'best_total' => 0,
        ];
    }
    ob_start();
    trytest_render_podium_inner($norm, $userId, $h, false);
    return ob_get_clean() ?: '';
}

function trytest_render_podium_inner(array $rows, int $userId, callable $h, bool $showFraction): void
{
    if (!$rows) {
        echo '<p class="py-6 text-center text-sm text-slate-500 dark:text-zinc-400">No rankings yet. Finish a quiz to appear here.</p>';
        return;
    }
    $top = array_slice($rows, 0, 3);
    $second = $top[1] ?? null;
    $first = $top[0] ?? null;
    $third = $top[2] ?? null;
    ?>
    <div class="flex items-end justify-center gap-2 pt-4 pb-2">
        <?php trytest_podium_slot($second, 2, $userId, $h, 'h-24 w-[30%]', 'bg-slate-100/90 dark:bg-zinc-800/90 dark:ring-1 dark:ring-white/[0.06]', false, $showFraction); ?>
        <?php trytest_podium_slot($first, 1, $userId, $h, 'h-32 w-[34%]', 'bg-emerald-50 dark:bg-emerald-950/45 dark:ring-1 dark:ring-emerald-400/25', true, $showFraction); ?>
        <?php trytest_podium_slot($third, 3, $userId, $h, 'h-20 w-[30%]', 'bg-slate-100/90 dark:bg-zinc-800/90 dark:ring-1 dark:ring-white/[0.06]', false, $showFraction); ?>
    </div>
    <ul class="mt-4 max-h-64 space-y-1 overflow-y-auto rounded-xl bg-slate-50/80 p-2 dark:bg-zinc-900/80">
        <?php
        $i = 0;
        foreach ($rows as $row):
            $i++;
            if ($i <= 3) {
                continue;
            }
            $uid = (int) ($row['user_id'] ?? 0);
            $idx = (string) ($row['index_number'] ?? '');
            $sc = (int) ($row['best_score'] ?? 0);
            $tot = (int) ($row['best_total'] ?? 0);
            $isMe = $uid === $userId;
            ?>
            <li class="flex flex-nowrap items-center gap-2 rounded-lg px-2 py-1.5 <?php echo $isMe ? 'bg-emerald-100/60 dark:bg-emerald-950/40' : 'bg-white dark:bg-zinc-950'; ?>">
                <span class="w-5 shrink-0 text-center text-[10px] font-bold tabular-nums text-slate-500 dark:text-zinc-500"><?php echo $i; ?></span>
                <div class="h-8 w-8 shrink-0 overflow-hidden rounded-full bg-slate-100 dark:bg-zinc-800 [&>svg]:h-full [&>svg]:w-full"><?php echo trytest_student_avatar_svg($idx, 32, $uid); ?></div>
                <div class="min-w-0 flex-1 overflow-hidden">
                    <p class="truncate text-xs font-medium leading-tight dark:text-zinc-200"><?php echo $h(trytest_student_display_name($idx)); ?></p>
                </div>
                <span class="shrink-0 whitespace-nowrap text-xs font-bold tabular-nums text-[#2C6A7D] dark:text-[#7eb8b8]"><?php echo $sc; ?><?php echo $showFraction && $tot > 0 ? $h('/' . $tot) : ''; ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
}

function trytest_podium_slot(?array $slot, int $place, int $userId, callable $h, string $box, string $ring, bool $crown, bool $showFraction): void
{
    if (!$slot) {
        echo '<div class="' . $h($box) . ' flex flex-col items-center justify-end rounded-xl bg-slate-100/50 dark:bg-zinc-800/40"></div>';
        return;
    }
    $uid = (int) ($slot['user_id'] ?? 0);
    $idx = (string) ($slot['index_number'] ?? '');
    $sc = (int) ($slot['best_score'] ?? 0);
    $tot = (int) ($slot['best_total'] ?? 0);
    $isMe = $uid === $userId;
    $frac = $showFraction && $tot > 0 ? '/' . $tot : '';
    ?>
    <div class="<?php echo $h($box); ?> relative flex flex-col items-center">
        <?php if ($crown): ?><span class="absolute -top-4 left-1/2 z-10 -translate-x-1/2 text-lg leading-none drop-shadow-sm" aria-hidden="true">👑</span><?php endif; ?>
        <div class="flex w-full flex-1 flex-col items-center justify-end rounded-xl <?php echo $h($ring); ?> px-1 pb-2 pt-3">
            <div class="relative z-0 mb-1 h-12 w-12 overflow-hidden rounded-full bg-white/90 dark:bg-zinc-800/90 [&>svg]:h-full [&>svg]:w-full"><?php echo trytest_student_avatar_svg($idx, 48, $uid); ?></div>
            <p class="w-full truncate px-0.5 text-center text-[10px] font-bold leading-tight dark:text-zinc-100"><?php echo $h(trytest_student_display_name($idx)); ?></p>
            <p class="mt-1 whitespace-nowrap text-xs font-extrabold tabular-nums text-[#2C6A7D] dark:text-[#7eb8b8]"><?php echo $sc; ?><?php echo $h($frac); ?></p>
            <span class="mt-1 rounded-full bg-white/90 px-2 py-0.5 text-[9px] font-semibold text-slate-600 dark:bg-zinc-900/90 dark:text-zinc-300">#<?php echo $place; ?></span>
        </div>
    </div>
    <?php
}

/**
 * Convert HTML datetime-local value to stored SQL datetime string, or null if empty/invalid.
 */
function trytest_datetime_local_to_sql(?string $input): ?string
{
    $input = trim((string) ($input ?? ''));
    if ($input === '') {
        return null;
    }
    $normalized = str_replace('T', ' ', $input);
    $t = strtotime($normalized);
    if ($t === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $t);
}

/**
 * Value for datetime-local input from stored SQL datetime.
 */
function trytest_sql_datetime_to_datetime_local(?string $sql): string
{
    if ($sql === null || trim((string) $sql) === '') {
        return '';
    }
    $t = strtotime((string) $sql);
    return $t === false ? '' : date('Y-m-d\TH:i', $t);
}

/**
 * @return 'before'|'open'|'after'|'unset'
 */
function trytest_quiz_schedule_phase(?string $startsAtSql, ?string $endsAtSql, ?int $now = null): string
{
    $now = $now ?? time();
    $sRaw = $startsAtSql !== null ? trim((string) $startsAtSql) : '';
    $eRaw = $endsAtSql !== null ? trim((string) $endsAtSql) : '';
    $s = $sRaw !== '' ? strtotime($sRaw) : false;
    $e = $eRaw !== '' ? strtotime($eRaw) : false;
    if ($s === false) {
        $s = null;
    } else {
        $s = (int) $s;
    }
    if ($e === false) {
        $e = null;
    } else {
        $e = (int) $e;
    }
    if ($s === null && $e === null) {
        return 'unset';
    }
    if ($s !== null && $now < $s) {
        return 'before';
    }
    if ($e !== null && $now > $e) {
        return 'after';
    }
    return 'open';
}

/**
 * Quiz timer cap in seconds (same rules as quiz.php): min(duration, time until window end), or window-only when no per-quiz duration.
 */
function trytest_quiz_effective_duration_seconds(int $durationMinutes, ?string $quizEndsAtSql, ?int $now = null): int
{
    $now = $now ?? time();
    $durationSec = $durationMinutes > 0 ? $durationMinutes * 60 : 0;
    $endsRaw = $quizEndsAtSql !== null ? trim((string) $quizEndsAtSql) : '';
    $endsTs = null;
    if ($endsRaw !== '') {
        $endParsed = strtotime($endsRaw);
        if ($endParsed !== false) {
            $endsTs = (int) $endParsed;
        }
    }
    $untilEnd = ($endsTs !== null && $now < $endsTs) ? max(0, $endsTs - $now) : null;
    if ($durationSec > 0) {
        return $untilEnd !== null ? min($durationSec, $untilEnd) : $durationSec;
    }
    if ($untilEnd !== null && $untilEnd > 0) {
        return $untilEnd;
    }

    return 0;
}

function trytest_record_document_download(PDO $db, int $userId, int $documentId): void
{
    if ($userId < 1 || $documentId < 1) {
        return;
    }
    $st = $db->prepare(
        'INSERT OR REPLACE INTO student_document_downloads (user_id, document_id, downloaded_at) VALUES (?, ?, datetime(\'now\'))'
    );
    $st->execute([$userId, $documentId]);
}

/**
 * Eligible student documents the user has never downloaded (for nav badge).
 */
function trytest_student_downloads_pending_count(PDO $db, int $userId, string $userDepartment, string $userLevel): int
{
    if ($userId < 1) {
        return 0;
    }
    $vis = trytest_student_documents_visibility_sql('d', $userLevel, $userDepartment);
    $sql = 'SELECT COUNT(*) FROM student_documents d'
        . ' LEFT JOIN student_document_downloads x ON x.document_id = d.id AND x.user_id = ?'
        . ' WHERE ' . $vis['sql'] . ' AND x.document_id IS NULL';
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$userId], $vis['params']));

    return (int) $stmt->fetchColumn();
}

/**
 * Latest open/close times per quiz id for the student dashboard (same rules as course list).
 *
 * @return array<int, array{start: int|null, end: int|null}>
 */
function trytest_student_dashboard_quiz_schedule_map(PDO $db, string $userLevel, string $userDepartment): array
{
    $out = [];
    $userLevel = trim($userLevel);
    $userDepartment = trim($userDepartment);
    if ($userLevel === '' || $userDepartment === '') {
        return $out;
    }
    $courseSql = 'SELECT c.id, c.code, c.title, c.level, c.department FROM courses c WHERE c.level = ?';
    $courseParams = [$userLevel];
    $courseSql .= ' AND LOWER(TRIM(COALESCE(c.department, \'\'))) = LOWER(TRIM(?))';
    $courseParams[] = $userDepartment;
    $courseSql .= ' ORDER BY c.code ASC';
    $courseStmt = $db->prepare($courseSql);
    $courseStmt->execute($courseParams);
    $courses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($courses as $course) {
        $cid = (int) ($course['id'] ?? 0);
        if ($cid < 1) {
            continue;
        }
        $quizStmt = $db->prepare(
            'SELECT DISTINCT q.id, q.quiz_starts_at, q.quiz_ends_at, q.level AS quiz_level
             FROM quizzes q
             LEFT JOIN quiz_courses qc ON qc.quiz_id = q.id
             WHERE (q.course_id = ? OR qc.course_id = ?)
             ORDER BY q.id DESC'
        );
        $quizStmt->execute([$cid, $cid]);
        foreach ($quizStmt->fetchAll(PDO::FETCH_ASSOC) as $qz) {
            if (!trytest_quiz_level_visible_to_student(isset($qz['quiz_level']) ? (string) $qz['quiz_level'] : null, $userLevel)) {
                continue;
            }
            $qid = (int) ($qz['id'] ?? 0);
            if ($qid < 1) {
                continue;
            }
            $stRaw = trim((string) ($qz['quiz_starts_at'] ?? ''));
            $enRaw = trim((string) ($qz['quiz_ends_at'] ?? ''));
            $stTs = $stRaw !== '' ? strtotime($stRaw) : false;
            $enTs = $enRaw !== '' ? strtotime($enRaw) : false;
            $out[$qid] = [
                'start' => ($stTs !== false && $stTs > 0) ? (int) $stTs : null,
                'end' => ($enTs !== false && $enTs > 0) ? (int) $enTs : null,
            ];
        }
    }

    return $out;
}

/**
 * Flatten dashboard course rows into unique quizzes with schedule + attempt flags.
 *
 * @return list<array<string,mixed>>
 */
function trytest_student_encouragement_quiz_rows(array $coursesWithQuizzes, ?int $now = null): array
{
    $now = $now ?? time();
    $byId = [];
    foreach ($coursesWithQuizzes as $course) {
        $code = trim((string) ($course['code'] ?? ''));
        foreach ((array) ($course['quizzes'] ?? []) as $qz) {
            $qid = (int) ($qz['id'] ?? 0);
            if ($qid < 1) {
                continue;
            }
            $qcount = (int) ($qz['question_count'] ?? 0);
            if ($qcount < 1) {
                continue;
            }
            if (isset($byId[$qid])) {
                continue;
            }
            $stRaw = trim((string) ($qz['quiz_starts_at'] ?? ''));
            $enRaw = trim((string) ($qz['quiz_ends_at'] ?? ''));
            $stNull = $stRaw !== '' ? $stRaw : null;
            $enNull = $enRaw !== '' ? $enRaw : null;
            $phase = trytest_quiz_schedule_phase($stNull, $enNull, $now);
            $stTs = $stRaw !== '' ? strtotime($stRaw) : false;
            $enTs = $enRaw !== '' ? strtotime($enRaw) : false;
            $byId[$qid] = [
                'quiz_id' => $qid,
                'quiz_title' => trim((string) ($qz['title'] ?? '')) !== '' ? trim((string) ($qz['title'] ?? '')) : 'Quiz',
                'course_code' => $code,
                'user_has_attempt' => !empty($qz['user_has_attempt']),
                'phase' => $phase,
                'start_ts' => ($stTs !== false && $stTs > 0) ? (int) $stTs : null,
                'end_ts' => ($enTs !== false && $enTs > 0) ? (int) $enTs : null,
            ];
        }
    }

    return array_values($byId);
}

/**
 * Pick a quiz to anchor “next paper” encouragement (upcoming / open practice first).
 *
 * @param list<array<string,mixed>> $rows
 * @return array<string,mixed>|null
 */
function trytest_student_pick_next_paper_quiz(array $rows, ?int $now = null): ?array
{
    $now = $now ?? time();
    if ($rows === []) {
        return null;
    }
    $rank = static function (array $q) use ($now): array {
        $attempted = !empty($q['user_has_attempt']);
        $phase = (string) ($q['phase'] ?? 'unset');
        $start = $q['start_ts'] ?? null;
        $end = $q['end_ts'] ?? null;
        if (!$attempted && $phase === 'before') {
            $soon = $start !== null ? max(0, (int) $start - $now) : 86_400 * 365;

            return [0, $soon, (int) ($q['quiz_id'] ?? 0)];
        }
        if (!$attempted && $phase === 'open') {
            $urgent = $end !== null ? max(0, (int) $end - $now) : 86_400 * 365;

            return [1, $urgent, (int) ($q['quiz_id'] ?? 0)];
        }
        if (!$attempted && $phase === 'unset') {
            return [2, 0, (int) ($q['quiz_id'] ?? 0)];
        }
        if (!$attempted && $phase === 'after') {
            return [3, 0, (int) ($q['quiz_id'] ?? 0)];
        }
        if ($attempted && ($phase === 'open' || $phase === 'unset')) {
            return [4, 0, (int) ($q['quiz_id'] ?? 0)];
        }
        if ($attempted && $phase === 'before') {
            $soon = $start !== null ? max(0, (int) $start - $now) : 86_400 * 365;

            return [5, $soon, (int) ($q['quiz_id'] ?? 0)];
        }

        return [6, 0, (int) ($q['quiz_id'] ?? 0)];
    };
    usort($rows, static function (array $a, array $b) use ($rank): int {
        $ra = $rank($a);
        $rb = $rank($b);
        foreach ([0, 1, 2] as $i) {
            if ($ra[$i] !== $rb[$i]) {
                return $ra[$i] <=> $rb[$i];
            }
        }

        return 0;
    });

    return $rows[0] ?? null;
}

/**
 * Short dashboard note + solid background token (rotates ~every 10 minutes).
 *
 * @return array{lead:string,body:string,quiz_id:int,context:string,surface:string}
 */
function trytest_student_dashboard_encouragement(
    array $coursesWithQuizzes,
    int $userId,
    string $studentShortName,
    ?int $now = null
): array {
    $now = $now ?? time();
    $name = trim($studentShortName) !== '' ? trim($studentShortName) : 'there';
    $rows = trytest_student_encouragement_quiz_rows($coursesWithQuizzes, $now);
    $pick = trytest_student_pick_next_paper_quiz($rows, $now);
    $quizId = $pick !== null ? (int) ($pick['quiz_id'] ?? 0) : 0;
    $seed = (int) sprintf('%u', crc32((string) $userId . '|' . (string) intdiv($now, 600) . '|' . $quizId));

    /** Solid light fills (Trytest palette — no gradients). */
    $surfaces = ['#D8EFEF', '#FCE8E9', '#E2E8F0', '#EDE9D6'];
    $surface = $surfaces[$seed % count($surfaces)];

    $paper = $pick !== null ? (string) ($pick['quiz_title'] ?? 'Quiz') : '';
    if (strlen($paper) > 42) {
        $paper = substr($paper, 0, 39) . '…';
    }
    $phase = $pick !== null ? (string) ($pick['phase'] ?? 'unset') : '';
    $attempted = $pick !== null && !empty($pick['user_has_attempt']);
    $whenHint = '';
    if ($pick !== null && ($pick['start_ts'] ?? null) !== null && $now < (int) $pick['start_ts']) {
        $whenHint = 'Opens ' . date('M j', (int) $pick['start_ts']) . '.';
    } elseif ($pick !== null && ($pick['end_ts'] ?? null) !== null && $phase === 'open' && $now < (int) $pick['end_ts']) {
        $whenHint = 'Closes ' . date('M j', (int) $pick['end_ts']) . '.';
    }

    $leads = [
        'Hi, ' . $name . '.',
        'Hey, ' . $name . '.',
        'Ready, ' . $name . '?',
    ];
    $lead = $leads[$seed % count($leads)];

    if ($pick === null) {
        $bodies = [
            'Open Quizzes or Files when you are set.',
            'Your hub is below — one tap each.',
            'Pick Files or Quizzes to continue.',
        ];
        $body = $bodies[intdiv($seed, 2) % count($bodies)];
    } elseif ($attempted && ($phase === 'open' || $phase === 'unset')) {
        $body = 'Retry **PAPER** to improve your score.' . ($whenHint !== '' ? ' ' . $whenHint : '');
        $body = str_replace('**PAPER**', $paper, $body);
    } else {
        $body = 'Next: **PAPER**.' . ($whenHint !== '' ? ' ' . $whenHint : '');
        $body = str_replace('**PAPER**', $paper, $body);
    }

    $context = $pick === null ? 'no_quiz' : ($attempted ? 'retry' : 'fresh');

    return [
        'lead' => $lead,
        'body' => trim(preg_replace('/\s+/u', ' ', $body)),
        'quiz_id' => $quizId,
        'context' => $context,
        'surface' => $surface,
    ];
}
