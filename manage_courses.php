<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config/db.php';

if (empty($_SESSION['is_admin'])) {
    trytest_redirect(trytest_home_with_query(['mode' => 'admin']));
}

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_course') {
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $title = trim((string) ($_POST['title'] ?? ''));
        $level = trim((string) ($_POST['level'] ?? ''));
        $department = trim((string) ($_POST['department'] ?? ''));
        if ($code === '' || $title === '' || $level === '') {
            $error = 'Course code, title, and level are required.';
        } else {
            $db->prepare('INSERT INTO courses (code, title, level, department) VALUES (?, ?, ?, ?)')->execute([$code, $title, $level, $department]);
            $message = 'Course created.';
        }
    }
    if ($action === 'update_course_department') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $department = trim((string) ($_POST['department'] ?? ''));
        if ($courseId < 1) {
            $error = 'Select a valid course to update.';
        } else {
            $db->prepare('UPDATE courses SET department = ? WHERE id = ?')->execute([$department, $courseId]);
            $message = 'Course department updated.';
        }
    }
    if ($action === 'update_course') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $title = trim((string) ($_POST['title'] ?? ''));
        $level = trim((string) ($_POST['level'] ?? ''));
        $department = trim((string) ($_POST['department'] ?? ''));
        if ($courseId < 1 || $code === '' || $title === '' || $level === '') {
            $error = 'Course code, title, and level are required for updates.';
        } else {
            $db->prepare(
                'UPDATE courses SET code = ?, title = ?, level = ?, department = ? WHERE id = ?'
            )->execute([$code, $title, $level, $department, $courseId]);
            $message = 'Course updated.';
        }
    }
    if ($action === 'delete_course') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        if ($courseId < 1) {
            $error = 'Select a valid course to delete.';
        } else {
            $db->beginTransaction();
            try {
                $db->prepare('DELETE FROM quiz_courses WHERE course_id = ?')->execute([$courseId]);
                $db->prepare('UPDATE quizzes SET course_id = NULL WHERE course_id = ?')->execute([$courseId]);
                $db->prepare('DELETE FROM courses WHERE id = ?')->execute([$courseId]);
                $db->commit();
                $message = 'Course deleted.';
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Failed to delete course.';
            }
        }
    }
}

