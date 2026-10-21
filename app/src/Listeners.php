<?php
declare(strict_types=1);

namespace Stationwagon;

use InvalidArgumentException;

/**
 * Who is listening, counted by pings: each open player (and embed) sends one a minute while it
 * plays, with a random id the page made when Listen was pressed (no cookie). Each Listen is kept
 * for RAW_DAYS with the address and browser details it came from, and what they tell us: device,
 * browser, app (Agent) and country and city (Geo, looked up on this server). After that only
 * totals are left: per hour (the most at once, listener-minutes, Listen presses), per day by each
 * of those details (listener_tally), and one unique mark per listener per day (listener_visitors,
 * a salted hash whose salt changes each month). The Privacy page says all this to listeners.
 *
 * "Listening now" is the Listens heard in the last ACTIVE_SECONDS. Our own test runs (Agent's test
 * mark in the browser details) count as listening now but stay out of every total.
 */
final class Listeners
{
    public const PING_SECONDS = 60;     // how often a player pings (assets/listeners.js)
    public const ACTIVE_SECONDS = 90;   // a ping counts as "listening now" this long
    public const MIN_GAP_SECONDS = 50;  // pings closer than this add no minute (a reload, a fast loop)
    public const RAW_DAYS = 30;         // each Listen, with its address and browser details, is kept this long
    public const KEEP_DAYS = 400;       // the totals are kept this long
    public const NEW_PER_ADDRESS = 20;  // at most this many new Listens from one address…
    public const CAP_SECONDS = 600;     // …in this long (more aren't counted)
    public const SOURCES = ['player', 'embed'];
    public const KINDS = ['source' => 'Where', 'device' => 'Device', 'browser' => 'Browser', 'app' => 'App', 'country' => 'Country', 'city' => 'City'];
    private const SOURCE_NAMES = ['player' => 'Station page', 'embed' => 'Embed on a website'];

    public function __construct(private readonly Db $db, private readonly array $station, private readonly ?Geo $geo = null)
    {
    }

