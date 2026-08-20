<?php

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Csrf;
use App\Core\License;
use App\Core\Logger;
use App\Core\Validator;
use App\Repositories\AttemptRepository;
use App\Repositories\ExamRepository;
use App\Services\ExamImportService;

if (empty($_SESSION['admin_logged_in'])) {
    header("Location: adminlogin.php");
    exit();
}

$flash_error = [];
$flash_success = "";

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    header("Location: logout.php");
    exit();
}

// ---- Create / Edit exam profile -----------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !Csrf::verify($_POST['csrf_token'] ?? null)) {
    $flash_error[] = 'Your session expired. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $exam_name = Validator::string($_POST['exam_name'] ?? '', 150);
    $hh = Validator::int($_POST['timeHH'] ?? 0);
    $mm = Validator::int($_POST['timeMM'] ?? 0);
    $ss = Validator::int($_POST['timeSS'] ?? 0);
    $total_minutes = ($hh * 60) + $mm + (int) round($ss / 60);
    if ($total_minutes <= 0) $total_minutes = 60;

    $color_theme = Validator::hexColor($_POST['color_theme'] ?? '');
    $stream = Validator::inArray($_POST['stream'] ?? '', ['Natural Science', 'Social Science'], 'Natural Science');

    $meta = [
        'exam_name'         => $exam_name,
        'duration'          => $total_minutes,
        'stream'            => $stream,
        'color_theme'       => $color_theme,
        'shuffle_questions' => isset($_POST['shuffle_questions']),
        'shuffle_choices'   => isset($_POST['shuffle_choices']),
    ];

    if ($exam_name === '') {
        $flash_error[] = 'Exam name is required.';
    } elseif ($_POST['action'] === 'create') {
        if (!isset($_FILES['exam_json']) || $_FILES['exam_json']['error'] !== UPLOAD_ERR_OK) {
            $flash_error[] = 'Please attach a valid .json exam file.';
        } else {
            $result = ExamImportService::createFromUpload($_FILES['exam_json'], $meta);
            if ($result['errors']) {
                $flash_error = $result['errors'];
            } else {
                Logger::audit('admin', $_SESSION['admin_username'], 'exam_created', ['exam_id' => $result['exam_id'], 'name' => $exam_name]);
                header("Location: adminpanel.php?created=1");
                exit();
            }
        }
    } elseif ($_POST['action'] === 'edit') {
        $exam_id = Validator::int($_POST['exam_id'] ?? 0);
        if ($exam_id <= 0 || !ExamRepository::find($exam_id)) {
            $flash_error[] = 'The exam you tried to edit no longer exists.';
        } elseif (isset($_FILES['exam_json']) && $_FILES['exam_json']['error'] === UPLOAD_ERR_OK) {
            $errors = ExamImportService::replaceFromUpload($exam_id, $_FILES['exam_json'], $meta);
            if ($errors) {
                $flash_error = $errors;
            } else {
                Logger::audit('admin', $_SESSION['admin_username'], 'exam_updated', ['exam_id' => $exam_id]);
                header("Location: adminpanel.php?updated=1");
                exit();
            }
        } else {
            ExamRepository::updateMeta($exam_id, $meta);
            Logger::audit('admin', $_SESSION['admin_username'], 'exam_updated_meta_only', ['exam_id' => $exam_id]);
            header("Location: adminpanel.php?updated=1");
            exit();
        }
    }
}

// ---- Toggle live / delete (GET actions, still CSRF-checked via token param) ----
if (isset($_GET['toggle_live'])) {
    if (!Csrf::verify($_GET['csrf_token'] ?? null)) {
        $flash_error[] = 'Security check failed — please try again.';
    } else {
        $target_id = Validator::int($_GET['toggle_live']);
        $current_status = Validator::int($_GET['current'] ?? 0);
        ExamRepository::setLive($target_id, $current_status === 0);
        Logger::audit('admin', $_SESSION['admin_username'], $current_status === 0 ? 'exam_started' : 'exam_stopped', ['exam_id' => $target_id]);
        header("Location: adminpanel.php");
        exit();
    }
}

if (isset($_GET['confirm_delete'])) {
    if (!Csrf::verify($_GET['csrf_token'] ?? null)) {
        $flash_error[] = 'Security check failed — please try again.';
    } else {
        $delete_id = Validator::int($_GET['confirm_delete']);
        ExamRepository::delete($delete_id);
        Logger::audit('admin', $_SESSION['admin_username'], 'exam_deleted', ['exam_id' => $delete_id]);
        header("Location: adminpanel.php?deleted=1");
        exit();
    }
}

if (isset($_GET['created'])) $flash_success = 'Exam profile created successfully.';
if (isset($_GET['updated'])) $flash_success = 'Exam profile updated successfully.';
if (isset($_GET['deleted'])) $flash_success = 'Exam profile deleted.';

