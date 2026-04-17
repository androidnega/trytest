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

$docStmt = $db->prepare(
    'SELECT d.id, d.title, d.department, d.level, d.created_at,
        CASE WHEN x.document_id IS NOT NULL THEN 1 ELSE 0 END AS downloaded
     FROM student_documents d
     LEFT JOIN student_document_downloads x ON x.document_id = d.id AND x.user_id = ?
     ORDER BY d.id DESC'
);
$docStmt->execute([$userId]);
$docRows = $docStmt->fetchAll(PDO::FETCH_ASSOC);

$studentDocuments = [];
$pendingEligible = 0;
foreach ($docRows as $d) {
    $dd = trim((string) ($d['department'] ?? ''));
    $dl = trim((string) ($d['level'] ?? ''));
    $eligible = trytest_student_document_eligible($userDepartment, $userLevel, $dd, $dl);
    $downloaded = !empty($d['downloaded']);
    $createdAt = trim((string) ($d['created_at'] ?? ''));
    $isNew = $eligible && $seenForNew !== null && $createdAt !== '' && $createdAt > $seenForNew;
    if ($eligible && !$downloaded) {
        $pendingEligible++;
    }
    $studentDocuments[] = [
        'id' => (int) ($d['id'] ?? 0),
        'title' => (string) ($d['title'] ?? ''),
        'department' => $dd,
        'level' => $dl,
        'eligible' => $eligible,
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
$ytLockedModal = trytest_youtube_downloads_locked_modal_html($yt);
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
    <title>Downloads · Trytest</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo $h(trytest_url('favicon.svg')); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body { font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif; }</style>
</head>
<body class="min-h-screen bg-white text-slate-900">
    <header class="sticky top-0 z-10 border-b border-slate-200 bg-white px-4 py-2.5">
        <div class="mx-auto flex max-w-lg items-center gap-3">
            <a href="<?php echo $h($dashboardUrl); ?>" class="shrink-0 text-sm font-semibold text-[#2C6A7D] hover:underline">← Home</a>
            <div class="flex min-w-0 flex-1 items-center justify-end gap-2">
                <h1 class="min-w-0 flex-1 truncate text-base font-bold <?php echo $pendingEligible > 0 ? 'pr-1' : ''; ?>">Downloads</h1>
                <?php if ($pendingEligible > 0): ?>
                    <span class="inline-flex h-5 min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-[#E50914] px-1.5 text-[10px] font-extrabold leading-none text-white" title="Files you have not downloaded yet"><?php echo $pendingEligible > 9 ? '9+' : (string) $pendingEligible; ?></span>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <main class="mx-auto max-w-lg px-4 py-4">
        <?php if ($ytActivationPanel !== ''): ?>
            <div class="text-left"><?php echo $ytActivationPanel; ?></div>
        <?php elseif ($ytSoftPromo !== ''): ?>
            <div class="text-left"><?php echo $ytSoftPromo; ?></div>
        <?php elseif (!empty($yt['gate_active']) && trim((string) ($yt['channel_id'] ?? '')) === ''): ?>
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-center text-xs text-amber-900">PDF gate is on but no YouTube channel is set yet — ask your teacher to finish YouTube setup so subscribe and download can work.</div>
        <?php endif; ?>
        <?php if (!$studentDocuments): ?>
            <p class="rounded-lg border border-dashed border-slate-200 px-3 py-8 text-center text-sm text-slate-500">No files available.</p>
        <?php else: ?>
            <ul class="space-y-2">
                <?php foreach ($studentDocuments as $doc): ?>
                    <?php
                    $eligible = !empty($doc['eligible']);
                    $dd = trim((string) ($doc['department'] ?? ''));
                    $dl = trim((string) ($doc['level'] ?? ''));
                    $scope = ($dd === '' ? 'Any program' : $dd) . ' · ' . ($dl === '' ? 'Any level' : ('Lv ' . $dl));
                    ?>
                    <li class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 shadow-sm">
                        <div class="flex items-start gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <p class="truncate text-sm font-semibold text-slate-900"><?php echo $h((string) ($doc['title'] ?? 'PDF')); ?></p>
                                    <?php if ($eligible && !empty($doc['is_new'])): ?>
                                        <span class="shrink-0 rounded-full bg-amber-100 px-1.5 py-0.5 text-[9px] font-extrabold uppercase tracking-wide text-amber-900">New</span>
                                    <?php endif; ?>
                                </div>
                                <p class="mt-0.5 truncate text-[10px] text-slate-500"><?php echo $h($scope); ?></p>
                            </div>
                        </div>
                        <?php if ($eligible): ?>
                            <?php if (!empty($doc['downloaded'])): ?>
                                <span class="mt-2 inline-flex w-full items-center justify-center rounded-lg border border-emerald-200 bg-emerald-50 py-1.5 text-center text-xs font-bold text-emerald-800">Downloaded</span>
                            <?php elseif ($downloadsLocked): ?>
                                <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50/90 px-2 py-2 text-center">
                                    <p class="text-[11px] font-semibold text-amber-950">Subscribe first</p>
                                    <p class="mt-0.5 text-[10px] leading-snug text-amber-900/90">Use the red <strong>YouTube</strong> block above, then your download buttons turn on.</p>
                                    <a href="#trytest-downloads-yt-gate" class="mt-1.5 inline-block text-[10px] font-bold text-[#2C6A7D] underline decoration-2 underline-offset-2">Jump to subscribe</a>
                                </div>
                            <?php else: ?>
                                <a href="<?php echo $h($downloadResourceBase); ?>?id=<?php echo (int) ($doc['id'] ?? 0); ?>" class="mt-2 block w-full rounded-lg bg-[#2C6A7D] py-1.5 text-center text-xs font-bold text-white hover:bg-[#24586a]">Download</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="mt-2 rounded-md bg-slate-100 py-1 text-center text-[10px] text-slate-500">Not for your program / level</p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($ytLockedModal !== ''): ?>
            <?php echo $ytLockedModal; ?>
        <?php endif; ?>
    </main>
</body>
</html>
