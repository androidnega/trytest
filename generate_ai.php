<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/levels.php';
require_once __DIR__ . '/includes/question_json_formats.php';

$error = '';
$prompt = '';
$topic = '';
$topicsRaw = '';
$course = '';
$level = '';
$count = '';
$questionFormat = 'mcq';

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
        $questionFormat = trytest_normalize_question_format_type((string) ($_POST['question_format'] ?? 'mcq'));
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
            $prompt = trytest_build_ai_question_prompt($questionFormat, $topics, $countInt, $course, $level);
            $topic = implode(', ', $topics);
            $topicsRaw = $topic;
        }
    }
}

$isAdmin = !empty($_SESSION['is_admin']);
$levelOptions = $isAdmin ? trytest_level_dropdown_options($db) : [];
$formatExample = trytest_question_json_format_example($questionFormat);
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php trytest_link_preview_meta(['title' => 'Trytest — AI prompt', 'description' => 'Trytest admin: AI exam generation.']); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Trytest — AI prompt</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo $h(trytest_url('favicon.svg')); ?>">
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
                <p class="text-sm text-slate-500">Pick a question type, copy the JSON format for AI, generate a prompt, then import the response.</p>
            </div>
            <a href="<?php echo $h(trytest_home_url()); ?>" class="text-sm text-indigo-600">Back to dashboard</a>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sm:p-6 space-y-5">

            <?php if ($error !== ''): ?>
                <div class="rounded-lg bg-red-100 text-red-700 px-3 py-2 text-sm"><?php echo $h($error); ?></div>
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
                <div class="rounded-xl border border-indigo-100 bg-indigo-50/50 p-3 sm:p-4 space-y-3">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">JSON format for AI</p>
                            <p class="text-xs text-slate-600 mt-0.5">Copy this sample so the AI returns only the type you chose.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach (trytest_question_format_types() as $ft): ?>
                                <button type="button" class="format-tab rounded-lg border px-3 py-1.5 text-xs font-semibold <?php echo $questionFormat === $ft ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300 bg-white text-slate-700'; ?>" data-format="<?php echo $h($ft); ?>"><?php echo $h(trytest_question_format_label($ft)); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" id="copyFormatBtn" class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">Copy JSON format</button>
                        <span id="copyFormatStatus" class="self-center text-xs text-emerald-700"></span>
                    </div>
                    <textarea readonly id="formatBox" class="w-full h-48 p-3 border border-slate-300 rounded-lg font-mono text-xs bg-white"><?php echo $h($formatExample); ?></textarea>
                </div>

                <div class="grid gap-4 lg:grid-cols-[1fr_1fr]">
                    <form method="post" class="space-y-4 rounded-xl border border-slate-200 p-3 sm:p-4" id="aiPromptForm">
                        <input type="hidden" name="topic" value="<?php echo $h($topic); ?>">
                        <input type="hidden" name="topics" id="topicsHidden" value="<?php echo $h($topicsRaw); ?>">
                        <input type="hidden" name="question_format" id="questionFormatHidden" value="<?php echo $h($questionFormat); ?>">
                        <div>
                            <span class="mb-1 block text-sm font-medium text-slate-700">Question type</span>
                            <div class="grid gap-2 sm:grid-cols-3">
                                <?php foreach (trytest_question_format_types() as $ft): ?>
                                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50">
                                        <input type="radio" name="question_format_radio" value="<?php echo $h($ft); ?>" class="format-radio accent-indigo-600" <?php echo $questionFormat === $ft ? 'checked' : ''; ?>>
                                        <span class="font-medium text-slate-800"><?php echo $h(trytest_question_format_label($ft)); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">The prompt will ask AI for this type only — not a mix.</p>
                        </div>
                        <div>
                            <label for="topicInput" class="mb-1 block text-sm font-medium text-slate-700">Topics</label>
                            <div class="rounded-xl border border-slate-300 p-2">
                                <div id="topicChips" class="mb-2 flex flex-wrap gap-2"></div>
                                <input id="topicInput" placeholder="Type topic, press Enter or comma" class="w-full border-0 p-1 text-sm focus:outline-none">
                            </div>
                            <p class="mt-1 text-xs text-slate-500">Use comma or Enter to add multiple topics.</p>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <input name="course" value="<?php echo $h($course); ?>" placeholder="Course code (optional)" class="w-full p-3 border border-slate-300 rounded-lg">
                            <select name="level" class="w-full p-3 border border-slate-300 rounded-lg bg-white">
                                <option value="">Level (optional)</option>
                                <?php foreach ($levelOptions as $lo): ?>
                                    <?php $lv = (string) ($lo['value'] ?? ''); ?>
                                    <option value="<?php echo $h($lv); ?>" <?php echo $level !== '' && trytest_level_canon($level) === trytest_level_canon($lv) ? 'selected' : ''; ?>><?php echo $h((string) ($lo['label'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input name="count" value="<?php echo $h($count); ?>" type="number" min="1" placeholder="Number of questions" class="w-full p-3 border border-slate-300 rounded-lg" required>
                        <button class="bg-emerald-600 text-white p-3 rounded-lg w-full font-medium" type="submit">Generate Prompt</button>
                    </form>

                    <div class="rounded-xl border border-slate-200 p-3 sm:p-4 space-y-3 bg-slate-50/40">
                        <p class="text-sm text-slate-600">Copy prompt → paste into ChatGPT/Gemini → copy JSON response → import.</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" id="copyPromptBtn" class="rounded bg-slate-800 px-3 py-2 text-sm text-white">Copy prompt</button>
                            <a href="<?php echo $h(trytest_url('dashboard/import_json')); ?>" class="inline-block bg-emerald-600 text-white px-4 py-2 rounded-lg text-sm">Go to JSON Import</a>
                        </div>
                        <textarea readonly id="promptBox" class="w-full h-72 p-3 border border-slate-300 rounded-lg font-mono text-sm bg-white"><?php echo $h($prompt); ?></textarea>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($isAdmin): ?>
    <script>
        (function () {
            var formats = <?php echo json_encode([
                'mcq' => trytest_question_json_format_example('mcq'),
                'fill' => trytest_question_json_format_example('fill'),
                'true_false' => trytest_question_json_format_example('true_false'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            var formatBox = document.getElementById('formatBox');
            var formatHidden = document.getElementById('questionFormatHidden');
            var copyFormatBtn = document.getElementById('copyFormatBtn');
            var copyFormatStatus = document.getElementById('copyFormatStatus');
            var tabs = document.querySelectorAll('.format-tab');
            var radios = document.querySelectorAll('.format-radio');

            function setFormat(ft) {
                if (!formats[ft]) ft = 'mcq';
                if (formatBox) formatBox.value = formats[ft];
                if (formatHidden) formatHidden.value = ft;
                tabs.forEach(function (btn) {
                    var on = btn.getAttribute('data-format') === ft;
                    btn.classList.toggle('border-indigo-600', on);
                    btn.classList.toggle('bg-indigo-600', on);
                    btn.classList.toggle('text-white', on);
                    btn.classList.toggle('border-slate-300', !on);
                    btn.classList.toggle('bg-white', !on);
                    btn.classList.toggle('text-slate-700', !on);
                });
                radios.forEach(function (r) {
                    r.checked = r.value === ft;
                });
            }

            tabs.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    setFormat(btn.getAttribute('data-format') || 'mcq');
                });
            });
            radios.forEach(function (r) {
                r.addEventListener('change', function () {
                    if (r.checked) setFormat(r.value);
                });
            });
            copyFormatBtn?.addEventListener('click', function () {
                var text = formatBox?.value || '';
                if (!text) return;
                navigator.clipboard.writeText(text).then(function () {
                    if (copyFormatStatus) copyFormatStatus.textContent = 'Format copied';
                    copyFormatBtn.textContent = 'Copied';
                    setTimeout(function () {
                        copyFormatBtn.textContent = 'Copy JSON format';
                        if (copyFormatStatus) copyFormatStatus.textContent = '';
                    }, 1200);
                });
            });

            var form = document.getElementById('aiPromptForm');
            form?.addEventListener('submit', function () {
                var checked = document.querySelector('.format-radio:checked');
                if (checked && formatHidden) formatHidden.value = checked.value;
            });

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
