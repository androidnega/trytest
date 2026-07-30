<?php

declare(strict_types=1);

/**
 * If $answer is a bank letter A–D (optionally with punctuation), return the matching option text.
 * Otherwise return the trimmed answer unchanged.
 */
function trytest_mcq_resolve_correct_to_option_text(
    string $answer,
    string $optionA,
    string $optionB,
    string $optionC,
    string $optionD
): string {
    $raw = trim($answer);
    if ($raw === '') {
        return $raw;
    }
    if (!preg_match('/^\(?\s*([ABCD])\s*[\.\)\:\-]?\s*$/i', $raw, $m)) {
        return $raw;
    }
    $letter = strtoupper($m[1]);
    $map = [
        'A' => trim($optionA),
        'B' => trim($optionB),
        'C' => trim($optionC),
        'D' => trim($optionD),
    ];
    $text = $map[$letter] ?? '';

    return $text !== '' ? $text : $raw;
}
