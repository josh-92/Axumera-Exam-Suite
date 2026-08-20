<?php

namespace App\Core;

class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }

    public static function verify(?string $token): bool
    {
        if (empty($_SESSION[self::SESSION_KEY]) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION[self::SESSION_KEY], $token);
    }

    /** Verify the current request's POST token, or terminate with 419. */
    public static function guard(): void
    {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!self::verify($token)) {
            http_response_code(419);
            Logger::warning('CSRF token mismatch on ' . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Your session expired or the request could not be verified. Please refresh and try again.']);
            exit;
        }
    }
}
