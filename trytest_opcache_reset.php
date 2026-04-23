<?php

declare(strict_types=1);

/**
 * Clear PHP OPcache (and APCu user cache if enabled) on the live server. Same ?k= as diagnostics.
 * Does NOT clear reverse-proxy, LiteSpeed, Cloudflare, or browser caches — use the host/CDN panel for those.
 * DELETE this file when finished.
 *
 * Open: trytest_opcache_reset.php?k=SECRET
 *   or: /trytest_opcache/SECRET/  (if .htaccess has the rewrite)
 */

require_once __DIR__ . '/includes/trytest_diag_access.php';
trytest_diag_ensure_key();

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

$lines = [];

if (function_exists('opcache_reset') && opcache_reset()) {
    $lines[] = 'OPcache: reset OK.';
} else {
    $lines[] = 'OPcache: not available, not enabled, or reset failed (normal on some shared hosts).';
}

if (function_exists('apcu_clear_cache') && apcu_clear_cache()) {
    $lines[] = 'APCu user cache: cleared.';
} else {
    $lines[] = 'APCu: not in use or clear skipped.';
}

$lines[] = 'Remove trytest_opcache_reset.php and includes/trytest_diag_access.php when you no longer need these tools.';

$body = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>OPcache / APCu</title><style>body{font-family:system-ui,sans-serif;max-width:40rem;margin:1.5rem}li{margin:.35rem 0}code{background:#f0f0f0}</style></head><body><h1>Server-side PHP cache</h1><ol>';
foreach ($lines as $l) {
    $body .= '<li>' . htmlspecialchars($l, ENT_QUOTES, 'UTF-8') . '</li>';
}
$body .= '</ol><p><strong>Host / CDN (not cleared here):</strong> cPanel <em>LiteSpeed</em> cache, <em>Cloudflare</em> (Caching → Purge), or your provider’s “clear cache” must be done in those dashboards if pages still look stale.</p></body></html>';
echo $body;
exit;
