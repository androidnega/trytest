<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/student_helpers.php';
require_once __DIR__ . '/includes/departments.php';
require_once __DIR__ . '/includes/levels.php';
require_once __DIR__ . '/includes/student_theme.php';

if (empty($_SESSION['user_id'])) {
    trytest_redirect(trytest_home_url());
}

$userId = (int) $_SESSION['user_id'];
$sync = $db->prepare('SELECT index_number, level, department, TRIM(COALESCE(nickname, \'\')) AS nickname FROM users WHERE id = ?');
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
$_SESSION['user_nickname'] = trim((string) ($syncRow['nickname'] ?? ''));
trytest_student_require_nickname($db);

$departmentOptions = trytest_department_dropdown_options($db);
$levelOptions = trytest_level_dropdown_options($db);
$departmentUpdateError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_student_department') {
    $deptRaw = (string) ($_POST['department'] ?? '');
    $levelRaw = trim((string) ($_POST['level'] ?? ''));
    $resolvedDept = trytest_resolve_department_for_save($deptRaw, $departmentOptions);
    if ($departmentOptions === [] || $resolvedDept === null) {
        $departmentUpdateError = 'Choose your program from the list, then save.';
    } else {
        $resolvedLevel = $levelRaw !== '' ? trytest_resolve_level_for_save($levelRaw, $levelOptions) : null;
        if ($levelRaw !== '' && $resolvedLevel === null) {
            $departmentUpdateError = 'Choose a valid level from the list.';
        } else {
            try {
                if ($resolvedLevel !== null) {
                    $db->prepare('UPDATE users SET department = ?, level = ? WHERE id = ?')
                        ->execute([$resolvedDept, $resolvedLevel, $userId]);
                    $userLevel = $resolvedLevel;
                    $_SESSION['user_level'] = $resolvedLevel;
                } else {
                    $db->prepare('UPDATE users SET department = ? WHERE id = ?')->execute([$resolvedDept, $userId]);
                }
                $userDepartment = $resolvedDept;
                $_SESSION['user_department'] = $resolvedDept;
                trytest_redirect(trytest_url('quizzes'));
            } catch (Throwable $e) {
                $departmentUpdateError = 'Could not save your program now. Please try again shortly.';
            }
        }
    }
}

try {
    $db->prepare('UPDATE users SET quizzes_feed_last_seen_at = datetime(\'now\') WHERE id = ?')->execute([$userId]);
} catch (Throwable $e) {
    // column missing on very old DB — ignore
}

$coursesWithQuizzes = trytest_student_load_courses_with_quizzes($db, $userId, $userLevel, $userDepartment);
$needsDepartmentSetup = $departmentOptions !== []
    && trytest_student_should_offer_department_change($userDepartment, $departmentOptions, $coursesWithQuizzes);
$departmentSetupRequired = trytest_student_department_needs_refresh($userDepartment, $departmentOptions);

$dashboardUrl = trytest_url('dashboard');
$quizUrlBase = trytest_url('quiz');
$quizSchedulesPollUrl = trytest_url('api_quiz_schedules.php');
$studentPortalPostUrl = trytest_url('student_portal.php');

