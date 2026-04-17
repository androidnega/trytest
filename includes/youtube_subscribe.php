<?php

declare(strict_types=1);

function trytest_youtube_ensure_google_php(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $gp = __DIR__ . '/../config/google.php';
    if (is_file($gp)) {
        require_once $gp;
    }
}

/**
 * Load merged settings: database row (admin UI) overrides file/env fallbacks.
 * Optional `config/google.php` fills any remaining blanks (production-friendly).
 *
 * @return array{
 *   client_id:string,
 *   client_secret:string,
 *   redirect_uri:string,
 *   channel_id:string,
 *   gate_enabled:bool,
 *   gate_active:bool,
 *   credentials_complete:bool,
 *   oauth_gate_ready:bool,
 *   pdf_unlock_code:string
 * }
 */
function trytest_youtube_settings(): array
{
    trytest_youtube_ensure_google_php();

    $path = __DIR__ . '/../config/youtube.php';
    $file = is_file($path) ? require $path : [];
    $fClient = trim((string) ($file['client_id'] ?? ''));
    $fSecret = trim((string) ($file['client_secret'] ?? ''));
    $fRedirect = trim((string) ($file['redirect_uri'] ?? ''));
    $fChannel = trim((string) ($file['channel_id'] ?? ''));

    $rowGate = false;
    $dClient = '';
    $dSecret = '';
    $dRedirect = '';
    $dChannel = '';
    $dPdfCode = '';

    global $db;
    if (isset($db) && $db instanceof PDO) {
        try {
            $st = $db->query(
                'SELECT gate_enabled, client_id, client_secret, redirect_uri, channel_id, pdf_unlock_code FROM youtube_app_settings WHERE id = 1'
            );
            $r = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($r)) {
                $rowGate = ((int) ($r['gate_enabled'] ?? 0)) === 1;
                $dClient = trim((string) ($r['client_id'] ?? ''));
                $dSecret = trim((string) ($r['client_secret'] ?? ''));
                $dRedirect = trim((string) ($r['redirect_uri'] ?? ''));
                $dChannel = trim((string) ($r['channel_id'] ?? ''));
                $dPdfCode = trim((string) ($r['pdf_unlock_code'] ?? ''));
            }
        } catch (Throwable $e) {
            // table missing on very old DB — fall back to file only
        }
    }

    $clientId = $dClient !== '' ? $dClient : $fClient;
    $clientSecret = $dSecret !== '' ? $dSecret : $fSecret;
    $redirectUri = $dRedirect !== '' ? $dRedirect : $fRedirect;
    $channelId = $dChannel !== '' ? $dChannel : $fChannel;

    if ($clientId === '' && defined('GOOGLE_CLIENT_ID')) {
        $clientId = trim((string) GOOGLE_CLIENT_ID);
    }
    if ($clientSecret === '' && defined('GOOGLE_CLIENT_SECRET')) {
        $clientSecret = trim((string) GOOGLE_CLIENT_SECRET);
    }
    if ($redirectUri === '' && defined('GOOGLE_REDIRECT_URI')) {
        $redirectUri = trim((string) GOOGLE_REDIRECT_URI);
    }
    if ($channelId === '' && defined('YOUTUBE_CHANNEL_ID')) {
        $channelId = trim((string) YOUTUBE_CHANNEL_ID);
    }

    $credentialsComplete = $clientId !== '' && $clientSecret !== '' && $redirectUri !== '' && $channelId !== '';
    $pdfUnlockCode = $dPdfCode !== '' ? $dPdfCode : trim((string) ($file['pdf_unlock_code'] ?? ''));
    if ($pdfUnlockCode === '' && defined('YOUTUBE_PDF_UNLOCK_CODE')) {
        $pdfUnlockCode = trim((string) YOUTUBE_PDF_UNLOCK_CODE);
    }
    // Light PDF gate: on when admin toggles it (no OAuth required). OAuth remains optional for legacy API checks.
    $gateActive = $rowGate;

    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'channel_id' => $channelId,
        'pdf_unlock_code' => $pdfUnlockCode,
        'gate_enabled' => $rowGate,
        'gate_active' => $gateActive,
        'credentials_complete' => $credentialsComplete,
        'oauth_gate_ready' => $rowGate && $credentialsComplete,
    ];
}

