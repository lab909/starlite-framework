<?php

declare(strict_types=1);

namespace Starlite;

/**
 * Minimal CSRF protection using the native PHP file session.
 * The session is closed straight away so long-running SSE responses never hold the session lock.
 */
final class Csrf
{
    private static ?string $token = null;

    public static function token(): string
    {
        if (self::$token !== null) {
            return self::$token;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'cookie_secure' => ($_SERVER['HTTPS'] ?? '') === 'on',
                'use_strict_mode' => true,
            ]);
        }
        self::$token = $_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
        session_write_close();

        return self::$token;
    }

    public static function isValid(): bool
    {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';

        return is_string($sent) && $sent !== '' && hash_equals(self::token(), $sent);
    }
}
