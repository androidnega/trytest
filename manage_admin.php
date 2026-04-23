<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/youtube_subscribe.php';
require_once __DIR__ . '/includes/departments.php';
require_once __DIR__ . '/includes/levels.php';
require_once __DIR__ . '/includes/trytest_presence.php';

if (empty($_SESSION['is_admin'])) {
    trytest_redirect(trytest_url('admin'));
}

$courseCount = (int) $db->query('SELECT COUNT(*) FROM courses')->fetchColumn();
$departmentDropdownCount = trytest_department_dropdown_option_count($db);
$levelPresetCount = (int) $db->query('SELECT COUNT(*) FROM levels')->fetchColumn();
$quizCount = (int) $db->query('SELECT COUNT(*) FROM quizzes')->fetchColumn();
$userCount = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$questionCount = (int) $db->query('SELECT COUNT(*) FROM questions')->fetchColumn();
$yt = trytest_youtube_settings();
$ytLabel = $yt['gate_active'] ? 'On' : 'Off';
$feedbackCount = (int) $db->query('SELECT COUNT(*) FROM student_system_feedback')->fetchColumn();
$presenceWs = trytest_presence_ws_url();
$liveQuizCount = trytest_presence_live_student_count($db);
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php trytest_link_preview_meta(['title' => 'Trytest — Admin Manager', 'description' => 'Trytest admin: overview and tools.']); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest — Admin Manager</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo $h(trytest_url('favicon.svg')); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body { font-family: Inter, ui-sans-serif, system-ui, sans-serif; }</style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
        <header class="mb-8 border-b border-slate-200 pb-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-indigo-600">Trytest</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">Admin manager</h1>
                    <p class="mt-2 max-w-xl text-sm text-slate-600">Open a section below. Layout is tuned for desktop: clear hierarchy, readable type, and consistent spacing.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <a href="<?php echo $h(trytest_url('dashboard/change_admin_password')); ?>" class="rounded-lg border border-slate-200 bg-white px-3 py-2 font-medium text-slate-700 shadow-sm hover:bg-slate-50">Password</a>
                    <a href="<?php echo $h(trytest_home_url()); ?>" class="rounded-lg bg-slate-900 px-3 py-2 font-medium text-white hover:bg-slate-800">← Control center</a>
                </div>
            </div>
            <dl class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Live in quiz</dt>
                    <dd class="mt-1 flex items-baseline gap-2">
                        <span id="trytestLiveQuizCount" class="text-2xl font-bold tabular-nums text-emerald-700"><?php echo (int) $liveQuizCount; ?></span>
                        <span class="text-xs text-slate-500">students</span>
                    </dd>
                    <p id="trytestLiveQuizSource" class="mt-1 text-[11px] text-slate-400"><?php echo $presenceWs !== '' ? 'WebSocket + pings' : 'Live pings (SSE)'; ?></p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Feedback</dt>
                    <dd class="mt-1 text-2xl font-bold tabular-nums text-slate-900"><?php echo $feedbackCount; ?></dd>
                    <dd class="mt-1"><a class="text-xs font-medium text-indigo-600 hover:underline" href="<?php echo $h(trytest_url('dashboard/manage_feedback')); ?>">View ratings</a></dd>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">YouTube gate</dt>
                    <dd class="mt-1 text-sm font-semibold <?php echo $yt['gate_active'] ? 'text-emerald-700' : 'text-slate-600'; ?>"><?php echo $h($ytLabel); ?></dd>
                </div>
            </dl>
        </header>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
            <a href="<?php echo $h(trytest_url('dashboard/manage_feedback')); ?>" class="group flex min-h-0 items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-4 shadow-sm transition hover:border-indigo-200 hover:shadow md:min-h-[5.25rem]">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-700">
                    <i class="fa-solid fa-comment-dots text-sm"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="truncate text-sm font-semibold text-slate-900">Student feedback</h2>
                        <span class="shrink-0 rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold tabular-nums text-slate-600"><?php echo $feedbackCount; ?></span>
                    </div>
                    <p class="mt-0.5 truncate text-xs text-slate-500">Star ratings from the home dashboard</p>
                </div>
                <i class="fa-solid fa-chevron-right shrink-0 text-xs text-slate-300 group-hover:text-slate-400" aria-hidden="true"></i>
            </a>
            <a href="<?php echo $h(trytest_url('dashboard/manage_courses')); ?>" class="group flex min-h-0 items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-4 shadow-sm transition hover:border-slate-300 hover:bg-slate-50/90 md:min-h-[5.25rem]">
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                    <i class="fa-solid fa-book-open text-xs"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="truncate text-sm font-semibold text-slate-900">Courses</h2>
                        <span class="shrink-0 rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-slate-600"><?php echo $courseCount; ?></span>
                    </div>
                    <p class="truncate text-[11px] leading-tight text-slate-500">Programs &amp; departments</p>
                </div>
                <i class="fa-solid fa-chevron-right shrink-0 text-[10px] text-slate-300 group-hover:text-slate-400" aria-hidden="true"></i>
            </a>
            <a href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_departments'), ENT_QUOTES, 'UTF-8'); ?>" class="group flex min-h-0 items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3 py-2.5 transition hover:border-slate-300 hover:bg-slate-50/90">
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-cyan-50 text-cyan-700">
                    <i class="fa-solid fa-building-columns text-xs"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="truncate text-sm font-semibold text-slate-900">Departments</h2>
                        <span class="shrink-0 rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-slate-600"><?php echo $departmentDropdownCount; ?></span>
                    </div>
                    <p class="truncate text-[11px] leading-tight text-slate-500">Dropdown list for students &amp; PDFs</p>
                </div>
                <i class="fa-solid fa-chevron-right shrink-0 text-[10px] text-slate-300 group-hover:text-slate-400" aria-hidden="true"></i>
            </a>
            <a href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_levels'), ENT_QUOTES, 'UTF-8'); ?>" class="group flex min-h-0 items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3 py-2.5 transition hover:border-slate-300 hover:bg-slate-50/90">
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-700">
                    <i class="fa-solid fa-layer-group text-xs"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="truncate text-sm font-semibold text-slate-900">Levels</h2>
                        <span class="shrink-0 rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-slate-600"><?php echo $levelPresetCount; ?></span>
                    </div>
                    <p class="truncate text-[11px] leading-tight text-slate-500">Shared dropdown (100–400 + custom)</p>
                </div>
                <i class="fa-solid fa-chevron-right shrink-0 text-[10px] text-slate-300 group-hover:text-slate-400" aria-hidden="true"></i>
            </a>
            <a href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_quizzes'), ENT_QUOTES, 'UTF-8'); ?>" class="group flex min-h-0 items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3 py-2.5 transition hover:border-slate-300 hover:bg-slate-50/90">
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                    <i class="fa-solid fa-clipboard-question text-xs"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="truncate text-sm font-semibold text-slate-900">Quizzes</h2>
                        <span class="shrink-0 rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-slate-600"><?php echo $quizCount; ?></span>
                    </div>
                    <p class="truncate text-[11px] leading-tight text-slate-500">Tests &amp; schedules</p>
                </div>
                <i class="fa-solid fa-chevron-right shrink-0 text-[10px] text-slate-300 group-hover:text-slate-400" aria-hidden="true"></i>
            </a>
            <a href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_users'), ENT_QUOTES, 'UTF-8'); ?>" class="group flex min-h-0 items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3 py-2.5 transition hover:border-slate-300 hover:bg-slate-50/90">
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-violet-50 text-violet-600">
                    <i class="fa-solid fa-users text-xs"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="truncate text-sm font-semibold text-slate-900">Users</h2>
                        <span class="shrink-0 rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-slate-600"><?php echo $userCount; ?></span>
                    </div>
                    <p class="truncate text-[11px] leading-tight text-slate-500">Student accounts</p>
                </div>
                <i class="fa-solid fa-chevron-right shrink-0 text-[10px] text-slate-300 group-hover:text-slate-400" aria-hidden="true"></i>
            </a>
            <a href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_questions'), ENT_QUOTES, 'UTF-8'); ?>" class="group flex min-h-0 items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3 py-2.5 transition hover:border-slate-300 hover:bg-slate-50/90">
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
                    <i class="fa-solid fa-circle-check text-xs"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="truncate text-sm font-semibold text-slate-900">Questions</h2>
                        <span class="shrink-0 rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-slate-600"><?php echo $questionCount; ?></span>
                    </div>
                    <p class="truncate text-[11px] leading-tight text-slate-500">Review, approve, export</p>
                </div>
                <i class="fa-solid fa-chevron-right shrink-0 text-[10px] text-slate-300 group-hover:text-slate-400" aria-hidden="true"></i>
            </a>
            <a href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_resources'), ENT_QUOTES, 'UTF-8'); ?>" class="group flex min-h-0 items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3 py-2.5 transition hover:border-slate-300 hover:bg-slate-50/90">
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-rose-600">
                    <i class="fa-solid fa-file-pdf text-xs"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="truncate text-sm font-semibold text-slate-900">Student PDFs</h2>
                    </div>
                    <p class="truncate text-[11px] leading-tight text-slate-500">Uploads by program &amp; level</p>
                </div>
                <i class="fa-solid fa-chevron-right shrink-0 text-[10px] text-slate-300 group-hover:text-slate-400" aria-hidden="true"></i>
            </a>
            <a href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_youtube'), ENT_QUOTES, 'UTF-8'); ?>" class="group flex min-h-0 items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3 py-2.5 transition hover:border-slate-300 hover:bg-slate-50/90">
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-red-50 text-red-600">
                    <i class="fa-brands fa-youtube text-xs"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="truncate text-sm font-semibold text-slate-900">YouTube gate</h2>
                        <span class="shrink-0 rounded-md px-1.5 py-0.5 text-[10px] font-semibold tabular-nums <?php echo $yt['gate_active'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'; ?>"><?php echo htmlspecialchars($ytLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <p class="truncate text-[11px] leading-tight text-slate-500">API keys &amp; PDF download lock</p>
                </div>
                <i class="fa-solid fa-chevron-right shrink-0 text-[10px] text-slate-300 group-hover:text-slate-400" aria-hidden="true"></i>
            </a>
        </div>
    </div>
    <script>
    window.TRYTEST_WEB_BASE = <?php echo json_encode(trytest_base_path(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); ?>;
    window.TRYTEST_PRESENCE_WS = <?php echo json_encode($presenceWs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script>
    (function () {
        function prefix() {
            var b = typeof window.TRYTEST_WEB_BASE === 'string' ? window.TRYTEST_WEB_BASE : '';
            return b.replace(/\/+$/, '');
        }
        function absPath(path) {
            var b = prefix();
            path = String(path || '').replace(/^\//, '');
            if (!b) return '/' + path;
            return path === '' ? b : b + '/' + path;
        }
        var el = document.getElementById('trytestLiveQuizCount');
        var srcEl = document.getElementById('trytestLiveQuizSource');
        if (!el) return;
        function setCount(n) {
            el.textContent = String(n);
        }
        var wsUrl = typeof window.TRYTEST_PRESENCE_WS === 'string' ? window.TRYTEST_PRESENCE_WS.trim() : '';
        if (wsUrl) {
            if (srcEl) srcEl.textContent = 'WebSocket (open quiz tabs)';
            try {
                var ws = new WebSocket(wsUrl);
                ws.addEventListener('open', function () {
                    ws.send(JSON.stringify({ type: 'admin' }));
                });
                ws.addEventListener('message', function (ev) {
                    try {
                        var d = JSON.parse(ev.data);
                        if (d && typeof d.n === 'number') setCount(d.n);
                    } catch (e) {}
                });
            } catch (e2) {
                if (srcEl) srcEl.textContent = 'WebSocket failed — falling back to SSE';
                openSse();
            }
        } else {
            openSse();
        }
        function openSse() {
            if (srcEl && !wsUrl) srcEl.textContent = 'Live pings (SSE)';
            var u = absPath('admin_presence_sse.php');
            try {
                var es = new EventSource(u);
                var esClosed = false;
                es.onmessage = function (ev) {
                    try {
                        var d = JSON.parse(ev.data);
                        if (d && typeof d.n === 'number') setCount(d.n);
                    } catch (e) {}
                };
                es.onerror = function () {
                    // Close immediately so the browser does not retry EventSource forever
                    // (403 / dropped connections otherwise spam the server and look like endless loads).
                    if (esClosed) return;
                    esClosed = true;
                    try {
                        es.close();
                    } catch (e4) {}
                    if (srcEl) srcEl.textContent = 'Live count paused — refresh to reconnect';
                };
            } catch (e3) {}
        }
    })();
    </script>
</body>
</html>
