<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/levels.php';

if (empty($_SESSION['is_admin'])) {
    trytest_redirect(trytest_url('admin'));
}

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_level') {
        $raw = trim((string) ($_POST['value'] ?? ''));
        if ($raw === '') {
            $error = 'Enter a level label (e.g. 500).';
        } elseif (strlen($raw) > 32) {
            $error = 'Level is too long (32 characters max).';
        } elseif (preg_match('/^\d+$/', $raw) !== 1) {
            $error = 'Use digits only for level (e.g. 100, 200).';
        } else {
            $norm = (string) (int) $raw;
            if ($norm === '0') {
                $error = 'Invalid level.';
            } else {
                $dup = $db->prepare('SELECT id FROM levels WHERE value = ? COLLATE NOCASE LIMIT 1');
                $dup->execute([$norm]);
                if ($dup->fetch()) {
                    $message = 'That level is already in the list.';
                } else {
                    try {
                        $sort = (int) $norm;
                        $db->prepare('INSERT INTO levels (value, sort_order) VALUES (?, ?)')->execute([$norm, $sort]);
                        $message = 'Level added. It appears in student sign-up, courses, PDFs, and AI prompt.';
                    } catch (Throwable $e) {
                        $error = 'Could not save (duplicate?).';
                    }
                }
            }
        }
    }
    if ($action === 'delete_level') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            $error = 'Invalid level.';
        } else {
            $db->prepare('DELETE FROM levels WHERE id = ?')->execute([$id]);
            $message = 'Removed from the preset list. Existing course or user values are unchanged until you edit them.';
        }
    }
}

$levels = $db->query('SELECT id, value, sort_order, created_at FROM levels ORDER BY sort_order ASC, CAST(value AS INTEGER) ASC, value COLLATE NOCASE')->fetchAll();
$mergedPreview = trytest_level_dropdown_options($db);
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php trytest_link_preview_meta(['title' => 'Trytest — Levels', 'description' => 'Trytest admin: academic levels.']); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest — Levels</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 p-4">
    <div class="mx-auto max-w-2xl space-y-4 py-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Levels</h1>
                    <p class="mt-1 text-sm text-slate-500">These values power every level dropdown (students, courses, PDFs, AI prompt). Extra labels from existing data are merged automatically below.</p>
                </div>
                <a href="<?php echo $h(trytest_url('dashboard/manage_admin')); ?>" class="text-sm text-indigo-600 hover:underline">Back to manager</a>
            </div>
            <?php if ($error !== ''): ?><div class="mt-3 rounded-lg bg-red-100 px-3 py-2 text-sm text-red-700"><?php echo $h($error); ?></div><?php endif; ?>
            <?php if ($message !== ''): ?><div class="mt-3 rounded-lg bg-emerald-100 px-3 py-2 text-sm text-emerald-800"><?php echo $h($message); ?></div><?php endif; ?>
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="mb-3 font-semibold text-slate-900">Add a level</h2>
            <form method="post" class="flex flex-col gap-2 sm:flex-row sm:items-end">
                <input type="hidden" name="action" value="add_level">
                <div class="min-w-0 flex-1">
                    <label class="mb-1 block text-xs font-medium text-slate-600" for="lvl_val">Digits only</label>
                    <input id="lvl_val" class="w-full rounded-lg border border-slate-300 px-3 py-2" name="value" placeholder="e.g. 500" maxlength="8" inputmode="numeric" pattern="[0-9]+" required>
                </div>
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white" type="submit"><i class="fa-solid fa-plus mr-1.5"></i>Add</button>
            </form>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="mb-3 font-semibold text-slate-900">Preset list</h2>
            <?php if (!$levels): ?>
                <p class="text-sm text-slate-500">No rows in <code class="rounded bg-slate-100 px-1">levels</code> yet. Add digits above (100–400 are seeded on first app load).</p>
            <?php else: ?>
                <ul class="divide-y divide-slate-100 rounded-xl border border-slate-100">
                    <?php foreach ($levels as $row): ?>
                        <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5">
                            <span class="text-sm font-medium text-slate-800"><?php echo $h((string) ($row['value'] ?? '')); ?></span>
                            <form method="post" class="inline" onsubmit="return confirm('Remove this preset?');">
                                <input type="hidden" name="action" value="delete_level">
                                <input type="hidden" name="id" value="<?php echo (int) ($row['id'] ?? 0); ?>">
                                <button type="submit" class="text-xs font-medium text-red-600 hover:underline">Remove</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="mb-2 font-semibold text-slate-900">Effective dropdown (presets + in-use values)</h2>
            <p class="text-xs text-slate-500"><?php echo count($mergedPreview); ?> option(s): <?php echo $h(implode(', ', array_map(static fn ($x) => (string) ($x['label'] ?? ''), $mergedPreview))); ?></p>
        </section>
    </div>
</body>
</html>
