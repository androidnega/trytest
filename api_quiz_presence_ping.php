<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/trytest_presence.php';

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

$action = (string) ($data['action'] ?? 'ping');
$quizId = (int) ($data['quiz_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($quizId < 1 || $userId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_params'], JSON_THROW_ON_ERROR);
    exit;
}

if ($action === 'leave') {
    trytest_presence_ping_delete($db, $userId, $quizId);
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    exit;
}

trytest_presence_ping_upsert($db, $userId, $quizId);
echo json_encode(['ok' => true, 'count' => trytest_presence_live_student_count($db)], JSON_THROW_ON_ERROR);
