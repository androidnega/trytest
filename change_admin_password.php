<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/admin_auth.php';

if (empty($_SESSION['is_admin'])) {
    trytest_redirect(trytest_url('admin'));
}

$error = '';
$message = '';
$adminUser = (string) ($_SESSION['admin_username'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $new2 = (string) ($_POST['new_password_confirm'] ?? '');
    $err = trytest_admin_change_password($db, $adminUser, $current, $new, $new2);
    if ($err !== '') {
        $error = $err;
    } else {
        $message = 'Password updated. Use it next time you sign in.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php trytest_link_preview_meta(['title' => 'Trytest — Change admin password', 'description' => 'Trytest admin: change password.']); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest — Change admin password</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(trytest_url('favicon.svg'), ENT_QUOTES, 'UTF-8'); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen p-4">
    <div class="mx-auto max-w-md py-8">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex items-center justify-between gap-2">
                <h1 class="text-lg font-bold text-slate-900">Change password</h1>
                <a href="<?php echo htmlspecialchars(trytest_home_url(), ENT_QUOTES, 'UTF-8'); ?>" class="text-xs text-indigo-600 hover:underline">Dashboard</a>
            </div>
            <p class="mb-4 text-xs text-slate-500">Account: <span class="font-medium text-slate-700"><?php echo htmlspecialchars($adminUser, ENT_QUOTES, 'UTF-8'); ?></span></p>

            <?php if ($error !== ''): ?>
                <div class="mb-3 rounded-lg bg-red-100 px-3 py-2 text-sm text-red-700"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($message !== ''): ?>
                <div class="mb-3 rounded-lg bg-emerald-100 px-3 py-2 text-sm text-emerald-800"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="post" class="space-y-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Current password</label>
                    <input class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" type="password" name="current_password" required autocomplete="current-password">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">New password (min 10 characters)</label>
                    <input class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" type="password" name="new_password" required autocomplete="new-password" minlength="10">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Confirm new password</label>
                    <input class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" type="password" name="new_password_confirm" required autocomplete="new-password" minlength="10">
                </div>
                <button class="w-full rounded-lg bg-slate-900 py-2.5 text-sm font-semibold text-white" type="submit">Update password</button>
            </form>
        </div>
    </div>
</body>
</html>
