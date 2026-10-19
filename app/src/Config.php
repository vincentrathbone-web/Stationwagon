<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * Settings come from config.local.php, which the installer writes and git never sees. Until it
 * exists, the app is "not installed" and only the installer runs.
 */
final class Config
{
    private static ?array $values = null;

    public static function path(): string
    {
        return SW_ROOT . '/config.local.php';
    }

    public static function all(): array
    {
        if (self::$values === null) {
            self::$values = is_file(self::path()) ? (array) require self::path() : [];
        }
        return self::$values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function isInstalled(): bool
    {
        return !empty(self::get('installed_at')) && is_array(self::get('db'));
    }

    public static function version(): string
    {
        return trim((string) @file_get_contents(SW_ROOT . '/VERSION')) ?: 'unknown';
    }

    /** Forget the cached values (after the installer writes the file, and in tests). */
    public static function reset(): void
    {
        self::$values = null;
    }
}
