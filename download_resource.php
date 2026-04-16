<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/student_helpers.php';
require __DIR__ . '/includes/youtube_subscribe.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Sign in required.';
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id < 1) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid document.';
    exit;
}

$stmt = $db->prepare(
    'SELECT id, title, department, level, stored_name, original_name FROM student_documents WHERE id = ?'
);
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

$userDept = trim((string) ($_SESSION['user_department'] ?? ''));
$userLevel = trim((string) ($_SESSION['user_level'] ?? ''));
$docDept = trim((string) ($row['department'] ?? ''));
$docLevel = trim((string) ($row['level'] ?? ''));

if (!trytest_student_document_eligible($userDept, $userLevel, $docDept, $docLevel)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'This file is not available for your program or level.';
    exit;
}

$yt = trytest_youtube_settings();
if (!empty($yt['gate_active'])) {
    $uid = (int) $_SESSION['user_id'];
    if (!trytest_youtube_download_allowed($db, $uid, $yt)) {
        $back = trytest_url('download_resource?id=' . $id);
        header('Location: ' . trytest_url('youtube_connect?next=' . rawurlencode($back)));
        exit;
    }
}

$stored = basename((string) ($row['stored_name'] ?? ''));
$baseDir = realpath(__DIR__ . '/data/uploads/pdfs');
if ($baseDir === false) {
    http_response_code(500);
    echo 'Storage not configured.';
    exit;
}
$path = realpath($baseDir . DIRECTORY_SEPARATOR . $stored);
if ($path === false || strncmp($path, $baseDir, strlen($baseDir)) !== 0 || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File missing.';
    exit;
}

$downloadName = (string) ($row['original_name'] ?? 'document.pdf');
if (!preg_match('/\.pdf$/i', $downloadName)) {
    $downloadName .= '.pdf';
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $downloadName) . '"');
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
