<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/levels.php';

$error = '';
$prompt = '';
$topic = '';
$topicsRaw = '';
$course = '';
$level = '';
$count = '';

/**
 * @return list<string>
 */
function trytest_parse_topics(string $raw): array
{
    $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
    $topics = [];
    foreach ($parts as $part) {
        $clean = trim((string) $part);
        if ($clean === '') {
            continue;
        }
        $topics[] = $clean;
    }
    return array_values(array_unique($topics));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $user = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        if (trytest_admin_count($db) < 1) {
            $error = 'No administrator account yet. Create one from the admin dashboard.';
        } elseif (trytest_admin_attempt_login($db, $user, $pass)) {
            trytest_redirect(trytest_url('dashboard/generate_ai'));
        } else {
            $error = 'Invalid admin username or password.';
        }
    } elseif (!empty($_SESSION['is_admin'])) {
        $topic = trim((string) ($_POST['topic'] ?? ''));
        $topicsRaw = trim((string) ($_POST['topics'] ?? ''));
        $course = trim((string) ($_POST['course'] ?? ''));
        $levelRaw = trim((string) ($_POST['level'] ?? ''));
        $count = trim((string) ($_POST['count'] ?? ''));
        $levelOpts = trytest_level_dropdown_options($db);
        $level = $levelRaw !== '' ? (trytest_resolve_level_for_save($levelRaw, $levelOpts) ?? '') : '';
        if ($levelRaw !== '' && $level === '') {
            $error = 'Choose a level from the list or leave it blank.';
        }

        $topics = trytest_parse_topics($topicsRaw !== '' ? $topicsRaw : $topic);
        $countInt = ctype_digit($count) ? (int) $count : 0;
        if ($error === '' && (count($topics) < 1 || $countInt < 1)) {
            $error = 'At least one topic and a valid question count are required.';
        }
        if ($error === '') {
            $topicList = implode(', ', $topics);
            $courseLine = $course !== '' ? "Course: {$course}\n" : '';
            $levelLine = $level !== '' ? "Level: {$level}\n" : '';
            $prompt = "Generate {$countInt} multiple choice questions using one or more of these topics: {$topicList}.\n\n" .
                $courseLine .
                $levelLine .
                "\nReturn ONLY JSON in this format:\n\n" .
                "[\n" .
                "  {\n" .
                "    \"question\": \"...\",\n" .
                "    \"topics\": [\"Topic A\", \"Topic B\"],\n" .
                "    \"options\": [\"A\", \"B\", \"C\", \"D\"],\n" .
                "    \"answer\": \"Correct Option\"\n" .
                "  }\n" .
                "]\n\n" .
                "Make sure:\n" .
                "- Every question includes at least one value in \"topics\"\n" .
                "- Topics come from this allowed list: {$topicList}\n" .
                "- Answers are included\n" .
                "- No explanation\n" .
                "- Clean format";
            $topic = $topicList;
            $topicsRaw = $topicList;
        }
    }
}

