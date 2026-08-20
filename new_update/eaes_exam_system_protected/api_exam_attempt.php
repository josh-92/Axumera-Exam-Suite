<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:03              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 require_once __DIR__ . '/app/bootstrap.php'; use App\Services\ExamAttemptService; use App\Core\Session; Session::start(); if (Session::get('student_id')) { goto O0lH0; } http_response_code(401); echo json_encode(['success' => false, 'error' => 'Student unauthorized.']); exit; O0lH0: $VhBTi = new ExamAttemptService(); header('Content-Type: application/json'); $l4L0m = $_SERVER['REQUEST_METHOD']; $uJgaK = $_GET['action'] ?? ''; try { if ($l4L0m === 'POST') { goto a1hO9; } throw new Exception("Invalid method."); goto iL2Ri; a1hO9: $TwvlD = json_decode(file_get_contents('php://input'), true); if ($uJgaK === 'start') { goto BrRH5; } if ($uJgaK === 'submit') { goto Sof7T; } throw new Exception("Invalid action."); goto dIhoB; BrRH5: $ugLXG = (int) ($TwvlD['exam_id'] ?? 0); $wphBH = $VhBTi->startExam($ugLXG, Session::get('student_id')); echo json_encode(['success' => true, 'data' => $wphBH]); goto dIhoB; Sof7T: $h3utf = (int) ($TwvlD['session_id'] ?? 0); $Q_r0P = $TwvlD['responses'] ?? []; $wphBH = $VhBTi->submitExam($h3utf, $Q_r0P); echo json_encode($wphBH); dIhoB: iL2Ri: } catch (Exception $NacY1) { http_response_code(400); echo json_encode(['success' => false, 'error' => $NacY1->getMessage()]); }
