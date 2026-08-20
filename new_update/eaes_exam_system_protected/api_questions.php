<?php

/**
 * api_questions.php — Question Bank API
 * -------------------------------------
 * REST-ish JSON endpoint consumed by admin_question_bank.php.
 *
 * Endpoints:
 *   GET  ?action=list                 paginated bank listing + filters
 *   GET  ?action=facets               distinct filter values for dropdowns
 *   GET  ?action=show&id=N            single question + exam assignments
 *   GET  ?action=exams                exams available for assignment
 *   GET  ?action=assigned&exam_id=N   bank questions attached to an exam
 *   GET  ?action=export&format=csv|json[&filters…]  download current filter set
 *   GET  ?action=template&format=csv|json           download an import template
 *   POST ?action=save                 create/update question (JSON body)
 *   POST ?action=archive              soft-delete (JSON body: ids[])
 *   POST ?action=restore              restore (JSON body: ids[])
 *   POST ?action=assign               attach bank questions to an exam (JSON body)
 *   POST ?action=unassign             detach (JSON body: exam_id, question_id)
 *   POST ?action=points               update per-question marks (JSON body)
 *   POST ?action=import               multipart upload (file + subject/grade defaults)
 *
 * All mutations require a valid CSRF token (X-CSRF-Token header for JSON
 * bodies, csrf_token form field for the multipart import).
 */

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Csrf;
use App\Core\Logger;
use App\Repositories\QuestionBankRepository;

header('Content-Type: application/json');

// Auth: the deployed obfuscated core does not expose Session::get(), so we
// use the same $_SESSION keys set by adminlogin.php (the pattern every
// working page — adminpanel, analytics, changepassword — relies on).
if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

$adminId = (int) $_SESSION['admin_id'];
$adminName = (string) ($_SESSION['admin_username'] ?? 'admin');
$method = $_SERVER['REQUEST_METHOD'];
$action = (string) ($_GET['action'] ?? '');
$body = null;

