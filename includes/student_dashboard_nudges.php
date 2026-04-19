<?php

declare(strict_types=1);

require_once __DIR__ . '/youtube_subscribe.php';

/**
 * One dismissible home-dashboard tip per visit (solid background per kind).
 * WhatsApp nudge picks a random shareable video and opens wa.me with prefilled text.
 *
 * @param array<string, mixed> $ytSettings From trytest_youtube_settings()
 * @return list<array{id:string,kind:string,body:string,link?:string,link_label?:string,whatsapp_href?:string,cooldown_days:int}>
 */
function trytest_student_dashboard_nudges_collect(
    PDO $db,
    int $userId,
    array $ytSettings,
    string $downloadsPageUrl
): array {
    $candidates = [];

    $lastStmt = $db->prepare(
        'SELECT s.score, s.total, s.created_at, q.title AS quiz_title
         FROM scores s
         INNER JOIN quizzes q ON q.id = s.quiz_id
         WHERE s.user_id = ?
         ORDER BY s.created_at DESC, s.id DESC
         LIMIT 1'
    );
    $lastStmt->execute([$userId]);
    $lastRow = $lastStmt->fetch(PDO::FETCH_ASSOC);

    if (is_array($lastRow)) {
        $sc = (int) ($lastRow['score'] ?? 0);
        $tot = max(1, (int) ($lastRow['total'] ?? 1));
        $acc = (int) round(100 * $sc / $tot);
        $title = trim((string) ($lastRow['quiz_title'] ?? 'Quiz'));
        if (function_exists('mb_strlen') && mb_strlen($title) > 48) {
            $title = mb_substr($title, 0, 45) . '…';
        } elseif (strlen($title) > 48) {
            $title = substr($title, 0, 45) . '…';
        }
        $when = trim((string) ($lastRow['created_at'] ?? ''));
        $whenShort = $when !== '' ? date('M j', strtotime($when) ?: time()) : '';
        $line = $whenShort !== ''
            ? "Last quiz ({$whenShort}): {$title} — you scored {$sc}/{$tot} ({$acc}%)."
            : "Last quiz: {$title} — you scored {$sc}/{$tot} ({$acc}%).";
        if ($acc >= 80) {
            $line .= ' Strong work — keep the streak.';
        } elseif ($acc >= 50) {
            $line .= ' Solid effort — review the misses and try again when you are ready.';
        } else {
            $line .= ' Every attempt counts — use Downloads for practice with answer keys.';
        }
        $candidates['last_quiz'] = [
            'id' => 'last_quiz',
            'kind' => 'last_quiz',
            'body' => $line,
            'cooldown_days' => 4,
        ];
    }

    $praiseLines = [
        'You are showing up — that is what great students do. Keep going.',
        'Small steps every day add up. Proud of the work you are putting in.',
        'Stay curious: you are building habits that last beyond one exam.',
        'Consistency beats intensity. Thanks for sticking with Trytest.',
        'Your effort matters — celebrate progress, not only perfect scores.',
        'You belong here. Take the next quiz when you feel ready.',
    ];
    $pi = random_int(0, count($praiseLines) - 1);
    $candidates['praise'] = [
        'id' => 'praise',
        'kind' => 'praise',
        'body' => $praiseLines[$pi],
        'cooldown_days' => 8,
    ];

    $candidates['downloads'] = [
        'id' => 'downloads_keys',
        'kind' => 'downloads',
        'body' => 'Need more practice? Open Files → Downloads for question sets that include answer keys.',
        'link' => $downloadsPageUrl,
        'link_label' => 'Open Downloads',
        'cooldown_days' => 10,
    ];

    $sharePool = trytest_youtube_student_share_video_pool($ytSettings);
    if ($sharePool !== []) {
        $pickVideo = $sharePool[random_int(0, count($sharePool) - 1)];
        $shareText =
            "Check out this study video:\n"
            . $pickVideo
            . "\n\n— From Trytest. Share with a few friends who are studying too — it helps us keep materials and downloads available.";
        $wa = 'https://wa.me/?text=' . rawurlencode($shareText);
        $candidates['whatsapp'] = [
            'id' => 'whatsapp_share',
            'kind' => 'whatsapp',
            'body' => 'Tap below to share a picked study clip with friends on WhatsApp — spreading the word helps us keep downloads and resources going.',
            'whatsapp_href' => $wa,
            'cooldown_days' => 12,
        ];
    }

    if ($candidates === []) {
        return [];
    }

    $keys = array_keys($candidates);
    $chosenKey = $keys[random_int(0, count($keys) - 1)];

    return [$candidates[$chosenKey]];
}

