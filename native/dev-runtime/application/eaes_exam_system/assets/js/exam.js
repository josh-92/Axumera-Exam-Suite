(function () {
    'use strict';

    const cfg = window.EAES_EXAM;
    if (!cfg) return;

    const examData = cfg.examData;
    let totalSecondsLeft = cfg.secondsLeft;
    let currentIndex = 0;
    let studentAnswers = Object.assign({}, cfg.savedAnswers || {});
    let flaggedQuestions = Object.assign({}, cfg.savedFlags || {});
    let timerIsHidden = false;
    let dirty = false;

    // Escape teacher-authored text before it touches innerHTML. Question
    // files can be imported from third parties, so <img onerror=…> and
    // friends must render as text, not execute. MathJax ($…$) still works
    // because it scans text nodes. See the security audit for the full write-up.
    function esc(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    const questionText = document.getElementById("question-text");
    const paragraphContainer = document.getElementById("paragraph-box-container");
    const optionsContainer = document.getElementById("options-container");
    const currentQLabel = document.getElementById("current-q-num-label");
    const statusText = document.getElementById("status-text");
    const overviewGrid = document.getElementById("overview-grid");
    const flagBtn = document.getElementById("flag-btn");
    const clearBtn = document.getElementById("clear-btn");
    const timerBox = document.getElementById("timer-box-display");
    const timerDigits = document.getElementById("timer-digits");
    const timerToggleBtn = document.getElementById("timer-toggle-btn");
    const nextBtn = document.getElementById("next-btn");
    const prevBtn = document.getElementById("prev-btn");
    const finishBtn = document.getElementById("finish-attempt-sidebar-btn");
    const autosaveIndicator = document.getElementById("autosave-indicator");

    let qCounter = 1;
    examData.forEach(q => { if (!q.is_passage) q.display_number = qCounter++; });

    function initExam() {
        if (examData.length > 0) {
            renderOverviewGrid();
            loadQuestion(currentIndex);
            startCountdownEngine();
            startAutosaveEngine();
        }
    }

    function startCountdownEngine() {
        renderTimerStringDisplay();
        const countdownInterval = setInterval(() => {
            if (totalSecondsLeft <= 0) {
                clearInterval(countdownInterval);
                executeAutoSubmitRoute();
            } else {
                totalSecondsLeft--;
                renderTimerStringDisplay();
            }
        }, 1000);
    }

    function renderTimerStringDisplay() {
        let hours = Math.floor(totalSecondsLeft / 3600);
        let minutes = Math.floor((totalSecondsLeft % 3600) / 60);
        let seconds = totalSecondsLeft % 60;
        let formatted = (hours < 10 ? "0" + hours : hours) + ":" + (minutes < 10 ? "0" + minutes : minutes) + ":" + (seconds < 10 ? "0" + seconds : seconds);
        if (!timerIsHidden) timerDigits.textContent = formatted;
        timerBox.classList.toggle('low-time', totalSecondsLeft <= 60);
    }

    timerToggleBtn.addEventListener('click', () => {
        timerIsHidden = !timerIsHidden;
        timerToggleBtn.textContent = timerIsHidden ? "Show" : "Hide";
        timerDigits.textContent = timerIsHidden ? "⏱️ Hidden" : "";
        if (!timerIsHidden) renderTimerStringDisplay();
    });

    function loadQuestion(index) {
        const q = examData[index];

        if (q.is_passage) {
            currentQLabel.textContent = q.roman_id;
            questionText.innerHTML = "<strong>Reading Passage:</strong><br><br>" + escapeAndBreak(q.text);
            optionsContainer.innerHTML = "";
            statusText.textContent = "Reading Material";
            flagBtn.style.display = "none";
            clearBtn.style.display = "none";
            paragraphContainer.style.display = "none";
        } else {
            currentQLabel.textContent = q.display_number;
            questionText.innerHTML = esc(q.text);

            if (q.paragraph && q.paragraph.trim() !== "") {
                paragraphContainer.innerHTML = "<strong>Information:</strong><br>" + esc(q.paragraph);
                paragraphContainer.style.display = "block";
            } else {
                paragraphContainer.style.display = "none";
            }

            statusText.textContent = studentAnswers[q.id] ? "Answered" : "Not yet answered";
            flagBtn.style.display = "block";
            clearBtn.style.display = "block";

            optionsContainer.innerHTML = "";
            // Render in this student's shuffled optionOrder (falls back to the
            // natural a,b,c,d order for non-shuffled exams or older cached
            // pages). The radio's VALUE — what actually gets stored in
            // studentAnswers and later autosaved/graded — is always the
            // ORIGINAL option letter, never the shuffled display position.
            // This is what keeps grading, autosave, and review all working
            // without any changes: they only ever see original letters.
            const order = (q.optionOrder && q.optionOrder.length) ? q.optionOrder : Object.keys(q.options);
            const displayLetters = ['A', 'B', 'C', 'D'];
            order.forEach((key, i) => {
                const value = q.options[key];
                if (!value || value === 'N/A') return;
                const label = document.createElement("label");
                label.className = "option-item";
                const isChecked = studentAnswers[q.id] === key;
                const input = document.createElement('input');
                input.type = 'radio';
                input.name = 'q-option';
                input.value = key;
                input.checked = isChecked;
                input.addEventListener('change', () => selectAnswer(key));
                const span = document.createElement('span');
                span.innerHTML = (displayLetters[i] || key.toUpperCase()) + '. ' + esc(value);
                label.appendChild(input);
                label.appendChild(span);
                optionsContainer.appendChild(label);
            });
        }

        document.querySelectorAll(".q-box").forEach(b => b.classList.remove("active"));
        const currentBox = document.getElementById(`q-box-${index}`);
        if (currentBox) currentBox.classList.add("active");

        flagBtn.textContent = flaggedQuestions[q.id] ? "🚩 Remove flag" : "🚩 Flag question";
        nextBtn.style.display = (index === examData.length - 1) ? "none" : "block";

        if (typeof MathJax !== 'undefined' && MathJax.typesetPromise) MathJax.typesetPromise();
    }

    function escapeAndBreak(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML.replace(/\n\n/g, '<br><br>');
    }

    function selectAnswer(selectedOption) {
        const q = examData[currentIndex];
        studentAnswers[q.id] = selectedOption;
        statusText.textContent = "Answered";
        const currentBox = document.getElementById(`q-box-${currentIndex}`);
        if (currentBox) currentBox.classList.add("answered");
        markDirty();
    }

    clearBtn.addEventListener('click', () => {
        const q = examData[currentIndex];
        if (studentAnswers[q.id]) {
            delete studentAnswers[q.id];
            statusText.textContent = "Not yet answered";
            document.getElementsByName("q-option").forEach(r => r.checked = false);
            const currentBox = document.getElementById(`q-box-${currentIndex}`);
            if (currentBox) currentBox.classList.remove("answered");
            markDirty();
        }
    });

    flagBtn.addEventListener('click', () => {
        const q = examData[currentIndex];
        const box = document.getElementById(`q-box-${currentIndex}`);
        if (flaggedQuestions[q.id]) {
            delete flaggedQuestions[q.id];
            if (box) box.classList.remove("flagged");
            flagBtn.textContent = "🚩 Flag question";
        } else {
            flaggedQuestions[q.id] = true;
            if (box) box.classList.add("flagged");
            flagBtn.textContent = "🚩 Remove flag";
        }
        markDirty();
    });

    function renderOverviewGrid() {
        overviewGrid.innerHTML = "";
        examData.forEach((q, index) => {
            const box = document.createElement("div");
            box.className = "q-box";
            box.id = `q-box-${index}`;

            if (q.is_passage) {
                box.innerHTML = `<span>${esc(q.roman_id)}</span>`;
                box.style.backgroundColor = "#f1f3f4";
                box.style.border = "1px solid #1967d2";
                box.style.color = "#1967d2";
                box.style.fontWeight = "bold";
            } else {
                box.innerHTML = `<span>${q.display_number}</span>`;
                if (studentAnswers[q.id]) box.classList.add("answered");
                if (flaggedQuestions[q.id]) box.classList.add("flagged");
            }

            box.addEventListener("click", () => { currentIndex = index; loadQuestion(currentIndex); });
            overviewGrid.appendChild(box);
        });
    }

    nextBtn.addEventListener('click', () => { if (currentIndex < examData.length - 1) { currentIndex++; loadQuestion(currentIndex); } });
    prevBtn.addEventListener('click', () => { if (currentIndex > 0) { currentIndex--; loadQuestion(currentIndex); } });

    function markDirty() {
        dirty = true;
        // Re-render the overview dot for the current question immediately.
        const box = document.getElementById(`q-box-${currentIndex}`);
        if (box) {
            const q = examData[currentIndex];
            box.classList.toggle('answered', !!studentAnswers[q.id]);
        }
    }

    // ---- Native shell messaging (Phase 4, optional) ----
    // The native Axumera Student client (WebView2) listens for lifecycle
    // messages. Every call is guarded: in a normal browser these are no-ops
    // and the exam behaves exactly as before.
    function postToNative(message) {
        try {
            if (window.chrome && window.chrome.webview && window.chrome.webview.postMessage) {
                window.chrome.webview.postMessage(message);
            }
        } catch (e) { /* non-fatal */ }
    }

    // ---- Server autosave (source of truth; replaces old localStorage-only approach) ----
    function startAutosaveEngine() {
        setInterval(sendAutosave, cfg.autosaveIntervalMs || 15000);
        window.addEventListener('beforeunload', () => { if (dirty) sendAutosaveBeacon(); });
    }

    function sendAutosave() {
        if (!dirty) return;
        setIndicator('saving');
        fetch(cfg.autosaveUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrfToken },
            body: JSON.stringify({ answers: studentAnswers, flags: flaggedQuestions, csrf_token: cfg.csrfToken })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                dirty = false;
                setIndicator('saved');
                if (typeof data.seconds_remaining === 'number') {
                    // Resync with the server clock periodically to correct drift.
                    totalSecondsLeft = data.seconds_remaining;
                }
            } else if (data.status === 'expired') {
                // Time is up server-side; the attempt was finalized there.
                // End the session and let the result page render.
                dirty = false;
                window.location.href = cfg.reviewUrl + '?autosubmit=true';
            } else {
                setIndicator('error');
            }
        })
        .catch(() => setIndicator('error'));
    }

    function sendAutosaveBeacon() {
        if (navigator.sendBeacon) {
            const blob = new Blob([JSON.stringify({ answers: studentAnswers, flags: flaggedQuestions, csrf_token: cfg.csrfToken })], { type: 'application/json' });
            navigator.sendBeacon(cfg.autosaveUrl, blob);
        }
    }

    function setIndicator(state) {
        if (!autosaveIndicator) return;
        autosaveIndicator.classList.remove('saving', 'error');
        if (state === 'saving') {
            autosaveIndicator.textContent = '💾 Saving…';
            autosaveIndicator.classList.add('saving');
        } else if (state === 'error') {
            autosaveIndicator.textContent = '⚠️ Save failed';
            autosaveIndicator.classList.add('error');
        } else {
            autosaveIndicator.textContent = '💾 Saved';
        }
    }

    function loadReviewSummaryPage() {
        dirty = true; // force a final flush even if nothing changed since the last tick
        finishBtn.disabled = true;
        finishBtn.textContent = "Saving…";
        // The review page is still part of the active attempt; the native
        // shell keeps kiosk mode locked until the server finalizes it.
        postToNative({ type: 'exam-ended', payload: { schemaVersion: 1, reason: 'review' } });
        forceFinalAutosave().finally(() => { window.location.href = cfg.reviewUrl; });
    }

    function executeAutoSubmitRoute() {
        postToNative({ type: 'exam-ended', payload: { schemaVersion: 1, reason: 'autosubmit' } });
        forceFinalAutosave().finally(() => { window.location.href = cfg.reviewUrl + "?autosubmit=true"; });
    }

    function forceFinalAutosave() {
        return fetch(cfg.autosaveUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrfToken },
            body: JSON.stringify({ answers: studentAnswers, flags: flaggedQuestions, csrf_token: cfg.csrfToken })
        }).catch(() => {});
    }

    finishBtn.addEventListener('click', loadReviewSummaryPage);

    // ---- Exam integrity / lockdown ----
    // Best-effort deterrents, not unbreakable DRM: a determined student with
    // a second device can still cheat. The point is to (a) discourage casual
    // cheating, (b) create a server-side audit trail a teacher can review,
    // and (c) optionally end the attempt automatically past a threshold the
    // school configures. Every violation is confirmed and counted server-side
    // (report_violation.php), never trusted from the client alone.
    function initIntegrity() {
        const integrityCfg = cfg.integrity || { enabled: false };
        const gateOverlay = document.getElementById('integrity-gate-overlay');
        const gateStartBtn = document.getElementById('integrity-gate-start-btn');
        const warningOverlay = document.getElementById('integrity-warning-overlay');
        const warningText = document.getElementById('integrity-warning-text');
        const warningResumeBtn = document.getElementById('integrity-warning-resume-btn');
        const indicator = document.getElementById('integrity-indicator');

        if (!integrityCfg.enabled) {
            document.body.classList.remove('exam-gated');
            if (gateOverlay) gateOverlay.style.display = 'none';
            return;
        }

        let violationCount = integrityCfg.startingViolationCount || 0;
        let armed = false;
        const lastReportAt = {}; // per-event-type throttle, ms epoch
        const REPORT_THROTTLE_MS = 3000;

        function requestFullscreen() {
            const el = document.documentElement;
            const req = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
            if (req) { try { req.call(el); } catch (e) { /* denied or unsupported — proceed anyway */ } }
        }

        function isFullscreen() {
            return !!(document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement);
        }

        function updateIndicator() {
            if (!indicator) return;
            indicator.style.display = 'inline-block';
            indicator.textContent = violationCount > 0 ? `🛡️ Monitored (${violationCount})` : '🛡️ Monitored';
        }

        function showWarning(message) {
            if (!warningOverlay) return;
            if (warningText) warningText.textContent = message;
            warningOverlay.style.display = 'flex';
        }

        function hideWarning() {
            if (warningOverlay) warningOverlay.style.display = 'none';
        }

        const WARNING_MESSAGES = {
            tab_hidden: 'You switched away from the exam tab. This has been recorded.',
            window_blur: 'You left the exam window. This has been recorded.',
            fullscreen_exit: 'You exited fullscreen. This has been recorded — please return to fullscreen to continue.'
        };

        function reportViolation(eventType, showsWarning) {
            const now = Date.now();
            if (lastReportAt[eventType] && now - lastReportAt[eventType] < REPORT_THROTTLE_MS) return;
            lastReportAt[eventType] = now;

            fetch(integrityCfg.reportUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrfToken },
                body: JSON.stringify({ event: eventType, csrf_token: cfg.csrfToken })
            })
            .then(res => res.json())
            .then(data => {
                if (data.status !== 'success') return;
                violationCount = data.violation_count;
                updateIndicator();
                if (indicator) indicator.classList.toggle('flagged', !!data.flagged);

                if (data.auto_submit) {
                    forceFinalAutosave().finally(() => {
                        window.location.href = cfg.reviewUrl + '?autosubmit=true';
                    });
                    return;
                }

                if (showsWarning && violationCount >= (data.warn_threshold || 1)) {
                    showWarning(WARNING_MESSAGES[eventType] || 'A monitored event was recorded.');
                }
            })
            .catch(() => { /* best-effort; do not block the student's exam on a network hiccup */ });
        }

        function armListeners() {
            if (armed) return;
            armed = true;

            // Same-window navigation (Finish Attempt -> review -> Back To Exam) tears
            // the document down and fires blur/visibility/fullscreenchange events.
            // Those are normal exam-workflow navigation, NOT integrity events, so any
            // event arriving while a navigation is in progress (plus a short grace
            // window for teardown event-order races) is ignored. Real integrity events
            // (alt-tab, window switch, fullscreen exit while staying on the page) still
            // fire because no navigation is in progress.
            let navigatingAway = false;
            let navigationMarkedAt = 0;
            const markNavigation = () => { navigatingAway = true; navigationMarkedAt = Date.now(); };
            const isNavigatingAway = () => navigatingAway || (Date.now() - navigationMarkedAt) < 800;
            window.addEventListener('beforeunload', markNavigation);
            window.addEventListener('pagehide', markNavigation);
            window.addEventListener('pageshow', () => { navigatingAway = false; });

            document.addEventListener('visibilitychange', () => {
                if (document.hidden && !isNavigatingAway()) reportViolation('tab_hidden', true);
            });

            window.addEventListener('blur', () => {
                // Skip the redundant report if visibilitychange already caught this
                // same switch a moment ago (alt-tab fires both in most browsers).
                if (document.hidden || isNavigatingAway()) return;
                reportViolation('window_blur', true);
            });

            const fullscreenChangeHandler = () => {
                if (isNavigatingAway()) return;
                if (!isFullscreen()) reportViolation('fullscreen_exit', true);
                else hideWarning();
            };
            document.addEventListener('fullscreenchange', fullscreenChangeHandler);
            document.addEventListener('webkitfullscreenchange', fullscreenChangeHandler);
            document.addEventListener('msfullscreenchange', fullscreenChangeHandler);

            document.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                reportViolation('context_menu_attempt', false);
            });

            ['copy', 'cut'].forEach(evt => document.addEventListener(evt, (e) => {
                e.preventDefault();
                reportViolation('copy_attempt', false);
            }));
            document.addEventListener('paste', (e) => {
                e.preventDefault();
                reportViolation('paste_attempt', false);
            });

            document.addEventListener('keydown', (e) => {
                const key = (e.key || '').toLowerCase();
                const blocksDevtools =
                    key === 'f12' ||
                    ((e.ctrlKey || e.metaKey) && e.shiftKey && ['i', 'j', 'c'].includes(key)) ||
                    ((e.ctrlKey || e.metaKey) && key === 'u');
                if (blocksDevtools) {
                    e.preventDefault();
                    reportViolation('devtools_shortcut_attempt', false);
                }
            });
        }

        // ---- Phase 4: native shell → page requests (WebView2 only) ----
        // The native layer never reports integrity events itself. It asks the
        // page, which reuses this page's CSRF token and the exact same
        // report_violation.php pipeline, so every violation is counted exactly
        // once, server-side, with unchanged thresholds.
        let exitHandling = false;

        function reportControlledExit() {
            const now = Date.now();
            if (lastReportAt['controlled_exit'] && now - lastReportAt['controlled_exit'] < REPORT_THROTTLE_MS) {
                return Promise.resolve(false);
            }
            lastReportAt['controlled_exit'] = now;
            return fetch(integrityCfg.reportUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrfToken },
                body: JSON.stringify({ event: 'controlled_exit', csrf_token: cfg.csrfToken })
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    violationCount = data.violation_count;
                    updateIndicator();
                    if (indicator) indicator.classList.toggle('flagged', !!data.flagged);
                    if (data.auto_submit) {
                        // The server decided this exit crosses the configured
                        // threshold — its rule wins; route to auto-submit.
                        forceFinalAutosave().finally(() => { window.location.href = cfg.reviewUrl + '?autosubmit=true'; });
                    }
                }
                return data.status === 'success';
            })
            .catch(() => false);
        }

        function handleNativeExitExam() {
            if (exitHandling) return;
            exitHandling = true;
            Promise.all([reportControlledExit(), forceFinalAutosave()]).finally(() => {
                postToNative({ type: 'exit-exam-ack', payload: { schemaVersion: 1 } });
                postToNative({ type: 'exam-ended', payload: { schemaVersion: 1, reason: 'controlled-exit' } });
                window.location.href = cfg.reviewUrl;
            });
        }

        function onNativeMessage(event) {
            const data = event && event.data;
            if (!data || typeof data !== 'object' || !data.type) return;
            if (data.type === 'exit-exam') handleNativeExitExam();
        }

        try {
            if (window.chrome && window.chrome.webview && window.chrome.webview.addEventListener) {
                window.chrome.webview.addEventListener('message', onNativeMessage);
            }
        } catch (e) { /* WebView2 unavailable — the native shell has its own fallback */ }

        if (gateStartBtn) {
            gateStartBtn.addEventListener('click', () => {
                requestFullscreen();
                document.body.classList.remove('exam-gated');
                if (gateOverlay) gateOverlay.style.display = 'none';
                updateIndicator();
                armListeners();
                postToNative({ type: 'exam-started', payload: { schemaVersion: 1 } });
            });
        }

        if (warningResumeBtn) {
            warningResumeBtn.addEventListener('click', () => {
                if (!isFullscreen()) requestFullscreen();
                hideWarning();
            });
        }
    }

    initExam();
    initIntegrity();
})();
