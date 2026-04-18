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

/** True for env values like 1, true, yes, on (case-insensitive). */
function trytest_youtube_truthy_env(string $name): bool
{
    $v = getenv($name);
    if ($v === false) {
        return false;
    }
    $v = strtolower(trim((string) $v));

    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

/**
 * @return list<string>
 */
function trytest_split_video_urls(string $raw): array
{
    $parts = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $out = [];
    foreach ($parts as $line) {
        $u = trim((string) $line);
        if ($u === '') {
            continue;
        }
        if (!preg_match('#^https?://#i', $u)) {
            continue;
        }
        $out[] = $u;
    }
    return array_values(array_unique($out));
}

function trytest_youtube_extract_video_id(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $p = parse_url($url);
    if (!is_array($p)) {
        return '';
    }
    $host = strtolower((string) ($p['host'] ?? ''));
    $path = (string) ($p['path'] ?? '');
    if (str_contains($host, 'youtu.be')) {
        $id = trim($path, '/');
        return preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id) === 1 ? $id : '';
    }
    parse_str((string) ($p['query'] ?? ''), $q);
    $v = trim((string) ($q['v'] ?? ''));
    if ($v !== '' && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $v) === 1) {
        return $v;
    }
    if (preg_match('#/(embed|shorts)/([A-Za-z0-9_-]{6,20})#', $path, $m)) {
        return (string) ($m[2] ?? '');
    }
    return '';
}

function trytest_youtube_embed_url(string $videoUrl): string
{
    $id = trytest_youtube_extract_video_id($videoUrl);
    return $id === '' ? '' : ('https://www.youtube.com/embed/' . rawurlencode($id) . '?rel=0&autoplay=1&mute=1');
}

/** Same embed behaviour as in-quiz video breaks (autoplay, not forced mute). */
function trytest_youtube_embed_url_quiz_style(string $videoUrl): string
{
    $id = trytest_youtube_extract_video_id($videoUrl);
    return $id === '' ? '' : ('https://www.youtube.com/embed/' . rawurlencode($id) . '?rel=0&autoplay=1');
}

/**
 * First video URL for the downloads subscribe screen (quiz ads, else dashboard clips).
 *
 * @param array<string, mixed> $settings trytest_youtube_settings()
 */