/**
 * At most one of dismissible nudge or cheer per load (featured video/quote is always shown separately).
 *
 * @param array{lead:string,body:string,quiz_id:int,context:string,surface?:string}|null $encouragement
 * @return array{nudge:string, encouragement:array{lead:string,body:string,quiz_id:int,context:string,surface?:string}|null}
 */
function trytest_student_dashboard_nudge_or_cheer_slot(string $nudgeHtml, ?array $encouragement): array
{
    $nudgeOk = trim($nudgeHtml) !== '';
    $encOk = is_array($encouragement);
    if (!$nudgeOk && !$encOk) {
        return ['nudge' => '', 'encouragement' => null];
    }
    if (!$nudgeOk) {
        return ['nudge' => '', 'encouragement' => $encouragement];
    }
    if (!$encOk) {
        return ['nudge' => $nudgeHtml, 'encouragement' => null];
    }
    $cycle = (int) ($_SESSION['trytest_dash_nudge_cheer_cycle'] ?? 0);
    $_SESSION['trytest_dash_nudge_cheer_cycle'] = $cycle + 1;
    if (($cycle % 2) === 0) {
        return ['nudge' => $nudgeHtml, 'encouragement' => null];
    }

    return ['nudge' => '', 'encouragement' => $encouragement];
}

/**
 * Solid background classes per kind (no gradients).
 *
 * @return array{card:string,text:string,link:string}
 */
function trytest_student_dashboard_nudge_surface_classes(string $kind): array
{
    switch ($kind) {
        case 'last_quiz':
            return [
                'card' => 'border border-[#9ec9c9] bg-[#D8EFEF] dark:border-emerald-800/30 dark:bg-emerald-950/22',
                'text' => 'text-slate-900 dark:text-zinc-100',
                'link' => 'font-semibold text-[#1d5c6e] underline dark:text-[#8ebfbf]',
            ];
        case 'praise':
            return [
                'card' => 'border border-[#f5c2c7] bg-[#FCE8E9] dark:border-rose-800/30 dark:bg-rose-950/20',
                'text' => 'text-slate-900 dark:text-zinc-100',
                'link' => 'font-semibold text-[#9f2d3a] underline dark:text-rose-300/90',
            ];
        case 'downloads':
            return [
                'card' => 'border border-slate-300 bg-[#E2E8F0] dark:border-zinc-700/40 dark:bg-[#222228]/90',
                'text' => 'text-slate-900 dark:text-zinc-100',
                'link' => 'font-semibold text-[#2C6A7D] underline dark:text-[#8ebfbf]',
            ];
        case 'whatsapp':
            return [
                'card' => 'border border-[#86efac] bg-[#DCFCE7] dark:border-green-800/35 dark:bg-green-950/22',
                'text' => 'text-slate-900 dark:text-zinc-100',
                'link' => 'font-semibold text-[#15803d] underline dark:text-green-300/90',
            ];
        default:
            return [
                'card' => 'border border-slate-200 bg-white dark:border-zinc-700/45 dark:bg-[#1e1e24]',
                'text' => 'text-slate-900 dark:text-zinc-100',
                'link' => 'font-semibold text-[#2C6A7D] underline dark:text-[#7eb8b8]',
            ];
    }
}

/**
 * One dismissible strip + inline script for localStorage cooldowns.
 */
