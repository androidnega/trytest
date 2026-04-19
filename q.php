<?php

declare(strict_types=1);

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/quiz_share.php';

$c = isset($_GET['c']) ? trytest_quiz_normalize_share_code((string) $_GET['c']) : '';
$id = trytest_quiz_id_from_share_code($db, $c);
if ($id < 1) {
    trytest_redirect(trytest_home_url());
}

trytest_redirect(trytest_url('share_quiz?s=' . rawurlencode($c)));
