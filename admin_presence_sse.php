<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/trytest_presence.php';

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

ignore_user_abort(false);
set_time_limit(0);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

while (connection_status() === CONNECTION_NORMAL) {
    $n = trytest_presence_live_student_count($db);
    echo 'data: ' . json_encode(['n' => $n], JSON_THROW_ON_ERROR) . "\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
    sleep(2);
}
