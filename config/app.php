<?php

declare(strict_types=1);

/**
 * Public URL path prefix for this installation (no trailing slash).
 *
 * - `auto` (default): derive from DOCUMENT_ROOT vs project root (works for both
 *   subdomain docroot installs and XAMPP `htdocs/tryTest` → `/trytest` URLs).
 * - `''` : force site root (use when the live site must be at domain root).
 * - `/trytest` : force a subfolder prefix.
 *
 * Server override (Apache): SetEnv TRYTEST_WEB_BASE /trytest  or SetEnv TRYTEST_WEB_BASE ""
 * (handled in includes/trytest_urls.php, not here).
 *
 * `domain_root_hosts`: when `base_path` is `auto`, treat these HTTP_HOST values as URL
 * root (`''` prefix) even if the app folder sits under DOCUMENT_ROOT. Use when the vhost
 * serves this app at the subdomain root but PHP sees a parent docroot. Add your live host
 * or leave empty for filesystem-only detection.
 */
return [
    // Force live URLs to the subdomain root.
    'base_path' => '',
    'domain_root_hosts' => [],
];
