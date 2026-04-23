<?php

declare(strict_types=1);

/**
 * URL path from web root to this app's folder (e.g. '/trytest') or '' at site root.
 */
function trytest_detect_base_path(): string
{
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    $appRoot = realpath(__DIR__ . '/..');
    if ($docRoot === false || $appRoot === false) {
        return '';
    }
    $doc = str_replace('\\', '/', $docRoot);
    $root = str_replace('\\', '/', $appRoot);
    if (!str_starts_with($root, $doc)) {
        return '';
    }
    $rel = substr($root, strlen($doc));
    $rel = trim($rel, '/');
    return $rel === '' ? '' : '/' . $rel;
}

function trytest_request_host_without_port(): string
{
    return preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
}

/**
 * True when the request is clearly local dev (XAMPP subfolder URLs use /trytest/...).
 */
function trytest_is_local_dev_host(): bool
{
    $h = trytest_request_host_without_port();
    if ($h === 'localhost' || $h === '127.0.0.1' || str_starts_with($h, 'localhost.')) {
        return true;
    }
    if (preg_match('/^192\.168\.\d{1,3}\.\d{1,3}$/', $h) === 1) {
        return true;
    }
    if (str_ends_with(strtolower($h), '.local')) {
        return true;
    }
    return false;
}

/**
 * When the app is mounted at the site root (base_path ""), /trytest is not a URL prefix
 * on this host — respond 404 with no redirect. Skipped on local dev (subfolder installs)
 * and when base_path is non-empty (intentional subfolder deploy).
 */
function trytest_reject_trytest_prefix_when_at_root(): void
{
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        return;
    }
    if (headers_sent()) {
        return;
    }
    if (trytest_is_local_dev_host()) {
        return;
    }
    if (trytest_base_path() !== '') {
        return;
    }
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (!is_string($path)) {
        return;
    }
    if ($path !== '/trytest' && !str_starts_with($path, '/trytest/')) {
        return;
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

/**
 * If a URL path looks like a leaked filesystem path under /home3/, return the document-root
 * tail (e.g. "dashboard/manage_admin") or null when we cannot map it safely.
 */
function trytest_leaked_home_path_tail(string $path): ?string
{
    if (!str_starts_with($path, '/home3/')) {
        return null;
    }
    if (preg_match('#^/home3/[^/]+/(?:[^/]+/)*trytest/(?:trytest/)*(.*)$#', $path, $m)) {
        return trim((string) ($m[1] ?? ''), '/');
    }
    // Typical cPanel docroot: /home3/user/public_html/...
    if (preg_match('#^/home3/[^/]+/(?:[^/]+/)*public_html/(.*)$#', $path, $m)) {
        return trim((string) ($m[1] ?? ''), '/');
    }
    return null;
}

/**
 * Turn a docroot-relative tail into the public URL path (applies base_path for subfolder installs).
 */
function trytest_url_path_from_docroot_tail(string $tail): string
{
    $tail = trim($tail, '/');
    $base = trytest_base_path();
    if ($base === '') {
        return $tail === '' ? '/' : '/' . $tail;
    }
    return $tail === '' ? $base : $base . '/' . $tail;
}

/**
 * Some shared hosts mis-resolve redirects so the browser ends up with a path like
 * /home3/user/.../public_html/dashboard or .../trytest/trytest/dashboard. If we see that on a
 * public host, 301 to the real site path (respects TRYTEST_WEB_BASE / configured base_path).
 */
function trytest_redirect_leaked_server_path_prefix(): void
{
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        return;
    }
    if (headers_sent()) {
        return;
    }
    if (trytest_is_local_dev_host()) {
        return;
    }
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (!is_string($path)) {
        return;
    }
    $tail = trytest_leaked_home_path_tail($path);
    if ($tail === null) {
        return;
    }
    $target = trytest_url_path_from_docroot_tail($tail);
    $norm = '/' . trim(str_replace('\\', '/', $path), '/');
    if ($norm === '//') {
        $norm = '/';
    }
    $want = '/' . trim(str_replace('\\', '/', $target), '/');
    if ($want === '//') {
        $want = '/';
    }
    if ($norm === $want) {
        return;
    }
    $qs = isset($_SERVER['QUERY_STRING']) && (string) $_SERVER['QUERY_STRING'] !== ''
        ? '?' . (string) $_SERVER['QUERY_STRING']
        : '';
    trytest_redirect($want . $qs, 301);
}

