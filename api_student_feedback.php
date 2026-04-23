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
if ($stars < 1 || $stars > 5) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'stars'], JSON_THROW_ON_ERROR);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

$exists = $db->prepare('SELECT 1 FROM student_system_feedback WHERE user_id = ? LIMIT 1');
$exists->execute([$userId]);
if ((bool) $exists->fetchColumn()) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'already_rated'], JSON_THROW_ON_ERROR);
    exit;
}

$ins = $db->prepare(
    'INSERT INTO student_system_feedback (user_id, stars, body, quiz_ref, created_at) VALUES (?, ?, ?, ?, datetime(\'now\'))'
);
$ins->execute([$userId, $stars, '', '']);

echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
