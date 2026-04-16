<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/youtube_subscribe.php';

if (empty($_SESSION['is_admin'])) {
    trytest_redirect(trytest_url('admin'));
}

$error = '';
$message = '';

$row = [
    'gate_enabled' => 0,
    'client_id' => '',
    'client_secret' => '',
    'redirect_uri' => trytest_absolute_url('youtube_oauth_callback'),
    'channel_id' => '',
];
$st = $db->query('SELECT gate_enabled, client_id, client_secret, redirect_uri, channel_id FROM youtube_app_settings WHERE id = 1');
if ($st) {
    $fetched = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($fetched)) {
        $row = array_merge($row, $fetched);
    }
}

$hasStoredSecret = trim((string) ($row['client_secret'] ?? '')) !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_youtube') {
        $gateOn = isset($_POST['gate_on']);
        $clientId = trim((string) ($_POST['client_id'] ?? ''));
        $redirectUri = trim((string) ($_POST['redirect_uri'] ?? ''));
        $channelId = trim((string) ($_POST['channel_id'] ?? ''));
        $newSecret = trim((string) ($_POST['client_secret_new'] ?? ''));
        $secret = $hasStoredSecret ? trim((string) ($row['client_secret'] ?? '')) : '';
        if ($newSecret !== '') {
            $secret = $newSecret;
        }

        if ($gateOn && ($clientId === '' || $redirectUri === '' || $channelId === '' || $secret === '')) {
            $error = 'When the gate is on, Client ID, Client secret, Redirect URI, and Channel ID (UC…) are all required.';
        } else {
            $db->prepare(
                'UPDATE youtube_app_settings SET
                    gate_enabled = ?,
                    client_id = ?,
                    client_secret = ?,
                    redirect_uri = ?,
                    channel_id = ?,
                    updated_at = datetime(\'now\')
                 WHERE id = 1'
            )->execute([
                $gateOn ? 1 : 0,
                $clientId,
                $secret,
                $redirectUri,
                $channelId,
            ]);
            $message = 'YouTube settings saved.';
            $row = [
                'gate_enabled' => $gateOn ? 1 : 0,
                'client_id' => $clientId,
                'client_secret' => $secret,
                'redirect_uri' => $redirectUri,
                'channel_id' => $channelId,
            ];
            $hasStoredSecret = $secret !== '';
        }
    }
}

$effective = trytest_youtube_settings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trytest — YouTube &amp; PDF gate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 p-4">
    <div class="mx-auto max-w-2xl space-y-4 py-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">YouTube API &amp; PDF downloads</h1>
                    <p class="mt-1 text-sm text-slate-500">Control whether students must verify a YouTube subscription before downloading PDF materials.</p>
                </div>
                <a href="<?php echo htmlspecialchars(trytest_url('dashboard/manage_admin'), ENT_QUOTES, 'UTF-8'); ?>" class="text-sm font-medium text-indigo-600 hover:underline">← Manager</a>
            </div>
            <?php if ($error !== ''): ?>
                <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($message !== ''): ?>
                <div class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Effective status</p>
                    <?php if ($effective['gate_active']): ?>
                        <p class="mt-1 text-sm font-semibold text-emerald-700"><i class="fa-solid fa-lock mr-1"></i> Gate on — subscription required to download</p>
                    <?php elseif (!empty($effective['gate_enabled']) && empty($effective['credentials_complete'])): ?>
                        <p class="mt-1 text-sm font-semibold text-amber-700"><i class="fa-solid fa-triangle-exclamation mr-1"></i> Gate toggled on but credentials incomplete — downloads stay open until fixed</p>
                    <?php else: ?>
                        <p class="mt-1 text-sm font-semibold text-slate-600"><i class="fa-solid fa-unlock mr-1"></i> Straightforward downloads — no YouTube check</p>
                    <?php endif; ?>
                </div>
            </div>

            <form method="post" class="space-y-4">
                <input type="hidden" name="action" value="save_youtube">

                <div class="flex flex-col gap-3 rounded-xl border border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="font-semibold text-slate-900">Require YouTube subscription</p>
                        <p class="mt-0.5 text-xs text-slate-500">Off = students download PDFs immediately (still filtered by program/level). On = Google sign-in + subscribed to your channel.</p>
                    </div>
                    <label class="inline-flex cursor-pointer items-center gap-2 shrink-0">
                        <input type="checkbox" name="gate_on" value="1" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" <?php echo !empty($row['gate_enabled']) ? 'checked' : ''; ?>>
                        <span class="text-sm font-medium text-slate-700">Enabled</span>
                    </label>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">OAuth client ID</label>
                    <input class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" type="text" name="client_id" value="<?php echo htmlspecialchars((string) ($row['client_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" placeholder="xxxxx.apps.googleusercontent.com">
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">OAuth client secret</label>
                    <?php if ($hasStoredSecret): ?>
                        <p class="mb-1 text-[11px] text-slate-500">A secret is saved. Enter a new value only if you want to replace it.</p>
                    <?php endif; ?>
                    <input class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" type="password" name="client_secret_new" value="" autocomplete="new-password" placeholder="<?php echo $hasStoredSecret ? 'Leave blank to keep current secret' : 'Paste client secret'; ?>">
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Authorized redirect URI</label>
                    <input class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-xs" type="url" name="redirect_uri" value="<?php echo htmlspecialchars((string) ($row['redirect_uri'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars(trytest_absolute_url('youtube_oauth_callback'), ENT_QUOTES, 'UTF-8'); ?>">
                    <p class="mt-1 text-[11px] text-slate-500">Must match exactly what you entered in Google Cloud → OAuth client → Authorized redirect URIs.</p>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Channel ID (UC…)</label>
                    <input class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-xs" type="text" name="channel_id" value="<?php echo htmlspecialchars((string) ($row['channel_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="UCxxxxxxxxxxxxxxxxxxxxxxxxxx">
                    <p class="mt-1 text-[11px] text-slate-500">From your channel URL: youtube.com/channel/<strong>UC…</strong> — not the @handle URL.</p>
                </div>

                <div class="rounded-xl border border-slate-100 bg-slate-50 p-3 text-[11px] text-slate-600">
                    <p class="font-semibold text-slate-700">Optional file fallback</p>
                    <p class="mt-1">If a field is left empty here, <code class="rounded bg-white px-1">config/youtube.php</code> (or env vars) can still supply that piece. The <strong>toggle above</strong> always lives in this database and decides whether the gate runs when credentials are complete.</p>
                </div>

                <button type="submit" class="w-full rounded-lg bg-slate-900 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Save settings</button>
            </form>
        </div>
    </div>
</body>
</html>