function trytest_youtube_downloads_gate_pick_video_url(array $settings): string
{
    foreach ((array) ($settings['quiz_ad_videos'] ?? []) as $u) {
        $s = trim((string) $u);
        if ($s !== '') {
            return $s;
        }
    }
    foreach ((array) ($settings['dashboard_videos'] ?? []) as $u) {
        $s = trim((string) $u);
        if ($s !== '') {
            return $s;
        }
    }

    return '';
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
 *   pdf_gate_from_config:bool,
 *   credentials_complete:bool,
 *   oauth_gate_ready:bool,
 *   pdf_unlock_code:string,
 *   ...
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
    $dDashboardVideosEnabled = false;
    $dDashboardVideoUrls = '';
    $dQuizAdEnabled = false;
    $dQuizAdEveryN = 20;
    $dQuizAdWatchSeconds = 20;
    $dQuizAdVideoUrls = '';

    global $db;
    if (isset($db) && $db instanceof PDO) {
        try {
            $st = $db->query(
                'SELECT gate_enabled, client_id, client_secret, redirect_uri, channel_id, pdf_unlock_code, dashboard_videos_enabled, dashboard_video_urls, quiz_ad_enabled, quiz_ad_every_n, quiz_ad_watch_seconds, quiz_ad_video_urls FROM youtube_app_settings WHERE id = 1'
            );
            $r = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($r)) {
                $rowGate = ((int) ($r['gate_enabled'] ?? 0)) === 1;
                $dClient = trim((string) ($r['client_id'] ?? ''));
                $dSecret = trim((string) ($r['client_secret'] ?? ''));
                $dRedirect = trim((string) ($r['redirect_uri'] ?? ''));
                $dChannel = trim((string) ($r['channel_id'] ?? ''));
                $dPdfCode = trim((string) ($r['pdf_unlock_code'] ?? ''));
                $dDashboardVideosEnabled = ((int) ($r['dashboard_videos_enabled'] ?? 0)) === 1;
                $dDashboardVideoUrls = trim((string) ($r['dashboard_video_urls'] ?? ''));
                $dQuizAdEnabled = ((int) ($r['quiz_ad_enabled'] ?? 0)) === 1;
                $dQuizAdEveryN = max(1, (int) ($r['quiz_ad_every_n'] ?? 20));
                $dQuizAdWatchSeconds = max(5, (int) ($r['quiz_ad_watch_seconds'] ?? 20));
                $dQuizAdVideoUrls = trim((string) ($r['quiz_ad_video_urls'] ?? ''));
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
    // Light PDF gate: admin DB toggle, OR config/env when channel is known (covers env-only production installs).
    $filePdfGate = !empty($file['pdf_gate_enabled']) || trytest_youtube_truthy_env('TRYTEST_YOUTUBE_PDF_GATE');
    $pdfGateFromConfig = $filePdfGate && $channelId !== '';
    $gateActive = $rowGate || $pdfGateFromConfig;
    $dashboardVideosEnabled = $dDashboardVideosEnabled || !empty($file['dashboard_videos_enabled']) || trytest_youtube_truthy_env('TRYTEST_DASHBOARD_VIDEOS_ENABLED');
    $dashboardVideoUrls = $dDashboardVideoUrls !== '' ? $dDashboardVideoUrls : trim((string) ($file['dashboard_video_urls'] ?? ''));
    $quizAdEnabled = $dQuizAdEnabled || !empty($file['quiz_ad_enabled']) || trytest_youtube_truthy_env('TRYTEST_QUIZ_AD_ENABLED');
    $quizAdEveryN = $dQuizAdEveryN > 0 ? $dQuizAdEveryN : (int) ($file['quiz_ad_every_n'] ?? 20);
    $quizAdWatchSeconds = $dQuizAdWatchSeconds > 0 ? $dQuizAdWatchSeconds : (int) ($file['quiz_ad_watch_seconds'] ?? 20);
    if ($quizAdEveryN < 1) {
        $quizAdEveryN = 20;
    }
    if ($quizAdWatchSeconds < 5) {
        $quizAdWatchSeconds = 20;
    }
    $quizAdVideoUrls = $dQuizAdVideoUrls !== '' ? $dQuizAdVideoUrls : trim((string) ($file['quiz_ad_video_urls'] ?? ''));
    $dashboardVideos = trytest_split_video_urls($dashboardVideoUrls);
    $quizAdVideos = trytest_split_video_urls($quizAdVideoUrls);
    $quizAdEnabled = $quizAdEnabled && $quizAdVideos !== [];
    $dashboardVideosEnabled = $dashboardVideosEnabled && $dashboardVideos !== [];

    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'channel_id' => $channelId,
        'pdf_unlock_code' => $pdfUnlockCode,
        'gate_enabled' => $rowGate,
        'gate_active' => $gateActive,
        'pdf_gate_from_config' => $pdfGateFromConfig,
        'credentials_complete' => $credentialsComplete,
        'oauth_gate_ready' => $rowGate && $credentialsComplete,
        'dashboard_videos_enabled' => $dashboardVideosEnabled,
        'dashboard_video_urls' => $dashboardVideoUrls,
        'dashboard_videos' => $dashboardVideos,
        'quiz_ad_enabled' => $quizAdEnabled,
        'quiz_ad_every_n' => $quizAdEveryN,
        'quiz_ad_watch_seconds' => $quizAdWatchSeconds,
        'quiz_ad_video_urls' => $quizAdVideoUrls,
        'quiz_ad_videos' => $quizAdVideos,
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
 * Process POST for the light PDF gate (same fields as download_resource).
 * On success, redirects to $successRedirect and exits.
 *
 * @param array<string, mixed> $settings trytest_youtube_settings()
 * @return null|string null if this request was not a gate unlock POST; non-empty string = error message
 */
function trytest_pdf_light_gate_process_unlock_post(array $settings, string $successRedirect): ?string
{
    if (empty($settings['gate_active']) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return null;
    }
    $act = (string) ($_POST['pdf_gate_action'] ?? '');
    if ($act !== 'unlock_code' && $act !== 'nudge_continue') {
        return null;
    }
    require_once __DIR__ . '/trytest_urls.php';
    if ($act === 'unlock_code') {
        $code = trim((string) ($_POST['unlock_code'] ?? ''));
        $expect = trim((string) ($settings['pdf_unlock_code'] ?? ''));
        if ($expect !== '' && strcasecmp($code, $expect) === 0) {
            trytest_pdf_light_gate_mark_ok();
            trytest_redirect($successRedirect);
        }

        return $expect === '' ? 'No video code is set yet — use Continue below.' : 'That code does not match the one in the video.';
    }
    trytest_pdf_light_gate_mark_ok();
    trytest_redirect($successRedirect);
}

/**
 * Prominent call-to-action on the Downloads page when the PDF gate is on.
 *
 * @param array<string, mixed> $settings trytest_youtube_settings()
 */
function trytest_youtube_downloads_activation_panel_html(array $settings, string $formActionUrl, string $gateError): string
{
    if (empty($settings['gate_active'])) {
        return '';
    }
    require_once __DIR__ . '/trytest_urls.php';
    if (trytest_youtube_download_allowed($settings)) {
        return '<div id="trytest-downloads-yt-gate" class="scroll-mt-24 mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-center text-sm font-medium text-emerald-900">'
            . 'Files are unlocked on this device for about a week.</div>';
    }
    $ch = trim((string) ($settings['channel_id'] ?? ''));
    $action = htmlspecialchars($formActionUrl, ENT_QUOTES, 'UTF-8');
    $ytUrl = htmlspecialchars(trytest_youtube_channel_browser_url($ch), ENT_QUOTES, 'UTF-8');
    $codeConfigured = trim((string) ($settings['pdf_unlock_code'] ?? '')) !== '';
    $err = trim($gateError);
    $videoPick = trytest_youtube_downloads_gate_pick_video_url($settings);
    $embed = trytest_youtube_embed_url_quiz_style($videoPick);
    $out = '<div id="trytest-downloads-yt-gate" class="scroll-mt-24">';
    $out .= '<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">';
    $out .= '<div class="border-b border-slate-100 px-4 py-3 text-center">';
    $out .= '<p class="text-[10px] font-bold uppercase tracking-widest text-[#E50914]">Subscribe</p>';
    $out .= '<h2 class="mt-1 text-base font-bold text-slate-900">Watch, then tap continue</h2></div>';
    $out .= '<div class="space-y-4 p-4">';
    if ($err !== '') {
        $out .= '<p class="rounded-lg bg-red-50 px-3 py-2 text-center text-xs font-medium text-red-900">' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    if ($embed !== '') {
        $out .= '<div class="overflow-hidden rounded-xl border border-slate-200 bg-black">'
            . '<div class="aspect-video w-full"><iframe class="h-full w-full" src="' . htmlspecialchars($embed, ENT_QUOTES, 'UTF-8')
            . '" title="Channel video" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div></div>';
    } elseif ($ch !== '') {
        $out .= '<div class="rounded-xl border border-slate-200 bg-slate-50 py-10 text-center text-sm text-slate-600">'
            . '<a href="' . $ytUrl . '" target="_blank" rel="noopener" class="font-bold text-red-600 underline">Open the channel on YouTube</a>'
            . '<p class="mt-2 text-xs text-slate-500">No preview video is configured yet — your teacher can add quiz or dashboard video URLs in Admin → YouTube.</p></div>';
    } else {
        $out .= '<p class="rounded-lg bg-amber-50 px-3 py-3 text-center text-xs text-amber-900">Ask your teacher to finish YouTube setup (channel and optional video URLs).</p>';
    }
    if ($ch !== '') {
        $out .= '<a href="' . $ytUrl . '" target="_blank" rel="noopener" class="flex w-full items-center justify-center gap-2 rounded-xl bg-red-600 py-3 text-sm font-bold text-white shadow-sm hover:bg-red-700">Open YouTube</a>';
    }
    if ($codeConfigured) {
        $out .= '<details class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs text-slate-600">'
            . '<summary class="cursor-pointer font-semibold text-slate-800">Have a code?</summary>'
            . '<form method="post" action="' . $action . '" class="mt-3 space-y-2">'
            . '<input type="hidden" name="pdf_gate_action" value="unlock_code">'
            . '<input type="text" name="unlock_code" autocomplete="off" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Code from the video">'
            . '<button type="submit" class="w-full rounded-lg bg-slate-900 py-2.5 text-sm font-bold text-white">Unlock with code</button></form></details>';
    }
    $out .= '<form method="post" action="' . $action . '" id="trytestDownloadsNudgeForm" class="space-y-2">'
        . '<input type="hidden" name="pdf_gate_action" value="nudge_continue">'
        . '<button type="button" id="trytestDownloadsContinueBtn" disabled class="w-full rounded-xl bg-slate-300 py-3 text-sm font-bold text-white">Continue in 5s</button>'
        . '<p class="text-center text-[10px] text-slate-500">After subscribing, continue here — you will land on your file list.</p></form>';
    $out .= '</div></div></div>';
    $out .= '<script>(function(){var b=document.getElementById("trytestDownloadsContinueBtn");var f=document.getElementById("trytestDownloadsNudgeForm");if(!b||!f)return;var t=5;var iv=setInterval(function(){t--;if(t>0){b.textContent="Continue in "+t+"s";return;}clearInterval(iv);b.disabled=false;b.textContent="I have subscribed — continue";b.className="w-full rounded-xl bg-[#2C6A7D] py-3 text-sm font-bold text-white shadow-sm hover:bg-[#24586a]";},1000);b.addEventListener("click",function(){if(b.disabled)return;f.submit();});})();</script>';

    return $out;
}

/**
 * When the PDF gate is off but a channel is configured — still show subscribe support on Downloads.
 *
 * @param array<string, mixed> $settings trytest_youtube_settings()
 */
function trytest_youtube_downloads_soft_promo_html(array $settings): string
{
    if (!empty($settings['gate_active'])) {
        return '';
    }
    $ch = trim((string) ($settings['channel_id'] ?? ''));
    if ($ch === '') {
        return '';
    }
    require_once __DIR__ . '/trytest_urls.php';
    $u = htmlspecialchars(trytest_youtube_channel_browser_url($ch), ENT_QUOTES, 'UTF-8');

    return '<div class="mb-4 rounded-xl border border-red-100 bg-gradient-to-r from-red-50 to-amber-50 px-4 py-3 text-center shadow-sm ring-1 ring-red-100/80">'
        . '<p class="text-xs font-semibold text-slate-900">Past papers &amp; weekly drops on YouTube</p>'
        . '<p class="mt-1 text-[11px] text-slate-600">Subscribe so you do not miss new PDFs and quizzes.</p>'
        . '<a href="' . $u . '" target="_blank" rel="noopener" class="mt-2 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-red-600 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-red-700">'
        . '<span aria-hidden="true">▶</span> Open channel &amp; subscribe</a></div>';
}

/**
 * @deprecated Pop-up gate removed; subscribe flow is inline on the downloads page.
 *
 * @param array<string, mixed> $settings trytest_youtube_settings()
 */
function trytest_youtube_downloads_locked_modal_html(array $settings): string
{
    unset($settings);

    return '';
}

/**
 * Thank-you strip after a completed quiz (dashboard) when a channel is configured.
 *
 * @param array<string, mixed> $settings trytest_youtube_settings()
 */
function trytest_youtube_quiz_complete_subscribe_html(array $settings): string
{
    $ch = trim((string) ($settings['channel_id'] ?? ''));
    if ($ch === '') {
        return '';
    }
    $u = htmlspecialchars(trytest_youtube_channel_browser_url($ch), ENT_QUOTES, 'UTF-8');
    $label = !empty($settings['gate_active'])
        ? 'Subscribe for more quizzes, PDFs, and updates.'
        : 'Subscribe on YouTube for more quizzes and updates.';

    return '<div class="rounded-2xl border border-red-100 bg-gradient-to-br from-red-50 via-white to-amber-50 px-4 py-4 text-center shadow-sm ring-1 ring-red-100/80">'
        . '<p class="text-xs font-bold uppercase tracking-wider text-red-600">Thank you</p>'
        . '<p class="mt-1 text-sm font-semibold text-slate-900">Show some love — subscribe to my YouTube channel</p>'
        . '<p class="mt-1 text-xs leading-relaxed text-slate-600">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<a href="' . $u . '" target="_blank" rel="noopener" class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-red-600 py-3 text-sm font-bold text-white shadow-sm hover:bg-red-700">'
        . '<span aria-hidden="true">▶</span> Open YouTube &amp; subscribe</a>'
        . '</div>';
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
 * Student dashboard YouTube gallery block.
 *
 * @param array<string,mixed> $settings
 */
function trytest_youtube_dashboard_videos_html(array $settings, bool $compactLayout = false): string
{
    if (empty($settings['dashboard_videos_enabled'])) {
        return '';
    }
    $videos = $settings['dashboard_videos'] ?? [];
    if (!is_array($videos) || $videos === []) {
        return '';
    }
    $cards = '';
    foreach ($videos as $idx => $u) {
        $url = trim((string) $u);
        $embed = trytest_youtube_embed_url($url);
        if ($embed === '') {
            continue;
        }
        $cards .= '<article class="overflow-hidden rounded-lg border border-slate-200 bg-white">'
            . '<div class="aspect-video w-full bg-slate-50">'
            . '<iframe class="h-full w-full" src="' . htmlspecialchars($embed, ENT_QUOTES, 'UTF-8') . '" title="Trytest video ' . (int) ($idx + 1) . '" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>'
            . '</div>'
            . '<div class="border-t border-slate-100 px-3 py-2 text-right"><a class="text-xs font-medium text-slate-700 hover:text-slate-900 hover:underline" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Open on YouTube</a></div>'
            . '</article>';
    }
    if ($cards === '') {
        return '';
    }
    $sectionClass = $compactLayout
        ? 'rounded-xl border border-slate-200 bg-white p-2.5 shadow-none'
        : 'mb-4 rounded-xl border border-slate-200 bg-white p-3 shadow-none sm:p-4';
    $gridClass = $compactLayout
        ? 'grid grid-cols-1 gap-2'
        : 'grid grid-cols-1 gap-3 sm:grid-cols-2';

    return '<section class="' . htmlspecialchars($sectionClass, ENT_QUOTES, 'UTF-8') . '" aria-label="Dashboard videos">'
        . '<div class="mb-2 flex items-center justify-between gap-2">'
        . '<h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Videos</h2>'
        . '<span class="text-[10px] font-medium uppercase tracking-wide text-slate-400">YouTube</span></div>'
        . '<div class="' . htmlspecialchars($gridClass, ENT_QUOTES, 'UTF-8') . '">' . $cards . '</div>'
        . '</section>';
}

/**
 * @param array<string,mixed> $settings
 * @return array{enabled:bool,every:int,watch_seconds:int,videos:list<string>}
 */
function trytest_youtube_quiz_ad_config(array $settings): array
{
    $videos = [];
    foreach ((array) ($settings['quiz_ad_videos'] ?? []) as $u) {
        $s = trim((string) $u);
        if ($s !== '') {
            $videos[] = $s;
        }
    }
    $enabled = !empty($settings['quiz_ad_enabled']) && $videos !== [];
    $every = max(1, (int) ($settings['quiz_ad_every_n'] ?? 20));
    $watchSeconds = max(5, (int) ($settings['quiz_ad_watch_seconds'] ?? 20));
    return [
        'enabled' => $enabled,
        'every' => $every,
        'watch_seconds' => $watchSeconds,
        'videos' => $videos,
    ];
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
    $videoPick = trytest_youtube_downloads_gate_pick_video_url($settings);
    $embed = trytest_youtube_embed_url_quiz_style($videoPick);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Subscribe · Trytest</title><script src="https://cdn.tailwindcss.com"></script></head>'
        . '<body class="min-h-screen bg-slate-50 p-4 text-slate-900">'
        . '<div class="mx-auto mt-4 max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm ring-1 ring-slate-200">'
        . '<div class="border-b border-slate-100 px-4 py-3 text-center">'
        . '<p class="text-[10px] font-bold uppercase tracking-widest text-[#E50914]">Subscribe</p>'
        . '<h1 class="mt-1 text-base font-bold text-slate-900">Watch, then continue</h1></div>'
        . '<div class="space-y-4 p-4">';
    if ($err !== '') {
        echo '<p class="rounded-lg bg-red-50 px-3 py-2 text-center text-xs text-red-800">' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    if ($embed !== '') {
        echo '<div class="overflow-hidden rounded-xl border border-slate-200 bg-black"><div class="aspect-video w-full">'
            . '<iframe class="h-full w-full" src="' . htmlspecialchars($embed, ENT_QUOTES, 'UTF-8') . '" title="Video" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>'
            . '</div></div>';
    }
    if ($ch !== '') {
        echo '<a href="' . $ytUrl . '" target="_blank" rel="noopener" class="flex w-full items-center justify-center gap-2 rounded-xl bg-red-600 py-3 text-sm font-bold text-white shadow-sm hover:bg-red-700">Open YouTube</a>';
    }
    if ($codeConfigured) {
        echo '<details class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs text-slate-600">'
            . '<summary class="cursor-pointer font-semibold text-slate-800">Have a code?</summary>'
            . '<form method="post" action="' . $postTarget . '" class="mt-3 space-y-2">'
            . '<input type="hidden" name="pdf_gate_action" value="unlock_code">'
            . '<input type="text" name="unlock_code" autocomplete="off" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Code">'
            . '<button type="submit" class="w-full rounded-lg bg-slate-900 py-2.5 text-sm font-bold text-white">Continue with code</button></form></details>';
    }
    echo '<form method="post" action="' . $postTarget . '" id="trytestPdfNudgeForm" class="space-y-2">'
        . '<input type="hidden" name="pdf_gate_action" value="nudge_continue">'
        . '<button type="button" id="trytestPdfContinueBtn" disabled class="w-full rounded-xl bg-slate-300 py-3 text-sm font-bold text-white">Continue in 5s</button>'
        . '</form><p class="text-center text-[10px] text-slate-500">Then your file: ' . htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') . '</p>'
        . '</div></div>'
        . '<p class="mx-auto mt-4 max-w-md text-center text-xs"><a class="font-semibold text-[#2C6A7D] underline" href="' . htmlspecialchars(trytest_url('downloads'), ENT_QUOTES, 'UTF-8') . '">Back to files</a></p>'
        . '<script>(function(){var b=document.getElementById("trytestPdfContinueBtn");var f=document.getElementById("trytestPdfNudgeForm");if(!b||!f)return;var t=5;var iv=setInterval(function(){t--;if(t>0){b.textContent="Continue in "+t+"s";return;}clearInterval(iv);b.disabled=false;b.textContent="I have subscribed — continue";b.className="w-full rounded-xl bg-[#2C6A7D] py-3 text-sm font-bold text-white";},1000);b.addEventListener("click",function(){if(b.disabled)return;f.submit();});})();</script>'
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
