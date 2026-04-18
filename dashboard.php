<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/admin_auth.php';

$error = '';
$needsAdminSetup = trytest_admin_count($db) === 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'setup_admin' && $needsAdminSetup) {
        $user = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        $pass2 = (string) ($_POST['password_confirm'] ?? '');
        $setupErr = trytest_admin_create_first($db, $user, $pass, $pass2);
        if ($setupErr !== '') {
            $error = $setupErr;
        } elseif (trytest_admin_attempt_login($db, $user, $pass)) {
            trytest_redirect(trytest_url('dashboard'));
        } else {
            $error = 'Account was created but sign-in failed. Try logging in.';
        }
    }
    if ($action === 'login' && !$needsAdminSetup) {
        $user = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        if (trytest_admin_attempt_login($db, $user, $pass)) {
            trytest_redirect(trytest_url('dashboard'));
        }
        $error = 'Invalid admin username or password.';
    }
}

$isAdmin = !empty($_SESSION['is_admin']);
$needsAdminSetup = trytest_admin_count($db) === 0;
$quizCount = 0;
$userCount = 0;
if ($isAdmin) {
    $quizCount = (int) $db->query('SELECT COUNT(*) FROM quizzes')->fetchColumn();
    $userCount = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
}
$avatarImport = 'https://api.dicebear.com/9.x/icons/svg?seed=import';
$avatarAi = 'https://api.dicebear.com/9.x/icons/svg?seed=ai';
$avatarManage = 'https://api.dicebear.com/9.x/icons/svg?seed=manage';
$adminLoginIllustrationUrl = 'https://thumbs.dreamstime.com/b/cartoon-illustration-girl-studying-online-using-laptop-headphones-comfortable-home-environment-girl-studying-376165006.jpg';
$h = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php trytest_link_preview_meta([
        'title' => 'Trytest — Dashboard',
        'description' => 'Admin sign-in or control center.',
        'path_line' => 'dashboard',
    ]); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest — Dashboard</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(trytest_url('favicon.svg'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body{font-family:Inter,ui-sans-serif,system-ui,sans-serif}</style>
</head>
<?php if (!$isAdmin): ?>
<body class="flex min-h-screen w-full max-w-[100vw] flex-col items-center justify-center overflow-x-hidden bg-slate-50 px-4 py-8 antialiased text-slate-900">
    <div class="w-full max-w-md overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="relative h-40 w-full border-b border-slate-100 bg-slate-50 sm:h-44">
            <img src="<?php echo $h($adminLoginIllustrationUrl); ?>" alt="" class="h-full w-full object-contain object-center p-3" width="400" height="300" loading="eager" decoding="async" referrerpolicy="no-referrer">
        </div>
        <div class="p-6">
            <h1 class="flex items-center justify-center gap-2 text-lg font-bold text-slate-900">
                <i class="fa-solid fa-shield-halved text-indigo-600" aria-hidden="true"></i>
                Admin
            </h1>
            <?php if ($error !== ''): ?>
                <p class="mt-3 flex items-start gap-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800">
                    <i class="fa-solid fa-circle-exclamation mt-0.5 shrink-0" aria-hidden="true"></i>
                    <span><?php echo $h($error); ?></span>
                </p>
            <?php endif; ?>
            <?php if ($needsAdminSetup): ?>
                <p class="mt-4 flex items-center justify-center gap-2 text-center text-xs text-amber-900">
                    <i class="fa-solid fa-user-plus text-amber-600" aria-hidden="true"></i>
                    <span>Create the first admin account.</span>
                </p>
                <form method="post" class="mt-4 flex flex-col gap-3">
                    <input type="hidden" name="action" value="setup_admin">
                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
                        <input class="w-full rounded-lg border border-slate-300 py-2.5 pl-10 pr-3 text-sm" type="text" name="username" placeholder="Username" required autocomplete="username" minlength="2" maxlength="64">
                    </div>
                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"><i class="fa-solid fa-lock"></i></span>
                        <input class="w-full rounded-lg border border-slate-300 py-2.5 pl-10 pr-3 text-sm" type="password" name="password" placeholder="Password (10+ chars)" required autocomplete="new-password" minlength="10">
                    </div>
                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"><i class="fa-solid fa-lock"></i></span>
                        <input class="w-full rounded-lg border border-slate-300 py-2.5 pl-10 pr-3 text-sm" type="password" name="password_confirm" placeholder="Confirm password" required autocomplete="new-password" minlength="10">
                    </div>
                    <button class="flex w-full items-center justify-center gap-2 rounded-lg bg-slate-900 py-2.5 text-sm font-medium text-white" type="submit">
                        <i class="fa-solid fa-check" aria-hidden="true"></i>
                        Create admin
                    </button>
                </form>
            <?php else: ?>
                <form method="post" class="mt-6 flex flex-col gap-3">
                    <input type="hidden" name="action" value="login">
                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
                        <input class="w-full rounded-lg border border-slate-300 py-2.5 pl-10 pr-3 text-sm" type="text" name="username" placeholder="Username" required autocomplete="username">
                    </div>
                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"><i class="fa-solid fa-lock"></i></span>
                        <input class="w-full rounded-lg border border-slate-300 py-2.5 pl-10 pr-3 text-sm" type="password" name="password" placeholder="Password" required autocomplete="current-password">
                    </div>
                    <button class="flex w-full items-center justify-center gap-2 rounded-lg bg-slate-900 py-2.5 text-sm font-medium text-white" type="submit">
                        <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i>
                        Sign in
                    </button>
                </form>
            <?php endif; ?>
            <a href="<?php echo $h(trytest_home_url()); ?>" class="mt-5 flex items-center justify-center gap-2 text-center text-sm text-indigo-600 hover:underline">
                <i class="fa-solid fa-graduation-cap" aria-hidden="true"></i>
                Student sign-in
            </a>
        </div>
    </div>
</body>
<?php else: ?>
<body class="min-h-screen bg-slate-50 p-4 text-slate-900 antialiased">
    <div class="mx-auto max-w-5xl space-y-6 py-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900">Admin Dashboard</h1>
                    <p class="text-sm text-slate-500 mt-1">Serene control center for quiz management.</p>
                    <div class="mt-3 flex -space-x-2">
                        <img src="<?php echo htmlspecialchars($avatarImport, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="h-8 w-8 rounded-full border border-slate-200 bg-white">
                        <img src="<?php echo htmlspecialchars($avatarAi, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="h-8 w-8 rounded-full border border-slate-200 bg-white">
                        <img src="<?php echo htmlspecialchars($avatarManage, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="h-8 w-8 rounded-full border border-slate-200 bg-white">
                    </div>
                </div>
                <a href="<?php echo htmlspecialchars(trytest_url('dashboard/logout'), ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-center gap-2 rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                    <i class="fa-solid fa-right-from-bracket"></i> Logout
                </a>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 mt-5">
                <div class="rounded-xl border border-slate-200 p-4">
                    <span class="mb-2 inline-flex h-8 w-8 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                        <i class="fa-solid fa-rectangle-list text-sm"></i>
                    </span>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Quizzes</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900"><?php echo $quizCount; ?></p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <span class="mb-2 inline-flex h-8 w-8 items-center justify-center rounded-full bg-violet-50 text-violet-600">
                        <i class="fa-solid fa-user-graduate text-sm"></i>
                    </span>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Students</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900"><?php echo $userCount; ?></p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <span class="mb-2 inline-flex h-8 w-8 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                        <i class="fa-solid fa-shield-halved text-sm"></i>
                    </span>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Session</p>
                    <p class="mt-1 text-sm font-semibold text-emerald-600">Admin logged in</p>
                </div>
            </div>

            <?php if ($error !== ''): ?>
                <div class="mt-4 rounded-lg bg-red-100 text-red-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <p class="mt-4 text-xs text-slate-500">Signed in as <span class="font-medium text-slate-700"><?php echo htmlspecialchars((string) ($_SESSION['admin_username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span> · <a class="text-indigo-600 hover:underline" href="<?php echo htmlspecialchars(trytest_url('dashboard/change_admin_password'), ENT_QUOTES, 'UTF-8'); ?>">Change password</a></p>
        </div>

        <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <div class="flex items-center gap-3 mb-2">
                    <img src="<?php echo htmlspecialchars($avatarImport, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="h-9 w-9 rounded-lg border border-slate-200 bg-white">
                    <h2 class="font-semibold text-slate-900">Import Exam</h2>
                </div>
                <p class="text-sm text-slate-500 mb-4">Paste raw exam text and auto-parse to questions.</p>
                <a href="<?php echo htmlspecialchars(trytest_url('dashboard/import_exam'), ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 text-white px-4 py-2 text-sm font-medium">Open <i class="fa-solid fa-arrow-right"></i></a>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <div class="flex items-center gap-3 mb-2">
                    <img src="<?php echo htmlspecialchars($avatarAi, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="h-9 w-9 rounded-lg border border-slate-200 bg-white">
                    <h2 class="font-semibold text-slate-900">AI Question Flow</h2>
                </div>
                <p class="text-sm text-slate-500 mb-4">Generate a clean prompt and import AI JSON safely.</p>
                <div class="flex flex-wrap gap-2">
                    <a href="<?php echo htmlspecialchars(trytest_url('dashboard/generate_ai'), ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"><i class="fa-solid fa-pen-to-square"></i> Prompt</a>
                    <a href="<?php echo htmlspecialchars(trytest_url('dashboard/import_json'), ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 text-white px-3 py-2 text-sm font-medium"><i class="fa-solid fa-brackets-curly"></i> Import JSON</a>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <div class="flex items-center gap-3 mb-2">
                    <img src="<?php echo htmlspecialchars($avatarManage, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="h-9 w-9 rounded-lg border border-slate-200 bg-white">
                    <h2 class="font-semibold text-slate-900">Manage Data</h2>
                </div>
                <p class="text-sm text-slate-500 mb-4">Create and manage courses, quizzes, questions, students and departments.</p>
                <a href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_admin'), ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 text-white px-4 py-2 text-sm font-medium">Open Manager <i class="fa-solid fa-arrow-right"></i></a>
            </div>
        </div>
    </div>
</body>
<?php endif; ?>
</html>
