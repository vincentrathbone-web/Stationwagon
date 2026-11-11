<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * Several streams on one installation (round 73, phase v1.3). The main station is stream 1; each further stream is a row of
 * `stations` whose parent_id is the main station. A stream is a station for everything that is its own: its AutoDJ timeline,
 * weekly schedule, live shows, presenter notes and listeners (every table already says which station a row is for). The library
 * (songs, playlists, ads, jingles), the sound settings and the people belong to the main station and are shared:
 * view() gives a stream row the main station's settings and says where the library is (`library_id`), which is all the
 * planner, ads and jingles need to know.
 *
 * A stream's address is `<the station's address>/<slug>` (its player page), with `?stream=<slug>` on the api files and the
 * embed; the main stream keeps the addresses it always had. People may go live on the streams they are given (user_streams);
 * admins and the built-in manager role may use them all.
 */
final class Streams
{
    /** Names a stream cannot have: they are addresses the app already uses (or folders and files that must stay reachable). */
    public const RESERVED = [
        'install', 'signin', 'signout', 'account', 'manage', 'studio', 'podcast', 'embed', 'api', 'assets', 'storage', 'cli', 'views', 'src', 'tests',
        'index', 'listen', 'verse', 'schedule', 'nowplaying', 'timeline', 'stream', 'streams', 'notes', 'vendor', 'admin', 'privacy', 'about', 'config', 'setup',
    ];

    // ---- Finding them -----------------------------------------------------------------------------------

    /** The main station (stream 1). */
    public static function main(Db $db): array
    {
        $row = $db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE parent_id IS NULL ORDER BY id LIMIT 1')->fetch();
        if (!$row) {
            throw new \RuntimeException('No station is set up yet.');
        }
        return $row;
    }

    /** Every stream, the main one first. @return list<array<string,mixed>> station rows, each with `main` (bool) */
    public static function all(Db $db): array
    {
        $main = self::main($db);
        $rows = $db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE parent_id = ? ORDER BY id', [$main['id']])->fetchAll();
        return array_map(fn(array $r) => $r + ['main' => false], [$main + ['main' => true], ...$rows]);
    }

    /** The stream with this address name ('' or null, and the main station's own slug, give the main stream); null if there is none. */
    public static function bySlug(Db $db, ?string $slug): ?array
    {
        $main = self::main($db);
        $slug = strtolower(trim((string) $slug));
        if ($slug === '' || $slug === $main['slug']) {
            return $main + ['main' => true];
        }
        $row = $db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE slug = ? AND parent_id = ?', [$slug, $main['id']])->fetch();
        return $row ? $row + ['main' => false] : null;
    }

    public static function byId(Db $db, int $id): ?array
    {
        foreach (self::all($db) as $s) {
            if ((int) $s['id'] === $id) {
                return $s;
            }
        }
        return null;
    }

    /**
     * What the planner, the schedule, the listeners and the studio are given for a stream: its own row (its id is its timeline's), with
     * the main station's settings (the sound settings are shared) and `library_id`, the station the songs, playlists, ads and jingles are.
     */
    public static function view(array $stream, array $main): array
    {
        return ['settings' => $main['settings'], 'library_id' => (int) $main['id'], 'timezone' => $main['timezone']] + $stream;
    }

    /** The station a screen or an api file works on: the stream with this address name (null: the main stream), as the classes want it. */
    public static function station(Db $db, ?string $slug = null): ?array
    {
        $s = self::bySlug($db, $slug);
        return $s === null ? null : self::view($s, self::main($db));
    }

    /**
     * The stream the manager screens are about (round 73): `?stream=<name>` picks one and keeps it for the session; otherwise the one
     * picked before, otherwise the main stream. Needs a started session. Returns the stream as station() gives it.
     */
    public static function selected(Db $db): array
    {
        $want = $_GET['stream'] ?? $_POST['stream'] ?? null;
        if (is_string($want) && $want !== '' && self::bySlug($db, $want) !== null) {
            $_SESSION['sw_stream'] = strtolower($want);
        }
        $slug = is_string($_SESSION['sw_stream'] ?? null) ? $_SESSION['sw_stream'] : null;
        return self::station($db, $slug) ?? self::station($db, null);
    }

    /** A planner for the stream with this address name (null: the main stream), or null when there is no such stream. */
    public static function planner(Db $db, ?string $slug = null): ?Planner
    {
        $st = self::station($db, $slug);
        return $st === null ? null : new Planner($db, $st, SW_ROOT);
    }

    /** The same station row with the library's id, for classes that work on the library (Library, Playlists) given a stream's view. */
    public static function libraryStation(array $station): array
    {
        return ['id' => (int) ($station['library_id'] ?? $station['id'])] + $station;
    }

    // ---- Making and changing them -----------------------------------------------------------------------

    /** What's wrong with an address name, or null: lower case letters, digits and hyphens, 2 to 40, not one the app already uses. */
    public static function slugProblem(string $slug): ?string
    {
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,38})[a-z0-9]$/', $slug)) {
            return 'The address name uses lower case letters, digits and hyphens (2 to 40), starting and ending with a letter or digit.';
        }
        if (in_array($slug, self::RESERVED, true)) {
            return '“' . $slug . '” is an address the station already uses. Choose another name.';
        }
        if (ctype_digit($slug)) {
            return 'The address name needs a letter in it.';
        }
        return null;
    }

    /** A suggested address name for a stream's name ("Gansbaai Gospel!" gives "gansbaai-gospel"). */
    public static function slugFor(string $name): string
    {
        // accents first by hand: iconv's transliteration differs from one system to the next (it can give "R'e")
        $name = strtr($name, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss']);
        $s = strtolower((string) (function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name));
        return trim(substr((string) preg_replace('/[^a-z0-9]+/', '-', $s), 0, 40), '-');
    }

    /** @return array{0:?int,1:?string} [the new stream's id, or null; what's wrong] */
    public static function create(Db $db, string $name, string $slug): array
    {
        $name = trim($name);
        $slug = strtolower(trim($slug));
        if ($name === '') {
            return [null, 'A stream needs a name.'];
        }
        if (mb_strlen($name) > 190) {
            return [null, 'That name is too long.'];
        }
        if (($why = self::slugProblem($slug)) !== null) {
            return [null, $why];
        }
        $main = self::main($db);
        if ($slug === $main['slug'] || $db->run('SELECT COUNT(*) FROM ' . $db->table('stations') . ' WHERE slug = ?', [$slug])->fetchColumn() > 0) {
            return [null, 'There is already a stream with that address name.'];
        }
        $id = $db->insert('stations', ['slug' => $slug, 'name' => $name, 'timezone' => $main['timezone'], 'settings' => null, 'parent_id' => (int) $main['id'], 'created_at' => gmdate('Y-m-d H:i:s')]);
        return [$id, null];
    }

    /** Change a further stream's name and address name (the main stream's name is the station's, set with the station). Returns what's wrong, or null. */
    public static function update(Db $db, int $id, string $name, string $slug): ?string
    {
        $s = self::byId($db, $id);
        if ($s === null || $s['main']) {
            return 'That stream isn’t here.';
        }
        $name = trim($name);
        $slug = strtolower(trim($slug));
        if ($name === '') {
            return 'A stream needs a name.';
        }
        if (($why = self::slugProblem($slug)) !== null) {
            return $why;
        }
        $main = self::main($db);
        if ($slug === $main['slug'] || $db->run('SELECT COUNT(*) FROM ' . $db->table('stations') . ' WHERE slug = ? AND id <> ?', [$slug, $id])->fetchColumn() > 0) {
            return 'There is already a stream with that address name.';
        }
        $db->run('UPDATE ' . $db->table('stations') . ' SET name = ?, slug = ? WHERE id = ?', [mb_substr($name, 0, 190), $slug, $id]);
        return null;
    }

    /**
     * Remove a further stream: its timeline, schedule, live shows, notes and listener numbers go with it (the library stays).
     * Refused while a live show is on it. Returns what's wrong, or null.
     */
    public static function delete(Db $db, int $id): ?string
    {
        $s = self::byId($db, $id);
        if ($s === null || $s['main']) {
            return 'That stream isn’t here.';
        }
        if ((int) $db->run('SELECT COUNT(*) FROM ' . $db->table('live_shows') . ' WHERE station_id = ? AND `open` = 1', [$id])->fetchColumn() > 0) {
            return 'A live show is on this stream now. End it first.';
        }
        $db->run('DELETE FROM ' . $db->table('stations') . ' WHERE id = ?', [$id]);
        return null;
    }

    // ---- Who may go live on which ---------------------------------------------------------------------------

    /** Admins and the built-in manager role may use every stream; everyone else only the ones they are given (the main stream until someone gives them streams). */
    public static function usesAll(array $user): bool
    {
        return in_array((string) ($user['role'] ?? ''), ['admin', 'manager'], true);
    }

    /** @return list<int> the ids of the streams this person may go live on */
    public static function allowedIds(Db $db, array $user): array
    {
        if (self::usesAll($user)) {
            return array_map(fn(array $s) => (int) $s['id'], self::all($db));
        }
        $rows = $db->run('SELECT us.station_id FROM ' . $db->table('user_streams') . ' us JOIN ' . $db->table('stations') . ' s ON s.id = us.station_id WHERE us.user_id = ? ORDER BY us.station_id', [(int) ($user['id'] ?? 0)])->fetchAll(\PDO::FETCH_COLUMN);
        // someone nobody has given streams yet (made some other way than the People screen) has the main stream, as every DJ always had
        return $rows ? array_map('intval', $rows) : [(int) self::main($db)['id']];
    }

    public static function may(Db $db, array $user, int $streamId): bool
    {
        return in_array($streamId, self::allowedIds($db, $user), true);
    }

    /** Give a person exactly these streams (ids that are streams; others are dropped). */
    public static function setFor(Db $db, int $userId, array $ids): void
    {
        $valid = array_map(fn(array $s) => (int) $s['id'], self::all($db));
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn(int $i) => in_array($i, $valid, true))));
        $db->run('DELETE FROM ' . $db->table('user_streams') . ' WHERE user_id = ?', [$userId]);
        foreach ($ids as $id) {
            $db->run('INSERT INTO ' . $db->table('user_streams') . ' (user_id, station_id) VALUES (?, ?)', [$userId, $id]);
        }
    }
}
