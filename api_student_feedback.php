<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/config/db.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth'], JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_THROW_ON_ERROR);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'json'], JSON_THROW_ON_ERROR);
    exit;
}

$stars = (int) ($data['stars'] ?? 0);
if ($stars < 0 || $stars > 5) {
    $stars = 0;
}
$body = trim((string) ($data['body'] ?? ''));
$quizRef = trim((string) ($data['quiz_ref'] ?? ''));
$bodyLen = function_exists('mb_strlen') ? mb_strlen($body) : strlen($body);
if ($body === '' || $bodyLen > 2000) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'body'], JSON_THROW_ON_ERROR);
    exit;
}
$refLen = function_exists('mb_strlen') ? mb_strlen($quizRef) : strlen($quizRef);
if ($refLen > 120) {
    $quizRef = function_exists('mb_substr') ? mb_substr($quizRef, 0, 120) : substr($quizRef, 0, 120);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$ins = $db->prepare(
    'INSERT INTO student_system_feedback (user_id, stars, body, quiz_ref) VALUES (?, ?, ?, ?)'
);
$ins->execute([$userId, $stars, $body, $quizRef]);

echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
