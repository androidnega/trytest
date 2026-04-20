<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/student_helpers.php';
require_once __DIR__ . '/includes/student_theme.php';

/** Viewport for all quiz UI states: lock pinch-zoom on mobile (user-scalable=no, shrink-to-fit). */
$trytestQuizViewport = 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, shrink-to-fit=no, viewport-fit=cover, user-scalable=no';

if (empty($_SESSION['user_id']) || empty($_SESSION['user_level'])) {
    trytest_redirect(trytest_home_url());
}

trytest_student_require_nickname($db);

$quizId = isset($_GET['quiz_id']) ? (int) $_GET['quiz_id'] : 0;

if ($quizId < 1) {
    http_response_code(400);
    echo 'Missing or invalid quiz_id. Example: ' . trytest_url('quiz?quiz_id=1');
    exit;
}

$check = $db->prepare(
    'SELECT id, title, duration_minutes, level, quiz_starts_at, quiz_ends_at FROM quizzes WHERE id = ?'
);
$check->execute([$quizId]);
$quizRow = $check->fetch();
if ($quizRow === false) {
    http_response_code(404);
    echo 'Quiz not found. Run ' . trytest_url('install') . ' first.';
    exit;
}
$quizTitle = (string) ($quizRow['title'] ?? 'Quiz');
$durationMinutes = isset($quizRow['duration_minutes']) && $quizRow['duration_minutes'] !== null
    ? max(0, (int) $quizRow['duration_minutes'])
    : 0;
$userLevel = (string) $_SESSION['user_level'];
$userDepartment = trim((string) ($_SESSION['user_department'] ?? ''));
if (!trytest_student_can_access_quiz($db, $quizId, $userLevel, $userDepartment)) {
    http_response_code(403);
    echo 'You are not allowed to access this quiz.';
    exit;
}

require_once __DIR__ . '/includes/quiz_share.php';
$quizShareCode = trytest_quiz_ensure_share_code($db, $quizId);

$priorAttemptStmt = $db->prepare('SELECT 1 FROM scores WHERE quiz_id = ? AND user_id = ? LIMIT 1');
$priorAttemptStmt->execute([$quizId, (int) ($_SESSION['user_id'] ?? 0)]);
$hasPriorAttempt = (bool) $priorAttemptStmt->fetchColumn();

require_once __DIR__ . '/includes/exam_short_messages.php';
$studentIdForIntro = (int) ($_SESSION['user_id'] ?? 0);
$lifetimeScoresStmt = $db->prepare('SELECT COUNT(*) FROM scores WHERE user_id = ?');
$lifetimeScoresStmt->execute([$studentIdForIntro]);
$lifetimeScoreCount = (int) $lifetimeScoresStmt->fetchColumn();
$showQuizIntro = $lifetimeScoreCount === 0;
$examWelcomeQuote = trytest_exam_short_random_message();
$examWelcomeImageUrl = trytest_url('KofiEmma.jpg');
$outroFile = __DIR__ . '/Emmanuel_outro.jpg';
$examOutroImageUrl = is_file($outroFile) ? trytest_url('Emmanuel_outro.jpg') : $examWelcomeImageUrl;
$quizAuthorName = 'Emmanuel K Kwofie';
$quizIntroSeconds = 10;

require_once __DIR__ . '/includes/youtube_subscribe.php';
$ytSettings = trytest_youtube_settings();
$quizAdConfig = trytest_youtube_quiz_ad_config($ytSettings);

$startsRaw = isset($quizRow['quiz_starts_at']) ? trim((string) $quizRow['quiz_starts_at']) : '';
$endsRaw = isset($quizRow['quiz_ends_at']) ? trim((string) $quizRow['quiz_ends_at']) : '';
$schedulePhase = trytest_quiz_schedule_phase(
    $startsRaw !== '' ? $startsRaw : null,
    $endsRaw !== '' ? $endsRaw : null
);

