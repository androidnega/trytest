<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/trytest_urls.php';

header('Location: ' . trytest_home_url(), true, 302);
exit;
