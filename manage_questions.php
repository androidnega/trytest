<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config/db.php';

if (empty($_SESSION['is_admin'])) {
    header('Location: /trytest/dashboard/?mode=admin');
    exit;
}

$error = '';
$message = '';
$selectedQuizId = isset($_GET['quiz_id']) ? (int) $_GET['quiz_id'] : 0;

if (isset($_GET['export']) && $_GET['export'] === 'txt') {
    $quizId = isset($_GET['quiz_id']) ? (int) $_GET['quiz_id'] : 0;
    if ($quizId > 0) {
        $quizTitleStmt = $db->prepare('SELECT title FROM quizzes WHERE id = ?');
        $quizTitleStmt->execute([$quizId]);
        $quizTitle = (string) ($quizTitleStmt->fetchColumn() ?: 'Question Set');

        $rowsStmt = $db->prepare(
            'SELECT id, question, option_a, option_b, option_c, option_d, correct_answer
             FROM questions
             WHERE quiz_id = ? AND status = ?
             ORDER BY id ASC'
        );
        $rowsStmt->execute([$quizId, 'approved']);
        $rows = $rowsStmt->fetchAll();

        $lines = [];
        $lines[] = 'Question Set: ' . $quizTitle;
        $lines[] = 'Exported at: ' . date('Y-m-d H:i:s');
        $lines[] = str_repeat('=', 56);
        $n = 1;
        foreach ($rows as $r) {
            $lines[] = $n . '. ' . trim((string) ($r['question'] ?? ''));
            $lines[] = '   A) ' . trim((string) ($r['option_a'] ?? ''));
            $lines[] = '   B) ' . trim((string) ($r['option_b'] ?? ''));
            $lines[] = '   C) ' . trim((string) ($r['option_c'] ?? ''));
            $lines[] = '   D) ' . trim((string) ($r['option_d'] ?? ''));
            $lines[] = '   Answer: ' . trim((string) ($r['correct_answer'] ?? ''));
            $lines[] = '';
            $n++;
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="question-set-' . $quizId . '.txt"');
        echo implode("\n", $lines);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_question') {
        $quizId = (int) ($_POST['quiz_id'] ?? 0);
        $selectedQuizId = $quizId;
        $type = strtolower(trim((string) ($_POST['question_type'] ?? 'mcq')));
        $question = trim((string) ($_POST['question'] ?? ''));
        $correct = trim((string) ($_POST['correct_answer'] ?? ''));
        $a = trim((string) ($_POST['option_a'] ?? ''));
        $b = trim((string) ($_POST['option_b'] ?? ''));
        $c = trim((string) ($_POST['option_c'] ?? ''));
        $d = trim((string) ($_POST['option_d'] ?? ''));
        if ($quizId < 1 || $question === '' || $correct === '') {
            $error = 'Quiz, question, and correct answer are required.';
        } elseif ($type === 'mcq' && ($a === '' || $b === '' || $c === '' || $d === '')) {
            $error = 'For MCQ, options A-D are required.';
        } else {
            if ($type === 'fill') {
                $a = $b = $c = $d = null;
            }
            $db->prepare(
                'INSERT INTO questions (quiz_id, question_type, question, option_a, option_b, option_c, option_d, correct_answer, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$quizId, $type, $question, $a, $b, $c, $d, $correct, 'pending']);
            $message = 'Question added to review pool.';
        }
    }
    if ($action === 'approve_all') {
        $quizId = (int) ($_POST['quiz_id'] ?? 0);
        $selectedQuizId = $quizId;
        if ($quizId > 0) {
            $stmt = $db->prepare('UPDATE questions SET status = ? WHERE quiz_id = ? AND status = ?');
            $stmt->execute(['approved', $quizId, 'pending']);
            $message = $stmt->rowCount() . ' pending questions approved.';
        }
    }
    if ($action === 'approve_one') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('UPDATE questions SET status = ? WHERE id = ?')->execute(['approved', $id]);
            $message = 'Question approved.';
        }
    }
    if ($action === 'save_edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $question = trim((string) ($_POST['question'] ?? ''));
        $correct = trim((string) ($_POST['correct_answer'] ?? ''));
        $a = trim((string) ($_POST['option_a'] ?? ''));
        $b = trim((string) ($_POST['option_b'] ?? ''));
        $c = trim((string) ($_POST['option_c'] ?? ''));
        $d = trim((string) ($_POST['option_d'] ?? ''));
        if ($id > 0 && $question !== '' && $correct !== '' && $a !== '' && $b !== '' && $c !== '' && $d !== '') {
            $db->prepare(
                'UPDATE questions
                 SET question = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, correct_answer = ?
                 WHERE id = ?'
            )->execute([$question, $a, $b, $c, $d, $correct, $id]);
            $message = 'Question updated.';
        } else {
            $error = 'Edit failed. All fields are required.';
        }
    }
    if ($action === 'delete_question') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('DELETE FROM questions WHERE id = ?')->execute([$id]);
            $message = 'Question deleted.';
        }
    }
}