function trytest_youtube_safe_next(?string $next): string
{
    $fallback = trytest_home_url();
    $next = trim((string) $next);
    if ($next === '' || $next[0] !== '/') {
        return $fallback;
    }
    if (str_starts_with($next, '//')) {
        return $fallback;
    }
    if (str_contains($next, "\r") || str_contains($next, "\n")) {
        return $fallback;
    }
    $base = trytest_base_path();
    if ($base === '') {
        if (!preg_match('#^/[A-Za-z0-9_./?=&-]*$#', $next)) {
            return $fallback;
        }
        return $next;
    }
    $prefix = $base . '/';
    if (strncmp($next, $prefix, strlen($prefix)) !== 0 && $next !== $base) {
        return $fallback;
    }
    return $next;
}

function trytest_youtube_session_ttl(): int
{
    return 86400 * 7;
}

function trytest_youtube_session_verified(): bool
{
    if (empty($_SESSION['youtube_verified'])) {
        return false;
    }
    $at = (int) ($_SESSION['youtube_verified_at'] ?? 0);
    if ($at < 1 || (time() - $at) > trytest_youtube_session_ttl()) {
        trytest_youtube_clear_session_verified();
        return false;
    }
    return true;
}

function trytest_youtube_mark_session_verified(): void
{
    $_SESSION['youtube_verified'] = true;
    $_SESSION['youtube_verified_at'] = time();
}

function trytest_youtube_clear_session_verified(): void
{
    unset($_SESSION['youtube_verified'], $_SESSION['youtube_verified_at'], $_SESSION['oauth_youtube_hybrid_next'], $_SESSION['oauth_youtube_hybrid_csrf']);
}

function trytest_youtube_fallback_code(): string
{
    trytest_youtube_ensure_google_php();
    if (defined('YOUTUBE_FALLBACK_CODE')) {
        return trim((string) YOUTUBE_FALLBACK_CODE);
    }
    return '';
}

/**
 * @return 'yes'|'no'|'unknown'
 */
function trytest_youtube_subscription_status(string $accessToken, string $channelId): string
{
    $q = http_build_query([
        'part' => 'id',
        'mine' => 'true',
        'forChannelId' => $channelId,
    ], '', '&', PHP_QUERY_RFC3986);
    $url = 'https://www.googleapis.com/youtube/v3/subscriptions?' . $q;
    $json = trytest_youtube_http_get_json($url, $accessToken);
    if ($json === null) {
        return 'unknown';
    }
    if (!empty($json['error'])) {
        return 'unknown';
    }
    $items = $json['items'] ?? [];
    if (is_array($items) && count($items) > 0) {
        return 'yes';
    }
    return 'no';
}

/**
 * HTML page: manual subscribe confirmation when API says “no” or is unavailable.
 *
 * @param array<string, mixed> $settings
 */
