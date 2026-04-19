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

/**
 * Two-line quotes for the home dashboard card — fuller lines than the compact quiz pool.
 *
 * @return list<string>
 */
function trytest_exam_dashboard_quote_pool(): array
{
    return [
        "The work you do when no one is watching is what shows up when everyone is.\nKeep building — exam day is just the spotlight.",
        "You do not need to feel ready to begin; you only need to begin to grow.\nSmall sessions today become confidence tomorrow.",
        "Every question you attempt is practice for calm under pressure.\nTrust the process and stay curious about what you miss.",
        "Progress is rarely loud — it is the quiet habit of showing up again.\nYou are allowed to learn in public; that is how mastery starts.",
        "Sleep, food, and a clear mind are part of your study plan, not extras.\nCare for yourself like you care about the grade.",
        "Confusion is not failure; it is the edge where learning happens.\nWrite down what puzzles you — that list is your roadmap.",
        "Compare yourself to yesterday’s you, not to someone else’s highlight.\nSteady beats flashy when the paper is in front of you.",
        "Read twice, answer once, and leave time to breathe at the end.\nExams reward clarity as much as knowledge.",
        "You have survived hard weeks before; this one is more of the same muscle.\nBreathe, break tasks down, and finish one step at a time.",
        "The best students are not fearless — they act despite the flutter.\nName the worry, then do the next small task anyway.",
        "Your index number is not a label for your limit; it is a door you walk through.\nKeep walking — consistency is your credential.",
        "When the room goes quiet and the clock ticks, rely on what you practiced.\nRepetition turns panic into pattern.",
        "Ask for help early; pride delayed is stress multiplied.\nTeachers and friends want you to understand, not to struggle alone.",
        "Revision is not rereading — it is retrieving, explaining, and testing yourself.\nTeach the wall; if it makes sense, you know it.",
        "You belong in the room with everyone else sitting the same paper.\nSit tall, read carefully, and trust what you prepared.",
        "One rough quiz does not define your term — it informs your next move.\nAdjust the plan, not your worth.",
        "Energy follows intention: name one win for today before you open the book.\nEven twenty focused minutes move the needle.",
        "The download section is there so practice can feel like the real thing.\nUse answer keys to learn the why, not only the mark.",
    ];
}

/**
 * Random richer two-line quote for the student home “Words for you” card.
 */
function trytest_exam_short_random_message_dashboard(): string
{
    $pool = trytest_exam_dashboard_quote_pool();
    if ($pool === []) {
        return trytest_exam_short_random_message();
    }

    return $pool[random_int(0, count($pool) - 1)];
}
