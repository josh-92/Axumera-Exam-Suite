<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:04              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 namespace App\Core; class Csrf { private const lrZ4H = '_csrf_token'; public static function token(): string { if (!empty($_SESSION[self::lrZ4H])) { goto VsgrE; } $_SESSION[self::lrZ4H] = bin2hex(random_bytes(32)); VsgrE: return $_SESSION[self::lrZ4H]; } public static function field(): string { $VpokN = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8'); return '<input type="hidden" name="csrf_token" value="' . $VpokN . '">'; } public static function verify(?string $VpokN): bool { if (!(empty($_SESSION[self::lrZ4H]) || empty($VpokN))) { goto kjx4h; } return false; kjx4h: return hash_equals($_SESSION[self::lrZ4H], $VpokN); } public static function guard(): void { $VpokN = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null; if (self::verify($VpokN)) { goto NIzIv; } http_response_code(419); Logger::warning('CSRF token mismatch on ' . ($_SERVER['REQUEST_URI'] ?? 'unknown')); header('Content-Type: application/json'); echo json_encode(['status' => 'error', 'message' => 'Your session expired or the request could not be verified. Please refresh and try again.']); exit; NIzIv: } }
