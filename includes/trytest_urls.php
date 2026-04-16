<?php

declare(strict_types=1);

/**
 * URL path from web root to this app's folder (e.g. '/trytest') or '' at site root.
 */
function trytest_detect_base_path(): string
{
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    $appRoot = realpath(__DIR__ . '/..');
    if ($docRoot === false || $appRoot === false) {
        return '';
    }
    $doc = str_replace('\\', '/', $docRoot);
    $root = str_replace('\\', '/', $appRoot);
    if (!str_starts_with($root, $doc)) {
        return '';
    }
    $rel = substr($root, strlen($doc));
    $rel = trim($rel, '/');
    return $rel === '' ? '' : '/' . $rel;
}

/**
 * @param array{base_path?: string, domain_root_hosts?: list<string>} $cfg
 */
function trytest_host_prefers_domain_root(array $cfg): bool
{
    $hosts = $cfg['domain_root_hosts'] ?? [];
    if (!is_array($hosts) || $hosts === []) {
        return false;
    }
    $h = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    foreach ($hosts as $allowed) {
        if (!is_string($allowed) || $allowed === '') {
            continue;
        }
        if (strcasecmp($h, $allowed) === 0) {
            return true;
        }
    }
    return false;
}

function trytest_request_path(): string
{
    $p = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $p = '/' . trim(str_replace('\\', '/', (string) $p), '/');
    return $p === '//' ? '/' : $p;
}

/** True when the request targets the public homepage (not /dashboard, /quiz, etc.). */
function trytest_is_app_root_request(): bool
{
    $p = trytest_request_path();
    if ($p === '/index.php') {
        return true;
    }
    $homeRaw = trytest_home_url();
    $base = trytest_base_path();
    if ($base !== '' && $p === $base . '/index.php') {
        return true;
    }
    $home = rtrim($homeRaw, '/');
    if ($home === '' || $home === '/') {
        return $p === '/' || $p === '';
    }
    return $p === $home || $p === $home . '/';
}

/**
 * URL path prefix, e.g. '/trytest' or '' when the app is at the site root.
 */
function trytest_base_path(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $env = getenv('TRYTEST_WEB_BASE');
    if ($env !== false) {
        $base = trim((string) $env, '/');
        $cached = $base === '' ? '' : '/' . $base;
        return $cached;
    }
    $file = __DIR__ . '/../config/app.php';
    if (!is_file($file)) {
        $cached = trytest_detect_base_path();
        return $cached;
    }
    /** @var array{base_path?: string, domain_root_hosts?: list<string>} $cfg */
    $cfg = require $file;
    $raw = isset($cfg['base_path']) ? trim((string) $cfg['base_path']) : 'auto';
    if (strtolower($raw) === 'auto') {
        if (trytest_host_prefers_domain_root($cfg)) {
            $cached = '';
            return $cached;
        }
        $cached = trytest_detect_base_path();
        return $cached;
    }
    $p = rtrim($raw, '/');
    if ($p === '') {
        $cached = '';
        return $cached;
    }
    $cached = $p[0] === '/' ? $p : '/' . $p;
    return $cached;
}

/**
 * Absolute path on this host (starts with /). $path should not start with /.
 */
function trytest_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $b = trytest_base_path();
    if ($b === '') {
        return '/' . $path;
    }
    return $path === '' ? $b : $b . '/' . $path;
}

function trytest_request_origin(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $proto = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $proto . '://' . $host;
}

/**
 * Full URL for the current host + configured base + path (for OAuth redirect URIs, etc.).
 */
function trytest_absolute_url(string $path = ''): string
{
    return trytest_request_origin() . trytest_url($path);
}

/** Site entry (student/admin router): `/` at domain root, or `/trytest` in a subfolder install. */
function trytest_home_url(): string
{
    return trytest_url('');
}

/**
 * Homepage with query string (e.g. `?mode=admin`, `?out=1`).
 *
 * @param array<string, string|int|float|bool|null> $params
 */
function trytest_home_with_query(array $params): string
{
    $q = http_build_query($params);
    $h = trytest_home_url();
    if ($h === '/') {
        return '/?' . $q;
    }
    return rtrim($h, '/') . '/?' . $q;
}
