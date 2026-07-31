<?php

declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$quizId = isset($data['quiz_id']) ? (int) $data['quiz_id'] : 0;
$score = isset($data['score']) ? (int) $data['score'] : 0;
$total = isset($data['total']) ? (int) $data['total'] : 0;

if ($quizId < 1 || $score < 0 || $total < 1 || $score > $total) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
    exit;
}

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/student_helpers.php';
require_once __DIR__ . '/includes/quiz_game.php';

$userId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$reviewPayload = isset($data['review']) && is_array($data['review']) ? $data['review'] : null;
$reviewJson = trytest_quiz_review_json_normalize($reviewPayload, $total);
$gamePayload = isset($data['game']) && is_array($data['game']) ? $data['game'] : [];

try {
    if ($userId !== null && $userId > 0) {
        trytest_student_api_require_nickname($db);
        $userLevel = trim((string) ($_SESSION['user_level'] ?? ''));
        $userDepartment = trim((string) ($_SESSION['user_department'] ?? ''));
        if (!trytest_student_can_access_quiz($db, $quizId, $userLevel, $userDepartment)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'forbidden']);
            exit;
        }
        $db->beginTransaction();
        $att = $db->prepare('INSERT INTO score_attempts (quiz_id, user_id, score, total) VALUES (?, ?, ?, ?)');
        $att->execute([$quizId, $userId, $score, $total]);
        $attemptId = (int) $db->lastInsertId();
        $existingStmt = $db->prepare(
            'SELECT id FROM scores WHERE quiz_id = ? AND user_id = ? ORDER BY id ASC LIMIT 1'
        );
        $existingStmt->execute([$quizId, $userId]);
        $existingId = (int) ($existingStmt->fetchColumn() ?: 0);
        if ($existingId > 0) {
            $upd = $db->prepare(
                'UPDATE scores SET score = ?, total = ?, review_json = ?, created_at = datetime(\'now\') WHERE id = ?'
            );
            $upd->execute([$score, $total, $reviewJson, $existingId]);
            $cleanup = $db->prepare('DELETE FROM scores WHERE quiz_id = ? AND user_id = ? AND id <> ?');
            $cleanup->execute([$quizId, $userId, $existingId]);
            $gameResult = trytest_student_game_apply_session($db, $userId, $quizId, $gamePayload);
            $db->commit();
            echo json_encode([
                'ok' => true,
                'id' => $existingId,
                'attempt_id' => $attemptId,
                'replaced' => true,
                'game' => $gameResult,
            ]);
            exit;
        }
        $ins = $db->prepare(
            'INSERT INTO scores (quiz_id, user_id, score, total, review_json) VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute([$quizId, $userId, $score, $total, $reviewJson]);
        $newId = (int) $db->lastInsertId();
        $gameResult = trytest_student_game_apply_session($db, $userId, $quizId, $gamePayload);
        $db->commit();
        echo json_encode([
            'ok' => true,
            'id' => $newId,
            'attempt_id' => $attemptId,
            'replaced' => false,
            'game' => $gameResult,
        ]);
        exit;
    }

    $stmt = $db->prepare('INSERT INTO scores (quiz_id, user_id, score, total) VALUES (?, ?, ?, ?)');
    $stmt->execute([$quizId, $userId, $score, $total]);
    echo json_encode(['ok' => true, 'id' => (int) $db->lastInsertId(), 'replaced' => false]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed']);
}
