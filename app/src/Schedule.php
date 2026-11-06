<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * The weekly schedule, in the station's own clock. A playlist block makes the AutoDJ play that
 * playlist; a live block marks a show slot for a DJ (shown on the grid; the studio uses it later).
 * days is a bitmask, Monday = 1 … Sunday = 64. A block ending before it starts runs past midnight.
 */
final class Schedule
{
    public const DAYS = [1 => 'Mon', 2 => 'Tue', 4 => 'Wed', 8 => 'Thu', 16 => 'Fri', 32 => 'Sat', 64 => 'Sun'];

    public function __construct(private readonly Db $db, private readonly array $station)
    {
    }

    public function all(): array
    {
        $sc = $this->db->table('schedule');
        $pl = $this->db->table('playlists');
        $us = $this->db->table('users');
        return $this->db->run(
            "SELECT s.*, p.name AS playlist_name, u.name AS dj_name FROM $sc s
             LEFT JOIN $pl p ON p.id = s.playlist_id LEFT JOIN $us u ON u.id = s.dj_user_id
             WHERE s.station_id = ? ORDER BY s.start_time, s.id",
            [$this->station['id']]
        )->fetchAll();
    }

    /**
     * The weekly blocks as the public JSON (api/schedule.php) tells them: `days` are ISO weekday numbers
     * (Monday 1 … Sunday 7), `start` and `end` are HH:MM in the station's time zone (a block that ends
     * before it starts runs past midnight; equal times are all day), `valid_from` / `valid_to` are dates or null.
     * @return list<array>
     */
    public function publicBlocks(): array
    {
        return array_map(fn($b) => [
            'id' => (int) $b['id'], 'title' => $b['title'], 'kind' => $b['kind'], 'host' => $b['kind'] === 'live' ? ($b['dj_name'] ?: null) : null,
            'days' => array_values(array_filter(range(1, 7), fn($d) => ((int) $b['days'] & (1 << ($d - 1))) !== 0)),
            'start' => substr($b['start_time'], 0, 5), 'end' => substr($b['end_time'], 0, 5),
            'valid_from' => $b['valid_from'] ?: null, 'valid_to' => $b['valid_to'] ?: null,
        ], $this->all());
    }

