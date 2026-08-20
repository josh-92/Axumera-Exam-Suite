<?php
require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Validator;
use App\Repositories\StudentRepository;

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: adminlogin.php');
    exit;
}

$error = '';
$notice = '';
$tempPassword = '';
$generatedFor = '';
$importIssues = [];
$removeIssues = [];
$view = (string) ($_GET['view'] ?? 'active');
if (!in_array($view, ['active', 'archived'], true)) {
    $view = 'active';
}

// GET-only admin actions: CSV template + the one-time credentials download.
$getAction = (string) ($_GET['action'] ?? '');
if ($getAction === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_import_template.csv"');
    echo "full_name,roll_number,stream,section\n";
    exit;
}
if ($getAction === 'remove_template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_remove_template.csv"');
    echo "roll_number,stream\n1,Natural Science\n2,Social Science\n";
    exit;
}
if ($getAction === 'discard_preview') {
    unset($_SESSION['remove_preview']);
    header('Location: admin_students.php?view=active');
    exit;
}
if ($getAction === 'download_credentials') {
    $pending = (string) ($_SESSION['pending_credentials_csv'] ?? '');
    unset($_SESSION['pending_credentials_csv'], $_SESSION['pending_credentials_label']);
    if ($pending === '') {
        header('Location: admin_students.php');
        exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_credentials_' . date('Ymd_His') . '.csv"');
    echo $pending;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'add_student') {
            $fullName = Validator::string($_POST['fullname'] ?? '', 100);
            $roll = Validator::rollNumber($_POST['rollnumber'] ?? null);
            $stream = Validator::inArray($_POST['stream'] ?? '', ['Natural Science', 'Social Science']);
            $section = Validator::inArray($_POST['section'] ?? '', ['A', 'B', 'C']);
            $password = (string) ($_POST['password'] ?? '');

            if ($fullName === '' || $roll === null || $stream === null || $section === null) {
                $error = 'Please fill in all fields with valid values (Roll Number must be 1-999).';
            } elseif ($password !== '' && (mb_strlen($password) < 6 || mb_strlen($password) > 72)) {
                $error = 'Password must be between 6 and 72 characters (or leave it blank to auto-generate one).';
            } else {
                // Auto-generate a temporary password only when the field is
                // blank, but never DISPLAY it unless the account is created.
                $autoPassword = ($password === '');
                if ($autoPassword) {
                    $password = bin2hex(random_bytes(5));
                }
                $result = StudentRepository::provision($fullName, (string) $roll, $stream, $section, $password);
                if ($result['ok']) {
                    if ($autoPassword) {
                        $tempPassword = $password;
                    }
                    $generatedFor = $fullName;
                    $notice = $result['mode'] === 'claimed'
                        ? 'Student added — a previously password-less record for this roll number was activated.'
                        : 'Student added successfully.';
                    Logger::audit('admin', $_SESSION['admin_username'], 'student_added', [
                        'student_id' => $result['id'],
                        'roll' => (int) $roll,
                        'stream' => $stream,
                        'section' => $section,
                        'mode' => $result['mode'],
                    ]);
                } else {
                    $error = $result['error'];
                }
            }
        } elseif ($action === 'import_students') {
            $file = $_FILES['student_csv'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'Please choose a CSV or Excel file to import.';
            } elseif ((int) $file['size'] > 5 * 1024 * 1024) {
                $error = 'The file is too large (max 5 MB).';
            } elseif (!is_uploaded_file((string) $file['tmp_name'])) {
                $error = 'The uploaded file could not be verified.';
            } elseif (!in_array(strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION)), ['csv', 'txt', 'xlsx'], true)) {
                $error = 'Please upload a .csv, .txt, or Excel (.xlsx) file.';
            } else {
                try {
                    $rows = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION)) === 'xlsx'
                        ? StudentRepository::parseXlsx((string) $file['tmp_name'])
                        : StudentRepository::parseCsv((string) file_get_contents((string) $file['tmp_name']));
                } catch (\InvalidArgumentException $e) {
                    $error = $e->getMessage();
                    $rows = [];
                }
                if ($rows) {
                    $result = StudentRepository::importBatch($rows);
                    $created = $result['created'];
                    $claimed = $result['claimed'];
                    $skipped = $result['skipped'];
                    $failed = $result['errors'];

                    $notice = 'Imported ' . $created . ' new student(s)' . ($claimed ? ' and activated ' . $claimed . ' legacy record(s)' : '') . '.';
                    if ($skipped || $failed) {
                        $notice .= ' ' . $skipped . ' skipped and ' . $failed . ' error(s) — see the notes below.';
                    }

                    // One-time credentials manifest — the ONLY record of the
                    // generated temp passwords. Stored in the session, streamed
                    // once on download, never written to the audit log.
                    $csv = "roll_number,full_name,stream,section,temporary_password,status,note\n";
                    foreach ($result['rows'] as $r) {
                        $note = '';
                        if ($r['status'] === 'skipped') {
                            $note = str_contains((string) $r['error'], 'already registered')
                                ? 'Already registered — use Reset to issue a new password.'
                                : (string) $r['error'];
                            $importIssues[] = 'Line ' . $r['line'] . ': ' . $r['name'] . ' (roll ' . $r['roll'] . ') — ' . $note;
                        } elseif ($r['status'] === 'error') {
                            $note = (string) $r['error'];
                            $importIssues[] = 'Line ' . $r['line'] . ': roll ' . $r['roll'] . ' — ' . $note;
                        } else {
                            $note = $r['status'] === 'claimed' ? 'activated existing record' : 'new account';
                        }
                        $csv .= implode(',', array_map(fn ($cell) => '"' . str_replace('"', '""', (string) $cell) . '"', [
                            $r['roll'], $r['name'], $r['stream'], $r['section'],
                            $r['password'] ?? '', $r['status'], $note,
                        ])) . "\n";
                    }
                    $_SESSION['pending_credentials_csv'] = $csv;
                    $_SESSION['pending_credentials_label'] = $created . ' created, ' . $claimed . ' activated';
                    Logger::audit('admin', $_SESSION['admin_username'], 'students_imported', [
                        'created' => $created,
                        'claimed' => $claimed,
                        'skipped' => $skipped,
                        'errors' => $failed,
                    ]);
                }
            }
        } elseif ($action === 'remove_student') {
            $studentId = Validator::int($_POST['student_id'] ?? 0);
            $student = $studentId > 0 ? StudentRepository::findById($studentId) : null;
            if (!$student) {
                $error = 'That student no longer exists.';
            } elseif (StudentRepository::hasLiveInProgressAttempt($studentId)) {
                $error = 'This student has an in-progress attempt on a live exam. Stop the exam before removing them.';
            } else {
                // Removal now ARCHIVES (soft-delete): the account disappears
                // from login/lists but stays fully restorable from the
                // Archived tab — attempt history included.
                $attempts = StudentRepository::attemptCount($studentId);
                StudentRepository::archive($studentId);
                $notice = 'Archived "' . (string) $student['full_name'] . '" (Roll ' . (int) $student['roll_number'] . ').';
                if ($attempts > 0) {
                    $notice .= ' Their ' . (int) $attempts . ' attempt record(s) are preserved — you can restore them from the Archived tab.';
                } else {
                    $notice .= ' You can restore them from the Archived tab.';
                }
                Logger::audit('admin', $_SESSION['admin_username'], 'student_archived', [
                    'student_id' => $studentId,
                    'roll' => (int) $student['roll_number'],
                    'preserved_attempts' => $attempts,
                ]);
            }
        } elseif ($action === 'restore_student') {
            $studentId = Validator::int($_POST['student_id'] ?? 0);
            $student = $studentId > 0 ? StudentRepository::findById($studentId, true) : null;
            if (!$student || $student['deleted_at'] === null) {
                $error = 'That archived student no longer exists.';
            } else {
                StudentRepository::restore($studentId);
                $notice = 'Restored "' . (string) $student['full_name'] . '" (Roll ' . (int) $student['roll_number'] . ') — they can log in again.';
                Logger::audit('admin', $_SESSION['admin_username'], 'student_restored', [
                    'student_id' => $studentId,
                    'roll' => (int) $student['roll_number'],
                ]);
            }
        } elseif ($action === 'purge_student') {
            $studentId = Validator::int($_POST['student_id'] ?? 0);
            $student = $studentId > 0 ? StudentRepository::findById($studentId, true) : null;
            if (!$student || $student['deleted_at'] === null) {
                $error = 'That archived student no longer exists.';
            } else {
                $attempts = StudentRepository::attemptCount($studentId);
                try {
                    StudentRepository::purge($studentId);
                } catch (\Throwable $e) {
                    Logger::error('Student purge failed: ' . $e->getMessage());
                    $error = 'This student could not be permanently deleted because other records still reference them. Contact your administrator.';
                    $studentId = 0; // keep the row visible
                }
                if ($studentId > 0) {
                    $notice = 'Permanently deleted "' . (string) $student['full_name'] . '" (Roll ' . (int) $student['roll_number'] . ').';
                    if ($attempts > 0) {
                        $notice .= ' Their ' . (int) $attempts . ' attempt record(s) and results were deleted with them. This cannot be undone.';
                    }
                    Logger::audit('admin', $_SESSION['admin_username'], 'student_purged', [
                        'student_id' => $studentId,
                        'roll' => (int) $student['roll_number'],
                        'deleted_attempts' => $attempts,
                    ]);
                }
            }
        } elseif ($action === 'preview_remove_students') {
            // Step 1 of the two-step removal: parse the file and RESOLVE who
            // would be archived, but touch nothing. The confirm screen is
            // rendered from $_SESSION['remove_preview'] in the same response.
            $file = $_FILES['student_remove_list'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'Please choose a file with the roll numbers to remove.';
            } elseif ((int) $file['size'] > 5 * 1024 * 1024) {
                $error = 'The file is too large (max 5 MB).';
            } elseif (!is_uploaded_file((string) $file['tmp_name'])) {
                $error = 'The uploaded file could not be verified.';
            } elseif (!in_array(strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION)), ['csv', 'txt', 'xlsx'], true)) {
                $error = 'Please upload a .csv, .txt, or Excel (.xlsx) file.';
            } else {
                try {
                    $entries = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION)) === 'xlsx'
                        ? StudentRepository::parseRollXlsx((string) $file['tmp_name'])
                        : StudentRepository::parseRollCsv((string) file_get_contents((string) $file['tmp_name']));
                    $resolution = StudentRepository::resolveRemovalEntries($entries);
                    $archiveIds = [];
                    $issues = [];
                    foreach ($resolution['rows'] as $r) {
                        if ($r['status'] === 'removed') {
                            $archiveIds[] = (int) $r['student_id'];
                        } else {
                            $issues[] = 'Line ' . $r['line'] . ': ' . ($r['name'] !== '' ? $r['name'] . ' ' : '') . '(roll ' . $r['roll'] . ')'
                                . ($r['stream'] !== '' ? ' — ' . $r['stream'] : '')
                                . ' — ' . $r['reason'];
                        }
                    }
                    // The preview stores the EXACT student ids to archive; the
                    // confirm step re-checks them before touching anything.
                    $_SESSION['remove_preview'] = [
                        'created_at' => time(),
                        'archive_ids' => $archiveIds,
                        'rows' => $resolution['rows'],
                        'issues' => $issues,
                    ];
                } catch (\InvalidArgumentException $e) {
                    $error = $e->getMessage();
                }
            }
        } elseif ($action === 'remove_students_batch') {
            // Step 2 (confirm): no file upload — archives the exact student
            // IDs chosen on the preview screen. Guards are re-checked by
            // archiveCandidates (a student may have been removed or gone
            // mid-exam since the preview).
            $preview = $_SESSION['remove_preview'] ?? null;
            if (!is_array($preview) || time() - (int) ($preview['created_at'] ?? 0) > 1800) {
                unset($_SESSION['remove_preview']);
                $error = 'The removal preview expired. Please upload the list again.';
            } else {
                $result = StudentRepository::archiveCandidates(array_map('intval', $preview['archive_ids'] ?? []));
                $removeIssues = [];
                foreach ($preview['issues'] ?? [] as $issue) {
                    $removeIssues[] = $issue;
                }
                foreach ($result['rows'] as $r) {
                    if ($r['status'] !== 'removed') {
                        $removeIssues[] = $r['name'] !== '' ? $r['name'] . ' (roll ' . $r['roll'] . ') — ' . $r['reason'] : $r['reason'];
                    }
                }
                unset($_SESSION['remove_preview']);
                $notice = 'Archived ' . $result['removed'] . ' student(s) — restorable from the Archived tab.';
                $notesCount = count($removeIssues);
                if ($notesCount > 0) {
                    $notice .= ' ' . $notesCount . ' entr' . ($notesCount === 1 ? 'y' : 'ies') . ' were not archived — see the notes below.';
                }
                Logger::audit('admin', $_SESSION['admin_username'], 'students_bulk_archived', [
                    'removed' => $result['removed'],
                    'skipped' => $result['skipped'],
                    'preview_issues' => count($preview['issues'] ?? []),
                ]);
            }
        } elseif ($action === 'reset_password') {
            $studentId = Validator::int($_POST['student_id'] ?? 0);
            $student = $studentId > 0 ? StudentRepository::findById($studentId) : null;
            if (!$student) {
                $error = 'That student no longer exists.';
            } else {
                // Teacher-assisted recovery: generate a one-time temp password the
                // teacher hands to the student. Only the hashed value is stored;
                // the plaintext is shown once here and never written to the log.
                $tempPassword = bin2hex(random_bytes(5));
                StudentRepository::setPassword($studentId, $tempPassword);
                $generatedFor = (string) $student['full_name'];
                Logger::audit('admin', $_SESSION['admin_username'], 'student_password_reset', [
                    'student_id' => $studentId,
                    'roll' => (int) $student['roll_number'],
                    'stream' => (string) $student['stream'],
                ]);
            }
        } else {
            $error = 'Invalid action.';
        }
    }
}

