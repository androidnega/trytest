<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config/db.php';

if (empty($_SESSION['is_admin'])) {
    trytest_redirect(trytest_home_with_query(['mode' => 'admin']));
}

$uploadDir = __DIR__ . '/data/uploads/pdfs';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'upload_pdf') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $department = trim((string) ($_POST['department'] ?? ''));
        $level = trim((string) ($_POST['level'] ?? ''));
        if ($title === '') {
            $error = 'Title is required.';
        } elseif (!isset($_FILES['pdf']) || !is_array($_FILES['pdf']) || (int) ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'Please choose a PDF file to upload.';
        } else {
            $tmp = (string) ($_FILES['pdf']['tmp_name'] ?? '');
            $orig = (string) ($_FILES['pdf']['name'] ?? 'document.pdf');
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $tmp !== '' && is_uploaded_file($tmp) ? (string) $finfo->file($tmp) : '';
            if ($mime !== 'application/pdf') {
                $error = 'Only PDF files are allowed.';
            } else {
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                if ($ext !== 'pdf') {
                    $ext = 'pdf';
                }
                $stored = bin2hex(random_bytes(16)) . '.' . $ext;
                $dest = $uploadDir . '/' . $stored;
                if (!move_uploaded_file($tmp, $dest)) {
                    $error = 'Could not save file on the server.';
                } else {
                    @chmod($dest, 0644);
                    $db->prepare(
                        'INSERT INTO student_documents (title, department, level, stored_name, original_name)
                         VALUES (?, ?, ?, ?, ?)'
                    )->execute([$title, $department, $level, $stored, $orig]);
                    $message = 'PDF uploaded.';
                }
            }
        }
    }
    if ($action === 'delete_document') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $db->prepare('SELECT stored_name FROM student_documents WHERE id = ?');
            $row->execute([$id]);
            $sn = $row->fetchColumn();
            if ($sn) {
                $path = $uploadDir . '/' . basename((string) $sn);
                if (is_file($path)) {
                    @unlink($path);
                }
                $db->prepare('DELETE FROM student_documents WHERE id = ?')->execute([$id]);
                $message = 'Document removed.';
            }
        }
    }
}

$rows = $db->query(
    'SELECT id, title, department, level, original_name, created_at
     FROM student_documents
     ORDER BY id DESC'
)->fetchAll();

$deptOptions = $db->query(
    "SELECT DISTINCT TRIM(department) AS d FROM courses WHERE TRIM(COALESCE(department, '')) != '' ORDER BY d COLLATE NOCASE"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest — Student PDFs</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen p-4">
    <div class="max-w-5xl mx-auto py-6 space-y-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900">Student PDF materials</h1>
                <a href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_admin'), ENT_QUOTES, 'UTF-8'); ?>" class="text-sm text-indigo-600">Back to manager</a>
            </div>
            <p class="mt-2 text-sm text-slate-500">Files are stored on this server only. Students see each item on their dashboard and can download if it matches their program and level. Optional: turn on the YouTube subscription gate under <a class="font-medium text-indigo-600 hover:underline" href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_youtube'), ENT_QUOTES, 'UTF-8'); ?>">YouTube gate</a> (or leave it off for immediate downloads).</p>
            <?php if ($error !== ''): ?><div class="mt-3 rounded-lg bg-red-100 text-red-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($message !== ''): ?><div class="mt-3 rounded-lg bg-emerald-100 text-emerald-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="font-semibold mb-3">Upload PDF</h2>
                <form method="post" enctype="multipart/form-data" class="space-y-3">
                    <input type="hidden" name="action" value="upload_pdf">
                    <input class="w-full border rounded-lg px-3 py-2" name="title" placeholder="Title shown to students" required>
                    <select class="w-full border rounded-lg px-3 py-2" name="department">
                        <option value="">All programs (any department)</option>
                        <?php foreach ($deptOptions as $d): ?>
                            <?php $dv = trim((string) ($d['d'] ?? '')); ?>
                            <?php if ($dv === '') {
                                continue;
                            } ?>
                            <option value="<?php echo htmlspecialchars($dv, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($dv, ENT_QUOTES, 'UTF-8'); ?> only</option>
                        <?php endforeach; ?>
                    </select>
                    <select class="w-full border rounded-lg px-3 py-2" name="level">
                        <option value="">All levels</option>
                        <option value="100">100</option>
                        <option value="200">200</option>
                        <option value="300">300</option>
                        <option value="400">400</option>
                    </select>
                    <input class="w-full text-sm" type="file" name="pdf" accept="application/pdf" required>
                    <button class="w-full rounded-lg bg-slate-900 text-white py-2 font-medium" type="submit"><i class="fa-solid fa-cloud-arrow-up mr-2"></i>Upload</button>
                </form>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="font-semibold mb-3">Uploaded (<?php echo count($rows); ?>)</h2>
                <div class="space-y-2 max-h-[480px] overflow-auto text-sm">
                    <?php foreach ($rows as $r): ?>
                        <div class="flex items-start justify-between gap-2 rounded-lg border border-slate-200 p-3">
                            <div class="min-w-0">
                                <p class="font-medium truncate"><?php echo htmlspecialchars((string) $r['title'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <p class="text-xs text-slate-500 mt-1">
                                    <?php echo htmlspecialchars(trim((string) ($r['department'] ?? '')) === '' ? 'All programs' : (string) $r['department'], ENT_QUOTES, 'UTF-8'); ?>
                                    ·
                                    <?php echo htmlspecialchars(trim((string) ($r['level'] ?? '')) === '' ? 'All levels' : 'Level ' . $r['level'], ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <p class="text-[11px] text-slate-400 mt-1 truncate"><?php echo htmlspecialchars((string) $r['original_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <form method="post" class="shrink-0">
                                <input type="hidden" name="action" value="delete_document">
                                <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                <button type="submit" class="rounded border border-red-200 px-2 py-1 text-xs text-red-600 hover:bg-red-50" onclick="return confirm('Delete this PDF from the server?');" title="Delete"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <p class="text-slate-500 text-sm">No PDFs yet.</p>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</body>
</html>
