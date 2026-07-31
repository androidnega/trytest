<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/student_theme.php';

/** @var string $dashboardUrl */
/** @var string $studentPortalPostUrl Form action for POSTs (student_portal.php — avoids /dashboard/ directory POST issues). */
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
/** @var bool $departmentSetupRequired */
/** @var string $departmentUpdateError */
/** @var list<array<string,mixed>> $departmentOptions */
/** @var list<array<string,mixed>> $levelOptions */
/** @var array<string,mixed>|null $doneBlock */
/** @var string $quizDoneYoutubeHtml */
/** @var array<string,mixed>|null $doneComparison */
/** @var array{lead:string,body:string,quiz_id:int,context:string,surface?:string}|null $dashboardEncouragement */
/** @var int $newQuizBadgeCount */
/** @var string $quizUrlBase */
/** @var string $studentFeedbackApiUrl */
/** @var bool $studentFeedbackAlreadySubmitted True when this student already has a rating row (one-time only). */
/** @var list<array<string,mixed>> $quizResultsRows */
/** @var bool $studentDashboardFixedViewport When true, home dashboard fits one viewport with no page scroll. */
/** @var string $dashboardFeaturedHtml Featured shell (Video | Words) — always present on home when logged in on home tab. */
/** @var string $dashboardFeaturedKind "video" when featured slot is YouTube; "words" when quote + image (layout unchanged). */
/** @var string $dashboardNudgesHtml Dismissible tips (praise, last quiz, downloads, YouTube). */
/** @var string $studentGamePanelHtml Game XP + knowledge cards strip for home. */
/** @var int $studentGameXp */
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
$departmentSetupRequired = !empty($departmentSetupRequired);
$departmentUpdateError = trim((string) ($departmentUpdateError ?? ''));
$levelOptions = isset($levelOptions) && is_array($levelOptions) ? $levelOptions : [];
$departmentOptions = isset($departmentOptions) && is_array($departmentOptions) ? $departmentOptions : [];
$quizDoneYoutubeHtml = (string) ($quizDoneYoutubeHtml ?? '');
$downloadsBadgeCount = max(0, (int) ($downloadsBadgeCount ?? 0));
$newQuizBadgeCount = max(0, (int) ($newQuizBadgeCount ?? 0));
$downloadsMenuBadge = '';
if ($downloadsBadgeCount > 0) {
    $dn = $downloadsBadgeCount > 9 ? '9+' : (string) $downloadsBadgeCount;
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
$dashboardFeaturedHtml = (string) ($dashboardFeaturedHtml ?? $dashboardYoutubeVideosHtml ?? '');
$dashboardNudgesHtml = (string) ($dashboardNudgesHtml ?? '');
$studentPortalPostUrl = isset($studentPortalPostUrl) ? (string) $studentPortalPostUrl : (string) ($dashboardUrl ?? '');
$studentFeedbackApiUrl = isset($studentFeedbackApiUrl) ? (string) $studentFeedbackApiUrl : '';
$studentFeedbackAlreadySubmitted = !empty($studentFeedbackAlreadySubmitted ?? false);
$studentGamePanelHtml = (string) ($studentGamePanelHtml ?? '');
$studentGameXp = max(0, (int) ($studentGameXp ?? 0));

$navClass = function (bool $on) use ($dashboardFixedViewport): string {
    $py = $dashboardFixedViewport ? 'py-3' : 'py-2';
    $base = 'flex flex-1 flex-col items-center gap-1 ' . $py . ' ';

    return $on
        ? $base . 'tt-dash-nav-on font-semibold'
        : $base . 'font-medium';
};
?>
<div class="trytest-student-shell tt-dash <?php echo $dashboardFixedViewport
    ? 'tt-dash--home-lock mx-auto flex h-svh max-h-svh w-full max-w-md flex-col overflow-hidden text-slate-900 md:max-w-3xl lg:max-w-none dark:text-zinc-100'
    : 'min-h-screen pb-24 text-slate-900 md:pb-8 dark:text-zinc-100'; ?>">
    <header class="tt-dash-header <?php echo $dashboardFixedViewport
        ? 'shrink-0'
        : 'sticky top-0 z-30'; ?>">
        <div class="tt-dash-bar mx-auto w-full max-w-6xl lg:max-w-7xl">
            <div class="tt-dash-identity min-w-0 flex-1">
                <p class="tt-dash-brand-mark">Trytest</p>
                <p class="tt-dash-hello truncate"><?php echo $h($userDisplayName); ?></p>
                <p class="tt-dash-meta truncate">Lv&nbsp;<?php echo $h($userLevel); ?> · <?php echo $h($deptLabel); ?> · <?php echo (int) $totalPoints; ?> pts<?php echo $studentGameXp > 0 ? ' · ' . (int) $studentGameXp . ' XP' : ''; ?></p>
            </div>
            <nav class="tt-dash-topnav" aria-label="Primary">
                <a href="<?php echo $h($dashboardUrl); ?>" class="<?php echo $homeNavOn ? 'is-on' : ''; ?>">Home</a>
                <a href="<?php echo $h($dashboardUrl); ?>?tab=rank" class="<?php echo $tabRank ? 'is-on' : ''; ?>">Rank</a>
                <a href="<?php echo $h($dashboardUrl); ?>?tab=results" class="<?php echo $tabResults ? 'is-on' : ''; ?>">Results</a>
                <?php if ($quizzesPageUrl !== ''): ?>
                <a href="<?php echo $h($quizzesPageUrl); ?>">Quizzes<?php echo $newQuizBadgeCount > 0 ? ' · ' . ($newQuizBadgeCount > 9 ? '9+' : (string) $newQuizBadgeCount) : ''; ?></a>
                <?php endif; ?>
                <a href="<?php echo $h($downloadsPageUrl); ?>">Files<?php echo $downloadsBadgeCount > 0 ? ' · ' . ($downloadsBadgeCount > 9 ? '9+' : (string) $downloadsBadgeCount) : ''; ?></a>
            </nav>
            <div class="tt-dash-actions shrink-0" role="toolbar" aria-label="Dashboard tools">
                <button type="button" id="dashboardRefreshBtn" class="tt-dash-icon-btn" aria-label="Refresh dashboard">
                    <i class="fa-solid fa-rotate-right transition-transform duration-500" id="dashboardRefreshIcon" aria-hidden="true"></i>
                </button>
                <?php trytest_student_theme_toggle_button(); ?>
                <div class="relative shrink-0">
                    <button type="button" id="profileMenuBtn" class="tt-dash-avatar-btn" aria-expanded="false" aria-haspopup="true" aria-label="Account menu">
                        <?php echo trytest_student_avatar_svg($userIndex, 40, $userId); ?>
                    </button>
                    <div id="profileMenu" class="hidden absolute right-0 z-40 mt-2 max-h-[min(16rem,45svh)] w-56 overflow-y-auto rounded-xl border border-slate-200 bg-white py-2 shadow-lg dark:border-zinc-700 dark:bg-zinc-900" role="menu">
                        <p class="px-3 text-xs font-semibold text-slate-900 dark:text-zinc-100">Profile</p>
                        <p class="mt-0.5 truncate px-3 text-[11px] text-slate-500 dark:text-zinc-400"><?php echo $h($userIndex); ?></p>
                        <div class="mt-2 space-y-1 border-t border-slate-100 px-3 py-2 text-xs text-slate-600 dark:border-zinc-800 dark:text-zinc-300">
                            <p class="flex items-center justify-between gap-2"><span class="text-slate-400 dark:text-zinc-500">Level</span><span class="font-medium text-slate-800 dark:text-zinc-100"><?php echo $h($userLevel); ?></span></p>
                            <p class="flex items-center justify-between gap-2"><span class="text-slate-400 dark:text-zinc-500">Program</span><span class="max-w-[9rem] truncate font-medium text-slate-800 dark:text-zinc-100"><?php echo $h($deptLabel); ?></span></p>
                            <p class="flex items-center justify-between gap-2"><span class="text-slate-400 dark:text-zinc-500">Points</span><span class="font-semibold tabular-nums text-[#2C6A7D] dark:text-[#7eb8b8]"><?php echo (int) $totalPoints; ?></span></p>
                        </div>
                        <?php if ($departmentOptions !== []): ?>
                        <form method="post" action="<?php echo $h($studentPortalPostUrl); ?>" class="space-y-2 border-t border-slate-100 px-3 py-2 dark:border-zinc-800" role="none">
                            <input type="hidden" name="action" value="update_student_department">
                            <p class="text-[11px] font-semibold text-slate-700 dark:text-zinc-200">Change program</p>
                            <select name="department" required class="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100">
                                <?php foreach ($departmentOptions as $depOpt): ?>
                                    <?php $dv = (string) ($depOpt['value'] ?? ''); ?>
                                    <option value="<?php echo $h($dv); ?>" <?php echo strcasecmp($dv, $userDepartment) === 0 ? 'selected' : ''; ?>><?php echo $h((string) ($depOpt['label'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($levelOptions !== []): ?>
                            <select name="level" required class="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100">
                                <?php foreach ($levelOptions as $lo): ?>
                                    <?php $lv = (string) ($lo['value'] ?? ''); ?>
                                    <option value="<?php echo $h($lv); ?>" <?php echo trytest_level_canon($lv) === trytest_level_canon($userLevel) ? 'selected' : ''; ?>><?php echo $h((string) ($lo['label'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                            <button type="submit" class="w-full rounded-lg bg-[#2C6A7D] px-2 py-1.5 text-xs font-semibold text-white">Update</button>
                        </form>
                        <?php endif; ?>
                        <a href="<?php echo $h($dashboardUrl); ?>?tab=results" class="border-t border-slate-100 px-3 py-2 text-sm font-medium text-[#2C6A7D] hover:bg-slate-50 dark:border-zinc-800 dark:text-[#7eb8b8] dark:hover:bg-zinc-800" role="menuitem">My results</a>
                        <a href="<?php echo $h($downloadsPageUrl); ?>" class="flex items-center justify-between border-t border-slate-100 px-3 py-2 text-sm font-medium text-[#2C6A7D] hover:bg-slate-50 dark:border-zinc-800 dark:text-[#7eb8b8] dark:hover:bg-zinc-800"><span>Downloads</span><?php echo $downloadsMenuBadge; ?></a>
                        <form method="post" class="border-t border-slate-100 px-2 pt-2 dark:border-zinc-800">
                            <input type="hidden" name="action" value="logout_user">
                            <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm text-[#E50914] hover:bg-red-50 dark:text-[#ff6b6b] dark:hover:bg-red-950/40" role="menuitem">Log out</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="<?php echo $dashboardFixedViewport
        ? 'tt-dash-main mx-auto flex min-h-0 w-full max-w-6xl flex-1 flex-col gap-3 overflow-hidden p-4 lg:max-w-7xl lg:overflow-visible lg:pb-10'
        : 'tt-dash-main mx-auto w-full max-w-6xl px-4 pb-24 pt-4 md:pb-8 lg:max-w-7xl'; ?>">
        <?php if ($needsDepartmentSetup): ?>
            <section class="mb-4 rounded-xl border-2 border-amber-400 bg-amber-50 px-4 py-3 shadow-sm dark:border-amber-600/50 dark:bg-amber-950/40" role="region" aria-labelledby="dept-setup-title">
                <h2 id="dept-setup-title" class="text-sm font-bold text-amber-950 dark:text-amber-100">
                    <?php echo $departmentSetupRequired ? 'Update your program' : 'Not seeing your quizzes?'; ?>
                </h2>
                <p class="mt-1 text-xs text-amber-900/90 dark:text-amber-200/80">
                    <?php if ($departmentSetupRequired): ?>
                        <?php echo $userDepartment === ''
                            ? 'Your account has no program set yet. Pick the one that matches your class.'
                            : 'Your saved program is no longer on the list (it may have been renamed or removed). Choose the current program so quizzes show up.'; ?>
                    <?php else: ?>
                        Your program is set to <strong><?php echo $h($userDepartment !== '' ? $userDepartment : '—'); ?></strong>
                        (Lv <?php echo $h($userLevel !== '' ? $userLevel : '—'); ?>). If that looks wrong, update it below — quizzes only appear for the matching class.
                    <?php endif; ?>
                </p>
                <?php if ($departmentUpdateError !== ''): ?>
                    <p class="mt-2 rounded-lg bg-red-100 px-3 py-2 text-xs font-medium text-red-800 dark:bg-red-950/50 dark:text-red-200"><?php echo $h($departmentUpdateError); ?></p>
                <?php endif; ?>
                <form method="post" action="<?php echo $h($studentPortalPostUrl); ?>" class="mt-3 grid gap-2 sm:grid-cols-2">
                    <input type="hidden" name="action" value="update_student_department">
                    <label class="block min-w-0 text-left">
                        <span class="mb-1 block text-[11px] font-medium text-amber-950/80 dark:text-amber-200/90">Program / department</span>
                        <select name="department" required class="w-full rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm font-medium text-slate-900 focus:outline-none focus:ring-2 focus:ring-amber-500 dark:border-amber-700 dark:bg-zinc-900 dark:text-zinc-100">
                            <option value="">Select…</option>
                            <?php foreach ($departmentOptions as $depOpt): ?>
                                <?php $dv = (string) ($depOpt['value'] ?? ''); ?>
                                <option value="<?php echo $h($dv); ?>" <?php echo strcasecmp($dv, $userDepartment) === 0 ? 'selected' : ''; ?>><?php echo $h((string) ($depOpt['label'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="block min-w-0 text-left">
                        <span class="mb-1 block text-[11px] font-medium text-amber-950/80 dark:text-amber-200/90">Level</span>
                        <select name="level" required class="w-full rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm font-medium text-slate-900 focus:outline-none focus:ring-2 focus:ring-amber-500 dark:border-amber-700 dark:bg-zinc-900 dark:text-zinc-100">
                            <option value="">Select…</option>
                            <?php foreach ($levelOptions as $lo): ?>
                                <?php $lv = (string) ($lo['value'] ?? ''); ?>
                                <option value="<?php echo $h($lv); ?>" <?php echo trytest_level_canon($lv) === trytest_level_canon($userLevel) ? 'selected' : ''; ?>><?php echo $h((string) ($lo['label'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="w-full rounded-lg bg-[#2C6A7D] px-5 py-2 text-sm font-bold text-white hover:bg-[#24586a] dark:bg-[#3d7d91] dark:hover:bg-[#356d7f] sm:col-span-2">Save &amp; show my quizzes</button>
                </form>
            </section>
        <?php endif; ?>
        <?php if (is_array($doneBlock) && !empty($doneBlock['quiz_id'])): ?>
            <?php $acc = $doneBlock['total'] > 0 ? (int) round(100 * (int) $doneBlock['score'] / (int) $doneBlock['total']) : 0; ?>
            <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-center text-xs font-semibold uppercase tracking-widest text-[#E50914] dark:text-[#f87171]">Quiz complete</p>
                <h2 class="mt-1 text-center text-lg font-bold dark:text-zinc-100"><?php echo $h((string) ($doneBlock['title'] ?? 'Quiz')); ?></h2>
                <div class="mx-auto mt-4 flex h-28 w-28 items-center justify-center rounded-full border-4 border-[#84B8B8] bg-white text-2xl font-extrabold tabular-nums text-[#2C6A7D] dark:bg-zinc-800 dark:text-[#7eb8b8]">
                    <span class="whitespace-nowrap"><?php echo (int) $doneBlock['score']; ?><span class="text-sm font-normal text-slate-400 dark:text-zinc-500">/<?php echo (int) $doneBlock['total']; ?></span></span>
                </div>
                <p class="mt-3 text-center text-sm text-slate-600 dark:text-zinc-400">Accuracy <?php echo $acc; ?>%</p>
                <?php if ($studentGameXp > 0): ?>
                    <p class="mt-1 text-center text-xs font-semibold text-[#1d4ed8] dark:text-sky-300">Game XP on your profile: <?php echo (int) $studentGameXp; ?></p>
                <?php endif; ?>
                <?php if (is_array($doneComparison)): ?>
                    <?php $delta = (int) ($doneComparison['delta'] ?? 0); ?>
                    <p class="mt-1 text-center text-sm font-semibold <?php echo $delta >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400'; ?>">
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
                    <p class="text-center text-sm font-semibold text-[#2C6A7D] dark:text-[#7eb8b8]">Your rank on this quiz: #<?php echo (int) $doneBlock['rank']; ?></p>
                <?php endif; ?>
                <?php if ($quizDoneYoutubeHtml !== ''): ?>
                    <div class="mt-4"><?php echo $quizDoneYoutubeHtml; ?></div>
                <?php endif; ?>
                <?php if (empty($doneBlock['can_retry'])): ?>
                    <p class="mt-3 text-center text-xs text-slate-500 dark:text-zinc-400">This quiz is no longer accepting new attempts. Your last score is still listed under <a href="<?php echo $h(rtrim($dashboardUrl, '/') . '?tab=results'); ?>" class="font-semibold text-[#2C6A7D] underline dark:text-[#7eb8b8]">My results</a>.</p>
                <?php else: ?>
                    <p class="mt-3 text-center text-xs text-slate-500 dark:text-zinc-400">This quiz is removed from your <strong>Quizzes</strong> list now that you have a score. Open <a href="<?php echo $h(rtrim($dashboardUrl, '/') . '?tab=results'); ?>" class="font-semibold text-[#2C6A7D] underline dark:text-[#7eb8b8]">My results</a> anytime for <strong>Try again</strong> (that clears your score first).</p>
                <?php endif; ?>
                <a href="<?php echo $h(rtrim($dashboardUrl, '/') . '?tab=results'); ?>" class="mt-3 block w-full rounded-xl border-2 border-[#2C6A7D] bg-white py-3 text-center text-sm font-bold text-[#2C6A7D] hover:bg-slate-50 dark:border-[#5a9a9a] dark:bg-zinc-800 dark:text-[#7eb8b8] dark:hover:bg-zinc-700">My results</a>
                <a href="<?php echo $h($dashboardUrl); ?>" class="mt-2 block w-full rounded-xl bg-[#E50914] py-3 text-center text-sm font-bold text-white dark:bg-[#c4080f]">Back to home</a>
            </section>

            <?php if ($studentFeedbackApiUrl !== '' && !$studentFeedbackAlreadySubmitted): ?>
                <style>
                    .trytest-feedback-card .trytest-feedback-star .trytest-feedback-star-icon path {
                        fill: none;
                        stroke: #94a3b8;
                        stroke-width: 1.25;
                        stroke-linejoin: round;
                    }
                    .trytest-feedback-card .trytest-feedback-star.trytest-feedback-star--lit .trytest-feedback-star-icon path {
                        fill: #fbbf24;
                        stroke: #d97706;
                        stroke-width: 0.85;
                    }
                    .dark .trytest-feedback-card .trytest-feedback-star:not(.trytest-feedback-star--lit) .trytest-feedback-star-icon path {
                        stroke: #71717a;
                    }
                </style>
                <section class="trytest-feedback-card mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" aria-labelledby="trytest-feedback-title">
                    <h2 id="trytest-feedback-title" class="text-center text-sm font-bold text-slate-900 dark:text-zinc-100">How was Trytest?</h2>
                    <p class="mt-1 text-center text-[11px] leading-snug text-slate-500 dark:text-zinc-400">Tap a star to rate your experience (one time only).</p>
                    <div class="mt-3 flex justify-center">
                        <div id="trytestFeedbackStars" class="inline-flex max-w-full items-center gap-0.5 rounded-2xl border border-slate-200/90 bg-slate-50/90 p-1 shadow-inner dark:border-zinc-700/90 dark:bg-zinc-900/60" role="group" aria-label="Star rating">
                            <?php for ($si = 1; $si <= 5; $si++): ?>
                                <button type="button" class="trytest-feedback-star group flex h-9 w-9 shrink-0 items-center justify-center rounded-xl transition-colors hover:bg-white/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#2C6A7D] dark:hover:bg-zinc-800/80 dark:focus-visible:ring-[#7eb8b8]" data-star="<?php echo $si; ?>" aria-label="<?php echo $si; ?> out of 5 stars">
                                    <svg class="trytest-feedback-star-icon h-6 w-6 transition-transform duration-150 group-hover:scale-105" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M11.625 2.275c.131-.267.556-.267.687 0l2.204 4.457 4.93.717c.304.044.427.417.195.627l-3.566 3.477.842 4.905c.052.305-.267.539-.535.395l-4.408-2.317-4.408 2.317c-.268.144-.587-.09-.535-.395l.842-4.905-3.566-3.477c-.232-.21-.109-.583.195-.627l4.93-.717 2.204-4.457z"/>
                                    </svg>
                                </button>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <p id="trytestFeedbackMsg" class="mt-2 hidden text-center text-[11px] font-medium"></p>
                </section>
            <?php endif; ?>

            <section class="mb-8 rounded-2xl border border-slate-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="text-center text-sm font-bold dark:text-zinc-100">This quiz · leaderboard</h3>
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
            $tileRounded = 'rounded-xl';
            $tileSvg = 22;
            $iconBox = 'tt-dash-tile-icon flex h-9 w-9 shrink-0 items-center justify-center';
            $tileRow2 = 'grid w-full min-w-0 grid-cols-2 gap-2';
            $tileH = 'min-h-[3.75rem]';
            $slimCard =
                'tt-dash-tile relative flex min-h-0 min-w-0 flex-row items-center gap-2.5 overflow-hidden ' . $tileH . ' '
                . 'text-left transition active:scale-[0.99]';
            $cheerSectionClass = $homeFlexLock
                ? 'tt-dash-cheer shrink-0 text-slate-900 dark:text-zinc-100'
                : 'tt-dash-cheer mb-4 text-slate-900 dark:text-zinc-100';
            $cheerBodyClass = $homeFlexLock
                ? 'mt-0.5 line-clamp-2 text-[11px] leading-snug'
                : 'mt-0.5 text-[11px] leading-snug';
            $quickSectionClass = $homeFlexLock ? 'min-w-0 shrink-0 overflow-hidden' : 'mb-6';
            $dashboardFeaturedKind = trim((string) ($dashboardFeaturedKind ?? 'words'));
            $homeQuickVideoLayout = trim((string) ($dashboardFeaturedHtml ?? '')) !== '' && $dashboardFeaturedKind === 'video';
            ?>
            <style>
                @keyframes trytest-quiz-icon-breathe {
                    0%, 100% { transform: scale(1); }
                    50% { transform: scale(1.12); }
                }
                .trytest-quiz-home-icon--pulse { animation: trytest-quiz-icon-breathe 2.4s ease-in-out infinite; }
            </style>
            <?php if ($homeFlexLock): ?>
            <div class="tt-dash-home flex min-h-0 flex-1 flex-col gap-3 overflow-hidden lg:overflow-visible">
            <?php else: ?>
            <div class="tt-dash-home flex flex-col gap-4">
            <?php endif; ?>
            <?php if ($enc !== null): ?>
                <section id="trytest-dash-cheer" class="<?php echo $h($cheerSectionClass); ?> tt-dash-home__cheer" aria-labelledby="dash-cheer-title">
                    <h2 id="dash-cheer-title" class="font-bold leading-tight"><?php echo $h((string) ($enc['lead'] ?? '')); ?></h2>
                    <p class="<?php echo $h($cheerBodyClass); ?>"><?php echo $h((string) ($enc['body'] ?? '')); ?></p>
                    <div class="mt-2 flex min-w-0 flex-wrap gap-1.5">
                        <?php if ($encQuizId > 0): ?>
                            <a href="<?php echo $h(rtrim($quizUrlBase, '/') . '?quiz_id=' . $encQuizId); ?>" class="tt-dash-cta">Open quiz</a>
                        <?php endif; ?>
                        <?php if ($totalQuizCards > 0 && $quizzesPageUrl !== ''): ?>
                            <a href="<?php echo $h($quizzesPageUrl); ?>" class="tt-dash-cta tt-dash-cta--ghost">All quizzes</a>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
            <?php if ($dashboardNudgesHtml !== ''): ?>
                <div class="tt-dash-home__nudge<?php echo $homeFlexLock ? ' min-h-0 shrink-0' : ''; ?>">
                    <?php echo $dashboardNudgesHtml; ?>
                </div>
            <?php endif; ?>
            <div class="tt-dash-home__grid">
            <?php if ($dashboardFeaturedHtml !== ''): ?>
                <div class="tt-dash-home__featured<?php echo $homeFlexLock ? ' min-h-0 shrink-0 overflow-hidden' : ''; ?>">
                    <?php echo $dashboardFeaturedHtml; ?>
                </div>
            <?php endif; ?>
            <section class="<?php echo $h($quickSectionClass); ?> tt-dash-home__links" aria-label="Quick links">
                <div class="tt-dash-tile-grid flex w-full min-w-0 flex-col gap-1.5 sm:gap-2">
                    <?php if ($homeQuickVideoLayout): ?>
                        <div class="<?php echo $h($tileRow2); ?>">
                            <a href="<?php echo $h($dashboardUrl); ?>?tab=rank" class="<?php echo $h($slimCard); ?>">
                                <span class="<?php echo $h($iconBox); ?>" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('trophy', $tileSvg); ?></span>
                                <span class="min-w-0 flex-1 overflow-hidden">
                                    <span class="tt-dash-tile-title block truncate leading-tight">Leaderboard</span>
                                    <span class="tt-dash-tile-sub mt-0.5 block truncate">Level ranks</span>
                                </span>
                            </a>
                            <?php if ($totalQuizCards > 0 && $quizzesPageUrl !== ''): ?>
                                <a href="<?php echo $h($quizzesPageUrl); ?>" class="<?php echo $h($slimCard); ?>">
                                    <?php if ($newQuizBadgeCount > 0): ?>
                                        <span class="tt-dash-badge absolute right-1.5 top-1.5 z-10 inline-flex h-4 max-w-[2.75rem] items-center justify-center rounded-full px-0.5 text-[7px] font-extrabold leading-none text-white"><?php echo $newQuizBadgeCount > 9 ? '9+' : (string) $newQuizBadgeCount; ?> new</span>
                                    <?php endif; ?>
                                    <span class="<?php echo $h($iconBox); ?> <?php echo $newQuizBadgeCount > 0 ? 'trytest-quiz-home-icon--pulse' : ''; ?>" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('quiz', $tileSvg); ?></span>
                                    <span class="min-w-0 flex-1 overflow-hidden pr-0.5">
                                        <span class="tt-dash-tile-title block truncate leading-tight">Quizzes</span>
                                        <span class="tt-dash-tile-sub mt-0.5 block truncate"><span class="font-extrabold tabular-nums text-[#1d4ed8]"><?php echo (int) $totalQuizCards; ?></span> ready</span>
                                    </span>
                                </a>
                            <?php else: ?>
                                <div class="<?php echo $h($slimCard); ?> pointer-events-none opacity-80">
                                    <span class="<?php echo $h($iconBox); ?> opacity-70" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('quiz', $tileSvg); ?></span>
                                    <span class="min-w-0 flex-1 overflow-hidden">
                                        <span class="tt-dash-tile-title block truncate leading-tight text-slate-600">Quizzes</span>
                                        <span class="tt-dash-tile-sub mt-0.5 block truncate">None yet</span>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="<?php echo $h($tileRow2); ?>">
                            <a href="<?php echo $h($dashboardUrl); ?>?tab=rank" class="<?php echo $h($slimCard); ?>">
                                <span class="<?php echo $h($iconBox); ?>" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('trophy', $tileSvg); ?></span>
                                <span class="min-w-0 flex-1 overflow-hidden">
                                    <span class="tt-dash-tile-title block truncate leading-tight">Leaderboard</span>
                                    <span class="tt-dash-tile-sub mt-0.5 block truncate">Level ranks</span>
                                </span>
                            </a>
                            <a href="<?php echo $h($downloadsPageUrl); ?>" class="<?php echo $h($slimCard); ?>">
                                <?php if ($downloadsBadgeCount > 0): ?>
                                    <span class="tt-dash-badge absolute right-1.5 top-1.5 z-10 inline-flex h-4 min-w-[1rem] items-center justify-center rounded-full px-0.5 text-[8px] font-extrabold leading-none text-white"><?php echo $downloadsBadgeCount > 9 ? '9+' : (string) $downloadsBadgeCount; ?></span>
                                <?php endif; ?>
                                <span class="<?php echo $h($iconBox); ?>" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('folder', $tileSvg); ?></span>
                                <span class="min-w-0 flex-1 overflow-hidden">
                                    <span class="tt-dash-tile-title block truncate leading-tight">Files</span>
                                    <span class="tt-dash-tile-sub mt-0.5 block truncate">Downloads</span>
                                </span>
                            </a>
                        </div>
                        <div class="<?php echo $h($tileRow2); ?>">
                            <?php if ($totalQuizCards > 0 && $quizzesPageUrl !== ''): ?>
                                <a href="<?php echo $h($quizzesPageUrl); ?>" class="<?php echo $h($slimCard); ?>">
                                    <?php if ($newQuizBadgeCount > 0): ?>
                                        <span class="tt-dash-badge absolute right-1.5 top-1.5 z-10 inline-flex h-4 max-w-[2.75rem] items-center justify-center rounded-full px-0.5 text-[7px] font-extrabold leading-none text-white"><?php echo $newQuizBadgeCount > 9 ? '9+' : (string) $newQuizBadgeCount; ?> new</span>
                                    <?php endif; ?>
                                    <span class="<?php echo $h($iconBox); ?> <?php echo $newQuizBadgeCount > 0 ? 'trytest-quiz-home-icon--pulse' : ''; ?>" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('quiz', $tileSvg); ?></span>
                                    <span class="min-w-0 flex-1 overflow-hidden pr-0.5">
                                        <span class="tt-dash-tile-title block truncate leading-tight">Quizzes</span>
                                        <span class="tt-dash-tile-sub mt-0.5 block truncate"><span class="font-extrabold tabular-nums text-[#1d4ed8]"><?php echo (int) $totalQuizCards; ?></span> ready</span>
                                    </span>
                                </a>
                            <?php else: ?>
                                <div class="<?php echo $h($slimCard); ?> pointer-events-none opacity-80">
                                    <span class="<?php echo $h($iconBox); ?> opacity-70" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('quiz', $tileSvg); ?></span>
                                    <span class="min-w-0 flex-1 overflow-hidden">
                                        <span class="tt-dash-tile-title block truncate leading-tight text-slate-600">Quizzes</span>
                                        <span class="tt-dash-tile-sub mt-0.5 block truncate">None yet</span>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <a href="<?php echo $h($dashboardUrl); ?>?tab=results" class="<?php echo $h($slimCard); ?>">
                                <span class="<?php echo $h($iconBox); ?>" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('results', $tileSvg); ?></span>
                                <span class="min-w-0 flex-1 overflow-hidden">
                                    <span class="tt-dash-tile-title block truncate leading-tight">Results</span>
                                    <span class="tt-dash-tile-sub mt-0.5 block truncate">My scores</span>
                                </span>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            </div>
            <?php if ($studentGamePanelHtml !== ''): ?>
                <div class="tt-dash-home__game<?php echo $homeFlexLock ? ' hidden lg:block' : ''; ?>">
                    <?php echo $studentGamePanelHtml; ?>
                </div>
            <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($tabRank): ?>
            <section class="mb-4 tt-dash-pagehead">
                <a href="<?php echo $h($dashboardUrl); ?>" class="text-sm text-[#2C6A7D] dark:text-[#7eb8b8]">← Home</a>
                <h2 class="mt-2 text-xl font-bold dark:text-zinc-100 lg:text-2xl">Leaderboard</h2>
            </section>
            <div class="tt-dash-rankboard">
            <?php echo trytest_render_level_podium_html($levelLeaderboardRows, $userId, $h); ?>
            </div>
        <?php endif; ?>

        <?php if ($tabResults): ?>
            <section class="mb-4 tt-dash-pagehead">
                <a href="<?php echo $h($dashboardUrl); ?>" class="text-sm text-[#2C6A7D] dark:text-[#7eb8b8]">← Home</a>
                <h2 class="mt-2 text-xl font-bold dark:text-zinc-100 lg:text-2xl">My results</h2>
                <p class="mt-1 text-xs text-slate-600 dark:text-zinc-400 lg:text-sm">Scores from quizzes you have finished. <strong>View quiz</strong> shows each question with right or wrong. <strong>Try again</strong> removes your saved score and attempts, then opens a fresh run.</p>
            </section>
            <?php if ($quizResultsRows === []): ?>
                <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50/80 px-4 py-10 text-center text-sm text-slate-600 dark:border-zinc-700 dark:bg-zinc-900/60 dark:text-zinc-400">
                    <p class="font-medium text-slate-800 dark:text-zinc-200">No results yet</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-zinc-500"><?php if ($quizzesPageUrl !== ''): ?>Complete a quiz from <a href="<?php echo $h($quizzesPageUrl); ?>" class="font-semibold text-[#2C6A7D] underline dark:text-[#7eb8b8]">Quizzes</a><?php else: ?>Complete a quiz<?php endif; ?> and your score will show here.</p>
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
                        <li class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-bold text-slate-900 dark:text-zinc-100"><?php echo $h((string) ($resRow['title'] ?? 'Quiz')); ?></p>
                                    <p class="mt-1 text-xs text-slate-500 dark:text-zinc-500"><?php echo $rwhenDisp !== '' ? $h($rwhenDisp) : ''; ?></p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="text-lg font-extrabold tabular-nums text-[#2C6A7D] dark:text-[#7eb8b8]"><?php echo $rscore; ?><span class="text-sm font-semibold text-slate-400 dark:text-zinc-500">/<?php echo $rtotal; ?></span></p>
                                    <p class="text-[11px] font-medium text-slate-500 dark:text-zinc-400"><?php echo $racc; ?>% accuracy</p>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <a href="<?php echo $h(trytest_url('quiz_review?quiz_id=' . $rqid)); ?>" class="inline-flex items-center rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs font-semibold text-zinc-800 shadow-sm hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700">View quiz</a>
                                <form method="post" action="<?php echo $h($studentPortalPostUrl); ?>" class="inline" onsubmit="return confirm('Reset this quiz? Your saved score and attempts will be removed, then you start fresh.');">
                                    <input type="hidden" name="action" value="reset_student_quiz">
                                    <input type="hidden" name="quiz_id" value="<?php echo $rqid; ?>">
                                    <button type="submit" class="inline-flex items-center rounded-lg border border-zinc-800 bg-zinc-900 px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-zinc-800 dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white">Try again</button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <nav class="tt-dash-nav <?php echo $dashboardFixedViewport
        ? 'shrink-0 md:hidden'
        : 'fixed bottom-0 left-0 right-0 z-50 md:hidden'; ?>" style="padding-bottom: max(0.5rem, env(safe-area-inset-bottom));">
        <div class="<?php echo $dashboardFixedViewport ? 'mx-auto flex w-full max-w-md md:max-w-3xl' : 'mx-auto flex max-w-6xl'; ?>">
            <a href="<?php echo $h($dashboardUrl); ?>" class="<?php echo $h($navClass($homeNavOn)); ?>">
                <span class="tt-dash-nav-icon flex h-6 w-6 items-center justify-center" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 4l9 6.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-9.5z"/></svg></span>
                <span class="text-[9px] font-semibold leading-tight">Home</span>
            </a>
            <a href="<?php echo $h($dashboardUrl); ?>?tab=rank" class="<?php echo $h($navClass($tabRank)); ?>">
                <span class="tt-dash-nav-icon flex h-6 w-6 items-center justify-center" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('trophy', 22); ?></span>
                <span class="text-[9px] font-semibold leading-tight">Rank</span>
            </a>
            <a href="<?php echo $h($dashboardUrl); ?>?tab=results" class="<?php echo $h($navClass($tabResults)); ?>">
                <span class="tt-dash-nav-icon flex h-6 w-6 items-center justify-center" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('results', 22); ?></span>
                <span class="text-[9px] font-semibold leading-tight">Results</span>
            </a>
            <a href="<?php echo $h($downloadsPageUrl); ?>" class="<?php echo $h($navClass(false)); ?> relative">
                <span class="tt-dash-nav-icon flex h-6 w-6 items-center justify-center" aria-hidden="true"><?php echo trytest_student_dashboard_tile_svg('folder', 22); ?></span>
                <?php if ($downloadsBadgeCount > 0): ?>
                    <span class="tt-dash-badge absolute right-[18%] top-0.5 flex h-[15px] min-w-[15px] items-center justify-center rounded-full px-0.5 text-[8px] font-extrabold leading-none text-white" aria-label="<?php echo (int) $downloadsBadgeCount; ?> pending"><?php echo $downloadsBadgeCount > 9 ? '9+' : (string) (int) $downloadsBadgeCount; ?></span>
                <?php endif; ?>
                <span class="text-[9px] font-semibold leading-tight">Files</span>
            </a>
        </div>
    </nav>
</div>
<?php trytest_student_theme_controller_script(); ?>
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
    var refreshBtn = document.getElementById('dashboardRefreshBtn');
    var refreshIcon = document.getElementById('dashboardRefreshIcon');
    if (!refreshBtn) return;
    refreshBtn.addEventListener('click', function () {
        if (refreshIcon) {
            refreshIcon.classList.add('animate-spin');
        }
        window.setTimeout(function () {
            window.location.reload();
        }, 450);
    });
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
(function () {
    var api = <?php echo json_encode($studentFeedbackApiUrl, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); ?>;
    if (!api) return;
    <?php if ($studentFeedbackAlreadySubmitted): ?>return;<?php endif; ?>
    var root = document.getElementById('trytestFeedbackStars');
    var msg = document.getElementById('trytestFeedbackMsg');
    if (!root || !msg) return;
    var quizRef = <?php echo json_encode(
        (is_array($doneBlock) && !empty($doneBlock['quiz_id'])) ? ('quiz:' . (int) $doneBlock['quiz_id']) : '',
        JSON_THROW_ON_ERROR
    ); ?>;
    var stars = root.querySelectorAll('.trytest-feedback-star');
    /** Keyboard/mouse hover preview (stars 1..N). */
    var hoverRating = 0;
    /** Shown while POST is in flight so tap on star 5 still lights 1–5. */
    var pendingRating = 0;
    /** Last rating saved successfully. */
    var committedRating = 0;
    function effectiveRating() {
        if (hoverRating > 0) {
            return hoverRating;
        }
        if (pendingRating > 0) {
            return pendingRating;
        }
        return committedRating;
    }
    function repaint() {
        var eff = effectiveRating();
        stars.forEach(function (b) {
            var sn = parseInt(b.getAttribute('data-star') || '0', 10);
            var on = eff > 0 && sn <= eff;
            b.classList.toggle('trytest-feedback-star--lit', on);
        });
    }
    root.addEventListener('mouseleave', function () {
        hoverRating = 0;
        repaint();
    });
    root.addEventListener('focusin', function (e) {
        var t = e.target;
        if (t && t.classList && t.classList.contains('trytest-feedback-star')) {
            hoverRating = parseInt(t.getAttribute('data-star') || '0', 10);
            repaint();
        }
    });
    root.addEventListener('focusout', function (e) {
        if (!root.contains(e.relatedTarget)) {
            hoverRating = 0;
            repaint();
        }
    });
    function setBusy(on) {
        root.setAttribute('aria-busy', on ? 'true' : 'false');
        stars.forEach(function (b) {
            b.disabled = on;
            b.classList.toggle('opacity-50', on);
            b.classList.toggle('pointer-events-none', on);
        });
    }
    function postRating(n) {
        pendingRating = n;
        repaint();
        setBusy(true);
        msg.classList.add('hidden');
        fetch(api, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ stars: n, quiz_ref: quizRef || undefined }),
        })
            .then(function (r) {
                return r.json().then(function (d) {
                    return { ok: r.ok, d: d };
                });
            })
            .then(function (res) {
                var d = res.d;
                if (d && d.ok) {
                    msg.textContent = 'Thanks — your rating was saved.';
                    msg.classList.remove('hidden', 'text-amber-700');
                    msg.classList.add('text-emerald-700', 'dark:text-emerald-400');
                    committedRating = n;
                    pendingRating = 0;
                    hoverRating = 0;
                    repaint();
                } else if (d && d.error === 'already_rated') {
                    msg.textContent = 'You already submitted a rating.';
                    msg.classList.remove('hidden', 'text-emerald-700');
                    msg.classList.add('text-amber-700', 'dark:text-amber-400');
                    pendingRating = 0;
                    repaint();
                } else {
                    throw new Error('fail');
                }
            })
            .catch(function () {
                msg.textContent = 'Could not send. Check your connection and try again.';
                msg.classList.remove('hidden', 'text-emerald-700');
                msg.classList.add('text-amber-700');
                pendingRating = 0;
                repaint();
            })
            .finally(function () {
                setBusy(false);
            });
    }
    stars.forEach(function (btn) {
        btn.addEventListener('mouseenter', function () {
            var n = parseInt(btn.getAttribute('data-star') || '0', 10);
            if (n >= 1 && n <= 5) {
                hoverRating = n;
                repaint();
            }
        });
        btn.addEventListener('click', function () {
            var n = parseInt(btn.getAttribute('data-star') || '0', 10);
            if (n >= 1 && n <= 5) {
                postRating(n);
            }
        });
    });
})();
</script>
