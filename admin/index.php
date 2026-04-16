<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/trytest_urls.php';

trytest_redirect(trytest_url('dashboard'), 302);
