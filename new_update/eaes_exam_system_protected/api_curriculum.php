<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:03              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 require_once __DIR__ . '/app/bootstrap.php'; use App\Services\CurriculumIntelligenceService; use App\Core\Session; Session::start(); if (Session::get('admin_id')) { goto XQX0b; } http_response_code(401); echo json_encode(['success' => false, 'error' => 'Unauthorized access.']); exit; XQX0b: $VhBTi = new CurriculumIntelligenceService(); header('Content-Type: application/json'); $l4L0m = $_SERVER['REQUEST_METHOD']; $uJgaK = $_GET['action'] ?? ''; try { if ($l4L0m === 'GET' && $uJgaK === 'coverage') { goto n6m1t; } throw new Exception("Invalid endpoint or method."); goto e4DjU; n6m1t: $ugLXG = (int) ($_GET['exam_id'] ?? 0); $L8kY8 = $VhBTi->analyzeCoverage($ugLXG); echo json_encode(['success' => true, 'report' => $L8kY8]); e4DjU: } catch (Exception $NacY1) { http_response_code(400); echo json_encode(['success' => false, 'error' => $NacY1->getMessage()]); }