    /**
     * A ping from a player: $stop when Listen is stopped (or the page closes). $ip and $ua are the
     * address and User-Agent it came from. Returns how many are listening now. Throws
     * InvalidArgumentException for an id or source that isn't one, and \OverflowException when
     * one address starts more than NEW_PER_ADDRESS Listens in CAP_SECONDS.
     */
    public function ping(string $sid, string $source, float $now, bool $stop = false, string $ip = '', string $ua = ''): int
    {
        if (preg_match('/^[0-9a-f]{16}$/', $sid) !== 1 || !in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Not a listener id.');
        }
        $ls = $this->db->table('listeners');
        $st = (int) $this->station['id'];
        $row = $this->db->run("SELECT * FROM $ls WHERE station_id = ? AND sid = ?", [$st, $sid])->fetch();
        if ($stop && $row) {
            $this->db->run("UPDATE $ls SET ended = 1 WHERE station_id = ? AND sid = ?", [$st, $sid]);
            return $this->now($now);
        }
        // a Stop that overtakes the Listen's first ping (a slow first request) is kept as an ended
        // Listen below, so that first ping, arriving after it, can't count the player as listening
        if ($row && ((int) $row['ended'] === 1 || $now - (float) $row['last_seen'] < self::MIN_GAP_SECONDS)) {
            return $this->now($now); // a late ping after Stop, or too soon after the last one
        }
        $new = !$row;
        if ($new) {
            $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
            $ua = mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', $ua) ?? '', 0, 400);
            if ($ip !== '' && (int) $this->db->run("SELECT COUNT(*) FROM $ls WHERE station_id = ? AND ip = ? AND first_seen > ?",
                    [$st, $ip, $now - self::CAP_SECONDS])->fetchColumn() >= self::NEW_PER_ADDRESS) {
                throw new \OverflowException('Too many new listens from one address.');
            }
            $agent = Agent::parse($ua);
            $place = $ip !== '' ? ($this->geo ?? Geo::shared())?->lookup($ip) : null;
            $row = ['source' => $source, 'test' => (int) $agent['test'], 'ip' => $ip, 'user_agent' => $ua,
                'device' => $agent['device'], 'browser' => $agent['browser'], 'app' => $agent['app'],
                'country_code' => $place['country_code'] ?? '', 'country' => $place['country'] ?? '', 'city' => $place['city'] ?? ''];
            $this->db->run("INSERT INTO $ls (station_id, sid, source, first_seen, last_seen, minutes, test, ip, user_agent, device, browser, app, country_code, country, city, ended)
                            VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)" . ($stop ? ' ON DUPLICATE KEY UPDATE ended = 1' : ''),
                [$st, $sid, $source, $now, $now, $row['test'], $ip, $ua, $row['device'], $row['browser'], $row['app'],
                    $row['country_code'], $row['country'], $row['city'], $stop ? 1 : 0]);
        } else {
            $this->db->run("UPDATE $ls SET last_seen = ?, minutes = minutes + 1 WHERE station_id = ? AND sid = ?", [$now, $st, $sid]);
        }
        $count = $this->now($now);
        if ((int) $row['test'] !== 1) {
            $this->count($row, $now, $new, $count);
        }
        if ($new) {
            $this->tidy($now);
        }
        return $count;
    }

    /** How many are listening now. */
    public function now(float $now): int
    {
        return (int) $this->db->run('SELECT COUNT(*) FROM ' . $this->db->table('listeners') . ' WHERE station_id = ? AND last_seen > ? AND ended = 0',
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
        $out = [];
        for ($t = $first, $i = 0; $i < $hours; $i++, $t += 3600) {
            $d = $this->local($t);
            $r = $rows[$t] ?? ['peak' => 0, 'minutes' => 0, 'listens' => 0];
            $out[] = ['start' => $t, 'label' => $d->format('H:i'), 'day' => $d->format('D j M')] + $r;
        }
        return $out;
    }

    /**
     * The last $days days (the station's days), oldest first, today last, with the unique listeners
     * of each. @return list<array{date:string, label:string, peak:int, minutes:int, listens:int, uniques:int}>
     */
    public function daily(float $now, int $days = 30): array
    {
        $today = $this->local((int) $now)->setTime(0, 0);
        $start = $today->modify('-' . ($days - 1) . ' days');
        $out = [];
        for ($d = $start; $d <= $today; $d = $d->modify('+1 day')) {
            $out[$d->format('Y-m-d')] = ['date' => $d->format('Y-m-d'), 'label' => $d->format('D j M'), 'peak' => 0, 'minutes' => 0, 'listens' => 0, 'uniques' => 0];
        }
        foreach ($this->rows($start->getTimestamp(), $now) as $t => $r) {
            $key = $this->local($t)->format('Y-m-d');
            if (isset($out[$key])) {
                $out[$key]['peak'] = max($out[$key]['peak'], $r['peak']);
                $out[$key]['minutes'] += $r['minutes'];
                $out[$key]['listens'] += $r['listens'];
            }
        }
        $uniques = $this->db->run('SELECT `day`, COUNT(*) FROM ' . $this->db->table('listener_visitors') . ' WHERE station_id = ? AND `day` >= ? GROUP BY `day`',
            [$this->station['id'], $start->format('Y-m-d')])->fetchAll(\PDO::FETCH_KEY_PAIR);
        foreach ($uniques as $day => $n) {
            if (isset($out[$day])) {
                $out[$day]['uniques'] = (int) $n;
            }
        }
        return array_values($out);
    }

    /**
     * The last $months calendar months (the station's), oldest first, this month last: unique
     * listeners in the month (each counted once however many days they listened), the most at
     * once, minutes and Listen presses.
     * @return list<array{month:string, label:string, uniques:int, peak:int, minutes:int, listens:int}>
     */
    public function monthly(float $now, int $months = 12): array
    {
        $this1 = $this->local((int) $now)->modify('first day of this month')->setTime(0, 0);
        $start = $this1->modify('-' . ($months - 1) . ' months');
        $out = [];
        for ($d = $start; $d <= $this1; $d = $d->modify('+1 month')) {
            $out[$d->format('Y-m')] = ['month' => $d->format('Y-m'), 'label' => $d->format('F Y'), 'uniques' => 0, 'peak' => 0, 'minutes' => 0, 'listens' => 0];
        }
        foreach ($this->rows($start->getTimestamp(), $now) as $t => $r) {
            $key = $this->local($t)->format('Y-m');
            if (isset($out[$key])) {
                $out[$key]['peak'] = max($out[$key]['peak'], $r['peak']);
                $out[$key]['minutes'] += $r['minutes'];
                $out[$key]['listens'] += $r['listens'];
            }
        }
        $uniques = $this->db->run("SELECT DATE_FORMAT(`day`, '%Y-%m') AS m, COUNT(DISTINCT visitor) FROM " . $this->db->table('listener_visitors')
            . ' WHERE station_id = ? AND `day` >= ? GROUP BY m', [$this->station['id'], $start->format('Y-m-d')])->fetchAll(\PDO::FETCH_KEY_PAIR);
        foreach ($uniques as $m => $n) {
            if (isset($out[$m])) {
                $out[$m]['uniques'] = (int) $n;
            }
        }
        return array_values($out);
    }

    /**
     * The last $days days by where, device, browser, app, country and city, most listened first.
     * @return array<string, list<array{value:string, listens:int, minutes:int}>> by kind (KINDS' keys)
     */
    public function breakdown(float $now, int $days = 30): array
    {
        $start = $this->local((int) $now)->setTime(0, 0)->modify('-' . ($days - 1) . ' days');
        $rows = $this->db->run('SELECT kind, value, SUM(listens) AS listens, SUM(minutes) AS minutes FROM ' . $this->db->table('listener_tally')
            . ' WHERE station_id = ? AND `day` >= ? GROUP BY kind, value ORDER BY minutes DESC, listens DESC, value',
            [$this->station['id'], $start->format('Y-m-d')])->fetchAll();
        $out = array_fill_keys(array_keys(self::KINDS), []);
        foreach ($rows as $r) {
            $out[$r['kind']][] = ['value' => (string) $r['value'], 'listens' => (int) $r['listens'], 'minutes' => (int) $r['minutes']];
        }
        return $out;
    }

    /** Add a counted ping to the totals: the hour, the day's tally by each detail, the day's unique mark. */
    private function count(array $row, float $now, bool $new, int $count): void
    {
        $st = (int) $this->station['id'];
        $this->db->run('INSERT INTO ' . $this->db->table('listener_hours') . ' (station_id, `hour`, peak, minutes, listens) VALUES (?, ?, ?, 1, ?)
                        ON DUPLICATE KEY UPDATE peak = GREATEST(peak, ?), minutes = minutes + 1, listens = listens + ?',
            [$st, self::hour($now), $count, (int) $new, $count, (int) $new]);
        $day = $this->local((int) $now)->format('Y-m-d');
        $country = $row['country'] !== '' ? $row['country'] : 'Unknown';
        $values = ['source' => self::SOURCE_NAMES[$row['source']], 'device' => $row['device'], 'browser' => $row['browser'], 'app' => $row['app'],
            'country' => $country, 'city' => $row['city'] !== '' ? $row['city'] . ', ' . $country : 'Unknown'];
        $params = [];
        foreach ($values as $kind => $value) {
            array_push($params, $st, $day, $kind, mb_substr($value !== '' ? $value : 'Unknown', 0, 160), (int) $new);
        }
        $this->db->run('INSERT INTO ' . $this->db->table('listener_tally') . ' (station_id, `day`, kind, value, listens, minutes) VALUES '
            . implode(', ', array_fill(0, count($values), '(?, ?, ?, ?, ?, 1)'))
            . ' ON DUPLICATE KEY UPDATE listens = listens + ' . (int) $new . ', minutes = minutes + 1', $params);
        if ($new && ($row['ip'] !== '' || $row['user_agent'] !== '')) {
            $mark = substr(hash_hmac('sha256', $row['ip'] . "\n" . $row['user_agent'], $this->salt($now), true), 0, 8);
            $this->db->run('INSERT IGNORE INTO ' . $this->db->table('listener_visitors') . ' (station_id, `day`, visitor) VALUES (?, ?, ?)', [$st, $day, $mark]);
        }
    }

    /**
     * This month's salt for the unique marks (in settings.listener_salt). A new month gets a new
     * random one and the old one is gone, so last month's marks can't be matched to anyone.
     */
    private function salt(float $now): string
    {
        $month = $this->local((int) $now)->format('Y-m');
        $t = $this->db->table('settings');
        for ($try = 0; $try < 3; $try++) {
            $raw = $this->db->run("SELECT value FROM $t WHERE name = 'listener_salt'")->fetchColumn();
            $v = $raw === false ? null : json_decode((string) $raw, true);
            if (is_array($v) && ($v['month'] ?? '') === $month && is_string($v['salt'] ?? null)) {
                return (string) hex2bin($v['salt']);
            }
            $fresh = json_encode(['month' => $month, 'salt' => bin2hex(random_bytes(32))]);
            // only one request may replace it: the others read the winner's next time round
            if ($raw === false) {
                $this->db->run("INSERT IGNORE INTO $t (name, value, updated_at) VALUES ('listener_salt', ?, ?)", [$fresh, gmdate('Y-m-d H:i:s')]);
            } else {
                $this->db->run("UPDATE $t SET value = ?, updated_at = ? WHERE name = 'listener_salt' AND value = ?", [$fresh, gmdate('Y-m-d H:i:s'), $raw]);
            }
        }
        throw new \RuntimeException("Can't keep the listener salt.");
    }

    /** Forget Listens older than RAW_DAYS (address and browser details with them) and totals older than KEEP_DAYS. */
    private function tidy(float $now): void
    {
        $st = (int) $this->station['id'];
        $this->db->run('DELETE FROM ' . $this->db->table('listeners') . ' WHERE station_id = ? AND first_seen < ?', [$st, $now - self::RAW_DAYS * 86400]);
        $this->db->run('DELETE FROM ' . $this->db->table('listener_hours') . ' WHERE station_id = ? AND `hour` < ?',
            [$st, self::hour($now - self::KEEP_DAYS * 86400)]);
        $day = gmdate('Y-m-d', (int) $now - self::KEEP_DAYS * 86400);
        $this->db->run('DELETE FROM ' . $this->db->table('listener_tally') . ' WHERE station_id = ? AND `day` < ?', [$st, $day]);
        $this->db->run('DELETE FROM ' . $this->db->table('listener_visitors') . ' WHERE station_id = ? AND `day` < ?', [$st, $day]);
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

    private function local(int $t): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $t))->setTimezone(new \DateTimeZone($this->station['timezone']));
    }

    /** The UTC hour a time falls in, as stored. */
    private static function hour(float $t): string
    {
        return gmdate('Y-m-d H:00:00', (int) floor($t / 3600) * 3600);
    }
}