$courses = $db->query(
    'SELECT c.id, c.code, c.title, c.level, c.department, COUNT(q.id) AS quiz_count
     FROM courses c
     LEFT JOIN quizzes q ON q.course_id = c.id
     GROUP BY c.id
     ORDER BY c.id DESC'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest — Manage Courses</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen p-4">
    <div class="max-w-5xl mx-auto py-6 space-y-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900">Courses</h1>
                <a href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_admin'), ENT_QUOTES, 'UTF-8'); ?>" class="text-sm text-indigo-600">Back to manager</a>
            </div>
            <?php if ($error !== ''): ?><div class="mt-3 rounded-lg bg-red-100 text-red-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($message !== ''): ?><div class="mt-3 rounded-lg bg-emerald-100 text-emerald-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="font-semibold mb-3">Create Course</h2>
                <form method="post" class="space-y-2">
                    <input type="hidden" name="action" value="create_course">
                    <div class="grid gap-2 sm:grid-cols-2">
                        <input class="w-full border rounded-lg px-3 py-2" name="code" placeholder="Course code (ITS201)" required>
                        <input class="w-full border rounded-lg px-3 py-2" name="level" placeholder="Level (e.g 200)" required>
                    </div>
                    <input class="w-full border rounded-lg px-3 py-2" name="title" placeholder="Course title" required>
                    <input class="w-full border rounded-lg px-3 py-2" name="department" placeholder="Department / program (optional)">
                    <button class="w-full bg-slate-900 text-white rounded-lg py-2 font-medium">Add Course</button>
                </form>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="font-semibold mb-3">Update Department</h2>
                <form method="post" class="space-y-2">
                    <input type="hidden" name="action" value="update_course_department">
                    <select class="w-full border rounded-lg px-3 py-2" name="course_id" required>
                        <option value="">Select course</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?php echo (int) $course['id']; ?>"><?php echo htmlspecialchars((string) $course['code'] . ' — ' . $course['title'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input class="w-full border rounded-lg px-3 py-2" name="department" placeholder="Department / program (blank to clear)">
                    <button class="w-full bg-[#2C6A7D] text-white rounded-lg py-2 font-medium">Update Department</button>
                </form>
            </section>
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-semibold mb-3">Existing Courses</h2>
            <div class="space-y-3 max-h-[58vh] overflow-auto">
                <?php foreach ($courses as $course): ?>
                    <form method="post" class="rounded-xl border border-slate-200 p-3 space-y-2 course-row-form">
                        <input type="hidden" name="course_id" value="<?php echo (int) $course['id']; ?>">
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            <input class="w-full border rounded-lg px-3 py-2 text-sm bg-slate-50" name="code" value="<?php echo htmlspecialchars((string) $course['code'], ENT_QUOTES, 'UTF-8'); ?>" required readonly>
                            <input class="w-full border rounded-lg px-3 py-2 text-sm bg-slate-50" name="title" value="<?php echo htmlspecialchars((string) $course['title'], ENT_QUOTES, 'UTF-8'); ?>" required readonly>
                            <input class="w-full border rounded-lg px-3 py-2 text-sm bg-slate-50" name="level" value="<?php echo htmlspecialchars((string) $course['level'], ENT_QUOTES, 'UTF-8'); ?>" required readonly>
                            <input class="w-full border rounded-lg px-3 py-2 text-sm bg-slate-50" name="department" value="<?php echo htmlspecialchars((string) ($course['department'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Department / program" readonly>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs text-slate-500"><?php echo (int) $course['quiz_count']; ?> linked quizzes</p>
                            <div class="flex items-center gap-2">
                                <button type="button" class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-medium text-white course-edit-btn">Edit</button>
                                <button name="action" value="update_course" class="hidden rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-medium text-white course-save-btn">Save</button>
                                <button type="button" class="hidden rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 course-cancel-btn">Cancel</button>
                                <button name="action" value="delete_course" class="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-medium text-red-600" onclick="return confirm('Delete this course? Linked quizzes will be unlinked from this course.');">Delete</button>
                            </div>
                        </div>
                    </form>
                <?php endforeach; ?>
                <?php if (!$courses): ?>
                    <p class="text-sm text-slate-500">No courses yet.</p>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <script>
        (function () {
            const forms = document.querySelectorAll('.course-row-form');
            forms.forEach(function (form) {
                const editBtn = form.querySelector('.course-edit-btn');
                const saveBtn = form.querySelector('.course-save-btn');
                const cancelBtn = form.querySelector('.course-cancel-btn');
                const fields = form.querySelectorAll('input[name="code"], input[name="title"], input[name="level"], input[name="department"]');

                if (!editBtn || !saveBtn || !cancelBtn || !fields.length) return;

                fields.forEach(function (field) {
                    field.dataset.initialValue = field.value;
                });

                function setEditing(editing) {
                    fields.forEach(function (field) {
                        field.readOnly = !editing;
                        field.classList.toggle('bg-slate-50', !editing);
                        field.classList.toggle('bg-white', editing);
                    });
                    editBtn.classList.toggle('hidden', editing);
                    saveBtn.classList.toggle('hidden', !editing);
                    cancelBtn.classList.toggle('hidden', !editing);
                }

                editBtn.addEventListener('click', function () {
                    setEditing(true);
                });

                cancelBtn.addEventListener('click', function () {
                    fields.forEach(function (field) {
                        field.value = field.dataset.initialValue || '';
                    });
                    setEditing(false);
                });
            });
        })();
    </script>
</body>
</html>
