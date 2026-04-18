<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/theory_rubric.php';

$error = '';
$message = '';
$jsonText = '';
$jsonTextContinue = '';
$selectedQuizId = '';

/**
 * @return list<array<string, mixed>>|null
 */
function trytest_decode_json_block(string $raw): ?array
{
    $text = trim($raw);
    if ($text === '') {
        return [];
    }

    // Accept markdown fenced output from AI, e.g. ```json ... ```
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
    $text = preg_replace('/\s*```$/', '', $text) ?? $text;
    $text = trim($text);

    // If there is surrounding commentary, try to isolate the first JSON array/object.
    if ((str_starts_with($text, '[') === false && str_starts_with($text, '{') === false)) {
        $startArr = strpos($text, '[');
        $startObj = strpos($text, '{');
        $starts = array_filter([$startArr, $startObj], static fn ($v) => $v !== false);
        if ($starts !== []) {
            $start = min($starts);
            $endArr = strrpos($text, ']');
            $endObj = strrpos($text, '}');
            $end = max((int) ($endArr === false ? -1 : $endArr), (int) ($endObj === false ? -1 : $endObj));
            if ($end >= $start) {
                $text = trim(substr($text, $start, $end - $start + 1));
            }
        }
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        // { "questions": [ {...}, ... ] } from AI tools
        if (isset($decoded['questions']) && is_array($decoded['questions'])) {
            return array_values($decoded['questions']);
        }
        // Gemini-style buckets: mcq_questions, fill_in_questions, theory_questions
        $bucketOrder = ['mcq_questions', 'fill_in_questions', 'theory_questions', 'fill_questions'];
        $merged = [];
        foreach ($bucketOrder as $bk) {
            if (!empty($decoded[$bk]) && is_array($decoded[$bk])) {
                foreach ($decoded[$bk] as $row) {
                    if (is_array($row)) {
                        $merged[] = $row;
                    }
                }
            }
        }
        if ($merged !== []) {
            return $merged;
        }
        // If one object was provided instead of an array, normalize to list.
        if (array_keys($decoded) !== range(0, count($decoded) - 1)) {
            return [$decoded];
        }
        return $decoded;
    }

    // Accept object fragments separated by commas/newlines.
    $wrapped = '[' . trim($text, ", \t\n\r\0\x0B") . ']';
    $decodedWrapped = json_decode($wrapped, true);
    if (is_array($decodedWrapped)) {
        return $decodedWrapped;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $user = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        if (trytest_admin_count($db) < 1) {
            $error = 'No administrator account yet. Create one from the admin dashboard.';
        } elseif (trytest_admin_attempt_login($db, $user, $pass)) {
            trytest_redirect(trytest_url('dashboard/import_json'));
        } else {
            $error = 'Invalid admin username or password.';
        }
    } elseif (!empty($_SESSION['is_admin']) && $action === 'import_json') {
        $selectedQuizId = trim((string) ($_POST['quiz_id'] ?? ''));
        $quizId = ctype_digit($selectedQuizId) ? (int) $selectedQuizId : 0;
        $jsonText = trim((string) ($_POST['json'] ?? ''));
        $jsonTextContinue = trim((string) ($_POST['json_continue'] ?? ''));

        if ($quizId < 1 || $jsonText === '') {
            $error = 'Select a quiz and paste JSON.';
        } else {
            $check = $db->prepare('SELECT COUNT(*) FROM quizzes WHERE id = ?');
            $check->execute([$quizId]);
            if ((int) $check->fetchColumn() === 0) {
                $error = 'Selected quiz does not exist.';
            } else {
                $part1 = trytest_decode_json_block($jsonText);
                $part2 = trytest_decode_json_block($jsonTextContinue);
                if ($part1 === null || $part2 === null) {
                    $error = 'Invalid JSON format. Ensure each part is valid JSON array/object.';
                } else {
                    $data = array_merge($part1, $part2);
                    $stmt = $db->prepare(
                        'INSERT INTO questions (quiz_id, question_type, question, option_a, option_b, option_c, option_d, correct_answer, theory_rubric, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );

                    $imported = 0;
                    $skippedDuplicates = 0;
                    $seenQuestions = [];
                    $db->beginTransaction();
                    try {
                        foreach ($data as $item) {
                            if (!is_array($item)) {
                                continue;
                            }
                            $question = trim((string) ($item['question'] ?? ''));
                            if ($question === '') {
                                continue;
                            }
                            $options = $item['options'] ?? null;
                            $answerRaw = $item['answer'] ?? null;
                            $answerField = '';
                            $extraAcceptFromAnswer = [];
                            if (is_array($answerRaw)) {
                                $parts = array_values(
                                    array_filter(
                                        array_map(static fn ($x) => trim((string) $x), $answerRaw),
                                        static fn ($x) => $x !== ''
                                    )
                                );
                                $answerField = $parts[0] ?? '';
                                $extraAcceptFromAnswer = array_slice($parts, 1);
                            } else {
                                $answerField = trim((string) $answerRaw);
                            }
                            $kwIn = $item['keywords'] ?? [];
                            if (!is_array($kwIn)) {
                                $kwIn = [];
                            }
                            $accIn = $item['accept'] ?? $item['acceptable_answers'] ?? [];
                            if (!is_array($accIn)) {
                                $accIn = [];
                            }
                            $accIn = array_merge($accIn, $extraAcceptFromAnswer);

                            $typeRaw = strtolower(trim((string) ($item['type'] ?? '')));
                            $type = $typeRaw;
                            if (!in_array($type, ['mcq', 'fill', 'theory'], true)) {
                                if (strpos($question, '____') !== false) {
                                    $type = 'fill';
                                } elseif (
                                    is_array($options)
                                    && count($options) >= 4
                                    && trim((string) ($options[0] ?? '')) !== ''
                                    && trim((string) ($options[1] ?? '')) !== ''
                                    && trim((string) ($options[2] ?? '')) !== ''
                                    && trim((string) ($options[3] ?? '')) !== ''
                                ) {
                                    $type = 'mcq';
                                } else {
                                    $type = 'theory';
                                }
                            }

                            $qKey = mb_strtolower($question);
                            if (isset($seenQuestions[$qKey])) {
                                $skippedDuplicates++;
                                continue;
                            }

                            if ($type === 'mcq') {
                                if ($answerField === '' || !is_array($options) || count($options) < 4) {
                                    continue;
                                }
                                $optA = trim((string) ($options[0] ?? ''));
                                $optB = trim((string) ($options[1] ?? ''));
                                $optC = trim((string) ($options[2] ?? ''));
                                $optD = trim((string) ($options[3] ?? ''));
                                if ($optA === '' || $optB === '' || $optC === '' || $optD === '') {
                                    continue;
                                }
                                $seenQuestions[$qKey] = true;
                                $stmt->execute([$quizId, 'mcq', $question, $optA, $optB, $optC, $optD, $answerField, null, 'pending']);
                                $imported++;
                                continue;
                            }

                            if ($type === 'fill') {
                                if ($answerField === '' || strpos($question, '____') === false) {
                                    continue;
                                }
                                $seenQuestions[$qKey] = true;
                                $stmt->execute([$quizId, 'fill', $question, '', '', '', '', $answerField, null, 'pending']);
                                $imported++;
                                continue;
                            }

                            if ($type === 'theory') {
                                if ($answerField === '' && $kwIn === [] && $accIn === []) {
                                    continue;
                                }
                                $answerFromKeywordsOnly = false;
                                if ($answerField === '' && $accIn !== []) {
                                    $answerField = trim((string) ($accIn[0] ?? ''));
                                    $accIn = array_values(
                                        array_filter(
                                            array_map('trim', array_slice($accIn, 1)),
                                            static fn ($x) => $x !== ''
                                        )
                                    );
                                }
                                if ($answerField === '' && $kwIn !== []) {
                                    $joined = implode(', ', array_map('trim', $kwIn));
                                    $answerField = strlen($joined) > 220 ? substr($joined, 0, 217) . '...' : $joined;
                                    $answerFromKeywordsOnly = true;
                                }
                                $acceptForRubric = $accIn;
                                if ($answerField !== '' && !$answerFromKeywordsOnly) {
                                    array_unshift($acceptForRubric, $answerField);
                                }
                                $rubricJson = trytest_theory_rubric_encode(
                                    array_map(static fn ($x) => trim((string) $x), $kwIn),
                                    array_map(static fn ($x) => trim((string) $x), $acceptForRubric)
                                );
                                $seenQuestions[$qKey] = true;
                                $stmt->execute([$quizId, 'theory', $question, '', '', '', '', $answerField, $rubricJson, 'pending']);
                                $imported++;
                            }
                        }
                        $db->commit();
                        if ($imported > 0) {
                            $message = $imported . ' questions imported successfully.';
                            if ($skippedDuplicates > 0) {
                                $message .= ' ' . $skippedDuplicates . ' duplicates skipped while merging parts.';
                            }
                        } else {
                            $message = 'No valid question records found in JSON.';
                        }
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $error = 'Import failed. Check JSON and database permissions.';
                    }
                }
            }
        }
    }
}

$isAdmin = !empty($_SESSION['is_admin']);
$quizzes = $db->query('SELECT id, title FROM quizzes ORDER BY id DESC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Trytest — Import JSON</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(trytest_url('favicon.svg'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body{font-family:Inter,ui-sans-serif,system-ui,sans-serif}</style>
</head>
<body class="bg-slate-50 min-h-screen p-4">
    <div class="max-w-5xl mx-auto py-4 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Import JSON Questions</h1>
                <p class="text-sm text-slate-500 mt-1">Merge partial AI outputs and import in one clean step.</p>
                <p class="text-xs text-slate-600 mt-2 max-w-3xl">Supported shapes: a JSON <strong>array</strong> of items; <code class="rounded bg-slate-100 px-1">{&quot;questions&quot;:[...]}</code>; or bucketed objects <code class="rounded bg-slate-100 px-1">mcq_questions</code>, <code class="rounded bg-slate-100 px-1">fill_in_questions</code>, <code class="rounded bg-slate-100 px-1">theory_questions</code> (and optional <code class="rounded bg-slate-100 px-1">fill_questions</code>) which are merged in that order. Use <code class="rounded bg-slate-100 px-1">type</code> <code class="rounded bg-slate-100 px-1">mcq</code> (four <code class="rounded bg-slate-100 px-1">options</code> + <code class="rounded bg-slate-100 px-1">answer</code>), <code class="rounded bg-slate-100 px-1">fill</code> (<code class="rounded bg-slate-100 px-1">____</code> + <code class="rounded bg-slate-100 px-1">answer</code>), or <code class="rounded bg-slate-100 px-1">theory</code> with optional <code class="rounded bg-slate-100 px-1">keywords</code> / <code class="rounded bg-slate-100 px-1">accept</code> and <code class="rounded bg-slate-100 px-1">answer</code> string or array.</p>
            </div>
            <a href="<?php echo htmlspecialchars(trytest_home_url(), ENT_QUOTES, 'UTF-8'); ?>" class="text-sm text-indigo-600">Back to dashboard</a>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sm:p-6 space-y-4">

            <?php if ($error !== ''): ?>
                <div class="rounded-lg bg-red-100 text-red-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($message !== ''): ?>
                <div class="rounded-lg bg-emerald-100 text-emerald-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if (!$isAdmin): ?>
                <p class="text-slate-600">Login to import AI JSON questions.</p>
                <form method="post" class="space-y-3 max-w-md">
                    <input type="hidden" name="action" value="login">
                    <input class="w-full rounded-lg border border-slate-300 px-3 py-2" type="text" name="username" placeholder="Username" required>
                    <input class="w-full rounded-lg border border-slate-300 px-3 py-2" type="password" name="password" placeholder="Password" required>
                    <button class="w-full rounded-lg bg-slate-900 text-white py-2 font-medium" type="submit">Login</button>
                </form>
            <?php else: ?>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="import_json">
                    <div class="rounded-xl border border-slate-200 p-3 sm:p-4 space-y-3">
                        <label class="block text-sm font-medium text-slate-700">Select quiz</label>
                        <select class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm" name="quiz_id" required>
                            <option value="">Choose quiz for this import</option>
                            <?php foreach ($quizzes as $quiz): ?>
                                <option value="<?php echo (int) $quiz['id']; ?>" <?php echo ($selectedQuizId === (string) $quiz['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $quiz['title'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="rounded-xl border border-slate-200 p-3 sm:p-4 space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-semibold text-slate-800">JSON Part 1</p>
                                <button type="button" id="copyJsonBtn" class="rounded-lg bg-slate-800 px-3 py-2 text-xs text-white">Copy part 1</button>
                            </div>
                            <p class="text-xs text-slate-500">Paste the first response from AI.</p>
                            <textarea id="jsonInput" name="json" rows="12" class="w-full rounded-lg border border-slate-300 p-3 font-mono text-sm" placeholder='Paste first AI JSON response here...' required><?php echo htmlspecialchars($jsonText, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="rounded-xl border border-slate-200 p-3 sm:p-4 space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-semibold text-slate-800">JSON Continuation (optional)</p>
                                <button type="button" id="appendJsonBtn" class="rounded-lg bg-[#2C6A7D] px-3 py-2 text-xs text-white">Append part 2 into part 1</button>
                            </div>
                            <p class="text-xs text-slate-500">Paste remaining questions if AI stopped early.</p>
                            <textarea id="jsonInputContinue" name="json_continue" rows="12" class="w-full rounded-lg border border-slate-300 p-3 font-mono text-sm" placeholder='Paste continuation JSON here (e.g remaining questions)...'><?php echo htmlspecialchars($jsonTextContinue, ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <p class="text-xs text-slate-500">Importer merges both parts and skips duplicates using question text.</p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-emerald-100 bg-emerald-50/60 p-3">
                        <button class="w-full rounded-lg bg-emerald-600 text-white p-3 font-semibold" type="submit">Import Questions</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($isAdmin): ?>
    <script>
        (function () {
            var btn = document.getElementById('copyJsonBtn');
            var box = document.getElementById('jsonInput');
            var continueBox = document.getElementById('jsonInputContinue');
            var appendBtn = document.getElementById('appendJsonBtn');
            if (!btn || !box) return;
            btn.addEventListener('click', function () {
                var text = box.value || '';
                if (!text.trim()) return;
                navigator.clipboard.writeText(text).then(function () {
                    btn.textContent = 'Copied';
                    setTimeout(function () { btn.textContent = 'Copy part 1'; }, 1200);
                });
            });
            appendBtn?.addEventListener('click', function () {
                if (!continueBox || !continueBox.value.trim()) return;
                box.value = (box.value.trim() ? box.value.trim() + "\n\n" : '') + continueBox.value.trim();
                continueBox.value = '';
                appendBtn.textContent = 'Appended';
                setTimeout(function () { appendBtn.textContent = 'Append part 2 into part 1'; }, 1200);
            });
        })();
    </script>
    <?php endif; ?>
</body>
</html>
