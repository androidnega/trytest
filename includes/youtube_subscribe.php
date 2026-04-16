<?php

declare(strict_types=1);

/**
 * Load merged settings: database row (admin UI) overrides file/env fallbacks.
 *
 * @return array{
 *   client_id:string,
 *   client_secret:string,
 *   redirect_uri:string,
 *   channel_id:string,
 *   gate_enabled:bool,
 *   gate_active:bool,
 *   credentials_complete:bool
 * }
 */
function trytest_youtube_settings(): array
{
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

    global $db;
    if (isset($db) && $db instanceof PDO) {
        try {
            $st = $db->query(
                'SELECT gate_enabled, client_id, client_secret, redirect_uri, channel_id FROM youtube_app_settings WHERE id = 1'
            );
            $r = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($r)) {
                $rowGate = ((int) ($r['gate_enabled'] ?? 0)) === 1;
                $dClient = trim((string) ($r['client_id'] ?? ''));
                $dSecret = trim((string) ($r['client_secret'] ?? ''));
                $dRedirect = trim((string) ($r['redirect_uri'] ?? ''));
                $dChannel = trim((string) ($r['channel_id'] ?? ''));
            }
        } catch (Throwable $e) {
            // table missing on very old DB — fall back to file only
        }
    }

    $clientId = $dClient !== '' ? $dClient : $fClient;
    $clientSecret = $dSecret !== '' ? $dSecret : $fSecret;
    $redirectUri = $dRedirect !== '' ? $dRedirect : $fRedirect;
    $channelId = $dChannel !== '' ? $dChannel : $fChannel;

    $credentialsComplete = $clientId !== '' && $clientSecret !== '' && $redirectUri !== '' && $channelId !== '';
    $gateActive = $rowGate && $credentialsComplete;

    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'channel_id' => $channelId,
        'gate_enabled' => $rowGate,
        'gate_active' => $gateActive,
        'credentials_complete' => $credentialsComplete,
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
    $q = http_build_query([
        'part' => 'id',
        'mine' => 'true',
        'forChannelId' => $channelId,
    ], '', '&', PHP_QUERY_RFC3986);
    $url = 'https://www.googleapis.com/youtube/v3/subscriptions?' . $q;
    $json = trytest_youtube_http_get_json($url, $accessToken);
    if ($json === null) {
        return false;
    }
    $items = $json['items'] ?? [];
    return is_array($items) && count($items) > 0;
}

function trytest_youtube_clear_user_tokens(PDO $db, int $userId): void
{
    $db->prepare('UPDATE users SET youtube_refresh_token = NULL WHERE id = ?')->execute([$userId]);
}

/**
 * Uses stored refresh token to confirm the student is still subscribed to your channel.
 */
function trytest_youtube_download_allowed(PDO $db, int $userId, array $settings): bool
{
    if (empty($settings['gate_active'])) {
        return true;
    }
    $stmt = $db->prepare('SELECT youtube_refresh_token FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $rt = trim((string) ($stmt->fetchColumn() ?: ''));
    if ($rt === '') {
        return false;
    }
    $access = trytest_youtube_refresh_access_token($rt, $settings);
    if ($access === null) {
        trytest_youtube_clear_user_tokens($db, $userId);
        return false;
    }
    $ok = trytest_youtube_user_subscribed_to_channel($access, $settings['channel_id']);
    if (!$ok) {
        return false;
    }
    return true;
}