function trytest_request_path(): string
{
    $p = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (!is_string($p) || $p === '') {
        $p = '/';
    }
    $p = '/' . trim(str_replace('\\', '/', $p), '/');
    return $p === '//' ? '/' : $p;
}

/** True when the request targets the public homepage (not /dashboard, /quiz, etc.). */
function trytest_is_app_root_request(): bool
{
    if (trytest_is_admin_entry_request()) {
        return false;
    }
    $p = trytest_request_path();
    if ($p === '/index.php') {
        return true;
    }
    $homeRaw = trytest_home_url();
    $base = trytest_base_path();
    if ($base !== '' && $p === $base . '/index.php') {
        return true;
    }
    $home = rtrim($homeRaw, '/');
    if ($home === '' || $home === '/') {
        return $p === '/' || $p === '';
    }
    return $p === $home || $p === $home . '/';
}

/**
 * True when the request is exactly the student/admin app hub path (/dashboard), not a subpage.
 * Respects base_path for subfolder installs.
 */
function trytest_is_dashboard_root_request(): bool
{
    $p = rtrim(trytest_request_path(), '/') ?: '/';
    $b = trytest_base_path();
    $dash = $b === '' ? '/dashboard' : $b . '/dashboard';
    $dash = rtrim($dash, '/') ?: '/';
    return $p === $dash;
}

/**
 * True when the request is the admin sign-in URL (/admin), respecting base_path.
 * Also true while running admin_login.php (rewritten from /admin on some stacks).
 */
function trytest_is_admin_entry_request(): bool
{
    $p = rtrim(trytest_request_path(), '/') ?: '/';
    $b = trytest_base_path();
    $admin = $b === '' ? '/admin' : $b . '/admin';
    $admin = rtrim($admin, '/') ?: '/';
    if ($p === $admin || $p === $admin . '/index.php') {
        return true;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '' && str_ends_with($script, 'admin_login.php')) {
        return true;
    }
    return false;
}

/**
 * URL path prefix, e.g. '/trytest' or '' when the app is at the site root.
 */
function trytest_base_path(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $env = getenv('TRYTEST_WEB_BASE');
    if ($env !== false) {
        $base = trim((string) $env, '/');
        $cached = $base === '' ? '' : '/' . $base;
        return $cached;
    }
    $file = __DIR__ . '/../config/app.php';
    if (!is_file($file)) {
        $cached = trytest_detect_base_path();
        return $cached;
    }
    /** @var array{base_path?: string} $cfg */
    $cfg = require $file;
    $raw = isset($cfg['base_path']) ? trim((string) $cfg['base_path']) : 'auto';
    if (strtolower($raw) === 'auto') {
        // Same detection on every host: subfolder production installs (e.g. cPanel) need this;
        // localhost-only detection left /admin and trytest_url() out of sync with the real path.
        $cached = trytest_detect_base_path();
        return $cached;
    }
    $p = rtrim($raw, '/');
    if ($p === '') {
        $cached = '';
        return $cached;
    }
    $cached = $p[0] === '/' ? $p : '/' . $p;
    if (!trytest_is_local_dev_host() && $cached !== '' && trytest_detect_base_path() === '') {
        $cached = '';
    }
    return $cached;
}

/**
 * Absolute path on this host (starts with /). $path should not start with /.
 */
function trytest_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $b = trytest_base_path();
    if ($b === '') {
        return '/' . $path;
    }
    return $path === '' ? $b : $b . '/' . $path;
}

function trytest_request_origin(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $proto = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $proto . '://' . $host;
}

/**
 * Full URL for the current host + configured base + path (for OAuth redirect URIs, etc.).
 */
function trytest_absolute_url(string $path = ''): string
{
    return trytest_request_origin() . trytest_url($path);
}

