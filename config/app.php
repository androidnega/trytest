<?php

declare(strict_types=1);

/**
 * Public URL path prefix for this installation (no trailing slash).
 *
 * - Subdomain / production (e.g. https://trytest.manuelcode.info/) → use '' (homepage is site root).
 * - XAMPP subfolder (http://localhost/trytest/...) → use '/trytest'
 *
 * Override with environment variable TRYTEST_WEB_BASE (empty string allowed).
 */
$env = getenv('TRYTEST_WEB_BASE');
if ($env !== false) {
    $base = trim($env, '/');
    return ['base_path' => $base === '' ? '' : '/' . $base];
}

return [
    // '' = https://trytest.example.com/ is the app homepage (recommended for trytest.manuelcode.info).
    // '/trytest' = http://localhost/trytest/ when the project lives under htdocs/tryTest.
    // Override: SetEnv TRYTEST_WEB_BASE /trytest
    'base_path' => '',
];
