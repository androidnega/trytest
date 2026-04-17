<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/student_helpers.php';

if (empty($_SESSION['user_id'])) {
    trytest_redirect(trytest_home_url());
}

$userId = (int) $_SESSION['user_id'];
$sync = $db->prepare('SELECT index_number, level, department FROM users WHERE id = ?');
$sync->execute([$userId]);
$syncRow = $sync->fetch();
if (!$syncRow) {
    trytest_redirect(trytest_home_url());
}
$userIndex = (string) ($syncRow['index_number'] ?? '');
$userLevel = trim((string) ($syncRow['level'] ?? ''));
$userDepartment = trim((string) ($syncRow['department'] ?? ''));
$_SESSION['user_index_number'] = $userIndex;
$_SESSION['user_level'] = $userLevel;
$_SESSION['user_department'] = $userDepartment;

$studentDocuments = [];
$docRows = $db->query('SELECT id, title, department, level FROM student_documents ORDER BY id DESC')->fetchAll();
foreach ($docRows as $d) {
    $dd = trim((string) ($d['department'] ?? ''));
    $dl = trim((string) ($d['level'] ?? ''));
    $studentDocuments[] = [
        'id' => (int) ($d['id'] ?? 0),
        'title' => (string) ($d['title'] ?? ''),
        'department' => $dd,
        'level' => $dl,
        'eligible' => trytest_student_document_eligible($userDepartment, $userLevel, $dd, $dl),
    ];
}

$dashboardUrl = trytest_url('dashboard');
$downloadResourceBase = trytest_url('download_resource');
$h = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Downloads · Trytest</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo $h(trytest_url('favicon.svg')); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body { font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif; }</style>
</head>
<body class="min-h-screen bg-white text-slate-900">
    <header class="sticky top-0 z-10 border-b border-slate-200 bg-white px-4 py-3">
        <div class="mx-auto flex max-w-lg items-center gap-3">
            <a href="<?php echo $h($dashboardUrl); ?>" class="text-sm font-semibold text-[#2C6A7D] hover:underline">← Home</a>
            <h1 class="min-w-0 flex-1 truncate text-lg font-bold">Downloads</h1>
        </div>
    </header>
    <main class="mx-auto max-w-lg px-4 py-5">
        <?php if (!$studentDocuments): ?>
            <p class="rounded-xl border border-dashed border-slate-200 px-4 py-10 text-center text-sm text-slate-500">No files available.</p>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($studentDocuments as $doc): ?>
                    <?php
                    $eligible = !empty($doc['eligible']);
                    $dd = trim((string) ($doc['department'] ?? ''));
                    $dl = trim((string) ($doc['level'] ?? ''));
                    $scope = ($dd === '' ? 'Any program' : $dd) . ' · ' . ($dl === '' ? 'Any level' : ('Lv ' . $dl));
                    ?>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="font-semibold text-slate-900"><?php echo $h((string) ($doc['title'] ?? 'PDF')); ?></p>
                        <p class="mt-1 text-[11px] text-slate-500"><?php echo $h($scope); ?></p>
                        <?php if ($eligible): ?>
                            <a href="<?php echo $h($downloadResourceBase); ?>?id=<?php echo (int) ($doc['id'] ?? 0); ?>" class="mt-3 block w-full rounded-xl bg-[#2C6A7D] py-2.5 text-center text-sm font-bold text-white hover:bg-[#24586a]">Download</a>
                        <?php else: ?>
                            <p class="mt-3 rounded-lg bg-slate-100 py-2 text-center text-xs text-slate-500">Not for your program / level</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