try {
    if ($method === 'POST') {
        Csrf::guard(); // 419 + JSON on failure
        $body = json_decode(file_get_contents('php://input'), true);
        $body = is_array($body) ? $body : [];
        if ($action === 'save') {
            $result = QuestionBankRepository::save($body, $adminId);
            Logger::audit('admin', $adminName, $result['created'] ? 'qb_question_created' : 'qb_question_updated', [
                'question_id' => $result['id'],
            ]);
            echo json_encode(['success' => true, 'id' => $result['id'], 'created' => $result['created']]);
        } elseif ($action === 'archive') {
            $ids = array_map('intval', (array) ($body['ids'] ?? []));
            $result = QuestionBankRepository::archive($ids);
            Logger::audit('admin', $adminName, 'qb_questions_archived', [
                'ids' => $ids,
                'archived' => $result['archived'],
                'blocked' => $result['blocked'],
            ]);
            echo json_encode(['success' => true] + $result);
        } elseif ($action === 'restore') {
            $ids = array_map('intval', (array) ($body['ids'] ?? []));
            $count = QuestionBankRepository::restore($ids);
            Logger::audit('admin', $adminName, 'qb_questions_restored', ['ids' => $ids, 'restored' => $count]);
            echo json_encode(['success' => true, 'restored' => $count]);
        } elseif ($action === 'assign') {
            $examId = (int) ($body['exam_id'] ?? 0);
            $questionIds = array_map('intval', (array) ($body['question_ids'] ?? []));
            $points = (float) ($body['points'] ?? 1);
            $pointsMap = is_array($body['points_map'] ?? null) ? $body['points_map'] : [];
            $result = QuestionBankRepository::assign($examId, $questionIds, $points, $pointsMap, $adminId);
            Logger::audit('admin', $adminName, 'qb_questions_assigned', [
                'exam_id' => $examId,
                'assigned' => $result['assigned'],
                'errors' => count($result['errors']),
            ]);
            echo json_encode(['success' => true] + $result);
        } elseif ($action === 'unassign') {
            $examId = (int) ($body['exam_id'] ?? 0);
            $questionId = (int) ($body['question_id'] ?? 0);
            QuestionBankRepository::unassign($examId, $questionId);
            Logger::audit('admin', $adminName, 'qb_question_unassigned', ['exam_id' => $examId, 'question_id' => $questionId]);
            echo json_encode(['success' => true]);
        } elseif ($action === 'points') {
            $examId = (int) ($body['exam_id'] ?? 0);
            $questionId = (int) ($body['question_id'] ?? 0);
            $points = (float) ($body['points'] ?? 0);
            $ok = QuestionBankRepository::updatePoints($examId, $questionId, $points);
            Logger::audit('admin', $adminName, 'qb_assignment_points', ['exam_id' => $examId, 'question_id' => $questionId, 'points' => $points]);
            echo json_encode(['success' => $ok]);
        } elseif ($action === 'import') {
            $result = handleImport($adminId);
            Logger::audit('admin', $adminName, 'qb_questions_imported', [
                'imported' => $result['imported'],
                'total' => $result['total'],
                'errors' => count($result['errors']),
            ]);
            echo json_encode(['success' => true] + $result);
        } else {
            throw new Exception('Invalid action or method.');
        }
    } elseif ($method === 'GET') {
        if ($action === 'list') {
            $filters = [
                'search' => (string) ($_GET['search'] ?? ''),
                'subject' => (string) ($_GET['subject'] ?? ''),
                'grade' => (string) ($_GET['grade'] ?? ''),
                'difficulty' => (string) ($_GET['difficulty'] ?? ''),
                'type' => (string) ($_GET['type'] ?? ''),
                'date_from' => (string) ($_GET['date_from'] ?? ''),
                'date_to' => (string) ($_GET['date_to'] ?? ''),
                'status' => (string) ($_GET['status'] ?? 'active'),
            ];
            if (!empty($_GET['mine'])) {
                $filters['created_by'] = $adminId;
            }
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 15)));
            echo json_encode(['success' => true] + QuestionBankRepository::paginate($filters, $page, $perPage));
        } elseif ($action === 'facets') {
            echo json_encode(['success' => true, 'facets' => QuestionBankRepository::facets()]);
        } elseif ($action === 'show') {
            $row = QuestionBankRepository::find((int) ($_GET['id'] ?? 0));
            if (!$row) {
                throw new Exception('Question not found.');
            }
            echo json_encode(['success' => true, 'question' => $row]);
        } elseif ($action === 'exams') {
            echo json_encode(['success' => true, 'exams' => QuestionBankRepository::assignableExams()]);
        } elseif ($action === 'assigned') {
            echo json_encode(['success' => true, 'assigned' => QuestionBankRepository::assigned((int) ($_GET['exam_id'] ?? 0))]);
        } elseif ($action === 'export') {
            streamExport($adminId);
        } elseif ($action === 'template') {
            streamTemplate();
        } else {
            throw new Exception('Invalid action or method.');
        }
    } else {
        throw new Exception('Invalid HTTP method.');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// =====================================================================
// Helpers
// =====================================================================

/** Multipart import: validate upload, parse CSV or JSON, import rows. */
function handleImport(int $adminId): array
{
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('The upload failed — please choose a file and try again.');
    }
    if ($_FILES['file']['size'] > 5 * 1024 * 1024) {
        throw new Exception('The file is too large (max 5 MB).');
    }
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'json'], true)) {
        throw new Exception('Only .csv and .json files are accepted.');
    }

    $content = (string) file_get_contents($_FILES['file']['tmp_name']);
    if (trim($content) === '') {
        throw new Exception('The uploaded file is empty.');
    }

    if ($ext === 'json') {
        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON: ' . json_last_error_msg());
        }
        $rows = isset($decoded['questions']) && is_array($decoded['questions']) ? $decoded['questions'] : $decoded;
        if (!is_array($rows) || $rows === []) {
            throw new Exception('The JSON file must contain a non-empty array of question objects.');
        }
    } else {
        $rows = QuestionBankRepository::parseCsv($content);
    }

    $defaults = [
        'subject' => trim((string) ($_POST['subject'] ?? '')),
        'grade' => trim((string) ($_POST['grade'] ?? '')),
    ];
    return QuestionBankRepository::import($rows, $defaults, $adminId);
}

