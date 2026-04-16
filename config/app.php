<?php

declare(strict_types=1);

/**
 * Public URL path prefix for this installation (no trailing slash).
 *
 * - `auto` (recommended): localhost / LAN / *.local → detect subfolder (e.g. `/trytest`);
 *   any other host (production) → always `''` so URLs never get `/trytest`.
 * - `''` : force site root on every host.
 * - `/trytest` : force that prefix on every host (rare).
 *
 * Override anywhere: SetEnv TRYTEST_WEB_BASE /trytest  or SetEnv TRYTEST_WEB_BASE ""
 * (handled in includes/trytest_urls.php).
 */
return [
    'base_path' => 'auto',
];
