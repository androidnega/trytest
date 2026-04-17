<?php

declare(strict_types=1);

/** @var string $dashboardUrl */
/** @var string $quizUrlBase */
/** @var string $downloadsPageUrl */
/** @var string $quizSchedulesPollUrl */
/** @var int $downloadsBadgeCount */
/** @var int $userId */
/** @var string $userIndex */
/** @var string $userLevel */
/** @var string $userDepartment */
/** @var string $userDisplayName */
/** @var int $totalPoints */
/** @var string $activeTab */
/** @var list<array<string,mixed>> $coursesWithQuizzes */
/** @var list<array<string,mixed>> $recentAttempts */
/** @var list<array<string,mixed>> $levelLeaderboardRows */
/** @var list<array<string,mixed>> $departmentOptions */
/** @var bool $needsDepartmentSetup */
/** @var string $departmentUpdateError */
/** @var array<string,mixed>|null $doneBlock */

$h = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};

$tabHome = $activeTab === 'home';
$tabRank = $activeTab === 'rank';
$homeNavOn = $tabHome && empty(($doneBlock ?? [])['quiz_id'] ?? null);
$deptLabel = $userDepartment !== '' ? $userDepartment : 'All programs';
$needsDepartmentSetup = !empty($needsDepartmentSetup);
$departmentUpdateError = trim((string) ($departmentUpdateError ?? ''));
$downloadsBadgeCount = max(0, (int) ($downloadsBadgeCount ?? 0));
$downloadsNavBadge = '';
if ($downloadsBadgeCount > 0) {
    $dn = $downloadsBadgeCount > 9 ? '9+' : (string) $downloadsBadgeCount;
    $downloadsNavBadge = '<span class="absolute -right-1 -top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-[#E50914] px-1 text-[9px] font-extrabold leading-none text-white" aria-label="'
        . $h((string) $downloadsBadgeCount . ' new or not yet downloaded')
        . '">' . $h($dn) . '</span>';
}

