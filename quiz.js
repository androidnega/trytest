(function () {
    const cfg = window.QUIZ_CONFIG || { quizId: 0, userId: 0, durationSeconds: 0 };
    const quizId = cfg.quizId;
    const userId = Number(cfg.userId || 0);
    let durationCapSeconds = Number(cfg.durationSeconds || 0);
    const quizAdEnabled = !!cfg.quizAdEnabled;
    const quizAdEvery = Math.max(1, Number(cfg.quizAdEvery || 20));
    const quizAdWatchSeconds = Math.max(5, Number(cfg.quizAdWatchSeconds || 20));
    const quizAdVideos = Array.isArray(cfg.quizAdVideos) ? cfg.quizAdVideos.map(String).filter(Boolean) : [];
    const priorAttempt = !!cfg.priorAttempt;
    const resetAttemptUrl = String(cfg.resetAttemptUrl || '');
    const quizIntroSeconds = Math.max(1, Math.min(60, Number(cfg.quizIntroSeconds || 5)));
    const showQuizIntro = !!cfg.showQuizIntro;
    const examWelcomeQuote = String(cfg.examWelcomeQuote || '');
    const examWelcomeImage = String(cfg.examWelcomeImage || '');
    const examOutroImage = String(cfg.examOutroImage || examWelcomeImage);
    const quizAuthorName = String(cfg.quizAuthorName || '');

    function trytestWebPrefix() {
        var b = typeof window.TRYTEST_WEB_BASE === 'string' ? window.TRYTEST_WEB_BASE : '';
        return b.replace(/\/+$/, '');
    }
    function absTrytestPath(path) {
        var b = trytestWebPrefix();
        path = String(path || '');
        if (path.charAt(0) === '?') {
            if (!b) {
                return '/' + path;
            }
            return b + path;
        }
        path = path.replace(/^\//, '');
        if (!b) {
            return '/' + path;
        }
        return path === '' ? b : b + '/' + path;
    }

    function resumeStorageKey() {
        return 'trytest_quiz_resume_v1_' + String(userId || '0') + '_' + String(quizId);
    }

    function clearQuizResumeStorage() {
        try {
            localStorage.removeItem(resumeStorageKey());
        } catch (e) {}
    }

    function parseResumePayload(raw) {
        if (!raw) return null;
        try {
            var o = JSON.parse(raw);
            if (!o || o.v !== 2 || !Array.isArray(o.orderedIds)) return null;
            if (!Array.isArray(o.adBreaksSeen)) o.adBreaksSeen = [];
            return o;
        } catch (e) {
            return null;
        }
    }

    function idsSameMultiset(a, b) {
        if (!Array.isArray(a) || !Array.isArray(b) || a.length !== b.length) return false;
        var sa = a
            .slice()
            .sort(function (x, y) {
                return x - y;
            })
            .join(',');
        var sb = b
            .slice()
            .sort(function (x, y) {
                return x - y;
            })
            .join(',');
        return sa === sb;
    }

    function applyResume(saved, serverIds) {
        if (!saved || !idsSameMultiset(saved.orderedIds, serverIds)) return false;
        if (saved.orderedIds.length !== serverIds.length) return false;
        if (Number(saved.durationSeconds) !== Number(durationCapSeconds)) return false;
        var idx = parseInt(String(saved.currentIndex), 10);
        if (isNaN(idx) || idx < 0 || idx >= saved.orderedIds.length) return false;
        orderedIds = saved.orderedIds.slice();
        currentIndex = idx;
        score = Math.max(0, parseInt(String(saved.score), 10) || 0);
        if (maxQuizMarks() > 0) {
            score = Math.min(score, maxQuizMarks());
        }
        var rem = parseInt(String(saved.remainingSeconds), 10);
        rem = isNaN(rem) ? durationCapSeconds : Math.max(0, rem);
        if (durationCapSeconds > 0) {
            rem = Math.min(rem, durationCapSeconds);
        }
        remainingSeconds = rem;
        adBreaksSeen = saved.adBreaksSeen
            .map(function (x) { return parseInt(String(x), 10); })
            .filter(function (x) { return !isNaN(x) && x > 0; });
        quizClockStarted = false;
        timerPaused = false;
        if (timerHandle) {
            clearInterval(timerHandle);
            timerHandle = null;
        }
        setScoreDisplay();
        if (totalValue) totalValue.textContent = String(maxQuizMarks());
        setFrozenTimerLabel();
        return true;
    }

    function saveQuizResume() {
        if (!quizId || !orderedIds.length) return;
        if (currentIndex >= orderedIds.length) return;
        try {
            localStorage.setItem(
                resumeStorageKey(),
                JSON.stringify({
                    v: 2,
                    orderedIds: orderedIds,
                    currentIndex: currentIndex,
                    score: score,
                    remainingSeconds: remainingSeconds,
                    durationSeconds: durationCapSeconds,
                    adBreaksSeen: adBreaksSeen,
                })
            );
        } catch (e) {}
    }

    const questionBox = document.getElementById('questionBox');
    const quizCard = document.getElementById('quizCard');
    const progressLabel = document.getElementById('progressLabel');
    const scoreValue = document.getElementById('scoreValue');
    const totalValue = document.getElementById('totalValue');
    const progressBar = document.getElementById('progressBar');
    const quizStatus = document.getElementById('quizStatus');
    const timerLabel = document.getElementById('timerLabel');

    /** @type {number[]} */
    let orderedIds = [];
    /** @type {Record<string, object>|null} Full quiz payload from first get_question response (avoids per-question HTTP). */
    let questionBank = null;
    let currentIndex = 0;
    let score = 0;
    let locked = false;
    let quizFinished = false;
    let remainingSeconds = durationCapSeconds;
    let timerHandle = null;
    let quizClockStarted = false;
    let timerPaused = false;
    /** @type {number[]} */
    let adBreaksSeen = [];
    /** @type {ReturnType<typeof setInterval> | null} */
    let durationSyncInterval = null;
    /** Logged when the learner submits an answer; flushed on Continue or at exam end (timer). */
    let pendingAttemptLog = null;
    /** @type {object[]} */
    let examReviewItems = [];

    /** Each quiz item is graded out of this many marks (MCQ, theory, SQL, etc.). */
    const MARKS_PER_QUESTION = 10;

    function maxQuizMarks() {
        return orderedIds.length * MARKS_PER_QUESTION;
    }

    function shuffleInPlace(arr) {
        for (let i = arr.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            const t = arr[i];
            arr[i] = arr[j];
            arr[j] = t;
        }
        return arr;
    }

    function apiUrl(params) {
        const q = new URLSearchParams(params);
        return absTrytestPath('get_question?' + q.toString());
    }

    function setProgress() {
        if (!progressLabel) return;
        if (orderedIds.length === 0) {
            progressLabel.textContent = '0 / 0';
            if (totalValue) totalValue.textContent = '0';
            if (progressBar) progressBar.style.width = '0%';
            return;
        }
        if (totalValue) totalValue.textContent = String(maxQuizMarks());
        if (currentIndex >= orderedIds.length) {
            progressLabel.textContent = orderedIds.length + ' / ' + orderedIds.length;
            if (progressBar) progressBar.style.width = '100%';
            return;
        }
        progressLabel.textContent = 'Question ' + (currentIndex + 1) + ' / ' + orderedIds.length;
        if (progressBar) {
            const done = currentIndex / orderedIds.length;
            progressBar.style.width = Math.max(0, Math.min(100, done * 100)) + '%';
        }
    }

    function setScoreDisplay() {
        if (scoreValue) {
            scoreValue.textContent = String(score);
        }
    }

    function setStatus(text, tone) {
        if (!quizStatus) return;
        quizStatus.textContent = text;
        quizStatus.className = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold';
        if (tone === 'error') {
            quizStatus.classList.add(
                'border',
                'border-red-200',
                'bg-red-50',
                'text-red-700',
                'dark:border-red-900/50',
                'dark:bg-red-950/50',
                'dark:text-red-300'
            );
            return;
        }
        if (tone === 'done') {
            quizStatus.classList.add(
                'border',
                'border-zinc-300',
                'bg-zinc-100',
                'text-zinc-700',
                'dark:border-zinc-600',
                'dark:bg-zinc-800',
                'dark:text-zinc-200'
            );
            return;
        }
        quizStatus.classList.add(
            'border',
            'border-zinc-300',
            'bg-zinc-100',
            'text-zinc-700',
            'dark:border-zinc-600',
            'dark:bg-zinc-800',
            'dark:text-zinc-200'
        );
    }

    function formatClock(seconds) {
        const safe = Math.max(0, seconds);
        const mins = Math.floor(safe / 60);
        const secs = safe % 60;
        return String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
    }

    function applyEffectiveDurationFromServer(raw) {
        var newCap = Math.max(0, Math.floor(Number(raw)));
        if (isNaN(newCap)) return;
        if (newCap === durationCapSeconds) return;
        var delta = newCap - durationCapSeconds;
        durationCapSeconds = newCap;
        if (durationCapSeconds <= 0) {
            remainingSeconds = 0;
            if (timerHandle) {
                clearInterval(timerHandle);
                timerHandle = null;
            }
            if (timerLabel) timerLabel.textContent = 'No limit';
            saveQuizResume();
            return;
        }
        remainingSeconds = Math.max(0, Math.min(durationCapSeconds, remainingSeconds + delta));
        if (timerLabel) timerLabel.textContent = formatClock(remainingSeconds);
        if (quizClockStarted && remainingSeconds <= 0) {
            if (timerHandle) {
                clearInterval(timerHandle);
                timerHandle = null;
            }
            endQuiz();
            return;
        }
        saveQuizResume();
    }

    function attachTimerSyncFromResponse(data) {
        if (data && typeof data.effective_duration_seconds !== 'undefined') {
            applyEffectiveDurationFromServer(data.effective_duration_seconds);
        }
    }

    function stopDurationSyncPolling() {
        if (durationSyncInterval) {
            clearInterval(durationSyncInterval);
            durationSyncInterval = null;
        }
    }

    function startDurationSyncPolling() {
        if (!quizId || durationSyncInterval) return;
        durationSyncInterval = setInterval(function () {
            if (quizFinished) {
                stopDurationSyncPolling();
                return;
            }
            var u =
                absTrytestPath('api_quiz_effective_duration.php?quiz_id=' + encodeURIComponent(String(quizId)));
            fetch(u, { method: 'GET', cache: 'no-store', credentials: 'same-origin' })
                .then(function (r) {
                    return r.json();
                })
                .then(function (d) {
                    if (d && d.ok && typeof d.effective_duration_seconds !== 'undefined') {
                        applyEffectiveDurationFromServer(d.effective_duration_seconds);
                    }
                })
                .catch(function () {});
        }, 25000);
    }

    function startTimer() {
        if (!timerLabel) return;
        if (remainingSeconds <= 0) {
            timerLabel.textContent = 'No limit';
            return;
        }
        timerPaused = false;
        timerLabel.textContent = formatClock(remainingSeconds);
        if (timerHandle) clearInterval(timerHandle);
        timerHandle = setInterval(function () {
            remainingSeconds--;
            timerLabel.textContent = formatClock(remainingSeconds);
            if (remainingSeconds <= 0) {
                clearInterval(timerHandle);
                timerHandle = null;
                endQuiz();
                return;
            }
            saveQuizResume();
        }, 1000);
    }

    /** Countdown begins when the first question is on screen (not during loading). */
    function maybeStartQuizTimer() {
        if (quizClockStarted) return;
        quizClockStarted = true;
        if (remainingSeconds <= 0) {
            if (timerLabel) timerLabel.textContent = 'No limit';
            return;
        }
        startTimer();
    }

    function setFrozenTimerLabel() {
        if (!timerLabel) return;
        if (durationCapSeconds <= 0) {
            timerLabel.textContent = 'No limit';
            return;
        }
        timerLabel.textContent = formatClock(remainingSeconds);
    }

    function pauseTimer() {
        if (!quizClockStarted) return;
        if (remainingSeconds <= 0) return;
        if (timerHandle) {
            clearInterval(timerHandle);
            timerHandle = null;
        }
        timerPaused = true;
    }

    function resumeTimer() {
        if (!quizClockStarted) return;
        if (!timerPaused) return;
        if (remainingSeconds <= 0) return;
        startTimer();
    }

    let quizAudioCtx = null;
    function getQuizAudioContext() {
        if (quizAudioCtx) {
            return quizAudioCtx;
        }
        try {
            quizAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
        } catch (e) {
            return null;
        }
        return quizAudioCtx;
    }
    /** Short cheerful arpeggio — Web Audio only, no asset files. */
    function playQuizCorrectSound() {
        var ctx = getQuizAudioContext();
        if (!ctx) {
            return;
        }
        try {
            if (ctx.state === 'suspended') {
                ctx.resume();
            }
        } catch (e) {}
        function tone(freq, startMs, durMs, vol) {
            var t0 = ctx.currentTime + startMs / 1000;
            var osc = ctx.createOscillator();
            var g = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(freq, t0);
            g.gain.setValueAtTime(0, t0);
            g.gain.linearRampToValueAtTime(vol, t0 + 0.025);
            g.gain.exponentialRampToValueAtTime(0.001, t0 + durMs / 1000);
            osc.connect(g);
            g.connect(ctx.destination);
            osc.start(t0);
            osc.stop(t0 + durMs / 1000 + 0.03);
        }
        tone(523.25, 0, 95, 0.11);
        tone(659.25, 78, 100, 0.1);
        tone(783.99, 158, 120, 0.09);
        tone(1046.5, 248, 200, 0.065);
    }
    /** Soft low “wrong” cue — not harsh. */
    function playQuizWrongSound() {
        var ctx = getQuizAudioContext();
        if (!ctx) {
            return;
        }
        try {
            if (ctx.state === 'suspended') {
                ctx.resume();
            }
        } catch (e) {}
        function tone(freq, startMs, durMs, type, vol) {
            var t0 = ctx.currentTime + startMs / 1000;
            var osc = ctx.createOscillator();
            var g = ctx.createGain();
            osc.type = type || 'triangle';
            osc.frequency.setValueAtTime(freq, t0);
            g.gain.setValueAtTime(0, t0);
            g.gain.linearRampToValueAtTime(vol, t0 + 0.03);
            g.gain.exponentialRampToValueAtTime(0.001, t0 + durMs / 1000);
            osc.connect(g);
            g.connect(ctx.destination);
            osc.start(t0);
            osc.stop(t0 + durMs / 1000 + 0.03);
        }
        tone(168, 0, 130, 'triangle', 0.1);
        tone(142, 95, 160, 'sawtooth', 0.075);
    }

    function triggerCardWrongFeedback() {
        playQuizWrongSound();
        if (!quizCard) return;
        quizCard.classList.remove('quiz-card--wrong');
        void quizCard.offsetWidth;
        quizCard.classList.add('quiz-card--wrong');
        setTimeout(function () {
            quizCard.classList.remove('quiz-card--wrong');
        }, 800);
    }

    function triggerCardCorrectFeedback() {
        playQuizCorrectSound();
        if (!quizCard) return;
        spawnCelebrationShimmer();
        spawnCelebrationSparkles();
        spawnCelebrationEmojis();
        quizCard.classList.remove('quiz-card--correct');
        void quizCard.offsetWidth;
        quizCard.classList.add('quiz-card--correct');
        setTimeout(function () {
            quizCard.classList.remove('quiz-card--correct');
        }, 1000);
    }

    function ensureFxLayer() {
        if (!quizCard) return null;
        var layer = quizCard.querySelector('.quiz-card-emoji-layer');
        if (!layer) {
            layer = document.createElement('div');
            layer.className = 'quiz-card-emoji-layer';
            layer.setAttribute('aria-hidden', 'true');
            quizCard.appendChild(layer);
        }
        return layer;
    }

    function spawnCelebrationShimmer() {
        if (!quizCard) return;
        var sh = document.createElement('div');
        sh.className = 'quiz-card-shimmer';
        sh.setAttribute('aria-hidden', 'true');
        quizCard.appendChild(sh);
        setTimeout(function () {
            if (sh.parentNode) sh.parentNode.removeChild(sh);
        }, 900);
    }

    function spawnCelebrationSparkles() {
        var layer = ensureFxLayer();
        if (!layer) return;
        var i;
        for (i = 0; i < 26; i++) {
            var el = document.createElement('span');
            el.className = 'quiz-fly-sparkle' + (Math.random() > 0.62 ? ' quiz-fly-sparkle--diamond' : '');
            el.style.left = 10 + Math.random() * 80 + '%';
            el.style.top = 12 + Math.random() * 55 + '%';
            var ang = Math.random() * Math.PI * 2;
            var dist = 48 + Math.random() * 110;
            el.style.setProperty('--sx', Math.round(Math.cos(ang) * dist) + 'px');
            el.style.setProperty('--sy', Math.round(Math.sin(ang) * dist - 18) + 'px');
            el.style.animationDelay = i * 0.022 + 's';
            layer.appendChild(el);
            (function (node) {
                setTimeout(function () {
                    if (node.parentNode) node.parentNode.removeChild(node);
                }, 1300);
            })(el);
        }
        var glyphs = ['✨', '💫', '⭐', '✨', '🌟', '✦'];
        for (i = 0; i < 10; i++) {
            var g = document.createElement('span');
            g.className = 'quiz-fly-emoji quiz-fly-emoji--sparkle';
            g.textContent = glyphs[i % glyphs.length];
            g.style.left = 18 + Math.random() * 64 + '%';
            g.style.top = 18 + Math.random() * 48 + '%';
            ang = Math.random() * Math.PI * 2;
            dist = 60 + Math.random() * 90;
            g.style.setProperty('--dx', Math.round(Math.cos(ang) * dist) + 'px');
            g.style.setProperty('--dy', (Math.round(Math.sin(ang) * dist) - 22) + 'px');
            g.style.setProperty('--rot', Math.round(80 + Math.random() * 200) + 'deg');
            g.style.animationDelay = i * 0.035 + 's';
            layer.appendChild(g);
            (function (node) {
                setTimeout(function () {
                    if (node.parentNode) node.parentNode.removeChild(node);
                }, 1500);
            })(g);
        }
    }

    function spawnCelebrationEmojis() {
        var layer = ensureFxLayer();
        if (!layer) return;
        var faces = ['😀', '😊', '🙂', '😄', '🌟'];
        var n = 12;
        for (var i = 0; i < n; i++) {
            var el = document.createElement('span');
            el.className = 'quiz-fly-emoji';
            el.textContent = faces[i % faces.length];
            var lx = 12 + Math.random() * 76;
            var ly = 15 + Math.random() * 45;
            el.style.left = lx + '%';
            el.style.top = ly + '%';
            var angle = Math.random() * Math.PI * 2;
            var dist = 55 + Math.random() * 95;
            var dx = Math.round(Math.cos(angle) * dist);
            var dy = Math.round(Math.sin(angle) * dist) - 25;
            var rot = Math.round((Math.random() - 0.5) * 40);
            el.style.setProperty('--dx', dx + 'px');
            el.style.setProperty('--dy', dy + 'px');
            el.style.setProperty('--rot', rot + 'deg');
            el.style.animationDelay = i * 0.04 + 's';
            layer.appendChild(el);
            (function (node) {
                setTimeout(function () {
                    if (node.parentNode) node.parentNode.removeChild(node);
                }, 1600);
            })(el);
        }
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escapeAttr(s) {
        return escapeHtml(s).replace(/'/g, '&#39;');
    }

    let quizIntroIntervalId = null;
    let quizIntroFinished = false;

    function clearQuizIntroInterval() {
        if (quizIntroIntervalId !== null) {
            clearInterval(quizIntroIntervalId);
            quizIntroIntervalId = null;
        }
    }

    function hideQuizIntroOverlay() {
        var ov = document.getElementById('quizIntroOverlay');
        if (ov) {
            ov.style.display = 'none';
            ov.setAttribute('aria-hidden', 'true');
        }
        if (document.body) {
            document.body.classList.remove('overflow-hidden');
        }
    }

    function beginQuizFromIntro() {
        if (quizIntroFinished) {
            return;
        }
        var contBtn = document.getElementById('quizIntroContinue');
        if (contBtn && contBtn.disabled) {
            return;
        }
        quizIntroFinished = true;
        clearQuizIntroInterval();
        hideQuizIntroOverlay();
        start();
    }

    function renderQuizIntro() {
        var mount = document.getElementById('quizIntroMount');
        if (!mount) {
            hideQuizIntroOverlay();
            start();
            return;
        }
        if (document.body) {
            document.body.classList.add('overflow-hidden');
        }
        var ov = document.getElementById('quizIntroOverlay');
        if (ov) {
            ov.style.display = '';
            ov.removeAttribute('aria-hidden');
        }
        var img = examWelcomeImage;
        var quote =
            examWelcomeQuote.trim() !== '' ? examWelcomeQuote : 'Believe in yourself.\nYou are ready.';
        var author = quizAuthorName.trim();
        var quoteLines = String(quote)
            .split(/\n/)
            .map(function (s) {
                return s.trim();
            })
            .filter(Boolean);
        var quoteHtml =
            quoteLines.length > 0
                ? quoteLines
                      .map(function (line) {
                          return escapeHtml(line);
                      })
                      .join('<br />')
                : escapeHtml('Believe in yourself.') + '<br />' + escapeHtml('You are ready.');
        mount.innerHTML =
            '<div class="mx-auto w-full max-w-md rounded-2xl border border-slate-300 bg-white p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-900">' +
            '<div class="flex flex-row items-center gap-3 sm:gap-4">' +
            '<img src="' +
            escapeAttr(img) +
            '" alt="" class="trytest-quiz-intro-thumb shrink-0 rounded-xl border border-slate-200 bg-slate-100 dark:border-zinc-600 dark:bg-zinc-800" width="128" height="128" loading="eager" />' +
            '<div class="min-w-0 flex-1 text-left">' +
            '<p id="quizIntroMsg" class="text-sm font-semibold leading-snug text-slate-800 sm:text-base dark:text-zinc-100">' +
            quoteHtml +
            '</p>' +
            (author !== ''
                ? '<p class="mt-2 border-t border-zinc-100 pt-2 text-xs font-medium tracking-wide text-zinc-600 sm:text-sm dark:border-zinc-700 dark:text-zinc-400">' +
                  escapeHtml(author) +
                  '</p>'
                : '') +
            '</div></div></div>' +
            '<div class="mx-auto mt-6 w-full max-w-md text-center">' +
            '<p id="quizIntroWait" class="mb-3 min-h-[1.25rem] text-xs font-medium tabular-nums text-slate-500 sm:text-sm dark:text-zinc-400"></p>' +
            '<button type="button" id="quizIntroContinue" disabled class="w-full cursor-not-allowed rounded-2xl border border-slate-300 bg-slate-100 py-3 text-sm font-bold text-slate-400 sm:py-3.5 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-500">' +
            'Continue' +
            '</button></div>';

        var waitEl = document.getElementById('quizIntroWait');
        var contBtn = document.getElementById('quizIntroContinue');
        var remain = quizIntroSeconds;

        function refreshWait() {
            if (waitEl) {
                waitEl.textContent = remain > 0 ? 'You can continue in ' + remain + 's' : '';
            }
        }
        refreshWait();

        quizIntroIntervalId = setInterval(function () {
            remain -= 1;
            if (remain <= 0) {
                clearQuizIntroInterval();
                refreshWait();
                if (contBtn) {
                    contBtn.disabled = false;
                    contBtn.className =
                        'w-full cursor-pointer rounded-xl border border-zinc-800 bg-zinc-900 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800 active:scale-[0.99] sm:py-3.5 dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white';
                }
                return;
            }
            refreshWait();
        }, 1000);

        if (contBtn) {
            contBtn.addEventListener('click', function () {
                if (quizIntroFinished || contBtn.disabled) {
                    return;
                }
                beginQuizFromIntro();
            });
        }
    }

    function renderLoading() {
        setStatus('Loading', 'ok');
        questionBox.innerHTML =
            '<div class="animate-pulse space-y-4 text-left">' +
            '<div class="h-6 w-3/4 rounded-lg bg-slate-200 dark:bg-zinc-700"></div>' +
            '<div class="h-12 rounded-2xl bg-slate-100 dark:bg-zinc-800"></div>' +
            '<div class="h-12 rounded-2xl bg-slate-100 dark:bg-zinc-800"></div>' +
            '<div class="h-12 rounded-2xl bg-slate-100 dark:bg-zinc-800"></div>' +
            '</div>';
    }

    function renderFetchError(message, onRetry) {
        questionBox.innerHTML =
            '<p class="mb-4 text-left text-slate-600 dark:text-zinc-400">' + escapeHtml(message) + '</p>' +
            '<button type="button" id="retryBtn" class="w-full rounded-xl border border-zinc-800 bg-zinc-900 p-4 text-base font-semibold text-white shadow-sm hover:bg-zinc-800 active:scale-[0.99] dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white">' +
            'Retry</button>';
        const btn = document.getElementById('retryBtn');
        if (btn) btn.addEventListener('click', onRetry);
    }

    function fetchJson(url) {
        return fetch(url, { method: 'GET', cache: 'no-store', credentials: 'same-origin' }).then(function (res) {
            return res.json().then(function (body) {
                if (!res.ok) {
                    const err = (body && body.error) || 'request_failed';
                    throw new Error(err);
                }
                return body;
            });
        });
    }

    /**
     * @param {string} rawUrl
     * @param {boolean} [forQuizAd] Muted autoplay + enablejsapi for in-quiz ad breaks (tap to unmute).
     */
    function youtubeEmbedUrl(rawUrl, forQuizAd) {
        var u = String(rawUrl || '').trim();
        if (!u) return '';
        try {
            var parsed = new URL(u);
            var host = (parsed.hostname || '').toLowerCase();
            var id = '';
            if (host.indexOf('youtu.be') !== -1) {
                id = parsed.pathname.replace(/^\/+/, '').split('/')[0] || '';
            } else {
                id = parsed.searchParams.get('v') || '';
                if (!id) {
                    var m = parsed.pathname.match(/\/(embed|shorts)\/([A-Za-z0-9_-]{6,20})/);
                    if (m && m[2]) id = m[2];
                }
            }
            if (!/^[A-Za-z0-9_-]{6,20}$/.test(id)) return '';
            var qs = 'rel=0&autoplay=1&playsinline=1';
            if (forQuizAd) {
                qs += '&mute=1&enablejsapi=1';
                try {
                    if (typeof window !== 'undefined' && window.location && window.location.origin) {
                        qs += '&origin=' + encodeURIComponent(window.location.origin);
                    }
                } catch (e2) {}
            }
            return 'https://www.youtube.com/embed/' + encodeURIComponent(id) + '?' + qs;
        } catch (e) {
            return '';
        }
    }

    function sendYoutubeIframeRaw(iframe, obj) {
        if (!iframe || !iframe.contentWindow) return;
        var s = JSON.stringify(obj);
        var origins = ['https://www.youtube.com', 'https://www.youtube-nocookie.com', '*'];
        for (var oi = 0; oi < origins.length; oi++) {
            try {
                iframe.contentWindow.postMessage(s, origins[oi]);
            } catch (e) {}
        }
    }

    /** Tell the embed we control it (needed for mute/unMute to work reliably). */
    function sendYoutubePlayerListening(iframe) {
        sendYoutubeIframeRaw(iframe, { event: 'listening' });
    }

    /**
     * YouTube embed commands: zero-arg funcs use args "" (not []) per embed quirks.
     * @param {string} func
     * @param {unknown} [args]
     */
    function sendYoutubeIframeCommand(iframe, func, args) {
        var hasArgs =
            args !== undefined &&
            args !== null &&
            !(Array.isArray(args) && args.length === 0) &&
            args !== '';
        if (!hasArgs) {
            sendYoutubeIframeRaw(iframe, { event: 'command', func: func, args: '' });
        } else {
            sendYoutubeIframeRaw(iframe, { event: 'command', func: func, args: args });
        }
    }

    function applyQuizAdIframeSound(iframe, unmuted) {
        if (!iframe) return;
        if (unmuted) {
            sendYoutubeIframeCommand(iframe, 'unMute');
            sendYoutubeIframeCommand(iframe, 'setVolume', [100]);
        } else {
            sendYoutubeIframeCommand(iframe, 'mute');
            sendYoutubeIframeCommand(iframe, 'setVolume', [0]);
        }
    }

    function hasSeenAdBreak(index) {
        return adBreaksSeen.indexOf(index) !== -1;
    }

    function markAdBreakSeen(index) {
        if (index < 1 || hasSeenAdBreak(index)) return;
        adBreaksSeen.push(index);
        saveQuizResume();
    }

    function shouldShowAdBreak() {
        if (!quizAdEnabled || quizAdVideos.length === 0) return false;
        if (currentIndex < 1 || currentIndex >= orderedIds.length) return false;
        if (currentIndex % quizAdEvery !== 0) return false;
        var breakIndex = Math.floor(currentIndex / quizAdEvery);
        return !hasSeenAdBreak(breakIndex);
    }

    function renderAdInterstitial(done) {
        var breakIndex = Math.floor(currentIndex / quizAdEvery);
        var chosen =
            quizAdVideos.length > 0
                ? quizAdVideos[Math.floor(Math.random() * quizAdVideos.length)]
                : '';
        var embed = youtubeEmbedUrl(chosen, true);
        if (!embed) {
            markAdBreakSeen(breakIndex);
            done();
            return;
        }
        var wait = Math.max(1, Math.floor(quizAdWatchSeconds));
        pauseTimer();
        setStatus('Watch required', 'ok');
        questionBox.innerHTML =
            '<div class="touch-manipulation space-y-3">' +
            '<p class="text-[11px] font-semibold uppercase tracking-wide text-red-600 dark:text-red-400">Video break</p>' +
            '<h2 class="text-lg font-bold text-slate-900 dark:text-zinc-100">Watch this video to continue</h2>' +
            '<p class="text-sm text-slate-600 dark:text-zinc-400">You reached question ' +
            currentIndex +
            '. Continue unlocks in <span id="adCountdown" class="font-bold text-slate-900 dark:text-zinc-100">' +
            wait +
            's</span>.</p>' +
            '<div class="overflow-hidden rounded-2xl border border-slate-200 bg-black dark:border-zinc-700">' +
            '<div class="aspect-video w-full"><iframe id="quizAdIframe" class="h-full w-full" src="' +
            escapeAttr(embed) +
            '" title="Quiz ad video" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div></div>' +
            '<button type="button" id="quizAdUnmuteBtn" aria-pressed="false" class="w-full cursor-pointer rounded-xl border border-slate-500 bg-slate-800 px-3 py-2.5 text-sm font-semibold text-white hover:bg-slate-700 dark:border-zinc-500 dark:bg-zinc-800 dark:hover:bg-zinc-700">Tap for sound</button>' +
            '<button type="button" id="adContinueBtn" disabled class="w-full rounded-2xl bg-slate-300 p-3 text-sm font-bold text-white dark:bg-zinc-700">Continue in ' +
            wait +
            's</button>' +
            '</div>';
        var countdownEl = document.getElementById('adCountdown');
        var adIframe = document.getElementById('quizAdIframe');
        var unmuteBtn = document.getElementById('quizAdUnmuteBtn');
        if (adIframe) {
            adIframe.addEventListener(
                'load',
                function () {
                    sendYoutubePlayerListening(adIframe);
                },
                { once: true }
            );
        }
        if (unmuteBtn && adIframe) {
            var adMuted = true;
            var clsMuted =
                'w-full cursor-pointer rounded-xl border border-slate-500 bg-slate-800 px-3 py-2.5 text-sm font-semibold text-white hover:bg-slate-700 dark:border-zinc-500 dark:bg-zinc-800 dark:hover:bg-zinc-700';
            var clsUnmuted =
                'w-full cursor-pointer rounded-xl border border-zinc-400 bg-zinc-100 px-3 py-2.5 text-sm font-semibold text-zinc-900 hover:bg-zinc-200 dark:border-zinc-500 dark:bg-zinc-700 dark:text-zinc-100 dark:hover:bg-zinc-600';
            function syncAdSoundButtonUi() {
                if (!unmuteBtn) return;
                if (!adMuted) {
                    unmuteBtn.textContent = 'Tap to mute';
                    unmuteBtn.setAttribute('aria-pressed', 'true');
                    unmuteBtn.className = clsUnmuted;
                } else {
                    unmuteBtn.textContent = 'Tap for sound';
                    unmuteBtn.setAttribute('aria-pressed', 'false');
                    unmuteBtn.className = clsMuted;
                }
            }
            function toggleAdSound() {
                adMuted = !adMuted;
                applyQuizAdIframeSound(adIframe, !adMuted);
                syncAdSoundButtonUi();
            }
            unmuteBtn.addEventListener('click', function () {
                toggleAdSound();
            });
        }
        var btn = document.getElementById('adContinueBtn');
        var t = wait;
        var unlockTimer = setInterval(function () {
            t--;
            if (countdownEl) countdownEl.textContent = String(Math.max(0, t)) + 's';
            if (!btn) return;
            if (t > 0) {
                btn.textContent = 'Continue in ' + t + 's';
                return;
            }
            clearInterval(unlockTimer);
            btn.disabled = false;
            btn.className =
                'w-full rounded-xl border border-zinc-800 bg-zinc-900 p-3 text-sm font-semibold text-white shadow-sm hover:bg-zinc-800 dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white';
            btn.textContent = 'Continue quiz';
        }, 1000);
        if (btn) {
            btn.addEventListener('click', function () {
                if (btn.disabled) return;
                clearInterval(unlockTimer);
                markAdBreakSeen(breakIndex);
                resumeTimer();
                done();
            });
        }
    }

    function resetPriorAttemptIfNeeded() {
        if (!priorAttempt || !resetAttemptUrl || !quizId) {
            return Promise.resolve();
        }
        return fetch(resetAttemptUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ quiz_id: quizId }),
        }).then(function () {
            // Retake reset is best-effort; quiz can still continue if it fails.
        }).catch(function () {});
    }

    function loadQuestionIds() {
        return fetchJson(apiUrl({ quiz_id: String(quizId) })).then(function (data) {
            if (!data.ok || !Array.isArray(data.ids)) {
                throw new Error('bad_response');
            }
            return data;
        });
    }

    function loadQuestionById(id) {
        return fetchJson(apiUrl({ quiz_id: String(quizId), id: String(id) })).then(function (data) {
            attachTimerSyncFromResponse(data);
            if (!data.ok || !data.question) {
                throw new Error('bad_response');
            }
            return data.question;
        });
    }

    function cloneBankQuestion(q) {
        try {
            return JSON.parse(JSON.stringify(q));
        } catch (e) {
            return q;
        }
    }

    /** Prefer in-memory bank from bulk boot; fall back to GET for one question (legacy / recovery). */
    function resolveQuestion(id) {
        var n = parseInt(String(id), 10);
        if (!questionBank || isNaN(n) || n < 1) {
            return loadQuestionById(n);
        }
        var q = questionBank[n];
        if (!q && questionBank[String(n)]) {
            q = questionBank[String(n)];
        }
        if (q) {
            return Promise.resolve(cloneBankQuestion(q));
        }
        return loadQuestionById(n);
    }

    function formatCorrectAnswerForReview(q) {
        const play = String(q.play_type || detectPlayType(q)).toLowerCase();
        if (play === 'sql') {
            return 'Graded by comparing your result set to the instructor query on the same practice data.';
        }
        if (play === 'mcq') {
            return String(resolveLetterMcqCorrect(q.correct_answer, q));
        }
        if (play === 'fill') {
            const stem = String(q.question || '');
            const blanks = blankCountInStem(stem);
            const raw = String(q.correct_answer || '');
            if (blanks > 1 && raw.indexOf('|') !== -1) {
                const segs = raw.split('|');
                return segs
                    .map(function (s, i) {
                        return 'Blank ' + (i + 1) + ': ' + String(s).trim();
                    })
                    .join(' · ');
            }
            return raw;
        }
        return String(q.correct_answer || '');
    }

    function flushPendingQuestionAttempt() {
        if (!pendingAttemptLog || !orderedIds.length) {
            return;
        }
        if (currentIndex < 0 || currentIndex >= orderedIds.length) {
            return;
        }
        if (pendingAttemptLog.questionId !== orderedIds[currentIndex]) {
            return;
        }
        examReviewItems.push(pendingAttemptLog);
        pendingAttemptLog = null;
    }

    function detectPlayType(q) {
        if (String(q.question_type || '').toLowerCase() === 'sql') {
            return 'sql';
        }
        const stem = String(q.question || '');
        if (stem.indexOf('____') !== -1) {
            return 'fill';
        }
        const keys = ['option_a', 'option_b', 'option_c', 'option_d'];
        for (let i = 0; i < keys.length; i++) {
            const t = q[keys[i]];
            if (t != null && String(t).trim() !== '') {
                return 'mcq';
            }
        }
        return 'theory';
    }

    function blankCountInStem(stem) {
        const m = String(stem || '').match(/____/g);
        return m ? m.length : 0;
    }

    function renderMcqOptions(q) {
        const keys = ['option_a', 'option_b', 'option_c', 'option_d'];
        const entries = [];
        keys.forEach(function (k) {
            const text = q[k];
            if (text != null && String(text).trim() !== '') {
                entries.push(String(text));
            }
        });
        shuffleInPlace(entries);
        const parts = [];
        entries.forEach(function (text) {
            const safe = escapeHtml(String(text));
            parts.push(
                '<button type="button" class="option flex min-h-[52px] w-full items-center rounded-2xl border border-zinc-200 bg-white p-3.5 text-left text-[15px] font-medium text-zinc-800 shadow-sm transition-all duration-200 hover:bg-zinc-50 active:scale-[0.99] disabled:opacity-50 sm:min-h-0 sm:rounded-xl sm:p-4 sm:text-base dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700" data-option="' +
                    escapeAttr(String(text)) +
                    '">' +
                    safe +
                    '</button>'
            );
        });
        return '<div class="space-y-3" id="optionsWrap">' + parts.join('') + '</div>';
    }

    function bindMcqHandlers(q) {
        const wrap = document.getElementById('optionsWrap');
        if (wrap) {
            wrap.addEventListener('click', function onOptionClick(e) {
                const btn = e.target.closest('.option');
                if (!btn || locked || btn.disabled) return;
                const selected = btn.getAttribute('data-option') || '';
                checkMcq(btn, selected, q.correct_answer, q);
            });
        }
    }

    function collectFreeResponseInputs() {
        const theory = document.getElementById('theoryInput');
        if (theory) {
            return [theory];
        }
        return Array.prototype.slice.call(document.querySelectorAll('.fill-blank-input'));
    }

    function bindFreeResponseHandlers(q) {
        const submit = document.getElementById('frSubmit');
        const inputs = collectFreeResponseInputs();
        if (inputs[0]) {
            inputs[0].focus();
        }

        function onFreeSubmit() {
            if (locked) return;
            if (!submit) return;
            checkFreeResponse(q, submit);
        }

        if (submit) {
            submit.addEventListener('click', onFreeSubmit);
        }
        inputs.forEach(function (inp) {
            inp.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' || e.shiftKey) return;
                const isMultiline = inp.tagName === 'TEXTAREA';
                if (isMultiline) return;
                e.preventDefault();
                onFreeSubmit();
            });
        });
    }

    function renderFillQuestion(q) {
        const raw = String(q.question || '');
        const parts = raw.split('____');
        const inputClass =
            'fill-blank-input mx-0.5 my-1 inline-block min-h-[44px] min-w-[6rem] max-w-full flex-1 rounded-xl border border-zinc-200 bg-white px-2 py-2 text-center text-base text-zinc-900 shadow-sm focus:border-zinc-400 focus:outline-none focus:ring-2 focus:ring-zinc-400/30 sm:max-w-[16rem] dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100';
        let inner =
            '<div class="mb-5 text-left text-[15px] font-medium leading-relaxed text-slate-900 sm:mb-6 sm:text-base md:text-lg dark:text-zinc-100">';
        if (parts.length < 2) {
            inner += escapeHtml(raw);
            inner +=
                ' <input type="text" autocomplete="off" aria-label="Answer" class="' +
                inputClass +
                '" placeholder="…" />';
        } else {
            for (let i = 0; i < parts.length; i++) {
                inner += '<span class="align-baseline">' + escapeHtml(parts[i]) + '</span>';
                if (i < parts.length - 1) {
                    inner +=
                        '<input type="text" inputmode="text" autocomplete="off" aria-label="Blank ' +
                        (i + 1) +
                        '" class="' +
                        inputClass +
                        '" placeholder="…" />';
                }
            }
        }
        inner += '</div>';
        questionBox.innerHTML =
            inner +
            '<button type="button" id="frSubmit" class="mt-4 flex min-h-[52px] w-full touch-manipulation items-center justify-center rounded-2xl border border-zinc-800 bg-zinc-900 px-4 py-3.5 text-[15px] font-semibold text-white shadow-sm transition-all duration-200 hover:bg-zinc-800 active:scale-[0.99] dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white">Check answer</button>';
        bindFreeResponseHandlers(q);
    }

    var sqlCmLoadingPromise = null;
    function ensureSqlCodeMirror() {
        if (typeof CodeMirror !== 'undefined') {
            return Promise.resolve();
        }
        if (sqlCmLoadingPromise) {
            return sqlCmLoadingPromise;
        }
        sqlCmLoadingPromise = new Promise(function (resolve, reject) {
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css';
            document.head.appendChild(link);
            var s1 = document.createElement('script');
            s1.src = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js';
            s1.onload = function () {
                var s2 = document.createElement('script');
                s2.src = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/sql/sql.min.js';
                s2.onload = function () {
                    resolve();
                };
                s2.onerror = reject;
                document.head.appendChild(s2);
            };
            s1.onerror = reject;
            document.head.appendChild(s1);
        });
        return sqlCmLoadingPromise;
    }

    function renderSqlQuestion(q) {
        const prompt = escapeHtml(String(q.question || ''));
        questionBox.innerHTML =
            '<h2 class="mb-3 text-left text-base font-bold leading-snug text-zinc-900 sm:text-lg dark:text-zinc-100">' +
            prompt +
            '</h2>' +
            '<div id="sqlCmMount" class="mb-3 overflow-hidden rounded-2xl border border-zinc-200 bg-white text-sm shadow-inner dark:border-zinc-600 dark:bg-zinc-900">' +
            '<textarea id="sqlStudentTa" rows="12" spellcheck="false" autocomplete="off" class="w-full resize-y px-3 py-3 font-mono text-[15px] leading-relaxed sm:text-[13px]">' +
            '-- Practice query\nSELECT ' +
            '</textarea></div>' +
            '<button type="button" id="sqlRunBtn" class="flex min-h-[52px] w-full touch-manipulation items-center justify-center rounded-2xl border border-zinc-800 bg-zinc-900 px-4 py-3.5 text-[15px] font-semibold text-white shadow-sm active:bg-zinc-950 dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900 dark:active:bg-white">Run SQL check</button>' +
            '<div id="sqlFeedback" class="mt-4 hidden rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-left text-[15px] leading-relaxed text-zinc-800 dark:border-zinc-600 dark:bg-zinc-800/60 dark:text-zinc-200"></div>';

        const ta = document.getElementById('sqlStudentTa');
        const runBtn = document.getElementById('sqlRunBtn');
        const fb = document.getElementById('sqlFeedback');

        function bindEditor(cmInstance) {
            if (runBtn) {
                runBtn.addEventListener('click', function () {
                    runSqlCheck(q, cmInstance, fb);
                });
            }
        }

        ensureSqlCodeMirror()
            .then(function () {
                if (!ta || typeof CodeMirror === 'undefined') {
                    return;
                }
                var cmCompact =
                    typeof window.matchMedia === 'function' &&
                    window.matchMedia('(max-width: 639px)').matches;
                const cm = CodeMirror.fromTextArea(ta, {
                    mode: 'text/x-sql',
                    lineNumbers: !cmCompact,
                    indentUnit: 2,
                    lineWrapping: true,
                    theme: 'default',
                    tabSize: 2,
                });
                try {
                    var winH = typeof window.innerHeight === 'number' ? window.innerHeight : 600;
                    var hPx = cmCompact
                        ? Math.round(Math.min(winH * 0.36, 340))
                        : Math.round(Math.min(winH * 0.34, 400));
                    cm.setSize('100%', hPx + 'px');
                } catch (e1) {}
                bindEditor(cm);
            })
            .catch(function () {
                if (ta) {
                    ta.className =
                        'min-h-[13rem] w-full resize-y rounded-2xl border border-zinc-200 bg-white px-3 py-3 font-mono text-[15px] text-zinc-900 shadow-inner dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100 sm:min-h-[240px] sm:text-sm';
                    bindEditor({
                        getValue: function () {
                            return ta.value;
                        },
                    });
                }
            });
    }

    function normalizeSqlPracticeInput(sql) {
        if (typeof sql !== 'string') {
            return '';
        }
        var lines = sql.split(/\r?\n/).filter(function (line) {
            return !/^\s*\d+\s*$/.test(line);
        });
        sql = lines.join('\n').trim();
        var patterns = [
            /\bINSERT\s+OR\s+REPLACE\s+INTO\b/i,
            /\bINSERT\s+INTO\b/i,
            /\bREPLACE\s+INTO\b/i,
        ];
        var found = null;
        for (var pi = 0; pi < patterns.length; pi++) {
            found = patterns[pi].exec(sql);
            if (found) {
                break;
            }
        }
        if (found) {
            sql = sql.slice(found.index);
            var semi = sql.indexOf(';');
            if (semi !== -1) {
                sql = sql.slice(0, semi + 1);
            }
            return sql.trim();
        }
        return sql;
    }

    function runSqlCheck(q, cm, fbEl) {
        if (locked) {
            return;
        }
        locked = true;
        if (fbEl) {
            fbEl.classList.add('hidden');
            fbEl.innerHTML = '';
        }
        const rawSql = cm && typeof cm.getValue === 'function' ? cm.getValue() : '';
        const sqlText = normalizeSqlPracticeInput(rawSql);
        runBtnBusy(true);
        fetch(absTrytestPath('sql_practice_grade'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                quiz_id: quizId,
                question_id: orderedIds[currentIndex],
                sql: sqlText,
            }),
        })
            .then(function (res) {
                return res.json().then(function (body) {
                    var o = body && typeof body === 'object' ? body : {};
                    if (!res.ok) {
                        o.ok = false;
                        if (typeof o.error === 'undefined') {
                            o.error = res.status >= 500 ? 'Server error (' + res.status + ').' : 'Request failed (' + res.status + ').';
                        }
                        return o;
                    }
                    return o;
                }).catch(function () {
                    return { ok: false, error: 'Could not read response.' };
                });
            })
            .then(function (data) {
                runBtnBusy(false);
                if (!data || !data.ok) {
                    locked = false;
                    if (fbEl) {
                        fbEl.classList.remove('hidden');
                        var detail =
                            data && data.error
                                ? '<p class="mt-2 text-xs leading-snug text-red-700 dark:text-red-300">' +
                                  escapeHtml(String(data.error)) +
                                  '</p>'
                                : '';
                        fbEl.innerHTML =
                            '<p class="font-semibold text-red-800 dark:text-red-200">Could not grade right now.</p>' +
                            detail +
                            '<p class="mt-2 text-xs text-zinc-600 dark:text-zinc-400">If you mixed SELECT with INSERT, remove the extra lines and keep one INSERT statement only.</p>';
                    }
                    return;
                }
                const verdict = String(data.verdict || 'wrong');
                var rawFeedback = Array.isArray(data.feedback) ? data.feedback : [];
                var lines =
                    verdict === 'correct'
                        ? rawFeedback.filter(function (line) {
                              return String(line).trim().indexOf('Hint:') !== 0;
                          })
                        : rawFeedback;
                let qMarks =
                    typeof data.marks === 'number' && !isNaN(data.marks)
                        ? Math.max(0, Math.min(MARKS_PER_QUESTION, Math.round(data.marks)))
                        : Math.max(
                              0,
                              Math.min(
                                  MARKS_PER_QUESTION,
                                  Math.round(MARKS_PER_QUESTION * (typeof data.similarity === 'number' ? data.similarity : 0))
                              )
                          );
                score += qMarks;
                setScoreDisplay();
                let badge =
                    '<span class="rounded-full bg-zinc-200 px-2 py-0.5 text-[11px] font-bold text-zinc-800 dark:bg-zinc-700 dark:text-zinc-100">Review</span>';
                if (verdict === 'correct') {
                    badge =
                        '<span class="rounded-full bg-zinc-300 px-2 py-0.5 text-[11px] font-bold text-zinc-900 dark:bg-zinc-600 dark:text-zinc-100">Correct</span>';
                    triggerCardCorrectFeedback();
                } else if (verdict === 'partial') {
                    badge =
                        '<span class="rounded-full bg-amber-200 px-2 py-0.5 text-[11px] font-bold text-amber-950 dark:bg-amber-900/50 dark:text-amber-100">Partial</span>';
                    triggerCardWrongFeedback();
                } else {
                    triggerCardWrongFeedback();
                }
                const sim =
                    data.sqlite_error
                        ? ''
                        : typeof data.similarity === 'number'
                          ? '<p class="mt-2 text-[13px] tabular-nums text-zinc-600 dark:text-zinc-400">Overlap: ' +
                            Math.round(data.similarity * 100) +
                            '%</p>'
                          : '';
                const marksNote =
                    '<p class="mt-2 text-[13px] font-semibold tabular-nums text-zinc-700 dark:text-zinc-300">Marks: ' +
                    String(qMarks) +
                    ' / ' +
                    String(MARKS_PER_QUESTION) +
                    '</p>';
                const ul =
                    lines.length > 0
                        ? '<ul class="mt-3 list-disc space-y-1.5 pl-4 text-[14px] leading-snug marker:text-zinc-400">' +
                          lines
                              .map(function (line) {
                                  return '<li>' + escapeHtml(String(line)) + '</li>';
                              })
                              .join('') +
                          '</ul>'
                        : '';
                if (fbEl) {
                    fbEl.classList.remove('hidden');
                    fbEl.innerHTML =
                        '<div class="mb-2 flex flex-wrap items-center gap-2">' +
                        badge +
                        '</div>' +
                        marksNote +
                        sim +
                        ul;
                }
                const shortSql = sqlText.length > 900 ? sqlText.slice(0, 897) + '…' : sqlText;
                pendingAttemptLog = {
                    questionId: orderedIds[currentIndex],
                    playType: 'sql',
                    stem: String(q.question || ''),
                    userAnswer: shortSql,
                    correctAnswer:
                        'Automatic result-set grade' +
                        (data.sqlite_error
                            ? ' (statement did not execute)'
                            : typeof data.similarity === 'number'
                              ? ' (~' + Math.round(data.similarity * 100) + '% overlap)'
                              : ''),
                    verdict: verdict === 'correct' ? 'correct' : verdict === 'partial' ? 'partial' : 'wrong',
                    marksEarned: qMarks,
                    marksMax: MARKS_PER_QUESTION,
                };
                renderNextButton();
            })
            .catch(function () {
                runBtnBusy(false);
                locked = false;
                if (fbEl) {
                    fbEl.classList.remove('hidden');
                    fbEl.innerHTML =
                        '<p class="font-semibold text-red-800 dark:text-red-200">Network error. Check your connection and try again.</p>';
                }
            });
    }

    function runBtnBusy(on) {
        const b = document.getElementById('sqlRunBtn');
        if (!b) {
            return;
        }
        b.disabled = !!on;
        b.textContent = on ? 'Checking…' : 'Run SQL check';
    }

    function renderTheoryQuestion(q) {
        const prompt = escapeHtml(String(q.question || ''));
        questionBox.innerHTML =
            '<h2 class="mb-3 text-left text-base font-bold leading-snug text-slate-900 sm:text-lg dark:text-zinc-100">' +
            prompt +
            '</h2>' +
            '<label for="theoryInput" class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-zinc-400">Your answer</label>' +
            '<textarea id="theoryInput" rows="4" maxlength="2000" autocomplete="off" ' +
            'class="w-full min-h-[100px] resize-y rounded-2xl border border-zinc-200 bg-white px-3 py-3 text-[16px] leading-relaxed text-zinc-900 shadow-sm focus:border-zinc-400 focus:outline-none focus:ring-2 focus:ring-zinc-400/30 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100 sm:text-base" ' +
            'placeholder="Type your answer…"></textarea>' +
            '<button type="button" id="frSubmit" class="mt-4 flex min-h-[52px] w-full touch-manipulation items-center justify-center rounded-2xl border border-zinc-800 bg-zinc-900 px-4 py-3.5 text-[15px] font-semibold text-white shadow-sm transition-all duration-200 hover:bg-zinc-800 active:scale-[0.99] dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white">Check answer</button>';
        bindFreeResponseHandlers(q);
    }

    function showLoadedQuestion(q) {
        locked = false;
        setProgress();
        maybeStartQuizTimer();
        if (quizCard) {
            var oldLayer = quizCard.querySelector('.quiz-card-emoji-layer');
            if (oldLayer) oldLayer.remove();
            quizCard.classList.remove('quiz-card--wrong', 'quiz-card--correct');
        }

        const playType = String(q.play_type || detectPlayType(q)).toLowerCase();

        if (playType === 'sql') {
            renderSqlQuestion(q);
            saveQuizResume();
            return;
        }
        if (playType === 'fill') {
            renderFillQuestion(q);
            saveQuizResume();
            return;
        }
        if (playType === 'theory') {
            renderTheoryQuestion(q);
            saveQuizResume();
            return;
        }

        const title =
            '<h2 class="mb-3 text-left text-base font-bold leading-snug text-slate-900 sm:mb-4 sm:text-lg dark:text-zinc-100">' +
            escapeHtml(String(q.question)) +
            '</h2>';
        questionBox.innerHTML = title + renderMcqOptions(q);
        bindMcqHandlers(q);
        saveQuizResume();
    }

    function showQuestionAtCurrentIndex() {
        if (orderedIds.length === 0) {
            questionBox.innerHTML = '<p class="text-slate-500 dark:text-zinc-400">No questions in this quiz yet.</p>';
            setProgress();
            setStatus('No Questions', 'error');
            return;
        }

        if (currentIndex >= orderedIds.length) {
            endQuiz();
            return;
        }
        if (shouldShowAdBreak()) {
            renderAdInterstitial(function () {
                showQuestionAtCurrentIndex();
            });
            return;
        }

        renderLoading();
        setProgress();
        setStatus('In Progress', 'ok');

        const id = orderedIds[currentIndex];
        resolveQuestion(id)
            .then(function (q) {
                showLoadedQuestion(q);
            })
            .catch(function () {
                renderFetchError('Could not load this question.', function () {
                    showQuestionAtCurrentIndex();
                });
            });
    }

    function normalize(s) {
        return String(s || '')
            .trim()
            .toLowerCase();
    }

    function isCorrectAnswer(selected, correct) {
        const userAnswer = normalize(selected);
        const normalizedCorrect = normalize(correct);
        if (!normalizedCorrect) return false;
        // Whole answer first so commas inside one correct string (e.g. "Paris, France") are not
        // misread as multiple alternatives (that used to mark wrong + highlight the same option green).
        if (userAnswer === normalizedCorrect) {
            return true;
        }
        if (String(correct).indexOf(',') !== -1) {
            const answers = String(correct)
                .split(',')
                .map(function (a) { return normalize(a); })
                .filter(Boolean);
            return answers.some(function (a) { return userAnswer === a; });
        }
        return false;
    }

    /** When correct_answer is a single letter A–D, compare against the actual option text. */
    function resolveLetterMcqCorrect(correct, q) {
        if (!q) {
            return String(correct || '');
        }
        const c = String(correct || '').trim();
        if (!/^[ABCD]$/i.test(c)) {
            return c;
        }
        const map = { A: 'option_a', B: 'option_b', C: 'option_c', D: 'option_d' };
        const col = map[c.toUpperCase()];
        if (!col) {
            return c;
        }
        const v = q[col];
        if (v == null || String(v).trim() === '') {
            return c;
        }
        return String(v).trim();
    }

    function isMcqSelectionCorrect(selected, correct, q) {
        return (
            isCorrectAnswer(selected, correct) || isCorrectAnswer(selected, resolveLetterMcqCorrect(correct, q))
        );
    }

    function isFillTheoryCorrect(userParts, correct, stem) {
        const n = userParts.length;
        const blanks = blankCountInStem(stem);
        if (blanks <= 1 || n <= 1) {
            return isCorrectAnswer(userParts[0] || '', correct);
        }
        const segs = String(correct || '').split('|');
        if (segs.length !== n) {
            return false;
        }
        for (let i = 0; i < n; i++) {
            if (!isCorrectAnswer(userParts[i], segs[i])) {
                return false;
            }
        }
        return true;
    }

    const THEORY_PASS_PCT = 50;

    function theoryAcceptHit(normUser, acceptList) {
        for (let i = 0; i < acceptList.length; i++) {
            const p = normalize(acceptList[i]);
            if (!p) continue;
            if (normUser.indexOf(p) !== -1) return true;
            if (normUser.length > 0 && normUser.length <= p.length && p.indexOf(normUser) !== -1) return true;
        }
        return false;
    }

    function theoryKeywordScore(normUser, keywords) {
        const matched = [];
        const missing = [];
        for (let i = 0; i < keywords.length; i++) {
            const k = normalize(keywords[i]);
            if (!k) continue;
            if (normUser.indexOf(k) !== -1) matched.push(String(keywords[i]));
            else missing.push(String(keywords[i]));
        }
        const total = keywords.length;
        const pct = total === 0 ? 0 : (matched.length / total) * 100;
        return { matched, missing, pct, total };
    }

    /**
     * @returns {{ verdict: 'correct'|'partial'|'wrong', pct: number, missing: string[], acceptHit: boolean }}
     */
    function evaluateTheory(userText, q) {
        const normUser = normalize(userText);
        const kw = Array.isArray(q.theory_keywords) ? q.theory_keywords : [];
        const acc = Array.isArray(q.theory_accept) ? q.theory_accept : [];

        if (kw.length === 0 && acc.length === 0) {
            const ex = isCorrectAnswer(userText, q.correct_answer);
            return { verdict: ex ? 'correct' : 'wrong', pct: ex ? 100 : 0, missing: [], acceptHit: ex };
        }
        if (theoryAcceptHit(normUser, acc)) {
            return { verdict: 'correct', pct: 100, missing: [], acceptHit: true };
        }
        if (kw.length > 0) {
            const sc = theoryKeywordScore(normUser, kw);
            if (sc.pct >= THEORY_PASS_PCT) {
                return { verdict: 'correct', pct: sc.pct, missing: sc.missing, acceptHit: false };
            }
            if (sc.matched.length > 0) {
                return { verdict: 'partial', pct: sc.pct, missing: sc.missing, acceptHit: false };
            }
            return { verdict: 'wrong', pct: 0, missing: sc.missing, acceptHit: false };
        }
        const ex2 = isCorrectAnswer(userText, q.correct_answer);
        return { verdict: ex2 ? 'correct' : 'wrong', pct: ex2 ? 100 : 0, missing: [], acceptHit: ex2 };
    }

    function disableAllOptions() {
        document.querySelectorAll('.option').forEach(function (b) {
            b.disabled = true;
        });
    }

    function renderNextButton() {
        if (document.getElementById('nextBtn')) return;
        questionBox.insertAdjacentHTML(
            'beforeend',
            '<button type="button" id="nextBtn" class="mt-4 w-full rounded-xl border border-zinc-300 bg-zinc-900 p-3.5 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800 dark:border-zinc-600 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white">Continue</button>'
        );
        const nextBtn = document.getElementById('nextBtn');
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                advance();
            });
        }
    }

    function checkMcq(btn, selected, correct, q) {
        if (locked) {
            return;
        }
        locked = true;
        disableAllOptions();

        const ok = isMcqSelectionCorrect(selected, correct, q);

        if (ok) {
            btn.textContent = selected;
            btn.className =
                'option flex min-h-[52px] w-full items-center rounded-2xl border border-zinc-400 bg-zinc-100 p-3.5 text-left text-[15px] font-semibold text-zinc-900 success-pop shadow-sm sm:min-h-0 sm:rounded-xl sm:p-4 sm:text-base dark:border-zinc-500 dark:bg-zinc-800 dark:text-zinc-100';
            btn.insertAdjacentHTML('beforeend', ' <span class="inline-block shrink-0 text-zinc-600 dark:text-zinc-300" aria-hidden="true">✓</span>');
            score += MARKS_PER_QUESTION;
            setScoreDisplay();
            triggerCardCorrectFeedback();
        } else {
            btn.textContent = selected;
            btn.className =
                'option flex min-h-[52px] w-full items-center rounded-2xl border border-red-300 bg-red-50 p-3.5 text-left text-[15px] font-semibold text-red-950 shadow-sm sm:min-h-0 sm:rounded-xl sm:p-4 sm:text-base dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-100';
            btn.insertAdjacentHTML('beforeend', ' <span class="inline-block shrink-0 opacity-90" aria-hidden="true">✗</span>');
            if (navigator.vibrate) {
                navigator.vibrate(200);
            }
            triggerCardWrongFeedback();
            highlightCorrectMcq(correct, q);
        }

        pendingAttemptLog = {
            questionId: orderedIds[currentIndex],
            playType: 'mcq',
            stem: String(q.question || ''),
            userAnswer: String(selected),
            correctAnswer: formatCorrectAnswerForReview(q),
            verdict: ok ? 'correct' : 'wrong',
            marksEarned: ok ? MARKS_PER_QUESTION : 0,
            marksMax: MARKS_PER_QUESTION,
        };

        renderNextButton();
    }

    function highlightCorrectMcq(correct, q) {
        document.querySelectorAll('.option').forEach(function (b) {
            const val = b.getAttribute('data-option') || '';
            if (!isMcqSelectionCorrect(val, correct, q)) {
                return;
            }
            b.textContent = val;
            b.className =
                'option flex min-h-[52px] w-full items-center rounded-2xl border border-zinc-400 bg-zinc-100 p-3.5 text-left text-[15px] font-semibold text-zinc-900 shadow-sm sm:min-h-0 sm:rounded-xl sm:p-4 sm:text-base dark:border-zinc-500 dark:bg-zinc-800 dark:text-zinc-100';
            b.insertAdjacentHTML('beforeend', ' <span class="inline-block shrink-0 text-zinc-600 dark:text-zinc-300" aria-hidden="true">✓</span>');
        });
    }

    function checkFreeResponse(q, submit) {
        const inputs = collectFreeResponseInputs();
        if (!submit || !inputs.length) return;

        locked = true;
        inputs.forEach(function (inp) {
            inp.disabled = true;
        });
        submit.disabled = true;

        const userParts = inputs.map(function (inp) {
            return inp.value;
        });
        const isTheory = !!document.getElementById('theoryInput');
        let verdict = 'wrong';
        /** @type {{ verdict: string, pct: number, missing: string[], acceptHit: boolean } | null} */
        let theoryEv = null;
        let feedbackLine = '';

        function setFreeInputState(v) {
            inputs.forEach(function (inp) {
                inp.classList.remove(
                    'ring-2',
                    'ring-red-500',
                    'border-red-500',
                    'ring-zinc-400',
                    'border-zinc-400',
                    'ring-amber-500',
                    'border-amber-500'
                );
                if (v === 'correct') {
                    inp.classList.add('ring-2', 'ring-zinc-400', 'border-zinc-400');
                } else if (v === 'partial') {
                    inp.classList.add('ring-2', 'ring-amber-500', 'border-amber-500');
                } else {
                    inp.classList.add('ring-2', 'ring-red-500', 'border-red-500');
                }
            });
        }

        if (isTheory) {
            theoryEv = evaluateTheory(userParts[0] || '', q);
            verdict = theoryEv.verdict;
            if (verdict === 'partial' && theoryEv.missing && theoryEv.missing.length) {
                feedbackLine =
                    '<p class="mt-2 text-sm text-amber-900/90 dark:text-amber-200">Missing key ideas: ' +
                    escapeHtml(theoryEv.missing.slice(0, 4).join(', ')) +
                    '.</p>';
            } else if (verdict === 'partial') {
                feedbackLine =
                    '<p class="mt-2 text-sm text-amber-900/90 dark:text-amber-200">Close — add a bit more detail.</p>';
            } else if (verdict === 'wrong' && theoryEv.missing && theoryEv.missing.length) {
                feedbackLine =
                    '<p class="mt-2 text-sm text-amber-900/90 dark:text-amber-200">Hint — try including: ' +
                    escapeHtml(theoryEv.missing.slice(0, 4).join(', ')) +
                    '.</p>';
            }
        } else {
            const okFill = isFillTheoryCorrect(userParts, q.correct_answer, q.question);
            verdict = okFill ? 'correct' : 'wrong';
            if (!okFill) {
                const evFill = evaluateTheory(userParts.join(' ').trim(), q);
                if (evFill.missing && evFill.missing.length) {
                    feedbackLine =
                        '<p class="mt-2 text-sm text-amber-900/90 dark:text-amber-200">Hint — try including: ' +
                        escapeHtml(evFill.missing.slice(0, 4).join(', ')) +
                        '.</p>';
                }
            }
        }

        let frMarks = 0;
        if (verdict === 'correct') {
            frMarks = MARKS_PER_QUESTION;
        } else if (verdict === 'partial' && theoryEv) {
            frMarks = Math.max(
                0,
                Math.min(MARKS_PER_QUESTION, Math.round((MARKS_PER_QUESTION * theoryEv.pct) / 100))
            );
        }

        if (verdict === 'correct') {
            setFreeInputState('correct');
            submit.className =
                'mt-4 w-full min-h-[48px] rounded-xl border border-zinc-400 bg-zinc-100 p-4 text-base font-semibold text-zinc-900 success-pop shadow-sm dark:border-zinc-500 dark:bg-zinc-800 dark:text-zinc-100';
            submit.innerHTML = 'Correct <span aria-hidden="true" class="text-zinc-600 dark:text-zinc-300">✓</span>';
            score += frMarks;
            setScoreDisplay();
            triggerCardCorrectFeedback();
        } else if (verdict === 'partial') {
            setFreeInputState('partial');
            submit.className =
                'mt-4 w-full min-h-[48px] rounded-xl border border-amber-300 bg-amber-50 p-4 text-base font-semibold text-amber-950 shadow-sm dark:border-amber-900/40 dark:bg-amber-950/35 dark:text-amber-100';
            submit.innerHTML = 'Partially correct <span aria-hidden="true">◆</span>';
            score += frMarks;
            setScoreDisplay();
        } else {
            setFreeInputState('wrong');
            submit.className =
                'mt-4 w-full min-h-[48px] rounded-xl border border-red-300 bg-red-50 p-4 text-base font-semibold text-red-950 shadow-sm dark:border-red-900/40 dark:bg-red-950/30 dark:text-red-100';
            submit.innerHTML = 'Wrong <span aria-hidden="true">✗</span>';
            if (navigator.vibrate) {
                navigator.vibrate(200);
            }
            triggerCardWrongFeedback();
        }

        const ua =
            userParts.length > 1
                ? userParts
                      .map(function (p) {
                          return String(p || '').trim();
                      })
                      .filter(Boolean)
                      .join(' · ')
                : String(userParts[0] || '');
        pendingAttemptLog = {
            questionId: orderedIds[currentIndex],
            playType: isTheory ? 'theory' : 'fill',
            stem: String(q.question || ''),
            userAnswer: ua,
            correctAnswer: formatCorrectAnswerForReview(q),
            verdict: verdict === 'correct' ? 'correct' : verdict === 'partial' ? 'partial' : 'wrong',
            marksEarned: frMarks,
            marksMax: MARKS_PER_QUESTION,
        };

        renderNextButton();
        if (feedbackLine) {
            submit.insertAdjacentHTML('afterend', feedbackLine);
        }
    }

    function renderExamReviewThenSave() {
        const total = maxQuizMarks();
        const parts = [];
        for (let i = 0; i < examReviewItems.length; i++) {
            const row = examReviewItems[i];
            const v = row.verdict;
            let badge =
                '<span class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">Correct</span>';
            if (v === 'partial') {
                badge =
                    '<span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-900 dark:bg-amber-200/90">Partial</span>';
            } else if (v === 'wrong') {
                badge =
                    '<span class="shrink-0 rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-bold text-red-800 dark:bg-red-900/40 dark:text-red-200">Wrong</span>';
            }
            const stemShort = String(row.stem || '').length > 220 ? String(row.stem).slice(0, 217) + '…' : String(row.stem || '');
            const marksLine =
                row.marksMax != null && row.marksEarned != null
                    ? '<p class="mt-1 text-xs tabular-nums font-medium text-zinc-500 dark:text-zinc-400">Marks: ' +
                      escapeHtml(String(row.marksEarned)) +
                      ' / ' +
                      escapeHtml(String(row.marksMax)) +
                      '</p>'
                    : '';
            parts.push(
                '<li class="rounded-xl border border-zinc-200 bg-white p-3 text-left shadow-sm dark:border-zinc-600 dark:bg-zinc-950/40">' +
                    '<div class="mb-2 flex flex-wrap items-start justify-between gap-2">' +
                    '<p class="min-w-0 flex-1 text-sm font-semibold leading-snug text-zinc-900 dark:text-zinc-100">' +
                    escapeHtml(stemShort) +
                    '</p>' +
                    badge +
                    '</div>' +
                    marksLine +
                    '<p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Your answer</p>' +
                    '<p class="mt-0.5 text-sm text-zinc-800 dark:text-zinc-200">' +
                    escapeHtml(String(row.userAnswer || '—')) +
                    '</p>' +
                    '<p class="mt-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">Correct answer</p>' +
                    '<p class="mt-0.5 text-sm font-medium text-zinc-700 dark:text-zinc-300">' +
                    escapeHtml(String(row.correctAnswer || '—')) +
                    '</p>' +
                    '</li>'
            );
        }
        const listHtml =
            parts.length > 0
                ? '<ol class="list-decimal space-y-3 pl-4 marker:font-semibold marker:text-zinc-400 dark:marker:text-zinc-500">' +
                  parts.join('') +
                  '</ol>'
                : '<p class="text-sm text-zinc-500 dark:text-zinc-400">No question details to show.</p>';
        questionBox.innerHTML =
            '<div class="touch-manipulation space-y-4">' +
            '<div>' +
            '<h2 class="text-xl font-bold text-zinc-900 dark:text-zinc-100">Exam complete</h2>' +
            '<p class="mt-1 text-2xl font-semibold text-zinc-800 dark:text-zinc-200">' +
            score +
            ' <span class="text-lg font-normal text-zinc-400 dark:text-zinc-500">/</span> ' +
            total +
            '</p>' +
            '<p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Review your answers below, then save to see rankings.</p>' +
            '</div>' +
            '<div class="max-h-[min(28rem,55vh)] overflow-y-auto rounded-2xl border border-zinc-200/90 bg-white p-3 shadow-sm dark:border-zinc-600 dark:bg-zinc-900 sm:p-4">' +
            '<p class="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">All questions</p>' +
            listHtml +
            '</div>' +
            '<button type="button" id="examReviewSaveBtn" class="w-full rounded-xl border border-zinc-900 bg-zinc-900 p-3.5 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800 active:scale-[0.99] dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white">' +
            'Save &amp; continue to results' +
            '</button>' +
            '</div>';
        const btn = document.getElementById('examReviewSaveBtn');
        if (btn) {
            btn.addEventListener('click', function () {
                btn.disabled = true;
                btn.textContent = 'Saving…';
                saveScore();
            });
        }
    }

    function advance() {
        flushPendingQuestionAttempt();
        currentIndex++;
        saveQuizResume();
        showQuestionAtCurrentIndex();
    }

    function endQuiz() {
        if (quizFinished) return;
        flushPendingQuestionAttempt();
        quizFinished = true;
        stopDurationSyncPolling();
        clearQuizResumeStorage();
        if (timerHandle) {
            clearInterval(timerHandle);
            timerHandle = null;
        }
        setProgress();
        setStatus('Completed', 'done');
        renderExamReviewThenSave();
    }

    function showQuizOutroThenRedirect(doneUrl, finalScore, finalTotal) {
        var mount = document.getElementById('quizOutroMount');
        var ov = document.getElementById('quizOutroOverlay');
        if (!ov || !mount) {
            window.location.href = doneUrl;
            return;
        }
        if (document.body) {
            document.body.classList.add('overflow-hidden');
        }
        ov.style.display = 'flex';
        ov.removeAttribute('aria-hidden');
        var img = examOutroImage;
        var author = quizAuthorName.trim();
        mount.innerHTML =
            '<div class="mx-auto w-full max-w-md rounded-2xl border border-slate-300 bg-white p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-900">' +
            '<div class="flex flex-row items-center gap-3 sm:gap-4">' +
            '<img src="' +
            escapeAttr(img) +
            '" alt="" class="trytest-quiz-outro-thumb shrink-0 rounded-xl border border-slate-200 bg-slate-100 dark:border-zinc-600 dark:bg-zinc-800" width="144" height="144" loading="eager" />' +
            '<div class="min-w-0 flex-1 text-left">' +
            '<p class="text-sm font-semibold leading-snug text-slate-800 sm:text-base dark:text-zinc-100">Nice work — your results are next.</p>' +
            '<p class="mt-1 text-xs tabular-nums text-slate-600 sm:text-sm dark:text-zinc-400">Score ' +
            escapeHtml(String(finalScore)) +
            ' / ' +
            escapeHtml(String(finalTotal)) +
            '</p>' +
            (author !== ''
                ? '<p class="mt-2 border-t border-zinc-100 pt-2 text-xs font-medium tracking-wide text-zinc-600 sm:text-sm dark:border-zinc-700 dark:text-zinc-400">' +
                  escapeHtml(author) +
                  '</p>'
                : '') +
            '</div></div></div>' +
            '<div class="mx-auto mt-6 w-full max-w-md">' +
            '<button type="button" id="quizOutroContinue" class="w-full rounded-xl border border-zinc-900 bg-zinc-900 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800 active:scale-[0.99] sm:py-3.5 dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white">' +
            'Continue to results' +
            '</button></div>';
        var gone = false;
        var go = function () {
            if (gone) {
                return;
            }
            gone = true;
            window.location.href = doneUrl;
        };
        var b = document.getElementById('quizOutroContinue');
        if (b) {
            b.addEventListener('click', go);
        }
    }

    function saveScore() {
        if (!quizId || orderedIds.length < 1) return;
        var doneUrl = absTrytestPath('?done=' + encodeURIComponent(String(quizId)));
        var finalScore = score;
        var finalTotal = maxQuizMarks();
        fetch(absTrytestPath('save_score'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                quiz_id: quizId,
                score: finalScore,
                total: finalTotal,
                review: examReviewItems,
            }),
        })
            .then(function (res) {
                return res.json().catch(function () {
                    return {};
                });
            })
            .finally(function () {
                showQuizOutroThenRedirect(doneUrl, finalScore, finalTotal);
            });
    }

    function start() {
        if (!quizId) {
            questionBox.innerHTML = '<p class="text-slate-500 dark:text-zinc-400">Invalid quiz.</p>';
            return;
        }
        quizFinished = false;
        questionBank = null;
        pendingAttemptLog = null;
        examReviewItems = [];

        renderLoading();
        progressLabel.textContent = 'Starting…';
        setScoreDisplay();
        setStatus('Starting', 'ok');
        quizClockStarted = false;

        resetPriorAttemptIfNeeded()
            .then(loadQuestionIds)
            .then(function (boot) {
                var ids = boot.ids;
                questionBank =
                    boot.questions && typeof boot.questions === 'object' && !Array.isArray(boot.questions)
                        ? boot.questions
                        : null;
                if (!ids.length) {
                    questionBank = null;
                    questionBox.innerHTML = '<p class="text-slate-500 dark:text-zinc-400">No questions in this quiz yet.</p>';
                    progressLabel.textContent = '';
                    if (totalValue) totalValue.textContent = '0';
                    if (progressBar) progressBar.style.width = '0%';
                    setStatus('No Questions', 'error');
                    return;
                }
                var raw = null;
                try {
                    raw = localStorage.getItem(resumeStorageKey());
                } catch (e) {}
                var saved = parseResumePayload(raw);
                if (saved && applyResume(saved, ids)) {
                    attachTimerSyncFromResponse(boot);
                    progressLabel.textContent = 'Resuming where you left off…';
                    setStatus('In Progress', 'ok');
                    startDurationSyncPolling();
                    showQuestionAtCurrentIndex();
                    return;
                }
                attachTimerSyncFromResponse(boot);
                remainingSeconds = durationCapSeconds;
                setFrozenTimerLabel();
                orderedIds = shuffleInPlace(ids.slice());
                currentIndex = 0;
                score = 0;
                adBreaksSeen = [];
                setScoreDisplay();
                if (totalValue) totalValue.textContent = String(maxQuizMarks());
                startDurationSyncPolling();
                showQuestionAtCurrentIndex();
            })
            .catch(function () {
                progressLabel.textContent = '';
                if (progressBar) progressBar.style.width = '0%';
                setStatus('Network Error', 'error');
                renderFetchError('Could not start the quiz.', start);
            });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.shiftKey) return;
        const btn = document.getElementById('frSubmit');
        if (!btn || btn.disabled) return;
        const ae = document.activeElement;
        if (!ae || !ae.classList || !ae.classList.contains('fill-blank-input')) return;
        e.preventDefault();
        btn.click();
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            pauseTimer();
            saveQuizResume();
            return;
        }
        if (document.visibilityState === 'visible') {
            resumeTimer();
        }
    });
    window.addEventListener('pagehide', function () {
        pauseTimer();
        saveQuizResume();
    });
    window.addEventListener('beforeunload', function () {
        saveQuizResume();
    });

    if (showQuizIntro) {
        renderQuizIntro();
    } else {
        hideQuizIntroOverlay();
        start();
    }
})();
