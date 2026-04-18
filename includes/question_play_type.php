<?php

declare(strict_types=1);

/**
 * How a question is played in the quiz UI (auto rules).
 * - Stem contains "____" → fill in the blank
 * - Else if any option text is non-empty → MCQ
 * - Else → short theory answer
 */
function trytest_question_play_type(array $row): string
{
    $stem = (string) ($row['question'] ?? '');
    if (strpos($stem, '____') !== false) {
        return 'fill';
    }
    foreach (['option_a', 'option_b', 'option_c', 'option_d'] as $k) {
        if (trim((string) ($row[$k] ?? '')) !== '') {
            return 'mcq';
        }
    }

    return 'theory';
}
