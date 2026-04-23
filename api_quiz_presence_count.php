<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/trytest_presence.php';

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_THROW_ON_ERROR);
    exit;
}

echo json_encode(
    [
        'ok' => true,
        'count' => trytest_presence_live_student_count($db),
    ],
    JSON_THROW_ON_ERROR
);
