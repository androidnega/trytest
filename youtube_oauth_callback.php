<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/youtube_subscribe.php';

function trytest_youtube_callback_page(string $title, string $bodyHtml, string $primaryHref, string $primaryLabel): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-50 min-h-screen p-6">'
        . '<div class="mx-auto max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">'
        . '<h1 class="text-lg font-bold text-slate-900">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<div class="mt-3 text-sm text-slate-600">' . $bodyHtml . '</div>'
        . '<a class="mt-5 inline-block rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white" href="'
        . htmlspecialchars($primaryHref, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($primaryLabel, ENT_QUOTES, 'UTF-8') . '</a>'
        . '</div></body></html>';
}

if (empty($_SESSION['user_id'])) {
    trytest_youtube_callback_page('Sign in required', '<p>Please sign in to Trytest first, then try downloading again.</p>', '/trytest/dashboard/', 'Back to dashboard');
    exit;
}

$settings = trytest_youtube_settings();
if (!$settings['gate_active']) {
    header('Location: /trytest/dashboard/');
    exit;
}

$err = isset($_GET['error']) ? (string) $_GET['error'] : '';
if ($err !== '') {
    trytest_youtube_callback_page(
        'YouTube sign-in cancelled',
        '<p>We could not verify your Google account. PDF downloads stay locked until you complete sign-in and subscribe to the channel.</p>',
        '/trytest/dashboard/',
        'Back to dashboard'
    );
    exit;
}

$state = (string) ($_GET['state'] ?? '');
$expected = (string) ($_SESSION['oauth_youtube_state'] ?? '');
$next = trytest_youtube_safe_next((string) ($_SESSION['oauth_youtube_next'] ?? '/trytest/dashboard/'));
$uid = (int) ($_SESSION['oauth_youtube_uid'] ?? 0);
unset($_SESSION['oauth_youtube_state'], $_SESSION['oauth_youtube_next'], $_SESSION['oauth_youtube_uid']);

if ($state === '' || $expected === '' || !hash_equals($expected, $state) || $uid !== (int) $_SESSION['user_id']) {
    trytest_youtube_callback_page('Invalid session', '<p>Please try the download again from your dashboard.</p>', '/trytest/dashboard/', 'Back to dashboard');
    exit;
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    trytest_youtube_callback_page('Missing code', '<p>Google did not return an authorization code. Try again.</p>', '/trytest/dashboard/', 'Back to dashboard');
    exit;
}

$token = trytest_youtube_http_post_form('https://oauth2.googleapis.com/token', [
    'code' => $code,
    'client_id' => $settings['client_id'],
    'client_secret' => $settings['client_secret'],
    'redirect_uri' => $settings['redirect_uri'],
    'grant_type' => 'authorization_code',
]);

if ($token === null || empty($token['access_token'])) {
    trytest_youtube_callback_page('Token error', '<p>Could not exchange the authorization code. Check client ID, secret, and redirect URI in <code>config/youtube.php</code>.</p>', '/trytest/dashboard/', 'Back to dashboard');
    exit;
}

$access = (string) $token['access_token'];
$refresh = trim((string) ($token['refresh_token'] ?? ''));

if (!trytest_youtube_user_subscribed_to_channel($access, $settings['channel_id'])) {
    $ch = rawurlencode($settings['channel_id']);
    $subUrl = 'https://www.youtube.com/channel/' . $ch . '?sub_confirmation=1';
    trytest_youtube_callback_page(
        'Subscribe to continue',
        '<p class="mb-3">Your Google account is connected, but we do not see an active subscription to the course channel yet.</p>'
            . '<p class="mb-3">Open YouTube, subscribe, then return here and try your download again.</p>'
            . '<p><a class="font-semibold text-indigo-600 underline" href="' . htmlspecialchars($subUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Open channel on YouTube</a></p>',
        '/trytest/youtube_connect?next=' . rawurlencode($next),
        'I subscribed — verify again'
    );
    exit;
}

if ($refresh === '') {
    trytest_youtube_callback_page(
        'No refresh token',
        '<p>Google did not return a refresh token. In Google Cloud OAuth client, revoke Trytest access for this Google account, then try again (we request offline access).</p>',
        '/trytest/youtube_connect?next=' . rawurlencode($next),
        'Try again'
    );
    exit;
}

$db->prepare('UPDATE users SET youtube_refresh_token = ? WHERE id = ?')->execute([$refresh, $uid]);

header('Location: ' . $next);
exit;
