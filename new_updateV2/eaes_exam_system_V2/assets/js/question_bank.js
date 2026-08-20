/* =========================================================================
   EAES Question Bank — front-end controller
   Talks to api_questions.php (JSON), renders into admin_question_bank.php.
   ========================================================================= */

(() => {
    'use strict';

    const API = 'api_questions.php';
    const PER_PAGE = 15;

    const state = {
        filters: { search: '', subject: '', grade: '', difficulty: '', type: '', date_from: '', date_to: '', status: 'active', mine: false },
        page: 1,
        totalPages: 0,
        total: 0,
        rows: [],
        selection: new Set(),
        facets: { subjects: [], grades: [], difficulties: [], types: [] },
        exams: [],
        pendingAssignIds: [],
        parsedQuestions: [],
        assignMode: 'existing', // 'existing' | 'new'
        confirmResolver: null,
    };

    // ---------------------------------------------------------------- api

    async function api(action, opts = {}) {
        const { method = 'GET', body, query = {} } = opts;
        const params = new URLSearchParams({ action, ...query });
        const headers = {};
        let payload;
        if (body instanceof FormData) {
            if (method === 'POST' && !body.has('csrf_token')) body.append('csrf_token', window.EAES_CSRF);
            payload = body;
        } else if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
            headers['X-CSRF-Token'] = window.EAES_CSRF;
            payload = JSON.stringify(body);
        }
        let res;
        try {
            res = await fetch(`${API}?${params}`, { method, headers, body: payload });
        } catch (e) {
            throw new Error('Network error — please check your connection and try again.');
        }
        if (res.status === 419) {
            toast('Session expired — refreshing…', 'warning');
            setTimeout(() => location.reload(), 1200);
            throw new Error('Session expired');
        }
        // Read as text first: the app's DB-failure handler emits an HTML
        // "Service Unavailable" page (HTTP 503), and Apache can serve plain
        // error pages too — parsing those as JSON only produces a confusing
        // "Unexpected token '<'" crash. Fail gracefully with a real message.
        const text = await res.text();
        const contentType = res.headers.get('content-type') || '';
        let data = null;
        if (contentType.includes('application/json')) {
            try {
                data = JSON.parse(text);
            } catch (e) {
                data = null; // HTML body served under a JSON content-type
            }
        }
        if (data === null || typeof data !== 'object') {
            if (res.status === 503) {
                throw new Error('The server could not reach its database (HTTP 503). Make sure MySQL is running, then try again.');
            }
            throw new Error(`The server returned an unexpected response (HTTP ${res.status}). Please try again.`);
        }
        if (!res.ok || data.success === false) {
            throw new Error(data.error || `Request failed (HTTP ${res.status}).`);
        }
        return data;
    }

    // ------------------------------------------------------------- helpers

    const $ = (id) => document.getElementById(id);

    function esc(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmtDate(value) {
        if (!value) return '—';
        const s = String(value).split(' ')[0]; // YYYY-MM-DD HH:MM:SS → YYYY-MM-DD
        return s || '—';
    }

    function questionText(row) {
        return row.question ?? row.question_text ?? '';
    }

    const TYPE_STYLES = { 'MCQ': 'qb-type-mcq', 'True/False': 'qb-type-tf', 'Essay': 'qb-type-essay' };
    const DIFF_STYLES = { easy: 'qb-diff-easy', medium: 'qb-diff-medium', hard: 'qb-diff-hard' };

    function typeBadge(type) {
        return `<span class="qb-badge ${TYPE_STYLES[type] || 'qb-type-mcq'}">${esc(type || 'MCQ')}</span>`;
    }

    function difficultyBadge(difficulty) {
        if (!difficulty) return '<span class="text-muted">—</span>';
        const label = difficulty.charAt(0).toUpperCase() + difficulty.slice(1);
        return `<span class="qb-badge ${DIFF_STYLES[difficulty] || ''}">${esc(label)}</span>`;
    }

    function toast(message, type = 'success', ms = 4200) {
        const box = $('qb-toasts');
        const el = document.createElement('div');
        el.className = `qb-toast qb-toast-${type}`;
        el.textContent = message;
        box.appendChild(el);
        setTimeout(() => {
            el.classList.add('qb-toast-out');
            setTimeout(() => el.remove(), 250);
        }, ms);
    }

    function setLoading(on) {
        $('qb-loading').hidden = !on;
        $('qb-table').style.opacity = on ? '0.4' : '1';
    }

    function currentQuery() {
        const q = {};
        Object.entries(state.filters).forEach(([k, v]) => {
            if (v !== '' && v !== false) q[k] = v;
        });
        if (state.filters.mine) q.mine = '1';
        return q;
    }

    // ------------------------------------------------------------- listing

    async function loadList() {
        setLoading(true);
        try {
            const data = await api('list', {
                query: { ...currentQuery(), page: state.page, per_page: PER_PAGE },
            });
            state.rows = data.rows;
            state.total = data.total;
            state.totalPages = data.total_pages;
            renderTable();
            renderPagination();
        } catch (e) {
            state.rows = [];
            renderTable();
            toast(e.message, 'error');
        } finally {
            setLoading(false);
        }
    }

    async function loadFacets() {
        try {
            const data = await api('facets');
            state.facets = data.facets;
            renderFacetSelects();
        } catch (e) { /* non-fatal */ }
    }

    async function loadExams() {
        try {
            const data = await api('exams');
            state.exams = data.exams;
            renderExamSelects();
        } catch (e) {
            toast('Could not load exams: ' + e.message, 'error');
        }
    }

    function renderFacetSelects() {
        fillSelect('qb-subject', state.facets.subjects, 'All subjects', state.filters.subject);
        fillSelect('qb-grade', state.facets.grades, 'All grades', state.filters.grade);
    }

    function fillSelect(id, values, placeholder, selected) {
        const sel = $(id);
        const options = [`<option value="">${esc(placeholder)}</option>`]
            .concat(values.map((v) => `<option value="${esc(v)}" ${v === selected ? 'selected' : ''}>${esc(v)}</option>`));
        sel.innerHTML = options.join('');
    }

    function renderTable() {
        const tbody = $('qb-tbody');
        const rows = state.rows;

        if (rows.length === 0) {
            tbody.innerHTML = `<tr><td colspan="9" class="qb-empty">
                ${state.filters.status === 'archived'
                    ? 'No archived questions match these filters.'
                    : 'No questions yet. Click “New Question” or import a CSV/JSON file to get started.'}
            </td></tr>`;
            $('qb-select-all').checked = false;
            updateBulkBar();
            return;
        }

        tbody.innerHTML = rows.map((q) => {
            const id = Number(q.id);
            const archived = !!q.archived_at;
            const checked = state.selection.has(id) ? 'checked' : '';
            const used = Number(q.assign_count || 0);
            return `<tr data-id="${id}" class="${checked ? 'is-selected' : ''}">
                <td><input type="checkbox" class="qb-row-check" data-id="${id}" ${checked}></td>
                <td>
                    <div class="qb-question-cell">
                        <span class="qb-question-text">${esc(questionText(q))}</span>
                        ${q.tags ? `<span class="qb-tags">${esc(q.tags)}</span>` : ''}
                    </div>
                </td>
                <td>${typeBadge(q.type)}</td>
                <td>${difficultyBadge(q.difficulty)}${archived ? '<br><span class="qb-archived-pill">Archived</span>' : ''}</td>
                <td>${esc(q.subject || '—')}</td>
                <td>${esc(q.grade || '—')}</td>
                <td class="qb-date-cell">
                    <span>${esc(fmtDate(q.created_at))}</span>
                    ${q.created_by_name ? `<span class="qb-created-by">${esc(q.created_by_name)}</span>` : ''}
                </td>
                <td>${used > 0 ? `<span class="qb-used">${used}</span>` : '<span class="text-muted">—</span>'}</td>
                <td class="qb-col-actions">
                    <button type="button" class="qb-icon-btn" data-action="preview" title="Preview">👁</button>
                    <button type="button" class="qb-icon-btn" data-action="edit" title="Edit">✏️</button>
                    <button type="button" class="qb-icon-btn" data-action="assign" title="Assign to exam">📎</button>
                    ${archived
                        ? `<button type="button" class="qb-icon-btn" data-action="restore" title="Restore">♻️</button>`
                        : `<button type="button" class="qb-icon-btn qb-danger" data-action="archive" title="Archive">🗑</button>`}
                </td>
            </tr>`;
        }).join('');

        // Page-level select-all reflects current page
        const pageIds = rows.map((q) => Number(q.id));
        $('qb-select-all').checked = pageIds.length > 0 && pageIds.every((id) => state.selection.has(id));
        updateBulkBar();
    }

    function renderPagination() {
        $('qb-page-info').textContent = state.total === 0
            ? 'No questions'
            : `Showing ${(state.page - 1) * PER_PAGE + 1}–${Math.min(state.page * PER_PAGE, state.total)} of ${state.total}`;

        const pages = [];
        const total = state.totalPages;
        const cur = state.page;
        if (total <= 1) {
            // no page numbers needed
        } else {
            const start = Math.max(1, Math.min(cur - 2, total - 4));
            const end = Math.min(total, start + 4);
            for (let p = start; p <= end; p++) {
                pages.push(`<button type="button" class="qb-page-btn ${p === cur ? 'is-active' : ''}" data-page="${p}">${p}</button>`);
            }
        }
        $('qb-page-numbers').innerHTML = pages.join('');
        $('qb-page-prev').disabled = cur <= 1;
        $('qb-page-next').disabled = cur >= total;
    }

    // ------------------------------------------------------------- selection

    function updateBulkBar() {
        const n = state.selection.size;
        const bar = $('qb-bulk-bar');
        bar.hidden = n === 0;
        $('qb-bulk-count').textContent = `${n} selected`;
        document.querySelectorAll('.qb-row-check').forEach((cb) => {
            cb.checked = state.selection.has(Number(cb.dataset.id));
        });
    }

    // ------------------------------------------------------------- editor

    function openEditor(id = null) {
        $('qf-id').value = id || '';
        $('qb-editor-title').textContent = id ? 'Edit Question' : 'New Question';
        if (id) {
            api('show', { query: { id } }).then((data) => {
                const q = data.question;
                $('qf-question').value = questionText(q);
                $('qf-type').value = q.type || 'MCQ';
                $('qf-difficulty').value = q.difficulty || '';
                $('qf-subject').value = q.subject || '';
                $('qf-grade').value = q.grade || '';
                $('qf-topic').value = q.topic || '';
                $('qf-tags').value = q.tags || '';
                $('qf-public').checked = Number(q.is_public) === 1;
                renderOptions(q);
                openModal('qb-editor-modal');
            }).catch((e) => toast(e.message, 'error'));
        } else {
            ['qf-question', 'qf-subject', 'qf-grade', 'qf-topic', 'qf-tags'].forEach((id) => $(id).value = '');
            $('qf-difficulty').value = '';
            $('qf-public').checked = true;
            renderOptions(null);
            openModal('qb-editor-modal');
            setTimeout(() => $('qf-question').focus(), 50);
        }
    }

    function renderOptions(q = null) {
        const type = $('qf-type').value;
        const wrap = $('qf-options');
        const correct = (q && q.correct_answer) || 'a';

        if (type === 'MCQ') {
            const letters = ['a', 'b', 'c', 'd'];
            const opts = letters.map((l) => `
                <div class="qb-option-row">
                    <span class="qb-option-letter">${l.toUpperCase()}</span>
                    <input type="text" id="qf-option-${l}" class="form-control qf-option" data-letter="${l}" maxlength="5000"
                           placeholder="Option ${l.toUpperCase()}" value="${esc(q ? q[`option_${l}`] : '')}">
                </div>`).join('');
            wrap.innerHTML = `
                <div class="qb-options-grid">${opts}</div>
                <div class="form-group qb-correct-row">
                    <label for="qf-correct">Correct answer</label>
                    <select id="qf-correct">
                        ${letters.map((l) => `<option value="${l}" ${correct === l ? 'selected' : ''}>Option ${l.toUpperCase()}</option>`).join('')}
                    </select>
                </div>
                <p class="qb-hint">At least two options are required, and the correct option must have text.</p>`;
        } else if (type === 'True/False') {
            const val = correct === 'b' ? 'False' : 'True';
            wrap.innerHTML = `
                <div class="qb-hint qb-options-note">True/False options are fixed: <b>A = True</b>, <b>B = False</b>.</div>
                <div class="form-group qb-correct-row">
                    <label for="qf-correct">Correct answer</label>
                    <select id="qf-correct">
                        <option value="True" ${val === 'True' ? 'selected' : ''}>True</option>
                        <option value="False" ${val === 'False' ? 'selected' : ''}>False</option>
                    </select>
                </div>`;
        } else {
            wrap.innerHTML = `
                <div class="qb-hint qb-options-note qb-warn-note">
                    Essay questions are stored in the bank for reference and review, but they cannot be
                    auto-graded by the exam engine — they cannot be assigned to exam papers.
                </div>`;
        }
    }

    function saveEditor() {
        const id = $('qf-id').value ? Number($('qf-id').value) : null;
        const type = $('qf-type').value;
        const payload = {
            id,
            question: $('qf-question').value.trim(),
            type,
            difficulty: $('qf-difficulty').value,
            subject: $('qf-subject').value.trim(),
            grade: $('qf-grade').value.trim(),
            topic: $('qf-topic').value.trim(),
            tags: $('qf-tags').value.trim(),
            is_public: $('qf-public').checked ? 1 : 0,
        };
        if (type === 'MCQ') {
            ['a', 'b', 'c', 'd'].forEach((l) => { payload[`option_${l}`] = $(`qf-option-${l}`) ? $(`qf-option-${l}`).value.trim() : ''; });
            payload.correct_answer = $('qf-correct').value;
        } else if (type === 'True/False') {
            payload.correct_answer = $('qf-correct').value === 'False' ? 'b' : 'a';
        }

        const submitBtn = $('qb-editor-save');
        submitBtn.disabled = true;
        api('save', { method: 'POST', body: payload })
            .then((data) => {
                toast(data.created ? 'Question created.' : 'Question updated.', 'success');
                if (data.duplicates && data.duplicates.length > 0) {
                    const ids = data.duplicates.map((d) => '#' + d.id).join(', ');
                    toast(`Saved, but very similar to existing: ${ids}.`, 'warning', 7000);
                }
                closeModal('qb-editor-modal');
                loadList();
            })
            .catch((e) => toast(e.message, 'error'))
            .finally(() => { submitBtn.disabled = false; });
    }

    // ------------------------------------------------------------- preview

    async function openPreview(id) {
        try {
            const data = await api('show', { query: { id } });
            const q = data.question;
            const opts = ['a', 'b', 'c', 'd']
                .filter((l) => q[`option_${l}`])
                .map((l) => `<div class="qb-preview-option ${q.correct_answer === l ? 'is-correct' : ''}">
                    <span class="qb-option-letter">${l.toUpperCase()}</span> ${esc(q[`option_${l}`])}
                    ${q.correct_answer === l ? '<span class="qb-correct-tag">Correct</span>' : ''}</div>`)
                .join('');
            const assignments = (q.assignments || []).map((a) => `
                <li>${esc(a.exam_name)} — ${Number(a.points)} pt${a.is_live ? ' <span class="qb-archived-pill">LIVE</span>' : ''}</li>`).join('');
            $('qb-preview-body').innerHTML = `
                <div class="qb-preview-meta">
                    ${typeBadge(q.type)} ${difficultyBadge(q.difficulty)}
                    ${q.subject ? `<span class="qb-meta-chip">${esc(q.subject)}</span>` : ''}
                    ${q.grade ? `<span class="qb-meta-chip">${esc(q.grade)}</span>` : ''}
                    ${q.topic ? `<span class="qb-meta-chip">${esc(q.topic)}</span>` : ''}
                </div>
                <div class="qb-preview-question">${esc(questionText(q))}</div>
                ${q.type === 'Essay' ? '<p class="qb-hint">Essay question — stored for reference; cannot be auto-graded or assigned.</p>' : ''}
                ${opts ? `<div class="qb-preview-options">${opts}</div>` : ''}
                ${q.tags ? `<div class="qb-preview-tags"><b>Tags:</b> ${esc(q.tags)}</div>` : ''}
                <div class="qb-preview-assignments">
                    <b>Assigned to ${(q.assignments || []).length} exam(s):</b>
                    ${assignments ? `<ul>${assignments}</ul>` : '<p class="text-muted">Not assigned to any exam yet.</p>'}
                </div>
                <div class="text-muted qb-preview-foot">
                    Created ${esc(q.created_at || '—')}${q.created_by_name ? ' by ' + esc(q.created_by_name) : ''}
                    · Updated ${esc(q.updated_at || '—')}
                </div>`;
            openModal('qb-preview-modal');
        } catch (e) {
            toast(e.message, 'error');
        }
    }

    // ------------------------------------------------------------- archive/restore

    function archiveQuestions(ids) {
        if (ids.length === 0) { toast('Select at least one question to archive.', 'warning'); return; }
        confirmDialog({
            title: 'Archive questions?',
            text: `Archive ${ids.length} question(s)? Archived questions disappear from the bank (and from generator pools) but can be restored anytime. Nothing is permanently deleted.`,
            okLabel: 'Archive',
        }).then((ok) => {
            if (!ok) return;
            api('archive', { method: 'POST', body: { ids } })
                .then((data) => {
                    if (data.blocked.length > 0) {
                        toast(`Blocked: ${data.blocked.length} question(s) are assigned to a LIVE exam.`, 'error', 6000);
                    }
                    if (data.affected_exams.length > 0) {
                        const names = data.affected_exams.map((e) => e.exam_name).slice(0, 3).join(', ');
                        toast(`Archived. Still used in: ${names}${data.affected_exams.length > 3 ? '…' : ''} (copies already inside those exams are kept).`, 'warning', 6000);
                    } else if (data.blocked.length === 0) {
                        toast(`${data.archived} question(s) archived.`, 'success');
                    }
                    state.selection.clear();
                    loadList();
                })
                .catch((e) => toast(e.message, 'error'));
        });
    }

    function restoreQuestions(ids) {
        if (ids.length === 0) { toast('Select at least one question to restore.', 'warning'); return; }
        confirmDialog({
            title: 'Restore questions?',
            text: `Restore ${ids.length} question(s) back into the active bank?`,
            okLabel: 'Restore',
            danger: false,
        }).then((ok) => {
            if (!ok) return;
            api('restore', { method: 'POST', body: { ids } })
                .then((data) => {
                    toast(`${data.restored} question(s) restored.`, 'success');
                    state.selection.clear();
                    loadList();
                })
                .catch((e) => toast(e.message, 'error'));
        });
    }

    // ------------------------------------------------------------- assign

    function openAssign(ids) {
        if (!ids || ids.length === 0) { toast('Select at least one question to assign.', 'warning'); return; }
        state.pendingAssignIds = ids;
        $('qb-assign-count').textContent = `${ids.length} question(s) selected for assignment`;
        $('qb-assign-points').value = '1';
        $('qb-assign-exam').value = '';
        setAssignMode('existing');
        renderAssignExamOptions();
        $('qb-assign-preview').innerHTML = ids.length > 3
            ? `<p class="text-muted">Assigning ${ids.length} questions.</p>`
            : '';
        openModal('qb-assign-modal');
    }

    function setAssignMode(mode) {
        state.assignMode = mode;
        document.querySelectorAll('.qb-assign-mode-btn').forEach((b) => {
            b.classList.toggle('is-active', b.dataset.mode === mode);
        });
        $('qb-assign-existing').hidden = mode !== 'existing';
        $('qb-assign-new').hidden = mode !== 'new';
    }

    function renderAssignExamOptions() {
        const sel = $('qb-assign-exam');
        const opts = state.exams.map((e) => {
            const live = Number(e.is_live) === 1;
            return `<option value="${e.id}" ${live ? 'disabled' : ''}>${esc(e.exam_name)}${live ? ' (LIVE — locked)' : ''}</option>`;
        });
        sel.innerHTML = `<option value="">— Choose an exam —</option>` + opts.join('');
    }

    function submitAssign() {
        const points = parseFloat($('qb-assign-points').value);
        if (!(points > 0)) { toast('Points must be greater than 0.', 'warning'); return; }
        const btn = $('qb-assign-submit');
        btn.disabled = true;

        let request;
        if (state.assignMode === 'new') {
            const name = $('qb-new-name').value.trim();
            if (!name) {
                toast('Give the new exam a name.', 'warning');
                btn.disabled = false;
                return;
            }
            const duration = Math.max(1, parseInt($('qb-new-duration').value, 10) || 60);
            request = api('assign_new_exam', {
                method: 'POST',
                body: {
                    exam_name: name,
                    duration,
                    stream: $('qb-new-stream').value,
                    shuffle_questions: $('qb-new-shuffle-q').checked,
                    shuffle_choices: $('qb-new-shuffle-c').checked,
                    question_ids: state.pendingAssignIds,
                    points,
                },
            });
        } else {
            const examId = Number($('qb-assign-exam').value);
            if (!examId) { toast('Please choose an exam paper.', 'warning'); btn.disabled = false; return; }
            request = api('assign', {
                method: 'POST',
                body: { exam_id: examId, question_ids: state.pendingAssignIds, points },
            });
        }

        request
            .then((data) => {
                if (data.errors && data.errors.length > 0) {
                    toast(`Assigned ${data.assigned}; ${data.errors.length} skipped: ${data.errors[0]}`, 'warning', 6000);
                } else if (state.assignMode === 'new') {
                    toast(`Exam “${data.exam_name}” created with ${data.assigned} question(s).`, 'success', 6000);
                } else {
                    toast(`${data.assigned} question(s) assigned to the exam.`, 'success');
                }
                closeModal('qb-assign-modal');
                state.selection.clear();
                if (state.assignMode === 'new') loadExams(); // new exam appears in dropdowns
                loadList();
            })
            .catch((e) => toast(e.message, 'error'))
            .finally(() => { btn.disabled = false; });
    }

    // ------------------------------------------------------------- assignments manager

    function openAssignments() {
        $('qb-assignments-exam').value = '';
        $('qb-assignments-list').innerHTML = '<p class="text-muted">Select an exam to see its assigned bank questions.</p>';
        renderAssignmentsExamOptions();
        openModal('qb-assignments-modal');
    }

    function renderAssignmentsExamOptions() {
        const sel = $('qb-assignments-exam');
        sel.innerHTML = `<option value="">— Choose an exam —</option>` + state.exams
            .map((e) => `<option value="${e.id}">${esc(e.exam_name)}${Number(e.is_live) === 1 ? ' (LIVE)' : ''}</option>`)
            .join('');
    }

    async function loadAssigned(examId) {
        const list = $('qb-assignments-list');
        list.innerHTML = '<p class="text-muted">Loading…</p>';
        try {
            const data = await api('assigned', { query: { exam_id: examId } });
            const exam = state.exams.find((e) => Number(e.id) === Number(examId));
            const live = exam ? Number(exam.is_live) === 1 : false;
            if (data.assigned.length === 0) {
                list.innerHTML = '<p class="text-muted">No bank questions assigned to this exam yet.</p>';
                return;
            }
            list.innerHTML = data.assigned.map((a) => `
                <div class="qb-assignment-row" data-qid="${a.question_id}">
                    <span class="qb-a-qnum">#${esc(a.position)}</span>
                    <div class="qb-a-main">
                        <div class="qb-a-text">${esc(a.question)}</div>
                        <div class="qb-a-meta">${esc(a.type || 'MCQ')}${a.difficulty ? ' · ' + esc(a.difficulty) : ''}${a.subject ? ' · ' + esc(a.subject) : ''}</div>
                    </div>
                    <div class="qb-a-controls">
                        <label class="qb-a-points-label">Marks
                            <input type="number" class="form-control qb-a-points" value="${Number(a.points)}" min="0.01" max="9999.99" step="0.25"
                                   data-exam="${examId}" data-qid="${a.question_id}" ${live ? 'disabled' : ''}>
                        </label>
                        <button type="button" class="qb-icon-btn" data-a-action="save-points" title="Save marks" ${live ? 'disabled' : ''}>💾</button>
                        <button type="button" class="qb-icon-btn qb-danger" data-a-action="remove" title="Remove from exam" ${live ? 'disabled' : ''}>🗑</button>
                    </div>
                </div>`).join('');
            if (live) {
                list.insertAdjacentHTML('beforeend', '<p class="qb-hint">This exam is LIVE — marks and assignments are locked.</p>');
            }
        } catch (e) {
            list.innerHTML = `<p class="text-muted">${esc(e.message)}</p>`;
        }
    }

    async function savePoints(examId, questionId, points) {
        try {
            await api('points', { method: 'POST', body: { exam_id: examId, question_id: questionId, points } });
            toast('Marks updated.', 'success', 2200);
        } catch (e) {
            toast(e.message, 'error');
        }
    }

    function removeAssignment(examId, questionId, text) {
        confirmDialog({
            title: 'Remove from exam?',
            text: `Remove “${text.slice(0, 80)}${text.length > 80 ? '…' : ''}” from this exam? The question stays in the bank.`,
            okLabel: 'Remove',
        }).then((ok) => {
            if (!ok) return;
            api('unassign', { method: 'POST', body: { exam_id: examId, question_id: questionId } })
                .then(() => {
                    toast('Question removed from the exam.', 'success');
                    loadAssigned(examId);
                    loadList();
                })
                .catch((e) => toast(e.message, 'error'));
        });
    }

    // ------------------------------------------------------------- import

    function openImport() {
        showImportUpload();
        openModal('qb-import-modal');
    }

    function showImportUpload() {
        $('qb-parsed-review').hidden = true;
        $('qb-parsed-import-btn').hidden = true;
        $('qb-import-submit').hidden = false;
        $('qb-import-upload').hidden = false;
        $('qb-import-result').hidden = true;
        $('qb-import-file').value = '';
        state.parsedQuestions = [];
    }

    function submitImport() {
        const file = $('qb-import-file').files[0];
        if (!file) { toast('Please choose a CSV, JSON or Word (.docx) file.', 'warning'); return; }
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        if (ext === 'docx' || ext === 'txt') {
            parseWordFile(file);
            return;
        }
        const fd = new FormData();
        fd.append('file', file);
        fd.append('subject', $('qb-import-subject').value.trim());
        fd.append('grade', $('qb-import-grade').value.trim());

        const btn = $('qb-import-submit');
        btn.disabled = true;
        btn.textContent = 'Importing…';
        api('import', { method: 'POST', body: fd })
            .then((data) => {
                const errors = data.errors || [];
                let html = `<div class="alert ${errors.length ? 'alert-warning' : 'alert-success'}">
                    Imported <b>${data.imported}</b> of <b>${data.total}</b> question(s).</div>`;
                if (errors.length) {
                    html += `<div class="qb-import-errors"><b>Skipped rows:</b><ul>${errors
                        .map((e) => `<li>Line ${e.line}: ${esc(e.message)}</li>`).join('')}</ul></div>`;
                }
                if (data.warnings && data.warnings.length) {
                    html += `<div class="qb-import-errors"><b>Similar-question warnings (saved anyway):</b><ul>${data.warnings
                        .map((w) => `<li>Line ${w.line}: ${esc(w.message)}</li>`).join('')}</ul></div>`;
                }
                const result = $('qb-import-result');
                result.innerHTML = html;
                result.hidden = false;
                toast(`Imported ${data.imported} question(s).`, errors.length ? 'warning' : 'success');
                loadList();
            })
            .catch((e) => toast(e.message, 'error'))
            .finally(() => { btn.disabled = false; btn.textContent = 'Import'; });
    }

    // ---------------------------------------------- Word / text review flow

    async function parseWordFile(file) {
        const fd = new FormData();
        fd.append('file', file);
        const btn = $('qb-import-submit');
        btn.disabled = true;
        btn.textContent = 'Reading document…';
        try {
            const data = await api('parse_docx', { method: 'POST', body: fd });
            state.parsedQuestions = data.questions;
            renderParsedReview(data.warnings || []);
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Import';
        }
    }

    function renderParsedReview(warnings) {
        const qs = state.parsedQuestions;
        const list = $('qb-parsed-list');
        list.innerHTML = qs.map((q, i) => {
            const isPassage = q.type === 'Passage';
            const opts = ['a', 'b', 'c', 'd']
                .filter((l) => (q.options?.[l] || '').trim())
                .map((l) => `<span class="qb-pr-opt"><b>${l.toUpperCase()}.</b> ${esc(q.options[l])}</span>`)
                .join('');
            const answerOptions = ['a', 'b', 'c', 'd'].map((l) =>
                `<option value="${l}" ${q.correct_answer === l ? 'selected' : ''}>${l.toUpperCase()}</option>`).join('');
            const note = isPassage
                ? '<span class="qb-pr-note qb-pr-note-passage">📄 Reading passage — kept as a reference question (or uncheck to skip)</span>'
                : (q.note ? `<span class="qb-pr-note">⚠ ${esc(q.note)}</span>` : '');
            return `<div class="qb-pr-item ${isPassage ? 'qb-pr-passage' : ''}" data-idx="${i}">
                <input type="checkbox" class="qb-pr-include" checked title="Include this item">
                <div class="qb-pr-main">
                    <textarea class="form-control qb-pr-q" rows="${isPassage ? 5 : 2}" maxlength="20000" title="You can edit the wording here">${esc(q.question)}</textarea>
                    <div class="qb-pr-opts ${opts ? '' : 'qb-pr-opts-empty'}">${opts || (isPassage ? 'Passage text above — no options needed.' : 'No options detected — set the type to Essay or add options after importing.')}</div>
                    <div class="qb-pr-controls">
                        ${isPassage ? '' : `<label>Correct answer
                            <select class="qb-pr-answer">
                                <option value="">— set —</option>
                                ${answerOptions}
                            </select>
                        </label>`}
                        <label>Type
                            <select class="qb-pr-type">
                                <option value="MCQ" ${q.type === 'MCQ' ? 'selected' : ''}>MCQ</option>
                                <option value="True/False" ${q.type === 'True/False' ? 'selected' : ''}>True/False</option>
                                <option value="Essay" ${q.type === 'Essay' || isPassage ? 'selected' : ''}>${isPassage ? 'Passage (saved as reference)' : 'Essay (no auto-grade)'}</option>
                            </select>
                        </label>
                        ${note}
                    </div>
                </div>
            </div>`;
        }).join('');

        const warnHtml = warnings.length
            ? `<div class="qb-parsed-warnings">${warnings.map((w) => `<p>${esc(w)}</p>`).join('')}</div>`
            : '';
        $('qb-parsed-warnings').innerHTML = warnHtml;
        const pCount = qs.filter((q) => q.type === 'Passage').length;
        $('qb-parsed-count').textContent = pCount > 0
            ? `${qs.length - pCount} question(s) + ${pCount} passage(s) found — review, set missing answers, then import.`
            : `${qs.length} question(s) found — review, set missing answers, then import.`;
        $('qb-import-upload').hidden = true;
        $('qb-import-submit').hidden = true;
        $('qb-import-result').hidden = true;
        $('qb-parsed-review').hidden = false;
        $('qb-parsed-import-btn').hidden = false;
        updateParsedButtons();
    }

    function collectParsedRows() {
        const rows = [];
        document.querySelectorAll('#qb-parsed-list .qb-pr-item').forEach((item, i) => {
            if (!item.querySelector('.qb-pr-include').checked) return;
            const q = state.parsedQuestions[i] || {};
            const question = item.querySelector('.qb-pr-q').value.trim();
            if (!question) return;
            const type = item.querySelector('.qb-pr-type').value;
            const row = {
                question,
                type,
                option_a: q.options?.a || '',
                option_b: q.options?.b || '',
                option_c: q.options?.c || '',
                option_d: q.options?.d || '',
            };
            if (type !== 'Essay') row.correct_answer = item.querySelector('.qb-pr-answer').value;
            rows.push(row);
        });
        return rows;
    }

    function updateParsedButtons() {
        const rows = collectParsedRows();
        const btn = $('qb-parsed-import-btn');
        const missing = rows.filter((r) => r.type !== 'Essay' && !r.correct_answer).length;
        btn.textContent = `Import ${rows.length} question${rows.length === 1 ? '' : 's'}`
            + (missing ? ` (${missing} need an answer)` : '');
        btn.disabled = rows.length === 0 || missing > 0;
    }

    function submitParsedImport() {
        const rows = collectParsedRows();
        if (rows.length === 0) { toast('Select at least one question to import.', 'warning'); return; }
        const missing = rows.filter((r) => r.type !== 'Essay' && !r.correct_answer);
        if (missing.length > 0) {
            toast(`Set the correct answer for ${missing.length} question(s) before importing.`, 'warning', 6000);
            return;
        }
        const payload = {
            questions: rows,
            subject: $('qb-import-subject').value.trim(),
            grade: $('qb-import-grade').value.trim(),
            difficulty: $('qb-import-difficulty').value,
        };
        const btn = $('qb-parsed-import-btn');
        btn.disabled = true;
        btn.textContent = 'Importing…';
        api('import_parsed', { method: 'POST', body: payload })
            .then((data) => {
                const errors = data.errors || [];
                let html = `<div class="alert ${errors.length ? 'alert-warning' : 'alert-success'}">
                    Imported <b>${data.imported}</b> of <b>${rows.length}</b> question(s).</div>`;
                if (errors.length) {
                    html += `<div class="qb-import-errors"><b>Skipped:</b><ul>${errors
                        .map((e) => `<li>Question ${e.line}: ${esc(e.message)}</li>`).join('')}</ul></div>`;
                }
                if (data.warnings && data.warnings.length) {
                    html += `<div class="qb-import-errors"><b>Similar-question warnings (saved anyway):</b><ul>${data.warnings
                        .map((w) => `<li>${esc(w.message)}</li>`).join('')}</ul></div>`;
                }
                $('qb-import-result').innerHTML = html;
                $('qb-import-result').hidden = false;
                // Back to the upload view so the result is clearly the final
                // state (and another file can be imported right away).
                $('qb-parsed-review').hidden = true;
                $('qb-parsed-import-btn').hidden = true;
                $('qb-import-submit').hidden = false;
                $('qb-import-upload').hidden = false;
                toast(`Imported ${data.imported} question(s).`, errors.length ? 'warning' : 'success');
                loadList();
            })
            .catch((e) => toast(e.message, 'error'))
            .finally(() => {
                btn.disabled = false;
                btn.textContent = 'Import';
            });
    }

    // ------------------------------------------------------------- modals

    function openModal(id) {
        $(id).hidden = false;
        document.body.classList.add('qb-modal-open');
    }

    function closeModal(id) {
        $(id).hidden = true;
        if (!document.querySelector('.qb-modal-backdrop:not([hidden])')) {
            document.body.classList.remove('qb-modal-open');
        }
    }

    function confirmDialog({ title, text, okLabel = 'Confirm', danger = true }) {
        return new Promise((resolve) => {
            $('qb-confirm-title').textContent = title;
            $('qb-confirm-text').textContent = text;
            const ok = $('qb-confirm-ok');
            ok.textContent = okLabel;
            ok.className = danger ? 'btn btn-danger' : 'btn btn-primary';
            state.confirmResolver = resolve;
            openModal('qb-confirm-modal');
        });
    }

    function settleConfirm(result) {
        if (state.confirmResolver) {
            state.confirmResolver(result);
            state.confirmResolver = null;
        }
        closeModal('qb-confirm-modal');
    }

    // ------------------------------------------------------------- wiring

    function debounce(fn, ms) {
        let t;
        return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
    }

    function bindEvents() {
        // Search
        const search = $('qb-search');
        search.addEventListener('input', debounce(() => {
            state.filters.search = search.value.trim();
            state.page = 1;
            loadList();
        }, 300));
        search.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                state.filters.search = search.value.trim();
                state.page = 1;
                loadList();
            }
        });

        // Filters
        ['qb-subject', 'qb-grade', 'qb-difficulty', 'qb-type', 'qb-date-from', 'qb-date-to'].forEach((id) => {
            $(id).addEventListener('change', () => {
                const key = id.replace('qb-', '').replace('date-from', 'date_from').replace('date-to', 'date_to');
                state.filters[key] = $(id).value;
                state.page = 1;
                loadList();
            });
        });

        $('qb-mine').addEventListener('change', () => {
            state.filters.mine = $('qb-mine').checked;
            state.page = 1;
            loadList();
        });

        $('qb-clear-filters').addEventListener('click', () => {
            state.filters = { search: '', subject: '', grade: '', difficulty: '', type: '', date_from: '', date_to: '', status: 'active', mine: false };
            ['qb-search', 'qb-subject', 'qb-grade', 'qb-difficulty', 'qb-type', 'qb-date-from', 'qb-date-to'].forEach((id) => { $(id).value = ''; });
            $('qb-mine').checked = false;
            setActiveTab('active');
            state.page = 1;
            loadList();
        });

        // Tabs
        document.querySelectorAll('.qb-tab').forEach((tab) => {
            tab.addEventListener('click', () => {
                setActiveTab(tab.dataset.status);
                state.filters.status = tab.dataset.status;
                state.page = 1;
                state.selection.clear();
                loadList();
            });
        });

        // Pagination
        $('qb-page-prev').addEventListener('click', () => { if (state.page > 1) { state.page--; loadList(); } });
        $('qb-page-next').addEventListener('click', () => { if (state.page < state.totalPages) { state.page++; loadList(); } });
        $('qb-page-numbers').addEventListener('click', (e) => {
            const btn = e.target.closest('[data-page]');
            if (btn) { state.page = Number(btn.dataset.page); loadList(); }
        });

        // Selection
        $('qb-select-all').addEventListener('change', (e) => {
            state.rows.forEach((q) => {
                if (e.target.checked) state.selection.add(Number(q.id));
                else state.selection.delete(Number(q.id));
            });
            renderTable();
        });
        $('qb-btn-clear-selection').addEventListener('click', () => { state.selection.clear(); renderTable(); });

        // Table actions (delegated)
        $('qb-tbody').addEventListener('click', (e) => {
            const check = e.target.closest('.qb-row-check');
            if (check) {
                const id = Number(check.dataset.id);
                if (check.checked) state.selection.add(id); else state.selection.delete(id);
                const tr = check.closest('tr');
                if (tr) tr.classList.toggle('is-selected', check.checked);
                updateBulkBar();
                return;
            }
            const btn = e.target.closest('[data-action]');
            if (!btn) return;
            const id = Number(btn.closest('tr').dataset.id);
            const action = btn.dataset.action;
            if (action === 'preview') openPreview(id);
            else if (action === 'edit') openEditor(id);
            else if (action === 'assign') openAssign([id]);
            else if (action === 'archive') archiveQuestions([id]);
            else if (action === 'restore') restoreQuestions([id]);
        });

        // Top toolbar buttons
        $('qb-btn-new').addEventListener('click', () => openEditor(null));
        $('qb-btn-assign').addEventListener('click', () => openAssign([...state.selection]));
        $('qb-btn-bulk-archive').addEventListener('click', () => archiveQuestions([...state.selection]));
        $('qb-btn-bulk-restore').addEventListener('click', () => restoreQuestions([...state.selection]));
        $('qb-btn-assignments').addEventListener('click', openAssignments);

        // Export / template menu
        $('qb-btn-export').addEventListener('click', (e) => {
            e.stopPropagation();
            $('qb-export-menu').classList.toggle('is-open');
        });
        document.addEventListener('click', () => $('qb-export-menu').classList.remove('is-open'));
        $('qb-export-menu').addEventListener('click', (e) => {
            const link = e.target.closest('a[data-format]');
            if (!link) return;
            e.preventDefault();
            const format = link.dataset.format;
            const template = link.dataset.template === '1';
            const params = new URLSearchParams({ action: template ? 'template' : 'export', format });
            if (!template) {
                Object.entries(currentQuery()).forEach(([k, v]) => params.set(k, v));
            }
            window.location.href = `${API}?${params}`;
        });

        // Import
        $('qb-btn-import').addEventListener('click', openImport);
        $('qb-import-submit').addEventListener('click', submitImport);
        $('qb-parsed-import-btn').addEventListener('click', submitParsedImport);
        $('qb-parsed-back').addEventListener('click', showImportUpload);
        $('qb-parsed-list').addEventListener('input', updateParsedButtons);
        $('qb-parsed-list').addEventListener('change', updateParsedButtons);

        // Editor
        $('qf-type').addEventListener('change', () => renderOptions(null));
        $('qb-editor-save').addEventListener('click', saveEditor);

        // Assign
        $('qb-assign-submit').addEventListener('click', submitAssign);
        document.querySelectorAll('.qb-assign-mode-btn').forEach((btn) => {
            btn.addEventListener('click', () => setAssignMode(btn.dataset.mode));
        });

        // Assignments manager
        $('qb-assignments-exam').addEventListener('change', (e) => {
            if (e.target.value) loadAssigned(e.target.value);
        });
        $('qb-assignments-list').addEventListener('click', (e) => {
            const btn = e.target.closest('[data-a-action]');
            if (!btn) return;
            const row = btn.closest('.qb-assignment-row');
            const examId = Number(btn.dataset.exam) || Number($('qb-assignments-exam').value);
            const qid = Number(row.dataset.qid);
            if (btn.dataset.aAction === 'save-points') {
                const input = row.querySelector('.qb-a-points');
                savePoints(examId, qid, parseFloat(input.value));
            } else if (btn.dataset.aAction === 'remove') {
                const text = row.querySelector('.qb-a-text').textContent;
                removeAssignment(examId, qid, text);
            }
        });

        // Confirm dialog
        $('qb-confirm-ok').addEventListener('click', () => settleConfirm(true));
        document.querySelectorAll('[data-close="qb-confirm-modal"]').forEach((el) => {
            el.addEventListener('click', () => settleConfirm(false));
        });

        // Generic modal close
        document.querySelectorAll('.qb-modal-backdrop').forEach((backdrop) => {
            backdrop.addEventListener('click', (e) => {
                if (e.target === backdrop) {
                    if (backdrop.id === 'qb-confirm-modal') { settleConfirm(false); return; }
                    closeModal(backdrop.id);
                }
            });
            const closeBtn = backdrop.querySelector('.qb-modal-close');
            if (closeBtn) closeBtn.addEventListener('click', () => closeModal(backdrop.id));
        });
        document.querySelectorAll('[data-close]').forEach((el) => {
            el.addEventListener('click', () => closeModal(el.dataset.close));
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (!$('qb-confirm-modal').hidden) { settleConfirm(false); return; }
                document.querySelectorAll('.qb-modal-backdrop:not([hidden])').forEach((m) => closeModal(m.id));
            }
        });
    }

    function setActiveTab(status) {
        document.querySelectorAll('.qb-tab').forEach((t) => {
            t.classList.toggle('is-active', t.dataset.status === status);
        });
        const archived = status === 'archived';
        $('qb-btn-bulk-archive').style.display = archived ? 'none' : '';
        $('qb-btn-bulk-restore').style.display = archived ? '' : 'none';
    }

    function init() {
        bindEvents();
        loadFacets();
        loadExams();
        setActiveTab(state.filters.status);
        loadList();
    }

    document.addEventListener('DOMContentLoaded', init);
})();
