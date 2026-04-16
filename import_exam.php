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
 * @return array<int, string>
 */
function buildAnswerMap(string $answersPart): array
{
    $answers = [];
    preg_match_all('/(\d+)\s*[\.\)\:-]\s*([A-D])\b/i', $answersPart, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $answers[(int) $match[1]] = strtoupper($match[2]);
    }
    return $answers;
}

/**
 * @return array<int, array{number:int, question:string, options:array<string,string>, correct_letter:string}>
 */
function parseExamQuestions(string $questionsPart, array $answerMap): array
{
    $rows = [];
    preg_match_all('/(^|\n)\s*(\d+)\.\s*(.+?)(?=\n\s*\d+\.\s+|\z)/s', $questionsPart, $blocks, PREG_SET_ORDER);

    foreach ($blocks as $block) {
        $number = (int) ($block[2] ?? 0);
        $body = trim((string) ($block[3] ?? ''));
        if ($number < 1 || $body === '') {
            continue;
        }

        $parts = preg_split('/\n\s*([A-D])\.\s*/i', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts) || count($parts) < 9) {
            continue;
        }

        $question = preg_replace('/\s+/', ' ', trim((string) $parts[0])) ?? trim((string) $parts[0]);
        $options = [];
        $rawOptions = [];

        for ($i = 1; $i < count($parts) - 1; $i += 2) {
            $label = strtoupper(trim((string) $parts[$i]));
            $raw = trim((string) $parts[$i + 1]);
            if (!in_array($label, ['A', 'B', 'C', 'D'], true)) {
                continue;
            }
            $rawOptions[$label] = $raw;
            $clean = preg_replace('/\s*\*{3}\s*$/', '', $raw) ?? $raw;
            $clean = preg_replace('/\s+/', ' ', trim($clean)) ?? trim($clean);
            $options[$label] = $clean;
        }

        if (
            $question === '' ||
            empty($options['A']) ||
            empty($options['B']) ||
            empty($options['C']) ||
            empty($options['D'])
        ) {
            continue;
        }

        $correctLetter = strtoupper((string) ($answerMap[$number] ?? ''));
        if (!in_array($correctLetter, ['A', 'B', 'C', 'D'], true)) {
            foreach (['A', 'B', 'C', 'D'] as $letter) {
                if (preg_match('/\*{3}\s*$/', (string) ($rawOptions[$letter] ?? '')) === 1) {
                    $correctLetter = $letter;
                    break;
                }
            }
        }

        if (!in_array($correctLetter, ['A', 'B', 'C', 'D'], true)) {
            continue;
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
            header('Location: ' . trytest_url('dashboard/import_exam'));
            exit;
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
                $parts = preg_split('/ANSWER\s+KEY/i', $rawText, 2);
                $questionsPart = trim((string) ($parts[0] ?? ''));
                $answersPart = trim((string) ($parts[1] ?? ''));

                $answerMap = buildAnswerMap($answersPart);
                $parsed = parseExamQuestions($questionsPart, $answerMap);

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
                                'pending',
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

                <p class="text-xs text-slate-500 mt-3">
                    Format expected: numbered questions, options A-D, and answer key lines like "1. B". Headers and instructions are ignored automatically.
                </p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
