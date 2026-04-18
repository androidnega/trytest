<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config/db.php';

if (empty($_SESSION['is_admin'])) {
    trytest_redirect(trytest_url('admin'));
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete_user') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('DELETE FROM scores WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $message = 'User deleted.';
        }
    }
}

$users = $db->query(
    'SELECT
        u.id,
        u.index_number,
        u.level,
        u.department,
        u.last_login_at,
        COUNT(s.id) AS attempts,
        COUNT(DISTINCT s.quiz_id) AS quizzes_taken,
        COALESCE(SUM(s.score), 0) AS total_points,
        COALESCE(ROUND(AVG(CASE WHEN s.total > 0 THEN (100.0 * s.score / s.total) END), 1), 0) AS avg_percent,
        COALESCE(ROUND(MAX(CASE WHEN s.total > 0 THEN (100.0 * s.score / s.total) END), 1), 0) AS best_percent,
        MAX(s.created_at) AS last_attempt_at,
        (
            SELECT q.title
            FROM scores s2
            LEFT JOIN quizzes q ON q.id = s2.quiz_id
            WHERE s2.user_id = u.id
            ORDER BY s2.id DESC
            LIMIT 1
        ) AS last_quiz_title,
        (
            SELECT s3.score
            FROM scores s3
            WHERE s3.user_id = u.id
            ORDER BY s3.id DESC
            LIMIT 1
        ) AS last_score,
        (
            SELECT s4.total
            FROM scores s4
            WHERE s4.user_id = u.id
            ORDER BY s4.id DESC
            LIMIT 1
        ) AS last_total
     FROM users u
     LEFT JOIN scores s ON s.user_id = u.id
     GROUP BY u.id
     ORDER BY u.id DESC'
)->fetchAll();

$h = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};

