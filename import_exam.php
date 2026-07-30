<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/admin_auth.php';
$dashboardUrl = trytest_home_url();

$error = '';
$message = '';
$isAdmin = !empty($_SESSION['is_admin']);

/**
 * Strip citation markers and normalize newlines for pasted exam / AI output.
 */
function trytest_exam_import_normalize_text(string $s): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/\[cite_start\]\s*/iu', '', $s) ?? $s;
    $s = preg_replace('/\[cite:\s*[^\]\n]+\]/iu', '', $s) ?? $s;
    return trim($s);
}

/**
 * @return array<int, string>
 */
function buildAnswerMap(string $answersPart): array
{
    $answersPart = trytest_exam_import_normalize_text($answersPart);
    $answers = [];
    // "1. B", "1) B", "1: B", "1 B" at line start
    preg_match_all('/(?:^|\n)\s*(\d+)\s*[\.\)\:]?\s*([A-D])\b/im', $answersPart, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $answers[(int) $match[1]] = strtoupper($match[2]);
    }

    return $answers;
}

/**
 * Parse one question block (stem + option lines A-D or A-C).
 *
 * @return array{question:string,options:array<string,string>,raw:array<string,string>}|null
 */
function trytest_exam_import_parse_question_body(string $body): ?array
{
    $body = trim($body);
    if ($body === '') {
        return null;
    }
    $lines = preg_split('/\n/', $body) ?: [];
    $stem = [];
    /** @var array<string,string> */
    $opts = ['A' => '', 'B' => '', 'C' => '', 'D' => ''];
    /** @var array<string,string> */
    $rawOpts = ['A' => '', 'B' => '', 'C' => '', 'D' => ''];
    $current = null;

    foreach ($lines as $rawLine) {
        $line = trim($rawLine);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^([A-D])[\.\)]\s*(.*)$/iu', $line, $m)) {
            $current = strtoupper($m[1]);
            $text = trim((string) $m[2]);
            $rawOpts[$current] = $text;
            $opts[$current] = preg_replace('/\s*\*{3,}\s*$/', '', $text) ?? $text;
            $opts[$current] = preg_replace('/\s+/', ' ', trim($opts[$current])) ?? trim($opts[$current]);
            continue;
        }
        if ($current !== null) {
            $rawOpts[$current] = trim($rawOpts[$current] . "\n" . $line);
            $opts[$current] = trim($opts[$current] . ' ' . $line);
            $opts[$current] = preg_replace('/\s*\*{3,}\s*$/', '', $opts[$current]) ?? $opts[$current];
            $opts[$current] = preg_replace('/\s+/', ' ', trim($opts[$current])) ?? trim($opts[$current]);
            continue;
        }
        $stem[] = $line;
    }

    $question = trim(preg_replace('/\s+/', ' ', implode(' ', $stem)) ?? '');
    if ($question === '') {
        return null;
    }

    $filled = 0;
    foreach (['A', 'B', 'C', 'D'] as $L) {
        if (trim($opts[$L]) !== '') {
            $filled++;
        }
    }
    if ($filled < 2) {
        return null;
    }

    return ['question' => $question, 'options' => $opts, 'raw' => $rawOpts];
}

/**
 * @return array<int, array{number:int, question:string, options:array<string,string>, correct_letter:string}>
 */
