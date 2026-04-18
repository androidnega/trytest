<?php

declare(strict_types=1);

/** @var string $dashboardUrl */
/** @var string $downloadsPageUrl */
/** @var int $downloadsBadgeCount */
/** @var int $userId */
/** @var string $userIndex */
/** @var string $userLevel */
/** @var string $userDepartment */
/** @var string $userDisplayName */
/** @var int $totalPoints */
/** @var string $activeTab */
/** @var list<array<string,mixed>> $coursesWithQuizzes */
/** @var list<array<string,mixed>> $levelLeaderboardRows */
/** @var string $quizzesPageUrl */
/** @var list<array<string,mixed>> $departmentOptions */
/** @var bool $needsDepartmentSetup */
/** @var string $departmentUpdateError */
/** @var array<string,mixed>|null $doneBlock */
/** @var string $quizDoneYoutubeHtml */
/** @var array<string,mixed>|null $doneComparison */
/** @var array{lead:string,body:string,quiz_id:int,context:string,surface?:string}|null $dashboardEncouragement */
/** @var int $newQuizBadgeCount */
/** @var string $quizUrlBase */
/** @var list<array<string,mixed>> $quizResultsRows */
/** @var bool $studentDashboardFixedViewport When true, home dashboard fits one viewport with no page scroll. */
/** @var string $dashboardYoutubeVideosHtml Main dashboard YouTube block (may be empty). */
$h = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};

$tabHome = $activeTab === 'home';
$tabRank = $activeTab === 'rank';
$tabResults = $activeTab === 'results';
$quizResultsRows = isset($quizResultsRows) && is_array($quizResultsRows) ? $quizResultsRows : [];
$homeNavOn = $tabHome && empty(($doneBlock ?? [])['quiz_id'] ?? null);
$deptLabel = $userDepartment !== '' ? $userDepartment : 'All programs';
$needsDepartmentSetup = !empty($needsDepartmentSetup);
$departmentUpdateError = trim((string) ($departmentUpdateError ?? ''));
$quizDoneYoutubeHtml = (string) ($quizDoneYoutubeHtml ?? '');
$downloadsBadgeCount = max(0, (int) ($downloadsBadgeCount ?? 0));
$newQuizBadgeCount = max(0, (int) ($newQuizBadgeCount ?? 0));
$downloadsNavBadge = '';
$downloadsMenuBadge = '';
if ($downloadsBadgeCount > 0) {
    $dn = $downloadsBadgeCount > 9 ? '9+' : (string) $downloadsBadgeCount;
    $downloadsNavBadge = '<span class="absolute -right-1 -top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-[#E50914] px-1 text-[9px] font-extrabold leading-none text-white" aria-label="'
        . $h((string) $downloadsBadgeCount . ' new or not yet downloaded')
        . '">' . $h($dn) . '</span>';
    $downloadsMenuBadge = '<span class="ml-2 inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-[#E50914] px-1 text-[10px] font-extrabold leading-none text-white" aria-label="'
        . $h((string) $downloadsBadgeCount . ' new or not yet downloaded')
        . '">' . $h($dn) . '</span>';
}
$totalQuizCards = 0;
foreach ($coursesWithQuizzes as $courseRow) {
    $totalQuizCards += count((array) ($courseRow['quizzes'] ?? []));
}
$quizzesPageUrl = trim((string) ($quizzesPageUrl ?? ''));

$dashboardFixedViewport = !empty($studentDashboardFixedViewport ?? false);
$dashboardYoutubeVideosHtml = (string) ($dashboardYoutubeVideosHtml ?? '');

