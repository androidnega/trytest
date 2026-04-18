<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/departments.php';
require __DIR__ . '/includes/student_helpers.php';
require_once __DIR__ . '/includes/youtube_subscribe.php';
require_once __DIR__ . '/includes/student_dashboard_featured.php';
require_once __DIR__ . '/includes/student_theme.php';

$departmentOptions = trytest_department_dropdown_options($db);

$incomingShareQuiz = isset($_GET['quiz']) ? (int) $_GET['quiz'] : 0;
if ($incomingShareQuiz < 1 && isset($_GET['quiz_id'])) {
    $incomingShareQuiz = (int) $_GET['quiz_id'];
}
if ($incomingShareQuiz > 0) {
    $qc = $db->prepare('SELECT id FROM quizzes WHERE id = ?');
    $qc->execute([$incomingShareQuiz]);
    if ($qc->fetchColumn()) {
        $_SESSION['pending_shared_quiz_id'] = $incomingShareQuiz;
    }
}

/**
 * Simple 4-digit numeric password for students.
 */
function trytest_generate_student_password(): string
{
    return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

$error = '';
$departmentUpdateError = '';
$message = '';
$generatedPassword = '';
$enteredIndex = '';
$loginMode = 'index';
$existingUserLevel = '';
$viewReset = isset($_GET['view']) && $_GET['view'] === 'reset';

if ($viewReset && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $loginMode = 'reset';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$viewReset && empty($_SESSION['user_id']) && !empty($_SESSION['trytest_login_resume']) && is_array($_SESSION['trytest_login_resume'])) {
    $resume = $_SESSION['trytest_login_resume'];
    unset($_SESSION['trytest_login_resume']);
    if (($resume['mode'] ?? '') === 'existing') {
        $idxResume = strtoupper(trim((string) ($resume['index'] ?? '')));
        if ($idxResume !== '') {
            $enteredIndex = $idxResume;
            $existingUserLevel = (string) ($resume['level'] ?? '');
            $loginMode = 'existing';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedShare = isset($_POST['shared_quiz_id']) ? (int) $_POST['shared_quiz_id'] : 0;
    if ($postedShare > 0) {
        $qc = $db->prepare('SELECT id FROM quizzes WHERE id = ?');
        $qc->execute([$postedShare]);
        if ($qc->fetchColumn()) {
            $_SESSION['pending_shared_quiz_id'] = $postedShare;
        }
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'continue_after_password_display') {
        $postedShareResume = isset($_POST['shared_quiz_id']) ? (int) $_POST['shared_quiz_id'] : 0;
        if ($postedShareResume > 0) {
            $qc = $db->prepare('SELECT id FROM quizzes WHERE id = ?');
            $qc->execute([$postedShareResume]);
            if ($qc->fetchColumn()) {
                $_SESSION['pending_shared_quiz_id'] = $postedShareResume;
            }
        }
        $idx = strtoupper(trim((string) ($_POST['pending_index'] ?? '')));
        if ($idx === '') {
            trytest_redirect(trytest_home_url());
        }
        $stmt = $db->prepare('SELECT level, password_hash FROM users WHERE index_number = ?');
        $stmt->execute([$idx]);
        $row = $stmt->fetch();
        if (!$row || trim((string) ($row['password_hash'] ?? '')) === '') {
            trytest_redirect(trytest_home_url());
        }
        $_SESSION['trytest_login_resume'] = [
            'mode' => 'existing',
            'index' => $idx,
            'level' => (string) ($row['level'] ?? ''),
        ];
        trytest_redirect(trytest_home_url());
    }

    if ($action === 'update_student_department' && !empty($_SESSION['user_id'])) {
        $uid = (int) $_SESSION['user_id'];
        $deptRaw = (string) ($_POST['department'] ?? '');
        if ($departmentOptions === []) {
            trytest_redirect(trytest_url('dashboard'));
        }
        $resolvedDept = trytest_resolve_department_for_save($deptRaw, $departmentOptions);
        if ($resolvedDept === null) {
            $departmentUpdateError = 'Choose your program from the list, then save.';
        } else {
            try {
                $db->prepare('UPDATE users SET department = ? WHERE id = ?')->execute([$resolvedDept, $uid]);
                $_SESSION['user_department'] = $resolvedDept;
                trytest_redirect(trytest_url('dashboard'));
            } catch (Throwable $e) {
                $departmentUpdateError = 'Could not save your program now. Please try again shortly.';
            }
        }
    }

    if ($action === 'check_index') {
        $indexNumber = strtoupper(trim((string) ($_POST['index_number'] ?? '')));
        if ($indexNumber === '') {
            $error = 'Index number is required.';
        } else {
            $enteredIndex = $indexNumber;
            $stmt = $db->prepare('SELECT id, level, password_hash FROM users WHERE index_number = ?');
            $stmt->execute([$indexNumber]);
            $user = $stmt->fetch();
            if (!$user) {
                $loginMode = 'new';
            } else {
                $storedHash = (string) ($user['password_hash'] ?? '');
                if ($storedHash === '') {
                    $plainPassword = trytest_generate_student_password();
                    $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
                    try {
                        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                            ->execute([$passwordHash, (int) $user['id']]);
                        $message = 'A new 4-digit Trytest password was created for you. Save it somewhere safe.';
                        $generatedPassword = $plainPassword;
                    } catch (Throwable $e) {
                        $error = 'Could not create password now. Please try again shortly.';
                        $loginMode = 'index';
                    }
                } else {
                    $loginMode = 'existing';
                    $existingUserLevel = (string) ($user['level'] ?? '');
                }
            }
        }
    }

    if ($action === 'login_existing') {
        $indexNumber = strtoupper(trim((string) ($_POST['index_number'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $enteredIndex = $indexNumber;
        $loginMode = 'existing';
        if ($indexNumber === '' || $password === '') {
            $error = 'Index number and password are required.';
        } else {
            $stmt = $db->prepare('SELECT id, level, password_hash, department FROM users WHERE index_number = ?');
            $stmt->execute([$indexNumber]);
            $user = $stmt->fetch();
            if (!$user) {
                $error = 'User not found. Enter index again.';
                $loginMode = 'index';
            } elseif (!password_verify($password, (string) ($user['password_hash'] ?? ''))) {
                $error = 'Invalid password. Use “Forgot password?” if you need a new 4-digit code.';
                $existingUserLevel = (string) ($user['level'] ?? '');
            } else {
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['user_index_number'] = $indexNumber;
                $_SESSION['user_level'] = (string) $user['level'];
                $_SESSION['user_department'] = trim((string) ($user['department'] ?? ''));
                try {
                    $db->prepare('UPDATE users SET last_login_at = datetime(\'now\') WHERE id = ?')
                        ->execute([(int) $user['id']]);
                } catch (Throwable $e) {
                    // Login still succeeds even if analytics timestamp update fails.
                }
                trytest_redirect(trytest_student_post_login_redirect_url($db));
            }
        }
    }

    if ($action === 'register_new') {
        $indexNumber = strtoupper(trim((string) ($_POST['index_number'] ?? '')));
        $level = trim((string) ($_POST['level'] ?? ''));
        $department = trim((string) ($_POST['department'] ?? ''));
        $enteredIndex = $indexNumber;
        $loginMode = 'new';
        if ($indexNumber === '' || $level === '') {
            $error = 'Index number and level are required.';
        } else {
            $resolvedDept = trytest_resolve_department_for_save($department, $departmentOptions);
            if ($departmentOptions !== [] && $resolvedDept === null) {
                $error = 'Please choose your program / department from the list.';
            } else {
                $departmentToSave = (string) $resolvedDept;
                $exists = $db->prepare('SELECT id FROM users WHERE index_number = ?');
                $exists->execute([$indexNumber]);
                if ($exists->fetch()) {
                    $error = 'Account already exists. Use password login.';
                    $loginMode = 'existing';
                } else {
                    $plainPassword = trytest_generate_student_password();
                    $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
                    try {
                        $db->prepare('INSERT INTO users (index_number, level, password_hash, department) VALUES (?, ?, ?, ?)')
                            ->execute([$indexNumber, $level, $passwordHash, $departmentToSave]);
                        $message = 'Your Trytest password is 4 digits. Use it next time you sign in.';
                        $generatedPassword = $plainPassword;
                        $loginMode = 'index';
                    } catch (Throwable $e) {
                        $error = 'Could not create your account now. Please try again shortly.';
                    }
                }
            }
        }
    }

    if ($action === 'reset_password') {
        $indexNumber = strtoupper(trim((string) ($_POST['index_number'] ?? '')));
        $enteredIndex = $indexNumber;
        $loginMode = 'reset';
        if ($indexNumber === '') {
            $error = 'Enter your index number to reset.';
        } else {
            $stmt = $db->prepare('SELECT id FROM users WHERE index_number = ?');
            $stmt->execute([$indexNumber]);
            $user = $stmt->fetch();
            if (!$user) {
                $error = 'No account found for that index.';
            } else {
                $plainPassword = trytest_generate_student_password();
                $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
                try {
                    $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                        ->execute([$passwordHash, (int) $user['id']]);
                    $message = 'New 4-digit Trytest password:';
                    $generatedPassword = $plainPassword;
                    $loginMode = 'index';
                } catch (Throwable $e) {
                    $error = 'Could not reset password now. Please try again shortly.';
                }
            }
        }
    }

    if ($action === 'logout_user') {
        trytest_youtube_clear_session_verified();
        unset(
            $_SESSION['user_id'],
            $_SESSION['user_index_number'],
            $_SESSION['user_level'],
            $_SESSION['user_department'],
            $_SESSION['trytest_pdf_gate_ok_at'],
            $_SESSION['pending_shared_quiz_id'],
            $_SESSION['trytest_dash_feat_kind']
        );
        trytest_redirect(trytest_home_with_query(['out' => '1']));
    }

    if ($action === 'reset_student_quiz' && !empty($_SESSION['user_id'])) {
        $resetQuizId = (int) ($_POST['quiz_id'] ?? 0);
        $uidReset = (int) $_SESSION['user_id'];
        $lvlReset = trim((string) ($_SESSION['user_level'] ?? ''));
        $depReset = trim((string) ($_SESSION['user_department'] ?? ''));
        if ($resetQuizId > 0 && trytest_student_can_access_quiz($db, $resetQuizId, $lvlReset, $depReset)) {
            trytest_student_wipe_quiz_results($db, $uidReset, $resetQuizId);
            trytest_redirect(trytest_url('quiz?quiz_id=' . $resetQuizId));
        }
        trytest_redirect(trytest_url('dashboard?tab=results'));
    }
}

$isUserLoggedIn = !empty($_SESSION['user_id']);
$userLevel = (string) ($_SESSION['user_level'] ?? '');
$userId = $isUserLoggedIn ? (int) $_SESSION['user_id'] : 0;

if ($isUserLoggedIn) {
    $sync = $db->prepare('SELECT index_number, level, department FROM users WHERE id = ?');
    $sync->execute([$userId]);
    $syncRow = $sync->fetch();
    if ($syncRow) {
        $_SESSION['user_index_number'] = (string) $syncRow['index_number'];
        $_SESSION['user_level'] = (string) $syncRow['level'];
        $_SESSION['user_department'] = trim((string) ($syncRow['department'] ?? ''));
        $userLevel = (string) $syncRow['level'];
    }
}

$userDepartment = $isUserLoggedIn ? trim((string) ($_SESSION['user_department'] ?? '')) : '';

if ($isUserLoggedIn) {
    $pq = (int) ($_SESSION['pending_shared_quiz_id'] ?? 0);
    if ($pq > 0) {
        if (trytest_student_can_access_quiz($db, $pq, $userLevel, $userDepartment)) {
            unset($_SESSION['pending_shared_quiz_id']);
            trytest_redirect(trytest_url('quiz?quiz_id=' . $pq));
        }
        unset($_SESSION['pending_shared_quiz_id']);
    }
}

$coursesWithQuizzes = [];
$totalPoints = 0;
$levelLeaderboardRows = [];
$doneBlock = null;
$doneComparison = null;
$dashboardEncouragement = null;
$newQuizBadgeCount = 0;
$quizResultsRows = [];
$activeTab = isset($_GET['tab']) ? strtolower(trim((string) $_GET['tab'])) : 'home';
if ($activeTab === 'profile') {
    $activeTab = 'home';
}
if (!in_array($activeTab, ['home', 'rank', 'results'], true)) {
    $activeTab = 'home';
}

if ($isUserLoggedIn) {
    $ptsStmt = $db->prepare('SELECT COALESCE(SUM(score), 0) FROM scores WHERE user_id = ?');
    $ptsStmt->execute([$userId]);
    $totalPoints = (int) $ptsStmt->fetchColumn();

    $coursesWithQuizzes = trytest_student_load_courses_with_quizzes($db, $userId, $userLevel, $userDepartment);

    $feedSeenRow = $db->prepare('SELECT created_at, quizzes_feed_last_seen_at FROM users WHERE id = ?');
    $feedSeenRow->execute([$userId]);
    $feedSeen = $feedSeenRow->fetch(PDO::FETCH_ASSOC) ?: [];
    $newQuizBadgeCount = trytest_student_new_quizzes_badge_count(
        $coursesWithQuizzes,
        trim((string) ($feedSeen['quizzes_feed_last_seen_at'] ?? '')),
        trim((string) ($feedSeen['created_at'] ?? ''))
    );

    $dashboardEncouragement = trytest_student_dashboard_encouragement(
        $coursesWithQuizzes,
        $userId,
        trytest_student_display_name((string) ($_SESSION['user_index_number'] ?? ''))
    );

    $levelLeaderboardRows = trytest_level_leaderboard($db, $userLevel, $userDepartment, 40);

    $doneQuizId = isset($_GET['done']) ? (int) $_GET['done'] : 0;
    if ($doneQuizId > 0) {
        $lastStmt = $db->prepare(
            'SELECT score, total FROM scores WHERE quiz_id = ? AND user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $lastStmt->execute([$doneQuizId, $userId]);
        $lastScore = $lastStmt->fetch();
        if ($lastScore) {
            $tq = $db->prepare('SELECT title, quiz_starts_at, quiz_ends_at FROM quizzes WHERE id = ?');
            $tq->execute([$doneQuizId]);
            $quizMeta = $tq->fetch(PDO::FETCH_ASSOC) ?: [];
            $quizTitleDone = (string) ($quizMeta['title'] ?? 'Quiz');
            $stDone = isset($quizMeta['quiz_starts_at']) ? trim((string) $quizMeta['quiz_starts_at']) : '';
            $enDone = isset($quizMeta['quiz_ends_at']) ? trim((string) $quizMeta['quiz_ends_at']) : '';
            $retryPhase = trytest_quiz_schedule_phase(
                $stDone !== '' ? $stDone : null,
                $enDone !== '' ? $enDone : null
            );
            $canRetryQuiz = ($retryPhase === 'open' || $retryPhase === 'unset');
            $boardRows = trytest_quiz_leaderboard($db, $doneQuizId, 40, $userLevel, $userDepartment);
            $userRank = null;
            $rn = 1;
            foreach ($boardRows as $br) {
                if ((int) ($br['user_id'] ?? 0) === $userId) {
                    $userRank = $rn;
                    break;
                }
                $rn++;
            }
            $doneBlock = [
                'quiz_id' => $doneQuizId,
                'title' => $quizTitleDone,
                'score' => (int) $lastScore['score'],
                'total' => (int) $lastScore['total'],
                'rank' => $userRank,
                'board' => $boardRows,
                'can_retry' => $canRetryQuiz,
            ];
            $lastTwoAttempts = $db->prepare(
                'SELECT score, total, created_at
                 FROM score_attempts
                 WHERE quiz_id = ? AND user_id = ?
                 ORDER BY id DESC
                 LIMIT 2'
            );
            $lastTwoAttempts->execute([$doneQuizId, $userId]);
            $attemptRows = $lastTwoAttempts->fetchAll(PDO::FETCH_ASSOC);
            if (count($attemptRows) >= 2) {
                $latest = (int) ($attemptRows[0]['score'] ?? 0);
                $prev = (int) ($attemptRows[1]['score'] ?? 0);
                $delta = $latest - $prev;
                $doneComparison = [
                    'latest' => $latest,
                    'previous' => $prev,
                    'delta' => $delta,
                    'trend' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'same'),
                ];
            }
            $activeTab = 'home';
        }
    }

    $downloadsBadgeCount = trytest_student_downloads_pending_count($db, $userId, $userDepartment, $userLevel);

    $quizResultsRows = trytest_student_quiz_results_rows($db, $userId, $userLevel, $userDepartment);
}

$downloadsBadgeCount = $downloadsBadgeCount ?? 0;
$newQuizBadgeCount = (int) ($newQuizBadgeCount ?? 0);

$ytSettings = trytest_youtube_settings();
$quizDoneYoutubeHtml = '';
if (is_array($doneBlock) && !empty($doneBlock['quiz_id'])) {
    $quizDoneYoutubeHtml = trytest_youtube_quiz_complete_subscribe_html($ytSettings);
}
$dashboardUrl = $isUserLoggedIn ? trytest_url('dashboard') : trytest_home_url();
$quizUrlBase = trytest_url('quiz');
$downloadsPageUrl = trytest_url('downloads');
$quizzesPageUrl = trytest_url('quizzes');
$quizSchedulesPollUrl = trytest_url('api_quiz_schedules.php');
$pendingShareQuizId = (int) ($_SESSION['pending_shared_quiz_id'] ?? 0);
$loginHeroImageUrl = 'https://thumbs.dreamstime.com/b/cartoon-illustration-girl-studying-online-using-laptop-headphones-comfortable-home-environment-girl-studying-376165006.jpg';
$studentPasswordOnlyView = !$isUserLoggedIn
    && $generatedPassword !== ''
    && $loginMode === 'index'
    && $error === '';

$needsDepartmentSetupForLayout = $isUserLoggedIn && trim($userDepartment) === '' && $departmentOptions !== [];
$studentDashboardFixedViewport = $isUserLoggedIn
    && $activeTab === 'home'
    && (!is_array($doneBlock) || empty($doneBlock['quiz_id']))
    && !$needsDepartmentSetupForLayout;
$studentLoginLockedViewport = !$isUserLoggedIn;
$htmlViewportLockClass = !empty($studentDashboardFixedViewport)
    ? 'h-svh max-h-svh overflow-hidden'
    : ($studentLoginLockedViewport ? 'h-full max-h-full overflow-hidden' : '');
$htmlRootClasses = trim(implode(' ', array_filter([trytest_student_zoom_lock_html_class(), $htmlViewportLockClass])));
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo htmlspecialchars($htmlRootClasses, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <?php trytest_link_preview_meta(['title' => 'Trytest', 'description' => 'Student sign-in, quizzes, and downloads.']); ?>
    <meta name="viewport" content="<?php echo htmlspecialchars(trytest_student_locked_viewport_content(), ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($isUserLoggedIn) {
        trytest_student_theme_head_early();
    } ?>
    <title>Trytest</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(trytest_url('favicon.svg'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <script src="https://cdn.tailwindcss.com"></script>
    <?php if ($isUserLoggedIn) {
        trytest_student_theme_tailwind_config_script();
    } ?>
    <style>
        body { font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif; }
        <?php if (!empty($studentDashboardFixedViewport) || $studentLoginLockedViewport): ?>
        html, body {
            height: 100%;
            max-height: 100%;
            overflow: hidden;
            overscroll-behavior: none;
        }
        <?php endif; ?>
        <?php if ($studentLoginLockedViewport): ?>
        html { height: 100vh; }
        body {
            height: 100vh;
            min-height: 100vh;
            max-height: 100vh;
        }
        <?php endif; ?>
        <?php if ($isUserLoggedIn): ?>
        html.dark { color-scheme: dark; }
        <?php endif; ?>
    </style>
    <?php trytest_student_zoom_lock_styles(); ?>
    <?php trytest_student_zoom_lock_gesture_script(); ?>
</head>
<body class="<?php
if ($isUserLoggedIn) {
    echo !empty($studentDashboardFixedViewport)
        ? 'touch-manipulation h-svh max-h-svh max-w-[100vw] overflow-hidden bg-zinc-50 text-slate-900 antialiased dark:bg-zinc-950 dark:text-zinc-100'
        : 'touch-manipulation min-h-screen max-w-[100vw] overflow-x-hidden bg-zinc-50 text-slate-900 antialiased dark:bg-zinc-950 dark:text-zinc-100';
} else {
    echo 'touch-manipulation flex h-screen max-h-screen min-h-0 w-full max-w-[100vw] flex-col items-center justify-center overflow-hidden bg-slate-50 px-4 py-4 text-slate-900 antialiased md:px-6 md:py-6';
}
?>">
<?php if ($isUserLoggedIn):
    $userIndex = (string) ($_SESSION['user_index_number'] ?? '');
    $userDisplayName = trytest_student_display_name($userIndex);
    $downloadsBadgeCount = (int) ($downloadsBadgeCount ?? 0);
    $newQuizBadgeCount = (int) ($newQuizBadgeCount ?? 0);
    $quizzesPageUrl = (string) ($quizzesPageUrl ?? '');
    $needsDepartmentSetup = $userDepartment === '' && $departmentOptions !== [];
    $departmentUpdateError = (string) ($departmentUpdateError ?? '');
    $quizDoneYoutubeHtml = (string) ($quizDoneYoutubeHtml ?? '');
    $doneComparison = is_array($doneComparison) ? $doneComparison : null;
    $showHomeFeatured = $activeTab === 'home' && (!is_array($doneBlock) || empty($doneBlock['quiz_id']));
    $dashboardFeaturedHtml = trytest_student_dashboard_featured_html(
        $ytSettings,
        !empty($studentDashboardFixedViewport),
        $showHomeFeatured
    );
    require __DIR__ . '/templates/student_gamified_shell.php';
else: ?>
    <?php if (!empty($studentPasswordOnlyView)): ?>
    <div class="flex w-full max-w-md min-w-0 flex-col items-center">
        <div class="w-full overflow-hidden rounded-xl border border-slate-200 bg-white px-6 py-14 text-center sm:px-10 sm:py-20">
            <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Password</p>
            <p class="mt-10 font-mono text-5xl font-extrabold tabular-nums tracking-[0.2em] text-slate-900 sm:mt-12 sm:text-6xl"><?php echo htmlspecialchars($generatedPassword, ENT_QUOTES, 'UTF-8'); ?></p>
            <form method="post" class="mt-12 sm:mt-14">
                <input type="hidden" name="action" value="continue_after_password_display">
                <input type="hidden" name="pending_index" value="<?php echo htmlspecialchars($enteredIndex, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($pendingShareQuizId > 0): ?>
                    <input type="hidden" name="shared_quiz_id" value="<?php echo $pendingShareQuizId; ?>">
                <?php endif; ?>
                <button type="submit" class="w-full rounded-xl bg-indigo-600 py-3 text-sm font-semibold text-white hover:bg-indigo-700">Continue</button>
            </form>
        </div>
        <p class="mt-4 text-center text-[10px] font-light tracking-[0.12em] text-slate-400/70">Project of Manuel</p>
    </div>
    <?php else: ?>
    <div class="flex w-full max-w-md min-w-0 flex-col items-center">
        <div class="w-full overflow-hidden rounded-xl border border-slate-200 bg-white">
            <div class="relative h-36 w-full border-b border-slate-100 bg-white sm:h-40">
                <img
                    src="<?php echo htmlspecialchars($loginHeroImageUrl, ENT_QUOTES, 'UTF-8'); ?>"
                    alt=""
                    class="h-full w-full object-contain object-center p-3 sm:p-4"
                    width="400"
                    height="300"
                    loading="eager"
                    decoding="async"
                    referrerpolicy="no-referrer"
                />
            </div>
            <div class="p-5 sm:p-6">
                <h1 class="flex items-center justify-center gap-2 text-lg font-bold tracking-tight text-slate-900">
                    <i class="fa-solid fa-graduation-cap text-indigo-600" aria-hidden="true"></i>
                    Trytest
                </h1>
                <p class="mx-auto mt-1 max-w-sm text-center text-xs leading-relaxed text-slate-500">
                    Quizzes and scores for your class — enter your index to sign in.
                </p>

                <?php if ($pendingShareQuizId > 0): ?>
                    <p class="mt-3 flex items-center justify-center gap-2 text-center text-xs text-indigo-800">
                        <i class="fa-solid fa-link shrink-0" aria-hidden="true"></i>
                        <span>Quiz link — sign in to open.</span>
                    </p>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <p class="mt-3 flex items-start gap-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800 break-words">
                        <i class="fa-solid fa-circle-exclamation mt-0.5 shrink-0" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                    </p>
                <?php endif; ?>
                <?php if ($message !== '' && $generatedPassword === ''): ?>
                    <div class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-900 break-words">
                        <p class="flex items-start gap-2">
                            <i class="fa-solid fa-circle-check mt-0.5 shrink-0" aria-hidden="true"></i>
                            <span><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></span>
                        </p>
                    </div>
                <?php endif; ?>

                <?php if ($loginMode === 'reset'): ?>
                    <div class="mt-5 space-y-3">
                        <p class="flex items-center justify-center gap-2 text-center text-xs text-slate-600">
                            <i class="fa-solid fa-key text-indigo-500" aria-hidden="true"></i>
                            <span>New code by index</span>
                        </p>
                        <form method="post" class="space-y-3">
                            <input type="hidden" name="action" value="reset_password">
                            <?php if ($pendingShareQuizId > 0): ?>
                                <input type="hidden" name="shared_quiz_id" value="<?php echo $pendingShareQuizId; ?>">
                            <?php endif; ?>
                            <div class="relative">
                                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"><i class="fa-solid fa-hashtag"></i></span>
                                <input class="w-full min-w-0 rounded-lg border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm uppercase placeholder:text-slate-300 placeholder:opacity-80 focus:outline-none focus:ring-2 focus:ring-indigo-500" type="text" name="index_number" placeholder="BC/ITS/24/047" value="<?php echo htmlspecialchars($enteredIndex, ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <button class="flex w-full items-center justify-center gap-2 rounded-lg bg-indigo-600 py-2.5 text-sm font-medium text-white" type="submit">
                                <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
                                Reset
                            </button>
                        </form>
                        <p class="text-center text-sm">
                            <a class="inline-flex items-center justify-center gap-1 text-indigo-600 hover:underline" href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                                Back
                            </a>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="mt-5 space-y-3">
                        <?php if ($loginMode === 'index'): ?>
                            <form method="post" class="space-y-3" id="formCheckIndex">
                                <input type="hidden" name="action" value="check_index">
                                <?php if ($pendingShareQuizId > 0): ?>
                                    <input type="hidden" name="shared_quiz_id" value="<?php echo $pendingShareQuizId; ?>">
                                <?php endif; ?>
                                <div class="relative">
                                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"><i class="fa-solid fa-id-card"></i></span>
                                    <input class="w-full min-w-0 rounded-lg border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm uppercase placeholder:text-slate-300 placeholder:opacity-80 focus:outline-none focus:ring-2 focus:ring-indigo-500" type="text" name="index_number" placeholder="BC/ITS/24/047" value="<?php echo htmlspecialchars($enteredIndex, ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                                <button class="flex w-full items-center justify-center gap-2 rounded-lg bg-indigo-600 py-2.5 text-sm font-medium text-white" type="submit">
                                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                    Continue
                                </button>
                            </form>
                            <p class="text-center text-sm">
                                <a class="inline-flex items-center justify-center gap-1 text-indigo-600 hover:underline" href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'); ?>?view=reset">
                                    <i class="fa-solid fa-unlock-keyhole text-xs" aria-hidden="true"></i>
                                    Forgot password
                                </a>
                            </p>
                        <?php elseif ($loginMode === 'existing'): ?>
                            <p class="flex items-center justify-center gap-2 text-center text-xs text-slate-600">
                                <i class="fa-solid fa-id-card text-slate-400" aria-hidden="true"></i>
                                <span class="font-medium"><?php echo htmlspecialchars($enteredIndex, ENT_QUOTES, 'UTF-8'); ?></span><?php if ($existingUserLevel !== ''): ?><span class="text-slate-300">·</span><span>Lv&nbsp;<?php echo htmlspecialchars($existingUserLevel, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                            </p>
                            <form method="post" class="space-y-3" id="formLoginExisting">
                                <input type="hidden" name="action" value="login_existing">
                                <?php if ($pendingShareQuizId > 0): ?>
                                    <input type="hidden" name="shared_quiz_id" value="<?php echo $pendingShareQuizId; ?>">
                                <?php endif; ?>
                                <input type="hidden" name="index_number" value="<?php echo htmlspecialchars($enteredIndex, ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="relative">
                                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"><i class="fa-solid fa-lock"></i></span>
                                    <input id="studentPassword" class="w-full min-w-0 rounded-lg border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-center font-mono text-lg tracking-widest focus:outline-none focus:ring-2 focus:ring-indigo-500" type="password" name="password" inputmode="numeric" pattern="[0-9]*" autocomplete="current-password" maxlength="4" placeholder="••••" required title="4-digit Trytest password">
                                </div>
                                <label class="flex cursor-pointer items-center justify-center gap-2 text-xs text-slate-600">
                                    <input type="checkbox" name="remember_me" value="1" id="rememberMe" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                    <i class="fa-regular fa-bookmark text-slate-400" aria-hidden="true"></i>
                                    <span>Remember device</span>
                                </label>
                                <button class="flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 py-2.5 text-sm font-medium text-white" type="submit">
                                    <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i>
                                    Sign in
                                </button>
                            </form>
                            <p class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-center text-xs text-slate-600">
                                <a class="inline-flex items-center gap-1 text-indigo-600 hover:underline" href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fa-solid fa-rotate-left text-[10px]" aria-hidden="true"></i>
                                    Other index
                                </a>
                                <a class="inline-flex items-center gap-1 text-indigo-600 hover:underline" href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'); ?>?view=reset">
                                    <i class="fa-solid fa-unlock-keyhole text-[10px]" aria-hidden="true"></i>
                                    Forgot
                                </a>
                            </p>
                        <?php else: ?>
                            <p class="flex items-center justify-center gap-2 text-xs text-slate-600">
                                <i class="fa-solid fa-user-plus text-indigo-500" aria-hidden="true"></i>
                                <span class="font-medium"><?php echo htmlspecialchars($enteredIndex, ENT_QUOTES, 'UTF-8'); ?></span>
                            </p>
                            <form method="post" class="space-y-2">
                                <input type="hidden" name="action" value="register_new">
                                <?php if ($pendingShareQuizId > 0): ?>
                                    <input type="hidden" name="shared_quiz_id" value="<?php echo $pendingShareQuizId; ?>">
                                <?php endif; ?>
                                <input type="hidden" name="index_number" value="<?php echo htmlspecialchars($enteredIndex, ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="relative">
                                    <span class="pointer-events-none absolute left-3 top-1/2 z-10 -translate-y-1/2 text-slate-400" aria-hidden="true"><i class="fa-solid fa-layer-group"></i></span>
                                    <select class="w-full min-w-0 appearance-none rounded-lg border border-slate-300 bg-white py-2.5 pl-10 pr-8 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" name="level" required>
                                        <option value="">Level</option>
                                        <option value="100">100</option>
                                        <option value="200">200</option>
                                        <option value="300">300</option>
                                        <option value="400">400</option>
                                    </select>
                                    <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"><i class="fa-solid fa-chevron-down text-xs"></i></span>
                                </div>
                                <div class="relative">
                                    <span class="pointer-events-none absolute left-3 top-1/2 z-10 -translate-y-1/2 text-slate-400" aria-hidden="true"><i class="fa-solid fa-building"></i></span>
                                    <select class="w-full min-w-0 appearance-none rounded-lg border border-slate-300 bg-white py-2.5 pl-10 pr-8 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" name="department" <?php echo $departmentOptions ? 'required' : ''; ?>>
                                        <option value=""><?php echo $departmentOptions ? 'Program' : 'Program (optional)'; ?></option>
                                        <?php foreach ($departmentOptions as $depOpt): ?>
                                            <option value="<?php echo htmlspecialchars((string) ($depOpt['value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) ($depOpt['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"><i class="fa-solid fa-chevron-down text-xs"></i></span>
                                </div>
                                <button class="mt-1 flex w-full items-center justify-center gap-2 rounded-lg bg-indigo-600 py-2.5 text-sm font-medium text-white" type="submit">
                                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                                    Create
                                </button>
                            </form>
                            <p class="text-center text-sm">
                                <a class="inline-flex items-center justify-center gap-1 text-indigo-600 hover:underline" href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                                    Other index
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <p class="mt-4 text-center text-[10px] font-light tracking-[0.12em] text-slate-400/70">Project of Manuel</p>
    </div>
    <?php endif; ?>
    <script>
        (function () {
            try {
                if (new URLSearchParams(window.location.search).get('out') === '1') {
                    localStorage.removeItem('trytest_student_login');
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState({}, '', '<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'); ?>');
                    }
                }
            } catch (e) {}
            var KEY = 'trytest_student_login';
            try {
                var raw = localStorage.getItem(KEY);
                if (!raw) return;
                var data = JSON.parse(raw);
                if (!data || !data.i) return;
                var idxInput = document.querySelector('input[name="index_number"]');
                if (idxInput && !idxInput.value) idxInput.value = data.i;
                var pwInput = document.getElementById('studentPassword');
                if (pwInput && data.p) pwInput.value = data.p;
                var remember = document.getElementById('rememberMe');
                if (remember) remember.checked = true;
            } catch (e) {}
        })();
        document.getElementById('formLoginExisting')?.addEventListener('submit', function () {
            var remember = document.getElementById('rememberMe');
            var idx = document.querySelector('#formLoginExisting input[name="index_number"]')?.value;
            var pw = document.getElementById('studentPassword')?.value;
            if (remember && remember.checked && idx && pw) {
                try {
                    localStorage.setItem('trytest_student_login', JSON.stringify({ i: idx, p: pw }));
                } catch (e) {}
            } else {
                try { localStorage.removeItem('trytest_student_login'); } catch (e) {}
            }
        });
        document.getElementById('formCheckIndex')?.addEventListener('submit', function () {
            try { localStorage.removeItem('trytest_student_login'); } catch (e) {}
        });
    </script>
<?php endif; ?>
</body>
</html>