function parseExamQuestions(string $questionsPart, array $answerMap): array
{
    $questionsPart = trytest_exam_import_normalize_text($questionsPart);
    $rows = [];
    preg_match_all(
        '/(?:^|\n)\s*(\d+)\.\s*(.+?)(?=\n\s*\d+\.\s|\z)/s',
        $questionsPart,
        $blocks,
        PREG_SET_ORDER
    );

    foreach ($blocks as $block) {
        $number = (int) ($block[1] ?? 0);
        $body = trim((string) ($block[2] ?? ''));
        if ($number < 1 || $body === '') {
            continue;
        }

        $parsed = trytest_exam_import_parse_question_body($body);
        if ($parsed === null) {
            continue;
        }

        $options = $parsed['options'];
        $rawOptions = $parsed['raw'];
        $question = $parsed['question'];

        $correctLetter = strtoupper((string) ($answerMap[$number] ?? ''));
        if (!in_array($correctLetter, ['A', 'B', 'C', 'D'], true)) {
            foreach (['A', 'B', 'C', 'D'] as $letter) {
                if (preg_match('/\*{3,}\s*$/', (string) ($rawOptions[$letter] ?? '')) === 1) {
                    $correctLetter = $letter;
                    break;
                }
            }
        }

        if (!in_array($correctLetter, ['A', 'B', 'C', 'D'], true)) {
            continue;
        }
        if (trim($options[$correctLetter] ?? '') === '') {
            continue;
        }

        foreach (['A', 'B', 'C', 'D'] as $L) {
            if (trim($options[$L]) === '') {
                $options[$L] = '';
            }
        }

        $rows[] = [
            'number' => $number,
            'question' => $question,
            'options' => [
                'A' => $options['A'],
                'B' => $options['B'],
                'C' => $options['C'],
                'D' => $options['D'],
            ],
            'correct_letter' => $correctLetter,
        ];
    }

    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $user = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        if (trytest_admin_count($db) < 1) {
            $error = 'No administrator account yet. Create one from the admin dashboard.';
        } elseif (trytest_admin_attempt_login($db, $user, $pass)) {
            trytest_redirect(trytest_url('dashboard/import_exam'));
        } else {
            $error = 'Invalid admin username or password.';
        }
    }

    if (!empty($_SESSION['is_admin']) && $action === 'import_exam') {
        $quizId = (int) ($_POST['quiz_id'] ?? 0);
        $rawText = trim((string) ($_POST['exam_text'] ?? ''));

        if ($quizId < 1 || $rawText === '') {
            $error = 'Select a quiz and paste exam text.';
        } else {
            $check = $db->prepare('SELECT COUNT(*) FROM quizzes WHERE id = ?');
            $check->execute([$quizId]);
            if ((int) $check->fetchColumn() === 0) {
                $error = 'Selected quiz does not exist.';
            } else {
                $parts = preg_split('/ANSWER\s*KEY/i', $rawText, 2);
                $questionsPart = trim((string) ($parts[0] ?? ''));
                $answersPart = trim((string) ($parts[1] ?? ''));

                $answerMap = buildAnswerMap($answersPart);
                $parsed = parseExamQuestions($questionsPart, $answerMap);
                if (!$parsed && preg_match('/ANSWER\s*KEY/i', $rawText) !== 1) {
                    $parsed = parseExamQuestions($questionsPart, []);
                }

                if (!$parsed) {
                    $error = 'No valid questions found. Use numbered questions with A-D options and include ANSWER KEY.';
                } else {
                    $ins = $db->prepare(
                        'INSERT INTO questions (quiz_id, question_type, question, option_a, option_b, option_c, option_d, correct_answer, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );

                    $db->beginTransaction();
                    try {
                        foreach ($parsed as $item) {
                            $ins->execute([
                                $quizId,
                                'mcq',
                                $item['question'],
                                $item['options']['A'],
                                $item['options']['B'],
                                $item['options']['C'],
                                $item['options']['D'],
                                $item['options'][$item['correct_letter']],
                                'approved',
                            ]);
                        }
                        $db->commit();
                        $message = count($parsed) . ' questions imported successfully.';
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $error = 'Import failed. Check database write permissions and try again.';
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
    <?php trytest_link_preview_meta(['title' => 'Trytest — Import exam', 'description' => 'Trytest admin: import an exam file.']); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest — Import exam</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(trytest_url('favicon.svg'), ENT_QUOTES, 'UTF-8'); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white min-h-screen p-4">
    <div class="max-w-3xl mx-auto pt-8 pb-10">
        <div class="bg-white rounded-2xl shadow p-6">
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-2xl font-bold text-slate-900">Import Exam Questions</h1>
                <a href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'); ?>" class="text-sm text-indigo-600">Back to dashboard</a>
            </div>

            <?php if ($error !== ''): ?>
                <div class="mb-4 rounded-lg bg-red-100 text-red-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($message !== ''): ?>
                <div class="mb-4 rounded-lg bg-emerald-100 text-emerald-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if (!$isAdmin): ?>
                <p class="text-slate-600 mb-4">Login to import full exam text.</p>
                <form method="post" class="space-y-3">
                    <input type="hidden" name="action" value="login">
                    <input class="w-full rounded-lg border border-slate-300 px-3 py-2" type="text" name="username" placeholder="Username" required>
                    <input class="w-full rounded-lg border border-slate-300 px-3 py-2" type="password" name="password" placeholder="Password" required>
                    <button class="w-full rounded-lg bg-slate-900 text-white py-2 font-medium" type="submit">Login</button>
                </form>
            <?php else: ?>
                <form method="post" class="space-y-3">
                    <input type="hidden" name="action" value="import_exam">

                    <select class="w-full rounded-lg border border-slate-300 px-3 py-2" name="quiz_id" required>
                        <option value="">Select quiz</option>
                        <?php foreach ($quizzes as $quiz): ?>
                            <option value="<?php echo (int) $quiz['id']; ?>">
                                <?php echo htmlspecialchars((string) $quiz['title'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <textarea name="exam_text" rows="15" class="w-full p-3 border border-slate-300 rounded font-mono text-sm" placeholder="Paste full exam text (questions + options + ANSWER KEY)..." required></textarea>

                    <button class="mt-3 w-full rounded-lg bg-blue-600 text-white p-3 font-medium" type="submit">
                        Import Questions
                    </button>
                </form>

                <details class="mt-4 rounded-lg border border-slate-200 bg-slate-50/80 p-3 text-sm text-slate-700">
                    <summary class="cursor-pointer font-semibold text-slate-900">Format for paste / AI output (plain text)</summary>
                    <p class="mt-2 text-xs leading-relaxed text-slate-600">
                        Use one block per question: a line starting with <code class="rounded bg-white px-1">1.</code>, <code class="rounded bg-white px-1">2.</code>, … then the stem, then each option on its own line starting with
                        <code class="rounded bg-white px-1">A.</code> / <code class="rounded bg-white px-1">B)</code> etc. (3 or 4 options is fine; leave D out if unused.)
                        LaTeX like <code class="rounded bg-white px-1">$f=1/T$</code> is kept. Trailing <code class="rounded bg-white px-1">***</code> on an option marks the correct answer when there is no answer key.
                        Citations like <code class="rounded bg-white px-1">[cite_start]</code> or <code class="rounded bg-white px-1">[cite: 1, 2]</code> are stripped automatically.
                    </p>
                    <pre class="mt-2 max-h-64 overflow-auto rounded-md border border-slate-200 bg-white p-2 text-[11px] leading-snug text-slate-800"><?php echo htmlspecialchars(
                        <<<'FORMAT'
1. What is the primary scope of data communication?
A. The physical transmission of electrical power
B. The exchange of data between two devices via some form of transmission medium
C. The storage and retrieval of information from a database
D. The processing of data by a single computer's CPU

5. In a data communication system, the _________ is the physical path by which a message travels from sender to receiver.
A. Protocol
B. Transmitter
C. Receiver
D. Transmission medium

ANSWER KEY
1. B
5. D
FORMAT
                        ,
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?></pre>
                </details>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
