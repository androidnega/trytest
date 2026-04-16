<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/youtube_subscribe.php';

if (empty($_SESSION['is_admin'])) {
    header('Location: ' . trytest_home_with_query(['mode' => 'admin']));
    exit;
}

$courseCount = (int) $db->query('SELECT COUNT(*) FROM courses')->fetchColumn();
$quizCount = (int) $db->query('SELECT COUNT(*) FROM quizzes')->fetchColumn();
$userCount = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$questionCount = (int) $db->query('SELECT COUNT(*) FROM questions')->fetchColumn();
$yt = trytest_youtube_settings();
$ytLabel = $yt['gate_active'] ? 'On' : 'Off';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest — Admin Manager</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(trytest_url('favicon.svg'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen p-3 sm:p-4">
    <div class="mx-auto max-w-2xl py-4 space-y-4">
        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 sm:px-5 sm:py-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h1 class="text-lg font-bold text-slate-900 sm:text-xl">Admin Manager</h1>
                    <p class="text-[11px] text-slate-500 sm:text-xs">Jump to a section.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm">
                    <a href="<?php echo htmlspecialchars(trytest_url('dashboard/change_admin_password'), ENT_QUOTES, 'UTF-8'); ?>" class="font-medium text-slate-600 hover:text-slate-900 hover:underline">Password</a>
                    <span class="text-slate-300" aria-hidden="true">·</span>
                    <a href="<?php echo htmlspecialchars(trytest_home_url(), ENT_QUOTES, 'UTF-8'); ?>" class="font-medium text-indigo-600 hover:underline">← Dashboard</a>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 sm:gap-2.5">
            <a href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_courses'), ENT_QUOTES, 'UTF-8'); ?>" class="group flex min-h-0 items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3 py-2.5 transition hover:border-slate-300 hover:bg-slate-50/90">
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
</body>
</html>
