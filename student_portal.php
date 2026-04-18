<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/departments.php';
require __DIR__ . '/includes/student_helpers.php';
require __DIR__ . '/includes/youtube_subscribe.php';

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
            $_SESSION['pending_shared_quiz_id']
        );
        trytest_redirect(trytest_home_with_query(['out' => '1']));
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
$activeTab = isset($_GET['tab']) ? strtolower(trim((string) $_GET['tab'])) : 'home';
if ($activeTab === 'profile') {
    $activeTab = 'home';
}
if (!in_array($activeTab, ['home', 'rank'], true)) {
    $activeTab = 'home';
}

if ($isUserLoggedIn) {
    $ptsStmt = $db->prepare('SELECT COALESCE(SUM(score), 0) FROM scores WHERE user_id = ?');
    $ptsStmt->execute([$userId]);
    $totalPoints = (int) $ptsStmt->fetchColumn();

    $coursesWithQuizzes = trytest_student_load_courses_with_quizzes($db, $userId, $userLevel, $userDepartment);

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
}

$downloadsBadgeCount = $downloadsBadgeCount ?? 0;

$ytSettings = trytest_youtube_settings();
$quizDoneYoutubeHtml = '';
if (is_array($doneBlock) && !empty($doneBlock['quiz_id'])) {
    $quizDoneYoutubeHtml = trytest_youtube_quiz_complete_subscribe_html($ytSettings);
}
$dashboardYoutubeVideosHtml = trytest_youtube_dashboard_videos_html($ytSettings);
$dashboardHasVideos = $isUserLoggedIn && trim((string) ($dashboardYoutubeVideosHtml ?? '')) !== '';

