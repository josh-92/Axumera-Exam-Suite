<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:03              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 require_once __DIR__ . '/app/bootstrap.php'; use App\Core\Csrf; use App\Core\License; use App\Core\Logger; use App\Core\Validator; use App\Repositories\AttemptRepository; use App\Repositories\ExamRepository; use App\Services\ExamImportService; if (!empty($_SESSION['admin_logged_in'])) { goto V_8ey; } header("Location: adminlogin.php"); exit; V_8ey: $TqCfa = []; $HDRVQ = ""; if (!(isset($_GET['action']) && $_GET['action'] === 'logout')) { goto Yis0A; } header("Location: logout.php"); exit; Yis0A: if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !Csrf::verify($_POST['csrf_token'] ?? null)) { goto bZiXZ; } if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) { goto M0sT5; } goto CRkPN; bZiXZ: $TqCfa[] = 'Your session expired. Please try again.'; goto CRkPN; M0sT5: $wKQPV = Validator::string($_POST['exam_name'] ?? '', 150); $RyO4C = Validator::int($_POST['timeHH'] ?? 0); $rtLxz = Validator::int($_POST['timeMM'] ?? 0); $Azv6R = Validator::int($_POST['timeSS'] ?? 0); $rxTRZ = $RyO4C * 60 + $rtLxz + (int) round($Azv6R / 60); if (!($rxTRZ <= 0)) { goto phao4; } $rxTRZ = 60; phao4: $kumtK = Validator::hexColor($_POST['color_theme'] ?? ''); $MKh3C = Validator::inArray($_POST['stream'] ?? '', ['Natural Science', 'Social Science'], 'Natural Science'); $tW2yW = ['exam_name' => $wKQPV, 'duration' => $rxTRZ, 'stream' => $MKh3C, 'color_theme' => $kumtK, 'shuffle_questions' => isset($_POST['shuffle_questions']), 'shuffle_choices' => isset($_POST['shuffle_choices'])]; if ($wKQPV === '') { goto OHbeO; } if ($_POST['action'] === 'create') { goto u3YYQ; } if ($_POST['action'] === 'edit') { goto Ayelu; } goto Vc5H1; OHbeO: $TqCfa[] = 'Exam name is required.'; goto Vc5H1; u3YYQ: if (!isset($_FILES['exam_json']) || $_FILES['exam_json']['error'] !== UPLOAD_ERR_OK) { goto zWQhz; } $wphBH = ExamImportService::createFromUpload($_FILES['exam_json'], $tW2yW); if ($wphBH['errors']) { goto ZLQay; } Logger::audit('admin', $_SESSION['admin_username'], 'exam_created', ['exam_id' => $wphBH['exam_id'], 'name' => $wKQPV]); header("Location: adminpanel.php?created=1"); exit; goto o3_4R; ZLQay: $TqCfa = $wphBH['errors']; o3_4R: goto xd2q1; zWQhz: $TqCfa[] = 'Please attach a valid .json exam file.'; xd2q1: goto Vc5H1; Ayelu: $sYOeU = Validator::int($_POST['exam_id'] ?? 0); if ($sYOeU <= 0 || !ExamRepository::find($sYOeU)) { goto JfKHJ; } if (isset($_FILES['exam_json']) && $_FILES['exam_json']['error'] === UPLOAD_ERR_OK) { goto uRKf5; } ExamRepository::updateMeta($sYOeU, $tW2yW); Logger::audit('admin', $_SESSION['admin_username'], 'exam_updated_meta_only', ['exam_id' => $sYOeU]); header("Location: adminpanel.php?updated=1"); exit; goto pqdYQ; JfKHJ: $TqCfa[] = 'The exam you tried to edit no longer exists.'; goto pqdYQ; uRKf5: $JgqOs = ExamImportService::replaceFromUpload($sYOeU, $_FILES['exam_json'], $tW2yW); if ($JgqOs) { goto sBdOA; } Logger::audit('admin', $_SESSION['admin_username'], 'exam_updated', ['exam_id' => $sYOeU]); header("Location: adminpanel.php?updated=1"); exit; goto EsdC8; sBdOA: $TqCfa = $JgqOs; EsdC8: pqdYQ: Vc5H1: CRkPN: if (!isset($_GET['toggle_live'])) { goto iz2Pz; } if (!Csrf::verify($_GET['csrf_token'] ?? null)) { goto Pek0d; } $k2KXu = Validator::int($_GET['toggle_live']); $i3q0r = Validator::int($_GET['current'] ?? 0); ExamRepository::setLive($k2KXu, $i3q0r === 0); Logger::audit('admin', $_SESSION['admin_username'], $i3q0r === 0 ? 'exam_started' : 'exam_stopped', ['exam_id' => $k2KXu]); header("Location: adminpanel.php"); exit; goto tJqLK; Pek0d: $TqCfa[] = 'Security check failed — please try again.'; tJqLK: iz2Pz: if (!isset($_GET['confirm_delete'])) { goto jMuFz; } if (!Csrf::verify($_GET['csrf_token'] ?? null)) { goto IKDMP; } $VAWCs = Validator::int($_GET['confirm_delete']); ExamRepository::delete($VAWCs); Logger::audit('admin', $_SESSION['admin_username'], 'exam_deleted', ['exam_id' => $VAWCs]); header("Location: adminpanel.php?deleted=1"); exit; goto g1iCg; IKDMP: $TqCfa[] = 'Security check failed — please try again.'; g1iCg: jMuFz: if (!isset($_GET['created'])) { goto NhwH2; } $HDRVQ = 'Exam profile created successfully.'; NhwH2: if (!isset($_GET['updated'])) { goto GuPzq; } $HDRVQ = 'Exam profile updated successfully.'; GuPzq: if (!isset($_GET['deleted'])) { goto ITqJG; } $HDRVQ = 'Exam profile deleted.'; ITqJG: $XWxFE = ExamRepository::all(); $s4MKw = License::status(); $X31ml = Csrf::token(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel — <?php  echo htmlspecialchars(b_K5T('app.name')); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
    <?php  include __DIR__ . '/partials/admin_header.php'; ?>

    <main class="dashboard-content">
        <?php  if ($s4MKw['valid']) { goto QO3Qw; } ?>
            <div class="alert alert-warning">
                ⚠️ License not active (<?php  echo htmlspecialchars($s4MKw['message']); ?>).
                <a href="license.php">Activate your license</a> to remove this notice.
            </div>
        <?php  QO3Qw: ?>

        <?php  if (!$HDRVQ) { goto dLAse; } ?><div class="alert alert-success">✅ <?php  echo htmlspecialchars($HDRVQ); ?></div><?php  dLAse: ?>
        <?php  foreach ($TqCfa as $NacY1) { ?><div class="alert alert-error">⚠️ <?php  echo htmlspecialchars($NacY1); ?></div><?php  TfSm9: } soOip: ?>

        <div class="exam-grid" id="examProfilesGrid">
            <?php  foreach ($XWxFE as $QR3LD) { ?>
                <?php  $S1K9N = (int) $QR3LD['id']; $jIcA3 = (int) $QR3LD['is_live']; $sWN9a = $jIcA3 === 1 ? "#2e7d32" : "#9e9e9e"; $iAnER = $jIcA3 === 1 ? "Active" : "Inactive"; $yxI9r = (int) $QR3LD['duration']; $JC3b0 = intdiv($yxI9r, 60); $aEG3b = $yxI9r % 60; $yxQMv = $QR3LD['color_theme'] ?: '#0062cc'; $Ht2Zq = $QR3LD['json_filename'] ?: 'questions.json'; $jc77k = $QR3LD['stream'] ?: 'Natural Science'; $l7mCm = ExamRepository::submissionCount($S1K9N); $oEA7G = AttemptRepository::flaggedCountForExam($S1K9N); $DPoMi = $jIcA3 === 1 || $l7mCm === 0; $yTjAF = $jIcA3 === 1 ? "🚫 Stop Exam to Download" : "📥 Download Results (.csv)"; ?>
                <div class="exam-profile-card" id="profile-<?php  echo $S1K9N; ?>">
                    <div class="card-header-bar" style="background-color: <?php  echo htmlspecialchars($yxQMv); ?>;"></div>
                    <div class="card-actions-wrapper">
                        <div class="action-circle-btn" title="Edit Profile" onclick='triggerEditModal(<?php  echo htmlspecialchars(json_encode(['id' => $S1K9N, 'name' => $QR3LD['exam_name'], 'hh' => $JC3b0, 'mm' => $aEG3b, 'color' => $yxQMv, 'filename' => $Ht2Zq, 'stream' => $jc77k, 'shuffleQuestions' => (bool) $QR3LD['shuffle_questions'], 'shuffleChoices' => (bool) $QR3LD['shuffle_choices']]), ENT_QUOTES, 'UTF-8'); ?>, event)'>✏️</div>
                        <div class="action-circle-btn" title="Delete Profile" onclick='triggerDeletePopup(<?php  echo $S1K9N; ?>, <?php  echo htmlspecialchars(json_encode($QR3LD['exam_name']), ENT_QUOTES, 'UTF-8'); ?>, event)'>🗑️</div>
                    </div>
                    <div class="card-body">
                        <div class="card-title"><?php  echo htmlspecialchars($QR3LD['exam_name']); ?></div>
                        <div class="card-meta">⏳ Duration: <strong><?php  echo (int) $QR3LD['duration']; ?> Mins</strong></div>
                        <div class="card-meta">📁 Track: <span class="track-chip"><?php  echo htmlspecialchars($jc77k); ?></span></div>
                        <div class="card-meta">👥 Attempts: <strong><?php  echo $l7mCm; ?></strong></div>
                        <?php  if (!((int) $QR3LD['shuffle_questions'] || (int) $QR3LD['shuffle_choices'])) { goto cQH_f; } ?>
                            <div class="card-meta">🔀 Shuffling:
                                <?php  $Aj4cN = []; if (!(int) $QR3LD['shuffle_questions']) { goto LfgWI; } $Aj4cN[] = 'Questions'; LfgWI: if (!(int) $QR3LD['shuffle_choices']) { goto ByqdN; } $Aj4cN[] = 'Choices'; ByqdN: echo htmlspecialchars(implode(' + ', $Aj4cN)); ?>
                            </div>
                        <?php  cQH_f: ?>
                        <?php  if (!($oEA7G > 0)) { goto UJW4a; } ?>
                            <div class="card-meta integrity-flag-meta">🚩 <strong><?php  echo $oEA7G; ?></strong> flagged for integrity review</div>
                        <?php  UJW4a: ?>
                        <div class="status-container">
                            <span class="status-circle" style="background-color: <?php  echo $sWN9a; ?>;"></span>
                            <span><?php  echo $iAnER; ?></span>
                        </div>
                    </div>
                    <div class="card-footer-buttons">
                        <div class="footer-action-row">
                            <button class="card-btn btn-card-status-label">Saved ✓</button>
                            <a href="adminpanel.php?toggle_live=<?php  echo $S1K9N; ?>&current=<?php  echo $jIcA3; ?>&csrf_token=<?php  echo urlencode($X31ml); ?>"
                               class="card-btn btn-card-start <?php  echo $jIcA3 === 1 ? 'live' : ''; ?>">
                                <?php  echo $jIcA3 === 1 ? 'Stop Exam' : 'Start Exam'; ?>
                            </a>
                        </div>
                        <button class="btn-card-download <?php  echo $DPoMi ? 'disabled-btn' : ''; ?>"
                                onclick="downloadExamResults(<?php  echo $S1K9N; ?>)" <?php  echo $DPoMi ? 'disabled' : ''; ?>>
                            <?php  echo $yTjAF; ?>
                        </button>
                        <button class="btn-card-download <?php  echo $DPoMi ? 'disabled-btn' : ''; ?>"
                                onclick="downloadQuestionReport(<?php  echo $S1K9N; ?>)" <?php  echo $DPoMi ? 'disabled' : ''; ?>>
                            📊 Question Analysis (.csv)
                        </button>
                    </div>
                </div>
            <?php  rko10: } O0oSE: ?>

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
                <?php  echo Csrf::field(); ?>
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

    <script>window.EAES_CSRF = <?php  echo json_encode($X31ml); ?>;</script>
    <script src="assets/js/admin.js"></script>
    <?php include __DIR__ . '/partials/copyright_footer.php'; ?>
</body>
</html>
