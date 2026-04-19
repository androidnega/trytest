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
 * Picks "video" vs "quote" when dashboard clips exist: random on first home view, then often
 * refreshes on each return to home (so refresh / other tabs / back to home feel dynamic).
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
    $raw = $_SESSION['trytest_dash_feat_kind'] ?? null;
    $current = is_string($raw) && ($raw === 'video' || $raw === 'quote') ? $raw : null;
    // ~53% chance per home visit to repick video vs quote (two independent rolls).
    $reroll = $current === null
        || random_int(1, 100) <= 40
        || random_int(1, 100) <= 22;
    if ($reroll) {
        $kind = random_int(0, 1) === 0 ? 'video' : 'quote';
        $_SESSION['trytest_dash_feat_kind'] = $kind;

        return $kind;
    }

    return $current;
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
    $imgShell =
        'relative h-full min-h-0 overflow-hidden rounded-2xl border border-slate-200 bg-slate-100 shadow-md dark:border-zinc-700/45 dark:bg-[#222228]';
    $imgInner = 'absolute inset-0 h-full w-full object-cover object-center';
    $phInner = 'absolute inset-0 bg-slate-100 dark:bg-[#222228]';
    $imgBlock = $imgUrl !== ''
        ? '<div class="' . htmlspecialchars($imgShell, ENT_QUOTES, 'UTF-8') . '"><img src="' . $h($imgUrl) . '" alt="" class="' . htmlspecialchars($imgInner, ENT_QUOTES, 'UTF-8') . '" loading="lazy" decoding="async" /></div>'
        : '<div class="' . htmlspecialchars($imgShell . ' border-dashed dark:border-zinc-600/50', ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"><div class="' . htmlspecialchars($phInner, ENT_QUOTES, 'UTF-8') . '"></div></div>';
    $textClass = $compactLayout
        ? 'text-sm font-bold leading-snug text-slate-800 dark:text-zinc-100'
        : 'text-base font-bold leading-snug text-slate-800 sm:text-lg dark:text-zinc-100';
    $sectionClass = $compactLayout
        ? 'rounded-xl border border-slate-200 bg-white p-2.5 shadow-none dark:border-zinc-800/50 dark:bg-[#1c1c22]'
        : 'mb-4 rounded-xl border border-slate-200 bg-white p-3 shadow-none sm:p-4 dark:border-zinc-800/50 dark:bg-[#1c1c22]';
    $footer = $compactLayout
        ? '<div class="flex items-center justify-end border-t border-slate-100 px-2 py-1 dark:border-zinc-800"><span class="text-[10px] font-semibold text-slate-500 dark:text-zinc-400">'
            . $h($author) . '</span></div>'
        : '<div class="border-t border-slate-100 px-3 py-2 text-right dark:border-zinc-800"><span class="text-xs font-medium text-slate-600 dark:text-zinc-400">'
            . $h($author) . '</span></div>';
    $body = '<p class="' . htmlspecialchars($textClass, ENT_QUOTES, 'UTF-8') . '">'
        . $h($l1) . '<br />' . $h($l2) . '</p>';

    $innerPad = $compactLayout ? 'p-3 sm:p-4' : 'p-4 sm:p-6 md:p-8';

    return '<section class="' . htmlspecialchars($sectionClass, ENT_QUOTES, 'UTF-8') . '" aria-label="Exam wish">'
        . '<div class="mb-1.5 flex items-center justify-between gap-2 sm:mb-2">'
        . '<h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-zinc-400">Words for you</h2>'
        . '<span class="text-[10px] font-medium uppercase tracking-wide text-slate-400 dark:text-zinc-500">Trytest</span></div>'
        . '<div class="grid grid-cols-1 gap-2">'
        . '<article class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm ring-1 ring-slate-100/80 dark:border-zinc-800/45 dark:bg-[#1e1e24] dark:ring-white/[0.04]">'
        . '<div class="grid min-h-[8.75rem] w-full grid-cols-[minmax(5.25rem,34%)_minmax(0,1fr)] items-stretch gap-3 bg-slate-50/90 sm:min-h-[9.75rem] sm:gap-4 dark:bg-[#16161a] ' . htmlspecialchars($innerPad, ENT_QUOTES, 'UTF-8') . '">'
        . $imgBlock
        . '<div class="flex min-h-0 min-w-0 flex-col justify-center text-left">' . $body . '</div>'
        . '</div>'
        . $footer
        . '</article></div></section>';
}

/**
 * Main dashboard hero: either YouTube clip or two-line exam wish + quote image (same vibe as quiz intro).
 * Shown to every signed-in student on home; not filtered by level.
 *
 * @param array<string, mixed> $ytSettings
 */
function trytest_student_dashboard_featured_html(array $ytSettings, bool $compactLayout, bool $homeTabActive): string
{
    if (!$homeTabActive) {
        return '';
    }
    $valid = trytest_youtube_dashboard_valid_embed_urls($ytSettings);
    $videoOk = $valid !== [];
    $kind = trytest_student_dashboard_featured_kind_resolve($videoOk);
    if ($kind === 'video' && $videoOk) {
        $url = trytest_youtube_dashboard_resolve_session_video_url($valid);
        if ($url !== '') {
            return trytest_youtube_dashboard_video_section_html($url, $compactLayout);
        }
    }
    $quote = trytest_exam_short_random_message_dashboard();

    return trytest_student_dashboard_featured_quote_section_html($compactLayout, $quote);
}
