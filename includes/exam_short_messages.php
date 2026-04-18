<?php

declare(strict_types=1);

/**
 * Short exam-hall messages (always brief).
 *
 * @return list<string>
 */
function trytest_exam_short_messages_pool(): array
{
    static $pool = null;
    if ($pool !== null) {
        return $pool;
    }
    $pool = [
        'Stay calm. Read twice.',
        'One question at a time.',
        'Trust your preparation.',
        'Clear steps earn points.',
        'Breathe. Then begin.',
        'Focus beats speed.',
        'Check the prompt again.',
        'You are ready for this.',
        'Neat work helps markers.',
        'Skip hard items, return later.',
        'Units and signs matter.',
        'Show your reasoning.',
        'Partial credit is real.',
        'Use the time you have.',
        'Do not rush the easy ones.',
        'Label what you draw.',
        'Answer what was asked.',
        'Scratch paper is free—use it.',
        'If stuck, write what you know.',
        'A steady pace wins.',
        'Silence doubt, not thinking.',
        'Read every option before choosing.',
        'Watch the clock, not others.',
        'Finish with a quick review.',
        'Confidence follows clarity.',
        'Small mistakes are fixable.',
        'You belong in this room.',
        'Turn nerves into focus.',
        'Start where you are strongest.',
        'Good luck—you have got this.',
        'Keep handwriting readable.',
        'Reread before you submit.',
        'Define terms if it helps.',
        'Estimate before you calculate.',
        'Diagram when it clarifies.',
        'State assumptions clearly.',
        'Multi-part? Number your answers.',
        'Time per mark—stay fair.',
        'Blank pages help no one—try.',
        'You have done harder days.',
        'Let today prove your effort.',
    ];

    return $pool;
}

function trytest_exam_short_message_for_quiz(int $userId, int $quizId): string
{
    $pool = trytest_exam_short_messages_pool();
    if ($pool === []) {
        return 'Good luck on this quiz.';
    }
    $ix = abs(crc32((string) $userId . '|' . (string) $quizId . '|' . gmdate('Y-m-d'))) % count($pool);

    return $pool[$ix];
}

/**
 * Random short exam line (not too long) for welcome card.
 */
function trytest_exam_short_random_message(): string
{
    $pool = trytest_exam_short_messages_pool();
    if ($pool === []) {
        return 'Good luck on this quiz.';
    }

    return $pool[random_int(0, count($pool) - 1)];
}
