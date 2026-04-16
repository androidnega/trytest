<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once dirname(__DIR__) . '/includes/trytest_urls.php';

if ((!empty($_SESSION['is_admin']) || !empty($_SESSION['user_id'])) && trytest_is_app_root_request()) {
    header('Location: ' . trytest_url('dashboard'), true, 302);
    exit;
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
