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
    if (!trytest_youtube_download_allowed($yt)) {
        $gateErr = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $act = (string) ($_POST['pdf_gate_action'] ?? '');
            if ($act === 'unlock_code') {
                $code = trim((string) ($_POST['unlock_code'] ?? ''));
                $expect = trim((string) ($yt['pdf_unlock_code'] ?? ''));
                if ($expect !== '' && strcasecmp($code, $expect) === 0) {
                    trytest_pdf_light_gate_mark_ok();
                    trytest_redirect(trytest_url('download_resource?id=' . $id));
                }
                $gateErr = $expect === '' ? 'No video code is set yet — use Continue to download.' : 'That code does not match the one in the video.';
            } elseif ($act === 'nudge_continue') {
                trytest_pdf_light_gate_mark_ok();
                trytest_redirect(trytest_url('download_resource?id=' . $id));
            }
        }
        trytest_render_pdf_download_gate($id, (string) ($row['title'] ?? 'PDF'), $yt, $gateErr);
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

trytest_record_document_download($db, (int) $_SESSION['user_id'], $id);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $downloadName) . '"');
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
