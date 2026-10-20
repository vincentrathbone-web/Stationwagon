<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * A station's playlists: named lists of songs played shuffled or in order. The planner uses a
 * playlist while a schedule block names it; outside the schedule it plays the whole library.
 * Methods that change things return what's wrong as a string, or null when it worked.
 */
final class Playlists
{
    public const MODES = ['shuffle' => 'Shuffle', 'in_order' => 'In order'];

    public function __construct(private readonly Db $db, private readonly array $station)
    {
    }

    /** With song count, total length and how many schedule blocks use each. */
    public function all(): array
    {
        $pl = $this->db->table('playlists');
        $pt = $this->db->table('playlist_tracks');
        $tr = $this->db->table('tracks');
        $sc = $this->db->table('schedule');
        return $this->db->run(
            "SELECT p.*, COUNT(t.id) AS songs, COALESCE(SUM(t.duration_s), 0) AS seconds,
                    (SELECT COUNT(*) FROM $sc s WHERE s.playlist_id = p.id) AS blocks
             FROM $pl p LEFT JOIN $pt pt ON pt.playlist_id = p.id LEFT JOIN $tr t ON t.id = pt.track_id AND t.status = 'ready'
             WHERE p.station_id = ? GROUP BY p.id ORDER BY p.name, p.id",
            [$this->station['id']]
        )->fetchAll();
    }

    public function get(int $id): ?array
    {
        $row = $this->db->run('SELECT * FROM ' . $this->db->table('playlists') . ' WHERE id = ? AND station_id = ?', [$id, $this->station['id']])->fetch();
        return $row ?: null;
    }

    /** The playlist's songs in play order. */
    public function tracks(int $id): array
    {
        $pt = $this->db->table('playlist_tracks');
        $tr = $this->db->table('tracks');
        return $this->db->run(
            "SELECT t.id, t.title, t.artist, t.duration_s, pt.position FROM $pt pt JOIN $tr t ON t.id = pt.track_id
             WHERE pt.playlist_id = ? ORDER BY pt.position, t.id",
            [$id]
        )->fetchAll();
    }

    /** @return array{0:?int,1:?string} [new id, problem] */
    public function create(string $name, string $mode): array
    {
        if (($problem = self::check($name, $mode)) !== null) {
            return [null, $problem];
        }
        $id = $this->db->insert('playlists', ['station_id' => $this->station['id'], 'name' => mb_substr(trim($name), 0, 190), 'mode' => $mode, 'active' => 1, 'created_at' => gmdate('Y-m-d H:i:s')]);
        return [$id, null];
    }

    public function update(int $id, string $name, string $mode, bool $active): ?string
    {
        if ($this->get($id) === null) {
            return 'That playlist doesn’t exist.';
        }
        if (($problem = self::check($name, $mode)) !== null) {
            return $problem;
        }
        $this->db->run('UPDATE ' . $this->db->table('playlists') . ' SET name = ?, mode = ?, active = ? WHERE id = ?', [mb_substr(trim($name), 0, 190), $mode, $active ? 1 : 0, $id]);
        return null;
    }

    /** Schedule blocks that used it are removed with it (they'd have nothing to play). */
    public function delete(int $id): ?string
    {
        if ($this->get($id) === null) {
            return 'That playlist doesn’t exist.';
        }
        $this->db->run('DELETE FROM ' . $this->db->table('schedule') . ' WHERE playlist_id = ? AND station_id = ?', [$id, $this->station['id']]);
        $this->db->run('DELETE FROM ' . $this->db->table('playlists') . ' WHERE id = ?', [$id]);
        return null;
    }

    /** Add songs at the end, in the order given; songs already in it are left where they are. */
    public function add(int $id, array $trackIds): ?string
    {
        if ($this->get($id) === null) {
            return 'That playlist doesn’t exist.';
        }
        $tr = $this->db->table('tracks');
        $pt = $this->db->table('playlist_tracks');
        $next = (int) $this->db->run("SELECT COALESCE(MAX(position), -1) + 1 FROM $pt WHERE playlist_id = ?", [$id])->fetchColumn();
        foreach (array_unique(array_map('intval', $trackIds)) as $trackId) {
            $ok = $this->db->run("SELECT COUNT(*) FROM $tr WHERE id = ? AND station_id = ?", [$trackId, $this->station['id']])->fetchColumn();
            $there = $this->db->run("SELECT COUNT(*) FROM $pt WHERE playlist_id = ? AND track_id = ?", [$id, $trackId])->fetchColumn();
            if ((int) $ok && !(int) $there) {
                $this->db->insert('playlist_tracks', ['playlist_id' => $id, 'track_id' => $trackId, 'position' => $next++]);
            }
        }
        return null;
    }

    public function remove(int $id, int $trackId): ?string
    {
        if ($this->get($id) === null) {
            return 'That playlist doesn’t exist.';
        }
        $this->db->run('DELETE FROM ' . $this->db->table('playlist_tracks') . ' WHERE playlist_id = ? AND track_id = ?', [$id, $trackId]);
        $this->renumber($id);
        return null;
    }

    /** Move a song one place earlier ($by = -1) or later ($by = 1). */
    public function move(int $id, int $trackId, int $by): ?string
    {
        if ($this->get($id) === null) {
            return 'That playlist doesn’t exist.';
        }
        $ids = array_map('intval', array_column($this->tracks($id), 'id'));
        $at = array_search($trackId, $ids, true);
        $to = $at === false ? false : $at + ($by < 0 ? -1 : 1);
        if ($at === false || $to < 0 || $to >= count($ids)) {
            return null; // already at the top or bottom
        }
        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        $this->renumber($id, $ids);
        return null;
    }

    private function renumber(int $id, ?array $order = null): void
    {
        $order ??= array_map('intval', array_column($this->tracks($id), 'id'));
        foreach ($order as $pos => $trackId) {
            $this->db->run('UPDATE ' . $this->db->table('playlist_tracks') . ' SET position = ? WHERE playlist_id = ? AND track_id = ?', [$pos, $id, $trackId]);
        }
    }

    private static function check(string $name, string $mode): ?string
    {
        if (trim($name) === '') {
            return 'Give the playlist a name.';
        }
        if (!isset(self::MODES[$mode])) {
            return 'Choose shuffle or in order.';
        }
        return null;
    }
}