function trytest_youtube_hybrid_confirmation_page(array $settings, string $next, string $apiNote): void
{
    $csrf = bin2hex(random_bytes(16));
    $_SESSION['oauth_youtube_hybrid_next'] = $next;
    $_SESSION['oauth_youtube_hybrid_csrf'] = $csrf;
    $postUrl = trytest_url('youtube_oauth_callback');
    $ch = rawurlencode($settings['channel_id']);
    $subUrl = 'https://www.youtube.com/channel/' . $ch . '?sub_confirmation=1';
    $needCode = trytest_youtube_fallback_code();
    $title = 'Confirm subscription';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-50 min-h-screen p-6">'
        . '<div class="mx-auto max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">'
        . '<h1 class="text-lg font-bold text-slate-900">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p class="mt-3 text-sm text-slate-600">' . htmlspecialchars($apiNote, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p class="mt-3 text-sm text-slate-600">Open the channel on YouTube and subscribe if you have not yet.</p>'
        . '<p class="mt-2 text-sm"><a class="font-semibold text-indigo-600 underline" href="' . htmlspecialchars($subUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Open channel on YouTube</a></p>'
        . '<form class="mt-6 space-y-4" method="post" action="' . htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="action" value="confirm_hybrid">'
        . '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">'
        . '<label class="flex items-start gap-2 text-sm text-slate-700"><input type="checkbox" name="subscribe_ack" value="1" class="mt-1">'
        . '<span>I have subscribed to this channel.</span></label>';
    if ($needCode !== '') {
        echo '<div><label class="block text-sm font-medium text-slate-700">Video code</label>'
            . '<input type="text" name="video_code" autocomplete="off" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Enter the code from the video"></div>';
    }
    echo '<button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">Continue to downloads</button>'
        . '</form>'
        . '<p class="mt-4 text-xs text-slate-500">If the YouTube API cannot see your subscription (privacy or limits), this step still lets you continue after you confirm.</p>'
        . '</div></body></html>';
}

/**
 * @return array<string,mixed>|null
 */
function trytest_youtube_http_post_form(string $url, array $fields): ?array
{
    $body = http_build_query($fields, '', '&');
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n",
            'content' => $body,
            'timeout' => 20,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * @return array<string,mixed>|null
 */
function trytest_youtube_http_get_json(string $url, string $bearer): ?array
{
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer {$bearer}\r\nAccept: application/json\r\n",
            'timeout' => 20,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function trytest_youtube_refresh_access_token(string $refreshToken, array $settings): ?string
{
    $res = trytest_youtube_http_post_form('https://oauth2.googleapis.com/token', [
        'client_id' => $settings['client_id'],
        'client_secret' => $settings['client_secret'],
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
    ]);
    if ($res === null) {
        return null;
    }
    $at = trim((string) ($res['access_token'] ?? ''));
    return $at !== '' ? $at : null;
}

function trytest_youtube_user_subscribed_to_channel(string $accessToken, string $channelId): bool
{
    return trytest_youtube_subscription_status($accessToken, $channelId) === 'yes';
}

function trytest_youtube_clear_user_tokens(PDO $db, int $userId): void
{
    $db->prepare('UPDATE users SET youtube_refresh_token = NULL WHERE id = ?')->execute([$userId]);
}

function trytest_youtube_channel_browser_url(string $channelId): string
{
    $c = trim($channelId);
    if ($c === '') {
        return 'https://www.youtube.com';
    }
    return 'https://www.youtube.com/channel/' . rawurlencode($c) . '?sub_confirmation=1';
}

function trytest_pdf_light_gate_session_ok(): bool
{
    $at = (int) ($_SESSION['trytest_pdf_gate_ok_at'] ?? 0);
    return $at > 0 && (time() - $at) <= trytest_youtube_session_ttl();
}

function trytest_pdf_light_gate_mark_ok(): void
{
    $_SESSION['trytest_pdf_gate_ok_at'] = time();
}

/**
 * Small promo strip when the PDF / YouTube nudge is enabled (quiz pages, etc.).
 *
 * @param array<string, mixed> $settings trytest_youtube_settings()
 */
function trytest_youtube_promo_banner_html(array $settings): string
{
    if (empty($settings['gate_active']) || trim((string) ($settings['channel_id'] ?? '')) === '') {
        return '';
    }
    $u = htmlspecialchars(trytest_youtube_channel_browser_url((string) $settings['channel_id']), ENT_QUOTES, 'UTF-8');
    return '<div class="rounded-xl bg-gradient-to-r from-red-50 to-amber-50 px-3 py-2 text-center text-[11px] font-medium text-slate-800 ring-1 ring-red-100">'
        . 'Past papers &amp; weekly PDF drops — <a class="font-bold text-red-700 underline decoration-red-300 underline-offset-2" href="'
        . $u . '" target="_blank" rel="noopener">subscribe on YouTube</a></div>';
}

/**
 * HTML interstitial: value-first copy, YouTube link, optional video code, low-friction continue.
 *
 * @param array<string, mixed> $settings
 */
function trytest_render_pdf_download_gate(int $docId, string $docTitle, array $settings, string $error): void
{
    require_once __DIR__ . '/trytest_urls.php';
    $ch = trim((string) ($settings['channel_id'] ?? ''));
    $ytUrl = htmlspecialchars(trytest_youtube_channel_browser_url($ch), ENT_QUOTES, 'UTF-8');
    $postTarget = htmlspecialchars(trytest_url('download_resource?id=' . $docId), ENT_QUOTES, 'UTF-8');
    $codeConfigured = trim((string) ($settings['pdf_unlock_code'] ?? '')) !== '';
    $err = trim($error);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Unlock PDF · Trytest</title><script src="https://cdn.tailwindcss.com"></script></head>'
        . '<body class="min-h-screen bg-slate-50 p-4 text-slate-900">'
        . '<div class="mx-auto mt-6 max-w-md rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">'
        . '<p class="text-center text-xs font-bold uppercase tracking-widest text-red-600">Trytest materials</p>'
        . '<h1 class="mt-2 text-center text-lg font-bold leading-snug">Free exam questions + answers PDF <span class="text-slate-600">(updated weekly)</span></h1>'
        . '<p class="mt-2 text-center text-sm text-slate-600">' . htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p class="mt-4 text-center text-sm text-slate-700">Subscribe to the channel for more past papers — then unlock your download below.</p>';
    if ($err !== '') {
        echo '<p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-center text-sm text-red-800">' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    if ($ch !== '') {
        echo '<div class="mt-5 flex flex-col gap-2">'
            . '<a href="' . $ytUrl . '" target="_blank" rel="noopener" class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-red-600 py-3 text-sm font-bold text-white shadow-sm hover:bg-red-700">Open YouTube channel</a>'
            . '<p class="text-center text-[10px] text-slate-500">Opens in a new tab — subscribe if you like the content.</p></div>';
    } else {
        echo '<p class="mt-4 text-center text-xs text-amber-800">Ask your teacher to add the YouTube channel ID in Admin → YouTube gate.</p>';
    }
    if ($codeConfigured) {
        echo '<form method="post" action="' . $postTarget . '" class="mt-6 space-y-2">'
            . '<input type="hidden" name="pdf_gate_action" value="unlock_code">'
            . '<label class="block text-xs font-medium text-slate-600">Code from the latest video</label>'
            . '<input type="text" name="unlock_code" autocomplete="off" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm" placeholder="e.g. TRYTEST2026">'
            . '<button type="submit" class="w-full rounded-xl bg-slate-900 py-3 text-sm font-bold text-white">Unlock download</button></form>';
    }
    echo '<form method="post" action="' . $postTarget . '" id="trytestPdfNudgeForm" class="mt-4">'
        . '<input type="hidden" name="pdf_gate_action" value="nudge_continue">'
        . '<button type="button" id="trytestPdfContinueBtn" class="w-full rounded-xl border-2 border-slate-200 bg-white py-3 text-sm font-bold text-slate-800 hover:bg-slate-50">Continue to download</button></form>'
        . '<p class="mt-4 text-center text-[10px] text-slate-500">No Google sign-in. Optional code if your teacher shared one in a video.</p>'
        . '</div><script>(function(){var b=document.getElementById("trytestPdfContinueBtn");var f=document.getElementById("trytestPdfNudgeForm");'
        . 'if(!b||!f)return;var u=' . json_encode($ch !== '' ? trytest_youtube_channel_browser_url($ch) : '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) . ';'
        . 'b.addEventListener("click",function(){if(u)window.open(u,"_blank","noopener");setTimeout(function(){f.submit();},2600);});})();</script>'
        . '</body></html>';
}

/**
 * PDF downloads: only the light nudge (session after Continue / correct code).
 * Google OAuth / YouTube API subscription checks are not used for PDF access.
 */
function trytest_youtube_download_allowed(array $settings): bool
{
    if (empty($settings['gate_active'])) {
        return true;
    }
    return trytest_pdf_light_gate_session_ok();
}
