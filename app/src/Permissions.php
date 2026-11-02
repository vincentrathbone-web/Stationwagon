<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * What a person may do beyond going live in their own block, keeping their own running order and
 * looking after their own podcast episodes (which every DJ may). A role gives its defaults; an admin
 * or a manager with "people" may tick or untick each one for someone they look after (users.permissions,
 * a JSON list; NULL means the role's defaults). Admins always have everything, so a station can't
 * lock itself out.
 */
final class Permissions
{
    /** key => [label, what it allows] */
    public const ALL = [
        'onair' => ['On air', 'Skip songs, go live any time and run anyone’s show'],
        'library' => ['Music library', 'Upload, edit and remove songs; song prep and re-levelling'],
        'playlists' => ['Playlists', 'Make and change playlists'],
        'schedule' => ['Schedule', 'Plan the week, including DJs’ live blocks'],
        'podcast' => ['Podcast', 'Every episode and the podcast’s details (DJs always have their own episodes)'],
        'listeners' => ['Listeners', 'Listener numbers and where they listen from'],
        'sound' => ['Sound & safety', 'Crossfades, fades, quality and the other station sound settings'],
        'people' => ['People', 'Add, change, block and remove people (up to their own role)'],
    ];

    /** @return list<string> the role's defaults */
    public static function defaults(string $role): array
    {
        return match ($role) {
            'admin', 'manager' => array_keys(self::ALL),
            default => [],
        };
    }

    /** @return list<string> what this person may do */
    public static function of(array $user): array
    {
        if (($user['role'] ?? '') === 'admin') {
            return array_keys(self::ALL);
        }
        $set = isset($user['permissions']) && $user['permissions'] !== null && $user['permissions'] !== ''
            ? self::clean(is_array($user['permissions']) ? $user['permissions'] : (json_decode((string) $user['permissions'], true) ?: []))
            : self::defaults((string) ($user['role'] ?? ''));
        return $set;
    }

    public static function has(array $user, string $key): bool
    {
        return in_array($key, self::of($user), true);
    }

    /** @return list<string> the known keys in $keys, in the standard order */
    public static function clean(array $keys): array
    {
        return array_values(array_filter(array_keys(self::ALL), fn($k) => in_array($k, $keys, true)));
    }

    /** What to store for $keys on someone with $role: NULL when it is just the role's defaults. */
    public static function store(string $role, array $keys): ?string
    {
        $keys = self::clean($keys);
        return $keys === self::defaults($role) ? null : json_encode($keys);
    }
}
