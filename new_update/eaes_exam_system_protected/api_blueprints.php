<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:03              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 require_once __DIR__ . '/app/bootstrap.php'; use App\Services\BlueprintService; use App\Core\Session; Session::start(); if (Session::get('admin_id')) { goto Q0f39; } http_response_code(401); echo json_encode(['success' => false, 'error' => 'Unauthorized access.']); exit; Q0f39: $VhBTi = new BlueprintService(); header('Content-Type: application/json'); $l4L0m = $_SERVER['REQUEST_METHOD']; $uJgaK = $_GET['action'] ?? ''; try { if ($l4L0m === 'POST' && $uJgaK === 'save') { goto VUYVz; } if ($l4L0m === 'GET' && $uJgaK === 'generate') { goto uBcMV; } throw new Exception("Invalid endpoint or method."); goto EiKjU; VUYVz: $TwvlD = json_decode(file_get_contents('php://input'), true); $wphBH = $VhBTi->saveBlueprint($TwvlD, Session::get('admin_id')); echo json_encode($wphBH); goto EiKjU; uBcMV: $PHXsS = (int) ($_GET['id'] ?? 0); $ZRuS9 = $VhBTi->generateExamInstance($PHXsS, Session::get('admin_id')); echo json_encode(['success' => true, 'exam' => $ZRuS9]); EiKjU: } catch (Exception $NacY1) { http_response_code(400); echo json_encode(['success' => false, 'error' => $NacY1->getMessage()]); }
