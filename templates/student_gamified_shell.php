<?php

declare(strict_types=1);

/** @var string $dashboardUrl */
/** @var string $quizUrlBase */
/** @var string $downloadResourceBase */
/** @var bool $youtubePdfGateActive */
/** @var list<array<string,mixed>> $studentDocuments */
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
/** @var array<string,mixed>|null $doneBlock */

$h = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};

$tabHome = $activeTab === 'home';
$tabRank = $activeTab === 'rank';
$tabProfile = $activeTab === 'profile';
$homeNavOn = $tabHome && empty(($doneBlock ?? [])['quiz_id'] ?? null);
$deptLabel = $userDepartment !== '' ? $userDepartment : 'All programs';

$navClass = static function (bool $on): string {
    return $on
        ? 'flex flex-1 flex-col items-center gap-1 py-2 text-[#E50914]'
        : 'flex flex-1 flex-col items-center gap-1 py-2 text-slate-500 hover:text-slate-700';
};
?>
<div class="min-h-screen bg-white text-slate-900 pb-24 md:pb-8">
    <header class="sticky top-0 z-30 border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3">
            <div class="flex min-w-0 items-center gap-3">
                <div class="h-12 w-12 shrink-0 overflow-hidden rounded-xl border border-slate-200 bg-white [&>svg]:h-full [&>svg]:w-full">
                    <?php echo trytest_student_avatar_svg($userIndex, 48); ?>
                </div>
                <div class="min-w-0">
                    <p class="text-xs text-slate-500">Hello</p>
                    <p class="truncate font-semibold"><?php echo $h($userDisplayName); ?></p>
                    <p class="truncate text-[11px] text-slate-500">Level <?php echo $h($userLevel); ?> · <?php echo $h($deptLabel); ?></p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <div class="rounded-full border border-slate-200 bg-white px-3 py-1 text-sm font-semibold text-[#2C6A7D]">
                    Points: <?php echo (int) $totalPoints; ?>
                </div>
                <div class="relative">
                    <button type="button" id="profileMenuBtn" class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700" aria-expanded="false" aria-haspopup="true" aria-label="Account menu">⋮</button>
                    <div id="profileMenu" class="hidden absolute right-0 mt-2 w-52 rounded-xl border border-slate-200 bg-white py-2 shadow-lg" role="menu">
                        <p class="border-b border-slate-100 px-3 py-2 text-xs text-slate-500">Signed in</p>
                        <p class="px-3 py-1 text-sm font-medium"><?php echo $h($userIndex); ?></p>
                        <form method="post" class="border-t border-slate-100 px-2 pt-2">
                            <input type="hidden" name="action" value="logout_user">
                            <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm text-[#E50914] hover:bg-red-50" role="menuitem">Log out</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="mx-auto hidden max-w-5xl items-center justify-center gap-8 pb-2 text-sm font-semibold md:flex">
            <a href="<?php echo $h($dashboardUrl); ?>" class="<?php echo $homeNavOn ? 'text-[#E50914]' : 'text-slate-500 hover:text-slate-700'; ?>">Home</a>
            <a href="<?php echo $h($dashboardUrl); ?>?tab=rank" class="<?php echo $tabRank ? 'text-[#E50914]' : 'text-slate-500 hover:text-slate-700'; ?>">Leaderboard</a>
            <a href="<?php echo $h($dashboardUrl); ?>?tab=profile" class="<?php echo $tabProfile ? 'text-[#E50914]' : 'text-slate-500 hover:text-slate-700'; ?>">Profile</a>
        </div>
    </header>

    <main class="mx-auto w-full max-w-5xl px-4 pt-4">
        <?php if (is_array($doneBlock) && !empty($doneBlock['quiz_id'])): ?>
            <?php $acc = $doneBlock['total'] > 0 ? (int) round(100 * (int) $doneBlock['score'] / (int) $doneBlock['total']) : 0; ?>
            <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-center text-xs font-semibold uppercase tracking-widest text-[#E50914]">Quiz complete</p>
                <h2 class="mt-1 text-center text-lg font-bold"><?php echo $h((string) ($doneBlock['title'] ?? 'Quiz')); ?></h2>
                <div class="mx-auto mt-4 flex h-28 w-28 items-center justify-center rounded-full border-4 border-[#84B8B8] bg-white text-2xl font-extrabold text-[#2C6A7D]">
                    <?php echo (int) $doneBlock['score']; ?><span class="text-sm font-normal text-slate-400">/<?php echo (int) $doneBlock['total']; ?></span>
                </div>
                <p class="mt-3 text-center text-sm text-slate-600">Accuracy <?php echo $acc; ?>%</p>
                <?php if (($doneBlock['rank'] ?? null) !== null): ?>
                    <p class="text-center text-sm font-semibold text-[#2C6A7D]">Your rank on this quiz: #<?php echo (int) $doneBlock['rank']; ?></p>
                <?php endif; ?>
                <a href="<?php echo $h($dashboardUrl); ?>" class="mt-4 block w-full rounded-xl bg-[#E50914] py-3 text-center text-sm font-bold text-white">Back to home</a>
            </section>

            <section class="mb-8 rounded-2xl border border-slate-200 bg-white p-4">
                <h3 class="text-center text-sm font-bold">Leaderboard · this quiz</h3>
                <p class="mb-4 text-center text-[11px] text-slate-500">Students who attempted this quiz (best score)</p>
                <?php echo trytest_render_quiz_podium_html($doneBlock['board'] ?? [], $userId, $h); ?>
            </section>
        <?php endif; ?>

        <?php if ($tabHome && (!is_array($doneBlock) || empty($doneBlock['quiz_id']))): ?>
            <div class="mb-4 grid grid-cols-3 gap-2">
                <a href="<?php echo $h($dashboardUrl); ?>?tab=rank" class="rounded-xl border border-slate-200 bg-white p-3 text-center shadow-sm">
                    <span class="text-xl" aria-hidden="true">🏆</span>
                    <p class="mt-1 text-[11px] font-semibold leading-tight">Level rank</p>
                </a>
                <div class="rounded-xl border border-slate-200 bg-white p-3 text-center shadow-sm">
                    <span class="text-xl" aria-hidden="true">📚</span>
                    <p class="mt-1 text-[11px] font-semibold leading-tight">Courses</p>
                </div>
                <a href="<?php echo $h($dashboardUrl); ?>?tab=profile" class="rounded-xl border border-slate-200 bg-white p-3 text-center shadow-sm">
                    <span class="text-xl" aria-hidden="true">👤</span>
                    <p class="mt-1 text-[11px] font-semibold leading-tight">Profile</p>
                </a>
            </div>

            <?php if ($recentAttempts): ?>
                <section class="mb-6">
                    <div class="mb-2 flex items-center justify-between">
                        <h2 class="text-sm font-bold">Recent quizzes</h2>
                        <a class="text-xs text-[#2C6A7D]" href="<?php echo $h($dashboardUrl); ?>?tab=rank">See ranks →</a>
                    </div>
                    <div class="space-y-2">
                        <?php foreach (array_slice($recentAttempts, 0, 5) as $att): ?>
                            <a href="<?php echo $h($quizUrlBase); ?>?quiz_id=<?php echo (int) ($att['quiz_id'] ?? 0); ?>" class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-3 py-3 text-left hover:border-[#84B8B8]">
                                <div class="min-w-0 pr-2">
                                    <p class="truncate text-sm font-medium"><?php echo $h((string) ($att['quiz_title'] ?? 'Quiz')); ?></p>
                                    <p class="text-[11px] text-slate-500"><?php echo $h((string) ($att['created_at'] ?? '')); ?></p>
                                </div>
                                <span class="shrink-0 rounded-full bg-[#84B8B8]/30 px-2.5 py-1 text-xs font-bold text-[#2C6A7D]"><?php echo (int) ($att['score'] ?? 0); ?>/<?php echo (int) ($att['total'] ?? 0); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($studentDocuments)): ?>
                <section class="mb-6">
                    <h2 class="mb-2 text-sm font-bold">Materials (PDF)</h2>
                    <p class="mb-2 text-[11px] text-slate-500">Downloads match your level and program when the admin set them. Others are shown so you know they exist.</p>
                    <?php if (!empty($youtubePdfGateActive)): ?>
                        <p class="mb-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-900">To download, you must be subscribed to the official course YouTube channel. The first download opens a quick Google sign-in to confirm your subscription.</p>
                    <?php endif; ?>
                    <div class="space-y-2">
                        <?php foreach ($studentDocuments as $doc): ?>
                            <?php
                            $eligible = !empty($doc['eligible']);
                            $dd = trim((string) ($doc['department'] ?? ''));
                            $dl = trim((string) ($doc['level'] ?? ''));
                            $scope = ($dd === '' ? 'All programs' : $dd) . ' · ' . ($dl === '' ? 'All levels' : ('Level ' . $dl));
                            ?>
                            <div class="flex items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white px-3 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium"><?php echo $h((string) ($doc['title'] ?? 'Document')); ?></p>
                                    <p class="text-[10px] text-slate-500"><?php echo $h($scope); ?></p>
                                    <?php if ($eligible): ?>
                                        <p class="mt-1 text-[10px] font-semibold text-emerald-700">For you</p>
                                    <?php else: ?>
                                        <p class="mt-1 text-[10px] font-semibold text-slate-500">Not for your current program or level</p>
                                    <?php endif; ?>
                                </div>
                                <?php if ($eligible): ?>
                                    <a href="<?php echo $h($downloadResourceBase); ?>?id=<?php echo (int) ($doc['id'] ?? 0); ?>" class="shrink-0 rounded-lg bg-[#2C6A7D] px-3 py-2 text-xs font-semibold text-white">Download</a>
                                <?php else: ?>
                                    <span class="shrink-0 rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-400">—</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section>
                <h2 class="mb-3 text-sm font-bold">Your courses</h2>
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
                                            data-quiz-start="<?php echo $stSec !== '' ? (string) $stSec : ''; ?>"
                                            data-quiz-end="<?php echo $enSec !== '' ? (string) $enSec : ''; ?>"
                                        >
                                            <div class="flex items-start justify-between gap-2">
                                                <div class="min-w-0 flex-1">
                                                    <?php if ($canPlay): ?>
                                                        <a href="<?php echo $h($quizUrlBase); ?>?quiz_id=<?php echo $qid; ?>" class="block text-[11px] font-semibold text-slate-800 hover:text-[#2C6A7D] leading-snug">
                                                            <?php echo $h($qtitle); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <p class="text-[11px] font-semibold text-slate-600 leading-snug"><?php echo $h($qtitle); ?></p>
                                                    <?php endif; ?>
                                                    <p class="mt-1 text-[10px] text-slate-500">
                                                        <?php if ($qc < 1): ?>
                                                            No approved questions yet — check back after your instructor publishes items.
                                                        <?php else: ?>
                                                            <?php echo $qc; ?> question<?php echo $qc === 1 ? '' : 's'; ?> · during the quiz you’ll see <span class="font-semibold text-slate-700">Question n / <?php echo $qc; ?></span>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                                <?php if ($canPlay): ?>
                                                    <span class="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-[9px] font-bold text-emerald-700">Open</span>
                                                <?php elseif ($phase === 'before'): ?>
                                                    <span class="shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-[9px] font-bold text-amber-800">Soon</span>
                                                <?php else: ?>
                                                    <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[9px] font-bold text-slate-500">Closed</span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="trytest-quiz-countdown mt-1.5 rounded-md bg-slate-50 px-2 py-1.5 text-center text-[11px] font-mono font-bold tracking-tight text-[#2C6A7D] ring-1 ring-slate-100 <?php echo $hasSchedule ? '' : 'hidden'; ?>"></p>
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
                <p class="text-xs text-slate-500">Total points at level <?php echo $h($userLevel); ?> · your program: <?php echo $h($deptLabel); ?></p>
            </section>
            <?php echo trytest_render_level_podium_html($levelLeaderboardRows, $userId, $h); ?>
            <p class="mt-4 text-center text-[11px] text-slate-500">Points = sum of scores from all quiz attempts.</p>
        <?php endif; ?>

        <?php if ($tabProfile): ?>
            <section class="mb-4">
                <a href="<?php echo $h($dashboardUrl); ?>" class="text-sm text-[#2C6A7D]">← Home</a>
                <h2 class="mt-2 text-xl font-bold">Profile</h2>
            </section>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 space-y-3 text-sm">
                <div class="flex justify-center pb-2">
                    <div class="h-20 w-20 overflow-hidden rounded-2xl border border-slate-200 [&>svg]:h-full [&>svg]:w-full">
                        <?php echo trytest_student_avatar_svg($userIndex, 80); ?>
                    </div>
                </div>
                <p><span class="text-slate-500">Index</span><br><span class="font-medium"><?php echo $h($userIndex); ?></span></p>
                <p><span class="text-slate-500">Level</span><br><span class="font-medium"><?php echo $h($userLevel); ?></span></p>
                <p><span class="text-slate-500">Program</span><br><span class="font-medium"><?php echo $h($deptLabel); ?></span></p>
                <p><span class="text-slate-500">Total points</span><br><span class="font-semibold text-[#2C6A7D]"><?php echo (int) $totalPoints; ?></span></p>
                <form method="post" class="pt-2 border-t border-slate-200">
                    <input type="hidden" name="action" value="logout_user">
                    <button type="submit" class="w-full rounded-xl bg-[#E50914] py-3 text-sm font-bold text-white">Log out</button>
                </form>
            </div>
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
            <a href="<?php echo $h($dashboardUrl); ?>?tab=profile" class="<?php echo $h($navClass($tabProfile)); ?>">
                <span class="text-lg">👤</span>
                <span class="text-[10px] font-semibold">Profile</span>
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
    function pad(n) { return String(n).padStart(2, '0'); }
    function formatRemain(ms) {
        if (ms <= 0) return '0d 00:00:00';
        var s = Math.floor(ms / 1000);
        var d = Math.floor(s / 86400);
        var h = Math.floor((s % 86400) / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        return (d > 0 ? d + 'd ' : '') + pad(h) + ':' + pad(m) + ':' + pad(sec);
    }
    function tick() {
        var now = Date.now();
        document.querySelectorAll('.trytest-quiz-card').forEach(function (card) {
            var el = card.querySelector('.trytest-quiz-countdown');
            if (!el) return;
            el.classList.remove('text-slate-500');
            el.classList.add('text-[#2C6A7D]');
            var sRaw = card.getAttribute('data-quiz-start') || '';
            var eRaw = card.getAttribute('data-quiz-end') || '';
            var s = sRaw ? parseInt(sRaw, 10) * 1000 : 0;
            var e = eRaw ? parseInt(eRaw, 10) * 1000 : 0;
            if (!s && !e) {
                el.textContent = '';
                el.classList.add('hidden');
                return;
            }
            el.classList.remove('hidden');
            if (s && now < s) {
                el.textContent = 'Opens in ' + formatRemain(s - now);
                return;
            }
            if (e && now < e) {
                el.textContent = 'Closes in ' + formatRemain(e - now);
                return;
            }
            if (e && now >= e) {
                el.textContent = 'Quiz window ended';
                el.classList.remove('text-[#2C6A7D]');
                el.classList.add('text-slate-500');
                return;
            }
            if (s && now >= s && (!e || now <= e)) {
                el.textContent = 'Quiz is open';
                return;
            }
            el.textContent = '';
            el.classList.add('hidden');
        });
    }
    tick();
    setInterval(tick, 1000);
})();
</script>
