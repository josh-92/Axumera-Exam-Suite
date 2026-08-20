<?php

namespace App\Core;

class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = (int) config('security.session_lifetime_minutes', 180) * 60;
        $secure   = self::isHttps();

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        session_name('EAESSESSID');
        session_start();

        // Idle timeout enforcement
        if (isset($_SESSION['_last_activity']) && (time() - $_SESSION['_last_activity']) > $lifetime) {
            self::destroy();
            session_start();
        }
        $_SESSION['_last_activity'] = time();

        // Rotate the session id periodically to mitigate fixation/hijacking.
        if (!isset($_SESSION['_started_at'])) {
            $_SESSION['_started_at'] = time();
        } elseif (time() - $_SESSION['_started_at'] > 900) {
            session_regenerate_id(true);
            $_SESSION['_started_at'] = time();
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    private static function isHttps(): bool
    {
        if (config('app.force_https')) {
            return true;
        }
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}
