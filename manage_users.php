<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config/db.php';

if (empty($_SESSION['is_admin'])) {
    header('Location: ' . trytest_url('dashboard/?mode=admin'));
    exit;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete_user') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('DELETE FROM scores WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $message = 'User deleted.';
        }
    }
}

$users = $db->query(
    'SELECT
        u.id,
        u.index_number,
        u.level,
        u.department,
        u.last_login_at,
        COUNT(s.id) AS attempts,
        COUNT(DISTINCT s.quiz_id) AS quizzes_taken,
        COALESCE(SUM(s.score), 0) AS total_points,
        COALESCE(ROUND(AVG(CASE WHEN s.total > 0 THEN (100.0 * s.score / s.total) END), 1), 0) AS avg_percent,
        COALESCE(ROUND(MAX(CASE WHEN s.total > 0 THEN (100.0 * s.score / s.total) END), 1), 0) AS best_percent,
        MAX(s.created_at) AS last_attempt_at,
        (
            SELECT q.title
            FROM scores s2
            LEFT JOIN quizzes q ON q.id = s2.quiz_id
            WHERE s2.user_id = u.id
            ORDER BY s2.id DESC
            LIMIT 1
        ) AS last_quiz_title,
        (
            SELECT s3.score
            FROM scores s3
            WHERE s3.user_id = u.id
            ORDER BY s3.id DESC
            LIMIT 1
        ) AS last_score,
        (
            SELECT s4.total
            FROM scores s4
            WHERE s4.user_id = u.id
            ORDER BY s4.id DESC
            LIMIT 1
        ) AS last_total
     FROM users u
     LEFT JOIN scores s ON s.user_id = u.id
     GROUP BY u.id
     ORDER BY u.id DESC'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest — Manage Users</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen p-4">
    <div class="max-w-5xl mx-auto py-6 space-y-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900">Users</h1>
                <a href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_admin'), ENT_QUOTES, 'UTF-8'); ?>" class="text-sm text-indigo-600">Back to manager</a>
            </div>
            <?php if ($message !== ''): ?><div class="mt-3 rounded-lg bg-emerald-100 text-emerald-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-semibold mb-1">All Students</h2>
            <p class="text-xs text-slate-500 mb-3">Includes performance metrics per student.</p>
            <div class="space-y-2 max-h-[70vh] overflow-auto">
                <?php foreach ($users as $user): ?>
                    <?php
                    $attempts = (int) ($user['attempts'] ?? 0);
                    $quizzesTaken = (int) ($user['quizzes_taken'] ?? 0);
                    $totalPoints = (int) ($user['total_points'] ?? 0);
                    $avgPercent = (float) ($user['avg_percent'] ?? 0);
                    $bestPercent = (float) ($user['best_percent'] ?? 0);
                    $lastScore = isset($user['last_score']) ? (int) $user['last_score'] : null;
                    $lastTotal = isset($user['last_total']) ? (int) $user['last_total'] : null;
                    $lastQuizTitle = trim((string) ($user['last_quiz_title'] ?? ''));
                    ?>
                    <div class="border rounded-lg p-3 text-sm space-y-2">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <p class="font-medium"><?php echo htmlspecialchars((string) $user['index_number'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <p class="text-slate-500 text-xs">Level <?php echo htmlspecialchars((string) $user['level'], ENT_QUOTES, 'UTF-8'); ?><?php $ud = trim((string) ($user['department'] ?? '')); if ($ud !== ''): ?> · <?php echo htmlspecialchars($ud, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></p>
                            </div>
                            <form method="post">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
                                <button class="text-red-600 text-xs" onclick="return confirm('Delete this user?');">Delete</button>
                            </form>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                            <div class="rounded border border-slate-200 px-2 py-1.5">
                                <p class="text-slate-400">Attempts</p>
                                <p class="font-semibold text-slate-700"><?php echo $attempts; ?></p>
                            </div>
                            <div class="rounded border border-slate-200 px-2 py-1.5">
                                <p class="text-slate-400">Quizzes</p>
                                <p class="font-semibold text-slate-700"><?php echo $quizzesTaken; ?></p>
                            </div>
                            <div class="rounded border border-slate-200 px-2 py-1.5">
                                <p class="text-slate-400">Avg %</p>
                                <p class="font-semibold text-slate-700"><?php echo number_format($avgPercent, 1); ?></p>
                            </div>
                            <div class="rounded border border-slate-200 px-2 py-1.5">
                                <p class="text-slate-400">Best %</p>
                                <p class="font-semibold text-slate-700"><?php echo number_format($bestPercent, 1); ?></p>
                            </div>
                        </div>

                        <div class="text-xs text-slate-500">
                            <p>Total points: <span class="font-medium text-slate-700"><?php echo $totalPoints; ?></span></p>
                            <p>Last quiz: <span class="font-medium text-slate-700"><?php echo htmlspecialchars($lastQuizTitle !== '' ? $lastQuizTitle : '-', ENT_QUOTES, 'UTF-8'); ?></span><?php if ($lastScore !== null && $lastTotal !== null): ?> · <span class="font-medium text-slate-700"><?php echo $lastScore; ?>/<?php echo $lastTotal; ?></span><?php endif; ?></p>
                            <p>Last login: <span class="font-medium text-slate-700"><?php echo htmlspecialchars((string) ($user['last_login_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></p>
                            <p>Last attempt: <span class="font-medium text-slate-700"><?php echo htmlspecialchars((string) ($user['last_attempt_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</body>
</html>
