<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require __DIR__ . '/config/db.php';

if (empty($_SESSION['is_admin'])) {
    trytest_redirect(trytest_url('admin'));
}

$rows = $db->query(
    'SELECT f.id, f.stars, f.body, f.quiz_ref, f.created_at, u.index_number, u.nickname
     FROM student_system_feedback f
     JOIN users u ON u.id = f.user_id
     ORDER BY f.id DESC
     LIMIT 200'
)->fetchAll(PDO::FETCH_ASSOC);

$total = (int) $db->query('SELECT COUNT(*) FROM student_system_feedback')->fetchColumn();
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student feedback · Trytest</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo $h(trytest_url('favicon.svg')); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body { font-family: Inter, ui-sans-serif, system-ui, sans-serif; }</style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:max-w-4xl">
        <header class="mb-6 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight text-slate-900">Student feedback</h1>
                <p class="mt-1 text-sm text-slate-500"><?php echo $total; ?> total · showing latest <?php echo count($rows); ?></p>
            </div>
            <a href="<?php echo $h(trytest_url('dashboard/manage_admin')); ?>" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">← Admin manager</a>
        </header>
        <?php if ($rows === []): ?>
            <p class="rounded-lg border border-dashed border-slate-200 bg-white px-4 py-10 text-center text-sm text-slate-500">No feedback yet.</p>
        <?php else: ?>
            <ul class="space-y-3">
                <?php foreach ($rows as $r): ?>
                    <?php
                    $stars = (int) ($r['stars'] ?? 0);
                    $nick = trim((string) ($r['nickname'] ?? ''));
                    $label = $nick !== '' ? $nick : (string) ($r['index_number'] ?? '');
                    ?>
                    <li class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-medium text-slate-800"><?php echo $h($label); ?>
                                <span class="font-normal text-slate-400">·</span>
                                <span class="font-mono text-xs text-slate-500"><?php echo $h((string) ($r['index_number'] ?? '')); ?></span>
                            </p>
                            <?php if ($stars >= 1 && $stars <= 5): ?>
                                <p class="text-amber-500" aria-label="<?php echo $stars; ?> stars"><?php echo str_repeat('★', $stars) . str_repeat('☆', 5 - $stars); ?></p>
                            <?php else: ?>
                                <p class="text-xs font-medium text-slate-400">No rating</p>
                            <?php endif; ?>
                        </div>
                        <?php if (trim((string) ($r['quiz_ref'] ?? '')) !== ''): ?>
                            <p class="mt-1 text-xs text-slate-500">Ref: <span class="font-medium text-slate-700"><?php echo $h(trim((string) $r['quiz_ref'])); ?></span></p>
                        <?php endif; ?>
                        <p class="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-slate-700"><?php echo $h((string) ($r['body'] ?? '')); ?></p>
                        <p class="mt-2 text-[11px] text-slate-400"><?php echo $h((string) ($r['created_at'] ?? '')); ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</body>
</html>
