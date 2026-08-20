<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:04              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 namespace App\Core; use Redis; use Exception; class RedisCache { private static ?Redis $instance = null; public static function getInstance(): Redis { if (!(self::$instance === null)) { goto fm0GM; } try { self::$instance = new Redis(); self::$instance->connect($_ENV['REDIS_HOST'] ?? '127.0.0.1', (int) ($_ENV['REDIS_PORT'] ?? 6379)); if (!isset($_ENV['REDIS_PASS'])) { goto X2pYQ; } self::$instance->auth($_ENV['REDIS_PASS']); X2pYQ: } catch (Exception $NacY1) { error_log("Redis Connection Failed: " . $NacY1->getMessage()); throw new Exception("Cache layer unavailable."); } fm0GM: return self::$instance; } public static function remember(string $CmSTM, int $ifvJK, callable $g2vl4) { $yAyx2 = self::getInstance(); $jgnT_ = $yAyx2->get($CmSTM); if (!($jgnT_ !== false)) { goto zMDXY; } return json_decode($jgnT_, true); zMDXY: $CDwXr = $g2vl4(); $yAyx2->setex($CmSTM, $ifvJK, json_encode($CDwXr)); return $CDwXr; } public static function invalidate(string $mEqKI): void { $yAyx2 = self::getInstance(); $lzaAq = $yAyx2->keys($mEqKI); if (empty($lzaAq)) { goto sU1af; } $yAyx2->del($lzaAq); sU1af: } }
