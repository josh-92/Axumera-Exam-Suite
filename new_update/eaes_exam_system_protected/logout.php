<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:06              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 require_once __DIR__ . '/app/bootstrap.php'; use App\Core\Logger; use App\Core\Session; if (empty($_SESSION['admin_username'])) { goto R3YRs; } Logger::audit('admin', $_SESSION['admin_username'], 'logout'); R3YRs: Session::destroy(); header("Location: adminlogin.php"); exit;
