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

    /** Start the session only if the browser already has one, or a "Keep me signed in" (public pages). */
    public static function resume(): void
    {
        if (PHP_SAPI !== 'cli' && (isset($_COOKIE['sw_session']) || isset($_COOKIE[Auth::REMEMBER_COOKIE]))) {
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

    /** @var array<string, array{0:string,1:int}> cookies set from the command line (the tests): name => [value, expires] */
    public static array $cliCookies = [];

    /** A cookie for our own pages only, like the session's (an expires in the past removes it). */
    public static function cookie(string $name, string $value, int $expires): void
    {
        if (PHP_SAPI === 'cli') {
            self::$cliCookies[$name] = [$value, $expires];
            return;
        }
        if (!headers_sent()) {
            setcookie($name, $value, ['expires' => $expires, 'path' => App::base() . '/', 'secure' => self::isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
        }
    }

    public const STAFF_HINT = 'sw_staff';

    /**
     * A hint for the website the station lives in (its header's "Sign in"): "1" while someone is
     * signed in here, for the whole site (path /) and readable by its scripts. It holds nothing
     * about who: the header asks api/me for that, and only when the hint is there, so visitors'
     * page views never reach the station. $expires 0: until the browser closes; past: removed.
     */
    public static function staffHint(bool $on, int $expires = 0): void
    {
        $expires = $on ? $expires : time() - 86400;
        if (PHP_SAPI === 'cli') {
            self::$cliCookies[self::STAFF_HINT] = [$on ? '1' : '', $expires];
            return;
        }
        if (!headers_sent()) {
            setcookie(self::STAFF_HINT, $on ? '1' : '', ['expires' => $expires, 'path' => '/', 'secure' => self::isHttps(), 'httponly' => false, 'samesite' => 'Lax']);
        }
    }

    public static function isHttps(): bool
    {
        // Cloudflare talks to this server over HTTPS too, so the server's own flag is enough
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
