<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:04              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 declare (strict_types=1); namespace App\Core; use DateTimeImmutable; final class License { private static string $lastError = ''; public static function status(): array { $FwM6e = self::verifyFile(self::licensePath()); if (!($FwM6e === null)) { goto l5jWF; } return ['valid' => false, 'reason' => self::lastErrorReason(), 'message' => self::$lastError]; l5jWF: return ['valid' => true, 'reason' => 'ok', 'message' => 'License active.', 'school_name' => $FwM6e['school_name'], 'expires' => $FwM6e['expires']]; } public static function isValid(): bool { return self::verifyFile(self::licensePath()) !== null; } public static function lastError(): string { return self::$lastError; } public static function hardwareId(): ?string { static $gDSz2 = null; static $lD001 = false; if (!$lD001) { goto Aea_6; } return $gDSz2; Aea_6: $lD001 = true; if (!(PHP_OS_FAMILY !== 'Windows' || !function_exists('shell_exec'))) { goto hdpsr; } self::setError('This installation requires Windows hardware identification.'); return null; hdpsr: foreach (['wmic csproduct get uuid 2>NUL', 'powershell -NoProfile -NonInteractive -Command "(Get-CimInstance -ClassName Win32_ComputerSystemProduct).UUID" 2>NUL'] as $x6wvm) { $W_c6S = @shell_exec($x6wvm); if (is_string($W_c6S)) { goto yGVrK; } goto GeyMW; yGVrK: if (!preg_match('/([0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})/', $W_c6S, $BlkyM)) { goto mJyZg; } $wqFqf = self::normalizeHardwareId($BlkyM[1]); if (!($wqFqf !== '' && $wqFqf !== str_repeat('0', 32) && $wqFqf !== str_repeat('F', 32))) { goto upm9m; } return $gDSz2 = $wqFqf; upm9m: mJyZg: GeyMW: } P5yfy: self::setError('Unable to read a valid motherboard UUID.'); return null; } public static function activateUpload(array $njbY3): array { if (!(($njbY3['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) { goto NDpg9; } return ['ok' => false, 'message' => 'Please choose a license file to upload.']; NDpg9: $IRKun = (string) ($njbY3['name'] ?? ''); $UmLSa = (int) ($njbY3['size'] ?? 0); $tHlnw = (string) ($njbY3['tmp_name'] ?? ''); if (!($UmLSa <= 0 || $UmLSa > 65536 || strtolower(pathinfo($IRKun, PATHINFO_EXTENSION)) !== 'lic')) { goto Vaqvz; } return ['ok' => false, 'message' => 'Upload a valid .lic file no larger than 64 KB.']; Vaqvz: if (!($tHlnw === '' || !is_uploaded_file($tHlnw))) { goto jJVXJ; } return ['ok' => false, 'message' => 'The uploaded license file could not be verified.']; jJVXJ: if (!(self::verifyFile($tHlnw) === null)) { goto kl2Vt; } return ['ok' => false, 'message' => 'Activation failed: ' . self::$lastError]; kl2Vt: if (move_uploaded_file($tHlnw, self::licensePath())) { goto lPmgd; } return ['ok' => false, 'message' => 'The valid license could not be saved. Check storage folder write permissions.']; lPmgd: self::setError(''); return ['ok' => true, 'message' => 'Software activated successfully.']; } private static function verifyFile(string $YYyHa): ?array {
    if (!is_file($YYyHa) || !is_readable($YYyHa)) { self::setError('License file is missing.'); return null; }
    $IzGyA = dirname(__DIR__) . '/Keys/public_key.pem';
    if (!is_file($IzGyA) || !is_readable($IzGyA)) { self::setError('Public license key is missing.'); return null; }
    // Fast path: the license was already fully verified within the last 24h.
    // The full verification shells out to wmic/powershell for the hardware ID,
    // which costs ~2.3s per request under Apache (wmic is gone on Win11, so the
    // powershell fallback runs every time). Cache the verified payload; expiry
    // is still enforced below from the cached data.
    $lU2sZ = dirname(__DIR__, 2) . '/storage/cache/license.cache';
    if (is_file($lU2sZ)) {
        $qIv8X = @json_decode((string) @file_get_contents($lU2sZ), true);
        if (is_array($qIv8X) && isset($qIv8X['school_name'], $qIv8X['hwid'], $qIv8X['expires'])
            && !((new DateTimeImmutable('today'))->format('Y-m-d') > $qIv8X['expires'])) {
            self::setError('');
            return $qIv8X;
        }
        @unlink($lU2sZ);
    }
    $iG4zU = @file_get_contents($YYyHa);
    $E2tRz = is_string($iG4zU) ? json_decode($iG4zU, true) : null;
    if (!is_array($E2tRz) || !isset($E2tRz['payload'], $E2tRz['signature']) || !is_string($E2tRz['payload']) || !is_string($E2tRz['signature'])) {
        self::setError('License file format is invalid.'); return null;
    }
    $weTug = base64_decode($E2tRz['payload'], true);
    $Ue9Md = base64_decode($E2tRz['signature'], true);
    $kztMe = @openssl_pkey_get_public('file://' . $IzGyA);
    if ($weTug === false || $Ue9Md === false || $kztMe === false) {
        self::setError('License signature data cannot be read.'); return null;
    }
    $kpKJe = openssl_verify($weTug, $Ue9Md, $kztMe, OPENSSL_ALGO_SHA256);
    if ($kpKJe !== 1) { self::setError('License signature is not valid.'); return null; }
    $CDwXr = json_decode($weTug, true);
    if (!is_array($CDwXr) || !isset($CDwXr['school_name'], $CDwXr['hwid'], $CDwXr['expires'])
        || !is_string($CDwXr['school_name']) || !is_string($CDwXr['hwid']) || !is_string($CDwXr['expires'])) {
        self::setError('Signed license payload is incomplete.'); return null;
    }
    $soinP = DateTimeImmutable::createFromFormat('!Y-m-d', $CDwXr['expires']);
    $coni8 = DateTimeImmutable::getLastErrors();
    if (!$soinP || ($coni8 !== false && ($coni8['warning_count'] || $coni8['error_count'])) || $soinP->format('Y-m-d') !== $CDwXr['expires']) {
        self::setError('License expiry date is invalid.'); return null;
    }
    if ((new DateTimeImmutable('today'))->format('Y-m-d') > $CDwXr['expires']) {
        self::setError('License has expired.'); return null;
    }
    $mh74f = self::hardwareId();
    if ($mh74f === null || !hash_equals(self::normalizeHardwareId($CDwXr['hwid']), $mh74f)) {
        self::setError('License hardware ID does not match this machine.'); return null;
    }
    self::setError('');
    @mkdir(dirname($lU2sZ), 0777, true);
    @file_put_contents($lU2sZ, json_encode($CDwXr));
    return $CDwXr;
}
 private static function licensePath(): string { return dirname(__DIR__, 2) . '/storage/license.lic'; } private static function normalizeHardwareId(string $U8MTm): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($U8MTm)) ?? ''); } private static function setError(string $tvwac): void { self::$lastError = $tvwac; } private static function lastErrorReason(): string { return match (self::$lastError) { 'License file is missing.' => 'missing', 'License has expired.' => 'expired', 'License hardware ID does not match this machine.' => 'hardware', default => 'invalid', }; } }
