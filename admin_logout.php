<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/admin_auth.php';
trytest_admin_logout();

trytest_redirect(trytest_home_with_query(['mode' => 'admin']));
