<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$quizId = isset($_GET['quiz_id']) ? (int) $_GET['quiz_id'] : 0;
if ($quizId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_quiz_id'], JSON_THROW_ON_ERROR);
    exit;
}

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/student_helpers.php';

$userLevel = (string) ($_SESSION['user_level'] ?? '');
$userDepartment = trim((string) ($_SESSION['user_department'] ?? ''));
if (empty($_SESSION['user_id']) || $userLevel === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated'], JSON_THROW_ON_ERROR);
    exit;
}

trytest_student_api_require_nickname($db);

$quizCheck = $db->prepare('SELECT id, level, quiz_starts_at, quiz_ends_at, duration_minutes FROM quizzes WHERE id = ?');
$quizCheck->execute([$quizId]);
$quiz = $quizCheck->fetch(PDO::FETCH_ASSOC);
if ($quiz === false) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'quiz_not_found'], JSON_THROW_ON_ERROR);
    exit;
}
if (!trytest_student_can_access_quiz($db, $quizId, $userLevel, $userDepartment)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_THROW_ON_ERROR);
    exit;
}

$schedulePhase = trytest_quiz_schedule_phase(
    isset($quiz['quiz_starts_at']) ? (string) $quiz['quiz_starts_at'] : null,
    isset($quiz['quiz_ends_at']) ? (string) $quiz['quiz_ends_at'] : null
);
if ($schedulePhase === 'before' || $schedulePhase === 'after') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'quiz_not_available'], JSON_THROW_ON_ERROR);
    exit;
}

$durationMinutes = isset($quiz['duration_minutes']) && $quiz['duration_minutes'] !== null
    ? max(0, (int) $quiz['duration_minutes'])
    : 0;
$effective = trytest_quiz_effective_duration_seconds(
    $durationMinutes,
    isset($quiz['quiz_ends_at']) ? (string) $quiz['quiz_ends_at'] : null
);

echo json_encode(['ok' => true, 'effective_duration_seconds' => $effective], JSON_THROW_ON_ERROR);
