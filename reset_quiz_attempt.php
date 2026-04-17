<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$quizId = isset($data['quiz_id']) ? (int) $data['quiz_id'] : 0;
if ($quizId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_quiz_id']);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userLevel = trim((string) ($_SESSION['user_level'] ?? ''));
$userDepartment = trim((string) ($_SESSION['user_department'] ?? ''));
if ($userId < 1 || $userLevel === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/student_helpers.php';

if (!trytest_student_can_access_quiz($db, $quizId, $userLevel, $userDepartment)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

try {
    $del = $db->prepare('DELETE FROM scores WHERE quiz_id = ? AND user_id = ?');
    $del->execute([$quizId, $userId]);
    echo json_encode(['ok' => true, 'removed' => $del->rowCount()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'reset_failed']);
}

