<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/student_helpers.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id < 1 && isset($_GET['quiz_id'])) {
    $id = (int) $_GET['quiz_id'];
}
if ($id < 1) {
    trytest_redirect(trytest_home_url());
}

$chk = $db->prepare('SELECT id FROM quizzes WHERE id = ?');
$chk->execute([$id]);
if (!$chk->fetchColumn()) {
    trytest_redirect(trytest_home_url());
}

$_SESSION['pending_shared_quiz_id'] = $id;

if (!empty($_SESSION['user_id'])) {
    $level = trim((string) ($_SESSION['user_level'] ?? ''));
    $dept = trim((string) ($_SESSION['user_department'] ?? ''));
    if (trytest_student_can_access_quiz($db, $id, $level, $dept)) {
        unset($_SESSION['pending_shared_quiz_id']);
        trytest_redirect(trytest_url('quiz?quiz_id=' . $id));
    }
    unset($_SESSION['pending_shared_quiz_id']);
    trytest_redirect(trytest_url('dashboard'));
}

trytest_redirect(trytest_home_with_query(['quiz' => $id]));
