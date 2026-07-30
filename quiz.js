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
    const presenceWsUrl = String(cfg.presenceWsUrl || '').trim();
    const ytSubscribeBrowserUrl = String(cfg.ytSubscribeBrowserUrl || '').trim();

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

    function stopPresenceTracking() {
        if (presencePingTimer !== null) {
            clearInterval(presencePingTimer);
            presencePingTimer = null;
        }
        if (presenceWs) {
            try {
                presenceWs.close();
            } catch (e) {}
            presenceWs = null;
        }
        if (quizId && userId) {
            try {
                var blob = new Blob([JSON.stringify({ action: 'leave', quiz_id: quizId })], { type: 'application/json' });
                navigator.sendBeacon(absTrytestPath('api_quiz_presence_ping.php'), blob);
            } catch (e2) {}
        }
    }

    function startPresenceTracking() {
        if (!quizId || !userId) {
            return;
        }
        if (presenceWsUrl) {
            try {
                presenceWs = new WebSocket(presenceWsUrl);
                presenceWs.addEventListener('open', function () {
                    if (presenceWs && presenceWs.readyState === 1) {
                        presenceWs.send(JSON.stringify({ type: 'quiz', quizId: quizId }));
                    }
                });
            } catch (e) {}
        }
        function pingHttp() {
            fetch(absTrytestPath('api_quiz_presence_ping.php'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'ping', quiz_id: quizId }),
            }).catch(function () {});
        }
        if (!presenceWsUrl) {
            pingHttp();
            if (presencePingTimer !== null) {
                clearInterval(presencePingTimer);
            }
            presencePingTimer = setInterval(pingHttp, 22000);
        }
    }

    const questionBox = document.getElementById('questionBox');
    const quizCard = document.getElementById('quizCard');
    const progressLabel = document.getElementById('progressLabel');
    const scoreValue = document.getElementById('scoreValue');
    const totalValue = document.getElementById('totalValue');
    const marksScaleHint = document.getElementById('marksScaleHint');
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

    /**
     * After shuffle, each row keeps bank = DB column (option_a→A) and text = stored option string.
     * On-screen A–D badges are positional only; correct_answer letters always mean bank columns.
     */
    /** @type {{ bank: string, text: string }[]|null} */
    let mcqRenderOrder = null;

    /** Bank→on-screen letter map for the current MCQ draw (also used to rewrite the stem). */
    /** @type {Record<string, string>|null} */
    let mcqBankToPosMap = null;

    /** @type {ReturnType<typeof setInterval> | null} */
    let presencePingTimer = null;
    /** @type {WebSocket | null} */
    let presenceWs = null;

    /** Each quiz item counts as one mark (MCQ, theory, fill, etc.). */
    const MARKS_PER_QUESTION = 1;

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
            progressLabel.textContent = 'No questions';
            if (totalValue) totalValue.textContent = '0';
            if (progressBar) progressBar.style.width = '0%';
            return;
        }
        if (totalValue) totalValue.textContent = String(maxQuizMarks());
        if (currentIndex >= orderedIds.length) {
            progressLabel.textContent = 'All ' + orderedIds.length + ' questions seen';
            if (progressBar) progressBar.style.width = '100%';
            return;
        }
        progressLabel.textContent =
            MARKS_PER_QUESTION === 1
                ? 'Question ' + (currentIndex + 1) + ' of ' + orderedIds.length
                : 'Question ' +
                  (currentIndex + 1) +
                  ' of ' +
                  orderedIds.length +
                  ' · marks max ' +
                  String(maxQuizMarks());
        if (progressBar) {
            const done = currentIndex / orderedIds.length;
            progressBar.style.width = Math.max(0, Math.min(100, done * 100)) + '%';
        }
    }

    function setScoreDisplay() {
        if (scoreValue) {
            scoreValue.textContent = String(score);
        }
        if (totalValue) {
            totalValue.textContent = orderedIds.length > 0 ? String(maxQuizMarks()) : '0';
        }
        if (marksScaleHint && orderedIds.length > 0) {
            marksScaleHint.textContent =
                MARKS_PER_QUESTION === 1
                    ? String(orderedIds.length) + ' questions · 1 mark each'
                    : String(orderedIds.length) +
                      ' questions × ' +
                      String(MARKS_PER_QUESTION) +
                      ' marks each → ' +
                      String(maxQuizMarks()) +
                      ' max';
        } else if (marksScaleHint) {
            marksScaleHint.textContent = '';
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
        quizIntroFinished = true;
        clearQuizIntroInterval();
        try {
            sessionStorage.setItem('trytest_quiz_intro_done_' + String(quizId), '1');
        } catch (eIntro) {}
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
            '<button type="button" id="quizIntroContinue" class="w-full cursor-pointer rounded-xl border border-zinc-800 bg-zinc-900 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800 active:scale-[0.99] sm:py-3.5 dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white">' +
            'Continue' +
            '</button></div>';

        var contBtn = document.getElementById('quizIntroContinue');

        if (contBtn) {
            contBtn.addEventListener('click', function () {
                if (quizIntroFinished) {
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
                qs += '&mute=0&enablejsapi=1';
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
        var subHtml =
            ytSubscribeBrowserUrl !== ''
                ? '<p class="text-xs text-slate-600 dark:text-zinc-400">Prefer to support the channel? <button type="button" id="adSubscribeBtn" class="font-bold text-red-600 underline decoration-red-300">Open YouTube &amp; subscribe</button> instead, then return here — your answers stay saved.</p>' +
                  '<p id="adSubscribeHint" class="hidden text-xs font-medium text-emerald-700 dark:text-emerald-400">When you are back on this tab, tap continue below.</p>' +
                  '<button type="button" id="adContinueSubscribe" class="hidden w-full rounded-xl border border-emerald-700 bg-emerald-50 px-3 py-2.5 text-sm font-semibold text-emerald-900 shadow-sm hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-100 dark:hover:bg-emerald-900/50" disabled>I am back — continue quiz</button>'
                : '';
        questionBox.innerHTML =
            '<div class="touch-manipulation space-y-3">' +
            '<p class="text-[11px] font-semibold uppercase tracking-wide text-red-600 dark:text-red-400">Video break</p>' +
            '<h2 class="text-lg font-bold text-slate-900 dark:text-zinc-100">Short video break</h2>' +
            '<p class="text-sm text-slate-600 dark:text-zinc-400">You reached question ' +
            currentIndex +
            '. The clip tries to play with sound (YouTube counts it like a normal view). Continue unlocks in <span id="adCountdown" class="font-bold text-slate-900 dark:text-zinc-100">' +
            wait +
            's</span>, or use subscribe instead.</p>' +
            subHtml +
            '<div class="overflow-hidden rounded-2xl border border-slate-200 bg-black dark:border-zinc-700">' +
            '<div class="aspect-video w-full"><iframe id="quizAdIframe" class="h-full w-full" src="' +
            escapeAttr(embed) +
            '" title="Quiz ad video" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div></div>' +
            '<button type="button" id="quizAdUnmuteBtn" aria-pressed="true" class="w-full cursor-pointer rounded-xl border border-zinc-400 bg-zinc-100 px-3 py-2.5 text-sm font-semibold text-zinc-900 hover:bg-zinc-200 dark:border-zinc-500 dark:bg-zinc-700 dark:text-zinc-100 dark:hover:bg-zinc-600">Tap to mute</button>' +
            '<button type="button" id="adContinueWatch" disabled class="w-full rounded-2xl bg-slate-300 p-3 text-sm font-bold text-white dark:text-zinc-700">Continue in ' +
            wait +
            's</button>' +
            '</div>';
        var countdownEl = document.getElementById('adCountdown');
        var adIframe = document.getElementById('quizAdIframe');
        var unmuteBtn = document.getElementById('quizAdUnmuteBtn');
        var btnWatch = document.getElementById('adContinueWatch');
        var btnSub = document.getElementById('adContinueSubscribe');
        var subBtn = document.getElementById('adSubscribeBtn');
        var subHint = document.getElementById('adSubscribeHint');
        if (adIframe) {
            adIframe.addEventListener(
                'load',
                function () {
                    sendYoutubePlayerListening(adIframe);
                    applyQuizAdIframeSound(adIframe, true);
                },
                { once: true }
            );
        }
        if (unmuteBtn && adIframe) {
            var adMuted = false;
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
            syncAdSoundButtonUi();
            unmuteBtn.addEventListener('click', function () {
                toggleAdSound();
            });
        }
        var subscribeChosen = false;
        var unlockTimer = null;
        function finishAd() {
            if (unlockTimer) {
                clearInterval(unlockTimer);
                unlockTimer = null;
            }
            document.removeEventListener('visibilitychange', onVis);
            markAdBreakSeen(breakIndex);
            resumeTimer();
            done();
        }
        function onVis() {
            if (!subscribeChosen || !btnSub) {
                return;
            }
            if (document.visibilityState === 'visible') {
                btnSub.disabled = false;
            }
        }
        document.addEventListener('visibilitychange', onVis);
        if (subBtn && ytSubscribeBrowserUrl !== '') {
            subBtn.addEventListener('click', function () {
                subscribeChosen = true;
                try {
                    window.open(ytSubscribeBrowserUrl, '_blank', 'noopener,noreferrer');
                } catch (e) {}
                if (btnSub) {
                    btnSub.classList.remove('hidden');
                }
                if (subHint) {
                    subHint.classList.remove('hidden');
                }
                setTimeout(function () {
                    if (btnSub) {
                        btnSub.disabled = false;
                    }
                }, 8000);
            });
        }
        if (btnSub) {
            btnSub.addEventListener('click', function () {
                if (btnSub.disabled) {
                    return;
                }
                finishAd();
            });
        }
        var t = wait;
        if (btnWatch) {
            unlockTimer = setInterval(function () {
                t--;
                if (countdownEl) countdownEl.textContent = String(Math.max(0, t)) + 's';
                if (t > 0) {
                    btnWatch.textContent = 'Continue in ' + t + 's';
                    return;
                }
                if (unlockTimer) {
                    clearInterval(unlockTimer);
                    unlockTimer = null;
                }
                btnWatch.disabled = false;
                btnWatch.className =
                    'w-full rounded-xl border border-zinc-800 bg-zinc-900 p-3 text-sm font-semibold text-white shadow-sm hover:bg-zinc-800 dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white';
                btnWatch.textContent = 'Continue quiz';
            }, 1000);
            btnWatch.addEventListener('click', function () {
                if (btnWatch.disabled) return;
                finishAd();
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

    /**
     * Multi-select style key in correct_answer: "AB", "A,B", "A and B", "both b and c", etc.
     * Returns sorted unique letters as a string (e.g. "BC") or null for single / non-letter keys.
     */
    function parseMcqCorrectLetterSet(correct) {
        const raw = String(correct || '').trim();
        if (!raw) return null;
        const compact = raw.replace(/\s+/g, '').toUpperCase();
        if (/^[ABCD]{2,4}$/.test(compact)) {
            const set = {};
            for (let i = 0; i < compact.length; i++) {
                set[compact[i]] = true;
            }
            return Object.keys(set).sort().join('');
        }
        const normalized = raw
            .replace(/\s+and\s+/gi, ',')
            .replace(/\s*&\s*/g, ',')
            .replace(/\s*\+\s*/g, ',')
            .replace(/\s*,\s*/g, ',')
            .replace(/\s+both\s+/gi, ',');
        const parts = normalized
            .split(/[,\/;|]+/)
            .map(function (p) {
                return p.trim();
            })
            .filter(Boolean);
        const lettersFromParts = [];
        for (let i = 0; i < parts.length; i++) {
            const p = parts[i].toUpperCase();
            if (/^[ABCD]$/.test(p)) {
                lettersFromParts.push(p);
            }
        }
        if (lettersFromParts.length >= 2) {
            const set = {};
            for (let j = 0; j < lettersFromParts.length; j++) {
                set[lettersFromParts[j]] = true;
            }
            return Object.keys(set).sort().join('');
        }
        const m = raw.toUpperCase().match(/\b([A-D])\b/g);
        if (m && m.length >= 2) {
            const set = {};
            for (let k = 0; k < m.length; k++) {
                set[m[k]] = true;
            }
            if (Object.keys(set).length >= 2) {
                return Object.keys(set).sort().join('');
            }
        }
        return null;
    }

    /** Letters A–D mentioned as standalone words (for "Both A and B" style options). */
    function extractLetterSetFromMcqOptionText(opt) {
        const m = String(opt || '')
            .toUpperCase()
            .match(/\b([A-D])\b/g);
        if (!m || m.length < 2) return null;
        const set = {};
        for (let i = 0; i < m.length; i++) {
            set[m[i]] = true;
        }
        const keys = Object.keys(set);
        if (keys.length < 2) return null;
        return keys.sort().join('');
    }

    /**
     * Option text for this quiz row whose wording encodes the same letter set (compound answers).
     */
    function findCompoundMcqOptionTextByLetterSet(q, letterSetStr) {
        if (!letterSetStr || !q) return '';
        const keys = ['option_a', 'option_b', 'option_c', 'option_d'];
        const hits = [];
        for (let i = 0; i < keys.length; i++) {
            const v = q[keys[i]];
            if (v == null || String(v).trim() === '') continue;
            const ts = String(v).trim();
            const setOpt = extractLetterSetFromMcqOptionText(ts);
            if (setOpt === letterSetStr) {
                hits.push(ts);
            }
        }
        return hits.length ? hits[0] : '';
    }

    function formatCorrectAnswerForReview(q) {
        const play = String(q.play_type || detectPlayType(q)).toLowerCase();
        if (play === 'mcq') {
            const ca = String(q.correct_answer || '');
            const setWant = parseMcqCorrectLetterSet(ca);
            let out = '';
            if (setWant) {
                const multi = findCompoundMcqOptionTextByLetterSet(q, setWant);
                if (multi) {
                    out = multi;
                }
            }
            if (out === '') {
                out = String(resolveLetterMcqCorrect(ca, q));
            }
            const bmap = mcqBankToPosMap || buildBankToPositionalLetterMapFromOrder(mcqRenderOrder);
            return rewriteBothAndPhrasesForShuffle(out, bmap || {});
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

    /**
     * MCQ row: fixed letter column + fluid text (grid keeps badge and first line on one horizontal rhythm;
     * items-center vertically centers the chip with the answer block for a sleek row).
     */
    const MCQ_OPTION_BTN_CLASS =
        'option grid min-h-[48px] w-full grid-cols-[2.25rem_minmax(0,1fr)] items-center gap-x-3 rounded-2xl border border-zinc-200 bg-white px-3 py-2.5 text-left text-[15px] font-medium leading-snug tracking-normal text-zinc-800 shadow-sm transition-all duration-200 hover:bg-zinc-50 active:scale-[0.99] disabled:opacity-50 sm:min-h-[52px] sm:rounded-xl sm:px-3.5 sm:py-3 sm:text-base dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700';

    /** Badge = on-screen position (first row A, …). Distinct color per letter A–D. */
    function mcqLetterBadgeClassForPositionalLetter(letter) {
        const L = String(letter || '')
            .toUpperCase()
            .slice(0, 1);
        const base =
            'trytest-mcq-letter inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-[11px] font-bold uppercase tabular-nums tracking-wide ring-1 ring-inset';
        const byLetter = {
            A: 'bg-sky-100 text-sky-900 ring-sky-300/80 dark:bg-sky-950/60 dark:text-sky-100 dark:ring-sky-700/55',
            B: 'bg-emerald-100 text-emerald-900 ring-emerald-300/80 dark:bg-emerald-950/60 dark:text-emerald-100 dark:ring-emerald-700/55',
            C: 'bg-violet-100 text-violet-900 ring-violet-300/80 dark:bg-violet-950/60 dark:text-violet-100 dark:ring-violet-700/55',
            D: 'bg-amber-100 text-amber-950 ring-amber-300/80 dark:bg-amber-950/60 dark:text-amber-100 dark:ring-amber-700/55',
        };
        return base + ' ' + (byLetter[L] || 'bg-zinc-200/90 text-zinc-800 ring-zinc-300/80 dark:bg-zinc-600 dark:text-zinc-100 dark:ring-zinc-500/55');
    }

    /** Map bank column letter (A=option_a) → on-screen letter for this shuffle. */
    function buildBankToPositionalLetterMapFromOrder(shuffledRows) {
        const map = {};
        if (!shuffledRows || !shuffledRows.length) {
            return map;
        }
        for (let i = 0; i < shuffledRows.length; i++) {
            const bank = String(shuffledRows[i].bank || '')
                .toUpperCase()
                .slice(0, 1);
            if (/^[ABCD]$/.test(bank)) {
                map[bank] = String.fromCharCode(65 + i);
            }
        }
        return map;
    }

    /**
     * Rewrite “Both A and B” / “both a, b” using bank letters to match on-screen letters after shuffle.
     */
    function rewriteBothAndPhrasesForShuffle(text, bankToPos) {
        const s = String(text || '');
        if (!s || !bankToPos || typeof bankToPos !== 'object') {
            return s;
        }
        function repl(m, _bw, x, y) {
            const bx = String(x).toUpperCase();
            const by = String(y).toUpperCase();
            const px = bankToPos[bx];
            const py = bankToPos[by];
            if (!px || !py) {
                return m;
            }
            const bothWord = /^Both\b/.test(String(m)) ? 'Both' : 'both';
            return bothWord + ' ' + px + ' and ' + py;
        }
        let out = s.replace(/\b(both)\s+([A-Da-d])\s+and\s+([A-Da-d])\b/gi, repl);
        out = out.replace(/\b(both)\s+([A-Da-d])\s*,\s*([A-Da-d])\b/gi, repl);
        return out;
    }

    /** Surface label for feedback (matches what the learner saw). */
    function displayMcqOptionSurfaceText(originalStoredText) {
        const bmap = mcqBankToPosMap || buildBankToPositionalLetterMapFromOrder(mcqRenderOrder);
        return rewriteBothAndPhrasesForShuffle(String(originalStoredText || ''), bmap || {});
    }

    function mcqOptionInitialInnerHtml(letter, text) {
        const lab = String(letter || '').toUpperCase().slice(0, 1);
        return (
            '<span class="' +
            mcqLetterBadgeClassForPositionalLetter(lab) +
            '" aria-hidden="true">' +
            escapeHtml(lab) +
            '</span><span class="min-w-0 justify-self-stretch text-left leading-snug break-words whitespace-normal">' +
            escapeHtml(String(text)) +
            '</span>'
        );
    }

    /** After answer feedback, keep the A–D badge beside the option text (do not use textContent alone). */
    function setMcqOptionFeedbackInnerHtml(btn, selectedText, suffixHtml) {
        const lab = String(btn.getAttribute('data-mcq-letter') || '')
            .toUpperCase()
            .slice(0, 1);
        btn.innerHTML =
            '<span class="' +
            mcqLetterBadgeClassForPositionalLetter(lab) +
            '" aria-hidden="true">' +
            escapeHtml(lab) +
            '</span><span class="flex min-w-0 items-start gap-2.5">' +
            '<span class="min-w-0 flex-1 text-left leading-snug break-words whitespace-normal">' +
            escapeHtml(displayMcqOptionSurfaceText(selectedText)) +
            '</span>' +
            suffixHtml +
            '</span>';
    }

    function renderMcqOptions(q) {
        mcqRenderOrder = null;
        mcqBankToPosMap = null;
        const keys = ['option_a', 'option_b', 'option_c', 'option_d'];
        const entries = [];
        keys.forEach(function (k, idx) {
            const text = q[k];
            if (text != null && String(text).trim() !== '') {
                entries.push({
                    bank: String.fromCharCode(65 + idx),
                    text: String(text).trim(),
                });
            }
        });
        shuffleInPlace(entries);
        mcqRenderOrder = entries.map(function (e) {
            return { bank: e.bank, text: e.text };
        });
        const bankToPos = buildBankToPositionalLetterMapFromOrder(entries);
        const parts = [];
        entries.forEach(function (entry, screenIndex) {
            const positional = String.fromCharCode(65 + screenIndex);
            const displayText = rewriteBothAndPhrasesForShuffle(entry.text, bankToPos);
            const labelHint = 'Choice ' + positional + ': ' + displayText.slice(0, 240);
            parts.push(
                '<button type="button" class="' +
                    MCQ_OPTION_BTN_CLASS +
                    '" data-option="' +
                    escapeAttr(entry.text) +
                    '" data-mcq-letter="' +
                    escapeAttr(positional) +
                    '" data-mcq-bank="' +
                    escapeAttr(entry.bank) +
                    '" aria-label="' +
                    escapeAttr(labelHint) +
                    '">' +
                    mcqOptionInitialInnerHtml(positional, displayText) +
                    '</button>'
            );
        });
        mcqBankToPosMap = bankToPos;
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
        mcqRenderOrder = null;
        mcqBankToPosMap = null;
        setProgress();
        maybeStartQuizTimer();
        if (quizCard) {
            var oldLayer = quizCard.querySelector('.quiz-card-emoji-layer');
            if (oldLayer) oldLayer.remove();
            quizCard.classList.remove('quiz-card--wrong', 'quiz-card--correct');
        }

        const playType = String(q.play_type || detectPlayType(q)).toLowerCase();

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

        const optsHtml = renderMcqOptions(q);
        const stemRaw = String(q.question || '');
        const stemShown =
            mcqBankToPosMap && typeof mcqBankToPosMap === 'object'
                ? rewriteBothAndPhrasesForShuffle(stemRaw, mcqBankToPosMap)
                : stemRaw;
        const stemBlock =
            '<div class="trytest-mcq-stem min-w-0 flex-1 lg:max-w-[52%] lg:pr-2">' +
            '<h2 class="mb-0 text-left text-base font-bold leading-snug text-slate-900 sm:text-lg lg:pt-0.5 dark:text-zinc-100">' +
            escapeHtml(stemShown) +
            '</h2>' +
            '</div>';
        const choicesBlock =
            '<div class="trytest-mcq-choices w-full min-w-0 lg:max-w-[min(28rem,46%)] lg:flex-shrink-0">' +
            optsHtml +
            '</div>';
        questionBox.innerHTML =
            '<div class="trytest-mcq-layout flex flex-col gap-4 sm:gap-5 lg:flex-row lg:items-start lg:justify-between lg:gap-8">' +
            stemBlock +
            choicesBlock +
            '</div>';
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

    /**
     * When correct_answer is a single letter A–D, map it to the DB bank option text
     * (option_a = A … option_d = D). Never treat the letter as on-screen row position after shuffle.
     */
    function resolveLetterMcqCorrect(correct, q) {
        if (!q) {
            return String(correct || '');
        }
        let c = String(correct || '').trim();
        // Allow "A", "A.", "A)", "(A)", "a :" etc.
        const letterMatch = c.match(/^\(?\s*([ABCD])\s*[\.\)\:\-]?\s*$/i);
        if (!letterMatch) {
            return c;
        }
        c = letterMatch[1].toUpperCase();
        if (mcqRenderOrder && mcqRenderOrder.length) {
            for (let i = 0; i < mcqRenderOrder.length; i++) {
                const row = mcqRenderOrder[i];
                if (row && String(row.bank || '').toUpperCase() === c) {
                    if (row.text != null && String(row.text).trim() !== '') {
                        return String(row.text).trim();
                    }
                    break;
                }
            }
        }
        const map = { A: 'option_a', B: 'option_b', C: 'option_c', D: 'option_d' };
        const col = map[c];
        if (!col) {
            return String(correct || '').trim();
        }
        const v = q[col];
        if (v == null || String(v).trim() === '') {
            return String(correct || '').trim();
        }
        return String(v).trim();
    }

    function isMcqSelectionCorrect(selected, correct, q) {
        if (!q) {
            return isCorrectAnswer(selected, correct);
        }
        if (isCorrectAnswer(selected, correct)) {
            return true;
        }
        if (isCorrectAnswer(selected, resolveLetterMcqCorrect(correct, q))) {
            return true;
        }
        const setWant = parseMcqCorrectLetterSet(correct);
        if (setWant) {
            const keys = ['option_a', 'option_b', 'option_c', 'option_d'];
            for (let i = 0; i < keys.length; i++) {
                const t = q[keys[i]];
                if (t == null || String(t).trim() === '') continue;
                const ts = String(t).trim();
                const setOpt = extractLetterSetFromMcqOptionText(ts);
                if (setOpt === setWant && isCorrectAnswer(selected, ts)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Single MCQ answer row to reveal after a wrong pick (never more than one highlight).
     * Ignores comma-separated “any of these” grading paths for display — uses first matching option text.
     */
    function getMcqCanonicalCorrectOptionText(correct, q) {
        if (!q) {
            return String(correct || '').trim();
        }
        const ca = String(correct || '').trim();
        if (!ca) {
            return '';
        }
        const setWant = parseMcqCorrectLetterSet(ca);
        if (setWant) {
            const multi = findCompoundMcqOptionTextByLetterSet(q, setWant);
            if (multi) {
                return String(multi).trim();
            }
        }
        if (/^[ABCD]$/i.test(ca) || /^\(?\s*[ABCD]\s*[\.\)\:\-]?\s*$/i.test(ca)) {
            return String(resolveLetterMcqCorrect(ca, q)).trim();
        }
        if (String(ca).indexOf(',') !== -1) {
            const parts = String(ca)
                .split(',')
                .map(function (s) {
                    return String(s || '').trim();
                })
                .filter(Boolean);
            const keys = ['option_a', 'option_b', 'option_c', 'option_d'];
            for (let p = 0; p < parts.length; p++) {
                const part = parts[p];
                const np = normalize(part);
                for (let i = 0; i < keys.length; i++) {
                    const t = q[keys[i]];
                    if (t == null || String(t).trim() === '') continue;
                    const ts = String(t).trim();
                    if (normalize(ts) === np) {
                        return ts;
                    }
                }
                if (mcqRenderOrder) {
                    for (let j = 0; j < mcqRenderOrder.length; j++) {
                        const ts2 = String(mcqRenderOrder[j].text || '').trim();
                        if (normalize(ts2) === np) {
                            return ts2;
                        }
                    }
                }
            }
        }
        return ca;
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
            btn.className =
                'option grid min-h-[48px] w-full grid-cols-[2.25rem_minmax(0,1fr)] items-center gap-x-3 rounded-2xl border border-zinc-400 bg-zinc-100 px-3 py-2.5 text-left text-[15px] font-semibold leading-snug tracking-normal text-zinc-900 shadow-sm success-pop sm:min-h-[52px] sm:rounded-xl sm:px-3.5 sm:py-3 sm:text-base dark:border-zinc-500 dark:bg-zinc-800 dark:text-zinc-100';
            setMcqOptionFeedbackInnerHtml(
                btn,
                selected,
                '<span class="mt-0.5 shrink-0 text-base leading-none text-zinc-600 dark:text-zinc-300" aria-hidden="true">✓</span>'
            );
            score += MARKS_PER_QUESTION;
            setScoreDisplay();
            triggerCardCorrectFeedback();
        } else {
            btn.className =
                'option grid min-h-[48px] w-full grid-cols-[2.25rem_minmax(0,1fr)] items-center gap-x-3 rounded-2xl border border-red-300 bg-red-50 px-3 py-2.5 text-left text-[15px] font-semibold leading-snug tracking-normal text-red-950 shadow-sm sm:min-h-[52px] sm:rounded-xl sm:px-3.5 sm:py-3 sm:text-base dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-100';
            setMcqOptionFeedbackInnerHtml(
                btn,
                selected,
                '<span class="mt-0.5 shrink-0 text-base leading-none opacity-90" aria-hidden="true">✗</span>'
            );
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
        const canon = getMcqCanonicalCorrectOptionText(correct, q);
        if (!canon) {
            return;
        }
        const nc = normalize(canon);
        var done = false;
        document.querySelectorAll('.option').forEach(function (b) {
            if (done) {
                return;
            }
            const val = String(b.getAttribute('data-option') || '').trim();
            if (normalize(val) !== nc) {
                return;
            }
            done = true;
            b.className =
                'option grid min-h-[48px] w-full grid-cols-[2.25rem_minmax(0,1fr)] items-center gap-x-3 rounded-2xl border border-zinc-400 bg-zinc-100 px-3 py-2.5 text-left text-[15px] font-semibold leading-snug tracking-normal text-zinc-900 shadow-sm sm:min-h-[52px] sm:rounded-xl sm:px-3.5 sm:py-3 sm:text-base dark:border-zinc-500 dark:bg-zinc-800 dark:text-zinc-100';
            setMcqOptionFeedbackInnerHtml(
                b,
                val,
                '<span class="mt-0.5 shrink-0 text-base leading-none text-zinc-600 dark:text-zinc-300" aria-hidden="true">✓</span>'
            );
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
        stopPresenceTracking();
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
                    startPresenceTracking();
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
                startDurationSyncPolling();
                startPresenceTracking();
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
        stopPresenceTracking();
    });

    var skipIntro = false;
    try {
        if (sessionStorage.getItem('trytest_quiz_intro_done_' + String(quizId)) === '1') {
            skipIntro = true;
        }
    } catch (eSkip) {}
    if (!skipIntro) {
        try {
            var rawResumeIntro = localStorage.getItem(resumeStorageKey());
            var parsedIntro = parseResumePayload(rawResumeIntro);
            if (parsedIntro && parseInt(String(parsedIntro.currentIndex), 10) > 0) {
                skipIntro = true;
            }
        } catch (eSkip2) {}
    }
    if (showQuizIntro && !skipIntro) {
        renderQuizIntro();
    } else {
        hideQuizIntroOverlay();
        start();
    }
})();
