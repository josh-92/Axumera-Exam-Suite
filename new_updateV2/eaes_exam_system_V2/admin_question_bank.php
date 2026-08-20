<?php
/**
 * admin_question_bank.php — Question Bank
 * ----------------------------------------
 * Main admin screen for managing the standalone question bank:
 * searchable/filterable/paginated list, create/edit/preview, soft archive
 * + restore, bulk assignment to exams with per-question points, and
 * CSV/JSON/Word(.docx)/text import & export. All logic lives in
 * assets/js/question_bank.js talking to api_questions.php.
 */

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Csrf;
use App\Repositories\QuestionBankRepository;

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: adminlogin.php');
    exit;
}

$csrf = Csrf::token();
$appName = htmlspecialchars((string) B_K5t('app.name', 'EAES'));

// ---- Fetch exams for JavaScript dropdowns (shared PDO singleton) ----
$exams = [];
foreach (QuestionBankRepository::assignableExams() as $exam) {
    $exams[] = ['id' => (int) $exam['id'], 'exam_name' => $exam['exam_name']];
}
$examListJson = json_encode($exams, JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Question Bank — <?php echo $appName; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/question_bank.css">
</head>
<body>
    <div class="page-shell">
    <?php include __DIR__ . '/partials/admin_header.php'; ?>

    <main class="qb-main">

        <!-- ======================= Toolbar ======================= -->
        <section class="card qb-toolbar">
            <div class="qb-toolbar-row">
                <div class="qb-search">
                    <input type="search" id="qb-search" class="form-control" placeholder="Search questions, subjects, topics, tags…">
                </div>
                <select id="qb-subject" class="form-control qb-filter"></select>
                <select id="qb-grade" class="form-control qb-filter"></select>
                <select id="qb-difficulty" class="form-control qb-filter">
                    <option value="">All difficulty</option>
                    <option value="easy">Easy</option>
                    <option value="medium">Medium</option>
                    <option value="hard">Hard</option>
                </select>
                <select id="qb-type" class="form-control qb-filter">
                    <option value="">All types</option>
                    <option value="MCQ">MCQ</option>
                    <option value="True/False">True/False</option>
                    <option value="Essay">Essay</option>
                </select>
                <input type="date" id="qb-date-from" class="form-control qb-filter" title="Created from">
                <input type="date" id="qb-date-to" class="form-control qb-filter" title="Created to">
                <button type="button" id="qb-clear-filters" class="btn btn-muted" title="Clear all filters">✕ Clear</button>
            </div>

            <div class="qb-toolbar-row qb-toolbar-row-bottom">
                <div class="qb-tabs" role="tablist">
                    <button type="button" class="qb-tab is-active" data-status="active">Active</button>
                    <button type="button" class="qb-tab" data-status="archived">Archived</button>
                </div>
                <label class="qb-mine">
                    <input type="checkbox" id="qb-mine"> Only my questions
                </label>
                <div class="qb-spacer"></div>
                <button type="button" id="qb-btn-new" class="btn btn-primary">＋ New Question</button>
                <button type="button" id="qb-btn-assign" class="btn">📎 Assign to Exam</button>
                <button type="button" id="qb-btn-bulk-archive" class="btn btn-danger">🗑 Archive</button>
                <button type="button" id="qb-btn-bulk-restore" class="btn btn-success" style="display:none">♻ Restore</button>
                <button type="button" id="qb-btn-assignments" class="btn">🗂 Assignments</button>
                <button type="button" id="qb-btn-import" class="btn btn-muted">⬆ Import</button>
                <div class="qb-dropdown">
                    <button type="button" id="qb-btn-export" class="btn btn-muted">⬇ Export ▾</button>
                    <div class="qb-dropdown-menu" id="qb-export-menu">
                        <a href="#" data-format="csv">Export CSV</a>
                        <a href="#" data-format="json">Export JSON</a>
                        <div class="qb-dropdown-sep"></div>
                        <a href="#" data-format="csv" data-template="1">Download CSV template</a>
                        <a href="#" data-format="json" data-template="1">Download JSON template</a>
                    </div>
                </div>
            </div>
        </section>

        <!-- ======================= Bulk bar ======================= -->
        <div id="qb-bulk-bar" class="qb-bulk-bar" hidden>
            <span id="qb-bulk-count">0 selected</span>
            <span class="qb-spacer"></span>
            <button type="button" class="btn btn-sm btn-muted" id="qb-btn-clear-selection">Clear selection</button>
        </div>

        <!-- ======================= Table ======================= -->
        <section class="card qb-table-card">
            <div id="qb-loading" class="qb-loading" hidden><div class="qb-spinner"></div> Loading questions…</div>
            <div class="qb-table-wrap">
                <table class="qb-table" id="qb-table">
                    <thead>
                        <tr>
                            <th class="qb-col-check"><input type="checkbox" id="qb-select-all" title="Select all on this page"></th>
                            <th>Question</th>
                            <th>Type</th>
                            <th>Difficulty</th>
                            <th>Subject</th>
                            <th>Grade</th>
                            <th>Created</th>
                            <th>Used</th>
                            <th class="qb-col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="qb-tbody">
                        <tr><td colspan="9" class="qb-empty">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="qb-pagination">
                <span id="qb-page-info" class="text-muted"></span>
                <div class="qb-pagination-controls">
                    <button type="button" class="btn btn-sm btn-muted" id="qb-page-prev">‹ Prev</button>
                    <span id="qb-page-numbers"></span>
                    <button type="button" class="btn btn-sm btn-muted" id="qb-page-next">Next ›</button>
                </div>
            </div>
        </section>
    </main>

    <!-- ======================= Editor modal ======================= -->
    <div class="qb-modal-backdrop" id="qb-editor-modal" hidden>
        <div class="qb-modal qb-modal-lg">
            <div class="qb-modal-header">
                <h3 id="qb-editor-title">New Question</h3>
                <button type="button" class="qb-modal-close" data-close="qb-editor-modal">✕</button>
            </div>
            <div class="qb-modal-body">
                <input type="hidden" id="qf-id">
                <div class="qb-form-grid">
                    <div class="form-group qb-span-2">
                        <label for="qf-question">Question text *</label>
                        <textarea id="qf-question" rows="3" maxlength="20000" placeholder="Write the question…"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="qf-type">Type</label>
                        <select id="qf-type">
                            <option value="MCQ">MCQ</option>
                            <option value="True/False">True/False</option>
                            <option value="Essay">Essay</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="qf-difficulty">Difficulty</label>
                        <select id="qf-difficulty">
                            <option value="">—</option>
                            <option value="easy">Easy</option>
                            <option value="medium">Medium</option>
                            <option value="hard">Hard</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="qf-subject">Subject / Course</label>
                        <input type="text" id="qf-subject" maxlength="100" placeholder="e.g. Mathematics">
                    </div>
                    <div class="form-group">
                        <label for="qf-grade">Grade</label>
                        <input type="text" id="qf-grade" maxlength="50" placeholder="e.g. Grade 12">
                    </div>
                    <div class="form-group">
                        <label for="qf-topic">Topic</label>
                        <input type="text" id="qf-topic" maxlength="255" placeholder="e.g. Algebra">
                    </div>
                    <div class="form-group">
                        <label for="qf-tags">Tags</label>
                        <input type="text" id="qf-tags" maxlength="500" placeholder="comma, separated, keywords">
                    </div>
                </div>
                <div id="qf-options">
                    <!-- MCQ / True/False / Essay options rendered by JS -->
                </div>
                <label class="qb-check">
                    <input type="checkbox" id="qf-public" checked>
                    Public (visible to all — generators can pick it)
                </label>
            </div>
            <div class="qb-modal-footer">
                <button type="button" class="btn btn-muted" data-close="qb-editor-modal">Cancel</button>
                <button type="button" id="qb-editor-save" class="btn btn-primary">Save Question</button>
            </div>
        </div>
    </div>

    <!-- ======================= Preview modal ======================= -->
    <div class="qb-modal-backdrop" id="qb-preview-modal" hidden>
        <div class="qb-modal qb-modal-lg">
            <div class="qb-modal-header">
                <h3>Question Preview</h3>
                <button type="button" class="qb-modal-close" data-close="qb-preview-modal">✕</button>
            </div>
            <div class="qb-modal-body" id="qb-preview-body"></div>
            <div class="qb-modal-footer">
                <button type="button" class="btn btn-muted" data-close="qb-preview-modal">Close</button>
            </div>
        </div>
    </div>

    <!-- ======================= Assign modal ======================= -->
    <div class="qb-modal-backdrop" id="qb-assign-modal" hidden>
        <div class="qb-modal">
            <div class="qb-modal-header">
                <h3>Assign to Exam</h3>
                <button type="button" class="qb-modal-close" data-close="qb-assign-modal">✕</button>
            </div>
            <div class="qb-modal-body">
                <p class="text-muted qb-assign-count" id="qb-assign-count">0 questions selected</p>
                <div class="qb-assign-mode">
                    <button type="button" class="qb-assign-mode-btn is-active" data-mode="existing">Existing exam</button>
                    <button type="button" class="qb-assign-mode-btn" data-mode="new">＋ Create new exam</button>
                </div>
                <div id="qb-assign-existing">
                    <div class="form-group">
                        <label for="qb-assign-exam">Exam paper</label>
                        <select id="qb-assign-exam"><option value="">— Choose an exam —</option></select>
                        <p class="qb-hint">Live exams are disabled — assignments cannot change while students are taking an exam.</p>
                    </div>
                </div>
                <div id="qb-assign-new" hidden>
                    <div class="qb-form-grid">
                        <div class="form-group">
                            <label for="qb-new-name">Exam name *</label>
                            <input type="text" id="qb-new-name" maxlength="150" placeholder="e.g. Physics Midterm — Grade 12">
                        </div>
                        <div class="form-group">
                            <label for="qb-new-duration">Duration (minutes)</label>
                            <input type="number" id="qb-new-duration" min="1" max="600" value="60">
                        </div>
                        <div class="form-group">
                            <label for="qb-new-stream">Stream</label>
                            <select id="qb-new-stream">
                                <option value="Natural Science">Natural Science</option>
                                <option value="Social Science">Social Science</option>
                            </select>
                        </div>
                        <div class="form-group qb-assign-shuffle">
                            <label class="qb-check"><input type="checkbox" id="qb-new-shuffle-q"> Shuffle question order</label>
                            <label class="qb-check"><input type="checkbox" id="qb-new-shuffle-c"> Shuffle answer choices</label>
                        </div>
                    </div>
                    <p class="qb-hint">The exam is created right away and these questions are assigned to it — no pre-uploaded exam needed.</p>
                </div>
                <div class="form-group">
                    <label for="qb-assign-points">Points per question</label>
                    <input type="number" id="qb-assign-points" min="0.01" max="9999.99" step="0.25" value="1">
                    <p class="qb-hint">You can fine-tune marks per question afterwards via “Assignments”.</p>
                </div>
                <div id="qb-assign-preview"></div>
            </div>
            <div class="qb-modal-footer">
                <button type="button" class="btn btn-muted" data-close="qb-assign-modal">Cancel</button>
                <button type="button" id="qb-assign-submit" class="btn btn-primary">Assign</button>
            </div>
        </div>
    </div>

    <!-- ======================= Assignments modal ======================= -->
    <div class="qb-modal-backdrop" id="qb-assignments-modal" hidden>
        <div class="qb-modal qb-modal-lg">
            <div class="qb-modal-header">
                <h3>Exam Assignments</h3>
                <button type="button" class="qb-modal-close" data-close="qb-assignments-modal">✕</button>
            </div>
            <div class="qb-modal-body">
                <div class="form-group">
                    <label for="qb-assignments-exam">Exam paper</label>
                    <select id="qb-assignments-exam"><option value="">— Choose an exam —</option></select>
                </div>
                <div id="qb-assignments-list" class="qb-assignments-list">
                    <p class="text-muted">Select an exam to see its assigned bank questions.</p>
                </div>
            </div>
            <div class="qb-modal-footer">
                <button type="button" class="btn btn-muted" data-close="qb-assignments-modal">Close</button>
            </div>
        </div>
    </div>

    <!-- ======================= Import modal ======================= -->
    <div class="qb-modal-backdrop" id="qb-import-modal" hidden>
        <div class="qb-modal">
            <div class="qb-modal-header">
                <h3>Import Questions</h3>
                <button type="button" class="qb-modal-close" data-close="qb-import-modal">✕</button>
            </div>
            <div class="qb-modal-body">
                <div id="qb-import-upload">
                    <div class="form-group">
                        <label for="qb-import-file">CSV, JSON, Word (.docx) or text file *</label>
                        <input type="file" id="qb-import-file" accept=".csv,.json,.docx,.txt">
                    </div>
                    <div class="qb-form-grid">
                        <div class="form-group">
                            <label for="qb-import-subject">Default subject (when missing)</label>
                            <input type="text" id="qb-import-subject" maxlength="100" placeholder="e.g. Physics">
                        </div>
                        <div class="form-group">
                            <label for="qb-import-grade">Default grade (when missing)</label>
                            <input type="text" id="qb-import-grade" maxlength="50" placeholder="e.g. Grade 12">
                        </div>
                        <div class="form-group">
                            <label for="qb-import-difficulty">Default difficulty (optional)</label>
                            <select id="qb-import-difficulty">
                                <option value="">—</option>
                                <option value="easy">Easy</option>
                                <option value="medium">Medium</option>
                                <option value="hard">Hard</option>
                            </select>
                        </div>
                    </div>
                    <div class="qb-hint">
                        <b>Word / text:</b> write questions the way you normally do — number each question
                        (<code>1.</code>), list options as <code>A. … B. … C. … D. …</code>, and mark the answer
                        with an <code>Answer: C</code> line (or <b>bold</b> the correct option). True/False items
                        work too. You'll review every question and set any missing answers before anything is saved.
                        <b>CSV:</b> <code>question, type, difficulty, subject, grade, topic, tags, option_a…option_d, correct_answer</code>.
                        <b>JSON:</b> the common export format (<code>question_type</code>, nested <code>options</code>,
                        <code>answer</code>) is supported. MCQ needs a correct answer (a–d).
                        Download a template from the Export menu for CSV/JSON.
                    </div>
                </div>
                <div id="qb-parsed-review" hidden>
                    <p class="text-muted" id="qb-parsed-count"></p>
                    <div id="qb-parsed-warnings"></div>
                    <div class="qb-parsed-list" id="qb-parsed-list"></div>
                    <div class="qb-parsed-actions">
                        <button type="button" class="btn btn-muted" id="qb-parsed-back">← Choose a different file</button>
                        <span class="qb-spacer"></span>
                    </div>
                </div>
                <div id="qb-import-result" hidden></div>
            </div>
            <div class="qb-modal-footer">
                <button type="button" class="btn btn-muted" data-close="qb-import-modal">Close</button>
                <button type="button" id="qb-import-submit" class="btn btn-primary">Import</button>
                <button type="button" id="qb-parsed-import-btn" class="btn btn-primary" hidden>Import questions</button>
            </div>
        </div>
    </div>

    <!-- Confirm dialog -->
    <div class="qb-modal-backdrop" id="qb-confirm-modal" hidden>
        <div class="qb-modal qb-modal-sm">
            <div class="qb-modal-header">
                <h3 id="qb-confirm-title">Are you sure?</h3>
                <button type="button" class="qb-modal-close" data-close="qb-confirm-modal">✕</button>
            </div>
            <div class="qb-modal-body" id="qb-confirm-text"></div>
            <div class="qb-modal-footer">
                <button type="button" class="btn btn-muted" data-close="qb-confirm-modal">Cancel</button>
                <button type="button" id="qb-confirm-ok" class="btn btn-danger">Confirm</button>
            </div>
        </div>
    </div>

    <div id="qb-toasts" class="qb-toasts"></div>

    <!-- ======================= INLINE FIX FOR renderExamSelects ======================= -->
    <script>
        // Pre‑load exam list from the server (avoids an extra API call)
        const EXAM_LIST = <?php echo $examListJson; ?>;

        // Define the missing function so question_bank.js can call it.
        function renderExamSelects(selectors = ['#qb-assign-exam', '#qb-assignments-exam'], selectedId) {
            // Accept a string or an array of CSS selectors
            if (typeof selectors === 'string') {
                selectors = [selectors];
            }

            selectors.forEach(function(sel) {
                var select = document.querySelector(sel);
                if (!select) return;

                // Preserve the first placeholder option if present
                var placeholder = select.options[0] ? select.options[0].text : '';
                select.innerHTML = '';

                if (placeholder) {
                    var opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = placeholder;
                    select.appendChild(opt);
                }

                EXAM_LIST.forEach(function(exam) {
                    var opt = document.createElement('option');
                    opt.value = exam.id;
                    opt.textContent = exam.exam_name;
                    if (exam.id == selectedId) {
                        opt.selected = true;
                    }
                    select.appendChild(opt);
                });
            });
        }

        // Optional: if the function is called before the page loads, provide a fallback
        // that retries after DOM ready.
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                // If question_bank.js called renderExamSelects during load, it will be defined now.
                // If not, we can re-run the function manually – but that's handled by the JS.
            });
        }
    </script>

    <script>window.EAES_CSRF = <?php echo json_encode($csrf); ?>;</script>    <script src="assets/js/question_bank.js"></script>
    <?php include __DIR__ . '/partials/copyright_footer.php'; ?>
    </div>
</body>
</html>
