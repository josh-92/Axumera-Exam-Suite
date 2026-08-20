<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:06              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 require_once __DIR__ . '/app/bootstrap.php'; use App\Core\Validator; use App\Repositories\AnalyticsRepository; use App\Repositories\ExamRepository; use App\Services\ReportService; if (!empty($_SESSION['admin_logged_in'])) { goto PUpzr; } header("Location: adminlogin.php"); exit; PUpzr: $sYOeU = Validator::int($_GET['exam_id'] ?? 0); $QR3LD = $sYOeU > 0 ? ExamRepository::find($sYOeU) : null; if ($QR3LD) { goto eDtRr; } http_response_code(404); die("Error: exam not found."); eDtRr: \App\Core\Logger::audit('admin', $_SESSION['admin_username'], 'download_question_report', ['exam_id' => $sYOeU]); $ziEfD = AnalyticsRepository::questionDifficulty($sYOeU); ReportService::streamQuestionReportCsv($QR3LD, $ziEfD); exit;
