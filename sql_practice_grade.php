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

trytest_student_api_require_nickname($db);

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
$goldenSql = (string) ($cfg['golden'] ?? '');
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
            'marks' => 0,
            'marks_max' => 10,
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

$studentIsSelect = trytest_sql_student_answer_is_select($sanStudent);
$expectedRows = [];
$stuResult = null;

if ($studentIsSelect) {
    $refResult = trytest_sql_run_select($sandbox, $reference);
    if ($refResult['error'] !== null) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Reference query failed: ' . $refResult['error']], JSON_THROW_ON_ERROR);
        exit;
    }
    $stuResult = trytest_sql_run_select($sandbox, $sanStudent);
    $expectedRows = $refResult['rows'];
} else {
    if ($goldenSql === '') {
        echo json_encode(
            [
                'ok' => true,
                'graded' => true,
                'verdict' => 'wrong',
                'similarity' => 0.0,
                'marks' => 0,
                'marks_max' => 10,
                'feedback' => [
                    'For INSERT/UPDATE/DELETE or DDL answers, the question needs a `golden_sql` field in sql_practice (the instructor\'s solution, one statement). We then compare the result of `reference_sql` after your answer vs after `golden_sql`.',
                    'Ask your instructor to add golden_sql to this question\'s SQL practice JSON.',
                ],
                'sqlite_error' => null,
                'expected_rows' => null,
                'actual_rows' => null,
            ],
            JSON_THROW_ON_ERROR
        );
        exit;
    }

    $stuExecErr = trytest_sql_exec_statement($sandbox, $sanStudent);
    if ($stuExecErr !== null) {
        $tips = array_merge(
            ['SQLite said: ' . $stuExecErr],
            $hints
        );
        echo json_encode(
            [
                'ok' => true,
                'graded' => true,
                'verdict' => 'wrong',
                'similarity' => 0.0,
                'marks' => 0,
                'marks_max' => 10,
                'feedback' => $tips,
                'sqlite_error' => $stuExecErr,
                'expected_rows' => null,
                'actual_rows' => null,
            ],
            JSON_THROW_ON_ERROR
        );
        exit;
    }

    $stuResult = trytest_sql_run_select($sandbox, $reference);
    if ($stuResult['error'] !== null) {
        $tips = array_merge(
            ['After your statement, reference_sql failed: ' . $stuResult['error']],
            $hints
        );
        echo json_encode(
            [
                'ok' => true,
                'graded' => true,
                'verdict' => 'wrong',
                'similarity' => 0.0,
                'marks' => 0,
                'marks_max' => 10,
                'feedback' => $tips,
                'sqlite_error' => $stuResult['error'],
                'expected_rows' => null,
                'actual_rows' => null,
            ],
            JSON_THROW_ON_ERROR
        );
        exit;
    }

    [$sandboxGold, $gErr] = trytest_sql_new_sandbox();
    if ($gErr !== null || !$sandboxGold instanceof PDO) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $gErr ?? 'sandbox'], JSON_THROW_ON_ERROR);
        exit;
    }
    $setupErrGold = trytest_sql_run_setup($sandboxGold, $setup);
    if ($setupErrGold !== null) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $setupErrGold], JSON_THROW_ON_ERROR);
        exit;
    }
    $goldExecErr = trytest_sql_exec_statement($sandboxGold, $goldenSql);
    if ($goldExecErr !== null) {
        http_response_code(500);
        echo json_encode(
            ['ok' => false, 'error' => 'golden_sql failed (check instructor config): ' . $goldExecErr],
            JSON_THROW_ON_ERROR
        );
        exit;
    }
    $goldRef = trytest_sql_run_select($sandboxGold, $reference);
    if ($goldRef['error'] !== null) {
        http_response_code(500);
        echo json_encode(
            ['ok' => false, 'error' => 'reference_sql failed after golden_sql: ' . $goldRef['error']],
            JSON_THROW_ON_ERROR
        );
        exit;
    }
    $expectedRows = $goldRef['rows'];
}

if ($stuResult === null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal grade state'], JSON_THROW_ON_ERROR);
    exit;
}

if ($studentIsSelect && $stuResult['error'] !== null) {
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
            'marks' => 0,
            'marks_max' => 10,
            'feedback' => $tips,
            'sqlite_error' => $stuResult['error'],
            'expected_rows' => count($expectedRows),
            'actual_rows' => null,
        ],
        JSON_THROW_ON_ERROR
    );
    exit;
}

$cmp = trytest_sql_compare_result_sets($expectedRows, $stuResult['rows']);
$f1 = (float) ($cmp['f1'] ?? 0);

$verdict = 'wrong';
if ($f1 >= $simCorrect) {
    $verdict = 'correct';
} elseif ($f1 >= $simPartial) {
    $verdict = 'partial';
}

$feedback = trytest_sql_feedback_lines($cmp, $f1, $hints, $verdict !== 'correct');
$marks = trytest_sql_marks_from_similarity($f1, $simCorrect);

echo json_encode(
    [
        'ok' => true,
        'graded' => true,
        'verdict' => $verdict,
        'similarity' => round($f1, 4),
        'precision' => round((float) ($cmp['precision'] ?? 0), 4),
        'recall' => round((float) ($cmp['recall'] ?? 0), 4),
        'marks' => $marks,
        'marks_max' => 10,
        'feedback' => $feedback,
        'sqlite_error' => null,
        'expected_rows' => count($expectedRows),
        'actual_rows' => count($stuResult['rows']),
    ],
    JSON_THROW_ON_ERROR
);
