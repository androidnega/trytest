<?php

declare(strict_types=1);

/**
 * URL path prefix, e.g. '/trytest' or '' when the app is at the site root.
 */
function trytest_base_path(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $file = __DIR__ . '/../config/app.php';
    $default = '/trytest';
    if (!is_file($file)) {
        $cached = $default;
        return $cached;
    }
    /** @var array{base_path?: string} $cfg */
    $cfg = require $file;
    $p = isset($cfg['base_path']) ? trim((string) $cfg['base_path']) : $default;
    $p = rtrim($p, '/');
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
