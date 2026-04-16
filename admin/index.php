<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once dirname(__DIR__) . '/includes/trytest_urls.php';

if (!empty($_SESSION['is_admin'])) {
    trytest_redirect(trytest_url('dashboard'), 302);
}

require dirname(__DIR__) . '/dashboard.php';
