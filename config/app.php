<?php

declare(strict_types=1);

/**
 * Public URL path prefix for this installation (no trailing slash).
 *
 * - `auto` (recommended): detect the URL prefix from the filesystem (app dir under `DOCUMENT_ROOT`),
 *   e.g. `/trytest` for `public_html/trytest/`. On every host so production subfolder installs work.
 *   `trytest` at site root (wrong prefix) is still blocked by trytest_reject_trytest_prefix_when_at_root.
 * - `''` : force site root on every host.
 * - `/subdir` : force that URL prefix on every host (only if the app is really served there).
 *
 * Override anywhere: SetEnv TRYTEST_WEB_BASE /subdir  or SetEnv TRYTEST_WEB_BASE ""
 * (handled in includes/trytest_urls.php). On root installs, requests under /trytest/ return 404.
 */
return [
    'base_path' => 'auto',
    /**
     * Optional WebSocket URL for live quiz presence (Node: realtime/presence-server.mjs).
     * Example: wss://trytest.manuelcode.info/ws-presence (behind reverse proxy).
     * Leave empty to use database pings + Server-Sent Events on admin pages only.
     */
    'presence_ws_url' => '',
    /**
     * Optional full public origin (no trailing slash), e.g. https://trytest.manuelcode.info
     * When set on production, redirects use an absolute URL (some hosts mishandle path-only Location).
     * Leave empty to use path-only redirects (default). Ignored on localhost / LAN / *.local.
     */
    'public_base_url' => '',
];
