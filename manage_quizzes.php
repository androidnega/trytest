<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/student_helpers.php';

if (empty($_SESSION['is_admin'])) {
    trytest_redirect(trytest_url('admin'));
}

$error = '';
$message = '';

if (isset($_GET['export']) && $_GET['export'] === 'txt') {
    $quizId = (int) ($_GET['quiz_id'] ?? 0);
    if ($quizId > 0) {
        $quizStmt = $db->prepare(
            'SELECT q.title, q.level, c.code AS course_code, c.title AS course_title
             FROM quizzes q
             LEFT JOIN courses c ON c.id = q.course_id
             WHERE q.id = ?'
        );
        $quizStmt->execute([$quizId]);
        $quiz = $quizStmt->fetch();
        if ($quiz) {
            $qStmt = $db->prepare(
                'SELECT id, question, option_a, option_b, option_c, option_d, correct_answer, status
                 FROM questions
                 WHERE quiz_id = ?
                 ORDER BY id ASC'
            );
            $qStmt->execute([$quizId]);
            $items = $qStmt->fetchAll();

            $lines = [];
            $lines[] = 'Quiz: ' . (string) ($quiz['title'] ?? '');
            $lines[] = 'Course: ' . (string) ($quiz['course_code'] ?? '-') . ' - ' . (string) ($quiz['course_title'] ?? '');
            $lines[] = 'Level: ' . (string) ($quiz['level'] ?? '-');
            $lines[] = 'Exported at: ' . date('Y-m-d H:i:s');
            $lines[] = str_repeat('=', 64);
            $n = 1;
            foreach ($items as $item) {
                $lines[] = $n . '. ' . trim((string) ($item['question'] ?? ''));
                $lines[] = '   A) ' . trim((string) ($item['option_a'] ?? ''));
                $lines[] = '   B) ' . trim((string) ($item['option_b'] ?? ''));
                $lines[] = '   C) ' . trim((string) ($item['option_c'] ?? ''));
                $lines[] = '   D) ' . trim((string) ($item['option_d'] ?? ''));
                $lines[] = '   Answer: ' . trim((string) ($item['correct_answer'] ?? ''));
                $lines[] = '   Status: ' . trim((string) ($item['status'] ?? 'approved'));
                $lines[] = '';
                $n++;
            }

            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="quiz-' . $quizId . '-questions.txt"');
            echo implode("\n", $lines);
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_quiz') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $startsSql = trytest_datetime_local_to_sql($_POST['quiz_starts_at'] ?? null);
        $endsSql = trytest_datetime_local_to_sql($_POST['quiz_ends_at'] ?? null);
        if ($startsSql !== null && $endsSql !== null && strtotime($endsSql) < strtotime($startsSql)) {
            $error = 'Quiz end must be on or after quiz start.';
        } elseif ($title === '' || $courseId < 1) {
            $error = 'Quiz title and course are required.';
        } else {
            $courseStmt = $db->prepare('SELECT level FROM courses WHERE id = ?');
            $courseStmt->execute([$courseId]);
            $course = $courseStmt->fetch();
            if (!$course) {
                $error = 'Selected course does not exist.';
            } else {
                $level = (string) ($course['level'] ?? '');
                $durationCreate = isset($_POST['duration_minutes']) ? max(0, (int) $_POST['duration_minutes']) : 0;
                $durationCreateSql = $durationCreate > 0 ? $durationCreate : null;
                $db->prepare(
                    'INSERT INTO quizzes (title, level, course_id, quiz_starts_at, quiz_ends_at, duration_minutes) VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$title, $level, $courseId, $startsSql, $endsSql, $durationCreateSql]);
                $quizId = (int) $db->lastInsertId();
                $db->prepare('INSERT OR IGNORE INTO quiz_courses (quiz_id, course_id) VALUES (?, ?)')->execute([$quizId, $courseId]);
                $message = 'Quiz created for level ' . $level . '.';
            }
        }
    }
    if ($action === 'update_quiz_schedule') {
        $id = (int) ($_POST['id'] ?? 0);
        $startsSql = trytest_datetime_local_to_sql($_POST['quiz_starts_at'] ?? null);
        $endsSql = trytest_datetime_local_to_sql($_POST['quiz_ends_at'] ?? null);
        if ($id < 1) {
            $error = 'Invalid quiz.';
        } elseif ($startsSql !== null && $endsSql !== null && strtotime($endsSql) < strtotime($startsSql)) {
            $error = 'Quiz end must be on or after quiz start.';
        } else {
            $durationUp = isset($_POST['duration_minutes']) ? max(0, (int) $_POST['duration_minutes']) : 0;
            $durationUpSql = $durationUp > 0 ? $durationUp : null;
            $db->prepare('UPDATE quizzes SET quiz_starts_at = ?, quiz_ends_at = ?, duration_minutes = ? WHERE id = ?')->execute([$startsSql, $endsSql, $durationUpSql, $id]);
            $message = 'Quiz schedule and time limit updated.';
        }
    }
    if ($action === 'delete_quiz') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->beginTransaction();
            try {
                $db->prepare('DELETE FROM scores WHERE quiz_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM questions WHERE quiz_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM quiz_courses WHERE quiz_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM quizzes WHERE id = ?')->execute([$id]);
                $db->commit();
                $message = 'Quiz deleted.';
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Failed to delete quiz.';
            }
        }
    }
}

