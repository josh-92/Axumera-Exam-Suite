<?php

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Validator;
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

\App\Core\Logger::audit('admin', $_SESSION['admin_username'], 'download_scoreboard', ['exam_id' => $exam_id]);

ReportService::streamScoreboardCsv($exam);
exit();
