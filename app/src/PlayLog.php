<?php
declare(strict_types=1);

namespace Stationwagon;

use DateTimeImmutable;
use DateTimeZone;

/**
 * What aired, kept after the timeline forgets it (it keeps about a day): ads in `ad_plays` (round 63) and jingles in
 * `jingle_plays` (schema 23). One row per play on any stream, written once it has finished (logAired, called by the
 * planner whenever it plans and by the screen that shows the log). The Ads and Jingles screens show the last days
 * from it, and it downloads as CSV (proof of play for an advertiser). Listener stats still leave jingles and ads out.
 */
final class PlayLog
{
    /** How far back the CSV goes, in days, at most */
    public const MAX_DAYS = 400;

    private readonly string $table;
    private readonly bool $ads;

    /** @param array $station the main station or a stream: the log covers every stream of the station either way */
    public function __construct(private readonly Db $db, private readonly array $station, string $kind)
    {
        if (!in_array($kind, ['ad', 'jingle'], true)) {
            throw new \InvalidArgumentException('A play log is for ads or jingles.');
        }
        $this->ads = $kind === 'ad';
        $this->table = $db->table($this->ads ? 'ad_plays' : 'jingle_plays');
    }

    /** Log the clips of this kind that have finished playing on this stream's timeline (each once). */
    public function logAired(float $now): void
    {
        $kind = $this->ads ? 'ad' : 'jingle';
        $cols = $this->ads ? 'station_id, n, track_id, title, advertiser, started_at, played_s' : 'station_id, n, track_id, title, started_at, played_s';
        $pick = $this->ads ? 'tl.station_id, tl.n, tl.track_id, t.title, a.advertiser, tl.start_at, tl.play_s' : 'tl.station_id, tl.n, tl.track_id, t.title, tl.start_at, tl.play_s';
        $join = $this->ads ? ' LEFT JOIN ' . $this->db->table('ads') . ' a ON a.track_id = t.id' : '';
        $this->db->run(
            "INSERT IGNORE INTO {$this->table} ($cols)
             SELECT $pick FROM " . $this->db->table('timeline') . ' tl JOIN ' . $this->db->table('tracks') . " t ON t.id = tl.track_id AND t.kind = '$kind'$join
             WHERE tl.station_id = ? AND tl.type = 'song' AND tl.start_at + tl.play_s <= ?",
            [$this->station['id'], $now]
        );
    }

    /**
     * Plays a day for the last $days days (the station's days, oldest first), one row per clip (by its id, or its title
     * once it was removed), busiest first.
     * @return array{days:list<string>,rows:list<array{track_id:?int,title:string,advertiser:?string,counts:array<string,int>,total:int}>}
     */
    public function summary(float $now, int $days = 7): array
    {
        $days = max(1, min(31, $days));
        $tz = $this->zone();
        $today = (new DateTimeImmutable('@' . (int) $now))->setTimezone($tz)->setTime(0, 0);
        $list = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $list[] = $today->modify("-$i days")->format('Y-m-d');
        }
        $rows = [];
        foreach ($this->plays($today->modify('-' . ($days - 1) . ' days')->getTimestamp(), $now + 1) as $p) {
            $key = $p['track_id'] !== null ? 'id' . $p['track_id'] : 't' . $p['title'];
            $day = (new DateTimeImmutable('@' . (int) $p['started_at']))->setTimezone($tz)->format('Y-m-d');
            $rows[$key] ??= ['track_id' => $p['track_id'], 'title' => $p['title'], 'advertiser' => $p['advertiser'], 'counts' => [], 'total' => 0];
            $rows[$key]['counts'][$day] = ($rows[$key]['counts'][$day] ?? 0) + 1;
            $rows[$key]['total']++;
        }
        $rows = array_values($rows);
        usort($rows, fn(array $a, array $b) => [$b['total'], $a['title']] <=> [$a['total'], $b['title']]);
        return ['days' => $list, 'rows' => $rows];
    }

    /**
     * Every play from $from to before $to (unix seconds), oldest first, with the stream it was on (the title and
     * advertiser as they were when it aired).
     * @return list<array{track_id:?int,title:string,advertiser:?string,started_at:float,played_s:float,stream:string}>
     */
    public function plays(int|float $from, int|float $to): array
    {
        $streams = [];
        foreach (Streams::all($this->db) as $s) {
            $streams[(int) $s['id']] = (string) $s['name'];
        }
        if (!$streams) {
            return [];
        }
        $in = implode(',', array_fill(0, count($streams), '?'));
        $rows = $this->db->run(
            'SELECT station_id, track_id, title, ' . ($this->ads ? 'advertiser' : 'NULL AS advertiser') . ", started_at, played_s FROM {$this->table}
             WHERE station_id IN ($in) AND started_at >= ? AND started_at < ? ORDER BY started_at, id",
            [...array_keys($streams), $from, $to]
        )->fetchAll();
        return array_map(fn(array $r) => [
            'track_id' => $r['track_id'] !== null ? (int) $r['track_id'] : null,
            'title' => (string) $r['title'],
            'advertiser' => $r['advertiser'] !== null && $r['advertiser'] !== '' ? (string) $r['advertiser'] : null,
            'started_at' => (float) $r['started_at'],
            'played_s' => (float) $r['played_s'],
            'stream' => $streams[(int) $r['station_id']] ?? '',
        ], $rows);
    }

    /** The log of the last $days days (the station's days, today included) as CSV text: one line per play, in station time. */
    public function csv(float $now, int $days): string
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        $tz = $this->zone();
        $from = (new DateTimeImmutable('@' . (int) $now))->setTimezone($tz)->setTime(0, 0)->modify('-' . ($days - 1) . ' days')->getTimestamp();
        $out = fopen('php://temp', 'r+');
        $head = $this->ads ? ['Date', 'Time', 'Ad', 'Advertiser', 'Seconds played', 'Stream'] : ['Date', 'Time', 'Jingle', 'Seconds played', 'Stream'];
        fputcsv($out, $head, ',', '"', '');
        foreach ($this->plays($from, $now + 1) as $p) {
            $at = (new DateTimeImmutable('@' . (int) floor($p['started_at'])))->setTimezone($tz);
            $line = [$at->format('Y-m-d'), $at->format('H:i:s'), self::cell($p['title'])];
            if ($this->ads) {
                $line[] = self::cell((string) $p['advertiser']);
            }
            fputcsv($out, [...$line, number_format($p['played_s'], 1, '.', ''), self::cell($p['stream'])], ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return "\u{FEFF}" . $csv; // a byte-order mark, so a spreadsheet reads the names as UTF-8
    }

    /** A cell a spreadsheet won't run as a formula. */
    private static function cell(string $v): string
    {
        return $v !== '' && str_contains('=+-@' . "\t\r", $v[0]) ? "'" . $v : $v;
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