/** Download the current filtered set as CSV or JSON. */
function streamExport(int $adminId): void
{
    $filters = [
        'search' => (string) ($_GET['search'] ?? ''),
        'subject' => (string) ($_GET['subject'] ?? ''),
        'grade' => (string) ($_GET['grade'] ?? ''),
        'difficulty' => (string) ($_GET['difficulty'] ?? ''),
        'type' => (string) ($_GET['type'] ?? ''),
        'date_from' => (string) ($_GET['date_from'] ?? ''),
        'date_to' => (string) ($_GET['date_to'] ?? ''),
        'status' => (string) ($_GET['status'] ?? 'active'),
    ];
    if (!empty($_GET['mine'])) {
        $filters['created_by'] = $adminId;
    }
    $rows = QuestionBankRepository::export($filters);
    $format = ($_GET['format'] ?? 'csv') === 'json' ? 'json' : 'csv';
    $stamp = date('Y-m-d');

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="question_bank_' . $stamp . '.json"');
        echo json_encode(['questions' => $rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="question_bank_' . $stamp . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM so Excel opens it correctly
    fputcsv($out, ['id', 'question', 'type', 'difficulty', 'subject', 'grade', 'topic', 'tags',
        'option_a', 'option_b', 'option_c', 'option_d', 'correct_answer',
        'status', 'created_at', 'updated_at']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['id'],
            $row['question'] ?? $row['question_text'],
            $row['type'],
            $row['difficulty'],
            $row['subject'],
            $row['grade'],
            $row['topic'],
            $row['tags'],
            $row['option_a'],
            $row['option_b'],
            $row['option_c'],
            $row['option_d'],
            $row['correct_answer'],
            $row['status'],
            $row['created_at'],
            $row['updated_at'],
        ]);
    }
    fclose($out);
    exit;
}

/** Download an import template (CSV with example rows, or JSON structure). */
function streamTemplate(): void
{
    $format = ($_GET['format'] ?? 'csv') === 'json' ? 'json' : 'csv';

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="question_bank_template.json"');
        echo json_encode([
            'questions' => [
                [
                    'question' => 'What is the chemical symbol for water?',
                    'type' => 'MCQ',
                    'difficulty' => 'easy',
                    'subject' => 'Chemistry',
                    'grade' => 'Grade 9',
                    'topic' => 'Elements & Compounds',
                    'tags' => 'chemistry, water',
                    'option_a' => 'H2O',
                    'option_b' => 'CO2',
                    'option_c' => 'O2',
                    'option_d' => 'NaCl',
                    'correct_answer' => 'a',
                ],
                [
                    'question' => 'Photosynthesis occurs only during daylight.',
                    'type' => 'True/False',
                    'difficulty' => 'medium',
                    'subject' => 'Biology',
                    'grade' => 'Grade 10',
                    'topic' => 'Plant Biology',
                    'tags' => 'photosynthesis',
                    'correct_answer' => 'a',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="question_bank_template.csv"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['question', 'type', 'difficulty', 'subject', 'grade', 'topic', 'tags',
        'option_a', 'option_b', 'option_c', 'option_d', 'correct_answer']);
    fputcsv($out, ['What is the chemical symbol for water?', 'MCQ', 'easy', 'Chemistry', 'Grade 9', 'Elements & Compounds',
        'chemistry, water', 'H2O', 'CO2', 'O2', 'NaCl', 'a']);
    fputcsv($out, ['Photosynthesis occurs only during daylight.', 'True/False', 'medium', 'Biology', 'Grade 10',
        'Plant Biology', 'photosynthesis', '', '', '', '', 'a']);
    fclose($out);
    exit;
}
