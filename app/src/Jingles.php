<?php
declare(strict_types=1);

namespace Stationwagon;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The station's jingles (round 70): tiny clips that announce the station between songs. A jingle's audio is
 * a `tracks` row with kind = 'jingle', so uploading, song prep's levelling, the stream and the timeline treat
 * it like a song; the Music library never lists it (every song query filters kind = 'song'). What makes it a
 * jingle is its row in `jingles` (on or off, and a note).
 *
 * The AutoDJ (Planner::plan) asks pick() for a jingle after every N songs (see settings()): the active jingle
 * that was played longest ago, so several jingles take turns. A jingle only ever goes between two songs: never
 * straight after an ad, another jingle or a live show, and an ad break does not count as a song. Listeners and
 * the apps see the station's name for it (no artist, art or copyright line), and nothing is logged: stats count
 * listeners, and jingles are not listed under Recent or Next.
 */
final class Jingles
{
    /** The station the jingles belong to: the main station, for a stream too (round 73) */
    private readonly int $lib;

    public function __construct(private readonly Db $db, private readonly array $station)
    {
        $this->lib = (int) ($station['library_id'] ?? $station['id']);
    }

    // ---- The station's jingle settings -----------------------------------------------------------------

    /** @return array{enabled:bool,every:int,crossfade:float} */
    public static function settings(array $station): array
    {
        $all = Planner::settings($station);
        return [
            'enabled' => (int) $all['jingles_enabled'] === 1,
            'every' => max(1, min(20, (int) $all['jingles_every_songs'])),
            'crossfade' => max(0.0, min(6.0, (float) $all['jingles_crossfade_seconds'])),
        ];
    }

    /**
     * Check and keep the jingle settings: on or off, and a jingle after every N songs (1 to 20).
     * @return array{0:?array,1:?string} [the updated station row, or null; what's wrong, or null]
     */
    public function saveSettings(array $in): array
    {
        $every = isset($in['every']) && preg_match('/^\d{1,3}$/', trim((string) $in['every'])) ? (int) trim((string) $in['every']) : null;
        if ($every === null || $every < 1 || $every > 20) {
            return [null, 'Jingles after every: choose 1 to 20 songs.'];
        }
        $own = json_decode((string) ($this->station['settings'] ?? ''), true);
        $own = array_replace(is_array($own) ? $own : [], ['jingles_enabled' => empty($in['enabled']) ? 0 : 1, 'jingles_every_songs' => $every]);
        $json = json_encode($own, JSON_UNESCAPED_SLASHES);
        $this->db->run('UPDATE ' . $this->db->table('stations') . ' SET settings = ? WHERE id = ?', [$json, $this->lib]);
        return [['settings' => $json] + $this->station, null];
    }

    // ---- The jingles -----------------------------------------------------------------------------------

    /** Make the row for a track that was just uploaded as a jingle: on. */
    public function create(int $trackId): void
    {
        $this->db->run('INSERT IGNORE INTO ' . $this->db->table('jingles') . ' (track_id, station_id, created_at) VALUES (?, ?, ?)',
            [$trackId, $this->lib, gmdate('Y-m-d H:i:s')]);
    }

    /**
     * Every jingle with how it did today (the station's day up to $now): played (started) and planned (still to come).
     * @return list<array<string,mixed>>
     */
    public function all(float $now): array
    {
        $j = $this->db->table('jingles');
        $t = $this->db->table('tracks');
        $rows = $this->db->run(
            "SELECT j.*, t.title, t.duration_s, t.file, t.loudness_lufs, t.mastered, t.created_at AS uploaded_at
             FROM $j j JOIN $t t ON t.id = j.track_id
             WHERE j.station_id = ? AND t.kind = 'jingle' AND t.status = 'ready' ORDER BY t.title, j.track_id",
            [$this->lib]
        )->fetchAll();
        $start = (new DateTimeImmutable('@' . (int) floor($now)))->setTimezone($this->zone())->setTime(0, 0);
        $from = $start->getTimestamp();
        $to = $start->modify('+1 day')->getTimestamp();
        $counts = [];
        foreach ($this->db->run(
            'SELECT tl.track_id, SUM(tl.start_at <= ?) AS aired, SUM(tl.start_at > ?) AS planned
             FROM ' . $this->db->table('timeline') . ' tl WHERE tl.station_id = ? AND tl.type = \'song\' AND tl.start_at >= ? AND tl.start_at < ? GROUP BY tl.track_id',
            [$now, $now, $this->station['id'], $from, $to]
        )->fetchAll() as $c) {
            $counts[(int) $c['track_id']] = ['aired' => (int) $c['aired'], 'planned' => (int) $c['planned']];
        }
        return array_map(fn(array $r) => $r + [
            'aired_today' => $counts[(int) $r['track_id']]['aired'] ?? 0,
            'planned_today' => $counts[(int) $r['track_id']]['planned'] ?? 0,
        ], $rows);
    }

