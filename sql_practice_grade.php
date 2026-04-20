<?php

declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_THROW_ON_ERROR);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json'], JSON_THROW_ON_ERROR);
    exit;
}

$quizId = isset($data['quiz_id']) ? (int) $data['quiz_id'] : 0;
$questionId = isset($data['question_id']) ? (int) $data['question_id'] : 0;
$studentSql = isset($data['sql']) ? (string) $data['sql'] : '';

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/student_helpers.php';
require_once __DIR__ . '/includes/sql_practice.php';

$userId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$userLevel = trim((string) ($_SESSION['user_level'] ?? ''));
$userDepartment = trim((string) ($_SESSION['user_department'] ?? ''));

if ($userId < 1 || $userLevel === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated'], JSON_THROW_ON_ERROR);
    exit;
}

if ($quizId < 1 || $questionId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_ids'], JSON_THROW_ON_ERROR);
    exit;
}

if (!trytest_student_can_access_quiz($db, $quizId, $userLevel, $userDepartment)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_THROW_ON_ERROR);
    exit;
}

$stmt = $db->prepare(
    'SELECT id, quiz_id, question_type, sql_practice
     FROM questions
     WHERE id = ? AND quiz_id = ? AND status = \'approved\''
);
$stmt->execute([$questionId, $quizId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row === false || strtolower((string) ($row['question_type'] ?? '')) !== 'sql') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'not_sql_question'], JSON_THROW_ON_ERROR);
    exit;
}

$decoded = json_decode((string) ($row['sql_practice'] ?? ''), true);
$cfg = trytest_sql_practice_parse_config(is_array($decoded) ? $decoded : null);
if (!$cfg['ok']) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $cfg['error'] ?? 'bad_config'], JSON_THROW_ON_ERROR);
    exit;
}

$setup = $cfg['setup'];
$reference = $cfg['reference'];
$hints = $cfg['hints'];
$simCorrect = $cfg['simCorrect'];
$simPartial = $cfg['simPartial'];

$bad = trytest_sql_student_query_allowed($studentSql);
if ($bad !== null) {
    echo json_encode(
        [
            'ok' => true,
            'graded' => true,
            'verdict' => 'wrong',
            'similarity' => 0.0,
            'feedback' => [$bad],
            'sqlite_error' => null,
            'expected_rows' => null,
            'actual_rows' => null,
        ],
        JSON_THROW_ON_ERROR
    );
    exit;
}

$sanStudent = trytest_sql_strip_sql_comments(rtrim(trim($studentSql), ';'));

[$sandbox, $serr] = trytest_sql_new_sandbox();
if ($serr !== null || !$sandbox instanceof PDO) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $serr ?? 'sandbox'], JSON_THROW_ON_ERROR);
    exit;
}

$setupErr = trytest_sql_run_setup($sandbox, $setup);
if ($setupErr !== null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $setupErr], JSON_THROW_ON_ERROR);
    exit;
}

$refResult = trytest_sql_run_select($sandbox, $reference);
if ($refResult['error'] !== null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Reference query failed: ' . $refResult['error']], JSON_THROW_ON_ERROR);
    exit;
}

$stuResult = trytest_sql_run_select($sandbox, $sanStudent);
if ($stuResult['error'] !== null) {
    $tips = array_merge(
        ['SQLite said: ' . $stuResult['error']],
        $hints
    );
    echo json_encode(
        [
            'ok' => true,
            'graded' => true,
            'verdict' => 'wrong',
            'similarity' => 0.0,
            'feedback' => $tips,
            'sqlite_error' => $stuResult['error'],
            'expected_rows' => count($refResult['rows']),
            'actual_rows' => null,
        ],
        JSON_THROW_ON_ERROR
    );
    exit;
}

$cmp = trytest_sql_compare_result_sets($refResult['rows'], $stuResult['rows']);
$f1 = (float) ($cmp['f1'] ?? 0);

$verdict = 'wrong';
if ($f1 >= $simCorrect) {
    $verdict = 'correct';
} elseif ($f1 >= $simPartial) {
    $verdict = 'partial';
}

$feedback = trytest_sql_feedback_lines($cmp, $f1, $hints);

echo json_encode(
    [
        'ok' => true,
        'graded' => true,
        'verdict' => $verdict,
        'similarity' => round($f1, 4),
        'precision' => round((float) ($cmp['precision'] ?? 0), 4),
        'recall' => round((float) ($cmp['recall'] ?? 0), 4),
        'feedback' => $feedback,
        'sqlite_error' => null,
        'expected_rows' => count($refResult['rows']),
        'actual_rows' => count($stuResult['rows']),
    ],
    JSON_THROW_ON_ERROR
);
