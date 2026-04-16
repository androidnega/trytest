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
    trytest_youtube_callback_page('Sign in required', '<p>Please sign in to Trytest first, then try downloading again.</p>', trytest_home_url(), 'Back to dashboard');
    exit;
}

$settings = trytest_youtube_settings();
if (!$settings['gate_active']) {
    trytest_redirect(trytest_home_url());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'confirm_hybrid') {
    $csrf = (string) ($_POST['csrf'] ?? '');
    $expectedCsrf = (string) ($_SESSION['oauth_youtube_hybrid_csrf'] ?? '');
    if ($csrf === '' || $expectedCsrf === '' || !hash_equals($expectedCsrf, $csrf)) {
        trytest_youtube_callback_page('Invalid form', '<p>Please start the download again from your dashboard.</p>', trytest_home_url(), 'Back to dashboard');
        exit;
    }
    if (empty($_POST['subscribe_ack'])) {
        trytest_youtube_callback_page(
            'Confirmation required',
            '<p>Please check the box to confirm you subscribed to the channel.</p>',
            trytest_home_url(),
            'Back to dashboard'
        );
        exit;
    }
    $need = trytest_youtube_fallback_code();
    if ($need !== '') {
        $entered = trim((string) ($_POST['video_code'] ?? ''));
        if ($entered === '' || strcasecmp($entered, $need) !== 0) {
            trytest_youtube_callback_page(
                'Invalid code',
                '<p>The video code does not match. Check the video description or ask your instructor.</p>',
                trytest_home_url(),
                'Back to dashboard'
            );
            exit;
        }
    }
    trytest_youtube_mark_session_verified();
    $next = trytest_youtube_safe_next((string) ($_SESSION['oauth_youtube_hybrid_next'] ?? trytest_home_url()));
    unset($_SESSION['oauth_youtube_hybrid_next'], $_SESSION['oauth_youtube_hybrid_csrf']);
    trytest_redirect($next);
}

$err = isset($_GET['error']) ? (string) $_GET['error'] : '';
if ($err !== '') {
    trytest_youtube_callback_page(
        'YouTube sign-in cancelled',
        '<p>We could not verify your Google account. PDF downloads stay locked until you complete sign-in and subscribe to the channel.</p>',
        trytest_home_url(),
        'Back to dashboard'
    );
    exit;
}

$state = (string) ($_GET['state'] ?? '');
$expected = (string) ($_SESSION['oauth_youtube_state'] ?? '');
$next = trytest_youtube_safe_next((string) ($_SESSION['oauth_youtube_next'] ?? trytest_home_url()));
$uid = (int) ($_SESSION['oauth_youtube_uid'] ?? 0);
unset($_SESSION['oauth_youtube_state'], $_SESSION['oauth_youtube_next'], $_SESSION['oauth_youtube_uid']);

if ($state === '' || $expected === '' || !hash_equals($expected, $state) || $uid !== (int) $_SESSION['user_id']) {
    trytest_youtube_callback_page('Invalid session', '<p>Please try the download again from your dashboard.</p>', trytest_home_url(), 'Back to dashboard');
    exit;
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    trytest_youtube_callback_page('Missing code', '<p>Google did not return an authorization code. Try again.</p>', trytest_home_url(), 'Back to dashboard');
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
    trytest_youtube_callback_page('Token error', '<p>Could not exchange the authorization code. Check client ID, secret, and redirect URI in <code>config/youtube.php</code>.</p>', trytest_home_url(), 'Back to dashboard');
    exit;
}

$access = (string) $token['access_token'];
$refresh = trim((string) ($token['refresh_token'] ?? ''));

$subStatus = trytest_youtube_subscription_status($access, $settings['channel_id']);
if ($subStatus !== 'yes') {
    $note = $subStatus === 'unknown'
        ? 'The YouTube API could not confirm your subscription right now (network, limits, or privacy settings).'
        : 'We do not see an active subscription to this channel for the Google account you signed in with.';
    trytest_youtube_hybrid_confirmation_page($settings, $next, $note);
    exit;
}

if ($refresh === '') {
    trytest_youtube_callback_page(
        'No refresh token',
        '<p>Google did not return a refresh token. In Google Cloud OAuth client, revoke Trytest access for this Google account, then try again (we request offline access).</p>',
        trytest_url('youtube_login?next=' . rawurlencode($next)),
        'Try again'
    );
    exit;
}

$db->prepare('UPDATE users SET youtube_refresh_token = ? WHERE id = ?')->execute([$refresh, $uid]);
trytest_youtube_mark_session_verified();

trytest_redirect($next);
