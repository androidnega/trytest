<?php

declare(strict_types=1);

/**
 * Admin sign-in entry (served at URL /admin via .htaccess rewrite).
 * Kept at document root as admin_login.php so hosts that block or mishandle an /admin/ directory still work.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/trytest_urls.php';

if (!empty($_SESSION['is_admin'])) {
    trytest_redirect(trytest_url('dashboard'), 302);
}
if (!empty($_SESSION['user_id'])) {
    trytest_redirect(trytest_url('dashboard'), 302);
}

require __DIR__ . '/dashboard.php';
