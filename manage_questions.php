<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/question_play_type.php';

if (empty($_SESSION['is_admin'])) {
    trytest_redirect(trytest_url('admin'));
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
    $postedQuizId = (int) ($_POST['quiz_id'] ?? 0);
    if ($postedQuizId > 0) {
        $selectedQuizId = $postedQuizId;
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
        if ($id > 0 && $selectedQuizId > 0) {
            $ok = $db->prepare('UPDATE questions SET status = ? WHERE id = ? AND quiz_id = ? AND status = ?');
            $ok->execute(['approved', $id, $selectedQuizId, 'pending']);
            if ($ok->rowCount() > 0) {
                $message = 'Question approved.';
            }
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
        if ($id < 1 || $selectedQuizId < 1) {
            $error = 'Edit failed. Missing quiz or question.';
        } elseif ($question === '' || $correct === '') {
            $error = 'Edit failed. Question and correct answer are required.';
        } else {
            $chk = $db->prepare('SELECT id FROM questions WHERE id = ? AND quiz_id = ? AND status IN (\'pending\', \'approved\')');
            $chk->execute([$id, $selectedQuizId]);
            if (!$chk->fetch()) {
                $error = 'Question not found in this quiz set.';
            } else {
                $db->prepare(
                    'UPDATE questions
                     SET question = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, correct_answer = ?
                     WHERE id = ? AND quiz_id = ?'
                )->execute([$question, $a, $b, $c, $d, $correct, $id, $selectedQuizId]);
                $message = 'Question updated.';
            }
        }
    }
    if ($action === 'delete_question') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && $selectedQuizId > 0) {
            $del = $db->prepare('DELETE FROM questions WHERE id = ? AND quiz_id = ?');
            $del->execute([$id, $selectedQuizId]);
            if ($del->rowCount() > 0) {
                $message = 'Question deleted.';
            }
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
    <?php trytest_link_preview_meta(['title' => 'Trytest — Manage Questions', 'description' => 'Trytest admin: edit quiz questions.']); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest — Manage Questions</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen p-4">
    <div class="max-w-5xl mx-auto py-6 space-y-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900">Questions</h1>
                <a href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_admin'), ENT_QUOTES, 'UTF-8'); ?>" class="text-sm text-indigo-600">Back to manager</a>
            </div>
            <?php if ($error !== ''): ?><div class="mt-3 rounded-lg bg-red-100 text-red-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($message !== ''): ?><div class="mt-3 rounded-lg bg-emerald-100 text-emerald-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        </div>

        <div class="max-w-3xl">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-3">
                <h2 class="font-semibold">Question Set &amp; Review Pool</h2>
                <p class="text-xs text-slate-500">To load new items into the review pool, use <a class="font-medium text-indigo-600 hover:underline" href="<?php echo htmlspecialchars(trytest_url('dashboard/import_exam'), ENT_QUOTES, 'UTF-8'); ?>">Import exam</a> or <a class="font-medium text-indigo-600 hover:underline" href="<?php echo htmlspecialchars(trytest_url('dashboard/import_json'), ENT_QUOTES, 'UTF-8'); ?>">Import JSON</a>. Here you pick the quiz set, approve or edit, and export.</p>
                <p class="text-xs text-slate-600">Play mode is detected automatically: use <code class="rounded bg-slate-100 px-1">____</code> in the stem for fill-in-the-blank (multiple blanks: separate model answers with <code class="rounded bg-slate-100 px-1">|</code>); add options A–D for MCQ; leave options empty for short theory.</p>
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
                    <a href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_questions?quiz_id=' . (int) $selectedQuizId . '&export=txt'), ENT_QUOTES, 'UTF-8'); ?>" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Download .txt</a>
                    <button type="button" id="downloadPdfBtn" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Download PDF</button>
                </div>
            </section>
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-semibold mb-3">Pending Review (<?php echo count($pendingQuestions); ?>)</h2>
            <div class="space-y-3 max-h-[50vh] overflow-auto">
                <?php foreach ($pendingQuestions as $q): ?>
                    <form method="post" class="border rounded-lg p-3 space-y-2">
                        <input type="hidden" name="quiz_id" value="<?php echo (int) $selectedQuizId; ?>">
                        <input type="hidden" name="id" value="<?php echo (int) $q['id']; ?>">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500"><?php echo htmlspecialchars(trytest_question_play_type($q), ENT_QUOTES, 'UTF-8'); ?></p>
                        <div class="grid gap-2 md:grid-cols-2">
                            <input class="border rounded px-2 py-1.5" name="question" value="<?php echo htmlspecialchars((string) $q['question'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            <input class="border rounded px-2 py-1.5" name="correct_answer" value="<?php echo htmlspecialchars((string) $q['correct_answer'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            <input class="border rounded px-2 py-1.5" name="option_a" value="<?php echo htmlspecialchars((string) $q['option_a'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Option A (MCQ)">
                            <input class="border rounded px-2 py-1.5" name="option_b" value="<?php echo htmlspecialchars((string) $q['option_b'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Option B">
                            <input class="border rounded px-2 py-1.5" name="option_c" value="<?php echo htmlspecialchars((string) $q['option_c'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Option C">
                            <input class="border rounded px-2 py-1.5" name="option_d" value="<?php echo htmlspecialchars((string) $q['option_d'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Option D">
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
            <div id="approvedSet" class="space-y-3 max-h-[55vh] overflow-auto">
                <?php foreach ($approvedQuestions as $idx => $q): ?>
                    <form method="post" class="border rounded-lg p-3 space-y-2 text-sm" data-q-item>
                        <input type="hidden" name="quiz_id" value="<?php echo (int) $selectedQuizId; ?>">
                        <input type="hidden" name="id" value="<?php echo (int) $q['id']; ?>">
                        <p class="text-xs font-semibold text-slate-500">#<?php echo (int) ($idx + 1); ?> · approved · <span class="text-indigo-600"><?php echo htmlspecialchars(trytest_question_play_type($q), ENT_QUOTES, 'UTF-8'); ?></span></p>
                        <div class="grid gap-2 md:grid-cols-2">
                            <input class="border rounded px-2 py-1.5" name="question" value="<?php echo htmlspecialchars((string) $q['question'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            <input class="border rounded px-2 py-1.5" name="correct_answer" value="<?php echo htmlspecialchars((string) $q['correct_answer'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            <input class="border rounded px-2 py-1.5" name="option_a" value="<?php echo htmlspecialchars((string) $q['option_a'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Option A (MCQ)">
                            <input class="border rounded px-2 py-1.5" name="option_b" value="<?php echo htmlspecialchars((string) $q['option_b'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Option B">
                            <input class="border rounded px-2 py-1.5" name="option_c" value="<?php echo htmlspecialchars((string) $q['option_c'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Option C">
                            <input class="border rounded px-2 py-1.5" name="option_d" value="<?php echo htmlspecialchars((string) $q['option_d'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Option D">
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button name="action" value="save_edit" class="rounded bg-slate-900 px-3 py-1.5 text-xs text-white">Save changes</button>
                            <button name="action" value="delete_question" class="rounded border border-red-300 px-3 py-1.5 text-xs text-red-600" onclick="return confirm('Delete this question from the live quiz set?');">Delete</button>
                        </div>
                    </form>
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
