<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:05              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 namespace App\Services; use App\Repositories\QuestionRepository; class GradingService { public static function grade(int $ugLXG, array $hO7lU): array { $U6Xfu = QuestionRepository::answerKey($ugLXG); $LTqpG = 0; foreach ($U6Xfu as $i30SX => $RtkUV) { $eZIAg = $hO7lU[$i30SX] ?? $hO7lU[(string) $i30SX] ?? null; if (!($eZIAg === null)) { goto LJsYb; } goto AZqKq; LJsYb: $eZIAg = strtolower(trim((string) $eZIAg)); if (!($eZIAg === $RtkUV && $RtkUV !== '')) { goto Ns0cV; } $LTqpG++; Ns0cV: AZqKq: } u7WzH: return ['score' => $LTqpG, 'total' => count($U6Xfu)]; } }
