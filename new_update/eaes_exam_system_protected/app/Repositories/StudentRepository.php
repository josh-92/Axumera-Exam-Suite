<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:05              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 namespace App\Repositories; use App\Core\Database; class StudentRepository { public static function findByRollAndStream(string $R9xjU, string $MKh3C): ?array { $dzhGf = Database::connection()->prepare('SELECT * FROM students WHERE roll_number = :r AND stream = :s LIMIT 1'); $dzhGf->execute(['r' => $R9xjU, 's' => $MKh3C]); $RmthD = $dzhGf->fetch(); return $RmthD ?: null; } public static function findById(int $UBSEx): ?array { $dzhGf = Database::connection()->prepare('SELECT * FROM students WHERE id = :id LIMIT 1'); $dzhGf->execute(['id' => $UBSEx]); $RmthD = $dzhGf->fetch(); return $RmthD ?: null; } public static function upsert(string $tcr9x, string $R9xjU, string $MKh3C, string $oOnaN): int { $lNNqu = self::findByRollAndStream($R9xjU, $MKh3C); $fVwZF = Database::connection(); if (!$lNNqu) { goto dX5He; } $dzhGf = $fVwZF->prepare('UPDATE students SET full_name = :n, section = :sec WHERE id = :id'); $dzhGf->execute(['n' => $tcr9x, 'sec' => $oOnaN, 'id' => $lNNqu['id']]); return (int) $lNNqu['id']; dX5He: $dzhGf = $fVwZF->prepare('INSERT INTO students (full_name, roll_number, stream, section, created_at) VALUES (:n, :r, :s, :sec, NOW())'); $dzhGf->execute(['n' => $tcr9x, 'r' => $R9xjU, 's' => $MKh3C, 'sec' => $oOnaN]); return (int) $fVwZF->lastInsertId(); } }
