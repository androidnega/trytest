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
$quizLevel = (string) ($quizRow['level'] ?? '');
$userLevel = (string) $_SESSION['user_level'];
if ($quizLevel !== '' && $quizLevel !== $userLevel) {
    http_response_code(403);
    echo 'You are not allowed to access this quiz.';
    exit;
}

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
        @keyframes success-pop {
            0% { transform: scale(1); }
            40% { transform: scale(1.06); }
            100% { transform: scale(1); }
        }
        .success-pop { animation: success-pop 0.45s ease-out; }
        @keyframes wrong-flash {
            0%, 100% { opacity: 0; }
            40% { opacity: 1; }
        }
        #wrongFlash {
            pointer-events: none;
            position: fixed;
            inset: 0;
            z-index: 50;
            background: rgba(245, 34, 45, 0.35);
        }
    </style>
</head>
<body class="min-h-screen bg-white text-slate-900 pb-6">

<div id="wrongFlash" class="hidden" aria-hidden="true"></div>

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

    <div class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm" id="quizCard">
        <div id="questionBox"></div>
    </div>
</main>

<script>
window.QUIZ_CONFIG = {
    quizId: <?php echo json_encode($quizId, JSON_THROW_ON_ERROR); ?>,
    durationSeconds: <?php echo json_encode($effectiveDurationSeconds, JSON_THROW_ON_ERROR); ?>
};
window.TRYTEST_WEB_BASE = <?php echo json_encode(trytest_base_path(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="quiz.js"></script>
</body>
</html>
