(function () {
    const cfg = window.QUIZ_CONFIG || { quizId: 0, durationSeconds: 0 };
    const quizId = cfg.quizId;
    const durationSeconds = Number(cfg.durationSeconds || 0);

    const questionBox = document.getElementById('questionBox');
    const progressLabel = document.getElementById('progressLabel');
    const wrongFlash = document.getElementById('wrongFlash');
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
        return '/trytest/get_question?' + q.toString();
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

    function triggerWrongFlash() {
        if (!wrongFlash) return;
        wrongFlash.classList.remove('hidden');
        wrongFlash.style.animation = 'none';
        void wrongFlash.offsetWidth;
        wrongFlash.style.animation = 'wrong-flash 0.35s ease-out';
        setTimeout(function () {
            wrongFlash.classList.add('hidden');
            wrongFlash.style.animation = 'none';
        }, 400);
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
            'class="inline-block align-middle border-b-2 border-slate-400 bg-white px-2 py-1 mx-1 w-32 text-center text-slate-800 focus:border-[#2C6A7D] focus:outline-none transition-all" ' +
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
            '<button type="button" id="nextBtn" class="mt-4 w-full rounded-2xl bg-[#2C6A7D] p-3.5 text-sm font-bold text-white shadow-lg">Continue</button>'
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
                'option w-full rounded-2xl border border-[#2C6A7D] bg-[#2C6A7D] p-4 text-left text-base font-semibold text-white success-pop';
            btn.insertAdjacentHTML('beforeend', ' <span class="inline-block" aria-hidden="true">✓</span>');
            score++;
            setScoreDisplay();
        } else {
            btn.className =
                'option w-full rounded-2xl border border-[#E50914] bg-[#E50914] p-4 text-left text-base font-semibold text-white';
            if (navigator.vibrate) {
                navigator.vibrate(200);
            }
            triggerWrongFlash();
            highlightCorrectMcq(correct);
        }

        renderNextButton();
    }

    function highlightCorrectMcq(correct) {
        document.querySelectorAll('.option').forEach(function (b) {
            const val = b.getAttribute('data-option') || '';
            if (normalize(val) === normalize(correct)) {
                b.className =
                    'option w-full rounded-2xl border border-[#2C6A7D] bg-[#2C6A7D] p-4 text-left text-base font-semibold text-white';
            }
        });
    }

    function checkFill(input, submit, selected, correct) {
        locked = true;
        input.disabled = true;
        submit.disabled = true;

        const ok = isCorrectAnswer(selected, correct);

        if (ok) {
            input.classList.add('ring-2', 'ring-[#2C6A7D]', 'border-[#2C6A7D]');
            submit.className =
                'w-full rounded-2xl bg-[#2C6A7D] p-4 text-base font-semibold text-white success-pop';
            submit.textContent = 'Correct ✓';
            score++;
            setScoreDisplay();
        } else {
            input.classList.add('ring-2', 'ring-[#E50914]', 'border-[#E50914]');
            submit.className = 'w-full rounded-2xl bg-[#E50914] p-4 text-base font-semibold text-white';
            submit.textContent = 'Wrong';
            if (navigator.vibrate) {
                navigator.vibrate(200);
            }
            triggerWrongFlash();
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
        var doneUrl = '/trytest/dashboard/?done=' + encodeURIComponent(String(quizId));
        fetch('/trytest/save_score', {
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
        startTimer();

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
