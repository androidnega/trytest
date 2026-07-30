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
$levelOptions = trytest_level_dropdown_options($db);

$incomingShareQuiz = isset($_GET['quiz']) ? (int) $_GET['quiz'] : 0;
if ($incomingShareQuiz < 1 && isset($_GET['quiz_id'])) {
    $incomingShareQuiz = (int) $_GET['quiz_id'];
}
if ($incomingShareQuiz < 1) {
    $sIncoming = trytest_quiz_normalize_share_code((string) ($_GET['s'] ?? ''));
    if ($sIncoming !== '') {
        $incomingShareQuiz = trytest_quiz_id_from_share_code($db, $sIncoming);
    }
}
$incomingShareQuizTitle = '';
if ($incomingShareQuiz > 0) {
    $qc = $db->prepare('SELECT id, title FROM quizzes WHERE id = ?');
    $qc->execute([$incomingShareQuiz]);
    $shareQuizRow = $qc->fetch(PDO::FETCH_ASSOC);
    if ($shareQuizRow) {
        $_SESSION['pending_shared_quiz_id'] = $incomingShareQuiz;
        $incomingShareQuizTitle = trim((string) ($shareQuizRow['title'] ?? ''));
        if ($incomingShareQuizTitle === '') {
            $incomingShareQuizTitle = 'Quiz';
        }
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
        $levelRaw = trim((string) ($_POST['level'] ?? ''));
        if ($departmentOptions === []) {
            trytest_redirect(trytest_url('dashboard'));
        }
        $resolvedDept = trytest_resolve_department_for_save($deptRaw, $departmentOptions);
        if ($resolvedDept === null) {
            $departmentUpdateError = 'Choose your program from the list, then save.';
        } else {
            $resolvedLevel = null;
            if ($levelRaw !== '') {
                $resolvedLevel = trytest_resolve_level_for_save($levelRaw, $levelOptions);
                if ($resolvedLevel === null) {
                    $departmentUpdateError = 'Choose a valid level from the list.';
                }
            }
            if ($departmentUpdateError === '') {
                try {
                    if ($resolvedLevel !== null) {
                        $db->prepare('UPDATE users SET department = ?, level = ? WHERE id = ?')
                            ->execute([$resolvedDept, $resolvedLevel, $uid]);
                        $_SESSION['user_level'] = $resolvedLevel;
                    } else {
                        $db->prepare('UPDATE users SET department = ? WHERE id = ?')->execute([$resolvedDept, $uid]);
                    }
                    $_SESSION['user_department'] = $resolvedDept;
                    trytest_redirect(trytest_url('dashboard'));
                } catch (Throwable $e) {
                    $departmentUpdateError = 'Could not save your program now. Please try again shortly.';
                }
            }
        }
    }

    if ($action === 'update_student_level' && !empty($_SESSION['user_id'])) {
        $uid = (int) $_SESSION['user_id'];
        $levelRaw = trim((string) ($_POST['level'] ?? ''));
        $resolvedLevel = trytest_resolve_level_for_save($levelRaw, $levelOptions);
        if ($resolvedLevel === null) {
            $departmentUpdateError = 'Choose a valid level from the list.';
        } else {
            try {
                $db->prepare('UPDATE users SET level = ? WHERE id = ?')->execute([$resolvedLevel, $uid]);
                $_SESSION['user_level'] = $resolvedLevel;
                trytest_redirect(trytest_url('dashboard'));
            } catch (Throwable $e) {
                $departmentUpdateError = 'Could not save your level now. Please try again shortly.';
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
        $stmt = $db->prepare('SELECT id, level, password_hash, department, TRIM(COALESCE(nickname, \'\')) AS nickname FROM users WHERE index_number = ?');
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
            $_SESSION['user_nickname'] = trim((string) ($user['nickname'] ?? ''));
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
        $levelRaw = trim((string) ($_POST['level'] ?? ''));
        $department = trim((string) ($_POST['department'] ?? ''));
        $nicknameRaw = (string) ($_POST['nickname'] ?? '');
        $enteredIndex = $indexNumber;
        $loginMode = 'new';
        $nicknameNorm = trytest_student_normalize_nickname($nicknameRaw);
        $resolvedLevel = trytest_resolve_level_for_save($levelRaw, $levelOptions);
        if ($indexNumber === '' || $resolvedLevel === null) {
            $error = 'Index number and a level from the list are required.';
        } elseif ($nicknameNorm === null) {
            $error = 'Choose a nickname (2–40 characters: letters, numbers, spaces, dot, underscore, or hyphen).';
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
                        $db->prepare('INSERT INTO users (index_number, level, password_hash, department, nickname) VALUES (?, ?, ?, ?, ?)')
                            ->execute([$indexNumber, $resolvedLevel, $passwordHash, $departmentToSave, $nicknameNorm]);
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
            $_SESSION['user_nickname'],
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
        if ($resetQuizId > 0 && trytest_student_may_reset_quiz_attempt($db, $uidReset, $resetQuizId, $lvlReset, $depReset)) {
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
    $sync = $db->prepare('SELECT index_number, level, department, TRIM(COALESCE(nickname, \'\')) AS nickname FROM users WHERE id = ?');
    $sync->execute([$userId]);
    $syncRow = $sync->fetch();
    if ($syncRow) {
        $_SESSION['user_index_number'] = (string) $syncRow['index_number'];
        $_SESSION['user_level'] = (string) $syncRow['level'];
        $syncedDept = trim((string) ($syncRow['department'] ?? ''));
        // Heal case / spelling to the live preset label when it still matches.
        if ($syncedDept !== '' && $departmentOptions !== []) {
            $canonDept = trytest_resolve_department_for_save($syncedDept, $departmentOptions);
            if ($canonDept !== null && $canonDept !== $syncedDept) {
                try {
                    $db->prepare('UPDATE users SET department = ? WHERE id = ?')->execute([$canonDept, $userId]);
                    $syncedDept = $canonDept;
                } catch (Throwable $e) {
                    // keep synced value
                }
            }
        }
        $_SESSION['user_department'] = $syncedDept;
        $_SESSION['user_nickname'] = trim((string) ($syncRow['nickname'] ?? ''));
        $userLevel = (string) $syncRow['level'];
    }
}

$userDepartment = $isUserLoggedIn ? trim((string) ($_SESSION['user_department'] ?? '')) : '';
$userNickname = $isUserLoggedIn ? trim((string) ($_SESSION['user_nickname'] ?? '')) : '';

if ($isUserLoggedIn && $userNickname === '') {
    trytest_redirect(trytest_url('nickname'));
}

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
        trytest_student_display_name((string) ($_SESSION['user_nickname'] ?? ''), (string) ($_SESSION['user_index_number'] ?? ''))
    );

    $levelLeaderboardRows = trytest_level_leaderboard($db, $userLevel, $userDepartment);

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
$loginHeroWebp = trytest_url('assets/login-hero.webp');
$loginHeroWebpSm = trytest_url('assets/login-hero-480.webp');
$loginCssUrl = trytest_url('assets/login.css');
$studentPasswordOnlyView = !$isUserLoggedIn
    && $generatedPassword !== ''
    && $loginMode === 'index'
    && $error === '';

$needsDepartmentSetupForLayout = $isUserLoggedIn
    && $departmentOptions !== []
    && (
        trytest_student_department_needs_refresh($userDepartment, $departmentOptions)
        || (
            $isUserLoggedIn
            && trytest_student_should_offer_department_change(
                $userDepartment,
                $departmentOptions,
                $coursesWithQuizzes ?? []
            )
        )
    );
$studentDashboardFixedViewport = $isUserLoggedIn
    && $activeTab === 'home'
    && (!is_array($doneBlock) || empty($doneBlock['quiz_id']))
    && !$needsDepartmentSetupForLayout;
$studentLoginLockedViewport = !$isUserLoggedIn;
$htmlViewportLockClass = !empty($studentDashboardFixedViewport)
    ? 'h-svh max-h-svh overflow-hidden'
    : ($studentLoginLockedViewport ? 'tt-login h-full max-h-full overflow-hidden' : '');
$htmlRootClasses = trim(implode(' ', array_filter([trytest_student_zoom_lock_html_class(), $htmlViewportLockClass])));
$hLogin = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo htmlspecialchars($htmlRootClasses, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <?php
    $docTitle = 'Trytest';
    $previewMeta = [
        'title' => 'Trytest',
        'description' => 'Student sign-in, quizzes, and downloads.',
    ];
    if ($incomingShareQuizTitle !== '') {
        $docTitle = $incomingShareQuizTitle . ' · Trytest';
        $previewMeta = [
            'title' => $incomingShareQuizTitle,
            'description' => 'Sign in with your index number on Trytest to take this quiz.',
            'omit_path' => true,
        ];
    }
    trytest_link_preview_meta($previewMeta);
    ?>
    <meta name="viewport" content="<?php echo htmlspecialchars(trytest_student_locked_viewport_content(), ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($isUserLoggedIn) {
        trytest_student_theme_head_early();
    } ?>
    <title><?php echo htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(trytest_url('favicon.svg'), ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($isUserLoggedIn): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <script src="https://cdn.tailwindcss.com"></script>
    <?php trytest_student_theme_tailwind_config_script(); ?>
    <style>
        body { font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif; }
        <?php if (!empty($studentDashboardFixedViewport)): ?>
        html, body {
            height: 100%;
            max-height: 100%;
            overflow: hidden;
            overscroll-behavior: none;
        }
        <?php endif; ?>
        html.dark { color-scheme: dark; }
    </style>
    <?php else: ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="preload" as="image" href="<?php echo $hLogin($loginHeroWebpSm); ?>" type="image/webp" imagesrcset="<?php echo $hLogin($loginHeroWebpSm); ?> 480w, <?php echo $hLogin($loginHeroWebp); ?> 900w" imagesizes="(max-width: 480px) 100vw, 420px">
    <link rel="stylesheet" href="<?php echo $hLogin($loginCssUrl); ?>">
    <?php endif; ?>
    <?php trytest_student_zoom_lock_styles(); ?>
    <?php trytest_student_zoom_lock_gesture_script(); ?>
</head>
<body class="<?php
if ($isUserLoggedIn) {
    echo !empty($studentDashboardFixedViewport)
        ? 'touch-manipulation h-svh max-h-svh max-w-[100vw] overflow-hidden bg-zinc-50 text-slate-900 antialiased dark:bg-[#0f1014] dark:text-zinc-100'
        : 'touch-manipulation min-h-screen max-w-[100vw] overflow-x-hidden bg-zinc-50 text-slate-900 antialiased dark:bg-[#0f1014] dark:text-zinc-100';
} else {
    echo 'tt-login-body';
}
?>">
<?php if ($isUserLoggedIn):
    $userIndex = (string) ($_SESSION['user_index_number'] ?? '');
    $userDisplayName = trytest_student_display_name((string) ($_SESSION['user_nickname'] ?? ''), $userIndex);
    $downloadsBadgeCount = (int) ($downloadsBadgeCount ?? 0);
    $newQuizBadgeCount = (int) ($newQuizBadgeCount ?? 0);
    $quizzesPageUrl = (string) ($quizzesPageUrl ?? '');
    $needsDepartmentSetup = $departmentOptions !== []
        && trytest_student_should_offer_department_change($userDepartment, $departmentOptions, $coursesWithQuizzes);
    $departmentSetupRequired = trytest_student_department_needs_refresh($userDepartment, $departmentOptions);
    $departmentUpdateError = (string) ($departmentUpdateError ?? '');
    $levelOptions = $levelOptions ?? [];
    $quizDoneYoutubeHtml = (string) ($quizDoneYoutubeHtml ?? '');
    $doneComparison = is_array($doneComparison) ? $doneComparison : null;
    $showHomeFeatured = $activeTab === 'home' && (!is_array($doneBlock) || empty($doneBlock['quiz_id']));
    require_once __DIR__ . '/includes/student_dashboard_nudges.php';
    $dashboardNudgesHtml = '';
    $dashboardFeaturedHtml = '';
    $dashboardFeaturedKind = 'words';
    if ($showHomeFeatured) {
        $rawNudgeHtml = trytest_student_dashboard_nudges_html(
            trytest_student_dashboard_nudges_collect($db, $userId, $ytSettings, $downloadsPageUrl),
            !empty($studentDashboardFixedViewport)
        );
        $featPayload = trytest_student_dashboard_featured_payload(
            $ytSettings,
            !empty($studentDashboardFixedViewport),
            $showHomeFeatured
        );
        $dashboardFeaturedHtml = (string) ($featPayload['html'] ?? '');
        $dashboardFeaturedKind = (string) ($featPayload['kind'] ?? 'words');
        $pickedNc = trytest_student_dashboard_nudge_or_cheer_slot($rawNudgeHtml, $dashboardEncouragement);
        $dashboardNudgesHtml = $pickedNc['nudge'];
        $dashboardEncouragement = $pickedNc['encouragement'];
    }
    /** POST targets a real .php file so requests are not rewritten via /dashboard/ (directory POST → GET). */
    $studentPortalPostUrl = trytest_url('student_portal.php');
    $studentFeedbackApiUrl = trytest_url('api_student_feedback');
    $studentFeedbackAlreadySubmitted = false;
    if ($userId > 0) {
        try {
            $sfChk = $db->prepare('SELECT 1 FROM student_system_feedback WHERE user_id = ? LIMIT 1');
            $sfChk->execute([$userId]);
            $studentFeedbackAlreadySubmitted = (bool) $sfChk->fetchColumn();
        } catch (Throwable $e) {
            $studentFeedbackAlreadySubmitted = false;
        }
    }
    require __DIR__ . '/templates/student_gamified_shell.php';
else: ?>
    <?php
    $capSvg = '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 9.5 12 5l9 4.5-9 4.5L3 9.5Z" fill="#1d4ed8"/><path d="M7 12.2v3.3c0 1.4 2.2 2.7 5 2.7s5-1.3 5-2.7v-3.3" stroke="#1d4ed8" stroke-width="1.6" stroke-linecap="round"/><path d="M21 10v5.5" stroke="#1d4ed8" stroke-width="1.6" stroke-linecap="round"/></svg>';
    $iconId = '<svg class="tt-login-field__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3.5" y="5.5" width="17" height="13" rx="2.2" stroke="currentColor" stroke-width="1.7"/><path d="M7 10.5h4M7 13.5h6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';
    $iconLock = '<svg class="tt-login-field__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="5" y="10.5" width="14" height="9" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M8.5 10.5V8.2a3.5 3.5 0 0 1 7 0v2.3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';
    $iconHash = '<svg class="tt-login-field__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 4.5 7.5 19.5M16.5 4.5 15 19.5M4.5 9.5h15M4 14.5h15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';
    $iconLayer = '<svg class="tt-login-field__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m4 9 8-4 8 4-8 4-8-4Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="m4 13 8 4 8-4M4 17l8 4 8-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    $iconBuilding = '<svg class="tt-login-field__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4.5 20.5h15M7 20.5V6.5a1.5 1.5 0 0 1 1.5-1.5h7A1.5 1.5 0 0 1 17 6.5v14" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M10 9h.01M14 9h.01M10 12.5h.01M14 12.5h.01M10 16h.01M14 16h.01" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>';
    $iconNick = '<svg class="tt-login-field__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 19c1.8-3.2 4-4.8 7-4.8S17.2 15.8 19 19" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><circle cx="12" cy="8.5" r="3.5" stroke="currentColor" stroke-width="1.7"/></svg>';
    $heroBlock = '<div class="tt-login-hero" aria-hidden="true">'
        . '<picture>'
        . '<source type="image/webp" srcset="' . $hLogin($loginHeroWebpSm) . ' 480w, ' . $hLogin($loginHeroWebp) . ' 900w" sizes="(max-width: 480px) 92vw, 400px">'
        . '<img src="' . $hLogin($loginHeroWebp) . '" alt="" width="900" height="599" decoding="async" fetchpriority="high">'
        . '</picture></div>';
    ?>
    <?php if (!empty($studentPasswordOnlyView)): ?>
    <div class="tt-login-passonly">
        <div class="tt-login-passonly__card">
            <p class="tt-login-passonly__label">Your password</p>
            <p class="tt-login-passonly__code"><?php echo $hLogin($generatedPassword); ?></p>
            <form method="post">
                <input type="hidden" name="action" value="continue_after_password_display">
                <input type="hidden" name="pending_index" value="<?php echo $hLogin($enteredIndex); ?>">
                <?php if ($pendingShareQuizId > 0): ?>
                    <input type="hidden" name="shared_quiz_id" value="<?php echo $pendingShareQuizId; ?>">
                <?php endif; ?>
                <button type="submit" class="tt-login-btn">Continue</button>
            </form>
        </div>
        <p class="tt-login-foot">Project of Manuel</p>
    </div>
    <?php else: ?>
    <div class="tt-login-shell">
        <header class="tt-login-brand">
            <div class="tt-login-brand__mark"><?php echo $capSvg; ?><span>Trytest</span></div>
            <p class="tt-login-brand__tag">Quizzes for your class — sign in with your index.</p>
        </header>
        <?php echo $heroBlock; ?>
        <section class="tt-login-panel" aria-label="Sign in">
            <?php if ($pendingShareQuizId > 0): ?>
                <p class="tt-login-panel__hint">Quiz link ready — sign in to open it.</p>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <p class="tt-login-alert tt-login-alert--error" role="alert"><?php echo $hLogin($error); ?></p>
            <?php endif; ?>
            <?php if ($message !== '' && $generatedPassword === ''): ?>
                <div class="tt-login-alert tt-login-alert--ok"><?php echo $hLogin($message); ?></div>
            <?php endif; ?>

            <?php if ($loginMode === 'reset'): ?>
                <p class="tt-login-who">Get a new 4-digit code</p>
                <form method="post" class="tt-login-form">
                    <input type="hidden" name="action" value="reset_password">
                    <?php if ($pendingShareQuizId > 0): ?>
                        <input type="hidden" name="shared_quiz_id" value="<?php echo $pendingShareQuizId; ?>">
                    <?php endif; ?>
                    <div class="tt-login-field">
                        <?php echo $iconHash; ?>
                        <input class="tt-login-input" type="text" name="index_number" placeholder="BC/ITS/24/047" value="<?php echo $hLogin($enteredIndex); ?>" required autocomplete="username" autocapitalize="characters" spellcheck="false">
                    </div>
                    <button class="tt-login-btn" type="submit">Reset password</button>
                </form>
                <p class="tt-login-meta"><a href="<?php echo $hLogin($dashboardUrl); ?>">Back to sign in</a></p>
            <?php else: ?>
                <?php if ($loginMode === 'index'): ?>
                    <form method="post" class="tt-login-form" id="formCheckIndex">
                        <input type="hidden" name="action" value="check_index">
                        <?php if ($pendingShareQuizId > 0): ?>
                            <input type="hidden" name="shared_quiz_id" value="<?php echo $pendingShareQuizId; ?>">
                        <?php endif; ?>
                        <div class="tt-login-field">
                            <?php echo $iconId; ?>
                            <input class="tt-login-input" type="text" name="index_number" placeholder="BC/ITS/24/047" value="<?php echo $hLogin($enteredIndex); ?>" required autocomplete="username" autocapitalize="characters" spellcheck="false">
                        </div>
                        <button class="tt-login-btn" type="submit">Continue</button>
                    </form>
                    <p class="tt-login-meta"><a href="<?php echo $hLogin($dashboardUrl); ?>?view=reset">Forgot password</a></p>
                <?php elseif ($loginMode === 'existing'): ?>
                    <p class="tt-login-who"><strong><?php echo $hLogin($enteredIndex); ?></strong><?php if ($existingUserLevel !== ''): ?> · Lv <?php echo $hLogin($existingUserLevel); ?><?php endif; ?></p>
                    <form method="post" class="tt-login-form" id="formLoginExisting">
                        <input type="hidden" name="action" value="login_existing">
                        <?php if ($pendingShareQuizId > 0): ?>
                            <input type="hidden" name="shared_quiz_id" value="<?php echo $pendingShareQuizId; ?>">
                        <?php endif; ?>
                        <input type="hidden" name="index_number" value="<?php echo $hLogin($enteredIndex); ?>">
                        <div class="tt-login-field">
                            <?php echo $iconLock; ?>
                            <input id="studentPassword" class="tt-login-input tt-login-input--pin" type="password" name="password" inputmode="numeric" pattern="[0-9]*" autocomplete="current-password" maxlength="4" placeholder="••••" required title="4-digit Trytest password">
                        </div>
                        <label class="tt-login-check">
                            <input type="checkbox" name="remember_me" value="1" id="rememberMe">
                            <span>Remember this device</span>
                        </label>
                        <button class="tt-login-btn tt-login-btn--ok" type="submit">Sign in</button>
                    </form>
                    <p class="tt-login-meta">
                        <a href="<?php echo $hLogin($dashboardUrl); ?>">Other index</a>
                        <a href="<?php echo $hLogin($dashboardUrl); ?>?view=reset">Forgot</a>
                    </p>
                <?php else: ?>
                    <p class="tt-login-who">New account · <strong><?php echo $hLogin($enteredIndex); ?></strong></p>
                    <form method="post" class="tt-login-form">
                        <input type="hidden" name="action" value="register_new">
                        <?php if ($pendingShareQuizId > 0): ?>
                            <input type="hidden" name="shared_quiz_id" value="<?php echo $pendingShareQuizId; ?>">
                        <?php endif; ?>
                        <input type="hidden" name="index_number" value="<?php echo $hLogin($enteredIndex); ?>">
                        <div class="tt-login-field">
                            <?php echo $iconLayer; ?>
                            <select class="tt-login-select" name="level" required>
                                <option value="">Level</option>
                                <?php foreach ($levelOptions as $lox): ?>
                                    <option value="<?php echo $hLogin((string) ($lox['value'] ?? '')); ?>"><?php echo $hLogin((string) ($lox['label'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="tt-login-field">
                            <?php echo $iconBuilding; ?>
                            <select class="tt-login-select" name="department" <?php echo $departmentOptions ? 'required' : ''; ?>>
                                <option value=""><?php echo $departmentOptions ? 'Program' : 'Program (optional)'; ?></option>
                                <?php foreach ($departmentOptions as $depOpt): ?>
                                    <option value="<?php echo $hLogin((string) ($depOpt['value'] ?? '')); ?>"><?php echo $hLogin((string) ($depOpt['label'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="tt-login-field">
                            <?php echo $iconNick; ?>
                            <input class="tt-login-input" type="text" name="nickname" maxlength="40" autocomplete="nickname" placeholder="Nickname (how others see you)" required>
                        </div>
                        <button class="tt-login-btn" type="submit">Create account</button>
                    </form>
                    <p class="tt-login-meta"><a href="<?php echo $hLogin($dashboardUrl); ?>">Other index</a></p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <p class="tt-login-foot">Project of Manuel</p>
    </div>
    <?php endif; ?>
    <script>
        (function () {
            try {
                if (new URLSearchParams(window.location.search).get('out') === '1') {
                    localStorage.removeItem('trytest_student_login');
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState({}, '', '<?php echo $hLogin($dashboardUrl); ?>');
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
