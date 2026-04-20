<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/student_helpers.php';
require_once __DIR__ . '/includes/student_theme.php';

if (empty($_SESSION['user_id'])) {
    trytest_redirect(trytest_home_url());
}

$userId = (int) $_SESSION['user_id'];

$stmt = $db->prepare('SELECT TRIM(COALESCE(nickname, \'\')) FROM users WHERE id = ?');
$stmt->execute([$userId]);
$existingNick = (string) ($stmt->fetchColumn() ?: '');
if ($existingNick !== '') {
    $_SESSION['user_nickname'] = $existingNick;
    trytest_redirect(trytest_url('dashboard'));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_nickname') {
        $normalized = trytest_student_normalize_nickname((string) ($_POST['nickname'] ?? ''));
        if ($normalized === null) {
            $error = 'Enter a nickname (2–40 characters: letters, numbers, spaces, dot, underscore, or hyphen).';
        } else {
            try {
                $db->prepare('UPDATE users SET nickname = ? WHERE id = ?')->execute([$normalized, $userId]);
                $_SESSION['user_nickname'] = $normalized;
                trytest_redirect(trytest_student_post_login_redirect_url($db));
            } catch (Throwable $e) {
                $error = 'Could not save right now. Please try again.';
            }
        }
    }
}

$h = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php trytest_student_theme_head_early(); ?>
    <title>Your nickname · Trytest</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo $h(trytest_url('favicon.svg')); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <?php trytest_student_theme_tailwind_config_script(); ?>
    <style>body { font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif; }</style>
</head>
<body class="touch-manipulation flex min-h-screen flex-col items-center justify-center bg-slate-50 px-4 py-10 text-slate-900 antialiased dark:bg-[#0f1014] dark:text-zinc-100">
    <div class="w-full max-w-sm rounded-2xl border border-slate-200 bg-white px-6 py-10 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h1 class="text-center text-lg font-bold tracking-tight text-slate-900 dark:text-zinc-50">Choose your nickname</h1>
        <p class="mt-2 text-center text-xs leading-relaxed text-slate-500 dark:text-zinc-400">
            This is how you’ll appear on rankings and in the app — not your index number.
        </p>
        <?php if ($error !== ''): ?>
            <p class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800 dark:bg-red-950/40 dark:text-red-200"><?php echo $h($error); ?></p>
        <?php endif; ?>
        <form method="post" class="mt-6 space-y-4">
            <input type="hidden" name="action" value="save_nickname">
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-zinc-400">Nickname</span>
                <input
                    type="text"
                    name="nickname"
                    maxlength="40"
                    autocomplete="nickname"
                    required
                    class="w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-base text-slate-900 shadow-inner placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                    placeholder="e.g. Alex M."
                >
            </label>
            <button type="submit" class="w-full rounded-xl bg-indigo-600 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 active:scale-[0.99] dark:bg-indigo-500 dark:hover:bg-indigo-600">
                Continue
            </button>
        </form>
    </div>
</body>
</html>