    /**
     * $in: title, kind (playlist|live), playlist_id or dj_user_id, days (list of bits), start, end (HH:MM).
     * @return array{0:?int,1:array<string,string>} [new id, field => problem]
     */
    public function add(array $in): array
    {
        $e = [];
        $kind = (string) ($in['kind'] ?? '');
        $title = trim((string) ($in['title'] ?? ''));
        $days = 0;
        foreach ((array) ($in['days'] ?? []) as $bit) {
            if (isset(self::DAYS[(int) $bit])) {
                $days |= (int) $bit;
            }
        }
        $start = self::time((string) ($in['start'] ?? ''));
        $end = self::time((string) ($in['end'] ?? ''));
        $playlistId = null;
        $djId = null;
        if (!in_array($kind, ['playlist', 'live'], true)) {
            $e['kind'] = 'Choose a playlist or a live show.';
        } elseif ($kind === 'playlist') {
            $playlistId = (int) ($in['playlist_id'] ?? 0);
            $name = $this->db->run('SELECT name FROM ' . $this->db->table('playlists') . ' WHERE id = ? AND station_id = ?', [$playlistId, $this->station['id']])->fetchColumn();
            if ($name === false) {
                $e['playlist_id'] = 'Choose a playlist.';
            } elseif ($title === '') {
                $title = (string) $name;
            }
        } else {
            $djId = (int) ($in['dj_user_id'] ?? 0) ?: null;
            if ($djId !== null) {
                $ok = $this->db->run('SELECT COUNT(*) FROM ' . $this->db->table('station_users') . ' WHERE user_id = ? AND station_id = ?', [$djId, $this->station['id']])->fetchColumn();
                if (!(int) $ok) {
                    $e['dj_user_id'] = 'Choose one of this station’s DJs.';
                }
            }
            if ($title === '') {
                $e['title'] = 'Give the show a name.';
            }
        }
        if ($days === 0) {
            $e['days'] = 'Tick at least one day.';
        }
        if ($start === null) {
            $e['start'] = 'Use a time like 06:00.';
        }
        if ($end === null) {
            $e['end'] = 'Use a time like 09:00.';
        }
        if ($e) {
            return [null, $e];
        }
        $id = $this->db->insert('schedule', [
            'station_id' => $this->station['id'], 'kind' => $kind, 'title' => mb_substr($title, 0, 190),
            'playlist_id' => $playlistId, 'dj_user_id' => $djId, 'days' => $days,
            'start_time' => $start, 'end_time' => $end, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return [$id, []];
    }

    public function delete(int $id): ?string
    {
        $n = $this->db->run('DELETE FROM ' . $this->db->table('schedule') . ' WHERE id = ? AND station_id = ?', [$id, $this->station['id']])->rowCount();
        return $n ? null : 'That block isn’t in the schedule.';
    }

    /** "06:00" or "6:00" or "06:00:00" → "06:00:00"; 24:00 means midnight. */
    public static function time(string $s): ?string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})(?::00)?$/', trim($s), $m) !== 1) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h === 24 && $min === 0) {
            $h = 0;
        }
        return $h < 24 && $min < 60 ? sprintf('%02d:%02d:00', $h, $min) : null;
    }

    /**
     * The blocks that start on $now's day in $tz, by start time, each with `on_now` set when it
     * covers $now (overnight blocks run past midnight; start = end is all day).
     */
    public static function today(array $blocks, float $now, string $tz): array
    {
        $t = (new \DateTimeImmutable('@' . (int) floor($now)))->setTimezone(new \DateTimeZone($tz));
        $bit = 1 << ((int) $t->format('N') - 1);
        $clock = $t->format('H:i:s');
        $out = [];
        foreach ($blocks as $b) {
            if (((int) $b['days'] & $bit) === 0) {
                continue;
            }
            [$s, $e] = [$b['start_time'], $b['end_time']];
            $b['on_now'] = $s === $e || ($s < $e ? $clock >= $s && $clock < $e : $clock >= $s);
            $out[] = $b;
        }
        usort($out, fn($a, $b) => [$a['start_time'], (int) $a['id']] <=> [$b['start_time'], (int) $b['id']]);
        // where blocks overlap, the newest wins (as in the planner): only it is "on now"
        $winner = null;
        foreach ($out as $b) {
            if ($b['on_now'] && ($winner === null || (int) $b['id'] > $winner)) {
                $winner = (int) $b['id'];
            }
        }
        return array_map(fn($b) => ['on_now' => (int) $b['id'] === $winner] + $b, $out);
    }

    public static function dayNames(int $days): string
    {
        if ($days === 127) {
            return 'Every day';
        }
        if ($days === 31) {
            return 'Weekdays';
        }
        if ($days === 96) {
            return 'Weekends';
        }
        return implode(', ', array_values(array_filter(self::DAYS, fn($bit) => ($days & $bit) !== 0, ARRAY_FILTER_USE_KEY)));
    }

    /**
     * Pieces of each block per weekday for the grid, clipped to [$from, $to) hours.
     * @return list<array{day:int,top:float,len:float,block:array}> day 0 = Monday; top/len in hours
     */
    public static function gridPieces(array $blocks, int $from = 6, int $to = 24): array
    {
        $out = [];
        $hours = fn(string $t) => (int) substr($t, 0, 2) + (int) substr($t, 3, 2) / 60;
        foreach ($blocks as $b) {
            $s = $hours($b['start_time']);
            $e = $hours($b['end_time']);
            $spans = $s < $e ? [[0, $s, $e]] : ($s === $e ? [[0, 0, 24]] : [[0, $s, 24], [1, 0, $e]]);
            foreach (array_keys(self::DAYS) as $i => $bit) {
                if (!((int) $b['days'] & $bit)) {
                    continue;
                }
                foreach ($spans as [$shift, $a, $z]) {
                    $a = max($a, $from);
                    $z = min($z, $to);
                    if ($z > $a) {
                        $out[] = ['day' => ($i + $shift) % 7, 'top' => $a - $from, 'len' => $z - $a, 'block' => $b];
                    }
                }
            }
        }
        return $out;
    }
}