if ($schedulePhase === 'before') {
    $openTs = strtotime($startsRaw);
    $openLabel = $openTs ? date('M j, Y g:i A', $openTs) : '';
    ?>
<!DOCTYPE html>
<html lang="en" class="quiz-no-zoom">
<head>
    <meta charset="UTF-8">
    <?php trytest_link_preview_meta([
        'title' => 'Opens soon · ' . $quizTitle,
        'description' => 'Not open yet — ' . $quizTitle,
        'path_line' => 'quiz?quiz_id=' . $quizId,
    ]); ?>
    <meta name="viewport" content="<?php echo htmlspecialchars($trytestQuizViewport, ENT_QUOTES, 'UTF-8'); ?>">
    <?php trytest_student_theme_head_early(); ?>
    <title>Opens soon · <?php echo htmlspecialchars($quizTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php trytest_student_theme_tailwind_config_script(); ?>
    <style>html.quiz-no-zoom, html.quiz-no-zoom body { touch-action: manipulation; -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }</style>
</head>
<body class="flex min-h-screen touch-manipulation items-center justify-center bg-white p-6 text-slate-900 dark:bg-zinc-950 dark:text-zinc-100">
    <div class="w-full max-w-md space-y-4 rounded-3xl border border-slate-200 p-6 text-center dark:border-zinc-800 dark:bg-zinc-900">
        <p class="text-xs font-bold uppercase tracking-widest text-amber-600 dark:text-amber-400">Not open yet</p>
        <h1 class="text-xl font-bold dark:text-zinc-100"><?php echo htmlspecialchars($quizTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($openLabel !== ''): ?>
            <p class="text-sm text-slate-600 dark:text-zinc-400">Scheduled: <?php echo htmlspecialchars($openLabel, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <p id="openCountdown" class="font-mono text-2xl font-bold text-[#2C6A7D] dark:text-[#7eb8b8]"></p>
        <a href="<?php echo htmlspecialchars(trytest_home_url(), ENT_QUOTES, 'UTF-8'); ?>" class="inline-block w-full rounded-2xl bg-[#E50914] py-3 text-sm font-bold text-white dark:bg-[#c4080f]">Back to dashboard</a>
    </div>
    <?php trytest_student_theme_controller_script(); ?>
    <script>
    (function () {
        var target = <?php echo $openTs ? (int) $openTs * 1000 : 'null'; ?>;
        var el = document.getElementById('openCountdown');
        function pad(n) { return String(n).padStart(2, '0'); }
        function fmt(ms) {
            if (ms <= 0) return 'Starting…';
            var s = Math.floor(ms / 1000);
            var d = Math.floor(s / 86400);
            var h = Math.floor((s % 86400) / 3600);
            var m = Math.floor((s % 3600) / 60);
            var sec = s % 60;
            return (d > 0 ? d + 'd ' : '') + pad(h) + ':' + pad(m) + ':' + pad(sec);
        }
        function tick() {
            if (!el || !target) { if (el) el.textContent = ''; return; }
            var left = target - Date.now();
            el.textContent = left > 0 ? ('Opens in ' + fmt(left)) : 'Starting…';
            if (left <= 0) location.reload();
        }
        tick();
        setInterval(tick, 1000);
    })();
    </script>
    <script>
    (function () {
        function blockGesture(e) { e.preventDefault(); }
        document.addEventListener('gesturestart', blockGesture, { passive: false });
        document.addEventListener('gesturechange', blockGesture, { passive: false });
        document.addEventListener('gestureend', blockGesture, { passive: false });
    })();
    </script>
</body>
</html>
    <?php
    exit;
}

if ($schedulePhase === 'after') {
    ?>
<!DOCTYPE html>
<html lang="en" class="quiz-no-zoom">
<head>
    <meta charset="UTF-8">
    <?php trytest_link_preview_meta([
        'title' => 'Closed · ' . $quizTitle,
        'description' => 'Window ended — ' . $quizTitle,
        'path_line' => 'quiz?quiz_id=' . $quizId,
    ]); ?>
    <meta name="viewport" content="<?php echo htmlspecialchars($trytestQuizViewport, ENT_QUOTES, 'UTF-8'); ?>">
    <?php trytest_student_theme_head_early(); ?>
    <title>Closed · <?php echo htmlspecialchars($quizTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php trytest_student_theme_tailwind_config_script(); ?>
    <style>html.quiz-no-zoom, html.quiz-no-zoom body { touch-action: manipulation; -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }</style>
</head>
<body class="flex min-h-screen touch-manipulation items-center justify-center bg-white p-6 text-slate-900 dark:bg-zinc-950 dark:text-zinc-100">
    <div class="w-full max-w-md space-y-3 rounded-3xl border border-slate-200 p-6 text-center dark:border-zinc-800 dark:bg-zinc-900">
        <p class="text-xs font-bold uppercase tracking-widest text-slate-500 dark:text-zinc-400">Quiz window closed</p>
        <h1 class="text-xl font-bold dark:text-zinc-100"><?php echo htmlspecialchars($quizTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="text-sm text-slate-600 dark:text-zinc-400">This quiz is no longer accepting attempts.</p>
        <a href="<?php echo htmlspecialchars(trytest_home_url(), ENT_QUOTES, 'UTF-8'); ?>" class="mt-4 inline-block w-full rounded-2xl bg-slate-900 py-3 text-sm font-bold text-white dark:bg-zinc-100 dark:text-zinc-900">Back to dashboard</a>
    </div>
    <?php trytest_student_theme_controller_script(); ?>
    <script>
    (function () {
        function blockGesture(e) { e.preventDefault(); }
        document.addEventListener('gesturestart', blockGesture, { passive: false });
        document.addEventListener('gesturechange', blockGesture, { passive: false });
        document.addEventListener('gestureend', blockGesture, { passive: false });
    })();
    </script>
</body>
</html>
    <?php
    exit;
}

$effectiveDurationSeconds = trytest_quiz_effective_duration_seconds(
    $durationMinutes,
    $endsRaw !== '' ? $endsRaw : null
);

?>
<!DOCTYPE html>
<html lang="en" class="quiz-no-zoom">
<head>
    <meta charset="UTF-8">
    <?php trytest_link_preview_meta([
        'title' => $quizTitle . ' · Trytest',
        'description' => 'Quiz: ' . $quizTitle,
        'path_line' => ($quizShareCode !== '' ? ('q/' . $quizShareCode) : ('quiz?quiz_id=' . $quizId)),
    ]); ?>
    <meta name="viewport" content="<?php echo htmlspecialchars($trytestQuizViewport, ENT_QUOTES, 'UTF-8'); ?>">
    <?php trytest_student_theme_head_early(); ?>
    <title><?php echo htmlspecialchars($quizTitle, ENT_QUOTES, 'UTF-8'); ?> · Trytest</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(trytest_url('favicon.svg'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <?php trytest_student_theme_tailwind_config_script(); ?>
    <style>
        html.quiz-no-zoom,
        html.quiz-no-zoom body {
            touch-action: manipulation;
            -webkit-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }
        html { color-scheme: light; }
        html.dark { color-scheme: dark; }
        body { font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif; }
        #quizAppShell input[type='text'],
        #quizAppShell input.fill-blank-input,
        #quizAppShell textarea {
            font-size: 16px !important;
            line-height: 1.35;
        }
        .trytest-quiz-intro-thumb {
            width: 7rem;
            height: 7rem;
            object-fit: cover;
        }
        @media (min-width: 640px) {
            .trytest-quiz-intro-thumb {
                width: 8rem;
                height: 8rem;
            }
        }
        .trytest-quiz-outro-thumb {
            width: 7.5rem;
            height: 7.5rem;
            object-fit: cover;
        }
        @media (min-width: 640px) {
            .trytest-quiz-outro-thumb {
                width: 9rem;
                height: 9rem;
            }
        }
        @keyframes success-pop {
            0% { transform: scale(1); }
            40% { transform: scale(1.06); }
            100% { transform: scale(1); }
        }
        .success-pop { animation: success-pop 0.45s ease-out; }
        @keyframes quiz-card-wrong-pulse {
            0%, 100% { box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); }
            50% { box-shadow: 0 0 0 6px rgba(244 63 94 / 0.15); }
        }
        #quizCard.quiz-card--wrong {
            animation: quiz-card-wrong-pulse 0.36s ease-out 2;
            border-color: rgb(251 113 133);
            background-color: rgb(255 241 242);
        }
        @keyframes quiz-card-correct-glow {
            0% { box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); }
            45% { box-shadow: 0 0 0 6px rgba(161 161 170 / 0.35); }
            100% { box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); }
        }
        #quizCard.quiz-card--correct {
            animation: quiz-card-correct-glow 0.55s ease-out;
            border-color: rgb(161 161 170);
            background-color: rgb(250 250 250);
        }
        html.dark #quizCard.quiz-card--correct {
            background-color: rgba(39 39 42 / 0.65);
            border-color: rgb(113 113 122);
        }
        html.dark #quizCard.quiz-card--wrong {
            border-color: rgb(225 29 72);
            background-color: rgba(136 19 55 / 0.25);
        }
        .quiz-card-emoji-layer {
            position: absolute;
            inset: 0;
            pointer-events: none;
            z-index: 15;
            overflow: visible;
        }
        .quiz-fly-emoji {
            position: absolute;
            font-size: clamp(1.1rem, 4vw, 1.65rem);
            line-height: 1;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
            animation: quiz-emoji-fly 1.35s ease-out forwards;
        }
        @keyframes quiz-emoji-fly {
            0% { transform: translate(-50%, -50%) scale(0.15); opacity: 0; }
            12% { opacity: 1; transform: translate(-50%, -50%) scale(1); }
            100% { transform: translate(calc(-50% + var(--dx, 0px)), calc(-50% + var(--dy, 0px))) scale(0.85) rotate(var(--rot, 12deg)); opacity: 0; }
        }
        .quiz-fly-emoji--sparkle {
            font-size: clamp(0.95rem, 3.5vw, 1.35rem);
            filter: drop-shadow(0 0 4px rgba(250, 204, 21, 0.95));
            animation: quiz-sparkle-twirl 1.15s ease-out forwards;
        }
        @keyframes quiz-sparkle-twirl {
            0% { transform: translate(-50%, -50%) scale(0) rotate(-40deg); opacity: 0; }
            18% { opacity: 1; transform: translate(-50%, -50%) scale(1.15) rotate(12deg); }
            100% { transform: translate(calc(-50% + var(--dx, 0px)), calc(-50% + var(--dy, 0px))) scale(0.5) rotate(var(--rot, 180deg)); opacity: 0; }
        }
        .quiz-fly-sparkle {
            position: absolute;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            margin-left: -3px;
            margin-top: -3px;
            background: radial-gradient(circle at 30% 30%, #fff 0%, #fef9c3 35%, #facc15 65%, transparent 88%);
            box-shadow:
                0 0 4px 1px rgba(254, 240, 138, 0.95),
                0 0 10px 3px rgba(250, 204, 21, 0.55),
                0 0 16px 5px rgba(255, 255, 255, 0.35);
            animation: quiz-sparkle-particle 1.05s ease-out forwards;
        }
        .quiz-fly-sparkle--diamond {
            width: 5px;
            height: 5px;
            border-radius: 1px;
            background: linear-gradient(135deg, #fff 0%, #fde047 50%, #f59e0b 100%);
            box-shadow: 0 0 6px 2px rgba(253, 224, 71, 0.85);
        }
        @keyframes quiz-sparkle-particle {
            0% { transform: translate(-50%, -50%) scale(0); opacity: 0; }
            15% { opacity: 1; transform: translate(-50%, -50%) scale(1); }
            100% { transform: translate(calc(-50% + var(--sx, 0px)), calc(-50% + var(--sy, 0px))) scale(0.2); opacity: 0; }
        }
        .quiz-card-shimmer {
            position: absolute;
            inset: -3px;
            border-radius: 1.5rem;
            pointer-events: none;
            z-index: 12;
            overflow: hidden;
            background: conic-gradient(
                from 0deg at 50% 50%,
                transparent 0deg,
                rgba(255, 255, 255, 0.55) 25deg,
                transparent 55deg,
                rgba(254, 240, 138, 0.4) 90deg,
                transparent 120deg,
                rgba(255, 255, 255, 0.45) 160deg,
                transparent 200deg
            );
            animation: quiz-shimmer-spin 0.85s ease-out forwards;
            opacity: 0;
        }
        @keyframes quiz-shimmer-spin {
            0% { transform: rotate(-30deg) scale(0.85); opacity: 0; }
            12% { opacity: 0.95; }
            100% { transform: rotate(220deg) scale(1.15); opacity: 0; }
        }
    </style>
</head>
<body class="min-h-screen touch-manipulation bg-stone-100 pb-6 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100">

<div id="quizIntroOverlay" class="fixed inset-0 z-[200] flex flex-col items-center justify-center bg-white p-4 sm:p-6 dark:bg-zinc-950" role="dialog" aria-modal="true" aria-labelledby="quizIntroMsg"<?php echo $showQuizIntro ? '' : ' style="display:none;" aria-hidden="true"'; ?>>
    <div id="quizIntroMount" class="w-full max-w-lg"></div>
</div>

<div id="quizOutroOverlay" class="fixed inset-0 z-[210] flex flex-col items-center justify-center bg-white p-4 sm:p-6 dark:bg-zinc-950" role="dialog" aria-modal="true" aria-hidden="true" style="display:none;">
    <div id="quizOutroMount" class="w-full max-w-lg"></div>
</div>

<div id="quizAppShell" class="min-h-screen touch-manipulation bg-stone-100 dark:bg-zinc-950">
<div class="sticky top-0 z-30 border-b border-zinc-200/90 bg-white/95 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/95">
    <div class="mx-auto flex max-w-lg items-center gap-3 px-4 py-3">
        <a href="<?php echo htmlspecialchars(trytest_home_url(), ENT_QUOTES, 'UTF-8'); ?>" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-zinc-200 bg-white text-lg text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700" aria-label="Back to dashboard">←</a>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-bold dark:text-zinc-100"><?php echo htmlspecialchars($quizTitle, ENT_QUOTES, 'UTF-8'); ?></p>
            <p id="progressLabel" class="text-[11px] text-zinc-500 dark:text-zinc-400"></p>
        </div>
        <span id="quizStatus" class="shrink-0 rounded-full border border-zinc-300 bg-zinc-100 px-2.5 py-1 text-[10px] font-semibold text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200">…</span>
    </div>
    <div class="mx-auto max-w-lg px-4 pb-3">
        <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
            <div id="progressBar" class="h-full rounded-full bg-zinc-600 transition-all duration-500 dark:bg-zinc-400" style="width: 0%;"></div>
        </div>
    </div>
</div>

<main class="mx-auto max-w-lg px-4 pt-4">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-zinc-200/90 bg-white px-3 py-3 text-sm shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div>
            <p class="text-[10px] uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Score</p>
            <p class="text-xl font-extrabold text-zinc-900 dark:text-zinc-50"><span id="scoreValue">0</span><span class="text-sm font-normal text-zinc-400 dark:text-zinc-500"> / </span><span id="totalValue" class="text-zinc-700 dark:text-zinc-200">0</span></p>
        </div>
        <div class="text-right">
            <p class="text-[10px] uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Time</p>
            <p id="timerLabel" class="text-lg font-bold text-zinc-700 dark:text-zinc-200"><?php
                if ($effectiveDurationSeconds > 0) {
                    $tm = intdiv($effectiveDurationSeconds, 60);
                    $ts = $effectiveDurationSeconds % 60;
                    echo htmlspecialchars(sprintf('%d:%02d', $tm, $ts), ENT_QUOTES, 'UTF-8');
                } else {
                    echo '—';
                }
            ?></p>
        </div>
    </div>

    <div class="relative touch-manipulation overflow-visible rounded-2xl border border-zinc-200/90 bg-white p-4 shadow-md transition-[border-color,box-shadow] duration-300 dark:border-zinc-700 dark:bg-zinc-900" id="quizCard">
        <div id="questionBox"></div>
    </div>
</main>
</div>

<script>
window.QUIZ_CONFIG = {
    quizId: <?php echo json_encode($quizId, JSON_THROW_ON_ERROR); ?>,
    userId: <?php echo json_encode((int) ($_SESSION['user_id'] ?? 0), JSON_THROW_ON_ERROR); ?>,
    durationSeconds: <?php echo json_encode($effectiveDurationSeconds, JSON_THROW_ON_ERROR); ?>,
    quizAdEnabled: <?php echo json_encode((bool) ($quizAdConfig['enabled'] ?? false), JSON_THROW_ON_ERROR); ?>,
    quizAdEvery: <?php echo json_encode((int) ($quizAdConfig['every'] ?? 20), JSON_THROW_ON_ERROR); ?>,
    quizAdWatchSeconds: <?php echo json_encode((int) ($quizAdConfig['watch_seconds'] ?? 20), JSON_THROW_ON_ERROR); ?>,
    quizAdVideos: <?php echo json_encode((array) ($quizAdConfig['videos'] ?? []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); ?>,
    priorAttempt: <?php echo json_encode($hasPriorAttempt, JSON_THROW_ON_ERROR); ?>,
    resetAttemptUrl: <?php echo json_encode(trytest_url('reset_quiz_attempt'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); ?>,
    quizIntroSeconds: <?php echo json_encode($quizIntroSeconds, JSON_THROW_ON_ERROR); ?>,
    showQuizIntro: <?php echo json_encode($showQuizIntro, JSON_THROW_ON_ERROR); ?>,
    examWelcomeQuote: <?php echo json_encode($examWelcomeQuote, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); ?>,
    examWelcomeImage: <?php echo json_encode($examWelcomeImageUrl, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); ?>,
    examOutroImage: <?php echo json_encode($examOutroImageUrl, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); ?>,
    quizAuthorName: <?php echo json_encode($quizAuthorName, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); ?>
};
window.TRYTEST_WEB_BASE = <?php echo json_encode(trytest_base_path(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script>
(function () {
    function blockGesture(e) {
        e.preventDefault();
    }
    if (typeof document !== 'undefined' && document.addEventListener) {
        document.addEventListener('gesturestart', blockGesture, { passive: false });
        document.addEventListener('gesturechange', blockGesture, { passive: false });
        document.addEventListener('gestureend', blockGesture, { passive: false });
    }
})();
</script>
<?php trytest_student_theme_controller_script(); ?>
<script src="<?php echo htmlspecialchars(trytest_url('quiz.js?v=' . (string) @filemtime(__DIR__ . '/quiz.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
