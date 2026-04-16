<?php

declare(strict_types=1);

/**
 * Public URL path prefix for this installation (no trailing slash).
 *
 * - `auto` (default): derive from DOCUMENT_ROOT vs project root (works for both
 *   subdomain docroot installs and XAMPP `htdocs/tryTest` → `/trytest` URLs).
 * - `''` : force site root (e.g. you know the vhost docroot is this app folder).
 * - `/trytest` : force a subfolder prefix.
 *
 * Server override (Apache): SetEnv TRYTEST_WEB_BASE /trytest  or SetEnv TRYTEST_WEB_BASE ""
 * (handled in includes/trytest_urls.php, not here).
 */
return [
    'base_path' => 'auto',
];
