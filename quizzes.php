<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/student_helpers.php';

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
    <title>Quizzes · Trytest</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo $h(trytest_url('favicon.svg')); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body { font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif; }</style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased pb-24">
    <header class="sticky top-0 z-10 border-b border-slate-200/80 bg-white/95 backdrop-blur">
        <div class="mx-auto flex max-w-5xl items-center gap-3 px-4 py-3">
            <a href="<?php echo $h($dashboardUrl); ?>" class="shrink-0 text-sm font-semibold text-[#2C6A7D] hover:underline">← Home</a>
            <h1 class="min-w-0 flex-1 truncate text-lg font-bold tracking-tight text-slate-900">Quizzes</h1>
        </div>
    </header>
    <main class="mx-auto w-full max-w-5xl px-4 py-6">
        <?php if ($userDepartment === ''): ?>
            <section class="rounded-2xl border-2 border-amber-400 bg-amber-50 px-4 py-4 shadow-sm">
                <h2 class="text-sm font-bold text-amber-950">Choose your program first</h2>
                <p class="mt-1 text-xs leading-relaxed text-amber-900/90">Set your program on the home screen so we can show quizzes for your class.</p>
                <a href="<?php echo $h($dashboardUrl); ?>" class="mt-3 inline-flex text-sm font-bold text-[#2C6A7D] hover:underline">Go to home</a>
            </section>
        <?php else: ?>
            <?php require __DIR__ . '/templates/partials/student_quiz_course_list.php'; ?>
        <?php endif; ?>
    </main>
    <?php require __DIR__ . '/templates/partials/student_quiz_course_list_script.php'; ?>
</body>
</html>
