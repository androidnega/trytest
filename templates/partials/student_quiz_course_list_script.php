<?php

declare(strict_types=1);

/** @var string $quizSchedulesPollUrl */

?>
<script>
(function () {
    function setQuizResumeProgress() {
        document.querySelectorAll('.trytest-quiz-card').forEach(function (card) {
            var quizId = parseInt(card.getAttribute('data-quiz-id') || '0', 10);
            var userIdVal = parseInt(card.getAttribute('data-user-id') || '0', 10);
            var el = card.querySelector('.trytest-quiz-progress');
            if (!el || !quizId || !userIdVal) return;
            var fallback = el.textContent || 'Not started';
            var key = 'trytest_quiz_resume_v1_' + String(userIdVal) + '_' + String(quizId);
            try {
                var raw = localStorage.getItem(key);
                if (!raw) {
                    el.textContent = fallback;
                    return;
                }
                var payload = JSON.parse(raw);
                if (!payload || payload.v !== 1 || !Array.isArray(payload.orderedIds)) {
                    el.textContent = fallback;
                    return;
                }
                var total = payload.orderedIds.length || parseInt(el.getAttribute('data-total') || '0', 10) || 0;
                var idx = parseInt(String(payload.currentIndex || 0), 10);
                if (isNaN(idx) || idx < 0) idx = 0;
                if (total > 0 && idx > total) idx = total;
                if (total > 0 && idx > 0 && idx < total) {
                    var pct = Math.round((idx / total) * 100);
                    el.textContent = 'In progress: ' + idx + '/' + total + ' (' + pct + '%)';
                    return;
                }
                el.textContent = fallback;
            } catch (e) {
                el.textContent = fallback;
            }
        });
    }

    function pad2(n) { return String(n).padStart(2, '0'); }
    function formatMinSec(ms) {
        if (ms <= 0) return '0:00';
        var totalSec = Math.floor(ms / 1000);
        var m = Math.floor(totalSec / 60);
        var sec = totalSec % 60;
        return String(m) + ':' + pad2(sec);
    }
    function countdownBase() {
        return 'trytest-quiz-countdown mt-1 min-h-[1.6rem] w-full rounded-md border px-1.5 py-1 text-center text-[10px] font-black tabular-nums leading-tight tracking-tight sm:text-[11px] ';
    }
    function cardTimes(card) {
        var sRaw = card.getAttribute('data-quiz-start') || '';
        var eRaw = card.getAttribute('data-quiz-end') || '';
        var s = sRaw ? parseInt(sRaw, 10) * 1000 : 0;
        var e = eRaw ? parseInt(eRaw, 10) * 1000 : 0;
        return { s: s, e: e };
    }
    function phaseKey(now, s, e) {
        if (!s && !e) return 'unset';
        if (s && now < s) return 'before';
        if (e && now >= e) return 'after';
        return 'open';
    }
    function setQuizBadge(badge, key) {
        if (!badge) return;
        var base = 'trytest-quiz-badge shrink-0 rounded-full px-2 py-0.5 text-[9px] font-bold ';
        if (key === 'before') {
            badge.className = base + 'bg-amber-50 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200';
            badge.textContent = 'Soon';
        } else if (key === 'after') {
            badge.className = base + 'bg-slate-100 text-slate-500 dark:bg-zinc-800 dark:text-zinc-400';
            badge.textContent = 'Closed';
        } else {
            badge.className = base + 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300';
            badge.textContent = 'Open';
        }
    }
    function tick() {
        var now = Date.now();
        document.querySelectorAll('.trytest-quiz-card').forEach(function (card) {
            var t = cardTimes(card);
            var s = t.s;
            var e = t.e;
            var pk = phaseKey(now, s, e);
            var canPlay = pk === 'open' || pk === 'unset';
            var badge = card.querySelector('.trytest-quiz-badge');
            var title = card.querySelector('.trytest-quiz-title');
            if (pk === 'unset') {
                setQuizBadge(badge, 'open');
            } else {
                setQuizBadge(badge, pk);
            }
            if (title) {
                if (canPlay) {
                    title.className =
                        'trytest-quiz-title block text-[13px] font-semibold leading-snug text-slate-800 hover:text-[#2C6A7D] dark:text-zinc-100 dark:hover:text-[#7eb8b8]';
                } else {
                    title.className =
                        'trytest-quiz-title block cursor-default text-[13px] font-semibold leading-snug pointer-events-none text-slate-600 dark:text-zinc-500';
                }
            }
            var el = card.querySelector('.trytest-quiz-countdown');
            if (!el) return;
            if (!s && !e) {
                el.textContent = '';
                el.className = countdownBase() + 'hidden';
                return;
            }
            if (s && now < s) {
                el.className =
                    countdownBase() +
                    'border-amber-300 bg-amber-100 text-amber-950 shadow-inner dark:border-amber-700 dark:bg-amber-950/50 dark:text-amber-100';
                el.innerHTML =
                    '<span class="block text-[9px] font-bold uppercase tracking-wide text-amber-800/90 dark:text-amber-200/90">Opens in</span><span class="mt-0.5 block text-sm sm:text-base">' +
                    formatMinSec(s - now) +
                    '</span>';
                return;
            }
            if (e && now < e) {
                el.className =
                    countdownBase() +
                    'border-sky-400 bg-sky-100 text-sky-950 shadow-inner dark:border-sky-700 dark:bg-sky-950/40 dark:text-sky-100';
                el.innerHTML =
                    '<span class="block text-[9px] font-bold uppercase tracking-wide text-sky-900/90 dark:text-sky-200/90">Closes in</span><span class="mt-0.5 block text-sm sm:text-base">' +
                    formatMinSec(e - now) +
                    '</span>';
                return;
            }
            if (e && now >= e) {
                el.className =
                    countdownBase() + 'border-slate-300 bg-slate-200 text-slate-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300';
                el.textContent = 'Ended';
                return;
            }
            if (s && now >= s && (!e || now <= e)) {
                el.className =
                    countdownBase() +
                    'border-emerald-300 bg-emerald-100 text-emerald-950 shadow-inner ring-1 ring-emerald-200/80 dark:border-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-100 dark:ring-emerald-900/50';
                var playHref = card.getAttribute('data-quiz-href') || '';
                var hasAttempt = card.getAttribute('data-user-has-attempt') === '1';
                var playLabel = hasAttempt ? 'Retake (resets points)' : 'Start now';
                el.textContent = '';
                if (playHref) {
                    var link = document.createElement('a');
                    link.href = playHref;
                    link.className =
                        'trytest-quiz-start-link block w-full cursor-pointer rounded-md py-1 text-sm font-black tabular-nums tracking-tight text-emerald-950 no-underline outline-none ring-0 transition hover:bg-emerald-200/70 focus-visible:ring-2 focus-visible:ring-emerald-600 dark:text-emerald-200 dark:hover:bg-emerald-900/40 dark:focus-visible:ring-emerald-500';
                    link.setAttribute('aria-label', 'Start quiz');
                    link.textContent = playLabel;
                    el.appendChild(link);
                } else {
                    el.textContent = playLabel;
                }
                return;
            }
            el.textContent = '';
            el.className = countdownBase() + 'hidden';
        });
    }
    tick();
    setQuizResumeProgress();
    setInterval(tick, 1000);

    var pollUrl = <?php echo json_encode($quizSchedulesPollUrl ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    if (pollUrl) {
        function mergeSchedules(data) {
            if (!data || !data.ok || !data.quizzes) return;
            var map = {};
            data.quizzes.forEach(function (q) {
                var id = parseInt(q.quiz_id, 10);
                if (!id) return;
                map[id] = q;
            });
            document.querySelectorAll('.trytest-quiz-card').forEach(function (card) {
                var id = parseInt(card.getAttribute('data-quiz-id') || '0', 10);
                var row = map[id];
                if (!row) return;
                if (row.start != null) {
                    card.setAttribute('data-quiz-start', String(row.start));
                } else {
                    card.setAttribute('data-quiz-start', '');
                }
                if (row.end != null) {
                    card.setAttribute('data-quiz-end', String(row.end));
                } else {
                    card.setAttribute('data-quiz-end', '');
                }
            });
            tick();
        }
        function pollOnce() {
            fetch(pollUrl, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(mergeSchedules)
                .catch(function () {});
        }
        setTimeout(pollOnce, 4000);
        setInterval(pollOnce, 45000);
    }
})();
</script>
