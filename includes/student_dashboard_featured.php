<?php

declare(strict_types=1);

require_once __DIR__ . '/exam_short_messages.php';
require_once __DIR__ . '/trytest_urls.php';
require_once __DIR__ . '/youtube_subscribe.php';

function trytest_student_dashboard_quote_image_url(): string
{
    $f = __DIR__ . '/../KofiEmma.jpg';

    return is_file($f) ? trytest_url('KofiEmma.jpg') : '';
}

/**
 * Alternates featured "video" vs "quote" on each new login session when dashboard clips exist.
 * Without clips, always "quote".
 */
function trytest_student_dashboard_featured_kind_resolve(bool $videoAvailable): string
{
    if (!$videoAvailable) {
        return 'quote';
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['trytest_dash_feat_kind'])) {
        $k = (string) $_SESSION['trytest_dash_feat_kind'];

        return ($k === 'video' || $k === 'quote') ? $k : 'quote';
    }
    $prev = isset($_COOKIE['trytest_dash_feat']) ? (string) $_COOKIE['trytest_dash_feat'] : '';
    if ($prev !== 'video' && $prev !== 'quote') {
        $kind = 'quote';
    } else {
        $kind = $prev === 'video' ? 'quote' : 'video';
    }
    $_SESSION['trytest_dash_feat_kind'] = $kind;
    $base = trytest_base_path();
    $cookiePath = $base === '' ? '/' : $base;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    setcookie('trytest_dash_feat', $kind, [
        'expires' => time() + 365 * 24 * 3600,
        'path' => $cookiePath,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    return $kind;
}

function trytest_student_dashboard_featured_quote_section_html(bool $compactLayout, string $twoLineQuote): string
{
    $h = static function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    };
    $lines = preg_split("/\r\n|\n|\r/", $twoLineQuote) ?: [];
    $l1 = trim((string) ($lines[0] ?? ''));
    $l2 = trim((string) ($lines[1] ?? ''));
    if ($l1 === '') {
        $l1 = 'Believe in yourself.';
        $l2 = $l2 !== '' ? $l2 : 'You are ready.';
    } elseif ($l2 === '') {
        $l2 = "You've got this.";
    }
    $imgUrl = trytest_student_dashboard_quote_image_url();
    $author = 'Emmanuel K Kwofie';
    $thumbSize = $compactLayout ? 'h-[4.5rem] w-[4.5rem]' : 'h-24 w-24 sm:h-[6.5rem] sm:w-[6.5rem]';
    $imgClass = 'shrink-0 rounded-xl border border-slate-200 bg-slate-100 object-cover shadow-sm ' . $thumbSize;
    $phClass = 'shrink-0 rounded-xl border border-dashed border-slate-200 bg-slate-50 ' . $thumbSize;
    $imgBlock = $imgUrl !== ''
        ? '<img src="' . $h($imgUrl) . '" alt="" class="' . htmlspecialchars($imgClass, ENT_QUOTES, 'UTF-8') . '" loading="lazy" decoding="async" />'
        : '<div class="' . htmlspecialchars($phClass, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></div>';
    $textClass = $compactLayout
        ? 'text-xs font-semibold leading-snug text-slate-800'
        : 'text-sm font-semibold leading-snug text-slate-800 sm:text-base';
    $authorClass = $compactLayout
        ? 'mt-1.5 border-t border-slate-100 pt-1.5 text-[10px] font-medium tracking-wide text-[#2C6A7D]'
        : 'mt-2 border-t border-slate-100 pt-2 text-xs font-medium tracking-wide text-[#2C6A7D] sm:text-sm';
    $pad = $compactLayout ? 'p-2.5 sm:p-3' : 'p-3 sm:p-4';
    $gap = $compactLayout ? 'gap-2.5 sm:gap-3' : 'gap-3 sm:gap-4';
    $sectionClass = $compactLayout
        ? 'rounded-xl border border-slate-200 bg-white p-2.5 shadow-none'
        : 'mb-4 rounded-xl border border-slate-200 bg-white p-3 shadow-none sm:p-4';
    $body = '<p class="' . htmlspecialchars($textClass, ENT_QUOTES, 'UTF-8') . '">'
        . $h($l1) . '<br />' . $h($l2) . '</p>'
        . '<p class="' . htmlspecialchars($authorClass, ENT_QUOTES, 'UTF-8') . '">' . $h($author) . '</p>';

    return '<section class="' . htmlspecialchars($sectionClass, ENT_QUOTES, 'UTF-8') . '" aria-label="Exam wish">'
        . '<div class="mb-1.5 flex items-center justify-between gap-2 sm:mb-2">'
        . '<h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Words for you</h2>'
        . '<span class="text-[10px] font-medium uppercase tracking-wide text-slate-400">Trytest</span></div>'
        . '<article class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">'
        . '<div class="flex flex-row items-center ' . htmlspecialchars($gap, ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($pad, ENT_QUOTES, 'UTF-8') . '">'
        . $imgBlock
        . '<div class="min-w-0 flex-1 text-left">' . $body . '</div>'
        . '</div></article></section>';
}

/**
 * Main dashboard hero: either YouTube clip or two-line exam wish + quote image (same vibe as quiz intro).
 * Shown to every signed-in student on home; not filtered by level.
 *
 * @param array<string, mixed> $ytSettings
 */
function trytest_student_dashboard_featured_html(array $ytSettings, bool $compactLayout): string
{
    $valid = trytest_youtube_dashboard_valid_embed_urls($ytSettings);
    $videoOk = $valid !== [];
    $kind = trytest_student_dashboard_featured_kind_resolve($videoOk);
    if ($kind === 'video' && $videoOk) {
        $url = trytest_youtube_dashboard_resolve_session_video_url($valid);
        if ($url !== '') {
            return trytest_youtube_dashboard_video_section_html($url, $compactLayout);
        }
    }
    $quote = trytest_exam_short_random_message();

    return trytest_student_dashboard_featured_quote_section_html($compactLayout, $quote);
}
