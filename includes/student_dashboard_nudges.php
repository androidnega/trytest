<?php

declare(strict_types=1);

require_once __DIR__ . '/youtube_subscribe.php';

/**
 * Dismissible home-dashboard tips (praise, last score, downloads, YouTube share).
 * Visibility is partly random per visit; closing stores a cooldown in localStorage (handled in-page script).
 *
 * @param array<string, mixed> $ytSettings From trytest_youtube_settings()
 * @return list<array{id:string,body:string,link?:string,link_label?:string,cooldown_days:int}>
 */
function trytest_student_dashboard_nudges_collect(
    PDO $db,
    int $userId,
    array $ytSettings,
    string $downloadsPageUrl
): array {
    $out = [];

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

    if (
        is_array($lastRow)
        && random_int(1, 100) <= 82
    ) {
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
        $out[] = [
            'id' => 'last_quiz',
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
    if (random_int(1, 100) <= 40) {
        $pi = random_int(0, count($praiseLines) - 1);
        $out[] = [
            'id' => 'praise_' . (string) $pi,
            'body' => $praiseLines[$pi],
            'cooldown_days' => 8,
        ];
    }

    if (random_int(1, 100) <= 44) {
        $out[] = [
            'id' => 'downloads_keys',
            'body' => 'Need more practice? Open Files → Downloads for question sets that include answer keys.',
            'link' => $downloadsPageUrl,
            'link_label' => 'Open Downloads',
            'cooldown_days' => 10,
        ];
    }

    $ch = trim((string) ($ytSettings['channel_id'] ?? ''));
    if ($ch !== '' && random_int(1, 100) <= 32) {
        $ytUrl = trytest_youtube_channel_browser_url($ch);
        $out[] = [
            'id' => 'youtube_share_three',
            'body' => 'Share our YouTube channel with three people you study with — it helps us keep materials and downloads available for everyone.',
            'link' => $ytUrl,
            'link_label' => 'Open channel',
            'cooldown_days' => 14,
        ];
    }

    shuffle($out);
    if (count($out) > 3) {
        $out = array_slice($out, 0, 3);
    }

    return $out;
}

/**
 * Rounded dismissible strips + inline script for localStorage cooldowns.
 */
function trytest_student_dashboard_nudges_html(array $nudges, bool $compactLayout): string
{
    if ($nudges === []) {
        return '';
    }
    $h = static function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    };
    $wrapClass = $compactLayout
        ? 'mb-2 space-y-2'
        : 'mb-3 space-y-2.5';
    $cardClass = 'relative rounded-xl border border-slate-200/90 bg-white/95 px-3 py-2.5 pr-8 text-left shadow-sm dark:border-zinc-600 dark:bg-zinc-900/90';
    $textClass = $compactLayout
        ? 'text-[11px] leading-snug text-slate-800 dark:text-zinc-200'
        : 'text-xs leading-snug text-slate-800 sm:text-sm dark:text-zinc-200';
    $btnClass =
        'absolute right-1 top-1 flex h-6 w-6 cursor-pointer items-center justify-center rounded-md text-sm font-bold leading-none text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:text-zinc-500 dark:hover:bg-zinc-800 dark:hover:text-zinc-200';
    $parts = [];
    foreach ($nudges as $n) {
        $id = preg_replace('/[^a-z0-9_]/i', '', (string) ($n['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $body = trim((string) ($n['body'] ?? ''));
        if ($body === '') {
            continue;
        }
        $cd = max(1, min(30, (int) ($n['cooldown_days'] ?? 7)));
        $link = isset($n['link']) ? trim((string) $n['link']) : '';
        $linkLabel = isset($n['link_label']) ? trim((string) $n['link_label']) : '';
        $linkHtml = '';
        if ($link !== '') {
            $lab = $linkLabel !== '' ? $linkLabel : 'Open';
            $linkHtml =
                ' <a href="'
                . $h($link)
                . '" class="whitespace-nowrap font-semibold text-[#2C6A7D] underline dark:text-[#7eb8b8]">'
                . $h($lab)
                . '</a>';
        }
        $parts[] =
            '<div class="' . $h($cardClass) . '" data-trytest-nudge="' . $h($id) . '" data-trytest-nudge-cooldown="' . (string) $cd . '">'
            . '<button type="button" class="' . $h($btnClass) . '" data-trytest-nudge-close aria-label="Dismiss">×</button>'
            . '<p class="' . $h($textClass) . '">' . $h($body) . $linkHtml . '</p>'
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
    function isDismissed(id, cooldownDays) {
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
        if (id && isDismissed(id, cd)) {
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
