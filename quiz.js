(function () {
    const cfg = window.QUIZ_CONFIG || { quizId: 0, userId: 0, durationSeconds: 0 };
    const quizId = cfg.quizId;
    const userId = Number(cfg.userId || 0);
    const durationSeconds = Number(cfg.durationSeconds || 0);
    const quizAdEnabled = !!cfg.quizAdEnabled;
    const quizAdEvery = Math.max(1, Number(cfg.quizAdEvery || 20));
    const quizAdWatchSeconds = Math.max(5, Number(cfg.quizAdWatchSeconds || 20));
    const quizAdVideos = Array.isArray(cfg.quizAdVideos) ? cfg.quizAdVideos.map(String).filter(Boolean) : [];
    const priorAttempt = !!cfg.priorAttempt;
    const resetAttemptUrl = String(cfg.resetAttemptUrl || '');

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
            if (!o || o.v !== 1 || !Array.isArray(o.orderedIds)) return null;
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
        if (Number(saved.durationSeconds) !== Number(durationSeconds)) return false;
        var idx = parseInt(String(saved.currentIndex), 10);
        if (isNaN(idx) || idx < 0 || idx >= saved.orderedIds.length) return false;
        orderedIds = saved.orderedIds.slice();
        currentIndex = idx;
        score = Math.max(0, parseInt(String(saved.score), 10) || 0);
        var rem = parseInt(String(saved.remainingSeconds), 10);
        rem = isNaN(rem) ? durationSeconds : Math.max(0, rem);
        if (durationSeconds > 0) {
            rem = Math.min(rem, durationSeconds);
        }
        remainingSeconds = rem;
        adBreaksSeen = saved.adBreaksSeen
            .map(function (x) { return parseInt(String(x), 10); })
            .filter(function (x) { return !isNaN(x) && x > 0; });
        quizClockStarted = false;
        setScoreDisplay();
        if (totalValue) totalValue.textContent = String(orderedIds.length);
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
                    v: 1,
                    orderedIds: orderedIds,
                    currentIndex: currentIndex,
                    score: score,
                    remainingSeconds: remainingSeconds,
                    durationSeconds: durationSeconds,
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
    let currentIndex = 0;
    let score = 0;
    let locked = false;
    let remainingSeconds = durationSeconds;
    let timerHandle = null;
    let quizClockStarted = false;
    let timerPaused = false;
    /** @type {number[]} */
    let adBreaksSeen = [];

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
        if (totalValue) totalValue.textContent = String(orderedIds.length);
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
            quizStatus.classList.add('border', 'border-red-200', 'bg-red-50', 'text-red-700');
            return;
        }
        if (tone === 'done') {
            quizStatus.classList.add('border', 'border-[#84B8B8]', 'bg-[#84B8B8]/20', 'text-[#2C6A7D]');
            return;
        }
        quizStatus.classList.add('border', 'border-[#84B8B8]', 'bg-[#84B8B8]/20', 'text-[#2C6A7D]');
    }

    function formatClock(seconds) {
        const safe = Math.max(0, seconds);
        const mins = Math.floor(safe / 60);
        const secs = safe % 60;
        return String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
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
        if (durationSeconds <= 0) {
            timerLabel.textContent = 'No limit';
            return;
        }
        timerLabel.textContent = formatClock(durationSeconds);
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

    function triggerCardWrongFeedback() {
        if (!quizCard) return;
        quizCard.classList.remove('quiz-card--wrong');
        void quizCard.offsetWidth;
        quizCard.classList.add('quiz-card--wrong');
        setTimeout(function () {
            quizCard.classList.remove('quiz-card--wrong');
        }, 800);
    }

    function triggerCardCorrectFeedback() {
        if (!quizCard) return;
        quizCard.classList.remove('quiz-card--correct');
        void quizCard.offsetWidth;
        quizCard.classList.add('quiz-card--correct');
        spawnCelebrationShimmer();
        spawnCelebrationSparkles();
        spawnCelebrationEmojis();
        setTimeout(function () {
            quizCard.classList.remove('quiz-card--correct');
        }, 700);
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

    function renderLoading() {
        setStatus('Loading', 'ok');
        questionBox.innerHTML =
            '<div class="animate-pulse space-y-4 text-left">' +
            '<div class="h-6 bg-slate-200 rounded-lg w-3/4"></div>' +
            '<div class="h-12 bg-slate-100 rounded-2xl"></div>' +
            '<div class="h-12 bg-slate-100 rounded-2xl"></div>' +
            '<div class="h-12 bg-slate-100 rounded-2xl"></div>' +
            '</div>';
    }

    function renderFetchError(message, onRetry) {
        questionBox.innerHTML =
            '<p class="text-slate-600 text-left mb-4">' + escapeHtml(message) + '</p>' +
            '<button type="button" id="retryBtn" class="w-full p-4 rounded-2xl bg-[#E50914] text-white font-semibold text-base active:scale-[0.99]">' +
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

    function youtubeEmbedUrl(rawUrl) {
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
            return 'https://www.youtube.com/embed/' + encodeURIComponent(id) + '?rel=0&autoplay=1';
        } catch (e) {
            return '';
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
        var chosen = quizAdVideos[(breakIndex - 1) % quizAdVideos.length] || '';
        var embed = youtubeEmbedUrl(chosen);
        if (!embed) {
            markAdBreakSeen(breakIndex);
            done();
            return;
        }
        var wait = Math.max(1, Math.floor(quizAdWatchSeconds));
        pauseTimer();
        setStatus('Watch required', 'ok');
        questionBox.innerHTML =
            '<div class="space-y-3">' +
            '<p class="text-[11px] font-semibold uppercase tracking-wide text-red-600">Video break</p>' +
            '<h2 class="text-lg font-bold text-slate-900">Watch this video to continue</h2>' +
            '<p class="text-sm text-slate-600">You reached question ' +
            currentIndex +
            '. Continue unlocks in <span id="adCountdown" class="font-bold text-slate-900">' +
            wait +
            's</span>.</p>' +
            '<div class="overflow-hidden rounded-2xl border border-slate-200 bg-black">' +
            '<div class="aspect-video w-full"><iframe class="h-full w-full" src="' +
            escapeAttr(embed) +
            '" title="Quiz ad video" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div>' +
            '</div>' +
            '<button type="button" id="adContinueBtn" disabled class="w-full rounded-2xl bg-slate-300 p-3 text-sm font-bold text-white">Continue in ' +
            wait +
            's</button>' +
            '</div>';
        var countdownEl = document.getElementById('adCountdown');
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
            btn.className = 'w-full rounded-2xl bg-[#E50914] p-3 text-sm font-bold text-white';
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
            return data.ids;
        });
    }

    function loadQuestionById(id) {
        return fetchJson(apiUrl({ quiz_id: String(quizId), id: String(id) })).then(function (data) {
            if (!data.ok || !data.question) {
                throw new Error('bad_response');
            }
            return data.question;
        });
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

    function renderMcqOptions(q) {
        const keys = ['option_a', 'option_b', 'option_c', 'option_d'];
        const parts = [];
        keys.forEach(function (k) {
            const text = q[k];
            if (text && String(text).trim() !== '') {
                const safe = escapeHtml(String(text));
                parts.push(
                    '<button type="button" class="option w-full rounded-2xl border border-slate-200 bg-white p-4 text-left text-base font-medium text-slate-800 transition-all duration-300 hover:bg-slate-200 active:scale-[0.99] disabled:opacity-50" data-option="' +
                        escapeAttr(String(text)) +
                        '">' +
                        safe +
                        '</button>'
                );
            }
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
                checkMcq(btn, selected, q.correct_answer);
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
            'fill-blank-input mx-0.5 my-1 inline-block min-h-[44px] min-w-[6rem] max-w-full flex-1 rounded-xl border-2 border-slate-200 bg-white px-2 py-2 text-center text-base text-slate-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/35 sm:max-w-[16rem]';
        let inner =
            '<div class="mb-6 text-left text-base font-medium leading-relaxed text-slate-900 md:text-lg">';
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
            '<button type="button" id="frSubmit" class="mt-4 w-full min-h-[48px] rounded-2xl bg-[#E50914] p-4 text-base font-semibold text-white active:scale-[0.99] transition-all duration-300">Check answer</button>';
        bindFreeResponseHandlers(q);
    }

    function renderTheoryQuestion(q) {
        const prompt = escapeHtml(String(q.question || ''));
        questionBox.innerHTML =
            '<h2 class="mb-3 text-left text-lg font-bold leading-snug text-slate-900">' +
            prompt +
            '</h2>' +
            '<label for="theoryInput" class="mb-1.5 block text-xs font-medium text-slate-600">Your answer</label>' +
            '<textarea id="theoryInput" rows="3" maxlength="2000" autocomplete="off" ' +
            'class="w-full min-h-[88px] resize-y rounded-xl border-2 border-slate-200 bg-white px-3 py-3 text-base text-slate-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/35" ' +
            'placeholder="Type your answer…"></textarea>' +
            '<button type="button" id="frSubmit" class="mt-4 w-full min-h-[48px] rounded-2xl bg-[#E50914] p-4 text-base font-semibold text-white active:scale-[0.99] transition-all duration-300">Check answer</button>';
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
            '<h2 class="mb-4 text-left text-lg font-bold leading-snug text-slate-900">' +
            escapeHtml(String(q.question)) +
            '</h2>';
        questionBox.innerHTML = title + renderMcqOptions(q);
        bindMcqHandlers(q);
        saveQuizResume();
    }

    function showQuestionAtCurrentIndex() {
        if (orderedIds.length === 0) {
            questionBox.innerHTML = '<p class="text-slate-500">No questions in this quiz yet.</p>';
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
        loadQuestionById(id)
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
        if (normalizedCorrect.indexOf(',') === -1) {
            return userAnswer === normalizedCorrect;
        }

        const answers = String(correct)
            .split(',')
            .map(function (a) { return normalize(a); })
            .filter(Boolean);
        return answers.some(function (a) { return userAnswer === a; });
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
            '<button type="button" id="nextBtn" class="mt-4 w-full rounded-2xl border-2 border-slate-800 bg-slate-900 p-3.5 text-sm font-bold text-white shadow-lg transition hover:bg-slate-800">Continue</button>'
        );
        const nextBtn = document.getElementById('nextBtn');
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                advance();
            });
        }
    }

    function checkMcq(btn, selected, correct) {
        locked = true;
        disableAllOptions();

        const ok = isCorrectAnswer(selected, correct);

        if (ok) {
            btn.className =
                'option w-full rounded-2xl border-2 border-emerald-600 bg-emerald-500 p-4 text-left text-base font-semibold text-white success-pop shadow-sm';
            btn.insertAdjacentHTML('beforeend', ' <span class="inline-block shrink-0" aria-hidden="true">✅</span>');
            score++;
            setScoreDisplay();
            triggerCardCorrectFeedback();
        } else {
            btn.className =
                'option w-full rounded-2xl border-2 border-red-600 bg-red-500 p-4 text-left text-base font-semibold text-white shadow-sm';
            btn.insertAdjacentHTML('beforeend', ' <span class="inline-block shrink-0" aria-hidden="true">❌</span>');
            if (navigator.vibrate) {
                navigator.vibrate(200);
            }
            triggerCardWrongFeedback();
            highlightCorrectMcq(correct);
        }

        renderNextButton();
    }

    function highlightCorrectMcq(correct) {
        document.querySelectorAll('.option').forEach(function (b) {
            const val = b.getAttribute('data-option') || '';
            if (normalize(val) === normalize(correct)) {
                b.className =
                    'option w-full rounded-2xl border-2 border-emerald-600 bg-emerald-500 p-4 text-left text-base font-semibold text-white shadow-sm';
                if (!b.textContent.includes('✅')) {
                    b.insertAdjacentHTML('beforeend', ' <span class="inline-block shrink-0" aria-hidden="true">✅</span>');
                }
            }
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
        let feedbackLine = '';

        function setFreeInputState(v) {
            inputs.forEach(function (inp) {
                inp.classList.remove(
                    'ring-2',
                    'ring-red-500',
                    'border-red-500',
                    'ring-emerald-500',
                    'border-emerald-500',
                    'ring-amber-500',
                    'border-amber-500'
                );
                if (v === 'correct') {
                    inp.classList.add('ring-2', 'ring-emerald-500', 'border-emerald-500');
                } else if (v === 'partial') {
                    inp.classList.add('ring-2', 'ring-amber-500', 'border-amber-500');
                } else {
                    inp.classList.add('ring-2', 'ring-red-500', 'border-red-500');
                }
            });
        }

        if (isTheory) {
            const ev = evaluateTheory(userParts[0] || '', q);
            verdict = ev.verdict;
            if (verdict === 'partial' && ev.missing && ev.missing.length) {
                feedbackLine =
                    '<p class="mt-2 text-sm text-amber-900/90">Missing key ideas: ' +
                    escapeHtml(ev.missing.slice(0, 4).join(', ')) +
                    '.</p>';
            } else if (verdict === 'partial') {
                feedbackLine =
                    '<p class="mt-2 text-sm text-amber-900/90">Close — add a bit more detail.</p>';
            }
        } else {
            const okFill = isFillTheoryCorrect(userParts, q.correct_answer, q.question);
            verdict = okFill ? 'correct' : 'wrong';
        }

        if (verdict === 'correct') {
            setFreeInputState('correct');
            submit.className =
                'mt-4 w-full min-h-[48px] rounded-2xl border-2 border-emerald-600 bg-emerald-500 p-4 text-base font-semibold text-white success-pop shadow-sm';
            submit.innerHTML = 'Correct <span aria-hidden="true">✅</span>';
            score++;
            setScoreDisplay();
            triggerCardCorrectFeedback();
        } else if (verdict === 'partial') {
            setFreeInputState('partial');
            submit.className =
                'mt-4 w-full min-h-[48px] rounded-2xl border-2 border-amber-600 bg-amber-500 p-4 text-base font-semibold text-white shadow-sm';
            submit.innerHTML = 'Partially correct <span aria-hidden="true">◆</span>';
        } else {
            setFreeInputState('wrong');
            submit.className =
                'mt-4 w-full min-h-[48px] rounded-2xl border-2 border-red-600 bg-red-500 p-4 text-base font-semibold text-white shadow-sm';
            submit.innerHTML = 'Wrong <span aria-hidden="true">❌</span>';
            if (navigator.vibrate) {
                navigator.vibrate(200);
            }
            triggerCardWrongFeedback();
        }

        renderNextButton();
        if (feedbackLine) {
            submit.insertAdjacentHTML('afterend', feedbackLine);
        }
    }

    function advance() {
        currentIndex++;
        saveQuizResume();
        showQuestionAtCurrentIndex();
    }

    function endQuiz() {
        clearQuizResumeStorage();
        if (timerHandle) {
            clearInterval(timerHandle);
            timerHandle = null;
        }
        setProgress();
        setStatus('Completed', 'done');
        questionBox.innerHTML =
            '<h2 class="mb-2 text-xl font-bold text-slate-900">Saving…</h2>' +
            '<p class="mb-1 text-2xl font-semibold text-[#2C6A7D]">' +
            score +
            ' <span class="text-lg font-normal text-slate-400">/</span> ' +
            orderedIds.length +
            '</p>' +
            '<p class="text-sm text-slate-500">Taking you to results and rankings.</p>';
        saveScore();
    }

    function saveScore() {
        if (!quizId || orderedIds.length < 1) return;
        var doneUrl = absTrytestPath('?done=' + encodeURIComponent(String(quizId)));
        fetch(absTrytestPath('save_score'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                quiz_id: quizId,
                score: score,
                total: orderedIds.length,
            }),
        })
            .then(function (res) {
                return res.json().catch(function () {
                    return {};
                });
            })
            .finally(function () {
                window.location.href = doneUrl;
            });
    }

    function start() {
        if (!quizId) {
            questionBox.innerHTML = '<p class="text-slate-500">Invalid quiz.</p>';
            return;
        }

        renderLoading();
        progressLabel.textContent = 'Starting…';
        setScoreDisplay();
        setStatus('Starting', 'ok');
        quizClockStarted = false;

        resetPriorAttemptIfNeeded()
            .then(loadQuestionIds)
            .then(function (ids) {
                if (!ids.length) {
                    questionBox.innerHTML = '<p class="text-slate-500">No questions in this quiz yet.</p>';
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
                    progressLabel.textContent = 'Resuming where you left off…';
                    setStatus('In Progress', 'ok');
                    showQuestionAtCurrentIndex();
                    return;
                }
                remainingSeconds = durationSeconds;
                setFrozenTimerLabel();
                orderedIds = shuffleInPlace(ids.slice());
                currentIndex = 0;
                score = 0;
                adBreaksSeen = [];
                setScoreDisplay();
                if (totalValue) totalValue.textContent = String(orderedIds.length);
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
            saveQuizResume();
        }
    });
    window.addEventListener('pagehide', saveQuizResume);

    start();
})();
