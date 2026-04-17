<?php

declare(strict_types=1);

function trytest_student_display_name(string $indexNumber): string
{
    $t = trim($indexNumber);
    if ($t === '') {
        return 'Student';
    }
    $parts = preg_split('/[\/\\]+/', $t) ?: [];
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

/** @return list<array{user_id:int,index_number:string,department:string,best_score:int,best_total:int,first_best_at:string}> */
function trytest_quiz_leaderboard(PDO $db, int $quizId, int $limit = 40): array
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
        WHERE r.rn = 1
        ORDER BY r.score DESC, r.created_at ASC, u.index_number ASC
        LIMIT ' . $lim;

    $stmt = $db->prepare($sql);
    $stmt->execute([$quizId]);
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

/** @return list<array{user_id:int,index_number:string,department:string,total_points:int}> */
function trytest_level_leaderboard(PDO $db, string $level, int $limit = 40): array
{
    if ($level === '') {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT u.id AS user_id, u.index_number AS index_number, u.department AS department,
                COALESCE(SUM(s.score), 0) AS total_points
         FROM users u
         LEFT JOIN scores s ON s.user_id = u.id AND s.user_id IS NOT NULL
         WHERE u.level = ?
         GROUP BY u.id
         ORDER BY total_points DESC, u.index_number ASC
         LIMIT ' . max(1, min(100, $limit))
    );
    $stmt->execute([$level]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'user_id' => (int) ($r['user_id'] ?? 0),
            'index_number' => (string) ($r['index_number'] ?? ''),
            'department' => (string) ($r['department'] ?? ''),
            'total_points' => (int) ($r['total_points'] ?? 0),
        ];
    }
    return $out;
}

/**
 * Whether this student may open the quiz (same course/level/dept rules as the dashboard list).
 */
