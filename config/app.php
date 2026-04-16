<?php

declare(strict_types=1);

/**
 * Public URL path prefix for this installation (no trailing slash).
 *
 * - XAMPP typical: document root is htdocs and app is in htdocs/tryTest → use '/trytest'
 * - Subdomain at domain root (e.g. https://trytest.manuelcode.info/dashboard/) → use ''
 *
 * Override with environment variable TRYTEST_WEB_BASE (empty string allowed).
 */
$env = getenv('TRYTEST_WEB_BASE');
if ($env !== false) {
    $base = trim($env, '/');
    return ['base_path' => $base === '' ? '' : '/' . $base];
}

return [
    // Local XAMPP (htdocs/tryTest → http://localhost/trytest/...): keep '/trytest'.
    // Live site at subdomain root (https://trytest.example.com/dashboard/): use ''.
    // Prefer server env: SetEnv TRYTEST_WEB_BASE ""  (empty = root install).
    'base_path' => '/trytest',
];
