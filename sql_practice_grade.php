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
    'SELECT id, quiz_id, question_type, sql_practice, correct_answer
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
    trytest_sql_emit_graded_wrong(
        [
            (string) ($cfg['error'] ?? 'Invalid SQL practice configuration.'),
            'Ask your instructor to fix the question JSON (setup_sql / reference_sql).',
        ]
    );
}

$setup = $cfg['setup'];
$reference = $cfg['reference'];
$goldenSql = (string) ($cfg['golden'] ?? '');
if ($goldenSql === '') {
    $ca = trim((string) ($row['correct_answer'] ?? ''));
    if ($ca !== '' && $ca !== '-' && preg_match('/^\s*(INSERT|REPLACE|UPDATE|DELETE)\b/is', $ca) === 1) {
        $goldenSql = trytest_sql_extract_first_dml_statement(trytest_sql_strip_line_number_only_lines($ca));
    }
}
$hints = $cfg['hints'];
$simCorrect = $cfg['simCorrect'];
$simPartial = $cfg['simPartial'];

$studentSql = trytest_sql_normalize_student_sql($studentSql);

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
    trytest_sql_emit_graded_wrong([
        'Could not start the in-memory SQL practice database. Try again in a moment.',
        'Nothing you type here runs on the real course database — it is quiz-only.',
    ]);
}

$setupErr = trytest_sql_run_setup($sandbox, $setup);
if ($setupErr !== null) {
    trytest_sql_emit_graded_wrong(
        [
            'The practice database could not be built for this question.',
            $setupErr,
            'Your answer is not graded against the model until setup runs. Ask your instructor to check setup_sql (include CREATE TABLE before INSERT/SELECT).',
        ]
    );
}

$studentIsSelect = trytest_sql_student_answer_is_select($sanStudent);
$expectedRows = [];
$stuResult = null;

if ($studentIsSelect) {
    $refResult = trytest_sql_run_select($sandbox, $reference);
    if ($refResult['error'] !== null) {
        trytest_sql_emit_graded_wrong(
            [
                'The reference check query failed after setup.',
                'Reference error: ' . $refResult['error'],
                'Ask your instructor to verify reference_sql matches the tables created in setup_sql.',
            ],
            $refResult['error']
        );
    }
    $stuResult = trytest_sql_run_select($sandbox, $sanStudent);
    $expectedRows = $refResult['rows'];
} else {
    if ($goldenSql === '') {
        trytest_sql_emit_graded_wrong(
            [
                'This question needs a model answer for INSERT/UPDATE/DELETE grading.',
                'The instructor should add `golden_sql` (canonical statement) with `reference_sql` as the checking SELECT, or put the model DML in `reference_sql` and the checking SELECT in `compare_sql` / `verify_sql`. You can also store the model INSERT in the question\'s correct answer field for SQL items.',
            ]
        );
    }

    $stuExecErr = trytest_sql_exec_statement($sandbox, $sanStudent);
    if ($stuExecErr !== null) {
        $tips = ['SQLite said: ' . $stuExecErr];
        if (preg_match('/\bno column named\b/i', $stuExecErr) === 1) {
            $tips[] =
                'Your INSERT names a column that is not on the sandbox table for this question. Use the exact column names from that table\'s CREATE TABLE (check `setup_sql` / the brief), or ask the instructor to fix `setup_sql` if the brief expects names like ProductID but the table was created differently.';
        }
        $tips = array_merge($tips, $hints);
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
        trytest_sql_emit_graded_wrong([
            'Could not start a second practice database for grading. Try again in a moment.',
        ]);
    }
    $setupErrGold = trytest_sql_run_setup($sandboxGold, $setup);
    if ($setupErrGold !== null) {
        trytest_sql_emit_graded_wrong(
            [
                'Could not rebuild the practice database for the model answer.',
                $setupErrGold,
            ]
        );
    }
    $goldExecErr = trytest_sql_exec_statement($sandboxGold, $goldenSql);
    if ($goldExecErr !== null) {
        trytest_sql_emit_graded_wrong(
            [
                'The model answer (golden_sql) could not run in the practice database.',
                'golden_sql error: ' . $goldExecErr,
                'Ask your instructor to fix golden_sql or the table definition in setup_sql.',
            ],
            $goldExecErr
        );
    }
    $goldRef = trytest_sql_run_select($sandboxGold, $reference);
    if ($goldRef['error'] !== null) {
        trytest_sql_emit_graded_wrong(
            [
                'The checking SELECT failed after the model statement.',
                'Error: ' . $goldRef['error'],
                'Ask your instructor to align reference_sql with setup_sql and golden_sql.',
            ],
            $goldRef['error']
        );
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
