<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/trytest_urls.php';
trytest_admin_logout();

trytest_redirect(trytest_url('admin'));