function trytest_student_can_access_quiz(PDO $db, int $quizId, string $userLevel, string $userDepartment): bool
{
    $quizId = max(0, $quizId);
    $userLevel = trim($userLevel);
    $userDepartment = trim($userDepartment);
    if ($quizId < 1 || $userLevel === '') {
        return false;
    }

    if ($userDepartment === '') {
        $sql = 'SELECT 1 FROM quizzes q WHERE q.id = ?
            AND (q.level IS NULL OR TRIM(COALESCE(q.level, \'\')) = \'\' OR q.level = ?)
            AND (
                EXISTS (SELECT 1 FROM courses c WHERE c.id = q.course_id AND c.level = ?)
                OR EXISTS (
                    SELECT 1 FROM quiz_courses qc
                    INNER JOIN courses c ON c.id = qc.course_id
                    WHERE qc.quiz_id = q.id AND c.level = ?
                )
            )
            LIMIT 1';
        $st = $db->prepare($sql);
        $st->execute([$quizId, $userLevel, $userLevel, $userLevel]);

        return (bool) $st->fetchColumn();
    }

    $sql = 'SELECT 1 FROM quizzes q WHERE q.id = ?
        AND (q.level IS NULL OR TRIM(COALESCE(q.level, \'\')) = \'\' OR q.level = ?)
        AND (
            EXISTS (
                SELECT 1 FROM courses c
                WHERE c.id = q.course_id AND c.level = ?
                AND (TRIM(COALESCE(c.department, \'\')) = \'\' OR LOWER(TRIM(c.department)) = LOWER(?))
            )
            OR EXISTS (
                SELECT 1 FROM quiz_courses qc
                INNER JOIN courses c ON c.id = qc.course_id
                WHERE qc.quiz_id = q.id AND c.level = ?
                AND (TRIM(COALESCE(c.department, \'\')) = \'\' OR LOWER(TRIM(c.department)) = LOWER(?))
            )
        )
        LIMIT 1';
    $st = $db->prepare($sql);
    $st->execute([$quizId, $userLevel, $userLevel, $userDepartment, $userLevel, $userDepartment]);

    return (bool) $st->fetchColumn();
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
        echo '<p class="py-6 text-center text-sm text-slate-500">No rankings yet. Finish a quiz to appear here.</p>';
        return;
    }
    $top = array_slice($rows, 0, 3);
    $second = $top[1] ?? null;
    $first = $top[0] ?? null;
    $third = $top[2] ?? null;
    ?>
    <div class="flex items-end justify-center gap-2 pt-4 pb-2">
        <?php trytest_podium_slot($second, 2, $userId, $h, 'h-24 w-[30%]', 'bg-slate-100/90', false, $showFraction); ?>
        <?php trytest_podium_slot($first, 1, $userId, $h, 'h-32 w-[34%]', 'bg-emerald-50', true, $showFraction); ?>
        <?php trytest_podium_slot($third, 3, $userId, $h, 'h-20 w-[30%]', 'bg-slate-100/90', false, $showFraction); ?>
    </div>
    <ul class="mt-4 max-h-64 space-y-1 overflow-y-auto rounded-xl bg-slate-50/80 p-2">
        <?php
        $i = 0;
        foreach ($rows as $row):
            $i++;
            if ($i <= 3) {
                continue;
            }
            $uid = (int) ($row['user_id'] ?? 0);
            $idx = (string) ($row['index_number'] ?? '');
            $dept = trim((string) ($row['department'] ?? ''));
            $sc = (int) ($row['best_score'] ?? 0);
            $tot = (int) ($row['best_total'] ?? 0);
            $isMe = $uid === $userId;
            ?>
            <li class="flex flex-nowrap items-center gap-2 rounded-lg px-2 py-1.5 <?php echo $isMe ? 'bg-emerald-100/60' : 'bg-white'; ?>">
                <span class="w-5 shrink-0 text-center text-[10px] font-bold tabular-nums text-slate-500"><?php echo $i; ?></span>
                <div class="h-8 w-8 shrink-0 overflow-hidden rounded-full bg-slate-100 [&>svg]:h-full [&>svg]:w-full"><?php echo trytest_student_avatar_svg($idx, 32, $uid); ?></div>
                <div class="min-w-0 flex-1 overflow-hidden">
                    <p class="truncate text-xs font-medium leading-tight"><?php echo $h(trytest_student_display_name($idx)); ?><?php if ($dept !== ''): ?><span class="text-slate-400"> · </span><span class="text-[10px] text-slate-500"><?php echo $h($dept); ?></span><?php endif; ?></p>
                </div>
                <span class="shrink-0 whitespace-nowrap text-xs font-bold tabular-nums text-[#2C6A7D]"><?php echo $sc; ?><?php echo $showFraction && $tot > 0 ? $h('/' . $tot) : ''; ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
}

function trytest_podium_slot(?array $slot, int $place, int $userId, callable $h, string $box, string $ring, bool $crown, bool $showFraction): void
{
    if (!$slot) {
        echo '<div class="' . $h($box) . ' flex flex-col items-center justify-end rounded-xl bg-slate-100/50"></div>';
        return;
    }
    $uid = (int) ($slot['user_id'] ?? 0);
    $idx = (string) ($slot['index_number'] ?? '');
    $dept = trim((string) ($slot['department'] ?? ''));
    $sc = (int) ($slot['best_score'] ?? 0);
    $tot = (int) ($slot['best_total'] ?? 0);
    $isMe = $uid === $userId;
    $frac = $showFraction && $tot > 0 ? '/' . $tot : '';
    ?>
    <div class="<?php echo $h($box); ?> relative flex flex-col items-center">
        <?php if ($crown): ?><span class="absolute -top-4 left-1/2 z-10 -translate-x-1/2 text-lg leading-none drop-shadow-sm" aria-hidden="true">👑</span><?php endif; ?>
        <div class="flex w-full flex-1 flex-col items-center justify-end rounded-xl <?php echo $h($ring); ?> px-1 pb-2 pt-3">
            <div class="relative z-0 mb-1 h-12 w-12 overflow-hidden rounded-full bg-white/90 [&>svg]:h-full [&>svg]:w-full"><?php echo trytest_student_avatar_svg($idx, 48, $uid); ?></div>
            <p class="w-full truncate px-0.5 text-center text-[10px] font-bold leading-tight"><?php echo $h(trytest_student_display_name($idx)); ?></p>
            <?php if ($dept !== ''): ?><p class="w-full truncate px-0.5 text-center text-[8px] text-slate-600"><?php echo $h($dept); ?></p><?php endif; ?>
            <p class="mt-1 whitespace-nowrap text-xs font-extrabold tabular-nums text-[#2C6A7D]"><?php echo $sc; ?><?php echo $h($frac); ?></p>
            <span class="mt-1 rounded-full bg-white/90 px-2 py-0.5 text-[9px] font-semibold text-slate-600">#<?php echo $place; ?></span>
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
    $stmt = $db->query('SELECT id, department, level FROM student_documents');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $eligibleIds = [];
    foreach ($rows as $d) {
        $dd = trim((string) ($d['department'] ?? ''));
        $dl = trim((string) ($d['level'] ?? ''));
        if (!trytest_student_document_eligible($userDepartment, $userLevel, $dd, $dl)) {
            continue;
        }
        $eligibleIds[] = (int) ($d['id'] ?? 0);
    }
    $eligibleIds = array_values(array_filter($eligibleIds, static fn (int $id): bool => $id > 0));
    if ($eligibleIds === []) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($eligibleIds), '?'));
    $chk = $db->prepare(
        'SELECT document_id FROM student_document_downloads WHERE user_id = ? AND document_id IN (' . $placeholders . ')'
    );
    $chk->execute(array_merge([$userId], $eligibleIds));
    $done = [];
    foreach ($chk->fetchAll(PDO::FETCH_COLUMN) as $di) {
        $done[(int) $di] = true;
    }
    $c = 0;
    foreach ($eligibleIds as $id) {
        if (empty($done[$id])) {
            $c++;
        }
    }
    return $c;
}

/**
 * Latest open/close times per quiz id for the student dashboard (same rules as course list).
 *
 * @return array<int, array{start: int|null, end: int|null}>
 */
function trytest_student_dashboard_quiz_schedule_map(PDO $db, string $userLevel, string $userDepartment): array
{
    $out = [];
    $courseSql = 'SELECT c.id, c.code, c.title, c.level, c.department FROM courses c WHERE c.level = ?';
    $courseParams = [$userLevel];
    if ($userDepartment !== '') {
        $courseSql .= ' AND (TRIM(COALESCE(c.department, \'\')) = \'\' OR LOWER(TRIM(c.department)) = LOWER(?))';
        $courseParams[] = $userDepartment;
    }
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
            'SELECT DISTINCT q.id, q.quiz_starts_at, q.quiz_ends_at
             FROM quizzes q
             LEFT JOIN quiz_courses qc ON qc.quiz_id = q.id
             WHERE (q.course_id = ? OR qc.course_id = ?)
               AND (q.level IS NULL OR q.level = ?)
             ORDER BY q.id DESC'
        );
        $quizStmt->execute([$cid, $cid, $userLevel]);
        foreach ($quizStmt->fetchAll(PDO::FETCH_ASSOC) as $qz) {
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
