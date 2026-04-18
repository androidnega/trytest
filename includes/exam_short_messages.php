<?php

declare(strict_types=1);

require_once __DIR__ . '/exam_wishes_200.php';

/**
 * Two-line exam wishes pool (200 items).
 *
 * @return list<string>
 */
function trytest_exam_short_messages_pool(): array
{
    return trytest_exam_wishes_two_line_pool();
}

function trytest_exam_short_message_for_quiz(int $userId, int $quizId): string
{
    $pool = trytest_exam_short_messages_pool();
    if ($pool === []) {
        return "Believe in yourself.\nYou are ready.";
    }
    $ix = abs(crc32((string) $userId . '|' . (string) $quizId . '|' . gmdate('Y-m-d'))) % count($pool);

    return $pool[$ix];
}

/**
 * Random two-line exam wish for welcome card.
 */
function trytest_exam_short_random_message(): string
{
    $pool = trytest_exam_short_messages_pool();
    if ($pool === []) {
        return "Believe in yourself.\nYou are ready.";
    }

    return $pool[random_int(0, count($pool) - 1)];
}
