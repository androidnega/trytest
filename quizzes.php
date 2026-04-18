<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/student_helpers.php';
require_once __DIR__ . '/includes/student_theme.php';

if (empty($_SESSION['user_id'])) {
    trytest_redirect(trytest_home_url());
}

$userId = (int) $_SESSION['user_id'];
$sync = $db->prepare('SELECT index_number, level, department FROM users WHERE id = ?');
$sync->execute([$userId]);
$syncRow = $sync->fetch();
if (!$syncRow) {
    trytest_redirect(trytest_home_url());
}
$userLevel = trim((string) ($syncRow['level'] ?? ''));
$userDepartment = trim((string) ($syncRow['department'] ?? ''));
$_SESSION['user_index_number'] = (string) ($syncRow['index_number'] ?? '');
$_SESSION['user_level'] = $userLevel;
$_SESSION['user_department'] = $userDepartment;

try {
    $db->prepare('UPDATE users SET quizzes_feed_last_seen_at = datetime(\'now\') WHERE id = ?')->execute([$userId]);
} catch (Throwable $e) {
    // column missing on very old DB — ignore
}

$coursesWithQuizzes = trytest_student_load_courses_with_quizzes($db, $userId, $userLevel, $userDepartment);

$dashboardUrl = trytest_url('dashboard');
$quizUrlBase = trytest_url('quiz');
$quizSchedulesPollUrl = trytest_url('api_quiz_schedules.php');

$h = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php trytest_student_theme_head_early(); ?>
    <title>Quizzes · Trytest</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo $h(trytest_url('favicon.svg')); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <?php trytest_student_theme_tailwind_config_script(); ?>
    <style>
        body { font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif; }
        html.dark { color-scheme: dark; }
    </style>
</head>
<body class="min-h-screen bg-slate-50 pb-24 text-slate-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <header class="sticky top-0 z-10 border-b border-slate-200/80 bg-white/95 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/95">
        <div class="mx-auto flex max-w-5xl items-center gap-3 px-4 py-3">
            <a href="<?php echo $h($dashboardUrl); ?>" class="shrink-0 text-sm font-semibold text-[#2C6A7D] hover:underline dark:text-[#7eb8b8]">← Home</a>
            <h1 class="min-w-0 flex-1 truncate text-lg font-bold tracking-tight text-slate-900 dark:text-zinc-100">Quizzes</h1>
        </div>
    </header>
    <main class="mx-auto w-full max-w-5xl px-4 py-6">
        <?php if ($userDepartment === ''): ?>
            <section class="rounded-2xl border-2 border-amber-400 bg-amber-50 px-4 py-4 shadow-sm dark:border-amber-600/50 dark:bg-amber-950/40">
                <h2 class="text-sm font-bold text-amber-950 dark:text-amber-100">Choose your program first</h2>
                <p class="mt-1 text-xs leading-relaxed text-amber-900/90 dark:text-amber-200/80">Set your program on the home screen so we can show quizzes for your class.</p>
                <a href="<?php echo $h($dashboardUrl); ?>" class="mt-3 inline-flex text-sm font-bold text-[#2C6A7D] hover:underline dark:text-[#7eb8b8]">Go to home</a>
            </section>
        <?php else: ?>
            <?php require __DIR__ . '/templates/partials/student_quiz_course_list.php'; ?>
        <?php endif; ?>
    </main>
    <?php require __DIR__ . '/templates/partials/student_quiz_course_list_script.php'; ?>
    <?php trytest_student_theme_controller_script(); ?>
</body>
</html>
