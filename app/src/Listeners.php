<?php
declare(strict_types=1);

namespace Stationwagon;

use InvalidArgumentException;

/**
 * Who is listening, counted by pings: each open player (and embed) sends one a minute while it
 * plays, with a random id the page made when Listen was pressed. Nothing personal is kept: no
 * cookie, no IP address, no browser details, and the id is gone with the page. "Listening now" is
 * the ids heard in the last ACTIVE_SECONDS; each hour keeps the most at once, listener-minutes and
 * Listen presses, for the manager's charts.
 */
final class Listeners
{
    public const PING_SECONDS = 60;     // how often a player pings (assets/listeners.js)
    public const ACTIVE_SECONDS = 90;   // a ping counts as "listening now" this long
    public const MIN_GAP_SECONDS = 50;  // pings closer than this add no minute (a reload, a fast loop)
    public const FORGET_SECONDS = 600;  // quiet players are deleted after this
    public const KEEP_DAYS = 400;       // hourly stats are kept this long
    public const SOURCES = ['player', 'embed'];

    public function __construct(private readonly Db $db, private readonly array $station)
    {
    }

    /**
     * A ping from a player: $stop when Listen is stopped (or the page closes). Returns how many are
     * listening now. Throws InvalidArgumentException for an id or source that isn't one.
     */
    public function ping(string $sid, string $source, float $now, bool $stop = false): int
    {
        if (preg_match('/^[0-9a-f]{16}$/', $sid) !== 1 || !in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Not a listener id.');
        }
        $ls = $this->db->table('listeners');
        $st = (int) $this->station['id'];
        if ($stop) {
            $this->db->run("DELETE FROM $ls WHERE station_id = ? AND sid = ?", [$st, $sid]);
            return $this->now($now);
        }
        $last = $this->db->run("SELECT last_seen FROM $ls WHERE station_id = ? AND sid = ?", [$st, $sid])->fetchColumn();
        if ($last !== false && $now - (float) $last < self::MIN_GAP_SECONDS) {
            return $this->now($now);
        }
        $new = $last === false;
        $this->db->run("INSERT INTO $ls (station_id, sid, source, first_seen, last_seen) VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE last_seen = ?", [$st, $sid, $source, $now, $now, $now]);
        $count = $this->now($now);
        $this->db->run('INSERT INTO ' . $this->db->table('listener_hours') . ' (station_id, `hour`, peak, minutes, listens) VALUES (?, ?, ?, 1, ?)
                        ON DUPLICATE KEY UPDATE peak = GREATEST(peak, ?), minutes = minutes + 1, listens = listens + ?',
            [$st, self::hour($now), $count, (int) $new, $count, (int) $new]);
        if ($new) {
            // tidy up now and then: quiet players, and stats older than KEEP_DAYS
            $this->db->run("DELETE FROM $ls WHERE station_id = ? AND last_seen < ?", [$st, $now - self::FORGET_SECONDS]);
            $this->db->run('DELETE FROM ' . $this->db->table('listener_hours') . ' WHERE station_id = ? AND `hour` < ?',
                [$st, self::hour($now - self::KEEP_DAYS * 86400)]);
        }
        return $count;
    }

    /** How many are listening now. */
    public function now(float $now): int
    {
        return (int) $this->db->run('SELECT COUNT(*) FROM ' . $this->db->table('listeners') . ' WHERE station_id = ? AND last_seen > ?',
            [$this->station['id'], $now - self::ACTIVE_SECONDS])->fetchColumn();
    }

    /**
     * The last $hours hours, oldest first, in the station's time zone: one entry per hour, empty
     * hours as zeros. @return list<array{start:int, label:string, day:string, peak:int, minutes:int, listens:int}>
     */
    public function hourly(float $now, int $hours = 48): array
    {
        $first = (int) floor($now / 3600) * 3600 - ($hours - 1) * 3600;
        $rows = $this->rows($first, $now);
        $tz = new \DateTimeZone($this->station['timezone']);
        $out = [];
        for ($t = $first, $i = 0; $i < $hours; $i++, $t += 3600) {
            $d = (new \DateTimeImmutable('@' . $t))->setTimezone($tz);
            $r = $rows[$t] ?? ['peak' => 0, 'minutes' => 0, 'listens' => 0];
            $out[] = ['start' => $t, 'label' => $d->format('H:i'), 'day' => $d->format('D j M')] + $r;
        }
        return $out;
    }

    /**
     * The last $days days (the station's days), oldest first, today last.
     * @return list<array{date:string, label:string, peak:int, minutes:int, listens:int}>
     */
    public function daily(float $now, int $days = 30): array
    {
        $tz = new \DateTimeZone($this->station['timezone']);
        $today = (new \DateTimeImmutable('@' . (int) $now))->setTimezone($tz)->setTime(0, 0);
        $start = $today->modify('-' . ($days - 1) . ' days');
        $out = [];
        for ($d = $start; $d <= $today; $d = $d->modify('+1 day')) {
            $out[$d->format('Y-m-d')] = ['date' => $d->format('Y-m-d'), 'label' => $d->format('D j M'), 'peak' => 0, 'minutes' => 0, 'listens' => 0];
        }
        foreach ($this->rows($start->getTimestamp(), $now) as $t => $r) {
            $key = (new \DateTimeImmutable('@' . $t))->setTimezone($tz)->format('Y-m-d');
            if (isset($out[$key])) {
                $out[$key]['peak'] = max($out[$key]['peak'], $r['peak']);
                $out[$key]['minutes'] += $r['minutes'];
                $out[$key]['listens'] += $r['listens'];
            }
        }
        return array_values($out);
    }

    /** @return array<int, array{peak:int, minutes:int, listens:int}> by the hour's unix start */
    private function rows(int $from, float $to): array
    {
        $rows = $this->db->run('SELECT `hour`, peak, minutes, listens FROM ' . $this->db->table('listener_hours') . ' WHERE station_id = ? AND `hour` >= ? AND `hour` <= ?',
            [$this->station['id'], self::hour($from), self::hour($to)])->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(new \DateTimeImmutable($r['hour'], new \DateTimeZone('UTC')))->getTimestamp()] =
                ['peak' => (int) $r['peak'], 'minutes' => (int) $r['minutes'], 'listens' => (int) $r['listens']];
        }
        return $out;
    }

    /** The UTC hour a time falls in, as stored. */
    private static function hour(float $t): string
    {
        return gmdate('Y-m-d H:00:00', (int) floor($t / 3600) * 3600);
    }
}