$courses = $db->query('SELECT id, code, title, level, department FROM courses ORDER BY id DESC')->fetchAll();
$quizzes = $db->query(
    'SELECT q.id, q.title, q.level, q.quiz_starts_at, q.quiz_ends_at, q.duration_minutes, c.code AS course_code, c.title AS course_title
     FROM quizzes q
     LEFT JOIN courses c ON c.id = q.course_id
     ORDER BY q.id DESC'
)->fetchAll();

$questionRows = $db->query(
    'SELECT id, quiz_id, question, option_a, option_b, option_c, option_d, correct_answer, status
     FROM questions
     ORDER BY id DESC'
)->fetchAll();
$questionsByQuiz = [];
foreach ($questionRows as $row) {
    $qid = (int) ($row['quiz_id'] ?? 0);
    if (!isset($questionsByQuiz[$qid])) {
        $questionsByQuiz[$qid] = [];
    }
    $questionsByQuiz[$qid][] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest — Manage Quizzes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
</head>
<body class="bg-slate-50 min-h-screen p-4">
    <div class="max-w-5xl mx-auto py-6 space-y-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900">Quizzes</h1>
                <a href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_admin'), ENT_QUOTES, 'UTF-8'); ?>" class="text-sm text-indigo-600">Back to manager</a>
            </div>
            <?php if ($error !== ''): ?><div class="mt-3 rounded-lg bg-red-100 text-red-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($message !== ''): ?><div class="mt-3 rounded-lg bg-emerald-100 text-emerald-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="font-semibold mb-3">Create Quiz / Test</h2>
                <form method="post" class="space-y-2">
                    <input type="hidden" name="action" value="create_quiz">
                    <input class="w-full border rounded-lg px-3 py-2" name="title" placeholder="Quiz title" required>
                    <select class="w-full border rounded-lg px-3 py-2" name="course_id" id="quizCourseSelect" required>
                        <option value="">Select course</option>
                        <?php foreach ($courses as $course): ?>
                            <?php $cdept = trim((string) ($course['department'] ?? '')); ?>
                            <option value="<?php echo (int) $course['id']; ?>" data-level="<?php echo htmlspecialchars((string) $course['level'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars((string) $course['code'] . ' — ' . $course['title'] . ($cdept !== '' ? ' · ' . $cdept : ''), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-slate-500">Assigned level: <span id="selectedCourseLevel" class="font-semibold text-slate-700">-</span></p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div>
                            <label class="block text-[11px] font-medium text-slate-600 mb-1">Opens at <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input class="w-full border rounded-lg px-2 py-2 text-sm" type="datetime-local" name="quiz_starts_at">
                        </div>
                        <div>
                            <label class="block text-[11px] font-medium text-slate-600 mb-1">Closes at <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input class="w-full border rounded-lg px-2 py-2 text-sm" type="datetime-local" name="quiz_ends_at">
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-500">Leave both empty for always-on access. Students see countdowns on their dashboard. After you create a quiz, use <strong>Share with students</strong> to copy or send a WhatsApp link (students sign in first).</p>
                    <div>
                        <label class="block text-[11px] font-medium text-slate-600 mb-1">Student time limit <span class="text-slate-400 font-normal">(minutes, optional)</span></label>
                        <input class="w-full border rounded-lg px-3 py-2" type="number" name="duration_minutes" min="0" step="1" placeholder="0 = no limit; timer starts when they see Q1">
                    </div>
                    <button class="w-full bg-emerald-600 text-white rounded-lg py-2 font-medium">Add Quiz</button>
                </form>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="font-semibold mb-3">Existing Quizzes</h2>
                <div class="space-y-2 max-h-80 overflow-auto">
                    <?php foreach ($quizzes as $quiz): ?>
                        <?php $quizId = (int) $quiz['id']; ?>
                        <?php $quizQuestions = $questionsByQuiz[$quizId] ?? []; ?>
                        <div class="border rounded-lg p-2 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-medium truncate"><?php echo htmlspecialchars((string) $quiz['title'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <p class="text-slate-500 text-xs">Level <?php echo htmlspecialchars((string) ($quiz['level'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string) ($quiz['course_code'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($quiz['course_title'])): ?> · <?php echo htmlspecialchars((string) $quiz['course_title'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></p>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <button type="button" class="text-indigo-600 text-xs quiz-toggle" data-target="quiz-<?php echo $quizId; ?>">View Q&A</button>
                                    <a
                                        href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_quizzes?export=txt&quiz_id=' . (int) $quizId), ENT_QUOTES, 'UTF-8'); ?>"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-slate-300 text-slate-600 hover:bg-slate-100"
                                        title="Download TXT"
                                        aria-label="Download TXT"
                                    >
                                        <i class="fa-regular fa-file-lines text-xs"></i>
                                    </a>
                                    <button
                                        type="button"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-slate-300 text-slate-600 hover:bg-slate-100 pdf-btn"
                                        data-quiz-id="<?php echo $quizId; ?>"
                                        data-quiz-title="<?php echo htmlspecialchars((string) $quiz['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                        title="Download PDF"
                                        aria-label="Download PDF"
                                    >
                                        <i class="fa-regular fa-file-pdf text-xs"></i>
                                    </button>
                                    <form method="post">
                                        <input type="hidden" name="action" value="delete_quiz">
                                        <input type="hidden" name="id" value="<?php echo $quizId; ?>">
                                        <button class="text-red-600 text-xs" onclick="return confirm('Delete this quiz and all its questions?');">Delete</button>
                                    </form>
                                </div>
                            </div>
                            <?php
                            $shareUrl = trytest_absolute_url('share_quiz?id=' . $quizId);
                            $shareTitle = (string) ($quiz['title'] ?? 'Quiz');
                            $waBody = 'Trytest — "' . $shareTitle . "\"\n\n" . $shareUrl . "\n\nSign in with your index number to open the quiz.";
                            $waHref = 'https://wa.me/?text=' . rawurlencode($waBody);
                            ?>
                            <div class="share-quiz-block mt-2 rounded-lg border border-emerald-100 bg-emerald-50/60 p-2">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-emerald-900">Share with students</p>
                                <p class="mt-0.5 text-[10px] text-emerald-800/90">Opens sign-in, then the quiz (same rules as the dashboard).</p>
                                <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-stretch">
                                    <input type="text" readonly class="share-quiz-url-input min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-2 py-2 font-mono text-[10px] text-slate-800" value="<?php echo htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="flex shrink-0 flex-wrap gap-1.5">
                                        <button type="button" class="share-quiz-copy rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800" data-url="<?php echo htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8'); ?>">Copy link</button>
                                        <a href="<?php echo htmlspecialchars($waHref, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-[#25D366] px-3 py-2 text-xs font-semibold text-white hover:bg-[#20bd5a] sm:flex-initial"><i class="fa-brands fa-whatsapp text-sm"></i> WhatsApp</a>
                                    </div>
                                </div>
                            </div>
                            <form method="post" class="mt-2 rounded-lg border border-slate-200 bg-slate-50 p-2 space-y-2">
                                <input type="hidden" name="action" value="update_quiz_schedule">
                                <input type="hidden" name="id" value="<?php echo $quizId; ?>">
                                <p class="text-[11px] font-semibold text-slate-700">Schedule <span class="text-slate-400 font-normal">(optional)</span></p>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <div>
                                        <label class="block text-[10px] text-slate-500 mb-0.5">Opens</label>
                                        <input class="w-full border rounded px-2 py-1.5 text-xs" type="datetime-local" name="quiz_starts_at" value="<?php echo htmlspecialchars(trytest_sql_datetime_to_datetime_local(isset($quiz['quiz_starts_at']) ? (string) $quiz['quiz_starts_at'] : ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] text-slate-500 mb-0.5">Closes</label>
                                        <input class="w-full border rounded px-2 py-1.5 text-xs" type="datetime-local" name="quiz_ends_at" value="<?php echo htmlspecialchars(trytest_sql_datetime_to_datetime_local(isset($quiz['quiz_ends_at']) ? (string) $quiz['quiz_ends_at'] : ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[10px] text-slate-500 mb-0.5">Time limit (min)</label>
                                    <?php
                                    $dmVal = isset($quiz['duration_minutes']) && $quiz['duration_minutes'] !== null && (int) $quiz['duration_minutes'] > 0
                                        ? (int) $quiz['duration_minutes']
                                        : '';
                                    ?>
                                    <input class="w-full border rounded px-2 py-1.5 text-xs" type="number" name="duration_minutes" min="0" step="1" value="<?php echo $dmVal !== '' ? (int) $dmVal : ''; ?>" placeholder="0 = none">
                                </div>
                                <button type="submit" class="text-xs font-medium text-indigo-600 hover:underline">Save schedule &amp; time limit</button>
                            </form>
                            <div id="quiz-<?php echo $quizId; ?>" class="mt-2 hidden space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-2">
                                <?php if ($quizQuestions): ?>
                                    <?php foreach ($quizQuestions as $index => $qq): ?>
                                        <div class="rounded border border-slate-200 bg-white p-2">
                                            <p class="text-xs font-medium text-slate-900"><?php echo ($index + 1); ?>. <?php echo htmlspecialchars((string) $qq['question'], ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="text-[11px] text-slate-600 mt-1">A) <?php echo htmlspecialchars((string) ($qq['option_a'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="text-[11px] text-slate-600">B) <?php echo htmlspecialchars((string) ($qq['option_b'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="text-[11px] text-slate-600">C) <?php echo htmlspecialchars((string) ($qq['option_c'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="text-[11px] text-slate-600">D) <?php echo htmlspecialchars((string) ($qq['option_d'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="text-[11px] text-emerald-700 mt-1">Answer: <?php echo htmlspecialchars((string) ($qq['correct_answer'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> <span class="text-slate-500">(<?php echo htmlspecialchars((string) ($qq['status'] ?? 'approved'), ENT_QUOTES, 'UTF-8'); ?>)</span></p>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-xs text-slate-500">No questions in this quiz yet.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </div>
<script>
(function () {
    document.querySelectorAll('.share-quiz-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var url = btn.getAttribute('data-url') || '';
            if (!url) return;
            var resetLabel = function () {
                btn.textContent = 'Copy link';
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () {
                    btn.textContent = 'Copied!';
                    setTimeout(resetLabel, 2200);
                }).catch(function () {
                    var block = btn.closest('.share-quiz-block');
                    var inp = block ? block.querySelector('.share-quiz-url-input') : null;
                    if (inp) {
                        inp.select();
                        try {
                            document.execCommand('copy');
                        } catch (e) {}
                    }
                });
                return;
            }
            var block = btn.closest('.share-quiz-block');
            var inp = block ? block.querySelector('.share-quiz-url-input') : null;
            if (inp) {
                inp.select();
                try {
                    document.execCommand('copy');
                    btn.textContent = 'Copied!';
                    setTimeout(resetLabel, 2200);
                } catch (e) {}
            }
        });
    });
})();

(function () {
    const select = document.getElementById('quizCourseSelect');
    const levelLabel = document.getElementById('selectedCourseLevel');
    if (!select || !levelLabel) return;
    function updateLevel() {
        const option = select.options[select.selectedIndex];
        levelLabel.textContent = option ? (option.getAttribute('data-level') || '-') : '-';
    }
    select.addEventListener('change', updateLevel);
    updateLevel();
})();

(function () {
    const toggles = document.querySelectorAll('.quiz-toggle');
    toggles.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const targetId = btn.getAttribute('data-target');
            const panel = targetId ? document.getElementById(targetId) : null;
            if (!panel) return;
            panel.classList.toggle('hidden');
            btn.textContent = panel.classList.contains('hidden') ? 'View Q&A' : 'Hide Q&A';
        });
    });
})();

const quizQuestionMap = <?php echo json_encode($questionsByQuiz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
(function () {
    const jsPDF = window.jspdf && window.jspdf.jsPDF ? window.jspdf.jsPDF : null;
    const pdfButtons = document.querySelectorAll('.pdf-btn');
    if (!jsPDF || !pdfButtons.length) return;
    pdfButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const quizId = Number(btn.getAttribute('data-quiz-id') || 0);
            const title = btn.getAttribute('data-quiz-title') || 'Quiz';
            const list = quizQuestionMap[String(quizId)] || quizQuestionMap[quizId] || [];
            const doc = new jsPDF({ unit: 'pt', format: 'a4' });
            let y = 40;
            doc.setFontSize(14);
            doc.text('Quiz: ' + title, 40, y);
            y += 20;
            doc.setFontSize(10);
            if (!list.length) {
                doc.text('No questions available.', 40, y);
            } else {
                list.forEach(function (q, idx) {
                    const block = [
                        (idx + 1) + '. ' + (q.question || ''),
                        'A) ' + (q.option_a || ''),
                        'B) ' + (q.option_b || ''),
                        'C) ' + (q.option_c || ''),
                        'D) ' + (q.option_d || ''),
                        'Answer: ' + (q.correct_answer || '') + ' (' + (q.status || 'approved') + ')'
                    ].join('\n');
                    const lines = doc.splitTextToSize(block, 520);
                    if (y + (lines.length * 12) > 790) {
                        doc.addPage();
                        y = 40;
                    }
                    doc.text(lines, 40, y);
                    y += (lines.length * 12) + 10;
                });
            }
            doc.save('quiz-' + quizId + '-questions.pdf');
        });
    });
})();
</script>
</body>
</html>
