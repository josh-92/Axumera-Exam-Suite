<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

/**
 * Offline license verification for the signed .lic files issued by the
 * developer-only license-keygen tool. The application contains only the public
 * key; the private signing key never belongs in a deployed EAES installation.
 */
final class License
{
    private static string $lastError = '';

    /** @return array{valid: bool, reason: string, message: string, school_name?: string, expires?: string} */
    public static function status(): array
    {
        $details = self::verifyFile(self::licensePath());

        if ($details === null) {
            return [
                'valid' => false,
                'reason' => self::lastErrorReason(),
                'message' => self::$lastError,
            ];
        }

        return [
            'valid' => true,
            'reason' => 'ok',
            'message' => 'License active.',
            'school_name' => $details['school_name'],
            'expires' => $details['expires'],
        ];
    }

    public static function isValid(): bool
    {
        return self::verifyFile(self::licensePath()) !== null;
    }

    public static function lastError(): string
    {
        return self::$lastError;
    }

    /**
     * Returns the Windows ComputerSystemProduct UUID used by the key generator.
     * Placeholder UUIDs are rejected to prevent a license being issued to an
     * unstable or meaningless hardware identity.
     */
    public static function hardwareId(): ?string
    {
        static $cachedHwid = null;
        static $lookedUp = false;

        if ($lookedUp) {
            return $cachedHwid;
        }
        $lookedUp = true;

        if (PHP_OS_FAMILY !== 'Windows' || !function_exists('shell_exec')) {
            self::setError('This installation requires Windows hardware identification.');
            return null;
        }

        foreach ([
            'wmic csproduct get uuid 2>NUL',
            'powershell -NoProfile -NonInteractive -Command "(Get-CimInstance -ClassName Win32_ComputerSystemProduct).UUID" 2>NUL',
        ] as $command) {
            $output = @shell_exec($command);
            if (!is_string($output)) {
                continue;
            }

            if (preg_match('/([0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})/', $output, $match)) {
                $hwid = self::normalizeHardwareId($match[1]);
                if ($hwid !== '' && $hwid !== str_repeat('0', 32) && $hwid !== str_repeat('F', 32)) {
                    return $cachedHwid = $hwid;
                }
            }
        }

        self::setError('Unable to read a valid motherboard UUID.');
        return null;
    }

    /**
     * Validates an uploaded license before replacing the active one.
     *
     * @param array{name?: mixed, size?: mixed, tmp_name?: mixed, error?: mixed} $upload
     * @return array{ok: bool, message: string}
     */
    public static function activateUpload(array $upload): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Please choose a license file to upload.'];
        }

        $name = (string) ($upload['name'] ?? '');
        $size = (int) ($upload['size'] ?? 0);
        $temporaryPath = (string) ($upload['tmp_name'] ?? '');
        if ($size <= 0 || $size > 65536 || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'lic') {
            return ['ok' => false, 'message' => 'Upload a valid .lic file no larger than 64 KB.'];
        }
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
            return ['ok' => false, 'message' => 'The uploaded license file could not be verified.'];
        }
        if (self::verifyFile($temporaryPath) === null) {
            return ['ok' => false, 'message' => 'Activation failed: ' . self::$lastError];
        }

        if (!move_uploaded_file($temporaryPath, self::licensePath())) {
            return ['ok' => false, 'message' => 'The valid license could not be saved. Check storage folder write permissions.'];
        }

        self::setError('');
        return ['ok' => true, 'message' => 'Software activated successfully.'];
    }

    /** @return array{school_name: string, hwid: string, expires: string}|null */
    private static function verifyFile(string $licensePath): ?array
    {
        if (!is_file($licensePath) || !is_readable($licensePath)) {
            self::setError('License file is missing.');
            return null;
        }

        $publicKeyPath = dirname(__DIR__) . '/Keys/public_key.pem';
        if (!is_file($publicKeyPath) || !is_readable($publicKeyPath)) {
            self::setError('Public license key is missing.');
            return null;
        }

        $licenseJson = @file_get_contents($licensePath);
        $envelope = is_string($licenseJson) ? json_decode($licenseJson, true) : null;
        if (!is_array($envelope) || !isset($envelope['payload'], $envelope['signature']) || !is_string($envelope['payload']) || !is_string($envelope['signature'])) {
            self::setError('License file format is invalid.');
            return null;
        }

        $payload = base64_decode($envelope['payload'], true);
        $signature = base64_decode($envelope['signature'], true);
        $publicKey = @openssl_pkey_get_public('file://' . $publicKeyPath);
        if ($payload === false || $signature === false || $publicKey === false) {
            self::setError('License signature data cannot be read.');
            return null;
        }

        $verified = openssl_verify($payload, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if (is_object($publicKey) || is_resource($publicKey)) {
            openssl_free_key($publicKey);
        }
        if ($verified !== 1) {
            self::setError('License signature is not valid.');
            return null;
        }

        $data = json_decode($payload, true);
        if (!is_array($data) || !isset($data['school_name'], $data['hwid'], $data['expires']) || !is_string($data['school_name']) || !is_string($data['hwid']) || !is_string($data['expires'])) {
            self::setError('Signed license payload is incomplete.');
            return null;
        }

        $expires = DateTimeImmutable::createFromFormat('!Y-m-d', $data['expires']);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$expires || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $expires->format('Y-m-d') !== $data['expires']) {
            self::setError('License expiry date is invalid.');
            return null;
        }
        if ((new DateTimeImmutable('today'))->format('Y-m-d') > $data['expires']) {
            self::setError('License has expired.');
            return null;
        }

        $localHwid = self::hardwareId();
        if ($localHwid === null || !hash_equals(self::normalizeHardwareId($data['hwid']), $localHwid)) {
            self::setError('License hardware ID does not match this machine.');
            return null;
        }

        self::setError('');
        return $data;
    }

    private static function licensePath(): string
    {
        return dirname(__DIR__, 2) . '/storage/license.lic';
    }

    private static function normalizeHardwareId(string $hardwareId): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($hardwareId)) ?? '');
    }

    private static function setError(string $message): void
    {
        self::$lastError = $message;
    }

    private static function lastErrorReason(): string
    {
        return match (self::$lastError) {
            'License file is missing.' => 'missing',
            'License has expired.' => 'expired',
            'License hardware ID does not match this machine.' => 'hardware',
            default => 'invalid',
        };
    }
}
