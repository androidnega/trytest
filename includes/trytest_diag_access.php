<?php

declare(strict_types=1);

/**
 * Shared key + gate for trytest_diagnostics and trytest_opcache_reset. Delete these tools when done.
 * Change the secret in one place here (and deploy).
 */
const TRYTEST_DIAG_SECRET = 'ttdiag_a7f3c9e2b8d14f6a0e4c1b5d9f2a8e3c6b0d4f7a1e5c9b2d6f0a3e7c1b4d8f2a5';

/**
 * @param 'disabled'|'missing'|'invalid' $reason
 */
function trytest_diag_deny(string $reason): void
{
    if (!headers_sent()) {
        $code = $reason === 'disabled' ? 503 : 403;
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
    }
    $body = '<h1>Trytest diagnostics / tools — access not allowed</h1>';
    if ($reason === 'disabled') {
        $body .= '<p>Set a non-empty <code>TRYTEST_DIAG_SECRET</code> in <code>includes/trytest_diag_access.php</code> on the server, then add <code>?k=</code> that value to the URL.</p>';
    } elseif ($reason === 'missing') {
        $body .= '<p>The <code>k</code> query parameter is missing. Some hosts, CDNs, or “privacy” tools strip query strings. Replace <code>SECRET</code> with the value of <code>TRYTEST_DIAG_SECRET</code> in that file.</p>';
        $body .= '<ul><li><code>…/trytest_diagnostics.php?k=SECRET</code></li>';
        $body .= '<li><code>…/index.php?trytest_diag=1&amp;k=SECRET</code></li>';
        $body .= '<li><code>…/trytest_diag/SECRET/</code> — key in the path (needs current <code>.htaccess</code>)</li>';
        $body .= '<li><code>…/trytest_opcache_reset.php?k=SECRET</code> (PHP cache; see also <code>trytest_opcache/SECRET/</code>)</li></ul>';
    } else {
        $body .= '<p><code>k</code> does not match <code>TRYTEST_DIAG_SECRET</code> in <code>includes/trytest_diag_access.php</code> on the server. Copy the key from the repo (no extra spaces or line breaks).</p>';
    }
    $body .= '<p><strong>This is not a generic 404 from the host</strong> — the request reached PHP but the key was refused.</p>';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Trytest tools</title><style>body{font-family:system-ui,sans-serif;max-width:44rem;margin:1.5rem;line-height:1.45}code{background:#f0f0f0;padding:0 .2rem}</style></head><body>' . $body . '</html>';
    exit;
}

function trytest_diag_ensure_key(): void
{
    if (TRYTEST_DIAG_SECRET === '') {
        trytest_diag_deny('disabled');
    }
    $k = (string) ($_GET['k'] ?? '');
    if ($k === '') {
        trytest_diag_deny('missing');
    }
    if (!hash_equals(TRYTEST_DIAG_SECRET, $k)) {
        trytest_diag_deny('invalid');
    }
}
