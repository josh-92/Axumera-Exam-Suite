<?php

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Validator;
use App\Repositories\AnalyticsRepository;
use App\Repositories\ExamRepository;
use App\Services\ReportService;

if (empty($_SESSION['admin_logged_in'])) {
    header("Location: adminlogin.php");
    exit();
}

$exam_id = Validator::int($_GET['exam_id'] ?? 0);
$exam = $exam_id > 0 ? ExamRepository::find($exam_id) : null;

if (!$exam) {
    http_response_code(404);
    die("Error: exam not found.");
}

\App\Core\Logger::audit('admin', $_SESSION['admin_username'], 'download_question_report', ['exam_id' => $exam_id]);

$stats = AnalyticsRepository::questionDifficulty($exam_id);
ReportService::streamQuestionReportCsv($exam, $stats);
exit();
