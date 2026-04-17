<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

$quizId = isset($_GET['quiz_id']) ? (int) $_GET['quiz_id'] : 0;
$questionId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

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

$quizCheck = $db->prepare('SELECT id, level, quiz_starts_at, quiz_ends_at FROM quizzes WHERE id = ?');
$quizCheck->execute([$quizId]);
$quiz = $quizCheck->fetch();
if ($quiz === false) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'quiz_not_found'], JSON_THROW_ON_ERROR);
    exit;
}
if (!empty($quiz['level']) && (string) $quiz['level'] !== $userLevel) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_THROW_ON_ERROR);
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
if ($schedulePhase === 'before') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'quiz_not_started'], JSON_THROW_ON_ERROR);
    exit;
}
if ($schedulePhase === 'after') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'quiz_ended'], JSON_THROW_ON_ERROR);
    exit;
}

if ($questionId < 1) {
    // Playable pool: only questions approved for this quiz (imports start as pending).
    $stmt = $db->prepare(
        "SELECT id FROM questions WHERE quiz_id = ? AND status = 'approved' ORDER BY id"
    );
    $stmt->execute([$quizId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    for ($i = count($ids) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $ids[$i];
        $ids[$i] = $ids[$j];
        $ids[$j] = $tmp;
    }
    echo json_encode(['ok' => true, 'ids' => $ids], JSON_THROW_ON_ERROR);
    exit;
}

$stmt = $db->prepare(
    'SELECT id, question_type, question, option_a, option_b, option_c, option_d, correct_answer
     FROM questions WHERE id = ? AND quiz_id = ? AND status = ?'
);
$stmt->execute([$questionId, $quizId, 'approved']);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row === false) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'question_not_found'], JSON_THROW_ON_ERROR);
    exit;
}

echo json_encode(['ok' => true, 'question' => $row], JSON_THROW_ON_ERROR);
