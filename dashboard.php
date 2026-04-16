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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
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
<body class="bg-slate-50 min-h-screen p-4">
    <div class="max-w-5xl mx-auto py-4 space-y-6">
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
                <?php if ($isAdmin): ?>
                    <a href="<?php echo htmlspecialchars(trytest_url('dashboard/logout'), ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-center gap-2 rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                        <i class="fa-solid fa-right-from-bracket"></i> Logout
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($isAdmin): ?>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-5">
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
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="mt-4 rounded-lg bg-red-100 text-red-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if (!$isAdmin): ?>
                <?php if ($needsAdminSetup): ?>
                    <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Create the first administrator account. Credentials are stored securely in your database (hashed).</p>
                    <form method="post" class="mt-4 space-y-3 max-w-md">
                        <input type="hidden" name="action" value="setup_admin">
                        <input class="w-full rounded-lg border border-slate-300 px-3 py-2" type="text" name="username" placeholder="Admin username" required autocomplete="username" minlength="2" maxlength="64">
                        <input class="w-full rounded-lg border border-slate-300 px-3 py-2" type="password" name="password" placeholder="Password (min 10 characters)" required autocomplete="new-password" minlength="10">
                        <input class="w-full rounded-lg border border-slate-300 px-3 py-2" type="password" name="password_confirm" placeholder="Confirm password" required autocomplete="new-password" minlength="10">
                        <button class="w-full rounded-lg bg-slate-900 text-white px-3 py-2 font-medium" type="submit">Create administrator</button>
                    </form>
                <?php else: ?>
                    <form method="post" class="mt-5 grid gap-2 sm:grid-cols-3">
                        <input type="hidden" name="action" value="login">
                        <input class="rounded-lg border border-slate-300 px-3 py-2" type="text" name="username" placeholder="Admin username" required autocomplete="username">
                        <input class="rounded-lg border border-slate-300 px-3 py-2" type="password" name="password" placeholder="Password" required autocomplete="current-password">
                        <button class="rounded-lg bg-slate-900 text-white px-3 py-2 font-medium" type="submit">Login</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p class="mt-4 text-xs text-slate-500">Signed in as <span class="font-medium text-slate-700"><?php echo htmlspecialchars((string) ($_SESSION['admin_username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span> · <a class="text-indigo-600 hover:underline" href="<?php echo htmlspecialchars(trytest_url('dashboard/change_admin_password'), ENT_QUOTES, 'UTF-8'); ?>">Change password</a></p>
            <?php endif; ?>
            <?php if (!$isAdmin): ?>
            <p class="mt-3 text-center text-sm text-slate-500"><a href="<?php echo htmlspecialchars(trytest_home_url(), ENT_QUOTES, 'UTF-8'); ?>" class="text-indigo-600 hover:underline">Student sign in</a></p>
            <?php endif; ?>
        </div>

        <?php if ($isAdmin): ?>
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
        <?php endif; ?>
    </div>
</body>
</html>
