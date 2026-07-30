<?php

declare(strict_types=1);

/**
 * Copyable JSON formats and AI prompts for admin import / generate flows.
 *
 * @return list<string>
 */
function trytest_question_format_types(): array
{
    return ['mcq', 'fill', 'true_false'];
}

function trytest_question_format_label(string $type): string
{
    return match ($type) {
        'fill' => 'Fill in the blank',
        'true_false' => 'True / False',
        default => 'Multiple choice (MCQ)',
    };
}

/**
 * Example JSON array string for one question type (pretty-printed).
 */
function trytest_question_json_format_example(string $type): string
{
    $type = trytest_normalize_question_format_type($type);
    if ($type === 'fill') {
        $rows = [
            [
                'type' => 'fill',
                'question' => 'HTTP stands for ____.',
                'answer' => 'HyperText Transfer Protocol',
                'topics' => ['Networking'],
            ],
            [
                'type' => 'fill',
                'question' => 'A primary key uniquely identifies a ____ in a table.',
                'answer' => 'row',
                'topics' => ['Database'],
            ],
        ];
    } elseif ($type === 'true_false') {
        $rows = [
            [
                'type' => 'true_false',
                'question' => 'TCP is a connection-oriented protocol.',
                'answer' => 'True',
                'topics' => ['Networking'],
            ],
            [
                'type' => 'true_false',
                'question' => 'HTML is a programming language.',
                'answer' => 'False',
                'topics' => ['Web'],
            ],
        ];
    } else {
        $rows = [
            [
                'type' => 'mcq',
                'question' => 'Which of the following is a primary color?',
                'options' => ['Green', 'Orange', 'Blue', 'Purple'],
                'answer' => 'Blue',
                'topics' => ['Basics'],
            ],
            [
                'type' => 'mcq',
                'question' => 'What does CPU stand for?',
                'options' => [
                    'Central Processing Unit',
                    'Computer Personal Unit',
                    'Central Program Utility',
                    'Control Process Unit',
                ],
                'answer' => 'Central Processing Unit',
                'topics' => ['Hardware'],
            ],
        ];
    }

    return (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Short rules block for AI prompts.
 */
function trytest_question_json_format_rules(string $type): string
{
    $type = trytest_normalize_question_format_type($type);
    if ($type === 'fill') {
        return <<<TXT
Rules:
- Return ONLY a JSON array (no markdown fences, no commentary).
- Every item MUST have "type": "fill".
- Put exactly one blank as ____ in the question stem (four underscores).
- "answer" is the text that fills the blank (string).
- Do NOT include multiple-choice options.
- Optional "topics" array is allowed.
TXT;
    }
    if ($type === 'true_false') {
        return <<<TXT
Rules:
- Return ONLY a JSON array (no markdown fences, no commentary).
- Every item MUST have "type": "true_false".
- "answer" must be exactly "True" or "False" (capital T/F).
- Do NOT invent extra options; the system uses True/False only.
- Optional "topics" array is allowed.
TXT;
    }

    return <<<TXT
Rules:
- Return ONLY a JSON array (no markdown fences, no commentary).
- Every item MUST have "type": "mcq".
- "options" must be exactly 4 distinct strings (A–D choices).
- "answer" must match one of the options exactly (the correct choice text).
- Optional "topics" array is allowed.
TXT;
}

/**
 * Full AI prompt for generating questions of one chosen type.
 *
 * @param list<string> $topics
 */
function trytest_build_ai_question_prompt(
    string $type,
    array $topics,
    int $count,
    string $course = '',
    string $level = ''
): string {
    $type = trytest_normalize_question_format_type($type);
    $label = trytest_question_format_label($type);
    $topicList = implode(', ', $topics);
    $courseLine = $course !== '' ? "Course: {$course}\n" : '';
    $levelLine = $level !== '' ? "Level: {$level}\n" : '';
    $example = trytest_question_json_format_example($type);
    $rules = trytest_question_json_format_rules($type);

    $kindLine = match ($type) {
        'fill' => "Generate {$count} fill-in-the-blank questions ({$label}).",
        'true_false' => "Generate {$count} true/false questions ({$label}).",
        default => "Generate {$count} multiple-choice questions ({$label}).",
    };

    return $kindLine . "\n"
        . "Use one or more of these topics: {$topicList}.\n\n"
        . $courseLine
        . $levelLine
        . "\n"
        . $rules
        . "\n\nExact JSON shape example (follow this structure):\n\n"
        . $example
        . "\n\nMake sure:\n"
        . "- Generate exactly {$count} questions of type \"{$type}\" only (do not mix other types)\n"
        . "- Every question includes at least one value in \"topics\" from: {$topicList}\n"
        . "- Answers are included\n"
        . "- No explanations outside JSON\n"
        . "- Clean valid JSON only";
}

function trytest_normalize_question_format_type(string $raw): string
{
    $t = strtolower(trim($raw));
    $t = str_replace(['-', ' '], '_', $t);
    if (in_array($t, ['true_false', 'truefalse', 'boolean', 'tf', 't_f'], true)) {
        return 'true_false';
    }
    if (in_array($t, ['fill', 'fill_in', 'fillin', 'blank'], true)) {
        return 'fill';
    }

    return 'mcq';
}
