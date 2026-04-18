<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/student_helpers.php';

if (empty($_SESSION['user_id']) || empty($_SESSION['user_level'])) {
    trytest_redirect(trytest_home_url());
}

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
$priorAttemptStmt = $db->prepare('SELECT 1 FROM scores WHERE quiz_id = ? AND user_id = ? LIMIT 1');
$priorAttemptStmt->execute([$quizId, (int) ($_SESSION['user_id'] ?? 0)]);
$hasPriorAttempt = (bool) $priorAttemptStmt->fetchColumn();

require_once __DIR__ . '/includes/exam_quotes_unique.php';
$studentIdForIntro = (int) ($_SESSION['user_id'] ?? 0);
$examWelcomeQuote = trytest_exam_quote_for_quiz($studentIdForIntro, $quizId);
$examWelcomeImageUrl = trytest_url('KofiEmma.jpg');
$quizIntroSeconds = 5;

require_once __DIR__ . '/includes/youtube_subscribe.php';
$ytSettings = trytest_youtube_settings();
$ytBanner = trytest_youtube_promo_banner_html($ytSettings);
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Opens soon · <?php echo htmlspecialchars($quizTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-white text-slate-900 flex items-center justify-center p-6">
    <div class="max-w-md w-full rounded-3xl border border-slate-200 p-6 text-center space-y-4">
        <p class="text-xs font-bold uppercase tracking-widest text-amber-600">Not open yet</p>
        <h1 class="text-xl font-bold"><?php echo htmlspecialchars($quizTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($openLabel !== ''): ?>
            <p class="text-sm text-slate-600">Scheduled: <?php echo htmlspecialchars($openLabel, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <p id="openCountdown" class="text-2xl font-mono font-bold text-[#2C6A7D]"></p>
        <?php if ($ytBanner !== ''): ?>
            <div class="mt-3 text-left"><?php echo $ytBanner; ?></div>
        <?php endif; ?>
        <a href="<?php echo htmlspecialchars(trytest_home_url(), ENT_QUOTES, 'UTF-8'); ?>" class="inline-block w-full rounded-2xl bg-[#E50914] py-3 text-sm font-bold text-white">Back to dashboard</a>
    </div>
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
</body>
</html>
    <?php
    exit;
}

if ($schedulePhase === 'after') {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Closed · <?php echo htmlspecialchars($quizTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-white text-slate-900 flex items-center justify-center p-6">
    <div class="max-w-md w-full rounded-3xl border border-slate-200 p-6 text-center space-y-3">
        <p class="text-xs font-bold uppercase tracking-widest text-slate-500">Quiz window closed</p>
        <h1 class="text-xl font-bold"><?php echo htmlspecialchars($quizTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="text-sm text-slate-600">This quiz is no longer accepting attempts.</p>
        <?php if ($ytBanner !== ''): ?>
            <div class="mt-3 text-left"><?php echo $ytBanner; ?></div>
        <?php endif; ?>
        <a href="<?php echo htmlspecialchars(trytest_home_url(), ENT_QUOTES, 'UTF-8'); ?>" class="mt-4 inline-block w-full rounded-2xl bg-slate-900 py-3 text-sm font-bold text-white">Back to dashboard</a>
    </div>
</body>
</html>
    <?php
    exit;
}

$now = time();
$endsTs = null;
if ($endsRaw !== '') {
    $endParsed = strtotime($endsRaw);
    if ($endParsed !== false) {
        $endsTs = (int) $endParsed;
    }
}
$durationSec = $durationMinutes > 0 ? $durationMinutes * 60 : 0;
$untilEnd = ($endsTs !== null && $now < $endsTs) ? max(0, $endsTs - $now) : null;
if ($durationSec > 0) {
    $effectiveDurationSeconds = $untilEnd !== null ? min($durationSec, $untilEnd) : $durationSec;
} elseif ($untilEnd !== null && $untilEnd > 0) {
    $effectiveDurationSeconds = $untilEnd;
} else {
    $effectiveDurationSeconds = 0;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?php echo htmlspecialchars($quizTitle, ENT_QUOTES, 'UTF-8'); ?> · Trytest</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(trytest_url('favicon.svg'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root { color-scheme: light; }
        body { font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif; }
        .trytest-exam-welcome-img {
            max-height: min(52vh, 280px);
        }
        @media (min-width: 640px) {
            .trytest-exam-welcome-img {
                max-height: 300px;
            }
        }
        @keyframes success-pop {
            0% { transform: scale(1); }
            40% { transform: scale(1.06); }
            100% { transform: scale(1); }
        }
        .success-pop { animation: success-pop 0.45s ease-out; }
        @keyframes quiz-card-wrong-pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
            50% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0.28); }
        }
        #quizCard.quiz-card--wrong {
            animation: quiz-card-wrong-pulse 0.36s ease-out 2;
            border-color: rgb(248 113 113);
        }
        @keyframes quiz-card-correct-glow {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
            45% { box-shadow: 0 0 28px rgba(16, 185, 129, 0.4); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
        #quizCard.quiz-card--correct {
            animation: quiz-card-correct-glow 0.65s ease-out;
            border-color: rgb(52 211 153);
            background-color: rgb(236 253 245);
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
<body class="min-h-screen bg-white text-slate-900 pb-6">

<div class="sticky top-0 z-30 border-b border-slate-200 bg-white backdrop-blur">
    <div class="mx-auto flex max-w-lg items-center gap-3 px-4 py-3">
        <a href="<?php echo htmlspecialchars(trytest_home_url(), ENT_QUOTES, 'UTF-8'); ?>" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-lg text-slate-700 hover:bg-slate-100" aria-label="Back to dashboard">←</a>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-bold"><?php echo htmlspecialchars($quizTitle, ENT_QUOTES, 'UTF-8'); ?></p>
            <p id="progressLabel" class="text-[11px] text-slate-500"></p>
        </div>
        <span id="quizStatus" class="shrink-0 rounded-full border border-[#84B8B8] bg-[#84B8B8]/20 px-2.5 py-1 text-[10px] font-semibold text-[#2C6A7D]">…</span>
    </div>
    <div class="mx-auto max-w-lg px-4 pb-3">
        <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
            <div id="progressBar" class="h-full rounded-full bg-[#E50914] transition-all duration-500" style="width: 0%;"></div>
        </div>
    </div>
</div>

<main class="mx-auto max-w-lg px-4 pt-4">
    <?php if ($ytBanner !== ''): ?>
        <div class="mb-3"><?php echo $ytBanner; ?></div>
    <?php endif; ?>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm">
        <div>
            <p class="text-[10px] uppercase tracking-wider text-slate-500">Score</p>
            <p class="text-xl font-extrabold text-[#E50914]"><span id="scoreValue">0</span><span class="text-sm font-normal text-slate-400"> / </span><span id="totalValue" class="text-slate-700">0</span></p>
        </div>
        <div class="text-right">
            <p class="text-[10px] uppercase tracking-wider text-slate-500">Time</p>
            <p id="timerLabel" class="text-lg font-bold text-[#2C6A7D]"><?php
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

    <div class="relative overflow-visible rounded-3xl border border-slate-200 bg-white p-4 shadow-sm transition-[border-color,box-shadow] duration-300" id="quizCard">
        <div id="questionBox"></div>
    </div>
</main>

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
    examWelcomeQuote: <?php echo json_encode($examWelcomeQuote, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); ?>,
    examWelcomeImage: <?php echo json_encode($examWelcomeImageUrl, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); ?>
};
window.TRYTEST_WEB_BASE = <?php echo json_encode(trytest_base_path(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="<?php echo htmlspecialchars(trytest_url('quiz.js?v=' . (string) @filemtime(__DIR__ . '/quiz.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