$rowsPayload = [];
foreach ($users as $user) {
    $attempts = (int) ($user['attempts'] ?? 0);
    $quizzesTaken = (int) ($user['quizzes_taken'] ?? 0);
    $totalPoints = (int) ($user['total_points'] ?? 0);
    $avgPercent = (float) ($user['avg_percent'] ?? 0);
    $bestPercent = (float) ($user['best_percent'] ?? 0);
    $lastScore = isset($user['last_score']) ? (int) $user['last_score'] : null;
    $lastTotal = isset($user['last_total']) ? (int) $user['last_total'] : null;
    $lastQuizTitle = trim((string) ($user['last_quiz_title'] ?? ''));
    $dept = trim((string) ($user['department'] ?? ''));

    $searchBlob = implode(' ', [
        (string) $user['index_number'],
        (string) $user['level'],
        $dept,
        (string) $totalPoints,
        (string) $attempts,
        (string) $quizzesTaken,
        (string) $avgPercent,
        (string) $bestPercent,
        $lastQuizTitle,
        (string) ($user['last_login_at'] ?? ''),
        (string) ($user['last_attempt_at'] ?? ''),
    ]);
    $searchHaystack = function_exists('mb_strtolower')
        ? mb_strtolower($searchBlob, 'UTF-8')
        : strtolower($searchBlob);

    $rowsPayload[] = [
        'id' => (int) $user['id'],
        'index_number' => (string) $user['index_number'],
        'level' => (string) $user['level'],
        'department' => $dept,
        'attempts' => $attempts,
        'quizzes_taken' => $quizzesTaken,
        'total_points' => $totalPoints,
        'avg_percent' => $avgPercent,
        'best_percent' => $bestPercent,
        'last_quiz_title' => $lastQuizTitle,
        'last_score' => $lastScore,
        'last_total' => $lastTotal,
        'last_login_at' => (string) ($user['last_login_at'] ?? ''),
        'last_attempt_at' => (string) ($user['last_attempt_at'] ?? ''),
        '_search' => $searchHaystack,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest — Manage Users</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 p-4 text-slate-900 antialiased">
    <div class="mx-auto max-w-3xl space-y-4 py-6">
        <header class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-4">
            <div>
                <h1 class="text-lg font-semibold tracking-tight text-slate-900">Students</h1>
                <p class="text-xs text-slate-500"><?php echo count($rowsPayload); ?> account<?php echo count($rowsPayload) === 1 ? '' : 's'; ?> · click a row for details</p>
            </div>
            <a href="<?php echo $h(trytest_url('dashboard/manage_admin')); ?>" class="text-sm font-medium text-slate-600 underline-offset-2 hover:text-slate-900 hover:underline">Back</a>
        </header>

        <?php if ($message !== ''): ?>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"><?php echo $h($message); ?></div>
        <?php endif; ?>

        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <?php if ($rowsPayload === []): ?>
                <p class="px-4 py-10 text-center text-sm text-slate-500">No students yet.</p>
            <?php else: ?>
                <div class="border-b border-slate-100 px-4 py-3">
                    <label for="studentSearch" class="sr-only">Search students</label>
                    <input
                        type="search"
                        id="studentSearch"
                        class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-slate-300 focus:outline-none focus:ring-2 focus:ring-slate-200"
                        placeholder="Search index, level, program, points, dates…"
                        autocomplete="off"
                        spellcheck="false"
                    >
                    <p id="studentSearchMeta" class="mt-1.5 text-xs text-slate-500" aria-live="polite"></p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[20rem] text-left text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Index</th>
                                <th class="hidden px-4 py-3 sm:table-cell">Level</th>
                                <th class="hidden px-4 py-3 md:table-cell">Program</th>
                                <th class="px-4 py-3 text-right">Points</th>
                            </tr>
                        </thead>
                        <tbody id="studentTbody" class="divide-y divide-slate-100">
                            <?php foreach ($rowsPayload as $row): ?>
                                <?php
                                $rowForJson = $row;
                                unset($rowForJson['_search']);
                                $searchAttr = (string) ($row['_search'] ?? '');
                                ?>
                                <tr
                                    class="cursor-pointer transition hover:bg-slate-50 focus-within:bg-slate-50"
                                    tabindex="0"
                                    role="button"
                                    data-user="<?php echo $h(base64_encode(json_encode($rowForJson, JSON_THROW_ON_ERROR))); ?>"
                                    data-search="<?php echo $h($searchAttr); ?>"
                                >
                                    <td class="px-4 py-3 font-medium text-slate-900"><?php echo $h($row['index_number']); ?></td>
                                    <td class="hidden px-4 py-3 text-slate-600 sm:table-cell"><?php echo $h($row['level']); ?></td>
                                    <td class="hidden max-w-[10rem] truncate px-4 py-3 text-slate-600 md:table-cell"><?php echo $row['department'] !== '' ? $h($row['department']) : '—'; ?></td>
                                    <td class="px-4 py-3 text-right tabular-nums text-slate-700"><?php echo (int) $row['total_points']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="studentSearchEmpty" class="hidden">
                                <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500">No students match your search.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div id="userModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4" aria-hidden="true">
        <div id="userModalBackdrop" class="absolute inset-0 bg-slate-900/40" tabindex="-1"></div>
        <div
            class="relative z-10 w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl"
            role="dialog"
            aria-modal="true"
            aria-labelledby="userModalTitle"
        >
            <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">
                <div class="min-w-0">
                    <h2 id="userModalTitle" class="truncate text-base font-semibold text-slate-900"></h2>
                    <p id="userModalSub" class="mt-0.5 text-xs text-slate-500"></p>
                </div>
                <button type="button" id="userModalClose" class="shrink-0 rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800" aria-label="Close">&times;</button>
            </div>
            <div class="max-h-[min(70vh,28rem)] overflow-y-auto px-4 py-3">
                <dl id="userModalDl" class="grid grid-cols-1 gap-3 text-sm"></dl>
            </div>
            <div class="border-t border-slate-100 bg-slate-50 px-4 py-3">
                <form method="post" id="userModalDeleteForm" class="flex flex-wrap items-center justify-between gap-2">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="id" id="userModalDeleteId" value="">
                    <p class="text-xs text-slate-500">Deleting removes all scores for this student.</p>
                    <button type="submit" class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">Delete user</button>
                </form>
            </div>
        </div>
    </div>

    <script>
(function () {
    var modal = document.getElementById('userModal');
    var backdrop = document.getElementById('userModalBackdrop');
    var closeBtn = document.getElementById('userModalClose');
    var titleEl = document.getElementById('userModalTitle');
    var subEl = document.getElementById('userModalSub');
    var dlEl = document.getElementById('userModalDl');
    var deleteId = document.getElementById('userModalDeleteId');
    var searchInput = document.getElementById('studentSearch');
    var studentTbody = document.getElementById('studentTbody');
    var searchMeta = document.getElementById('studentSearchMeta');
    var searchEmpty = document.getElementById('studentSearchEmpty');
    if (!modal || !backdrop || !closeBtn || !titleEl || !subEl || !dlEl || !deleteId) return;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function rowLabel(k) {
        var m = {
            attempts: 'Attempts',
            quizzes_taken: 'Quizzes taken',
            total_points: 'Total points',
            avg_percent: 'Average score %',
            best_percent: 'Best score %',
            last_quiz_title: 'Last quiz',
            last_score_line: 'Last score',
            last_login_at: 'Last login',
            last_attempt_at: 'Last attempt'
        };
        return m[k] || k;
    }

    function openModal(u) {
        titleEl.textContent = u.index_number || 'Student';
        var dept = u.department ? ' · ' + u.department : '';
        subEl.textContent = 'Level ' + (u.level || '—') + dept;

        var lastScoreLine = '—';
        if (u.last_score != null && u.last_total != null) {
            lastScoreLine = u.last_score + '/' + u.last_total;
        }
        var pairs = [
            ['attempts', u.attempts],
            ['quizzes_taken', u.quizzes_taken],
            ['total_points', u.total_points],
            ['avg_percent', typeof u.avg_percent === 'number' ? u.avg_percent.toFixed(1) : u.avg_percent],
            ['best_percent', typeof u.best_percent === 'number' ? u.best_percent.toFixed(1) : u.best_percent],
            ['last_quiz_title', u.last_quiz_title || '—'],
            ['last_score_line', lastScoreLine],
            ['last_login_at', u.last_login_at || '—'],
            ['last_attempt_at', u.last_attempt_at || '—']
        ];
        dlEl.innerHTML = pairs.map(function (p) {
            return '<div class="flex flex-col gap-0.5 rounded-lg border border-slate-100 bg-slate-50/80 px-3 py-2">'
                + '<dt class="text-[11px] font-medium uppercase tracking-wide text-slate-500">' + esc(rowLabel(p[0])) + '</dt>'
                + '<dd class="font-medium text-slate-900">' + esc(p[1]) + '</dd></div>';
        }).join('');

        deleteId.value = String(u.id);
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        modal.setAttribute('aria-hidden', 'false');
        closeBtn.focus();
    }

    function closeModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        modal.setAttribute('aria-hidden', 'true');
    }

    function onRowActivate(tr) {
        var b64 = tr.getAttribute('data-user');
        if (!b64) return;
        try {
            var json = atob(b64);
            openModal(JSON.parse(json));
        } catch (e) {}
    }

    function applyStudentSearch() {
        if (!studentTbody || !searchMeta) return;
        var q = (searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
        var rows = studentTbody.querySelectorAll('tr[data-user]');
        var n = 0;
        for (var i = 0; i < rows.length; i++) {
            var tr = rows[i];
            var hay = tr.getAttribute('data-search') || '';
            var ok = !q || hay.indexOf(q) !== -1;
            tr.classList.toggle('hidden', !ok);
            if (ok) n++;
        }
        if (searchEmpty) searchEmpty.classList.toggle('hidden', n !== 0);
        if (rows.length === 0) {
            searchMeta.textContent = '';
        } else if (!q) {
            searchMeta.textContent = 'Showing all ' + rows.length + ' student' + (rows.length === 1 ? '' : 's');
        } else {
            searchMeta.textContent = n === 0
                ? 'No matches'
                : ('Showing ' + n + ' of ' + rows.length);
        }
    }

    if (searchInput && studentTbody) {
        searchInput.addEventListener('input', applyStudentSearch);
        applyStudentSearch();
    }

    document.querySelectorAll('#studentTbody tr[data-user]').forEach(function (tr) {
        tr.addEventListener('click', function () { onRowActivate(tr); });
        tr.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                onRowActivate(tr);
            }
        });
    });

    closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
    });

    document.getElementById('userModalDeleteForm').addEventListener('submit', function (e) {
        if (!confirm('Delete this user and all their scores?')) e.preventDefault();
    });
})();
    </script>
</body>
</html>