$heroImageUrl = 'https://media.istockphoto.com/id/1359362604/vector/woman-filling-form.jpg?s=612x612&w=0&k=20&c=tUIAiwUal8wNbSU2M-6o5nw7eK3kMNho8yFQUQ8I1O0=';
$dashboardUrl = $isUserLoggedIn ? trytest_url('dashboard') : trytest_home_url();
$quizUrlBase = trytest_url('quiz');
$downloadsPageUrl = trytest_url('downloads');
$quizzesPageUrl = trytest_url('quizzes');
$quizSchedulesPollUrl = trytest_url('api_quiz_schedules.php');
$pendingShareQuizId = (int) ($_SESSION['pending_shared_quiz_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(trytest_url('favicon.svg'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style> body { font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif; } </style>
</head>
<body class="<?php echo $isUserLoggedIn ? 'min-h-screen bg-white text-slate-900 antialiased' : 'bg-white min-h-screen text-slate-900'; ?>">
<?php if ($isUserLoggedIn):
    $userIndex = (string) ($_SESSION['user_index_number'] ?? '');
    $userDisplayName = trytest_student_display_name($userIndex);
    $downloadsBadgeCount = (int) ($downloadsBadgeCount ?? 0);
    $quizzesPageUrl = (string) ($quizzesPageUrl ?? '');
    $needsDepartmentSetup = $userDepartment === '' && $departmentOptions !== [];
    $departmentUpdateError = (string) ($departmentUpdateError ?? '');
    $quizDoneYoutubeHtml = (string) ($quizDoneYoutubeHtml ?? '');
    $dashboardYoutubeVideosHtml = (string) ($dashboardYoutubeVideosHtml ?? '');
    $doneComparison = is_array($doneComparison) ? $doneComparison : null;
    require __DIR__ . '/templates/student_gamified_shell.php';
else: ?>
    <div class="mx-auto max-w-5xl p-0 md:p-4 md:py-8">
        <div class="min-h-[100dvh] rounded-none border-0 bg-white p-4 pb-8 md:min-h-0 md:rounded-2xl md:border md:border-slate-200 md:p-6 md:pb-6">
            <h1 class="text-2xl font-bold text-slate-900 mb-1 text-center md:text-left">Trytest</h1>
            <p class="text-slate-600 mb-5 text-center md:text-left">Sign in with your index number.</p>

            <?php if ($pendingShareQuizId > 0): ?>
                <div class="mb-4 rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2.5 text-center text-sm text-indigo-900 md:text-left">
                    You have a quiz link — sign in to open it (your level and program must match the quiz).
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="mb-4 rounded-lg bg-red-100 px-3 py-2 text-sm text-red-700"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($message !== ''): ?>
                <div class="mb-4 rounded-lg bg-emerald-100 px-3 py-2 text-sm text-emerald-800">
                    <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($generatedPassword !== ''): ?>
                        <span class="mt-2 block rounded-md bg-white/80 px-2 py-2 text-center font-mono text-lg font-bold tracking-widest text-slate-900"><?php echo htmlspecialchars($generatedPassword, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="mt-1 block text-xs text-emerald-900/80">This is your Trytest password (4 digits). You will enter it on the next step.</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($loginMode === 'reset'): ?>
                <div class="mx-auto flex min-h-[68dvh] w-full max-w-md items-center">
                    <div class="w-full">
                    <p class="mb-3 text-sm text-slate-600">Enter your index. We will create a new 4-digit Trytest password.</p>
                    <form method="post" class="space-y-3">
                        <input type="hidden" name="action" value="reset_password">
                        <?php if ($pendingShareQuizId > 0): ?>
                            <input type="hidden" name="shared_quiz_id" value="<?php echo $pendingShareQuizId; ?>">
                        <?php endif; ?>
                        <input class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 uppercase focus:outline-none focus:ring-2 focus:ring-indigo-500" type="text" name="index_number" placeholder="Index number" value="<?php echo htmlspecialchars($enteredIndex, ENT_QUOTES, 'UTF-8'); ?>" required>
                        <button class="w-full rounded-lg bg-indigo-600 py-2 font-medium text-white" type="submit">Reset password</button>
                    </form>
                    <p class="mt-3 text-center text-sm"><a class="text-indigo-600" href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'); ?>">Back to sign in</a></p>
                    </div>
                </div>
            <?php else: ?>
                <div class="grid h-[76dvh] grid-rows-[42%_58%] gap-3 overflow-hidden md:h-auto md:grid-cols-2 md:grid-rows-1 md:items-center md:gap-8">
                    <div class="order-2 min-h-0 md:order-1">
                        <section class="mx-auto flex h-full w-full max-w-md flex-col rounded-xl border border-slate-200 bg-slate-50 p-4 sm:p-5 md:mx-0 md:max-w-none md:h-auto">
                            <h2 class="mb-3 text-center text-base font-semibold text-slate-900 md:text-left">Sign in</h2>
                            <div class="min-h-0 flex-1 overflow-y-auto pr-1">
                            <?php if ($loginMode === 'index'): ?>
                                <form method="post" class="space-y-3" id="formCheckIndex">
                                    <input type="hidden" name="action" value="check_index">
                                    <?php if ($pendingShareQuizId > 0): ?>
                                        <input type="hidden" name="shared_quiz_id" value="<?php echo $pendingShareQuizId; ?>">
                                    <?php endif; ?>
                                    <input class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 uppercase focus:outline-none focus:ring-2 focus:ring-indigo-500" type="text" name="index_number" placeholder="Index number (e.g BC/ITS/24/047)" value="<?php echo htmlspecialchars($enteredIndex, ENT_QUOTES, 'UTF-8'); ?>" required>
                                    <button class="w-full rounded-lg bg-indigo-600 py-2 font-medium text-white" type="submit">Continue</button>
                                </form>
                                <p class="mt-3 text-center text-sm md:text-left"><a class="text-indigo-600" href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'); ?>?view=reset">Forgot password?</a></p>
                            <?php elseif ($loginMode === 'existing'): ?>
                                <p class="mb-2 text-center text-sm text-slate-600 md:text-left">Index <span class="font-semibold"><?php echo htmlspecialchars($enteredIndex, ENT_QUOTES, 'UTF-8'); ?></span><?php if ($existingUserLevel !== ''): ?> · Level <?php echo htmlspecialchars($existingUserLevel, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></p>
                                <form method="post" class="space-y-3" id="formLoginExisting">
                                    <input type="hidden" name="action" value="login_existing">
                                    <?php if ($pendingShareQuizId > 0): ?>
                                        <input type="hidden" name="shared_quiz_id" value="<?php echo $pendingShareQuizId; ?>">
                                    <?php endif; ?>
                                    <input type="hidden" name="index_number" value="<?php echo htmlspecialchars($enteredIndex, ENT_QUOTES, 'UTF-8'); ?>">
                                    <div>
                                        <label for="studentPassword" class="mb-1 block text-left text-xs font-medium text-slate-600">Your Trytest password <span class="text-slate-400">(4 digits)</span></label>
                                        <input id="studentPassword" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-center font-mono text-lg tracking-widest focus:outline-none focus:ring-2 focus:ring-indigo-500" type="password" name="password" inputmode="numeric" pattern="[0-9]*" autocomplete="current-password" maxlength="4" placeholder="••••" required title="Up to 4 digits — your Trytest password">
                                    </div>
                                    <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600">
                                        <input type="checkbox" name="remember_me" value="1" id="rememberMe" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                        Save password on this device for next visit
                                    </label>
                                    <button class="w-full rounded-lg bg-emerald-600 py-2 font-medium text-white" type="submit">Sign in</button>
                                </form>
                                <p class="mt-3 text-center text-sm md:text-left"><a class="text-indigo-600" href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'); ?>">Use another index</a> · <a class="text-indigo-600" href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'); ?>?view=reset">Forgot password?</a></p>
                            <?php else: ?>
                                <p class="mb-2 text-center text-sm text-slate-600 md:text-left">New account · <span class="font-semibold"><?php echo htmlspecialchars($enteredIndex, ENT_QUOTES, 'UTF-8'); ?></span></p>
                                <form method="post" class="space-y-3">
                                    <input type="hidden" name="action" value="register_new">
                                    <?php if ($pendingShareQuizId > 0): ?>
                                        <input type="hidden" name="shared_quiz_id" value="<?php echo $pendingShareQuizId; ?>">
                                    <?php endif; ?>
                                    <input type="hidden" name="index_number" value="<?php echo htmlspecialchars($enteredIndex, ENT_QUOTES, 'UTF-8'); ?>">
                                    <select class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" name="level" required>
                                        <option value="">Your level</option>
                                        <option value="100">100</option>
                                        <option value="200">200</option>
                                        <option value="300">300</option>
                                        <option value="400">400</option>
                                    </select>
                                    <label class="block text-left text-xs font-medium text-slate-600">Program / department<?php if ($departmentOptions): ?> <span class="text-red-600">*</span><?php else: ?> <span class="text-slate-400">(optional)</span><?php endif; ?></label>
                                    <select class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" name="department" <?php echo $departmentOptions ? 'required' : ''; ?>>
                                        <option value=""><?php echo $departmentOptions ? 'Select your program…' : 'Match any course program'; ?></option>
                                        <?php foreach ($departmentOptions as $depOpt): ?>
                                            <option value="<?php echo htmlspecialchars((string) ($depOpt['value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) ($depOpt['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!$departmentOptions): ?>
                                        <p class="text-xs text-slate-500">Your admin can add program names under Manager → Departments, or set a department on a course; until then, all courses for your level are shown.</p>
                                    <?php else: ?>
                                        <p class="text-xs text-slate-500">Required so quizzes and files match your program.</p>
                                    <?php endif; ?>
                                    <button class="w-full rounded-lg bg-indigo-600 py-2 font-medium text-white" type="submit">Create account</button>
                                </form>
                                <p class="mt-3 text-center text-sm md:text-left"><a class="text-indigo-600" href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'); ?>">Use another index</a></p>
                            <?php endif; ?>
                            </div>
                        </section>
                    </div>
                    <div class="order-1 min-h-0 md:order-2">
                        <figure class="h-full overflow-hidden rounded-2xl border border-slate-200 bg-indigo-50/40 shadow-sm md:rounded-none md:border-0 md:bg-transparent md:shadow-none">
                            <img src="<?php echo htmlspecialchars($heroImageUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="h-full w-full object-cover object-center md:rounded-none" width="612" height="612" loading="eager" decoding="async">
                        </figure>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
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
