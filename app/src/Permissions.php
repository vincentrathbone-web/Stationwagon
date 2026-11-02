<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * What a person may do beyond going live in their own scheduled block, keeping their own running
 * order and looking after their own podcast episodes (which everyone who signs in may). Each person
 * gets exactly what their role allows; the roles and their ticks are set under Manage → Roles
 * (Roles). Admins always have everything, so a station can't lock itself out.
 */
final class Permissions
{
    /** key => [label, what it allows] */
    public const ALL = [
        'anytime' => ['Go live any time', 'Live shows, events and podcast recordings outside their own scheduled block'],
        'desk' => ['Run the on-air desk', 'Skip songs, and run, change or end anyone’s live show'],
        'library' => ['Music library', 'Upload, edit and remove songs; song prep and re-levelling'],
        'playlists' => ['Playlists', 'Make and change playlists'],
        'schedule' => ['Schedule', 'Plan the week, including DJs’ live blocks'],
        'podcast' => ['Podcast', 'Every episode and the podcast’s details (everyone keeps their own episodes)'],
        'listeners' => ['Listeners', 'Listener numbers and where they listen from'],
        'sound' => ['Sound & safety', 'Crossfades, fades, quality and the other station sound settings'],
        'people' => ['People', 'Add, change, block and remove people, giving roles that can do no more than they can'],
    ];

    /** @return list<string> what a built-in role starts with */
    public static function defaults(string $role): array
    {
        return match ($role) {
            'admin', 'manager' => array_keys(self::ALL),
            default => [],
        };
    }

    /** @return list<string> what this person may do: their role's ticks (role_permissions, from Roles) */
    public static function of(array $user): array
    {
        if (($user['role'] ?? '') === 'admin') {
            return array_keys(self::ALL);
        }
        $set = $user['role_permissions'] ?? null;
        if ($set === null || $set === '') {
            return self::defaults((string) ($user['role'] ?? ''));
        }
        return self::clean(is_array($set) ? $set : (json_decode((string) $set, true) ?: []));
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

    /** "Everything", "Their own shows" or the labels, for lists. */
    public static function summary(array $keys): string
    {
        $keys = self::clean($keys);
        if (count($keys) === count(self::ALL)) {
            return 'Everything';
        }
        return $keys ? implode(', ', array_map(fn($k) => self::ALL[$k][0], $keys)) : 'Their own shows, in their own blocks';
    }
}
