<?php

declare(strict_types=1);

// Server diagnostics (works if trytest_diagnostics.php is blocked as a direct URL; needs ?k= from TRYTEST_DIAG_SECRET)
if (isset($_GET['trytest_diag']) && (string) $_GET['trytest_diag'] === '1') {
    require __DIR__ . '/trytest_diagnostics.php';
    exit;
}

require_once __DIR__ . '/includes/trytest_urls.php';

require __DIR__ . '/dashboard/index.php';
