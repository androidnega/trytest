(function () {
    const cfg = window.QUIZ_CONFIG || { quizId: 0, durationSeconds: 0 };
    const quizId = cfg.quizId;
    const durationSeconds = Number(cfg.durationSeconds || 0);

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
        timerLabel.textContent = formatClock(remainingSeconds);
        if (timerHandle) clearInterval(timerHandle);
        timerHandle = setInterval(function () {
            remainingSeconds--;
            timerLabel.textContent = formatClock(remainingSeconds);
            if (remainingSeconds <= 0) {
                clearInterval(timerHandle);
                timerHandle = null;
                endQuiz();
            }
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
        spawnCelebrationEmojis();
        setTimeout(function () {
            quizCard.classList.remove('quiz-card--correct');
        }, 700);
    }

    function spawnCelebrationEmojis() {
        if (!quizCard) return;
        var layer = quizCard.querySelector('.quiz-card-emoji-layer');
        if (!layer) {
            layer = document.createElement('div');
            layer.className = 'quiz-card-emoji-layer';
            layer.setAttribute('aria-hidden', 'true');
            quizCard.appendChild(layer);
        }
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

    function bindFillHandlers(q) {
        const input = document.getElementById('fillInput');
        const submit = document.getElementById('fillSubmit');
        if (input) input.focus();

        function onFillSubmit() {
            if (locked) return;
            if (!input || !submit) return;
            checkFill(input, submit, input.value, q.correct_answer);
        }

        if (submit) submit.addEventListener('click', onFillSubmit);
        if (input) {
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') onFillSubmit();
            });
        }
    }

    function renderFillQuestion(q) {
        const questionRaw = String(q.question || '');
        const safeQuestion = escapeHtml(questionRaw);
        const inlineInput =
            '<input type="text" id="fillInput" autocomplete="off" ' +
            'class="inline-block align-middle border-b-2 border-slate-400 bg-white px-2 py-1 mx-1 w-32 text-center text-slate-800 focus:border-emerald-500 focus:outline-none focus:ring-0 transition-all" ' +
            'placeholder="answer">';
        const questionWithBlank = safeQuestion.includes('___')
            ? safeQuestion.replace('___', inlineInput)
            : safeQuestion + ' ' + inlineInput;

        questionBox.innerHTML =
            '<h2 class="mb-6 text-left text-lg font-bold leading-snug text-slate-900">' +
            questionWithBlank +
            '</h2>' +
            '<button type="button" id="fillSubmit" class="w-full rounded-2xl bg-[#E50914] p-4 text-base font-semibold text-white active:scale-[0.99] transition-all duration-300">Check</button>';
        bindFillHandlers(q);
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

        const type = (q.question_type || q.type || 'mcq').toLowerCase();
        const title =
            '<h2 class="mb-4 text-left text-lg font-bold leading-snug text-slate-900">' +
            escapeHtml(String(q.question)) +
            '</h2>';

        if (type === 'fill') {
            renderFillQuestion(q);
            return;
        }

        questionBox.innerHTML = title + renderMcqOptions(q);
        bindMcqHandlers(q);
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

    function checkFill(input, submit, selected, correct) {
        locked = true;
        input.disabled = true;
        submit.disabled = true;

        const ok = isCorrectAnswer(selected, correct);

        if (ok) {
            input.classList.remove('ring-red-500', 'border-red-500');
            input.classList.add('ring-2', 'ring-emerald-500', 'border-emerald-500');
            submit.className =
                'w-full rounded-2xl border-2 border-emerald-600 bg-emerald-500 p-4 text-base font-semibold text-white success-pop shadow-sm';
            submit.innerHTML = 'Correct <span aria-hidden="true">✅</span>';
            score++;
            setScoreDisplay();
            triggerCardCorrectFeedback();
        } else {
            input.classList.add('ring-2', 'ring-red-500', 'border-red-500');
            submit.className =
                'w-full rounded-2xl border-2 border-red-600 bg-red-500 p-4 text-base font-semibold text-white shadow-sm';
            submit.innerHTML = 'Wrong <span aria-hidden="true">❌</span>';
            if (navigator.vibrate) {
                navigator.vibrate(200);
            }
            triggerCardWrongFeedback();
        }

        renderNextButton();
    }

    function advance() {
        currentIndex++;
        showQuestionAtCurrentIndex();
    }

    function endQuiz() {
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
        remainingSeconds = durationSeconds;
        quizClockStarted = false;
        setFrozenTimerLabel();

        loadQuestionIds()
            .then(function (ids) {
                orderedIds = shuffleInPlace(ids.slice());
                currentIndex = 0;
                score = 0;
                setScoreDisplay();
                if (orderedIds.length === 0) {
                    questionBox.innerHTML = '<p class="text-slate-500">No questions in this quiz yet.</p>';
                    progressLabel.textContent = '';
                    if (totalValue) totalValue.textContent = '0';
                    if (progressBar) progressBar.style.width = '0%';
                    setStatus('No Questions', 'error');
                    return;
                }
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
        if (e.key !== 'Enter') return;
        const input = document.getElementById('fillInput');
        const btn = document.getElementById('fillSubmit');
        if (!input || !btn) return;
        e.preventDefault();
        btn.click();
    });

    start();
})();
