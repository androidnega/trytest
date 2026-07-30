<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/theory_rubric.php';
require_once __DIR__ . '/includes/question_json_formats.php';

$error = '';
$message = '';
$jsonText = '';
$jsonTextContinue = '';
$selectedQuizId = '';
$importFormat = 'any';

/**
 * Turn one decoded JSON value (object or list) into a flat list of question rows.
 *
 * @param array<string, mixed> $decoded
 * @return list<array<string, mixed>>
 */
function trytest_json_value_to_question_rows(array $decoded): array
{
    if (isset($decoded['questions']) && is_array($decoded['questions'])) {
        return array_values($decoded['questions']);
    }
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
    if (array_keys($decoded) !== range(0, count($decoded) - 1)) {
        return [$decoded];
    }

    return $decoded;
}

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
        return trytest_json_value_to_question_rows($decoded);
    }

    // Multiple top-level objects: { "questions":[...] }{ "questions":[...] } (invalid single JSON, common from Gemini)
    if (str_starts_with($text, '{')) {
        $chunks = preg_split('/(?<=\})\s*(?=\{)/', $text);
        if (is_array($chunks) && count($chunks) > 1) {
            $merged = [];
            foreach ($chunks as $chunk) {
                $chunk = trim((string) $chunk);
                if ($chunk === '') {
                    continue;
                }
                $piece = json_decode($chunk, true);
                if (!is_array($piece)) {
                    continue;
                }
                $merged = array_merge($merged, trytest_json_value_to_question_rows($piece));
            }
            if ($merged !== []) {
                return $merged;
            }
        }
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
        $importFormatRaw = trim((string) ($_POST['import_format'] ?? 'any'));
        $importFormat = $importFormatRaw === 'any'
            ? 'any'
            : trytest_normalize_question_format_type($importFormatRaw);

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
                if ($part1 === null) {
                    $error = 'Invalid JSON in Part 1. Use a single array, one object with "questions", bucket keys (mcq_questions, …), or multiple objects like {...}{...} pasted together.';
                } elseif ($jsonTextContinue !== '' && $part2 === null) {
                    $error = 'Invalid JSON in Part 2 (continuation).';
                } else {
                    $data = array_merge($part1, $part2 ?? []);
                    $stmt = $db->prepare(
                        'INSERT INTO questions (quiz_id, question_type, question, option_a, option_b, option_c, option_d, correct_answer, theory_rubric, sql_practice, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );

                    $imported = 0;
                    $skippedDuplicates = 0;
                    $skippedWrongType = 0;
                    $seenQuestions = [];
                    $db->beginTransaction();
                    try {
                        $db->prepare('DELETE FROM questions WHERE quiz_id = ?')->execute([$quizId]);
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
                            if (in_array($type, ['true_false', 'truefalse', 'boolean', 'tf', 't_f'], true)) {
                                $type = 'true_false';
                            } elseif (in_array($type, ['fill', 'fill_in', 'fillin', 'blank'], true)) {
                                $type = 'fill';
                            } elseif ($type === 'mcq') {
                                $type = 'mcq';
                            } elseif (!in_array($type, ['mcq', 'fill', 'theory', 'true_false'], true)) {
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

                            if ($importFormat !== 'any') {
                                if ($type !== $importFormat) {
                                    // Soft coerce when AI forgot the type field but shape matches.
                                    if ($importFormat === 'true_false' && $type !== 'true_false') {
                                        $ansNorm = strtolower($answerField);
                                        if (in_array($ansNorm, ['true', 'false', 't', 'f', 'yes', 'no'], true)) {
                                            $type = 'true_false';
                                        } else {
                                            $skippedWrongType++;
                                            continue;
                                        }
                                    } elseif ($importFormat === 'fill' && $type !== 'fill') {
                                        if (strpos($question, '____') !== false && $answerField !== '') {
                                            $type = 'fill';
                                        } else {
                                            $skippedWrongType++;
                                            continue;
                                        }
                                    } elseif ($importFormat === 'mcq' && $type !== 'mcq') {
                                        if (is_array($options) && count($options) >= 4) {
                                            $type = 'mcq';
                                        } else {
                                            $skippedWrongType++;
                                            continue;
                                        }
                                    } else {
                                        $skippedWrongType++;
                                        continue;
                                    }
                                }
                            }

                            $qKey = mb_strtolower($question);
                            if (isset($seenQuestions[$qKey])) {
                                $skippedDuplicates++;
                                continue;
                            }

                            if ($type === 'true_false') {
                                $ansNorm = strtolower($answerField);
                                if (in_array($ansNorm, ['t', 'yes', '1'], true)) {
                                    $answerField = 'True';
                                } elseif (in_array($ansNorm, ['f', 'no', '0'], true)) {
                                    $answerField = 'False';
                                } elseif ($ansNorm === 'true') {
                                    $answerField = 'True';
                                } elseif ($ansNorm === 'false') {
                                    $answerField = 'False';
                                }
                                if (!in_array($answerField, ['True', 'False'], true)) {
                                    continue;
                                }
                                $seenQuestions[$qKey] = true;
                                $stmt->execute([$quizId, 'mcq', $question, 'True', 'False', '', '', $answerField, null, null, 'approved']);
                                $imported++;
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
                                $stmt->execute([$quizId, 'mcq', $question, $optA, $optB, $optC, $optD, $answerField, null, null, 'approved']);
                                $imported++;
                                continue;
                            }

                            if ($type === 'fill') {
                                if ($answerField === '' || strpos($question, '____') === false) {
                                    continue;
                                }
                                $seenQuestions[$qKey] = true;
                                $stmt->execute([$quizId, 'fill', $question, '', '', '', '', $answerField, null, null, 'approved']);
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
                                $stmt->execute([$quizId, 'theory', $question, '', '', '', '', $answerField, $rubricJson, null, 'approved']);
                                $imported++;
                                continue;
                            }
                        }
                        if ($imported > 0) {
                            $db->commit();
                            $message = $imported . ' questions imported (replaced previous questions for this quiz).';
                            if ($skippedDuplicates > 0) {
                                $message .= ' ' . $skippedDuplicates . ' duplicates skipped while merging parts.';
                            }
                            if ($skippedWrongType > 0) {
                                $message .= ' ' . $skippedWrongType . ' skipped (wrong question type for your filter).';
                            }
                        } else {
                            if ($db->inTransaction()) {
                                $db->rollBack();
                            }
                            $message = 'No valid question records found in JSON. Existing questions were left unchanged.';
                            if ($skippedWrongType > 0) {
                                $message .= ' (' . $skippedWrongType . ' had a different type than selected.)';
                            }
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
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$activeFormatPreview = $importFormat === 'any' ? 'mcq' : $importFormat;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php trytest_link_preview_meta(['title' => 'Trytest — Import JSON', 'description' => 'Trytest admin: import exam JSON.']); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Trytest — Import JSON</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo $h(trytest_url('favicon.svg')); ?>">
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
                <p class="text-sm text-slate-500 mt-1">Copy a JSON format for AI, choose the question type, then paste the response. Importing <strong>replaces all existing questions</strong> for the selected quiz.</p>
            </div>
            <div class="flex flex-wrap gap-3 text-sm">
                <a href="<?php echo $h(trytest_url('dashboard/generate_ai')); ?>" class="text-indigo-600">AI prompt builder</a>
                <a href="<?php echo $h(trytest_home_url()); ?>" class="text-indigo-600">Back to dashboard</a>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sm:p-6 space-y-4">

            <?php if ($error !== ''): ?>
                <div class="rounded-lg bg-red-100 text-red-700 px-3 py-2 text-sm"><?php echo $h($error); ?></div>
            <?php endif; ?>
            <?php if ($message !== ''): ?>
                <div class="rounded-lg bg-emerald-100 text-emerald-700 px-3 py-2 text-sm"><?php echo $h($message); ?></div>
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
                <div class="rounded-xl border border-indigo-100 bg-indigo-50/50 p-3 sm:p-4 space-y-3">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">Copy JSON format for AI</p>
                            <p class="text-xs text-slate-600 mt-0.5">Choose the kind of questions you want, copy the sample, and paste it into ChatGPT/Gemini with your topic.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach (trytest_question_format_types() as $ft): ?>
                                <button type="button" class="import-format-tab rounded-lg border px-3 py-1.5 text-xs font-semibold <?php echo $activeFormatPreview === $ft ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300 bg-white text-slate-700'; ?>" data-format="<?php echo $h($ft); ?>"><?php echo $h(trytest_question_format_label($ft)); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" id="copyImportFormatBtn" class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">Copy JSON format</button>
                        <span id="copyImportFormatStatus" class="self-center text-xs text-emerald-700"></span>
                    </div>
                    <textarea readonly id="importFormatBox" class="w-full h-44 p-3 border border-slate-300 rounded-lg font-mono text-xs bg-white"><?php echo $h(trytest_question_json_format_example($activeFormatPreview)); ?></textarea>
                </div>

                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="import_json">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl border border-slate-200 p-3 sm:p-4 space-y-3">
                            <label class="block text-sm font-medium text-slate-700">Select quiz</label>
                            <select class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm" name="quiz_id" required>
                                <option value="">Choose quiz for this import</option>
                                <?php foreach ($quizzes as $quiz): ?>
                                    <option value="<?php echo (int) $quiz['id']; ?>" <?php echo ($selectedQuizId === (string) $quiz['id']) ? 'selected' : ''; ?>>
                                        <?php echo $h((string) $quiz['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="rounded-xl border border-slate-200 p-3 sm:p-4 space-y-3">
                            <label class="block text-sm font-medium text-slate-700">Question type for this import</label>
                            <select class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm" name="import_format" id="importFormatSelect">
                                <option value="any" <?php echo $importFormat === 'any' ? 'selected' : ''; ?>>Any / mixed (auto-detect)</option>
                                <?php foreach (trytest_question_format_types() as $ft): ?>
                                    <option value="<?php echo $h($ft); ?>" <?php echo $importFormat === $ft ? 'selected' : ''; ?>><?php echo $h(trytest_question_format_label($ft)); ?> only</option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-slate-500">Pick one type so only that kind is imported (recommended).</p>
                        </div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="rounded-xl border border-slate-200 p-3 sm:p-4 space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-semibold text-slate-800">JSON Part 1</p>
                                <button type="button" id="copyJsonBtn" class="rounded-lg bg-slate-800 px-3 py-2 text-xs text-white">Copy part 1</button>
                            </div>
                            <p class="text-xs text-slate-500">Paste the first response from AI.</p>
                            <textarea id="jsonInput" name="json" rows="12" class="w-full rounded-lg border border-slate-300 p-3 font-mono text-sm" placeholder='Paste first AI JSON response here...' required><?php echo $h($jsonText); ?></textarea>
                        </div>

                        <div class="rounded-xl border border-slate-200 p-3 sm:p-4 space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-semibold text-slate-800">JSON Continuation (optional)</p>
                                <button type="button" id="appendJsonBtn" class="rounded-lg bg-[#2C6A7D] px-3 py-2 text-xs text-white">Append part 2 into part 1</button>
                            </div>
                            <p class="text-xs text-slate-500">Paste remaining questions if AI stopped early.</p>
                            <textarea id="jsonInputContinue" name="json_continue" rows="12" class="w-full rounded-lg border border-slate-300 p-3 font-mono text-sm" placeholder='Paste continuation JSON here (e.g remaining questions)...'><?php echo $h($jsonTextContinue); ?></textarea>
                            <p class="text-xs text-slate-500">Part 1 and Part 2 are merged; duplicate question text within the paste is skipped once. The quiz&rsquo;s prior questions are removed when at least one new row is imported.</p>
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
            var formats = <?php echo json_encode([
                'mcq' => trytest_question_json_format_example('mcq'),
                'fill' => trytest_question_json_format_example('fill'),
                'true_false' => trytest_question_json_format_example('true_false'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            var formatBox = document.getElementById('importFormatBox');
            var formatSelect = document.getElementById('importFormatSelect');
            var copyFormatBtn = document.getElementById('copyImportFormatBtn');
            var copyFormatStatus = document.getElementById('copyImportFormatStatus');
            var tabs = document.querySelectorAll('.import-format-tab');

            function setPreview(ft) {
                if (!formats[ft]) ft = 'mcq';
                if (formatBox) formatBox.value = formats[ft];
                tabs.forEach(function (btn) {
                    var on = btn.getAttribute('data-format') === ft;
                    btn.classList.toggle('border-indigo-600', on);
                    btn.classList.toggle('bg-indigo-600', on);
                    btn.classList.toggle('text-white', on);
                    btn.classList.toggle('border-slate-300', !on);
                    btn.classList.toggle('bg-white', !on);
                    btn.classList.toggle('text-slate-700', !on);
                });
                if (formatSelect && formatSelect.value !== 'any') {
                    formatSelect.value = ft;
                }
            }

            tabs.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var ft = btn.getAttribute('data-format') || 'mcq';
                    setPreview(ft);
                    if (formatSelect) formatSelect.value = ft;
                });
            });
            formatSelect?.addEventListener('change', function () {
                var v = formatSelect.value;
                if (v !== 'any' && formats[v]) setPreview(v);
            });
            copyFormatBtn?.addEventListener('click', function () {
                var text = formatBox?.value || '';
                if (!text) return;
                navigator.clipboard.writeText(text).then(function () {
                    if (copyFormatStatus) copyFormatStatus.textContent = 'Format copied — paste into AI with your topic';
                    copyFormatBtn.textContent = 'Copied';
                    setTimeout(function () {
                        copyFormatBtn.textContent = 'Copy JSON format';
                        if (copyFormatStatus) copyFormatStatus.textContent = '';
                    }, 1600);
                });
            });

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
