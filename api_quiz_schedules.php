<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/student_helpers.php';

$userId = (int) $_SESSION['user_id'];
$sync = $db->prepare('SELECT level, department FROM users WHERE id = ?');
$sync->execute([$userId]);
$row = $sync->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

trytest_student_api_require_nickname($db);

$userLevel = trim((string) ($row['level'] ?? ''));
$userDepartment = trim((string) ($row['department'] ?? ''));

$map = trytest_student_dashboard_quiz_schedule_map($db, $userLevel, $userDepartment);
$list = [];
foreach ($map as $quizId => $se) {
    $list[] = [
        'quiz_id' => $quizId,
        'start' => $se['start'],
        'end' => $se['end'],
    ];
}

$json = json_encode(['ok' => true, 'quizzes' => $list]);
echo $json !== false ? $json : '{"ok":false,"error":"encode"}';