$navClass = static function (bool $on): string {
    return $on
        ? 'flex flex-1 flex-col items-center gap-1 py-2 text-[#E50914]'
        : 'flex flex-1 flex-col items-center gap-1 py-2 text-slate-500 hover:text-slate-700';
};
?>
<div class="min-h-screen bg-white text-slate-900 pb-24 md:pb-8">
    <header class="sticky top-0 z-30 border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl flex-nowrap items-center justify-between gap-2 px-3 py-2.5 sm:gap-3 sm:px-4 sm:py-3">
            <div class="flex min-w-0 flex-1 items-center gap-2 sm:gap-3">
                <div class="h-10 w-10 shrink-0 overflow-hidden rounded-full bg-gradient-to-br from-slate-100 to-slate-200 ring-1 ring-slate-200/80 [&>svg]:h-full [&>svg]:w-full sm:h-11 sm:w-11">
                    <?php echo trytest_student_avatar_svg($userIndex, 44, $userId); ?>
                </div>
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold leading-tight text-slate-900"><?php echo $h($userDisplayName); ?></p>
                    <p class="truncate text-[10px] text-slate-500 sm:text-[11px]">Lv&nbsp;<?php echo $h($userLevel); ?> · <?php echo $h($deptLabel); ?></p>
                </div>
            </div>
            <div class="flex shrink-0 flex-nowrap items-center gap-1.5 sm:gap-2">
                <div class="inline-flex shrink-0 items-baseline gap-1 whitespace-nowrap rounded-full bg-[#2C6A7D]/10 px-2 py-1 text-[11px] font-bold tabular-nums text-[#2C6A7D] sm:px-3 sm:text-sm">
                    <span class="font-semibold text-[#2C6A7D]/75">Pts</span>
                    <span><?php echo (int) $totalPoints; ?></span>
                </div>
                <div class="relative shrink-0">
                    <button type="button" id="profileMenuBtn" class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-lg leading-none text-slate-700 hover:bg-slate-200 sm:h-10 sm:w-10" aria-expanded="false" aria-haspopup="true" aria-label="Account menu">⋯</button>
                    <div id="profileMenu" class="hidden absolute right-0 mt-2 w-56 rounded-xl border border-slate-200 bg-white py-2 shadow-lg" role="menu">
                        <p class="px-3 text-xs font-semibold text-slate-900">Profile</p>
                        <p class="mt-0.5 truncate px-3 text-[11px] text-slate-500"><?php echo $h($userIndex); ?></p>
                        <div class="mt-2 space-y-1 border-t border-slate-100 px-3 py-2 text-xs text-slate-600">
                            <p class="flex justify-between gap-2"><span class="text-slate-400">Level</span><span class="font-medium text-slate-800"><?php echo $h($userLevel); ?></span></p>
                            <p class="flex justify-between gap-2"><span class="text-slate-400">Program</span><span class="min-w-0 truncate text-right font-medium text-slate-800"><?php echo $h($deptLabel); ?></span></p>
                            <p class="flex justify-between gap-2"><span class="text-slate-400">Points</span><span class="font-semibold tabular-nums text-[#2C6A7D]"><?php echo (int) $totalPoints; ?></span></p>
                        </div>
                        <a href="<?php echo $h($downloadsPageUrl); ?>" class="relative block border-t border-slate-100 px-3 py-2 text-sm font-medium text-[#2C6A7D] hover:bg-slate-50">Downloads<?php echo $downloadsNavBadge; ?></a>
                        <form method="post" class="border-t border-slate-100 px-2 pt-2">
                            <input type="hidden" name="action" value="logout_user">
                            <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm text-[#E50914] hover:bg-red-50" role="menuitem">Log out</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="mx-auto hidden max-w-5xl flex-nowrap items-center justify-center gap-8 pb-2 text-sm font-semibold md:flex">
            <a href="<?php echo $h($dashboardUrl); ?>" class="whitespace-nowrap <?php echo $homeNavOn ? 'text-[#E50914]' : 'text-slate-500 hover:text-slate-700'; ?>">Home</a>
            <a href="<?php echo $h($dashboardUrl); ?>?tab=rank" class="whitespace-nowrap <?php echo $tabRank ? 'text-[#E50914]' : 'text-slate-500 hover:text-slate-700'; ?>">Leaderboard</a>
            <a href="<?php echo $h($downloadsPageUrl); ?>" class="relative whitespace-nowrap text-slate-500 hover:text-slate-700">Downloads<?php echo $downloadsNavBadge; ?></a>
        </div>
    </header>

    <main class="mx-auto w-full max-w-5xl px-4 pt-4">
        <?php if ($needsDepartmentSetup): ?>
            <section class="mb-4 rounded-xl border-2 border-amber-400 bg-amber-50 px-4 py-3 shadow-sm" role="region" aria-labelledby="dept-setup-title">
                <h2 id="dept-setup-title" class="text-sm font-bold text-amber-950">Choose your program</h2>
                <p class="mt-1 text-xs text-amber-900/90">Your account has no program set yet. Pick the one that matches you so quizzes and downloads line up with your class.</p>
                <?php if ($departmentUpdateError !== ''): ?>
                    <p class="mt-2 rounded-lg bg-red-100 px-3 py-2 text-xs font-medium text-red-800"><?php echo $h($departmentUpdateError); ?></p>
                <?php endif; ?>
                <form method="post" action="<?php echo $h($dashboardUrl); ?>" class="mt-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end">
                    <input type="hidden" name="action" value="update_student_department">
                    <label class="block min-w-0 flex-1 text-left">
                        <span class="mb-1 block text-[11px] font-medium text-amber-950/80">Program / department</span>
                        <select name="department" required class="w-full rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm font-medium text-slate-900 focus:outline-none focus:ring-2 focus:ring-amber-500">
                            <option value="">Select…</option>
                            <?php foreach ($departmentOptions as $depOpt): ?>
                                <option value="<?php echo $h((string) ($depOpt['value'] ?? '')); ?>"><?php echo $h((string) ($depOpt['label'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="w-full shrink-0 rounded-lg bg-[#2C6A7D] px-5 py-2 text-sm font-bold text-white hover:bg-[#24586a] sm:w-auto">Save program</button>
                </form>
            </section>
        <?php endif; ?>
        <?php if (is_array($doneBlock) && !empty($doneBlock['quiz_id'])): ?>
            <?php $acc = $doneBlock['total'] > 0 ? (int) round(100 * (int) $doneBlock['score'] / (int) $doneBlock['total']) : 0; ?>
            <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-center text-xs font-semibold uppercase tracking-widest text-[#E50914]">Quiz complete</p>
                <h2 class="mt-1 text-center text-lg font-bold"><?php echo $h((string) ($doneBlock['title'] ?? 'Quiz')); ?></h2>
                <div class="mx-auto mt-4 flex h-28 w-28 items-center justify-center rounded-full border-4 border-[#84B8B8] bg-white text-2xl font-extrabold tabular-nums text-[#2C6A7D]">
                    <span class="whitespace-nowrap"><?php echo (int) $doneBlock['score']; ?><span class="text-sm font-normal text-slate-400">/<?php echo (int) $doneBlock['total']; ?></span></span>
                </div>
                <p class="mt-3 text-center text-sm text-slate-600">Accuracy <?php echo $acc; ?>%</p>
                <?php if (($doneBlock['rank'] ?? null) !== null): ?>
                    <p class="text-center text-sm font-semibold text-[#2C6A7D]">Your rank on this quiz: #<?php echo (int) $doneBlock['rank']; ?></p>
                <?php endif; ?>
                <?php if (!empty($doneBlock['can_retry'])): ?>
                    <p class="mt-3 text-center text-xs leading-snug text-slate-600">You can <strong class="text-slate-800">take this quiz again</strong> while it stays open. Each round is saved; the leaderboard uses your best score.</p>
                    <a href="<?php echo $h($quizUrlBase); ?>?quiz_id=<?php echo (int) ($doneBlock['quiz_id'] ?? 0); ?>" class="mt-3 block w-full rounded-xl border-2 border-[#2C6A7D] bg-white py-3 text-center text-sm font-bold text-[#2C6A7D] shadow-sm hover:bg-cyan-50/80">Try again</a>
                <?php else: ?>
                    <p class="mt-3 text-center text-xs text-slate-500">This quiz is no longer accepting new attempts.</p>
                <?php endif; ?>
                <a href="<?php echo $h($dashboardUrl); ?>" class="mt-3 block w-full rounded-xl bg-[#E50914] py-3 text-center text-sm font-bold text-white">Back to home</a>
            </section>

            <section class="mb-8 rounded-2xl border border-slate-200 bg-white p-4">
                <h3 class="text-center text-sm font-bold">This quiz · leaderboard</h3>
                <?php echo trytest_render_quiz_podium_html($doneBlock['board'] ?? [], $userId, $h); ?>
            </section>
        <?php endif; ?>

        <?php if ($tabHome && (!is_array($doneBlock) || empty($doneBlock['quiz_id']))): ?>
            <div class="mb-4 grid grid-cols-2 gap-2">
                <a href="<?php echo $h($dashboardUrl); ?>?tab=rank" class="rounded-xl bg-gradient-to-br from-amber-50 to-white p-2.5 text-center ring-1 ring-amber-100/90 transition hover:ring-amber-200 sm:p-3">
                    <span class="text-lg sm:text-xl" aria-hidden="true">🏆</span>
                    <p class="mt-1 text-[10px] font-semibold leading-tight text-slate-800 sm:text-[11px]">Rank</p>
                </a>
                <a href="#section-courses" class="rounded-xl bg-gradient-to-br from-cyan-50 to-white p-2.5 text-center ring-1 ring-cyan-100/90 transition hover:ring-cyan-200 sm:p-3">
                    <span class="text-lg sm:text-xl" aria-hidden="true">📚</span>
                    <p class="mt-1 text-[10px] font-semibold leading-tight text-slate-800 sm:text-[11px]">Courses</p>
                </a>
            </div>

            <?php if ($recentAttempts): ?>
                <section class="mb-6">
                    <div class="mb-2 flex flex-nowrap items-center justify-between gap-2">
                        <h2 class="shrink-0 text-sm font-bold">Recent</h2>
                        <a class="shrink-0 whitespace-nowrap text-xs font-medium text-[#2C6A7D]" href="<?php echo $h($dashboardUrl); ?>?tab=rank">Ranks →</a>
                    </div>
                    <div class="space-y-2">
                        <?php foreach (array_slice($recentAttempts, 0, 5) as $att): ?>
                            <a href="<?php echo $h($quizUrlBase); ?>?quiz_id=<?php echo (int) ($att['quiz_id'] ?? 0); ?>" class="flex flex-nowrap items-center justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2.5 text-left ring-1 ring-slate-100 transition hover:bg-slate-100/80">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium"><?php echo $h((string) ($att['quiz_title'] ?? 'Quiz')); ?></p>
                                    <p class="truncate text-[10px] text-slate-500"><?php echo $h((string) ($att['created_at'] ?? '')); ?></p>
                                </div>
                                <span class="shrink-0 whitespace-nowrap rounded-full bg-[#2C6A7D]/10 px-2 py-0.5 text-xs font-bold tabular-nums text-[#2C6A7D]"><?php echo (int) ($att['score'] ?? 0); ?>/<?php echo (int) ($att['total'] ?? 0); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section id="section-courses" class="scroll-mt-20">
                <h2 class="mb-3 text-sm font-bold">Courses</h2>
                <?php if (!$coursesWithQuizzes): ?>
                    <p class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-500">No courses match your level<?php echo $userDepartment !== '' ? ' and program' : ''; ?> yet.</p>
                <?php else: ?>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <?php foreach ($coursesWithQuizzes as $course): ?>
                            <?php $cd = trim((string) ($course['department'] ?? '')); ?>
                            <article class="flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                                <div class="border-b border-slate-100 p-3">
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-[#2C6A7D]"><?php echo $h((string) ($course['code'] ?? '')); ?></p>
                                    <p class="text-sm font-semibold leading-snug"><?php echo $h((string) ($course['title'] ?? '')); ?></p>
                                    <?php if ($cd !== ''): ?><p class="mt-1 text-[10px] text-slate-500"><?php echo $h($cd); ?></p><?php endif; ?>
                                </div>
                                <div class="space-y-1.5 p-2">
                                    <?php foreach ($course['quizzes'] as $qz): ?>
                                        <?php
                                        $qid = (int) ($qz['id'] ?? 0);
                                        $qtitle = (string) ($qz['title'] ?? '');
                                        $qc = (int) ($qz['question_count'] ?? 0);
                                        $stRaw = isset($qz['quiz_starts_at']) ? trim((string) $qz['quiz_starts_at']) : '';
                                        $enRaw = isset($qz['quiz_ends_at']) ? trim((string) $qz['quiz_ends_at']) : '';
                                        $stTs = $stRaw !== '' ? strtotime($stRaw) : false;
                                        $enTs = $enRaw !== '' ? strtotime($enRaw) : false;
                                        $stSec = ($stTs !== false && $stTs > 0) ? (int) $stTs : '';
                                        $enSec = ($enTs !== false && $enTs > 0) ? (int) $enTs : '';
                                        $phase = trytest_quiz_schedule_phase(
                                            $stRaw !== '' ? $stRaw : null,
                                            $enRaw !== '' ? $enRaw : null
                                        );
                                        $canPlay = ($phase === 'open' || $phase === 'unset');
                                        $hasSchedule = ($stRaw !== '' || $enRaw !== '');
                                        ?>
                                        <div
                                            class="rounded-lg border border-slate-200 bg-white px-2 py-2 text-left trytest-quiz-card"
                                            data-quiz-id="<?php echo $qid; ?>"
                                            data-quiz-href="<?php echo $h($quizUrlBase); ?>?quiz_id=<?php echo $qid; ?>"
                                            data-quiz-start="<?php echo $stSec !== '' ? (string) $stSec : ''; ?>"
                                            data-quiz-end="<?php echo $enSec !== '' ? (string) $enSec : ''; ?>"
                                        >
                                            <div class="flex flex-nowrap items-start justify-between gap-2">
                                                <div class="min-w-0 flex-1 overflow-hidden">
                                                    <a href="<?php echo $h($quizUrlBase); ?>?quiz_id=<?php echo $qid; ?>" class="trytest-quiz-title block text-[11px] font-semibold leading-snug <?php echo $canPlay ? 'text-slate-800 hover:text-[#2C6A7D]' : 'pointer-events-none cursor-default text-slate-600'; ?>">
                                                        <?php echo $h($qtitle); ?>
                                                    </a>
                                                    <p class="mt-1 text-[10px] text-slate-500"><?php echo $qc < 1 ? 'No questions yet.' : ((string) $qc . ' questions · n/' . (string) $qc); ?></p>
                                                    <?php if ($canPlay && !empty($qz['user_has_attempt'])): ?>
                                                        <p class="mt-0.5 text-[9px] font-semibold leading-tight text-[#2C6A7D]">You have completed this before — tap the title to retry.</p>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($canPlay): ?>
                                                    <span class="trytest-quiz-badge shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-[9px] font-bold text-emerald-700">Open</span>
                                                <?php elseif ($phase === 'before'): ?>
                                                    <span class="trytest-quiz-badge shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-[9px] font-bold text-amber-800">Soon</span>
                                                <?php else: ?>
                                                    <span class="trytest-quiz-badge shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[9px] font-bold text-slate-500">Closed</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="trytest-quiz-countdown mt-2 hidden min-h-[2.75rem] w-full rounded-xl border px-2 py-2 text-center text-sm font-black tabular-nums leading-tight tracking-tight sm:min-h-[3rem] sm:px-3 sm:text-base" role="status" aria-live="polite"></div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($course['quizzes'])): ?>
                                        <p class="py-1 text-center text-[10px] text-slate-400">No quizzes</p>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($tabRank): ?>
            <section class="mb-4">
                <a href="<?php echo $h($dashboardUrl); ?>" class="text-sm text-[#2C6A7D]">← Home</a>
                <h2 class="mt-2 text-xl font-bold">Leaderboard</h2>
                <p class="mt-1 flex flex-nowrap items-center gap-x-2 overflow-x-auto text-xs text-slate-600">
                    <span class="shrink-0 whitespace-nowrap font-medium text-slate-800">Lv&nbsp;<?php echo $h($userLevel); ?></span>
                    <span class="shrink-0 text-slate-300" aria-hidden="true">·</span>
                    <span class="min-w-0 shrink truncate"><?php echo $h($deptLabel); ?></span>
                    <span class="shrink-0 whitespace-nowrap tabular-nums text-[#2C6A7D]"><?php echo (int) $totalPoints; ?> pts</span>
                </p>
            </section>
            <?php echo trytest_render_level_podium_html($levelLeaderboardRows, $userId, $h); ?>
        <?php endif; ?>
    </main>

    <nav class="fixed bottom-0 left-0 right-0 z-50 border-t border-slate-200 bg-white md:hidden" style="padding-bottom: max(0.5rem, env(safe-area-inset-bottom));">
        <div class="mx-auto flex max-w-5xl">
            <a href="<?php echo $h($dashboardUrl); ?>" class="<?php echo $h($navClass($homeNavOn)); ?>">
                <span class="text-lg">⌂</span>
                <span class="text-[10px] font-semibold">Home</span>
            </a>
            <a href="<?php echo $h($dashboardUrl); ?>?tab=rank" class="<?php echo $h($navClass($tabRank)); ?>">
                <span class="text-lg">🏆</span>
                <span class="text-[10px] font-semibold">Rank</span>
            </a>
            <a href="<?php echo $h($downloadsPageUrl); ?>" class="<?php echo $h($navClass(false)); ?> relative">
                <span class="text-lg">📥</span>
                <?php if ($downloadsBadgeCount > 0): ?>
                    <span class="absolute right-[22%] top-0.5 flex h-[15px] min-w-[15px] items-center justify-center rounded-full bg-[#E50914] px-0.5 text-[8px] font-extrabold leading-none text-white" aria-label="<?php echo (int) $downloadsBadgeCount; ?> pending"><?php echo $downloadsBadgeCount > 9 ? '9+' : (string) (int) $downloadsBadgeCount; ?></span>
                <?php endif; ?>
                <span class="text-[10px] font-semibold">Files</span>
            </a>
        </div>
    </nav>
</div>
<script>
(function () {
    var btn = document.getElementById('profileMenuBtn');
    var menu = document.getElementById('profileMenu');
    if (!btn || !menu) return;
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = !menu.classList.contains('hidden');
        menu.classList.toggle('hidden', open);
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
    });
    document.addEventListener('click', function () {
        menu.classList.add('hidden');
        btn.setAttribute('aria-expanded', 'false');
    });
    menu.addEventListener('click', function (e) { e.stopPropagation(); });
})();

(function () {
    function pad2(n) { return String(n).padStart(2, '0'); }
    function formatMinSec(ms) {
        if (ms <= 0) return '0:00';
        var totalSec = Math.floor(ms / 1000);
        var m = Math.floor(totalSec / 60);
        var sec = totalSec % 60;
        return String(m) + ':' + pad2(sec);
    }
    function countdownBase() {
        return 'trytest-quiz-countdown mt-2 min-h-[2.75rem] w-full rounded-xl border px-2 py-2 text-center text-sm font-black tabular-nums leading-tight tracking-tight sm:min-h-[3rem] sm:px-3 sm:text-base ';
    }
    function cardTimes(card) {
        var sRaw = card.getAttribute('data-quiz-start') || '';
        var eRaw = card.getAttribute('data-quiz-end') || '';
        var s = sRaw ? parseInt(sRaw, 10) * 1000 : 0;
        var e = eRaw ? parseInt(eRaw, 10) * 1000 : 0;
        return { s: s, e: e };
    }
    function phaseKey(now, s, e) {
        if (!s && !e) return 'unset';
        if (s && now < s) return 'before';
        if (e && now >= e) return 'after';
        return 'open';
    }
    function setQuizBadge(badge, key) {
        if (!badge) return;
        var base = 'trytest-quiz-badge shrink-0 rounded-full px-2 py-0.5 text-[9px] font-bold ';
        if (key === 'before') {
            badge.className = base + 'bg-amber-50 text-amber-800';
            badge.textContent = 'Soon';
        } else if (key === 'after') {
            badge.className = base + 'bg-slate-100 text-slate-500';
            badge.textContent = 'Closed';
        } else {
            badge.className = base + 'bg-emerald-50 text-emerald-700';
            badge.textContent = 'Open';
        }
    }
    function tick() {
        var now = Date.now();
        document.querySelectorAll('.trytest-quiz-card').forEach(function (card) {
            var t = cardTimes(card);
            var s = t.s;
            var e = t.e;
            var pk = phaseKey(now, s, e);
            var canPlay = pk === 'open' || pk === 'unset';
            var badge = card.querySelector('.trytest-quiz-badge');
            var title = card.querySelector('.trytest-quiz-title');
            if (pk === 'unset') {
                setQuizBadge(badge, 'open');
            } else {
                setQuizBadge(badge, pk);
            }
            if (title) {
                if (canPlay) {
                    title.className = 'trytest-quiz-title block text-[11px] font-semibold leading-snug text-slate-800 hover:text-[#2C6A7D]';
                } else {
                    title.className = 'trytest-quiz-title block text-[11px] font-semibold leading-snug pointer-events-none cursor-default text-slate-600';
                }
            }
            var el = card.querySelector('.trytest-quiz-countdown');
            if (!el) return;
            if (!s && !e) {
                el.textContent = '';
                el.className = countdownBase() + 'hidden';
                return;
            }
            if (s && now < s) {
                el.className = countdownBase() + 'border-amber-300 bg-amber-100 text-amber-950 shadow-inner';
                el.innerHTML = '<span class="block text-[10px] font-bold uppercase tracking-wide text-amber-800/90 sm:text-[11px]">Opens in</span><span class="mt-0.5 block text-lg sm:text-2xl">' + formatMinSec(s - now) + '</span>';
                return;
            }
            if (e && now < e) {
                el.className = countdownBase() + 'border-sky-400 bg-sky-100 text-sky-950 shadow-inner';
                el.innerHTML = '<span class="block text-[10px] font-bold uppercase tracking-wide text-sky-900/90 sm:text-[11px]">Closes in</span><span class="mt-0.5 block text-lg sm:text-2xl">' + formatMinSec(e - now) + '</span>';
                return;
            }
            if (e && now >= e) {
                el.className = countdownBase() + 'border-slate-300 bg-slate-200 text-slate-700';
                el.textContent = 'Ended';
                return;
            }
            if (s && now >= s && (!e || now <= e)) {
                el.className =
                    countdownBase() +
                    'border-emerald-300 bg-emerald-100 text-emerald-950 shadow-inner ring-1 ring-emerald-200/80';
                var playHref = card.getAttribute('data-quiz-href') || '';
                el.textContent = '';
                if (playHref) {
                    var link = document.createElement('a');
                    link.href = playHref;
                    link.className =
                        'trytest-quiz-start-link block w-full cursor-pointer rounded-lg py-1.5 text-lg font-black tabular-nums tracking-tight text-emerald-950 no-underline outline-none ring-0 transition hover:bg-emerald-200/70 focus-visible:ring-2 focus-visible:ring-emerald-600 sm:text-2xl';
                    link.setAttribute('aria-label', 'Start quiz');
                    link.textContent = 'Start now';
                    el.appendChild(link);
                } else {
                    el.textContent = 'Start now';
                }
                return;
            }
            el.textContent = '';
            el.className = countdownBase() + 'hidden';
        });
    }
    tick();
    setInterval(tick, 1000);

    var pollUrl = <?php echo json_encode($quizSchedulesPollUrl ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    if (pollUrl) {
        function mergeSchedules(data) {
            if (!data || !data.ok || !data.quizzes) return;
            var map = {};
            data.quizzes.forEach(function (q) {
                var id = parseInt(q.quiz_id, 10);
                if (!id) return;
                map[id] = q;
            });
            document.querySelectorAll('.trytest-quiz-card').forEach(function (card) {
                var id = parseInt(card.getAttribute('data-quiz-id') || '0', 10);
                var row = map[id];
                if (!row) return;
                if (row.start != null) {
                    card.setAttribute('data-quiz-start', String(row.start));
                } else {
                    card.setAttribute('data-quiz-start', '');
                }
                if (row.end != null) {
                    card.setAttribute('data-quiz-end', String(row.end));
                } else {
                    card.setAttribute('data-quiz-end', '');
                }
            });
            tick();
        }
        function pollOnce() {
            fetch(pollUrl, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(mergeSchedules)
                .catch(function () {});
        }
        setTimeout(pollOnce, 4000);
        setInterval(pollOnce, 45000);
    }
})();
</script>