$quizzes = $db->query('SELECT id, title FROM quizzes ORDER BY id DESC')->fetchAll();

if ($selectedQuizId < 1 && $quizzes) {
    $selectedQuizId = (int) ($quizzes[0]['id'] ?? 0);
}

$pendingQuestions = [];
$approvedQuestions = [];
if ($selectedQuizId > 0) {
    $pendingStmt = $db->prepare(
        'SELECT id, question, question_type, option_a, option_b, option_c, option_d, correct_answer
         FROM questions
         WHERE quiz_id = ? AND status = ?
         ORDER BY id DESC'
    );
    $pendingStmt->execute([$selectedQuizId, 'pending']);
    $pendingQuestions = $pendingStmt->fetchAll();

    $approvedStmt = $db->prepare(
        'SELECT id, question, question_type, option_a, option_b, option_c, option_d, correct_answer
         FROM questions
         WHERE quiz_id = ? AND status = ?
         ORDER BY id DESC'
    );
    $approvedStmt->execute([$selectedQuizId, 'approved']);
    $approvedQuestions = $approvedStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest — Manage Questions</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen p-4">
    <div class="max-w-5xl mx-auto py-6 space-y-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900">Questions</h1>
                <a href="/trytest/dashboard/manage_admin" class="text-sm text-indigo-600">Back to manager</a>
            </div>
            <?php if ($error !== ''): ?><div class="mt-3 rounded-lg bg-red-100 text-red-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($message !== ''): ?><div class="mt-3 rounded-lg bg-emerald-100 text-emerald-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="font-semibold mb-3">Add Question</h2>
                <form method="post" class="grid md:grid-cols-2 gap-2">
                    <input type="hidden" name="action" value="create_question">
                    <select class="border rounded-lg px-3 py-2 md:col-span-2" name="quiz_id" required>
                        <option value="">Select quiz</option>
                        <?php foreach ($quizzes as $quiz): ?>
                            <option value="<?php echo (int) $quiz['id']; ?>"><?php echo htmlspecialchars((string) $quiz['title'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="border rounded-lg px-3 py-2" name="question_type" required>
                        <option value="mcq">MCQ</option>
                        <option value="fill">Fill</option>
                    </select>
                    <input class="border rounded-lg px-3 py-2" name="correct_answer" placeholder="Correct answer" required>
                    <textarea class="border rounded-lg px-3 py-2 md:col-span-2" name="question" rows="2" placeholder="Question text" required></textarea>
                    <input class="border rounded-lg px-3 py-2" name="option_a" placeholder="Option A">
                    <input class="border rounded-lg px-3 py-2" name="option_b" placeholder="Option B">
                    <input class="border rounded-lg px-3 py-2" name="option_c" placeholder="Option C">
                    <input class="border rounded-lg px-3 py-2" name="option_d" placeholder="Option D">
                    <button class="md:col-span-2 bg-slate-900 text-white rounded-lg py-2 font-medium">Add Question</button>
                </form>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-3">
                <h2 class="font-semibold">Question Set & Review Pool</h2>
                <form method="get" class="space-y-2">
                    <label class="text-xs text-slate-500">Quiz set</label>
                    <div class="flex gap-2">
                        <select class="flex-1 border rounded-lg px-3 py-2" name="quiz_id">
                            <?php foreach ($quizzes as $quiz): ?>
                                <option value="<?php echo (int) $quiz['id']; ?>" <?php echo $selectedQuizId === (int) $quiz['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $quiz['title'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Load</button>
                    </div>
                </form>

                <div class="flex flex-wrap gap-2">
                    <form method="post">
                        <input type="hidden" name="action" value="approve_all">
                        <input type="hidden" name="quiz_id" value="<?php echo (int) $selectedQuizId; ?>">
                        <button class="rounded-lg bg-emerald-600 px-3 py-2 text-sm text-white">Accept All Pending</button>
                    </form>
                    <a href="/trytest/dashboard/manage_questions?quiz_id=<?php echo (int) $selectedQuizId; ?>&export=txt" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Download .txt</a>
                    <button type="button" id="downloadPdfBtn" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Download PDF</button>
                </div>
            </section>
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-semibold mb-3">Pending Review (<?php echo count($pendingQuestions); ?>)</h2>
            <div class="space-y-3 max-h-[50vh] overflow-auto">
                <?php foreach ($pendingQuestions as $q): ?>
                    <form method="post" class="border rounded-lg p-3 space-y-2">
                        <input type="hidden" name="id" value="<?php echo (int) $q['id']; ?>">
                        <div class="grid gap-2 md:grid-cols-2">
                            <input class="border rounded px-2 py-1.5" name="question" value="<?php echo htmlspecialchars((string) $q['question'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            <input class="border rounded px-2 py-1.5" name="correct_answer" value="<?php echo htmlspecialchars((string) $q['correct_answer'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            <input class="border rounded px-2 py-1.5" name="option_a" value="<?php echo htmlspecialchars((string) $q['option_a'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            <input class="border rounded px-2 py-1.5" name="option_b" value="<?php echo htmlspecialchars((string) $q['option_b'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            <input class="border rounded px-2 py-1.5" name="option_c" value="<?php echo htmlspecialchars((string) $q['option_c'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            <input class="border rounded px-2 py-1.5" name="option_d" value="<?php echo htmlspecialchars((string) $q['option_d'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button name="action" value="approve_one" class="rounded bg-emerald-600 px-3 py-1.5 text-xs text-white">Accept</button>
                            <button name="action" value="save_edit" class="rounded bg-slate-900 px-3 py-1.5 text-xs text-white">Save Edit</button>
                            <button name="action" value="delete_question" class="rounded border border-red-300 px-3 py-1.5 text-xs text-red-600" onclick="return confirm('Delete this question?');">Delete</button>
                        </div>
                    </form>
                <?php endforeach; ?>
                <?php if (!$pendingQuestions): ?>
                    <p class="text-sm text-slate-500">No pending questions for this quiz set.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-semibold mb-3">Approved Question Set (<?php echo count($approvedQuestions); ?>)</h2>
            <div id="approvedSet" class="space-y-2 max-h-[55vh] overflow-auto">
                <?php foreach ($approvedQuestions as $idx => $q): ?>
                    <div class="border rounded-lg p-3 text-sm" data-q-item>
                        <p class="font-medium"><?php echo ($idx + 1); ?>. <?php echo htmlspecialchars((string) $q['question'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="text-slate-600 mt-1">A) <?php echo htmlspecialchars((string) $q['option_a'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="text-slate-600">B) <?php echo htmlspecialchars((string) $q['option_b'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="text-slate-600">C) <?php echo htmlspecialchars((string) $q['option_c'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="text-slate-600">D) <?php echo htmlspecialchars((string) $q['option_d'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="text-xs text-slate-500 mt-1">Answer: <?php echo htmlspecialchars((string) $q['correct_answer'], ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                <?php endforeach; ?>
                <?php if (!$approvedQuestions): ?>
                    <p class="text-sm text-slate-500">No approved questions for this quiz set yet.</p>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script>
        (function () {
            const btn = document.getElementById('downloadPdfBtn');
            if (!btn) return;
            btn.addEventListener('click', function () {
                const jsPDF = window.jspdf?.jsPDF;
                if (!jsPDF) return;
                const doc = new jsPDF({ unit: 'pt', format: 'a4' });
                const items = document.querySelectorAll('#approvedSet [data-q-item]');
                let y = 40;
                doc.setFontSize(14);
                doc.text('Question Set Export', 40, y);
                y += 20;
                doc.setFontSize(10);
                items.forEach(function (node, idx) {
                    const text = node.innerText.replace(/\s+/g, ' ').trim();
                    const lines = doc.splitTextToSize((idx + 1) + '. ' + text, 520);
                    if (y + (lines.length * 14) > 790) {
                        doc.addPage();
                        y = 40;
                    }
                    doc.text(lines, 40, y);
                    y += (lines.length * 14) + 10;
                });
                doc.save('question-set-<?php echo (int) $selectedQuizId; ?>.pdf');
            });
        })();
    </script>
</body>
</html>
