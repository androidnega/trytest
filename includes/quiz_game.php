<?php

declare(strict_types=1);

/**
 * Static knowledge-card catalog (collectibles unlocked during quizzes).
 *
 * @return list<array{id:string,title:string,body:string}>
 */
function trytest_quiz_knowledge_card_catalog(): array
{
    return [
        [
            'id' => 'chunk_memory',
            'title' => 'Chunk it',
            'body' => 'Group facts into 3–5 chunks. Short-term memory holds about 7±2 items — chunks stretch that limit.',
        ],
        [
            'id' => 'active_recall',
            'title' => 'Active recall',
            'body' => 'Closing the notes and retrieving the answer beats re-reading. Quizzes are practice for your brain, not just a score.',
        ],
        [
            'id' => 'fitts_speed',
            'title' => "Fitts' idea",
            'body' => 'Bigger targets and shorter distances are faster to hit. Good UI (and good exam strategy) reduces unnecessary travel.',
        ],
        [
            'id' => 'gulf_execution',
            'title' => 'Gulf of execution',
            'body' => 'When you know what you want but not how to do it, the interface (or question wording) left a gap. Spot the gap — then close it.',
        ],
        [
            'id' => 'rods_cones',
            'title' => 'Rods vs cones',
            'body' => 'Rods handle low light and motion; cones handle color and detail. Night vision ≠ color vision.',
        ],
        [
            'id' => 'streak_power',
            'title' => 'Streak power',
            'body' => 'Momentum matters. A short focused burst with a streak beats long distracted sessions.',
        ],
        [
            'id' => 'blitz_calm',
            'title' => 'Blitz calm',
            'body' => 'Under a timer, breathe once, eliminate two wrong options, then commit. Speed without panic.',
        ],
        [
            'id' => 'mental_model',
            'title' => 'Mental models',
            'body' => 'Your internal picture of how something works guides every click. Update the model when feedback surprises you.',
        ],
        [
            'id' => 'spaced_practice',
            'title' => 'Space it out',
            'body' => 'Revisit topics after a day, then a week. Spacing beats cramming for long-term retention.',
        ],
        [
            'id' => 'error_friend',
            'title' => 'Errors teach',
            'body' => 'A wrong answer with a clear correction is more valuable than a lucky guess. Read the green check — then move on.',
        ],
        [
            'id' => 'quest_finish',
            'title' => 'Finish the quest',
            'body' => 'Small goals (5 correct, a 3-streak, one blitz) keep a long quiz feeling like levels, not a slog.',
        ],
        [
            'id' => 'checkpoint_reset',
            'title' => 'Checkpoint reset',
            'body' => 'Pause at checkpoints. Notice your score, reset your focus, then continue. Games do this for a reason.',
        ],
    ];
}

/**
 * @return array{xp:int,cards_unlocked:int,best_streak:int}
 */
function trytest_student_game_stats(PDO $db, int $userId): array
{
    if ($userId < 1) {
        return ['xp' => 0, 'cards_unlocked' => 0, 'best_streak' => 0];
    }
    $st = $db->prepare(
        'SELECT xp, cards_unlocked, best_streak FROM student_game_stats WHERE user_id = ? LIMIT 1'
    );
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['xp' => 0, 'cards_unlocked' => 0, 'best_streak' => 0];
    }

    return [
        'xp' => max(0, (int) ($row['xp'] ?? 0)),
        'cards_unlocked' => max(0, (int) ($row['cards_unlocked'] ?? 0)),
        'best_streak' => max(0, (int) ($row['best_streak'] ?? 0)),
    ];
}

/**
 * @return list<array{card_id:string,title:string,body:string,unlocked_at:string}>
 */
