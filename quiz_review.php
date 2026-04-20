<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/student_helpers.php';
require_once __DIR__ . '/includes/student_theme.php';

$trytestQuizViewport = 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, shrink-to-fit=no, viewport-fit=cover, user-scalable=no';

if (empty($_SESSION['user_id']) || empty($_SESSION['user_level'])) {
    trytest_redirect(trytest_home_url());
}

trytest_student_require_nickname($db);

$quizId = isset($_GET['quiz_id']) ? (int) $_GET['quiz_id'] : 0;
$userId = (int) $_SESSION['user_id'];
$userLevel = (string) $_SESSION['user_level'];
$userDepartment = trim((string) ($_SESSION['user_department'] ?? ''));

if ($quizId < 1) {
    trytest_redirect(trytest_url('dashboard?tab=results'));
}

if (!trytest_student_can_access_quiz($db, $quizId, $userLevel, $userDepartment)) {
    http_response_code(403);
    echo 'You are not allowed to view this quiz review.';
    exit;
}

$st = $db->prepare(
    'SELECT s.score, s.total, s.review_json, q.title
     FROM scores s
     INNER JOIN quizzes q ON q.id = s.quiz_id
     WHERE s.quiz_id = ? AND s.user_id = ?
     LIMIT 1'
);
$st->execute([$quizId, $userId]);
$srow = $st->fetch(PDO::FETCH_ASSOC);
if ($srow === false) {
    trytest_redirect(trytest_url('dashboard?tab=results'));
}

$quizTitle = (string) ($srow['title'] ?? 'Quiz');
$score = (int) ($srow['score'] ?? 0);
$total = (int) ($srow['total'] ?? 0);
$reviewRaw = trim((string) ($srow['review_json'] ?? ''));

/** @var list<array<string,mixed>> */
$reviewItems = [];
if ($reviewRaw !== '') {
    $decoded = json_decode($reviewRaw, true);
    if (is_array($decoded)) {
        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $reviewItems[] = $entry;
            }
        }
    }
}

$h = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};
$dashResults = trytest_url('dashboard?tab=results');
$postUrl = trytest_url('student_portal.php');

?>
<!DOCTYPE html>
<html lang="en" class="quiz-no-zoom">
<head>
    <meta charset="UTF-8">
    <?php trytest_link_preview_meta([
        'title' => $quizTitle . ' · Results review · Trytest',
        'description' => 'Saved answers for your last attempt.',
        'path_line' => 'quiz_review?quiz_id=' . $quizId,
    ]); ?>
    <meta name="viewport" content="<?php echo htmlspecialchars($trytestQuizViewport, ENT_QUOTES, 'UTF-8'); ?>">
    <?php trytest_student_theme_head_early(); ?>
    <title><?php echo $h($quizTitle); ?> · Review · Trytest</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo $h(trytest_url('favicon.svg')); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <?php trytest_student_theme_tailwind_config_script(); ?>
    <style>
        html.quiz-no-zoom, html.quiz-no-zoom body {
            touch-action: manipulation;
            -webkit-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }
        body { font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif; }
    </style>
</head>
<body class="min-h-screen touch-manipulation bg-stone-100 pb-10 text-zinc-800 dark:bg-zinc-950 dark:text-zinc-100">
    <header class="sticky top-0 z-20 border-b border-zinc-200/90 bg-white/95 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/95">
        <div class="mx-auto flex max-w-lg items-center gap-3 px-4 py-3">
            <a href="<?php echo $h($dashResults); ?>" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-zinc-200 bg-white text-lg text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700" aria-label="Back to My results">←</a>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-zinc-900 dark:text-zinc-100"><?php echo $h($quizTitle); ?></p>
                <p class="text-[11px] text-zinc-500 dark:text-zinc-400">Answer review</p>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-lg px-4 pt-5">
        <div class="rounded-2xl border border-zinc-200/90 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Your score</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-zinc-900 dark:text-zinc-50"><?php echo $score; ?><span class="text-lg font-normal text-zinc-400 dark:text-zinc-500"> / <?php echo $total; ?></span></p>
        </div>

        <?php if ($reviewItems === []): ?>
            <p class="mt-5 rounded-xl border border-dashed border-zinc-300 bg-white/80 px-4 py-6 text-center text-sm text-zinc-600 dark:border-zinc-600 dark:bg-zinc-900/50 dark:text-zinc-400">
                No saved question breakdown for this attempt. Take the quiz again — your next finish will store questions here.
            </p>
        <?php else: ?>
            <ol class="mt-5 space-y-3">
                <?php foreach ($reviewItems as $item): ?>
                    <?php
                    $verdict = (string) ($item['verdict'] ?? '');
                    $badgeClass = 'border-zinc-200 bg-zinc-100 text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200';
                    $badgeLabel = '—';
                    if ($verdict === 'correct') {
                        $badgeClass = 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-950/50 dark:text-emerald-100';
                        $badgeLabel = 'Correct';
                    } elseif ($verdict === 'partial') {
                        $badgeClass = 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100';
                        $badgeLabel = 'Partial';
                    } elseif ($verdict === 'wrong') {
                        $badgeClass = 'border-red-200 bg-red-50 text-red-950 dark:border-red-900/50 dark:bg-red-950/35 dark:text-red-100';
                        $badgeLabel = 'Wrong';
                    }
                    ?>
                    <li class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <p class="min-w-0 flex-1 text-sm font-semibold leading-snug text-zinc-900 dark:text-zinc-100"><?php echo $h((string) ($item['stem'] ?? '')); ?></p>
                            <span class="shrink-0 rounded-full border px-2.5 py-0.5 text-[11px] font-semibold <?php echo $badgeClass; ?>"><?php echo $h($badgeLabel); ?></span>
                        </div>
                        <p class="mt-3 text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Your answer</p>
                        <p class="mt-0.5 text-sm text-zinc-800 dark:text-zinc-200"><?php echo $h((string) ($item['userAnswer'] ?? '—')); ?></p>
                        <p class="mt-2 text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Correct answer</p>
                        <p class="mt-0.5 text-sm font-medium text-zinc-700 dark:text-zinc-300"><?php echo $h((string) ($item['correctAnswer'] ?? '—')); ?></p>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <form method="post" action="<?php echo $h($postUrl); ?>" class="mt-8" onsubmit="return confirm('Erase your saved score and attempts for this quiz and start fresh?');">
            <input type="hidden" name="action" value="reset_student_quiz">
            <input type="hidden" name="quiz_id" value="<?php echo $quizId; ?>">
            <button type="submit" class="w-full rounded-xl border border-zinc-900 bg-zinc-900 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-zinc-800 dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white">Try again — reset and start over</button>
        </form>
        <p class="mt-4 text-center text-xs text-zinc-500 dark:text-zinc-400">This clears your stored results for this quiz, then opens a new attempt.</p>
    </main>
    <?php trytest_student_theme_controller_script(); ?>
</body>
</html>
