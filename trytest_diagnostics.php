<?php

declare(strict_types=1);

/**
 * Server diagnostics for Trytest URL / admin routing. DELETE this file when finished.
 *
 * URL to open (one slash only after the host, no //):
 *
 *  · Direct:  …/trytest_diagnostics.php?k=SECRET
 *  · Home:    …/index.php?trytest_diag=1&k=SECRET  (or …/?trytest_diag=1&k=SECRET)
 *  · No query (if host strips it): …/trytest_diag/SECRET/  (see .htaccess)
 *
 * If the app is at the domain root, do not add a /tryTest/ folder in the URL unless the app really lives there.
 * Double slashes (//) after the host break the path. Remove this file on the server when finished.
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
    $body = '<h1>Trytest diagnostics — access not allowed</h1>';
    if ($reason === 'disabled') {
        $body .= '<p>Set a non-empty <code>TRYTEST_DIAG_SECRET</code> in <code>trytest_diagnostics.php</code> on the server, then add <code>?k=</code> that value to the URL.</p>';
    } elseif ($reason === 'missing') {
        $body .= '<p>The <code>k</code> query parameter is missing. Some hosts, CDNs, or “privacy” tools strip query strings; use one of the forms below (replace <code>SECRET</code> with the value of <code>TRYTEST_DIAG_SECRET</code> in this file).</p>';
        $body .= '<ul><li><code>…/trytest_diagnostics.php?k=SECRET</code></li>';
        $body .= '<li><code>…/index.php?trytest_diag=1&amp;k=SECRET</code></li>';
        $body .= '<li><code>…/trytest_diag/SECRET/</code> — key in the path (needs current <code>.htaccess</code>)</li></ul>';
    } else {
        $body .= '<p><code>k</code> does not match <code>TRYTEST_DIAG_SECRET</code> in <code>trytest_diagnostics.php</code> on the server. Copy the key from the file in the repository (no extra spaces or line breaks).</p>';
    }
    $body .= '<p><strong>This page is not a generic 404 from the host</strong> — the diagnostics script is running but refused the key.</p>';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Trytest diagnostics</title><style>body{font-family:system-ui,sans-serif;max-width:44rem;margin:1.5rem;line-height:1.45}code{background:#f0f0f0;padding:0 .2rem}</style></head><body>' . $body . '</html>';
    exit;
}

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

// Allow loading trytest_urls.php without 301/404 at end of that file
define('TRYTEST_SKIP_INIT_REDIRECTS', true);
require_once __DIR__ . '/includes/trytest_urls.php';

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$base = trytest_base_path();
$detect = function_exists('trytest_detect_base_path') ? trytest_detect_base_path() : 'n/a';
$adminPath = $base === '' ? '/admin' : rtrim($base, '/') . '/admin';
$uriPath = trytest_request_path();
$isAdminEntry = trytest_is_admin_entry_request();
$isDashRoot = trytest_is_dashboard_root_request();
$isAppRoot = trytest_is_app_root_request();

$appPhp = __DIR__ . '/config/app.php';
// app.php may already be required by trytest_base_path(); re-require runs the file again (idempotent return-only config).
$cfgForDiag = is_file($appPhp) ? require $appPhp : null;
$cfgBase = is_array($cfgForDiag) && isset($cfgForDiag['base_path'])
    ? (string) $cfgForDiag['base_path']
    : 'missing';

$envBase = getenv('TRYTEST_WEB_BASE');
$envBaseDisp = $envBase !== false ? (string) $envBase : '(not set)';

$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
$docRootReal = $docRoot !== '' && is_dir($docRoot) ? @realpath($docRoot) : false;
$appRootReal = @realpath(__DIR__);
$suggestions = [];

if ($isAdminEntry === false && (str_ends_with($uriPath, '/admin') || preg_match('#/admin$#', $uriPath) === 1)) {
    $suggestions[] = 'Request path ends with /admin but trytest_is_admin_entry_request() is false — check base_path / TRYTEST_WEB_BASE match your public URL (see detected vs request path).';
}
if ($base === '' && $docRootReal !== false && $appRootReal !== false) {
    $d = rtrim(str_replace('\\', '/', (string) $docRootReal), '/');
    $a = rtrim(str_replace('\\', '/', (string) $appRootReal), '/');
    if ($a !== $d && str_starts_with($a, $d) && $a !== $d) {
        $suggestions[] = 'App lives under document root in a subfolder, but trytest_base_path() is empty. Set base_path in config/app.php to that URL segment or set the TRYTEST_WEB_BASE environment variable.';
    }
}
if ($base !== '' && $isAdminEntry === true) {
    $suggestions[] = 'Admin entry is recognized for this request. If /admin still misbehaves in the browser, check .htaccess (mod_rewrite), a physical /admin/ directory, or a host "security" feature blocking /admin.';
}

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$adminSession = !empty($_SESSION['is_admin']);

$selfScript = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest — Server diagnostics</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 1rem; max-width: 56rem; }
        h1 { font-size: 1.25rem; }
        h2 { font-size: 1rem; margin-top: 1.5rem; }
        table { border-collapse: collapse; width: 100%; font-size: 0.875rem; }
        th, td { text-align: left; border: 1px solid #ccc; padding: 0.35rem 0.5rem; vertical-align: top; }
        th { background: #f2f2f2; }
        .warn { background: #fff8e6; padding: 0.75rem; border: 1px solid #e5c000; }
        pre { margin: 0.25rem 0; white-space: pre-wrap; word-break: break-all; font-size: 0.8rem; }
    </style>
</head>
<body>
    <h1>Trytest server diagnostics</h1>
    <p class="warn"><strong>Remove <code>trytest_diagnostics.php</code> from the server</strong> after you finish — it is protected only by a URL secret.</p>

    <h2>Environment</h2>
    <table>
        <tr><th>PHP</th><td><?php echo $h(PHP_VERSION . ' — ' . PHP_SAPI); ?></td></tr>
        <tr><th>HTTP_HOST</th><td><pre><?php echo $h((string) ($_SERVER['HTTP_HOST'] ?? '')); ?></pre></td></tr>
        <tr><th>HTTPS</th><td><?php echo $h((string) ($_SERVER['HTTPS'] ?? '')); ?></td></tr>
        <tr><th>REQUEST_URI</th><td><pre><?php echo $h((string) ($_SERVER['REQUEST_URI'] ?? '')); ?></pre></td></tr>
        <tr><th>SCRIPT_NAME</th><td><pre><?php echo $h($selfScript); ?></pre></td></tr>
        <tr><th>SCRIPT_FILENAME</th><td><pre><?php echo $h((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')); ?></pre></td></tr>
        <tr><th>DOCUMENT_ROOT (raw)</th><td><pre><?php echo $h($docRoot); ?></pre></td></tr>
        <tr><th>DOCUMENT_ROOT (realpath)</th><td><pre><?php echo $h($docRootReal !== false ? (string) $docRootReal : '(n/a)'); ?></pre></td></tr>
        <tr><th>App __DIR__ (realpath)</th><td><pre><?php echo $h($appRootReal !== false ? (string) $appRootReal : '(n/a)'); ?></pre></td></tr>
    </table>

    <h2>Configuration</h2>
    <table>
        <tr><th>getenv("TRYTEST_WEB_BASE")</th><td><pre><?php echo $h($envBaseDisp); ?></pre></td></tr>
        <tr><th>config app.php base_path (raw)</th><td><pre><?php echo $h($cfgBase); ?></pre></td></tr>
    </table>

    <h2>Computed routing (Trytest)</h2>
    <table>
        <tr><th>trytest_detect_base_path()</th><td><pre><?php echo $h((string) $detect); ?></pre> (from filesystem + DOCUMENT_ROOT)</td></tr>
        <tr><th>trytest_base_path()</th><td><pre><?php echo $h($base); ?></pre></td></tr>
        <tr><th>trytest_request_path()</th><td><pre><?php echo $h($uriPath); ?></pre></td></tr>
        <tr><th>trytest_is_admin_entry_request()</th><td><strong><?php echo $isAdminEntry ? 'true' : 'false'; ?></strong> — for this request only</td></tr>
        <tr><th>trytest_is_dashboard_root_request()</th><td><?php echo $isDashRoot ? 'true' : 'false'; ?></td></tr>
        <tr><th>trytest_is_app_root_request()</th><td><?php echo $isAppRoot ? 'true' : 'false'; ?></td></tr>
        <tr><th>trytest_url("admin")</th><td><pre><?php echo $h(trytest_url('admin')); ?></pre></td></tr>
        <tr><th>trytest_url("dashboard")</th><td><pre><?php echo $h(trytest_url('dashboard')); ?></pre></td></tr>
        <tr><th>Expected /admin public path (with base)</th><td><pre><?php echo $h($adminPath); ?></pre></td></tr>
    </table>

    <h2>Session (this browser)</h2>
    <table>
        <tr><th>$_SESSION["is_admin"]</th><td><?php echo $adminSession ? 'set' : 'not set'; ?> (if set, you may be redirected away from the admin login page)</td></tr>
    </table>

    <h2>Files</h2>
    <table>
        <tr><th>admin_login.php</th><td><?php echo is_file(__DIR__ . '/admin_login.php') ? 'present' : 'missing'; ?></td></tr>
        <tr><th>index.php</th><td><?php echo is_file(__DIR__ . '/index.php') ? 'present' : 'missing'; ?></td></tr>
        <tr><th>.htaccess</th><td><?php echo is_file(__DIR__ . '/.htaccess') ? 'present' : 'missing'; ?></td></tr>
    </table>

    <?php if (count($suggestions) > 0): ?>
    <h2>Suggestions</h2>
    <ul>
        <?php foreach ($suggestions as $s): ?>
        <li><?php echo $h($s); ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <h2>How to read this</h2>
    <ul>
        <li>Visit the real <strong>admin</strong> URL in another tab, e.g. <code><?php echo $h($adminPath); ?></code>, and compare with <em>trytest_is_admin_entry_request</em> when you load this same script under that path (not required, but the numbers above should match a working install).</li>
        <li>If <strong>trytest_base_path()</strong> is empty but your app is in a subfolder of the site, set <code>base_path</code> in <code>config/app.php</code> or the <code>TRYTEST_WEB_BASE</code> environment variable to that path segment (e.g. <code>tryTest</code> for <code>/tryTest/...</code> URLs).</li>
    </ul>
</body>
</html>