/** Site entry (student/admin router): `/` at domain root, or the configured base path in a subfolder install. */
function trytest_home_url(): string
{
    return trytest_url('');
}

/**
 * Homepage with query string (e.g. `?out=1`). Admin sign-in lives at /admin.
 *
 * @param array<string, string|int|float|bool|null> $params
 */
function trytest_home_with_query(array $params): string
{
    $q = http_build_query($params);
    $h = trytest_home_url();
    if ($h === '/') {
        return '/?' . $q;
    }
    return rtrim($h, '/') . '/?' . $q;
}

/**
 * Normalize Location targets so we never redirect to filesystem paths or protocol-relative URLs.
 * Same-host absolute http(s) URLs are reduced to path + query only.
 */
function trytest_redirect_location(string $location): string
{
    $location = trim($location);
    if ($location === '') {
        return trytest_home_url();
    }
    if (str_starts_with($location, '//')) {
        return trytest_home_url();
    }
    if (preg_match('#^https?://#i', $location) === 1) {
        $host = parse_url($location, PHP_URL_HOST);
        $cur = trytest_request_host_without_port();
        if (is_string($host) && strcasecmp((string) $host, $cur) === 0) {
            $path = parse_url($location, PHP_URL_PATH);
            $query = parse_url($location, PHP_URL_QUERY);
            $pathOnly = (is_string($path) && $path !== '') ? $path : '/';
            $location = $pathOnly;
            if (is_string($query) && $query !== '') {
                $location .= '?' . $query;
            }
        } else {
            return $location;
        }
    }
    $query = '';
    $pathPart = $location;
    $qPos = strpos($location, '?');
    if ($qPos !== false) {
        $pathPart = substr($location, 0, $qPos);
        $query = substr($location, $qPos);
    }
    if ($pathPart === '' || $pathPart[0] !== '/') {
        $pathPart = '/' . ltrim($pathPart, '/');
    }
    $lower = strtolower($pathPart);
    if (
        str_starts_with($pathPart, '/home3/')
        || str_starts_with($lower, '/var/')
        || str_starts_with($lower, '/usr/')
        || preg_match('#^/[a-z]:/#i', $pathPart) === 1
    ) {
        if (str_starts_with($pathPart, '/home3/')) {
            $tail = trytest_leaked_home_path_tail($pathPart);
            if ($tail !== null) {
                return trytest_url_path_from_docroot_tail($tail) . $query;
            }
        }
        return trytest_home_url();
    }
    return $pathPart . $query;
}

/**
 * Send an HTTP redirect and exit. Always pass through trytest_redirect_location().
 */
function trytest_redirect_scheme_host_prefix(): string
{
    if (trytest_is_local_dev_host()) {
        return '';
    }
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = '';
    $file = __DIR__ . '/../config/app.php';
    if (!is_file($file)) {
        return $cached;
    }
    /** @var array{public_base_url?: string} $cfg */
    $cfg = require $file;
    $u = trim((string) ($cfg['public_base_url'] ?? ''));
    if ($u === '') {
        return $cached;
    }
    $cached = rtrim($u, '/');
    return $cached;
}

function trytest_redirect(string $location, int $status = 302): void
{
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        return;
    }
    if (headers_sent()) {
        return;
    }
    $loc = trytest_redirect_location($location);
    $prefix = trytest_redirect_scheme_host_prefix();
    header('Location: ' . ($prefix !== '' ? $prefix : '') . $loc, true, $status);
    exit;
}

/**
 * Absolute asset/page URL using optional config `public_base_url` (same rules as redirects).
 */
function trytest_absolute_public_url(string $path = ''): string
{
    $prefix = trytest_redirect_scheme_host_prefix();
    if ($prefix !== '') {
        return $prefix . trytest_url($path);
    }

    return trytest_absolute_url($path);
}

/**
 * Canonical URL for the current request (path + query), for og:url.
 */
function trytest_current_absolute_public_request_url(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if ($uri === '' || $uri[0] !== '/') {
        $uri = '/' . ltrim($uri, '/');
    }
    $prefix = trytest_redirect_scheme_host_prefix();
    if ($prefix !== '') {
        return $prefix . $uri;
    }

    return trytest_request_origin() . $uri;
}

