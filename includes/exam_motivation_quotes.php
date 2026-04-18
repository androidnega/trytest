<?php

declare(strict_types=1);

/**
 * Pool of 200 distinct short exam / motivation lines for first-time quiz welcome.
 *
 * @return list<string>
 */
function trytest_exam_motivation_quote_pool(): array
{
    static $pool = null;
    if ($pool !== null) {
        return $pool;
    }

    $prefixes = [
        'Before you turn the page,',
        'When time feels tight,',
        'If a question stalls you,',
        'On exam day,',
        'Halfway through the paper,',
        'Right at the start,',
        'Between sections,',
        'If your mind races,',
        'When others finish early,',
        'If you miss a step,',
        'Under pressure,',
        'With blank space left,',
        'If the wording feels tricky,',
        'When formulas blur,',
        'If doubt whispers,',
        'Before you erase,',
        'When the clock ticks loud,',
        'If your hand cramps,',
        'On the final stretch,',
        'If confidence dips,',
    ];

    $middles = [
        'slow down and read the requirement twice.',
        'mark what you know first—momentum builds.',
        'show clear steps; partial credit travels with structure.',
        'breathe once, then answer the question asked—not the one you feared.',
        'check units and signs before you box the answer.',
        'outline in the margin; clarity saves minutes later.',
        'trust the prep you already did; stay in the present item.',
        'skip and return; fresh eyes often unlock the stuck part.',
        'label diagrams neatly; readers reward tidy reasoning.',
        'estimate the answer; sanity checks catch slips early.',
        'rewrite the given in your own words; understanding steers the work.',
        'allocate time by marks; fairness beats perfection on one item.',
        'pause once—then continue with precision.',
        'use every line with purpose; fluff rarely earns points.',
        'convert nerves into a checklist: given, find, plan, solve, review.',
        'reread the last sentence; exams often hide hints there.',
        'keep handwriting readable; kindness to the marker helps you.',
        'if two answers fight, pick the one the question actually names.',
        'start with definitions you trust; foundations steady the rest.',
        'end with a thirty-second scan; small fixes lift the total.',
    ];

    $pool = [];
    foreach ($prefixes as $pre) {
        foreach ($middles as $mid) {
            $pool[] = $pre . ' ' . $mid;
            if (count($pool) >= 200) {
                break 2;
            }
        }
    }

    return $pool;
}

function trytest_exam_motivation_quote_for_student(int $userId): string
{
    $pool = trytest_exam_motivation_quote_pool();
    if ($pool === []) {
        return 'Breathe, read carefully, and trust your preparation.';
    }
    $ix = abs(crc32((string) $userId . '|' . gmdate('Y-m-d'))) % count($pool);

    return $pool[$ix];
}