    public function get(int $trackId): ?array
    {
        $j = $this->db->table('jingles');
        $t = $this->db->table('tracks');
        $row = $this->db->run("SELECT j.*, t.title, t.duration_s FROM $j j JOIN $t t ON t.id = j.track_id WHERE j.track_id = ? AND j.station_id = ? AND t.kind = 'jingle'",
            [$trackId, $this->lib])->fetch();
        return $row ?: null;
    }

    /**
     * Check a jingle as the form posts it: title (kept on the track), active and notes.
     * @return array{0:?array,1:?string} [the clean values, what's wrong]
     */
    public static function check(array $in): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            return [null, 'A jingle needs a title.'];
        }
        $notes = trim((string) ($in['notes'] ?? ''));
        return [['title' => mb_substr($title, 0, 255), 'active' => empty($in['active']) ? 0 : 1, 'notes' => $notes !== '' ? mb_substr($notes, 0, 2000) : null], null];
    }

    /** Save a jingle (see check()); returns what's wrong, or null. */
    public function update(int $trackId, array $in): ?string
    {
        if ($this->get($trackId) === null) {
            return 'That jingle isn’t here.';
        }
        [$v, $problem] = self::check($in);
        if ($v === null) {
            return $problem;
        }
        $this->db->run('UPDATE ' . $this->db->table('tracks') . ' SET title = ? WHERE id = ? AND station_id = ?', [$v['title'], $trackId, $this->lib]);
        $this->db->run('UPDATE ' . $this->db->table('jingles') . ' SET active = ?, notes = ? WHERE track_id = ?', [$v['active'], $v['notes'], $trackId]);
        return null;
    }

    /** Turn several jingles on or off at once (the list's bulk bar, round 72); returns how many there were. */
    public function setActive(array $ids, bool $on): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn(int $i) => $i > 0)));
        if (!$ids) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $t = $this->db->table('jingles');
        $n = (int) $this->db->run("SELECT COUNT(*) FROM $t WHERE station_id = ? AND track_id IN ($marks)", [$this->lib, ...$ids])->fetchColumn();
        $this->db->run("UPDATE $t SET active = ? WHERE station_id = ? AND track_id IN ($marks)", [$on ? 1 : 0, $this->lib, ...$ids]);
        return $n;
    }

    // ---- Choosing a jingle -----------------------------------------------------------------------------

    /** The jingle to play next: the active one that was played (or planned) longest ago, or null when none is on. */
    public function pick(): ?int
    {
        $j = $this->db->table('jingles');
        $t = $this->db->table('tracks');
        $row = $this->db->run(
            "SELECT j.track_id FROM $j j JOIN $t t ON t.id = j.track_id AND t.kind = 'jingle' AND t.status = 'ready'
             LEFT JOIN (SELECT track_id, MAX(start_at) AS last FROM " . $this->db->table('timeline') . " WHERE station_id = ? GROUP BY track_id) p ON p.track_id = j.track_id
             WHERE j.station_id = ? AND j.active = 1
             ORDER BY (p.last IS NOT NULL), p.last, j.track_id LIMIT 1",
            [$this->station['id'], $this->lib]
        )->fetch();
        return $row ? (int) $row['track_id'] : null;
    }

    /** The audio facts the planner needs to place a jingle (like Planner::tracks() for a song), or null. */
    public function track(int $trackId): ?array
    {
        $r = $this->db->run('SELECT id, duration_s, outro FROM ' . $this->db->table('tracks') . " WHERE id = ? AND station_id = ? AND kind = 'jingle' AND status = 'ready'",
            [$trackId, $this->lib])->fetch();
        return $r ? ['id' => (int) $r['id'], 'duration' => (float) $r['duration_s'], 'outro' => $r['outro'], 'jingle' => true] : null;
    }

    /**
     * Songs since the last jingle at the end of the timeline (a live show or a jingle ends the count; an ad does not count).
     */
    public function songsSince(): int
    {
        $rows = $this->db->run(
            'SELECT tl.type, t.kind FROM ' . $this->db->table('timeline') . ' tl LEFT JOIN ' . $this->db->table('tracks') . ' t ON t.id = tl.track_id
             WHERE tl.station_id = ? ORDER BY tl.n DESC LIMIT 60',
            [$this->station['id']]
        )->fetchAll();
        $since = 0;
        foreach ($rows as $r) {
            if ($r['type'] === 'live' || $r['kind'] === 'jingle') {
                break;
            }
            if ($r['kind'] !== 'ad') {
                $since++;
            }
        }
        return $since;
    }

    private function zone(): DateTimeZone
    {
        try {
            return new DateTimeZone((string) ($this->station['timezone'] ?? 'UTC') ?: 'UTC');
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