/**
 * Short path + query for link-preview copy (relative to app base when possible).
 */
function trytest_link_preview_path_summary(): string
{
    $path = trytest_request_path();
    $base = trytest_base_path();
    if ($base !== '' && str_starts_with($path, $base)) {
        $rel = substr($path, strlen($base)) ?: '';
        $path = ($rel !== '' && $rel[0] === '/') ? $rel : '/' . ltrim($rel, '/');
    }
    if ($path === '') {
        $path = '/';
    }
    $qs = isset($_SERVER['QUERY_STRING']) ? trim((string) $_SERVER['QUERY_STRING']) : '';
    if ($qs !== '') {
        $path .= '?' . $qs;
    }
    if (strlen($path) > 160) {
        return substr($path, 0, 157) . '…';
    }

    return $path;
}

/**
 * Open Graph / Twitter Card tags for WhatsApp and similar link previews.
 * Uses favicon-og.png (PNG export of favicon.svg) because many crawlers ignore SVG for og:image.
 *
 * @param array{
 *   title?: string,
 *   description?: string,
 *   url?: ?string,
 *   image_path?: string,
 *   image_width?: int,
 *   image_height?: int,
 *   path_line?: string,
 *   omit_path?: bool
 * } $opts
 */
function trytest_link_preview_meta(array $opts = []): void
{
    $title = trim((string) ($opts['title'] ?? ''));
    if ($title === '') {
        $title = 'Trytest';
    }
    $desc = trim((string) ($opts['description'] ?? ''));
    $omitPath = !empty($opts['omit_path']);
    $pathLine = '';
    if (!$omitPath) {
        $pathLine = trim((string) ($opts['path_line'] ?? ''));
        if ($pathLine === '') {
            $pathLine = trytest_link_preview_path_summary();
        }
    }
    if ($pathLine !== '') {
        $desc = $desc !== '' ? ($desc . ' · ' . $pathLine) : $pathLine;
    }
    if (strlen($desc) > 300) {
        $desc = substr($desc, 0, 297) . '…';
    }
    $url = array_key_exists('url', $opts) ? $opts['url'] : null;
    if (!is_string($url) || $url === '') {
        $url = trytest_current_absolute_public_request_url();
    }
    $imagePath = trim((string) ($opts['image_path'] ?? 'favicon-og.png'));
    if ($imagePath === '') {
        $imagePath = 'favicon-og.png';
    }
    $imgW = isset($opts['image_width']) ? (int) $opts['image_width'] : 200;
    $imgH = isset($opts['image_height']) ? (int) $opts['image_height'] : 200;
    if ($imgW < 1) {
        $imgW = 200;
    }
    if ($imgH < 1) {
        $imgH = 200;
    }
    $imageUrl = trytest_absolute_public_url($imagePath);
    $h = static function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    };
    ?>
<meta property="og:site_name" content="<?php echo $h('Trytest'); ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?php echo $h($title); ?>">
<?php if ($desc !== ''): ?>
<meta property="og:description" content="<?php echo $h($desc); ?>">
<?php endif; ?>
<meta property="og:url" content="<?php echo $h($url); ?>">
<meta property="og:image" content="<?php echo $h($imageUrl); ?>">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="<?php echo $h((string) $imgW); ?>">
<meta property="og:image:height" content="<?php echo $h((string) $imgH); ?>">
<meta property="og:image:alt" content="<?php echo $h($title); ?>">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?php echo $h($title); ?>">
<?php if ($desc !== ''): ?>
<meta name="twitter:description" content="<?php echo $h($desc); ?>">
<?php endif; ?>
<meta name="twitter:image" content="<?php echo $h($imageUrl); ?>">
<?php
}

if (!defined('TRYTEST_SKIP_INIT_REDIRECTS') || TRYTEST_SKIP_INIT_REDIRECTS !== true) {
    trytest_redirect_leaked_server_path_prefix();
    trytest_reject_trytest_prefix_when_at_root();
}
