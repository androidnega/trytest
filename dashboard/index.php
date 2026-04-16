<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!empty($_SESSION['is_admin'])) {
    require dirname(__DIR__) . '/dashboard.php';
    exit;
}

if (!empty($_SESSION['user_id'])) {
    require dirname(__DIR__) . '/student_portal.php';
    exit;
}

$mode = isset($_GET['mode']) ? (string) $_GET['mode'] : '';
if ($mode === 'admin') {
    require dirname(__DIR__) . '/dashboard.php';
    exit;
}

require dirname(__DIR__) . '/student_portal.php';