$isAdmin = !empty($_SESSION['is_admin']);
$levelOptions = $isAdmin ? trytest_level_dropdown_options($db) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php trytest_link_preview_meta(['title' => 'Trytest — AI prompt', 'description' => 'Trytest admin: AI exam generation.']); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Trytest — AI prompt</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(trytest_url('favicon.svg'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body{font-family:Inter,ui-sans-serif,system-ui,sans-serif}</style>
</head>
<body class="bg-slate-50 min-h-screen p-4">
    <div class="max-w-5xl mx-auto pt-6 pb-10 space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Generate Questions (AI)</h1>
                <p class="text-sm text-slate-500">Build a clean prompt, copy it, then paste AI JSON in the importer.</p>
            </div>
            <a href="<?php echo htmlspecialchars(trytest_home_url(), ENT_QUOTES, 'UTF-8'); ?>" class="text-sm text-indigo-600">Back to dashboard</a>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sm:p-6 space-y-5">

            <?php if ($error !== ''): ?>
                <div class="rounded-lg bg-red-100 text-red-700 px-3 py-2 text-sm"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if (!$isAdmin): ?>
                <p class="text-slate-600">Login to generate prompts.</p>
                <form method="post" class="space-y-3 max-w-md">
                    <input type="hidden" name="action" value="login">
                    <input class="w-full rounded-lg border border-slate-300 px-3 py-2" type="text" name="username" placeholder="Username" required>
                    <input class="w-full rounded-lg border border-slate-300 px-3 py-2" type="password" name="password" placeholder="Password" required>
                    <button class="w-full rounded-lg bg-slate-900 text-white py-2 font-medium" type="submit">Login</button>
                </form>
            <?php else: ?>
                <div class="grid gap-4 lg:grid-cols-[1fr_1fr]">
                    <form method="post" class="space-y-4 rounded-xl border border-slate-200 p-3 sm:p-4" id="aiPromptForm">
                        <input type="hidden" name="topic" value="<?php echo htmlspecialchars($topic, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="topics" id="topicsHidden" value="<?php echo htmlspecialchars($topicsRaw, ENT_QUOTES, 'UTF-8'); ?>">
                        <div>
                            <label for="topicInput" class="mb-1 block text-sm font-medium text-slate-700">Topics</label>
                            <div class="rounded-xl border border-slate-300 p-2">
                                <div id="topicChips" class="mb-2 flex flex-wrap gap-2"></div>
                                <input id="topicInput" placeholder="Type topic, press Enter or comma" class="w-full border-0 p-1 text-sm focus:outline-none">
                            </div>
                            <p class="mt-1 text-xs text-slate-500">Use comma or Enter to add multiple topics.</p>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <input name="course" value="<?php echo htmlspecialchars($course, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Course code (optional)" class="w-full p-3 border border-slate-300 rounded-lg">
                            <select name="level" class="w-full p-3 border border-slate-300 rounded-lg bg-white">
                                <option value="">Level (optional)</option>
                                <?php foreach ($levelOptions as $lo): ?>
                                    <?php $lv = (string) ($lo['value'] ?? ''); ?>
                                    <option value="<?php echo htmlspecialchars($lv, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $level !== '' && trytest_level_canon($level) === trytest_level_canon($lv) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) ($lo['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input name="count" value="<?php echo htmlspecialchars($count, ENT_QUOTES, 'UTF-8'); ?>" type="number" min="1" placeholder="Number of questions" class="w-full p-3 border border-slate-300 rounded-lg" required>
                        <button class="bg-emerald-600 text-white p-3 rounded-lg w-full font-medium" type="submit">Generate Prompt</button>
                    </form>

                    <div class="rounded-xl border border-slate-200 p-3 sm:p-4 space-y-3 bg-slate-50/40">
                        <p class="text-sm text-slate-600">Copy prompt -> paste into ChatGPT/Gemini -> copy JSON response -> import with JSON importer.</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" id="copyPromptBtn" class="rounded bg-slate-800 px-3 py-2 text-sm text-white">Copy prompt</button>
                            <a href="<?php echo htmlspecialchars(trytest_url('dashboard/import_json'), ENT_QUOTES, 'UTF-8'); ?>" class="inline-block bg-emerald-600 text-white px-4 py-2 rounded-lg text-sm">Go to JSON Import</a>
                        </div>
                        <textarea readonly id="promptBox" class="w-full h-72 p-3 border border-slate-300 rounded-lg font-mono text-sm bg-white"><?php echo htmlspecialchars($prompt, ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($isAdmin): ?>
    <script>
        (function () {
            var hidden = document.getElementById('topicsHidden');
            var input = document.getElementById('topicInput');
            var chips = document.getElementById('topicChips');
            var copyBtn = document.getElementById('copyPromptBtn');
            var promptBox = document.getElementById('promptBox');
            if (!hidden || !input || !chips) return;

            var topics = [];
            function normalize(v) {
                return String(v || '').trim().replace(/\s+/g, ' ');
            }
            function syncHidden() {
                hidden.value = topics.join(', ');
            }
            function removeTopic(idx) {
                topics.splice(idx, 1);
                render();
            }
            function addTopic(raw) {
                var value = normalize(raw);
                if (!value) return;
                var lower = value.toLowerCase();
                for (var i = 0; i < topics.length; i++) {
                    if (topics[i].toLowerCase() === lower) return;
                }
                topics.push(value);
                render();
            }
            function render() {
                chips.innerHTML = '';
                topics.forEach(function (t, idx) {
                    var chip = document.createElement('span');
                    chip.className = 'inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-700';
                    chip.textContent = t;

                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'rounded px-1 text-slate-500 hover:bg-slate-200';
                    btn.textContent = '×';
                    btn.setAttribute('aria-label', 'Remove topic');
                    btn.addEventListener('click', function () { removeTopic(idx); });
                    chip.appendChild(btn);
                    chips.appendChild(chip);
                });
                syncHidden();
            }
            function addFromInput() {
                addTopic(input.value);
                input.value = '';
            }

            hidden.value.split(',').forEach(function (piece) { addTopic(piece); });
            render();

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault();
                    addFromInput();
                }
            });
            input.addEventListener('blur', addFromInput);

            copyBtn?.addEventListener('click', function () {
                var text = promptBox?.value || '';
                if (!text) return;
                navigator.clipboard.writeText(text).then(function () {
                    copyBtn.textContent = 'Copied';
                    setTimeout(function () { copyBtn.textContent = 'Copy prompt'; }, 1200);
                });
            });
        })();
    </script>
    <?php endif; ?>
</body>
</html>
