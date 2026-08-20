<?php
declare(strict_types=1);

// Disposable E2E-only signing helper. It is never included by the application.
if ($argc !== 5) {
    fwrite(STDERR, "Usage: php e2e-sign-test-license.php <private.pem> <public.pem> <license.lic> <hardware-id>\n");
    exit(64);
}
[$_, $privatePath, $publicPath, $licensePath, $hardwareId] = $argv;
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($key === false || !openssl_pkey_export($key, $privatePem)) {
    fwrite(STDERR, 'Key generation failed: ' . (openssl_error_string() ?: 'unknown OpenSSL error') . "\n");
    exit(1);
}
$details = openssl_pkey_get_details($key);
if (!is_array($details) || !isset($details['key'])) {
    fwrite(STDERR, "Public-key extraction failed.\n");
    exit(1);
}
$payload = json_encode([
    'school_name' => 'Axumera Synthetic Test School',
    'hwid' => preg_replace('/[^A-Za-z0-9]/', '', $hardwareId),
    'expires' => '2099-12-31',
], JSON_UNESCAPED_SLASHES);
if (!is_string($payload) || !openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256)) {
    fwrite(STDERR, 'Test-license signing failed: ' . (openssl_error_string() ?: 'unknown OpenSSL error') . "\n");
    exit(1);
}
if (file_put_contents($privatePath, $privatePem) === false || file_put_contents($publicPath, $details['key']) === false || file_put_contents($licensePath, json_encode(['payload' => base64_encode($payload), 'signature' => base64_encode($signature)])) === false) {
    fwrite(STDERR, "Could not write disposable test-license material.\n");
    exit(1);
}