$all_exams = ExamRepository::all();
$licenseStatus = License::status();
$csrf = Csrf::token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel — <?php echo htmlspecialchars(config('app.name')); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
    <?php include __DIR__ . '/partials/admin_header.php'; ?>

    <main class="dashboard-content">
        <?php if (!$licenseStatus['valid']): ?>
            <div class="alert alert-warning">
                ⚠️ License not active (<?php echo htmlspecialchars($licenseStatus['message']); ?>).
                <a href="license.php">Activate your license</a> to remove this notice.
            </div>
        <?php endif; ?>

        <?php if ($flash_success): ?><div class="alert alert-success">✅ <?php echo htmlspecialchars($flash_success); ?></div><?php endif; ?>
        <?php foreach ($flash_error as $e): ?><div class="alert alert-error">⚠️ <?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>

        <div class="exam-grid" id="examProfilesGrid">
            <?php foreach ($all_exams as $exam): ?>
                <?php
                    $exam_id_val = (int) $exam['id'];
                    $is_live = (int) $exam['is_live'];
                    $circle_color = $is_live === 1 ? "#2e7d32" : "#9e9e9e";
                    $status_label = $is_live === 1 ? "Active" : "Inactive";
                    $saved_mins = (int) $exam['duration'];
                    $h_val = intdiv($saved_mins, 60);
                    $m_val = $saved_mins % 60;
                    $card_color = $exam['color_theme'] ?: '#0062cc';
                    $file_title = $exam['json_filename'] ?: 'questions.json';
                    $stream_label = $exam['stream'] ?: 'Natural Science';
                    $total_submissions = ExamRepository::submissionCount($exam_id_val);
                    $flagged_count = AttemptRepository::flaggedCountForExam($exam_id_val);
                    $is_download_disabled = ($is_live === 1 || $total_submissions === 0);
                    $download_btn_text = $is_live === 1 ? "🚫 Stop Exam to Download" : "📥 Download Results (.csv)";
                ?>
                <div class="exam-profile-card" id="profile-<?php echo $exam_id_val; ?>">
                    <div class="card-header-bar" style="background-color: <?php echo htmlspecialchars($card_color); ?>;"></div>
                    <div class="card-actions-wrapper">
                        <div class="action-circle-btn" title="Edit Profile" onclick='triggerEditModal(<?php echo json_encode([
                            'id' => $exam_id_val, 'name' => $exam['exam_name'], 'hh' => $h_val, 'mm' => $m_val,
                            'color' => $card_color, 'filename' => $file_title, 'stream' => $stream_label,
                            'shuffleQuestions' => (bool) $exam['shuffle_questions'], 'shuffleChoices' => (bool) $exam['shuffle_choices'],
                        ]); ?>, event)'>✏️</div>
                        <div class="action-circle-btn" title="Delete Profile" onclick="triggerDeletePopup(<?php echo $exam_id_val; ?>, <?php echo json_encode($exam['exam_name']); ?>, event)">🗑️</div>
                    </div>
                    <div class="card-body">
                        <div class="card-title"><?php echo htmlspecialchars($exam['exam_name']); ?></div>
                        <div class="card-meta">⏳ Duration: <strong><?php echo (int) $exam['duration']; ?> Mins</strong></div>
                        <div class="card-meta">📁 Track: <span class="track-chip"><?php echo htmlspecialchars($stream_label); ?></span></div>
                        <div class="card-meta">👥 Attempts: <strong><?php echo $total_submissions; ?></strong></div>
                        <?php if ((int) $exam['shuffle_questions'] || (int) $exam['shuffle_choices']): ?>
                            <div class="card-meta">🔀 Shuffling:
                                <?php
                                    $shuffleBits = [];
                                    if ((int) $exam['shuffle_questions']) $shuffleBits[] = 'Questions';
                                    if ((int) $exam['shuffle_choices']) $shuffleBits[] = 'Choices';
                                    echo htmlspecialchars(implode(' + ', $shuffleBits));
                                ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($flagged_count > 0): ?>
                            <div class="card-meta integrity-flag-meta">🚩 <strong><?php echo $flagged_count; ?></strong> flagged for integrity review</div>
                        <?php endif; ?>
                        <div class="status-container">
                            <span class="status-circle" style="background-color: <?php echo $circle_color; ?>;"></span>
                            <span><?php echo $status_label; ?></span>
                        </div>
                    </div>
                    <div class="card-footer-buttons">
                        <div class="footer-action-row">
                            <button class="card-btn btn-card-status-label">Saved ✓</button>
                            <a href="adminpanel.php?toggle_live=<?php echo $exam_id_val; ?>&current=<?php echo $is_live; ?>&csrf_token=<?php echo urlencode($csrf); ?>"
                               class="card-btn btn-card-start <?php echo $is_live === 1 ? 'live' : ''; ?>">
                                <?php echo $is_live === 1 ? 'Stop Exam' : 'Start Exam'; ?>
                            </a>
                        </div>
                        <button class="btn-card-download <?php echo $is_download_disabled ? 'disabled-btn' : ''; ?>"
                                onclick="downloadExamResults(<?php echo $exam_id_val; ?>)" <?php echo $is_download_disabled ? 'disabled' : ''; ?>>
                            <?php echo $download_btn_text; ?>
                        </button>
                        <button class="btn-card-download <?php echo $is_download_disabled ? 'disabled-btn' : ''; ?>"
                                onclick="downloadQuestionReport(<?php echo $exam_id_val; ?>)" <?php echo $is_download_disabled ? 'disabled' : ''; ?>>
                            📊 Question Analysis (.csv)
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="btn-create-card" onclick="openModalForCreate()">
                <div class="plus-icon">+</div>
                <div class="create-text">Create New Exam Profile</div>
            </div>
        </div>
    </main>

    <div class="modal-background" id="examModal">
        <div class="modal-card">
            <div class="modal-header">
                <span id="modalTitleLabel">Create Exam Profile</span>
                <span class="modal-close-btn" onclick="closeModal()">&times;</span>
            </div>
            <form action="adminpanel.php" method="POST" enctype="multipart/form-data">
                <?php echo Csrf::field(); ?>
                <input type="hidden" name="action" id="modalActionInput" value="create">
                <input type="hidden" name="exam_id" id="modalExamIdInput" value="">
                <input type="hidden" name="color_theme" id="selectedColorInput" value="#ff4a71">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Profile Name (Displayed Subject Text)</label>
                        <input type="text" name="exam_name" id="examName" class="form-control" placeholder="e.g., Chemistry Grade 12 Mock" required maxlength="150">
                    </div>
                    <div class="form-group">
                        <label>Target Stream</label>
                        <select name="stream" id="streamSelect" class="form-control" required>
                            <option value="Natural Science">Natural Science</option>
                            <option value="Social Science">Social Science</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Exam Duration</label>
                        <div class="time-inputs-row">
                            <input type="number" name="timeHH" id="timeHH" class="form-control time-box-input" placeholder="HH" min="0" max="23" value="2">
                            <input type="number" name="timeMM" id="timeMM" class="form-control time-box-input" placeholder="MM" min="0" max="59" value="0">
                            <input type="number" name="timeSS" id="timeSS" class="form-control time-box-input" placeholder="SS" min="0" max="59" value="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label id="fileLabelContainer">Upload Exam File (.json)</label>
                        <div class="file-status-badge" id="editFileStatusBadge">
                            📄 Current File: <span id="attachedFileName" style="text-decoration: underline;"></span>
                        </div>
                        <input type="file" name="exam_json" id="examJsonFile" class="form-control" accept=".json" required>
                        <small class="file-help-info" id="fileFieldInstructionText">Select a question configuration array file.</small>
                    </div>
                    <div class="form-group">
                        <label>Anti-Cheating: Per-Student Randomization</label>
                        <div class="checkbox-row">
                            <label class="checkbox-inline">
                                <input type="checkbox" name="shuffle_questions" id="shuffleQuestionsCheckbox" value="1">
                                Shuffle question order (each student gets a different sequence)
                            </label>
                        </div>
                        <div class="checkbox-row">
                            <label class="checkbox-inline">
                                <input type="checkbox" name="shuffle_choices" id="shuffleChoicesCheckbox" value="1">
                                Shuffle answer choice order (A/B/C/D) per student
                            </label>
                        </div>
                        <small class="file-help-info">Each student's order is generated once, the first time they open the exam, and never changes afterward — even if they refresh, lose connection, or resume later.</small>
                    </div>
                    <div class="form-group">
                        <label>Profile Header Color Theme</label>
                        <div class="color-picker-row">
                            <div class="color-dot selected" data-color="#ff4a71" style="background-color:#ff4a71;" onclick="selectColorTheme('#ff4a71', this)"></div>
                            <div class="color-dot" data-color="#0062cc" style="background-color:#0062cc;" onclick="selectColorTheme('#0062cc', this)"></div>
                            <div class="color-dot" data-color="#2e7d32" style="background-color:#2e7d32;" onclick="selectColorTheme('#2e7d32', this)"></div>
                            <div class="color-dot" data-color="#ff9800" style="background-color:#ff9800;" onclick="selectColorTheme('#ff9800', this)"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary" id="modalSubmitBtnLabel">Save Profile</button>
                </div>
            </form>
        </div>
    </div>

    <div class="safety-overlay" id="safetyPopupContainer">
        <div class="safety-box">
            <h3>Are you sure you want to delete?</h3>
            <p>You are about to permanently remove "<span id="displayTargetExamName" style="font-weight:bold;color:var(--color-danger);"></span>" and all of its questions and attempt records.</p>
            <div class="safety-buttons">
                <button class="btn btn-muted" onclick="closeDeletePopup()">No</button>
                <button class="btn btn-danger" id="confirmDeleteExecuteBtn">Yes</button>
            </div>
        </div>
    </div>

    <script>window.EAES_CSRF = <?php echo json_encode($csrf); ?>;</script>
    <script src="assets/js/admin.js"></script>
</body>
</html>