$h = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo htmlspecialchars(trytest_student_zoom_lock_html_class(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <?php trytest_link_preview_meta([
        'title' => 'Quizzes · Trytest',
        'description' => 'Your courses and quizzes.',
        'path_line' => 'quizzes',
    ]); ?>
    <meta name="viewport" content="<?php echo htmlspecialchars(trytest_student_locked_viewport_content(), ENT_QUOTES, 'UTF-8'); ?>">
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
    <?php trytest_student_zoom_lock_styles(); ?>
    <?php trytest_student_zoom_lock_gesture_script(); ?>
</head>
<body class="touch-manipulation min-h-screen bg-slate-50 pb-24 text-slate-900 antialiased dark:bg-[#0f1014] dark:text-zinc-100">
    <header class="sticky top-0 z-10 border-b border-slate-200/80 bg-white/95 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/95">
        <div class="mx-auto flex max-w-5xl items-center gap-3 px-4 py-3">
            <a href="<?php echo $h($dashboardUrl); ?>" class="shrink-0 text-sm font-semibold text-[#2C6A7D] hover:underline dark:text-[#7eb8b8]">← Home</a>
            <h1 class="min-w-0 flex-1 truncate text-lg font-bold tracking-tight text-slate-900 dark:text-zinc-100">Quizzes</h1>
        </div>
    </header>
    <main class="mx-auto w-full max-w-5xl px-4 py-6">
        <?php if ($needsDepartmentSetup): ?>
            <section class="mb-4 rounded-2xl border-2 border-amber-400 bg-amber-50 px-4 py-4 shadow-sm dark:border-amber-600/50 dark:bg-amber-950/40">
                <h2 class="text-sm font-bold text-amber-950 dark:text-amber-100">
                    <?php echo $departmentSetupRequired ? 'Update your program' : 'Not seeing your quizzes?'; ?>
                </h2>
                <p class="mt-1 text-xs leading-relaxed text-amber-900/90 dark:text-amber-200/80">
                    <?php if ($departmentSetupRequired): ?>
                        <?php echo $userDepartment === ''
                            ? 'Pick your program and level so we can show quizzes for your class.'
                            : 'Your saved program is no longer available. Choose the current one to unlock quizzes.'; ?>
                    <?php else: ?>
                        No quizzes match <strong><?php echo $h($userDepartment); ?></strong> · Lv <?php echo $h($userLevel); ?>. Update below if that looks wrong.
                    <?php endif; ?>
                </p>
                <?php if ($departmentUpdateError !== ''): ?>
                    <p class="mt-2 rounded-lg bg-red-100 px-3 py-2 text-xs font-medium text-red-800"><?php echo $h($departmentUpdateError); ?></p>
                <?php endif; ?>
                <form method="post" class="mt-3 grid gap-2 sm:grid-cols-2">
                    <input type="hidden" name="action" value="update_student_department">
                    <label class="block text-left">
                        <span class="mb-1 block text-[11px] font-medium text-amber-950/80">Program</span>
                        <select name="department" required class="w-full rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm">
                            <option value="">Select…</option>
                            <?php foreach ($departmentOptions as $depOpt): ?>
                                <?php $dv = (string) ($depOpt['value'] ?? ''); ?>
                                <option value="<?php echo $h($dv); ?>" <?php echo strcasecmp($dv, $userDepartment) === 0 ? 'selected' : ''; ?>><?php echo $h((string) ($depOpt['label'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="block text-left">
                        <span class="mb-1 block text-[11px] font-medium text-amber-950/80">Level</span>
                        <select name="level" required class="w-full rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm">
                            <option value="">Select…</option>
                            <?php foreach ($levelOptions as $lo): ?>
                                <?php $lv = (string) ($lo['value'] ?? ''); ?>
                                <option value="<?php echo $h($lv); ?>" <?php echo trytest_level_canon($lv) === trytest_level_canon($userLevel) ? 'selected' : ''; ?>><?php echo $h((string) ($lo['label'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="rounded-lg bg-[#2C6A7D] px-5 py-2 text-sm font-bold text-white sm:col-span-2">Save &amp; show my quizzes</button>
                </form>
            </section>
        <?php endif; ?>
        <?php if ($userDepartment !== '' && !$departmentSetupRequired): ?>
            <?php require __DIR__ . '/templates/partials/student_quiz_course_list.php'; ?>
        <?php elseif (!$needsDepartmentSetup): ?>
            <section class="rounded-2xl border-2 border-amber-400 bg-amber-50 px-4 py-4 shadow-sm dark:border-amber-600/50 dark:bg-amber-950/40">
                <h2 class="text-sm font-bold text-amber-950 dark:text-amber-100">Choose your program first</h2>
                <p class="mt-1 text-xs leading-relaxed text-amber-900/90 dark:text-amber-200/80">Set your program on the home screen so we can show quizzes for your class.</p>
                <a href="<?php echo $h($dashboardUrl); ?>" class="mt-3 inline-flex text-sm font-bold text-[#2C6A7D] hover:underline dark:text-[#7eb8b8]">Go to home</a>
            </section>
        <?php endif; ?>
    </main>
    <?php require __DIR__ . '/templates/partials/student_quiz_course_list_script.php'; ?>
    <?php trytest_student_theme_controller_script(); ?>
</body>
</html>
