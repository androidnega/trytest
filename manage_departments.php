<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/departments.php';

if (empty($_SESSION['is_admin'])) {
    trytest_redirect(trytest_url('admin'));
}

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_department') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $error = 'Enter a department or program name.';
        } elseif (strlen($name) > 120) {
            $error = 'Name is too long (120 characters max).';
        } else {
            $dup = $db->prepare('SELECT id FROM departments WHERE name = ? COLLATE NOCASE LIMIT 1');
            $dup->execute([$name]);
            if ($dup->fetch()) {
                $message = 'That name is already in the list.';
            } else {
                try {
                    $db->prepare('INSERT INTO departments (name) VALUES (?)')->execute([$name]);
                    $message = 'Department added. It will appear in student and resource dropdowns.';
                } catch (Throwable $e) {
                    $error = 'Could not save (duplicate name?).';
                }
            }
        }
    }
    if ($action === 'delete_department') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            $error = 'Invalid department.';
        } else {
            $db->prepare('DELETE FROM departments WHERE id = ?')->execute([$id]);
            $message = 'Removed from the preset list. (Courses that still use this name will keep it until you edit them.)';
        }
    }
}

$departments = $db->query('SELECT id, name, created_at FROM departments ORDER BY name COLLATE NOCASE')->fetchAll();
$mergedPreview = trytest_department_dropdown_options($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php trytest_link_preview_meta(['title' => 'Trytest — Departments & programs', 'description' => 'Trytest admin: departments and programs.']); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest — Departments &amp; programs</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen p-4">
    <div class="mx-auto max-w-2xl space-y-4 py-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Departments &amp; programs</h1>
                    <p class="mt-1 text-sm text-slate-500">Names you add here appear in student sign-up and PDF upload dropdowns, together with any department text already set on courses.</p>
                </div>
                <a href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_admin'), ENT_QUOTES, 'UTF-8'); ?>" class="text-sm text-indigo-600 hover:underline">Back to manager</a>
            </div>
            <?php if ($error !== ''): ?><div class="mt-3 rounded-lg bg-red-100 px-3 py-2 text-sm text-red-700"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($message !== ''): ?><div class="mt-3 rounded-lg bg-emerald-100 px-3 py-2 text-sm text-emerald-800"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="mb-3 font-semibold text-slate-900">Add a name</h2>
            <form method="post" class="flex flex-col gap-2 sm:flex-row sm:items-end">
                <input type="hidden" name="action" value="add_department">
                <div class="min-w-0 flex-1">
                    <label class="mb-1 block text-xs font-medium text-slate-600" for="dept_name">Program / department</label>
                    <input id="dept_name" class="w-full rounded-lg border border-slate-300 px-3 py-2" name="name" placeholder="e.g. Information Technology" maxlength="120" required>
                </div>
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white" type="submit"><i class="fa-solid fa-plus mr-1.5"></i>Add</button>
            </form>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="mb-3 font-semibold text-slate-900">Preset list</h2>
            <?php if (!$departments): ?>
                <p class="text-sm text-slate-500">No presets yet. Add names above, or set a department on a course under <a class="font-medium text-indigo-600 hover:underline" href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_courses'), ENT_QUOTES, 'UTF-8'); ?>">Courses</a>.</p>
            <?php else: ?>
                <ul class="divide-y divide-slate-100 rounded-xl border border-slate-100">
                    <?php foreach ($departments as $row): ?>
                        <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5">
                            <span class="text-sm font-medium text-slate-800"><?php echo htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                            <form method="post" class="inline" onsubmit="return confirm('Remove this preset?');">
                                <input type="hidden" name="action" value="delete_department">
                                <input type="hidden" name="id" value="<?php echo (int) ($row['id'] ?? 0); ?>">
                                <button type="submit" class="text-xs font-medium text-red-600 hover:underline">Remove</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="mb-2 font-semibold text-slate-900">Combined dropdown values</h2>
            <p class="mb-3 text-xs text-slate-500">Students see this merged set when choosing a program (signup) and when you tag PDFs.</p>
            <?php if (!$mergedPreview): ?>
                <p class="text-sm text-slate-500">None yet — add presets or course departments.</p>
            <?php else: ?>
                <ul class="flex flex-wrap gap-2">
                    <?php foreach ($mergedPreview as $opt): ?>
                        <li class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-700"><?php echo htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>
