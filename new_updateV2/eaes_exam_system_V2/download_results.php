<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:06              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 require_once __DIR__ . '/app/bootstrap.php'; use App\Core\Validator; use App\Repositories\ExamRepository; use App\Services\ReportService; if (!empty($_SESSION['admin_logged_in'])) { goto kZ7Tb; } header("Location: adminlogin.php"); exit; kZ7Tb: $sYOeU = Validator::int($_GET['exam_id'] ?? 0); $QR3LD = $sYOeU > 0 ? ExamRepository::find($sYOeU) : null; if ($QR3LD) { goto H7I6p; } http_response_code(404); die("Error: exam not found."); H7I6p: \App\Core\Logger::audit('admin', $_SESSION['admin_username'], 'download_scoreboard', ['exam_id' => $sYOeU]); ReportService::streamScoreboardCsv($QR3LD); exit;