function trytest_student_dashboard_nudges_html(array $nudges, bool $compactLayout): string
{
    if ($nudges === []) {
        return '';
    }
    $h = static function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    };
    $wrapClass = $compactLayout ? 'mb-2' : 'mb-3';
    $btnClass =
        'absolute right-1 top-1 z-10 flex h-6 w-6 cursor-pointer items-center justify-center rounded-md text-sm font-bold leading-none text-slate-600/80 hover:bg-black/5 hover:text-slate-900 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-zinc-100';

    $parts = [];
    foreach ($nudges as $n) {
        $id = preg_replace('/[^a-z0-9_]/i', '', (string) ($n['id'] ?? ''));
        $kind = preg_replace('/[^a-z_]/i', '', (string) ($n['kind'] ?? 'note'));
        if ($id === '') {
            continue;
        }
        $body = trim((string) ($n['body'] ?? ''));
        if ($body === '') {
            continue;
        }
        $cd = max(1, min(30, (int) ($n['cooldown_days'] ?? 7)));
        $surf = trytest_student_dashboard_nudge_surface_classes($kind);
        $cardClass = 'relative rounded-xl px-3 py-2.5 pr-8 text-left shadow-sm ' . $surf['card'];
        $textClass = ($compactLayout ? 'text-[11px] leading-snug ' : 'text-xs leading-snug sm:text-sm ') . $surf['text'];

        $link = isset($n['link']) ? trim((string) $n['link']) : '';
        $linkLabel = isset($n['link_label']) ? trim((string) $n['link_label']) : '';
        $linkHtml = '';
        if ($link !== '') {
            $lab = $linkLabel !== '' ? $linkLabel : 'Open';
            $linkHtml =
                ' <a href="'
                . $h($link)
                . '" class="whitespace-nowrap ' . $h($surf['link']) . '">'
                . $h($lab)
                . '</a>';
        }

        $waHref = isset($n['whatsapp_href']) ? trim((string) $n['whatsapp_href']) : '';
        $waBlock = '';
        if ($waHref !== '') {
            $waBlock =
                '<a href="'
                . $h($waHref)
                . '" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex min-h-9 items-center gap-2 rounded-lg bg-[#25D366] px-3 py-2 text-xs font-bold text-white shadow-sm hover:bg-[#20bd5a]">'
                . '<i class="fa-brands fa-whatsapp text-base" aria-hidden="true"></i>'
                . '<span>Share on WhatsApp</span>'
                . '</a>';
        }

        $parts[] =
            '<div class="' . $h($cardClass) . '" data-trytest-nudge="' . $h($id) . '" data-trytest-nudge-cooldown="' . (string) $cd . '">'
            . '<button type="button" class="' . $h($btnClass) . '" data-trytest-nudge-close aria-label="Dismiss">×</button>'
            . '<p class="' . $h($textClass) . '">' . $h($body) . $linkHtml . '</p>'
            . $waBlock
            . '</div>';
    }
    if ($parts === []) {
        return '';
    }

    $script = <<<'JS'
<script>
(function () {
    var PREFIX = 'trytest_dash_nudge_v1_';
    function storageKey(id) { return PREFIX + id; }
    function isDismissed(id) {
        try {
            var raw = localStorage.getItem(storageKey(id));
            if (!raw) return false;
            var o = JSON.parse(raw);
            if (!o || typeof o.until !== 'number') return false;
            return Date.now() < o.until;
        } catch (e) {
            return false;
        }
    }
    var root = document.getElementById('trytest-dash-nudges');
    if (!root) return;
    root.querySelectorAll('[data-trytest-nudge]').forEach(function (el) {
        var id = el.getAttribute('data-trytest-nudge') || '';
        var cd = parseInt(el.getAttribute('data-trytest-nudge-cooldown') || '7', 10);
        if (isNaN(cd) || cd < 1) cd = 7;
        if (id && isDismissed(id)) {
            el.remove();
            return;
        }
        var btn = el.querySelector('[data-trytest-nudge-close]');
        if (btn) {
            btn.addEventListener('click', function () {
                var until = Date.now() + cd * 86400000;
                try {
                    localStorage.setItem(storageKey(id), JSON.stringify({ until: until }));
                } catch (e) {}
                el.remove();
                if (root && root.children.length === 0) root.remove();
            });
        }
    });
    if (root && root.children.length === 0) root.remove();
})();
</script>
JS;

    return '<div id="trytest-dash-nudges" class="' . $h($wrapClass) . '" aria-label="Tips">'
        . implode('', $parts)
        . '</div>' . $script;
}