$navClass = function (bool $on) use ($dashboardFixedViewport): string {
    $py = $dashboardFixedViewport ? 'py-3' : 'py-2';

    return $on
        ? 'flex flex-1 flex-col items-center gap-1 ' . $py . ' text-[#E50914]'
        : 'flex flex-1 flex-col items-center gap-1 ' . $py . ' text-slate-500 hover:text-slate-700';
};
?>
<div class="<?php echo $dashboardFixedViewport
    ? 'mx-auto flex h-svh max-h-svh w-full max-w-md flex-col overflow-hidden bg-white text-slate-900 md:max-w-lg'
    : 'min-h-screen bg-white pb-24 text-slate-900 md:pb-8'; ?>">
    <header class="<?php echo $dashboardFixedViewport
        ? 'shrink-0 border-b border-slate-200 bg-white px-4 py-3'
        : 'sticky top-0 z-30 border-b border-slate-200 bg-white'; ?>">
        <?php if ($dashboardFixedViewport): ?>
        <div class="flex min-w-0 items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-extrabold uppercase tracking-wider text-[#E50914]">Trytest</p>
                <p class="truncate text-sm font-semibold leading-tight text-slate-900"><?php echo $h($userDisplayName); ?></p>
                <p class="truncate text-[10px] text-slate-500">Lv&nbsp;<?php echo $h($userLevel); ?> · <?php echo $h($deptLabel); ?></p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <div class="h-9 w-9 shrink-0 overflow-hidden rounded-full bg-[#E2E8F0] ring-1 ring-slate-300 [&>svg]:h-full [&>svg]:w-full">
                    <?php echo trytest_student_avatar_svg($userIndex, 44, $userId); ?>
                </div>
                <div class="relative shrink-0">
                    <button type="button" id="profileMenuBtn" class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-lg leading-none text-slate-700 hover:bg-slate-200" aria-expanded="false" aria-haspopup="true" aria-label="Account menu">⋯</button>
                    <div id="profileMenu" class="hidden absolute right-0 z-40 mt-2 max-h-[min(16rem,45svh)] w-56 overflow-y-auto rounded-xl border border-slate-200 bg-white py-2 shadow-lg" role="menu">
                        <p class="px-3 text-xs font-semibold text-slate-900">Profile</p>
                        <p class="mt-0.5 truncate px-3 text-[11px] text-slate-500"><?php echo $h($userIndex); ?></p>
                        <div class="mt-2 space-y-1 border-t border-slate-100 px-3 py-2 text-xs text-slate-600">
                            <p class="flex items-center justify-between gap-2"><span class="text-slate-400">Level</span><span class="font-medium text-slate-800"><?php echo $h($userLevel); ?></span></p>
                            <p class="flex items-center justify-between gap-2"><span class="text-slate-400">Points</span><span class="font-semibold tabular-nums text-[#2C6A7D]"><?php echo (int) $totalPoints; ?></span></p>
                        </div>
                        <a href="<?php echo $h($dashboardUrl); ?>?tab=results" class="border-t border-slate-100 px-3 py-2 text-sm font-medium text-[#2C6A7D] hover:bg-slate-50" role="menuitem">My results</a>
                        <a href="<?php echo $h($downloadsPageUrl); ?>" class="flex items-center justify-between border-t border-slate-100 px-3 py-2 text-sm font-medium text-[#2C6A7D] hover:bg-slate-50"><span>Downloads</span><?php echo $downloadsMenuBadge; ?></a>
                        <form method="post" class="border-t border-slate-100 px-2 pt-2">
                            <input type="hidden" name="action" value="logout_user">
                            <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm text-[#E50914] hover:bg-red-50" role="menuitem">Log out</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="mx-auto flex max-w-5xl flex-nowrap items-center justify-between gap-2 px-3 py-2.5 sm:gap-3 sm:px-4 sm:py-3">
            <div class="flex min-w-0 flex-1 items-center gap-2 sm:gap-3">
                <div class="h-9 w-9 shrink-0 overflow-hidden rounded-full bg-[#E2E8F0] ring-1 ring-slate-300 [&>svg]:h-full [&>svg]:w-full sm:h-10 sm:w-10">
                    <?php echo trytest_student_avatar_svg($userIndex, 44, $userId); ?>
                </div>
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold leading-tight text-slate-900"><?php echo $h($userDisplayName); ?></p>
                    <p class="truncate text-[10px] text-slate-500 sm:text-[11px]">Lv&nbsp;<?php echo $h($userLevel); ?> · <?php echo $h($deptLabel); ?></p>
                </div>
            </div>
            <div class="flex shrink-0 flex-nowrap items-center gap-1.5 sm:gap-2">
                <div class="relative shrink-0">
                    <button type="button" id="profileMenuBtn" class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-lg leading-none text-slate-700 hover:bg-slate-200 sm:h-10 sm:w-10" aria-expanded="false" aria-haspopup="true" aria-label="Account menu">⋯</button>
                    <div id="profileMenu" class="hidden absolute right-0 mt-2 max-h-[min(16rem,45svh)] w-56 overflow-y-auto rounded-xl border border-slate-200 bg-white py-2 shadow-lg" role="menu">
                        <p class="px-3 text-xs font-semibold text-slate-900">Profile</p>
                        <p class="mt-0.5 truncate px-3 text-[11px] text-slate-500"><?php echo $h($userIndex); ?></p>
                        <div class="mt-2 space-y-1 border-t border-slate-100 px-3 py-2 text-xs text-slate-600">
                            <p class="flex items-center justify-between gap-2"><span class="text-slate-400">Level</span><span class="font-medium text-slate-800"><?php echo $h($userLevel); ?></span></p>
                            <p class="flex items-center justify-between gap-2"><span class="text-slate-400">Points</span><span class="font-semibold tabular-nums text-[#2C6A7D]"><?php echo (int) $totalPoints; ?></span></p>
                        </div>
                        <a href="<?php echo $h($dashboardUrl); ?>?tab=results" class="border-t border-slate-100 px-3 py-2 text-sm font-medium text-[#2C6A7D] hover:bg-slate-50" role="menuitem">My results</a>
                        <a href="<?php echo $h($downloadsPageUrl); ?>" class="flex items-center justify-between border-t border-slate-100 px-3 py-2 text-sm font-medium text-[#2C6A7D] hover:bg-slate-50"><span>Downloads</span><?php echo $downloadsMenuBadge; ?></a>
                        <form method="post" class="border-t border-slate-100 px-2 pt-2">
                            <input type="hidden" name="action" value="logout_user">
                            <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm text-[#E50914] hover:bg-red-50" role="menuitem">Log out</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="<?php echo $dashboardFixedViewport
            ? 'mx-auto hidden w-full max-w-md flex-nowrap items-center justify-center gap-8 px-4 pb-2 text-sm font-semibold md:flex md:max-w-lg'
            : 'mx-auto hidden max-w-5xl flex-nowrap items-center justify-center gap-8 pb-2 text-sm font-semibold md:flex'; ?>">
            <a href="<?php echo $h($dashboardUrl); ?>" class="whitespace-nowrap <?php echo $homeNavOn ? 'text-[#E50914]' : 'text-slate-500 hover:text-slate-700'; ?>">Home</a>
            <a href="<?php echo $h($dashboardUrl); ?>?tab=rank" class="whitespace-nowrap <?php echo $tabRank ? 'text-[#E50914]' : 'text-slate-500 hover:text-slate-700'; ?>">Leaderboard</a>
            <a href="<?php echo $h($dashboardUrl); ?>?tab=results" class="whitespace-nowrap <?php echo $tabResults ? 'text-[#E50914]' : 'text-slate-500 hover:text-slate-700'; ?>">Results</a>
            <?php if ($totalQuizCards > 0 && $quizzesPageUrl !== ''): ?>
                <a href="<?php echo $h($quizzesPageUrl); ?>" class="whitespace-nowrap text-slate-500 hover:text-slate-700">Quizzes</a>
            <?php endif; ?>
            <a href="<?php echo $h($downloadsPageUrl); ?>" class="relative whitespace-nowrap text-slate-500 hover:text-slate-700">Files<?php echo $downloadsNavBadge; ?></a>
        </div>
    </header>

    <main class="<?php echo $dashboardFixedViewport
        ? 'mx-auto flex min-h-0 w-full flex-1 flex-col gap-4 overflow-hidden p-4'
        : 'mx-auto w-full max-w-5xl px-4 pb-24 pt-4 md:pb-8'; ?>">
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
                <?php if (is_array($doneComparison)): ?>
                    <?php $delta = (int) ($doneComparison['delta'] ?? 0); ?>
                    <p class="mt-1 text-center text-sm font-semibold <?php echo $delta >= 0 ? 'text-emerald-700' : 'text-amber-700'; ?>">
                        <?php if ($delta > 0): ?>
                            Better than last attempt by +<?php echo $delta; ?> points.
                        <?php elseif ($delta < 0): ?>
                            <?php echo abs($delta); ?> points below your last attempt. You can bounce back.
                        <?php else: ?>
                            Same as your last attempt. Keep pushing for a new high score.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if (($doneBlock['rank'] ?? null) !== null): ?>
                    <p class="text-center text-sm font-semibold text-[#2C6A7D]">Your rank on this quiz: #<?php echo (int) $doneBlock['rank']; ?></p>
                <?php endif; ?>
                <?php if ($quizDoneYoutubeHtml !== ''): ?>
                    <div class="mt-4"><?php echo $quizDoneYoutubeHtml; ?></div>
                <?php endif; ?>
                <?php if (empty($doneBlock['can_retry'])): ?>
                    <p class="mt-3 text-center text-xs text-slate-500">This quiz is no longer accepting new attempts. Your last score is still listed under <a href="<?php echo $h(rtrim($dashboardUrl, '/') . '?tab=results'); ?>" class="font-semibold text-[#2C6A7D] underline">My results</a>.</p>
                <?php else: ?>
                    <p class="mt-3 text-center text-xs text-slate-500">This quiz is removed from your <strong>Quizzes</strong> list now that you have a score. Open <a href="<?php echo $h(rtrim($dashboardUrl, '/') . '?tab=results'); ?>" class="font-semibold text-[#2C6A7D] underline">My results</a> anytime for <strong>Try again</strong> (that clears your score first).</p>
                <?php endif; ?>
                <a href="<?php echo $h(rtrim($dashboardUrl, '/') . '?tab=results'); ?>" class="mt-3 block w-full rounded-xl border-2 border-[#2C6A7D] bg-white py-3 text-center text-sm font-bold text-[#2C6A7D] hover:bg-slate-50">My results</a>
                <a href="<?php echo $h($dashboardUrl); ?>" class="mt-2 block w-full rounded-xl bg-[#E50914] py-3 text-center text-sm font-bold text-white">Back to home</a>
            </section>

            <section class="mb-8 rounded-2xl border border-slate-200 bg-white p-4">
                <h3 class="text-center text-sm font-bold">This quiz · leaderboard</h3>
                <?php echo trytest_render_quiz_podium_html($doneBlock['board'] ?? [], $userId, $h); ?>
            </section>
        <?php endif; ?>

        <?php if ($tabHome && (!is_array($doneBlock) || empty($doneBlock['quiz_id']))): ?>
            <?php
            $enc = is_array($dashboardEncouragement ?? null) ? $dashboardEncouragement : null;
            $encQuizId = $enc !== null ? max(0, (int) ($enc['quiz_id'] ?? 0)) : 0;
            $encSurface = $enc !== null ? trim((string) ($enc['surface'] ?? '#D8EFEF')) : '#D8EFEF';
            if ($encSurface === '' || !preg_match('/^#[0-9A-Fa-f]{6}$/', $encSurface)) {
                $encSurface = '#D8EFEF';
            }
            $homeFlexLock = $dashboardFixedViewport;
            $tileRounded = $homeFlexLock ? 'rounded-xl' : 'rounded-2xl';
            $tileMin = $homeFlexLock ? 'min-h-0 min-w-0' : 'min-h-[96px]';
            $tileSvg = $homeFlexLock ? 32 : 38;
            $homeGridClass = $homeFlexLock
                ? 'grid min-h-0 flex-1 grid-cols-2 gap-4 [grid-template-rows:minmax(0,1fr)_minmax(0,1fr)]'
                : 'grid grid-cols-2 gap-3';
            $lbGridClass = $homeFlexLock ? 'row-start-1 col-start-1 ' : '';
            $filesGridClass = $homeFlexLock ? 'row-start-1 col-start-2 ' : '';
            $quizSpanClass = $homeFlexLock ? 'row-start-2 col-span-2 ' : 'col-span-2 ';
            $cheerSectionClass = $homeFlexLock
                ? 'shrink-0 rounded-xl border border-slate-200 bg-white px-3 py-2 text-slate-900'
                : 'mb-4 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-slate-900';
            $cheerBodyClass = $homeFlexLock
                ? 'mt-0.5 line-clamp-2 text-[11px] leading-snug text-slate-800'
                : 'mt-0.5 text-[11px] leading-snug text-slate-800';
            $quickSectionClass = $homeFlexLock ? 'flex min-h-0 flex-1 flex-col overflow-hidden' : 'mb-6';
            ?>
            <style>
                @keyframes trytest-quiz-icon-breathe {
                    0%, 100% { transform: scale(1); }
                    50% { transform: scale(1.18); }
                }
                .trytest-quiz-home-icon--pulse { animation: trytest-quiz-icon-breathe 2.2s ease-in-out infinite; }
            </style>
            <?php if ($homeFlexLock): ?>
            <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-hidden">
            <?php endif; ?>
            <?php if ($enc !== null): ?>
                <section id="trytest-dash-cheer" class="<?php echo $h($cheerSectionClass); ?>" style="border-left: 4px solid <?php echo $h($encSurface); ?>;" aria-labelledby="dash-cheer-title">
                    <h2 id="dash-cheer-title" class="text-xs font-bold leading-tight"><?php echo $h((string) ($enc['lead'] ?? '')); ?></h2>
                    <p class="<?php echo $h($cheerBodyClass); ?>"><?php echo $h((string) ($enc['body'] ?? '')); ?></p>
                    <div class="mt-1.5 flex min-w-0 flex-wrap gap-1.5">
                        <?php if ($encQuizId > 0): ?>
                            <a href="<?php echo $h(rtrim($quizUrlBase, '/') . '?quiz_id=' . $encQuizId); ?>" class="inline-flex min-h-9 shrink-0 items-center rounded-lg bg-[#2C6A7D] px-3 py-1.5 text-[10px] font-bold text-white hover:bg-[#24586a]">Open quiz</a>
                        <?php endif; ?>
                        <?php if ($totalQuizCards > 0 && $quizzesPageUrl !== ''): ?>
                            <a href="<?php echo $h($quizzesPageUrl); ?>" class="inline-flex min-h-9 shrink-0 items-center rounded-lg bg-[#1e293b] px-3 py-1.5 text-[10px] font-semibold text-white hover:bg-slate-800">All quizzes</a>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
            <?php if ($dashboardYoutubeVideosHtml !== ''): ?>
                <div<?php echo $homeFlexLock ? ' class="min-h-0 shrink-0 max-h-[min(36svh,15rem)] overflow-y-auto overflow-x-hidden overscroll-contain"' : ''; ?>>
                    <?php echo $dashboardYoutubeVideosHtml; ?>
                </div>
            <?php endif; ?>
            <section class="<?php echo $h($quickSectionClass); ?>" aria-label="Quick links">
                <div class="<?php echo $h($homeGridClass); ?>">
                    <a href="<?php echo $h($dashboardUrl); ?>?tab=rank" class="<?php echo $h($lbGridClass); ?>flex <?php echo $h($tileMin); ?> min-w-0 flex-row items-center gap-3 <?php echo $h($tileRounded); ?> border border-slate-200 bg-white px-3 py-3 text-left text-slate-900 transition hover:bg-slate-50/80 active:bg-slate-50">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#f4faf9] ring-1 ring-[#84B8B8]/45" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('trophy', $tileSvg); ?></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-bold leading-tight">Leaderboard</span>
                            <span class="mt-0.5 block text-xs text-slate-500">Level ranks</span>
                        </span>
                    </a>
                    <a href="<?php echo $h($downloadsPageUrl); ?>" class="<?php echo $h($filesGridClass); ?>relative flex <?php echo $h($tileMin); ?> min-w-0 flex-row items-center gap-3 <?php echo $h($tileRounded); ?> border border-slate-200 bg-white px-3 py-3 text-left text-slate-900 transition hover:bg-slate-50/80 active:bg-slate-50">
                        <?php if ($downloadsBadgeCount > 0): ?>
                            <span class="absolute right-2 top-2 z-10 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-[#E50914] px-1 text-[10px] font-extrabold text-white"><?php echo $downloadsBadgeCount > 9 ? '9+' : (string) $downloadsBadgeCount; ?></span>
                        <?php endif; ?>
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#f4faf9] ring-1 ring-[#84B8B8]/45" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('folder', $tileSvg); ?></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-bold leading-tight">Files</span>
                            <span class="mt-0.5 block text-xs text-slate-500">Downloads</span>
                        </span>
                    </a>
                    <?php if ($totalQuizCards > 0 && $quizzesPageUrl !== ''): ?>
                        <?php
                        $quizCardClass = 'relative ' . $quizSpanClass . 'flex ' . $tileMin . ' min-w-0 flex-row items-center gap-3 ' . $tileRounded . ' border border-slate-200 bg-white px-3 py-3 text-left text-slate-900 transition hover:bg-slate-50/80 active:bg-slate-50 ';
                        ?>
                        <a href="<?php echo $h($quizzesPageUrl); ?>" class="<?php echo $h($quizCardClass); ?>">
                            <?php if ($newQuizBadgeCount > 0): ?>
                                <span class="absolute right-2 top-2 z-10 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-[#E50914] px-1 text-[9px] font-extrabold text-white"><?php echo $newQuizBadgeCount > 9 ? '9+' : (string) $newQuizBadgeCount; ?> new</span>
                            <?php endif; ?>
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#f4faf9] ring-1 ring-[#84B8B8]/45 <?php echo $newQuizBadgeCount > 0 ? 'trytest-quiz-home-icon--pulse' : ''; ?>" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('quiz', $tileSvg); ?></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-bold leading-tight">Quizzes</span>
                                <span class="mt-0.5 block text-xs text-slate-500"><span class="font-extrabold tabular-nums text-[#2C6A7D]"><?php echo (int) $totalQuizCards; ?></span> <?php echo (int) $totalQuizCards === 1 ? 'quiz' : 'quizzes'; ?> ready</span>
                            </span>
                        </a>
                    <?php else: ?>
                        <div class="<?php echo $h($quizSpanClass); ?>flex <?php echo $homeFlexLock ? 'min-h-0' : 'min-h-[88px]'; ?> min-w-0 flex-row items-center gap-3 <?php echo $h($tileRounded); ?> border border-slate-200 bg-white px-3 py-3 text-left text-slate-500">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-50 opacity-70 ring-1 ring-slate-200" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('quiz', $tileSvg); ?></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold leading-tight text-slate-700">Quizzes</span>
                                <span class="mt-0.5 block text-xs text-slate-500">None for your program yet</span>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php if ($homeFlexLock): ?>
            </div>
            <?php endif; ?>
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

        <?php if ($tabResults): ?>
            <section class="mb-4">
                <a href="<?php echo $h($dashboardUrl); ?>" class="text-sm text-[#2C6A7D]">← Home</a>
                <h2 class="mt-2 text-xl font-bold">My results</h2>
                <p class="mt-1 text-xs text-slate-600">Scores from quizzes you have finished. Try again clears your score and history for that quiz so you can start fresh.</p>
            </section>
            <?php if ($quizResultsRows === []): ?>
                <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50/80 px-4 py-10 text-center text-sm text-slate-600">
                    <p class="font-medium text-slate-800">No results yet</p>
                    <p class="mt-1 text-xs text-slate-500"><?php if ($quizzesPageUrl !== ''): ?>Complete a quiz from <a href="<?php echo $h($quizzesPageUrl); ?>" class="font-semibold text-[#2C6A7D] underline">Quizzes</a><?php else: ?>Complete a quiz<?php endif; ?> and your score will show here.</p>
                </div>
            <?php else: ?>
                <ul class="space-y-3">
                    <?php foreach ($quizResultsRows as $resRow): ?>
                        <?php
                        $rqid = (int) ($resRow['quiz_id'] ?? 0);
                        $rscore = (int) ($resRow['score'] ?? 0);
                        $rtotal = (int) ($resRow['total'] ?? 0);
                        $racc = $rtotal > 0 ? (int) round(100 * $rscore / $rtotal) : 0;
                        $rwhen = trim((string) ($resRow['created_at'] ?? ''));
                        $rwhenDisp = $rwhen !== '' ? date('M j, Y · g:i A', strtotime($rwhen) ?: time()) : '';
                        ?>
                        <li class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-bold text-slate-900"><?php echo $h((string) ($resRow['title'] ?? 'Quiz')); ?></p>
                                    <p class="mt-1 text-xs text-slate-500"><?php echo $rwhenDisp !== '' ? $h($rwhenDisp) : ''; ?></p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="text-lg font-extrabold tabular-nums text-[#2C6A7D]"><?php echo $rscore; ?><span class="text-sm font-semibold text-slate-400">/<?php echo $rtotal; ?></span></p>
                                    <p class="text-[11px] font-medium text-slate-500"><?php echo $racc; ?>% accuracy</p>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <a href="<?php echo $h(rtrim($quizUrlBase, '/') . '?quiz_id=' . $rqid); ?>" class="inline-flex items-center rounded-lg bg-[#2C6A7D] px-3 py-2 text-xs font-bold text-white hover:bg-[#24586a]">View quiz</a>
                                <form method="post" action="<?php echo $h($dashboardUrl); ?>" class="inline" onsubmit="return confirm('Try again? Your saved score and attempts for this quiz will be removed.');">
                                    <input type="hidden" name="action" value="reset_student_quiz">
                                    <input type="hidden" name="quiz_id" value="<?php echo $rqid; ?>">
                                    <button type="submit" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-800 hover:bg-slate-50">Try again</button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <nav class="<?php echo $dashboardFixedViewport
        ? 'shrink-0 border-t border-slate-200 bg-white md:hidden'
        : 'fixed bottom-0 left-0 right-0 z-50 border-t border-slate-200 bg-white md:hidden'; ?>" style="padding-bottom: max(0.5rem, env(safe-area-inset-bottom));">
        <div class="<?php echo $dashboardFixedViewport ? 'mx-auto flex w-full max-w-md md:max-w-lg' : 'mx-auto flex max-w-5xl'; ?>">
            <a href="<?php echo $h($dashboardUrl); ?>" class="<?php echo $h($navClass($homeNavOn)); ?>">
                <span class="flex h-6 w-6 items-center justify-center text-[#2C6A7D]" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 4l9 6.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-9.5z"/></svg></span>
                <span class="text-[9px] font-semibold leading-tight">Home</span>
            </a>
            <a href="<?php echo $h($dashboardUrl); ?>?tab=rank" class="<?php echo $h($navClass($tabRank)); ?>">
                <span class="flex h-6 w-6 items-center justify-center text-[#2C6A7D]" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('trophy', 22); ?></span>
                <span class="text-[9px] font-semibold leading-tight">Rank</span>
            </a>
            <a href="<?php echo $h($dashboardUrl); ?>?tab=results" class="<?php echo $h($navClass($tabResults)); ?>">
                <span class="flex h-6 w-6 items-center justify-center text-[#2C6A7D]" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('results', 22); ?></span>
                <span class="text-[9px] font-semibold leading-tight">Results</span>
            </a>
            <a href="<?php echo $h($downloadsPageUrl); ?>" class="<?php echo $h($navClass(false)); ?> relative">
                <span class="flex h-6 w-6 items-center justify-center text-[#2C6A7D]" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('folder', 22); ?></span>
                <?php if ($downloadsBadgeCount > 0): ?>
                    <span class="absolute right-[18%] top-0.5 flex h-[15px] min-w-[15px] items-center justify-center rounded-full bg-[#E50914] px-0.5 text-[8px] font-extrabold leading-none text-white" aria-label="<?php echo (int) $downloadsBadgeCount; ?> pending"><?php echo $downloadsBadgeCount > 9 ? '9+' : (string) (int) $downloadsBadgeCount; ?></span>
                <?php endif; ?>
                <span class="text-[9px] font-semibold leading-tight">Files</span>
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
    var root = document.getElementById('trytest-dash-cheer');
    if (!root) return;
    var k = 'trytest_cheer_suppress_until';
    var until = 0;
    try { until = parseInt(sessionStorage.getItem(k) || '0', 10); } catch (e) {}
    if (until > Date.now()) {
        if (root.parentNode) root.parentNode.removeChild(root);
        return;
    }
    function remove() {
        if (root && root.parentNode) root.parentNode.removeChild(root);
    }
    function clearKey() {
        try { sessionStorage.removeItem(k); } catch (e) {}
    }
    var tmo = setTimeout(function () {
        clearKey();
        remove();
    }, 600000);
    window.addEventListener('pagehide', function () {
        clearTimeout(tmo);
        try { sessionStorage.setItem(k, String(Date.now() + 600000)); } catch (e) {}
    }, { passive: true });
})();
</script>
