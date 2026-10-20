<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * PHP sessions with safe cookie settings, kept in storage/sessions (not the host's shared /tmp).
 * Public pages only resume a session that already exists, so listeners never get a cookie.
 */
final class Session
{
    public const IDLE_SECONDS = 43200; // 12 h: long enough for a DJ's show

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            $_SESSION ??= []; // tests drive Auth with a plain array
            return;
        }
        $dir = SW_ROOT . '/storage/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.gc_maxlifetime', (string) self::IDLE_SECONDS);
        session_name('sw_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => App::base() . '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $seen = (int) ($_SESSION['seen'] ?? 0);
        if ($seen && time() - $seen > self::IDLE_SECONDS) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['seen'] = time();
    }

    /** Start the session only if the browser already has one (public pages). */
    public static function resume(): void
    {
        if (PHP_SAPI !== 'cli' && isset($_COOKIE['sw_session'])) {
            self::start();
        }
    }

    public static function active(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE || (PHP_SAPI === 'cli' && isset($_SESSION));
    }

    /** New session id after sign-in or sign-out, so an old id can't be reused. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function csrf(): string
    {
        self::start();
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function checkCsrf(mixed $given): bool
    {
        return is_string($given) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $given);
    }

    /** A message for the next page (after a redirect): ok or bad. */
    public static function flash(string $kind, string $message): void
    {
        self::start();
        $_SESSION['flash'][] = [$kind, $message];
    }

    /** @return list<array{0:string,1:string}> */
    public static function takeFlash(): array
    {
        if (!self::active() || empty($_SESSION['flash'])) {
            return [];
        }
        $all = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $all;
    }

    public static function isHttps(): bool
    {
        // Cloudflare talks to this server over HTTPS too, so the server's own flag is enough
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
