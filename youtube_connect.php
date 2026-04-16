<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/youtube_subscribe.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /trytest/dashboard/');
    exit;
}

$settings = trytest_youtube_settings();
if (!$settings['gate_active']) {
    $next = trytest_youtube_safe_next($_GET['next'] ?? '');
    header('Location: ' . $next);
    exit;
}

$next = trytest_youtube_safe_next($_GET['next'] ?? '/trytest/dashboard/');
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_youtube_state'] = $state;
$_SESSION['oauth_youtube_next'] = $next;
$_SESSION['oauth_youtube_uid'] = (int) $_SESSION['user_id'];

$scope = rawurlencode('https://www.googleapis.com/auth/youtube.readonly');
$url = 'https://accounts.google.com/o/oauth2/v2/auth'
    . '?client_id=' . rawurlencode($settings['client_id'])
    . '&redirect_uri=' . rawurlencode($settings['redirect_uri'])
    . '&response_type=code'
    . '&scope=' . $scope
    . '&access_type=offline'
    . '&prompt=consent'
    . '&state=' . rawurlencode($state);

header('Location: ' . $url);
exit;
