<?php

declare(strict_types=1);

/**
 * Public URL path prefix for this installation (no trailing slash).
 *
 * - `auto` (recommended): localhost / LAN / *.local → detect subfolder (e.g. `/trytest`);
 *   any other host (production) → always `''` (site root; `/trytest` is not a public path).
 * - `''` : force site root on every host.
 * - `/subdir` : force that URL prefix on every host (only if the app is really served there).
 *
 * Override anywhere: SetEnv TRYTEST_WEB_BASE /subdir  or SetEnv TRYTEST_WEB_BASE ""
 * (handled in includes/trytest_urls.php). On root installs, requests under /trytest/ return 404.
 */
return [
    'base_path' => 'auto',
];
