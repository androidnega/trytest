<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/student_helpers.php';
require_once __DIR__ . '/includes/youtube_subscribe.php';

if (empty($_SESSION['user_id'])) {
    trytest_redirect(trytest_home_url());
}

$userId = (int) $_SESSION['user_id'];
$sync = $db->prepare('SELECT index_number, level, department, downloads_last_seen_at FROM users WHERE id = ?');
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

$yt = trytest_youtube_settings();
$ytGateErr = '';
if (!empty($yt['gate_active']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $postGateErr = trytest_pdf_light_gate_process_unlock_post($yt, trytest_url('downloads'));
    if ($postGateErr !== null && $postGateErr !== '') {
        $ytGateErr = $postGateErr;
    }
}

$seenRaw = trim((string) ($syncRow['downloads_last_seen_at'] ?? ''));
$seenForNew = $seenRaw !== '' ? $seenRaw : null;

$vis = trytest_student_documents_visibility_sql('d', $userLevel, $userDepartment);
$docStmt = $db->prepare(
    'SELECT d.id, d.title, d.created_at,
        CASE WHEN x.document_id IS NOT NULL THEN 1 ELSE 0 END AS downloaded
     FROM student_documents d
     LEFT JOIN student_document_downloads x ON x.document_id = d.id AND x.user_id = ?
     WHERE ' . $vis['sql'] . '
     ORDER BY d.id DESC'
);
$docStmt->execute(array_merge([$userId], $vis['params']));
$docRows = $docStmt->fetchAll(PDO::FETCH_ASSOC);

$studentDocuments = [];
$pendingEligible = 0;
foreach ($docRows as $d) {
    $downloaded = !empty($d['downloaded']);
    $createdAt = trim((string) ($d['created_at'] ?? ''));
    $isNew = $seenForNew !== null && $createdAt !== '' && $createdAt > $seenForNew;
    if (!$downloaded) {
        $pendingEligible++;
    }
    $studentDocuments[] = [
        'id' => (int) ($d['id'] ?? 0),
        'title' => (string) ($d['title'] ?? ''),
        'downloaded' => $downloaded,
        'is_new' => $isNew,
    ];
}

$updSeen = $db->prepare('UPDATE users SET downloads_last_seen_at = datetime(\'now\') WHERE id = ?');
$updSeen->execute([$userId]);

$dashboardUrl = trytest_url('dashboard');
$downloadResourceBase = trytest_url('download_resource');
$ytActivationPanel = trytest_youtube_downloads_activation_panel_html($yt, trytest_url('downloads'), $ytGateErr);
$ytSoftPromo = trytest_youtube_downloads_soft_promo_html($yt);
$downloadsLocked = !empty($yt['gate_active']) && !trytest_youtube_download_allowed($yt);
$h = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Files · Trytest</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo $h(trytest_url('favicon.svg')); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body { font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif; }</style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <header class="sticky top-0 z-10 border-b border-slate-200/80 bg-white/95 backdrop-blur">
        <div class="mx-auto flex max-w-lg items-center gap-3 px-4 py-3">
            <a href="<?php echo $h($dashboardUrl); ?>" class="shrink-0 text-sm font-semibold text-[#2C6A7D] hover:underline">← Home</a>
            <div class="flex min-w-0 flex-1 items-center justify-end gap-2">
                <h1 class="min-w-0 flex-1 truncate text-lg font-bold tracking-tight text-slate-900"><?php echo $downloadsLocked ? 'Subscribe' : 'Your files'; ?></h1>
                <?php if (!$downloadsLocked && $pendingEligible > 0): ?>
                    <span class="inline-flex h-6 min-w-[1.5rem] shrink-0 items-center justify-center rounded-full bg-[#E50914] px-1.5 text-[10px] font-extrabold leading-none text-white" title="Not downloaded yet"><?php echo $pendingEligible > 9 ? '9+' : (string) $pendingEligible; ?></span>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <main class="mx-auto max-w-lg px-4 py-6">
        <?php if ($downloadsLocked): ?>
            <?php if ($ytActivationPanel !== ''): ?>
                <?php echo $ytActivationPanel; ?>
            <?php elseif (!empty($yt['gate_active']) && trim((string) ($yt['channel_id'] ?? '')) === ''): ?>
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-center text-xs text-amber-900">PDF gate is on but no YouTube channel is set yet — ask your teacher to finish YouTube setup.</div>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($ytActivationPanel !== ''): ?>
                <div class="mb-6"><?php echo $ytActivationPanel; ?></div>
            <?php elseif ($ytSoftPromo !== ''): ?>
                <div class="mb-6"><?php echo $ytSoftPromo; ?></div>
            <?php elseif (!empty($yt['gate_active']) && trim((string) ($yt['channel_id'] ?? '')) === ''): ?>
                <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-center text-xs text-amber-900">PDF gate is on but no YouTube channel is set yet — ask your teacher to finish YouTube setup.</div>
            <?php endif; ?>

            <?php if (!$studentDocuments): ?>
                <div class="rounded-2xl border border-dashed border-slate-200 bg-white px-6 py-14 text-center">
                    <p class="text-sm font-medium text-slate-700">No files for your program yet</p>
                    <p class="mt-2 text-xs leading-relaxed text-slate-500">When your teacher adds PDFs for your level and department, they will show up here.</p>
                </div>
            <?php else: ?>
                <ul class="space-y-3">
                    <?php foreach ($studentDocuments as $doc): ?>
                        <li class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <div class="flex items-center gap-3 px-4 py-3.5">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#2C6A7D]/10 text-lg" aria-hidden="true">📄</span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="truncate text-sm font-semibold text-slate-900"><?php echo $h((string) ($doc['title'] ?? 'PDF')); ?></p>
                                        <?php if (!empty($doc['is_new'])): ?>
                                            <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[9px] font-extrabold uppercase tracking-wide text-amber-900">New</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="border-t border-slate-100 px-4 py-3">
                                <?php if (!empty($doc['downloaded'])): ?>
                                    <span class="flex w-full items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 py-2.5 text-xs font-bold text-emerald-800">Downloaded</span>
                                <?php else: ?>
                                    <a href="<?php echo $h($downloadResourceBase); ?>?id=<?php echo (int) ($doc['id'] ?? 0); ?>" class="block w-full rounded-xl bg-[#2C6A7D] py-2.5 text-center text-sm font-bold text-white shadow-sm transition hover:bg-[#24586a]">Download</a>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