$hasCredentials = !empty($_SESSION['pending_credentials_csv']);
$term = trim((string) ($_GET['q'] ?? ''));
$students = $view === 'archived' ? StudentRepository::searchArchived($term) : StudentRepository::search($term);
$activeTotal = count(StudentRepository::search('', 1000));
$archivedTotal = count(StudentRepository::searchArchived('', 1000));

// Pending removal preview (the confirm screen). Fresh for 30 minutes, then discarded.
$preview = null;
if (!empty($_SESSION['remove_preview']) && is_array($_SESSION['remove_preview'])
    && time() - (int) ($_SESSION['remove_preview']['created_at'] ?? 0) <= 1800) {
    $preview = $_SESSION['remove_preview'];
} else {
    unset($_SESSION['remove_preview']);
}
$previewCandidates = $preview ? array_values(array_filter($preview['rows'], fn ($r) => $r['status'] === 'removed')) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students - <?php echo htmlspecialchars(b_K5t('app.name')); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <style>
        .students-wrap { max-width: 1180px; margin: 0 auto; padding: 28px 20px 60px; }
        .students-head { display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 14px; margin-bottom: 20px; }
        .students-head h1 { font-size: 22px; margin: 0; color: var(--color-ink); }
        .students-head p { margin: 4px 0 0; color: var(--color-muted); font-size: 13px; }
        .students-tools { display: flex; gap: 10px; align-items: center; }
        .students-tools form { display: flex; gap: 10px; align-items: center; }
        .student-table-wrap { overflow-x: auto; }
        table.student-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--color-border); border-radius: 12px; overflow: hidden; }
        table.student-table th, table.student-table td { padding: 12px 14px; text-align: left; font-size: 13.5px; border-bottom: 1px solid var(--color-border); }
        table.student-table th { background: #f8fafc; color: var(--color-muted); font-weight: 600; text-transform: uppercase; font-size: 11.5px; letter-spacing: .4px; }
        table.student-table tr:last-child td { border-bottom: none; }
        table.student-table tr:hover td { background: #fafcff; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; }
        .badge-ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .badge-warn { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .temp-pw-box { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 10px; padding: 14px 18px; margin-bottom: 20px; font-size: 14px; }
        .temp-pw-box code { background: #fef3c7; padding: 3px 10px; border-radius: 6px; font-size: 16px; font-weight: 700; letter-spacing: 1px; }
        .reset-form, .remove-form { display: inline; }
        .btn-sm { font-size: 12.5px; padding: 6px 12px; }
        .btn-remove { color: var(--color-danger); border-color: #fecaca; background: #fff; }
        .btn-remove:hover { background: #fef2f2; }
        .row-actions { display: flex; gap: 6px; justify-content: flex-end; }
        .muted-note { color: var(--color-muted); font-size: 12.5px; }
        .modal-body .form-group label { font-weight: 600; font-size: 13px; }
        .pw-help { font-size: 12px; color: var(--color-muted); margin: 4px 0 0; text-align: left; }
    </style>
</head>
<body>
    <div class="page-shell">
    <?php include __DIR__ . '/partials/admin_header.php'; ?>

    <div class="students-wrap">
        <?php if ($preview) { ?>
            <div class="students-head">
                <div>
                    <h1>Confirm removal</h1>
                    <p>Review exactly who will be archived from your list. <strong>Nothing has been touched yet</strong> — archiving is reversible from the Archived tab.</p>
                </div>
            </div>

            <?php if (!$previewCandidates) { ?>
                <div class="alert alert-warning">
                    <strong>Nothing to archive.</strong> No active students matched the roll numbers in your file — see the notes below.
                </div>
            <?php } else { ?>
                <div class="student-table-wrap card" style="padding:0;">
                    <table class="student-table">
                        <thead>
                            <tr><th>Roll</th><th>Full Name</th><th>Stream</th><th>Section</th><th>Attempts</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($previewCandidates as $r) { ?>
                                <tr>
                                    <td><strong><?php echo (int) $r['roll']; ?></strong></td>
                                    <td><?php echo htmlspecialchars((string) $r['name']); ?></td>
                                    <td><?php echo htmlspecialchars((string) $r['stream']); ?></td>
                                    <td><?php echo htmlspecialchars((string) $r['section']); ?></td>
                                    <td class="muted-note"><?php echo (int) $r['attempts']; ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>

            <?php if (!empty($preview['issues'])) { ?>
                <div class="alert alert-warning">
                    <strong>Not found / blocked — will NOT be archived:</strong>
                    <ul style="margin:8px 0 0 18px;padding:0;">
                        <?php foreach ($preview['issues'] as $issue) { ?><li><?php echo htmlspecialchars($issue); ?></li><?php } ?>
                    </ul>
                </div>
            <?php } ?>

            <div style="display:flex;gap:10px;margin-top:20px;">
                <form method="POST" action="admin_students.php?view=active">
                    <?php echo Csrf::field(); ?>
                    <input type="hidden" name="action" value="remove_students_batch">
                    <button type="submit" class="btn btn-primary" <?php echo !$previewCandidates ? 'disabled' : ''; ?>>Yes — archive <?php echo count($previewCandidates); ?> student(s) (restorable)</button>
                </form>
                <a href="admin_students.php?action=discard_preview" class="btn btn-muted">Cancel — keep everyone</a>
            </div>
        <?php } else { ?>
        <div class="students-head">
            <div>
                <h1>Student Accounts</h1>
                <p>Student ID = Roll Number + Stream. Import a whole class from CSV/Excel, add students one at a time, reset forgotten passwords, and remove mistaken entries. Removing now <strong>archives</strong> — nothing is permanently deleted until you do so from the Archived tab.</p>
                <div style="margin-top:12px;display:flex;gap:8px;">
                    <a href="admin_students.php?view=active<?php echo $term !== '' ? '&q=' . urlencode($term) : ''; ?>" class="btn <?php echo $view === 'active' ? 'btn-primary' : 'btn-muted'; ?> btn-sm">Active (<?php echo (int) $activeTotal; ?>)</a>
                    <a href="admin_students.php?view=archived<?php echo $term !== '' ? '&q=' . urlencode($term) : ''; ?>" class="btn <?php echo $view === 'archived' ? 'btn-primary' : 'btn-muted'; ?> btn-sm">Archived (<?php echo (int) $archivedTotal; ?>)</a>
                </div>
            </div>
            <div class="students-tools">
                <form method="GET" action="admin_students.php">
                    <input type="hidden" name="view" value="<?php echo $view; ?>">
                    <input type="text" name="q" class="form-control" placeholder="Search roll, name, stream…" value="<?php echo htmlspecialchars($term); ?>">
                    <button type="submit" class="btn btn-primary">Search</button>
                    <?php if ($term !== '') { ?><a href="admin_students.php?view=<?php echo $view; ?>" class="btn btn-muted">Clear</a><?php } ?>
                </form>
                <?php if ($view === 'active') { ?>
                    <button type="button" class="btn btn-primary" onclick="openImportModal()">⬆ Import CSV / Excel</button>
                    <button type="button" class="btn btn-primary" onclick="openRemoveModal()">🗑 Remove by List</button>
                    <button type="button" class="btn btn-primary" onclick="openAddModal()">＋ Add Student</button>
                <?php } ?>
            </div>
        </div>

        <?php if ($error !== '') { ?>
            <div class="alert alert-error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php } ?>
        <?php if ($notice !== '') { ?>
            <div class="alert alert-success">✅ <?php echo htmlspecialchars($notice); ?></div>
        <?php } ?>
        <?php if ($tempPassword !== '') { ?>
            <div class="temp-pw-box">
                <strong>🔑 Temporary password for <?php echo htmlspecialchars($generatedFor); ?></strong><br>
                <code><?php echo htmlspecialchars($tempPassword); ?></code><br>
                <span class="muted-note">Give this to the student in private. It is shown only once; the student can change it any time from the login page → “Forgot password?”.</span>
            </div>
        <?php } ?>
        <?php if ($hasCredentials) { ?>
            <div class="temp-pw-box">
                <strong>📥 Class credentials ready to hand out</strong><br>
                <span class="muted-note"><?php echo htmlspecialchars((string) ($_SESSION['pending_credentials_label'] ?? 'Students imported')); ?> — the temporary passwords exist only here and are cleared after download.</span><br>
                <a class="btn btn-primary btn-sm" style="margin-top:10px;display:inline-block;" href="admin_students.php?action=download_credentials">Download credentials (.csv)</a>
            </div>
        <?php } ?>
        <?php if (!empty($importIssues)) { ?>
            <div class="alert alert-warning">
                <strong>Skipped / invalid rows:</strong>
                <ul style="margin:8px 0 0 18px;padding:0;">
                    <?php foreach ($importIssues as $issue) { ?><li><?php echo htmlspecialchars($issue); ?></li><?php } ?>
                </ul>
            </div>
        <?php } ?>
        <?php if (!empty($removeIssues)) { ?>
            <div class="alert alert-warning">
                <strong>Skipped / not found:</strong>
                <ul style="margin:8px 0 0 18px;padding:0;">
                    <?php foreach ($removeIssues as $issue) { ?><li><?php echo htmlspecialchars($issue); ?></li><?php } ?>
                </ul>
            </div>
        <?php } ?>

        <div class="student-table-wrap card" style="padding:0;">
            <?php if ($view === 'archived') { ?>
                <table class="student-table">
                    <thead>
                        <tr>
                            <th>Roll</th>
                            <th>Full Name</th>
                            <th>Stream</th>
                            <th>Section</th>
                            <th>Archived</th>
                            <th>Attempts</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$students) { ?>
                            <tr><td colspan="7" class="muted-note" style="padding:24px;text-align:center;">No archived students. Removed students land here — restore them to undo.</td></tr>
                        <?php } ?>
                        <?php foreach ($students as $s) { ?>
                            <tr>
                                <td><strong><?php echo (int) $s['roll_number']; ?></strong></td>
                                <td><?php echo htmlspecialchars((string) $s['full_name']); ?></td>
                                <td><?php echo htmlspecialchars((string) $s['stream']); ?></td>
                                <td><?php echo htmlspecialchars((string) $s['section']); ?></td>
                                <td class="muted-note"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string) $s['deleted_at']))); ?></td>
                                <td class="muted-note"><?php echo (int) ($s['attempt_count'] ?? 0); ?></td>
                                <td>
                                    <div class="row-actions">
                                        <form class="restore-form" method="POST" action="admin_students.php?view=archived&q=<?php echo urlencode($term); ?>"
                                              data-name="<?php echo htmlspecialchars((string) $s['full_name'], ENT_QUOTES); ?>"
                                              data-roll="<?php echo (int) $s['roll_number']; ?>">
                                            <?php echo Csrf::field(); ?>
                                            <input type="hidden" name="action" value="restore_student">
                                            <input type="hidden" name="student_id" value="<?php echo (int) $s['id']; ?>">
                                            <button type="submit" class="btn btn-muted btn-sm">↩️ Restore</button>
                                        </form>
                                        <form class="purge-form" method="POST" action="admin_students.php?view=archived&q=<?php echo urlencode($term); ?>"
                                              data-name="<?php echo htmlspecialchars((string) $s['full_name'], ENT_QUOTES); ?>"
                                              data-roll="<?php echo (int) $s['roll_number']; ?>"
                                              data-attempts="<?php echo (int) ($s['attempt_count'] ?? 0); ?>">
                                            <?php echo Csrf::field(); ?>
                                            <input type="hidden" name="action" value="purge_student">
                                            <input type="hidden" name="student_id" value="<?php echo (int) $s['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-remove">🗑 Delete permanently</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } else { ?>
                <table class="student-table">
                    <thead>
                        <tr>
                            <th>Roll</th>
                            <th>Full Name</th>
                            <th>Stream</th>
                            <th>Section</th>
                            <th>Account</th>
                            <th>Last Login</th>
                            <th>Attempts</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$students) { ?>
                            <tr><td colspan="8" class="muted-note" style="padding:24px;text-align:center;">No students found.</td></tr>
                        <?php } ?>
                        <?php foreach ($students as $s) { ?>
                            <tr>
                                <td><strong><?php echo (int) $s['roll_number']; ?></strong></td>
                                <td><?php echo htmlspecialchars((string) $s['full_name']); ?></td>
                                <td><?php echo htmlspecialchars((string) $s['stream']); ?></td>
                                <td><?php echo htmlspecialchars((string) $s['section']); ?></td>
                                <td>
                                    <?php if (StudentRepository::hasPassword($s)) { ?>
                                        <span class="badge badge-ok">Registered</span>
                                    <?php } else { ?>
                                        <span class="badge badge-warn">No password yet</span>
                                    <?php } ?>
                                </td>
                                <td class="muted-note"><?php echo $s['last_login_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime((string) $s['last_login_at']))) : '—'; ?></td>
                                <td class="muted-note">
                                    <?php echo (int) ($s['attempt_count'] ?? 0); ?>
                                    <?php if (!empty($s['last_attempt_status'])) { ?>
                                        · <?php echo htmlspecialchars((string) $s['last_attempt_status']); ?>
                                        <?php if ($s['last_attempt_score'] !== null) { ?> (<?php echo (int) $s['last_attempt_score']; ?>)<?php } ?>
                                    <?php } ?>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <form class="reset-form" method="POST" action="admin_students.php?view=active&q=<?php echo urlencode($term); ?>"
                                              data-name="<?php echo htmlspecialchars((string) $s['full_name'], ENT_QUOTES); ?>"
                                              data-roll="<?php echo (int) $s['roll_number']; ?>">
                                            <?php echo Csrf::field(); ?>
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="student_id" value="<?php echo (int) $s['id']; ?>">
                                            <button type="submit" class="btn btn-muted btn-sm">🔑 Reset</button>
                                        </form>
                                        <form class="remove-form" method="POST" action="admin_students.php?view=active&q=<?php echo urlencode($term); ?>"
                                              data-name="<?php echo htmlspecialchars((string) $s['full_name'], ENT_QUOTES); ?>"
                                              data-roll="<?php echo (int) $s['roll_number']; ?>"
                                              data-attempts="<?php echo (int) ($s['attempt_count'] ?? 0); ?>">
                                            <?php echo Csrf::field(); ?>
                                            <input type="hidden" name="action" value="remove_student">
                                            <input type="hidden" name="student_id" value="<?php echo (int) $s['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-remove">🗑 Remove</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } ?>
        </div>
        </div>
    <?php } ?>

    <!-- Add Student modal -->
    <div class="modal-background" id="add-student-modal">
        <div class="modal-card">
            <div class="modal-header">
                <span>＋ Add Student</span>
                <span class="modal-close-btn" onclick="closeAddModal()">×</span>
            </div>
            <div class="modal-body">
                <form method="POST" action="admin_students.php">
                    <?php echo Csrf::field(); ?>
                    <input type="hidden" name="action" value="add_student">
                    <div class="form-group">
                        <label for="af-fullname">Full Name</label>
                        <input type="text" id="af-fullname" name="fullname" class="form-control" placeholder="As written by the school" required maxlength="100">
                    </div>
                    <div class="form-group">
                        <label for="af-rollnumber">Roll Number (ID)</label>
                        <input type="number" id="af-rollnumber" name="rollnumber" class="form-control" placeholder="e.g. 105" min="1" max="999" required>
                    </div>
                    <div class="form-group">
                        <label for="af-stream">Stream</label>
                        <select id="af-stream" name="stream" class="form-control" required>
                            <option value="" disabled selected>Choose your stream</option>
                            <option value="Natural Science">Natural Science</option>
                            <option value="Social Science">Social Science</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="af-section">Section</label>
                        <select id="af-section" name="section" class="form-control" required>
                            <option value="" disabled selected>Choose</option>
                            <option value="A">Section A</option>
                            <option value="B">Section B</option>
                            <option value="C">Section C</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="af-password">Password <span class="muted-note">(optional)</span></label>
                        <input type="text" id="af-password" name="password" class="form-control" placeholder="Leave blank to auto-generate" maxlength="72" autocomplete="off">
                        <p class="pw-help">Leave blank and a random temporary password will be generated and shown once after saving.</p>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;">Add Student</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Remove by List modal -->
    <div class="modal-background" id="remove-students-modal">
        <div class="modal-card">
            <div class="modal-header">
                <span>🗑 Remove Students by List</span>
                <span class="modal-close-btn" onclick="closeRemoveModal()">×</span>
            </div>
            <div class="modal-body">
                <p class="muted-note" style="margin-top:0;">Upload a file listing the roll numbers to <strong>archive</strong> — a <strong>.csv</strong>, <strong>.txt</strong>, or <strong>Excel .xlsx</strong>. One roll number per line, or a <code>roll_number[,stream]</code> header (the stream column is optional — without it, every student with that roll number is matched).</p>
                <p class="muted-note" style="margin:0 0 14px;">
                    Examples:<br>
                    <code>105</code> &nbsp;or&nbsp; <code>roll_number,stream<br>105,Natural Science</code><br>
                    You'll see <strong>exactly which students will be archived</strong> on a confirm screen before anything happens.
                </p>
                <form method="POST" action="admin_students.php" enctype="multipart/form-data">
                    <?php echo Csrf::field(); ?>
                    <input type="hidden" name="action" value="preview_remove_students">
                    <div class="form-group">
                        <label for="student_remove_list">Roll-number list</label>
                        <input type="file" id="student_remove_list" name="student_remove_list" class="form-control" accept=".csv,.txt,.xlsx" required>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;">
                        <a href="admin_students.php?action=remove_template" class="muted-note">⬇ Download template (.csv)</a>
                        <button type="submit" class="btn btn-danger" style="background:#dc2626;border-color:#dc2626;">Preview Removal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Import CSV modal -->
    <div class="modal-background" id="import-students-modal">
        <div class="modal-card">
            <div class="modal-header">
                <span>⬆ Import Students (CSV / Excel)</span>
                <span class="modal-close-btn" onclick="closeImportModal()">×</span>
            </div>
            <div class="modal-body">
                <p class="muted-note" style="margin-top:0;">Upload a class list with one student per row — a <strong>.csv</strong>, <strong>.txt</strong>, or <strong>Excel .xlsx</strong> file (column names like <em>name</em>, <em>roll</em>, <em>id</em> are accepted too). A random temporary password is generated for every row — you'll get a credentials file to download and hand out.</p>
                <table class="student-table" style="margin:10px 0 14px;">
                    <thead>
                        <tr><th>Column</th><th>What goes in it</th></tr>
                    </thead>
                    <tbody>
                        <tr><td><code>full_name</code></td><td>Student's name, as written by the school</td></tr>
                        <tr><td><code>roll_number</code></td><td>Whole number 1–999 — the student's ID</td></tr>
                        <tr><td><code>stream</code></td><td><code>Natural Science</code> or <code>Social Science</code></td></tr>
                        <tr><td><code>section</code></td><td><code>A</code>, <code>B</code> or <code>C</code></td></tr>
                    </tbody>
                </table>
                <p class="muted-note" style="margin:0 0 14px;">
                    Example row: <code>Abebe Tesfaye,105,Natural Science,A</code><br>
                    For .csv, save as <strong>UTF-8</strong> (Excel: “CSV UTF-8”). Invalid rows are reported and skipped; roll numbers already registered are skipped without touching their password.
                </p>
                <form method="POST" action="admin_students.php" enctype="multipart/form-data">
                    <?php echo Csrf::field(); ?>
                    <input type="hidden" name="action" value="import_students">
                    <div class="form-group">
                        <label for="student_csv">CSV or Excel file</label>
                        <input type="file" id="student_csv" name="student_csv" class="form-control" accept=".csv,.txt,.xlsx" required>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;">
                        <a href="admin_students.php?action=template" class="muted-note">⬇ Download template (.csv)</a>
                        <button type="submit" class="btn btn-primary">Import Class</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openAddModal() {
            document.getElementById('add-student-modal').style.display = 'flex';
        }
        function closeAddModal() {
            document.getElementById('add-student-modal').style.display = 'none';
        }
        document.getElementById('add-student-modal').addEventListener('click', function (e) {
            if (e.target === this) closeAddModal();
        });

        function openImportModal() {
            document.getElementById('import-students-modal').style.display = 'flex';
        }
        function closeImportModal() {
            document.getElementById('import-students-modal').style.display = 'none';
        }
        document.getElementById('import-students-modal').addEventListener('click', function (e) {
            if (e.target === this) closeImportModal();
        });

        function openRemoveModal() {
            document.getElementById('remove-students-modal').style.display = 'flex';
        }
        function closeRemoveModal() {
            document.getElementById('remove-students-modal').style.display = 'none';
        }
        document.getElementById('remove-students-modal').addEventListener('click', function (e) {
            if (e.target === this) closeRemoveModal();
        });

        // Safe confirm dialogs — names travel in data-* attributes (HTML-escaped),
        // never inside JS strings.
        document.querySelectorAll('.reset-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                var ok = confirm("Reset the password for " + form.dataset.name + " (Roll " + form.dataset.roll + ")? A temporary password will be generated.");
                if (!ok) e.preventDefault();
            });
        });
        document.querySelectorAll('.remove-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                var attempts = form.dataset.attempts === '0' ? ' No attempt history yet.' : ' Their ' + form.dataset.attempts + ' attempt record(s) are preserved.';
                var ok = confirm("Archive " + form.dataset.name + " (Roll " + form.dataset.roll + ")? They leave the active list but stay restorable from the Archived tab." + attempts);
                if (!ok) e.preventDefault();
            });
        });
        document.querySelectorAll('.restore-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                var ok = confirm("Restore " + form.dataset.name + " (Roll " + form.dataset.roll + ")? They will be able to log in again.");
                if (!ok) e.preventDefault();
            });
        });
        document.querySelectorAll('.purge-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                var attempts = form.dataset.attempts === '0' ? ' No attempt history yet.' : ' This will also permanently delete ' + form.dataset.attempts + ' attempt record(s) and their results.';
                var ok = confirm("PERMANENTLY DELETE " + form.dataset.name + " (Roll " + form.dataset.roll + ")? This cannot be undone." + attempts);
                if (!ok) e.preventDefault();
            });
        });
    </script>
    <?php include __DIR__ . '/partials/copyright_footer.php'; ?>
    </div>
</body>
</html>