function trytest_student_knowledge_cards(PDO $db, int $userId): array
{
    if ($userId < 1) {
        return [];
    }
    $catalog = [];
    foreach (trytest_quiz_knowledge_card_catalog() as $c) {
        $catalog[$c['id']] = $c;
    }
    $st = $db->prepare(
        'SELECT card_id, unlocked_at FROM student_knowledge_cards WHERE user_id = ? ORDER BY unlocked_at DESC, id DESC'
    );
    $st->execute([$userId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = trim((string) ($row['card_id'] ?? ''));
        if ($id === '' || !isset($catalog[$id])) {
            continue;
        }
        $out[] = [
            'card_id' => $id,
            'title' => $catalog[$id]['title'],
            'body' => $catalog[$id]['body'],
            'unlocked_at' => (string) ($row['unlocked_at'] ?? ''),
        ];
    }

    return $out;
}

/**
 * Persist XP / streak / newly unlocked cards from a finished quiz session.
 *
 * @param array<string,mixed> $game
 * @return array{xp_gained:int,cards:list<string>,ok:bool}
 */
function trytest_student_game_apply_session(PDO $db, int $userId, int $quizId, array $game): array
{
    if ($userId < 1) {
        return ['ok' => false, 'xp_gained' => 0, 'cards' => []];
    }
    $xpGain = max(0, min(500, (int) ($game['xp_gained'] ?? 0)));
    $bestStreak = max(0, min(200, (int) ($game['best_streak'] ?? 0)));
    $cardIds = [];
    if (isset($game['cards']) && is_array($game['cards'])) {
        foreach ($game['cards'] as $cid) {
            $cid = trim((string) $cid);
            if ($cid !== '' && preg_match('/^[a-z0-9_]{2,40}$/', $cid)) {
                $cardIds[$cid] = true;
            }
        }
    }
    $validIds = [];
    foreach (trytest_quiz_knowledge_card_catalog() as $c) {
        $validIds[$c['id']] = true;
    }
    $newCards = [];
    foreach (array_keys($cardIds) as $cid) {
        if (isset($validIds[$cid])) {
            $newCards[] = $cid;
        }
    }

    $db->prepare(
        'INSERT INTO student_game_stats (user_id, xp, cards_unlocked, best_streak, updated_at)
         VALUES (?, 0, 0, 0, datetime(\'now\'))
         ON CONFLICT(user_id) DO NOTHING'
    )->execute([$userId]);

    if ($xpGain > 0 || $bestStreak > 0) {
        $db->prepare(
            'UPDATE student_game_stats
             SET xp = xp + ?,
                 best_streak = MAX(best_streak, ?),
                 updated_at = datetime(\'now\')
             WHERE user_id = ?'
        )->execute([$xpGain, $bestStreak, $userId]);
    }

    $inserted = [];
    $insCard = $db->prepare(
        'INSERT OR IGNORE INTO student_knowledge_cards (user_id, card_id, quiz_id, unlocked_at)
         VALUES (?, ?, ?, datetime(\'now\'))'
    );
    foreach ($newCards as $cid) {
        $insCard->execute([$userId, $cid, $quizId > 0 ? $quizId : null]);
        if ($insCard->rowCount() > 0) {
            $inserted[] = $cid;
        }
    }
    if ($inserted !== []) {
        $db->prepare(
            'UPDATE student_game_stats
             SET cards_unlocked = (
                 SELECT COUNT(*) FROM student_knowledge_cards WHERE user_id = ?
             ),
             updated_at = datetime(\'now\')
             WHERE user_id = ?'
        )->execute([$userId, $userId]);
    }

    return ['ok' => true, 'xp_gained' => $xpGain, 'cards' => $inserted];
}

/**
 * Compact dashboard strip for XP + recent cards.
 *
 * @param list<array{card_id:string,title:string,body:string,unlocked_at:string}> $cards
 * @param array{xp:int,cards_unlocked:int,best_streak:int} $stats
 */
function trytest_student_game_dashboard_html(array $stats, array $cards, callable $h): string
{
    $xp = (int) ($stats['xp'] ?? 0);
    $best = (int) ($stats['best_streak'] ?? 0);
    $n = count($cards);
    if ($xp < 1 && $n < 1 && $best < 1) {
        return '';
    }
    $cardBits = '';
    $show = array_slice($cards, 0, 4);
    foreach ($show as $c) {
        $cardBits .=
            '<article class="tt-game-card rounded-xl border border-slate-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900">'
            . '<p class="text-[11px] font-bold text-slate-900 dark:text-zinc-100">' . $h((string) ($c['title'] ?? '')) . '</p>'
            . '<p class="mt-0.5 line-clamp-2 text-[10px] leading-snug text-slate-500 dark:text-zinc-400">' . $h((string) ($c['body'] ?? '')) . '</p>'
            . '</article>';
    }

    return '<section class="tt-game-panel mb-4 rounded-2xl border border-slate-200/80 bg-white/90 p-3 dark:border-zinc-800/50 dark:bg-[#1c1c22]" aria-label="Game progress">'
        . '<div class="flex items-center justify-between gap-2">'
        . '<h2 class="text-xs font-bold text-slate-900 dark:text-zinc-100">Game progress</h2>'
        . '<p class="text-[10px] font-semibold tabular-nums text-[#1d4ed8] dark:text-sky-300">' . $xp . ' XP'
        . ($best > 0 ? ' · best streak ' . $best : '')
        . ($n > 0 ? ' · ' . $n . ' cards' : '')
        . '</p></div>'
        . ($cardBits !== ''
            ? '<div class="mt-2 grid grid-cols-2 gap-2">' . $cardBits . '</div>'
            : '<p class="mt-1 text-[10px] text-slate-500 dark:text-zinc-400">Earn knowledge cards by hitting streaks and blitz rounds in quizzes.</p>')
        . '</section>';
}
