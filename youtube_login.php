<?php

declare(strict_types=1);

/**
 * OAuth entry point (same flow as youtube_connect.php).
 * Use this URL in docs / links; Google only needs the callback URL registered, not this path.
 */
require_once __DIR__ . '/config/google.php';
require __DIR__ . '/youtube_connect.php';
