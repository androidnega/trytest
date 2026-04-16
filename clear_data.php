<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/admin_auth.php';

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $user = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        if (trytest_admin_count($db) < 1) {
            $error = 'No administrator account yet. Open ' . trytest_url('admin') . ' to create one.';
        } elseif (trytest_admin_attempt_login($db, $user, $pass)) {
            trytest_redirect(trytest_url('dashboard/clear_data'));
        } else {
            $error = 'Invalid admin username or password.';
        }
    } elseif (!empty($_SESSION['is_admin']) && $action === 'clear') {
        try {
            $db->beginTransaction();
            $db->exec('DELETE FROM scores');
            $db->exec('DELETE FROM questions');
            $db->exec('DELETE FROM quiz_courses');
            $db->exec('DELETE FROM quizzes');
            $db->commit();
            $message = 'System data has been reset.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'Could not clear data. Check database permissions.';
        }
    }
}

$isAdmin = !empty($_SESSION['is_admin']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest — Reset data</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(trytest_url('favicon.svg'), ENT_QUOTES, 'UTF-8'); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white min-h-screen p-4">
    <div class="max-w-2xl mx-auto pt-8 pb-10">
        <div class="bg-white rounded-2xl shadow p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold text-slate-900">Clear System Data</h1>
                <a href="<?php echo htmlspecialchars(trytest_home_url(), ENT_QUOTES, 'UTF-8'); ?>" class="text-sm text-indigo-600">Back to dashboard</a>
            </div>
            <p class="text-sm text-slate-500">This deletes quizzes, questions, and scores.</p>

            <?php if ($error !== ''): ?>
                <div class="rounded-lg bg-red-100 text-red-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($message !== ''): ?>
                <div class="rounded-lg bg-emerald-100 text-emerald-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if (!$isAdmin): ?>
                <form method="post" class="space-y-3">
                    <input type="hidden" name="action" value="login">
                    <input class="w-full rounded-lg border border-slate-300 px-3 py-2" type="text" name="username" placeholder="Username" required>
                    <input class="w-full rounded-lg border border-slate-300 px-3 py-2" type="password" name="password" placeholder="Password" required>
                    <button class="w-full rounded-lg bg-slate-900 text-white py-2 font-medium" type="submit">Login</button>
                </form>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="action" value="clear">
                    <button class="w-full rounded-lg bg-red-600 text-white p-3 font-medium" type="submit" onclick="return confirm('Clear all system data?');">Reset Data</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
