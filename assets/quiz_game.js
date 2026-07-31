/**
 * Trytest quiz play layer: streak/combo, blitz rounds, quests, checkpoints, knowledge cards.
 * Official quiz marks stay unchanged; XP and cards are separate engagement rewards.
 */
(function (global) {
    'use strict';

    var CIRC = 2 * Math.PI * 16;
    var BLITZ_EVERY = 5;
    var BLITZ_SECS = 18;
    var CHECKPOINT_EVERY = 5;
    var COMBO_AT = 3;

    /** @type {ReturnType<typeof buildState>|null} */
    var state = null;
    /** @type {ReturnType<typeof setInterval>|null} */
    var blitzTimer = null;
    /** @type {ReturnType<typeof setTimeout>|null} */
    var toastTimer = null;

    function el(id) {
        return document.getElementById(id);
    }

    function buildState(cfg) {
        var owned = {};
        (cfg.ownedCards || []).forEach(function (id) {
            owned[String(id)] = true;
        });
        return {
            enabled: cfg.enabled !== false,
            catalog: Array.isArray(cfg.catalog) ? cfg.catalog : [],
            ownedCards: owned,
            unlockedThisRun: [],
            streak: 0,
            bestStreak: 0,
            correctCount: 0,
            xp: 0,
            answered: 0,
            blitzActive: false,
            blitzWon: false,
            blitzLeft: 0,
            quests: {
                fiveCorrect: { id: 'fiveCorrect', label: 'Get 5 correct', done: false, target: 5 },
                streak3: { id: 'streak3', label: 'Hit a 3-streak', done: false, target: 3 },
                blitzWin: { id: 'blitzWin', label: 'Win a blitz', done: false, target: 1 },
            },
            checkpointPending: false,
            lastComboXp: 0,
        };
    }

    function showToast(title, body) {
        var t = el('ttPlayToast');
        if (!t) return;
        var titleEl = el('ttPlayToastTitle');
        var bodyEl = el('ttPlayToastBody');
        if (titleEl) titleEl.textContent = title;
        if (bodyEl) bodyEl.textContent = body || '';
        t.classList.add('is-show');
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            t.classList.remove('is-show');
        }, 2600);
    }

    function renderQuests() {
        if (!state) return;
        var host = el('ttPlayQuests');
        if (!host) return;
        var order = ['fiveCorrect', 'streak3', 'blitzWin'];
        host.innerHTML = order
            .map(function (key) {
                var q = state.quests[key];
                return (
                    '<span class="tt-play-quest' +
                    (q.done ? ' is-done' : '') +
                    '">' +
                    (q.done ? '✓ ' : '') +
                    q.label +
                    '</span>'
                );
            })
            .join('');
    }

    function renderHud() {
        if (!state) return;
        var fill = el('ttPlayStreakFill');
        var val = el('ttPlayStreakValue');
        var wrap = el('ttPlayStreak');
        var xpEl = el('ttPlayXp');
        var pct = Math.min(100, (state.streak / 8) * 100);
        if (fill) fill.style.width = pct + '%';
        if (val) val.textContent = state.streak > 0 ? state.streak + '×' : '0';
        if (wrap) wrap.classList.toggle('is-hot', state.streak >= COMBO_AT);
        if (xpEl) xpEl.textContent = state.xp > 0 ? state.xp + ' XP this run' : 'Earn XP with streaks & blitz';
        renderQuests();
    }

    function completeQuest(key, xpBonus, toastTitle) {
        if (!state || state.quests[key].done) return;
        state.quests[key].done = true;
        state.xp += xpBonus;
        showToast(toastTitle || 'Quest complete', '+' + xpBonus + ' XP · ' + state.quests[key].label);
        renderHud();
    }

    function stopBlitz() {
        if (blitzTimer) {
            clearInterval(blitzTimer);
            blitzTimer = null;
        }
        if (!state) return;
        state.blitzActive = false;
        var banner = el('ttPlayBlitz');
        if (banner) banner.classList.remove('is-on');
    }

    function startBlitz() {
        if (!state) return;
        stopBlitz();
        state.blitzActive = true;
        state.blitzLeft = BLITZ_SECS;
        var banner = el('ttPlayBlitz');
        var secs = el('ttPlayBlitzSecs');
        var fg = el('ttPlayBlitzFg');
        if (banner) banner.classList.add('is-on');
        if (fg) {
            fg.style.strokeDasharray = String(CIRC);
            fg.style.strokeDashoffset = '0';
        }
        if (secs) secs.textContent = String(BLITZ_SECS);
        blitzTimer = setInterval(function () {
            if (!state || !state.blitzActive) {
                stopBlitz();
                return;
            }
            state.blitzLeft -= 1;
            if (secs) secs.textContent = String(Math.max(0, state.blitzLeft));
            if (fg) {
                var done = (BLITZ_SECS - Math.max(0, state.blitzLeft)) / BLITZ_SECS;
                fg.style.strokeDashoffset = String(CIRC * done);
            }
            if (state.blitzLeft <= 0) {
                stopBlitz();
                showToast('Blitz ended', 'No bonus this round — keep going.');
            }
        }, 1000);
    }

    function pickCard() {
        if (!state || !state.catalog.length) return null;
        for (var i = 0; i < state.catalog.length; i++) {
            var c = state.catalog[i];
            var id = String(c.id || '');
            if (!id || state.ownedCards[id]) continue;
            if (state.unlockedThisRun.indexOf(id) !== -1) continue;
            return c;
        }
        return null;
    }

    function unlockCard(reason) {
        if (!state) return;
        var card = pickCard();
        if (!card) return;
        var id = String(card.id);
        state.ownedCards[id] = true;
        state.unlockedThisRun.push(id);
        state.xp += 8;
        showToast('Card unlocked · ' + String(card.title || 'Knowledge'), String(card.body || reason || ''));
        renderHud();
    }

    function onAnswered(verdict) {
        if (!state || !state.enabled) return;
        var ok = verdict === 'correct' || verdict === 'partial';
        state.answered += 1;
        var wasBlitz = state.blitzActive;
        var blitzOk = wasBlitz && state.blitzLeft > 0;
        stopBlitz();

        if (ok) {
            state.correctCount += 1;
            if (verdict === 'correct') {
                state.streak += 1;
            } else {
                state.streak = Math.max(1, state.streak);
            }
            if (state.streak > state.bestStreak) state.bestStreak = state.streak;

            var comboXp = 0;
            if (state.streak >= COMBO_AT && verdict === 'correct') {
                comboXp = Math.min(5, state.streak - 1);
                state.xp += comboXp;
                state.lastComboXp = comboXp;
                showToast('Combo ×' + state.streak, '+' + comboXp + ' XP streak bonus');
            } else {
                state.lastComboXp = 0;
                state.xp += verdict === 'correct' ? 1 : 0;
            }

            if (blitzOk && verdict === 'correct') {
                state.blitzWon = true;
                state.xp += 5;
                showToast('Blitz win!', '+5 XP — answered in time');
                completeQuest('blitzWin', 6, 'Quest · Win a blitz');
                unlockCard('blitz');
            }

            if (state.streak === 3 || state.streak === 6 || state.streak === 9) {
                unlockCard('streak');
            }

            if (state.correctCount >= 5) {
                completeQuest('fiveCorrect', 5, 'Quest · Get 5 correct');
            }
            if (state.streak >= 3) {
                completeQuest('streak3', 5, 'Quest · Hit a 3-streak');
            }
        } else {
            state.streak = 0;
            state.lastComboXp = 0;
        }

        if (state.answered > 0 && state.answered % CHECKPOINT_EVERY === 0) {
            state.checkpointPending = true;
        }
        renderHud();
    }

    function onQuestionShown(index) {
        if (!state || !state.enabled) return;
        stopBlitz();
        if ((index + 1) % BLITZ_EVERY === 0) {
            startBlitz();
        }
        renderHud();
    }

    function showCheckpoint(score, total, onContinue) {
        var ov = el('ttPlayCheckpoint');
        if (!ov) {
            onContinue();
            return;
        }
        var sScore = el('ttCkScore');
        var sStreak = el('ttCkStreak');
        var sXp = el('ttCkXp');
        if (sScore) sScore.textContent = String(score) + '/' + String(total);
        if (sStreak) sStreak.textContent = String(state ? state.streak : 0);
        if (sXp) sXp.textContent = String(state ? state.xp : 0);
        ov.classList.add('is-on');
        ov.setAttribute('aria-hidden', 'false');
        var btn = el('ttPlayCheckpointBtn');
        function go() {
            ov.classList.remove('is-on');
            ov.setAttribute('aria-hidden', 'true');
            if (btn) btn.removeEventListener('click', go);
            if (state) state.checkpointPending = false;
            onContinue();
        }
        if (btn) {
            btn.addEventListener('click', go);
            btn.focus();
        }
    }

    function beforeAdvance(score, total, proceed) {
        if (!state || !state.enabled || !state.checkpointPending) {
            proceed();
            return;
        }
        showCheckpoint(score, total, proceed);
    }

    function getPayload() {
        if (!state) {
            return { xp_gained: 0, best_streak: 0, cards: [] };
        }
        return {
            xp_gained: state.xp,
            best_streak: state.bestStreak,
            cards: state.unlockedThisRun.slice(),
            quests_done: Object.keys(state.quests).filter(function (k) {
                return state.quests[k].done;
            }),
        };
    }

    function reset(cfg) {
        stopBlitz();
        state = buildState(cfg || {});
        renderHud();
    }

    function init(cfg) {
        reset(cfg || {});
    }

    global.TrytestQuizGame = {
        init: init,
        reset: reset,
        onQuestionShown: onQuestionShown,
        onAnswered: onAnswered,
        beforeAdvance: beforeAdvance,
        getPayload: getPayload,
        stopBlitz: stopBlitz,
    };
})(window);
