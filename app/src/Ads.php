<?php
declare(strict_types=1);

namespace Stationwagon;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The station's ads (round 63). An ad's audio is a `tracks` row with kind = 'ad', so uploading, song
 * prep's levelling, the stream and the timeline treat it like a song; the Music library never lists it
 * (every song query filters kind = 'song'). What makes it an ad is its row in `ads`: how many times a
 * day it should play, on which days and between which hours (the station's own clock), and the dates
 * it runs.
 *
 * The AutoDJ (Planner::plan) asks pick() for an ad each time an ad break is due (every N songs, see
 * settings()). pick() paces the ads through each one's day: an ad with a target of 10 over a window of
 * 10 hours is due about once an hour, so its plays spread out instead of bunching up, and an ad that
 * fell behind (no break came, a live show) catches up at the next break. What is already planned counts
 * as played, like songs, so the plan 3 hours ahead never over-books an ad.
 */
final class Ads
{
    /** Monday = 1 ... Sunday = 64 (like the schedule). */
    public const ALL_DAYS = 127;
    public const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 4 => 'Wed', 8 => 'Thu', 16 => 'Fri', 32 => 'Sat', 64 => 'Sun'];
    /** What listeners and the apps see for an ad (the title and the advertiser stay with the station). */
    public const PUBLIC_TITLE = 'Advertisement';
    private const DAY = 86400;

    public function __construct(private readonly Db $db, private readonly array $station)
    {
    }

    // ---- The station's ad-break settings ---------------------------------------------------------------

    /** @return array{enabled:bool,every:int,per_break:int,gap_minutes:int} */
    public static function settings(array $station): array
    {
        $all = Planner::settings($station);
        return [
            'enabled' => (int) $all['ads_enabled'] === 1,
            'every' => max(1, min(20, (int) $all['ads_every_songs'])),
            'per_break' => max(1, min(3, (int) $all['ads_per_break'])),
            'gap_minutes' => max(0, min(120, (int) $all['ads_gap_minutes'])),
        ];
    }

    /**
     * Check and keep the ad-break settings: on or off, an ad break after every N songs (1 to 20), how many
     * ads in a break (1 to 3) and at least this many minutes between breaks (0 to 120).
     * @return array{0:?array,1:?string} [the updated station row, or null; what's wrong, or null]
     */
    public function saveSettings(array $in): array
    {
        $int = fn(string $k) => isset($in[$k]) && preg_match('/^\d{1,3}$/', trim((string) $in[$k])) ? (int) trim((string) $in[$k]) : null;
        $every = $int('every');
        $per = $int('per_break');
        $gap = $int('gap_minutes');
        if ($every === null || $every < 1 || $every > 20) {
            return [null, 'Ads after every: choose 1 to 20 songs.'];
        }
        if ($per === null || $per < 1 || $per > 3) {
            return [null, 'Ads in a break: choose 1 to 3.'];
        }
        if ($gap === null || $gap > 120) {
            return [null, 'Minutes between breaks: choose 0 to 120.'];
        }
        $own = json_decode((string) ($this->station['settings'] ?? ''), true);
        $own = array_replace(is_array($own) ? $own : [], ['ads_enabled' => empty($in['enabled']) ? 0 : 1, 'ads_every_songs' => $every, 'ads_per_break' => $per, 'ads_gap_minutes' => $gap]);
        $json = json_encode($own, JSON_UNESCAPED_SLASHES);
        $this->db->run('UPDATE ' . $this->db->table('stations') . ' SET settings = ? WHERE id = ?', [$json, $this->station['id']]);
        return [['settings' => $json] + $this->station, null];
    }

    // ---- The ads ---------------------------------------------------------------------------------------

    /** Make the plan for a track that was just uploaded as an ad: all days, all day, 5 plays a day. */
    public function create(int $trackId, ?string $advertiser = null): void
    {
        $this->db->run('INSERT IGNORE INTO ' . $this->db->table('ads') . ' (track_id, station_id, advertiser, created_at) VALUES (?, ?, ?, ?)',
            [$trackId, $this->station['id'], $advertiser !== null && trim($advertiser) !== '' ? mb_substr(trim($advertiser), 0, 190) : null, gmdate('Y-m-d H:i:s')]);
    }

    /**
     * Every ad with how it did today (the station's day up to $now): aired (started) and planned (still to come).
     * @return list<array<string,mixed>>
     */
    public function all(float $now): array
    {
        $a = $this->db->table('ads');
        $t = $this->db->table('tracks');
        $rows = $this->db->run(
            "SELECT a.*, t.title, t.duration_s, t.file, t.loudness_lufs, t.mastered, t.created_at AS uploaded_at
             FROM $a a JOIN $t t ON t.id = a.track_id
             WHERE a.station_id = ? AND t.kind = 'ad' AND t.status = 'ready' ORDER BY t.title, a.track_id",
            [$this->station['id']]
        )->fetchAll();
        [$from, $to] = $this->dayBounds($now);
        $count = $this->counts($from, $to, $now);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['track_id'];
            $r['aired_today'] = $count[$id]['aired'] ?? 0;
            $r['planned_today'] = $count[$id]['planned'] ?? 0;
            $r['last_played'] = $count[$id]['last'] ?? null;
            $out[] = $r;
        }
        return $out;
    }

    public function get(int $trackId): ?array
    {
        $a = $this->db->table('ads');
        $t = $this->db->table('tracks');
        $row = $this->db->run("SELECT a.*, t.title, t.duration_s FROM $a a JOIN $t t ON t.id = a.track_id WHERE a.track_id = ? AND a.station_id = ? AND t.kind = 'ad'",
            [$trackId, $this->station['id']])->fetch();
        return $row ?: null;
    }

    /**
     * Check an ad's plan as the form posts it: title, advertiser, active, plays per day (0 to 500), start and end dates,
     * days (a list of 1 to 7, Monday = 1), from and until (HH:MM, both or neither), priority (0 or 1) and notes.
     * @return array{0:?array,1:?string} [the clean values (ads columns plus title), what's wrong]
     */
    public static function check(array $in): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            return [null, 'An ad needs a title.'];
        }
        $target = trim((string) ($in['daily_target'] ?? ''));
        if (!preg_match('/^\d{1,3}$/', $target) || (int) $target > 500) {
            return [null, 'Plays per day: choose a whole number from 0 to 500 (0 pauses the ad).'];
        }
        $dates = [];
        foreach (['start_date' => 'Start date', 'end_date' => 'End date'] as $k => $label) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v === '') {
                $dates[$k] = null;
                continue;
            }
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            if ($d === false || $d->format('Y-m-d') !== $v) {
                return [null, "$label must be a date, like 2027-01-31."];
            }
            $dates[$k] = $v;
        }
        if ($dates['start_date'] !== null && $dates['end_date'] !== null && $dates['end_date'] < $dates['start_date']) {
            return [null, 'The end date is before the start date.'];
        }
        $mask = 0;
        foreach ((array) ($in['days'] ?? []) as $d) {
            if (is_numeric($d) && (int) $d >= 1 && (int) $d <= 7) {
                $mask |= 1 << ((int) $d - 1);
            }
        }
        if ($mask === 0) {
            return [null, 'Choose at least one day for the ad to play.'];
        }
        $times = [];
        foreach (['window_start' => 'From', 'window_end' => 'Until'] as $k => $label) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v === '') {
                $times[$k] = null;
                continue;
            }
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $v)) {
                return [null, "$label must be a time, like 06:00."];
            }
            $times[$k] = $v;
        }
        if (($times['window_start'] === null) !== ($times['window_end'] === null)) {
            return [null, 'Give both a From and an Until time, or leave both empty for all day.'];
        }
        $notes = trim((string) ($in['notes'] ?? ''));
        return [[
            'title' => mb_substr($title, 0, 255),
            'advertiser' => ($adv = trim((string) ($in['advertiser'] ?? ''))) !== '' ? mb_substr($adv, 0, 190) : null,
            'active' => empty($in['active']) ? 0 : 1,
            'daily_target' => (int) $target,
            'start_date' => $dates['start_date'], 'end_date' => $dates['end_date'],
            'days' => $mask,
            'window_start' => $times['window_start'], 'window_end' => $times['window_end'],
            'priority' => !empty($in['priority']) ? 1 : 0,
            'notes' => $notes !== '' ? mb_substr($notes, 0, 2000) : null,
        ], null];
    }

    /** Save an ad's plan (see check()); returns what's wrong, or null. */
    public function update(int $trackId, array $in): ?string
    {
        if ($this->get($trackId) === null) {
            return 'That ad isn’t here.';
        }
        [$v, $problem] = self::check($in);
        if ($v === null) {
            return $problem;
        }
        $title = $v['title'];
        unset($v['title']);
        $this->db->run('UPDATE ' . $this->db->table('tracks') . ' SET title = ? WHERE id = ? AND station_id = ?', [$title, $trackId, $this->station['id']]);
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($v)));
        $this->db->run('UPDATE ' . $this->db->table('ads') . " SET $sets WHERE track_id = ?", [...array_values($v), $trackId]);
        return null;
    }

    /** "Every day, all day" or "Mon–Fri · 06:00–18:00" for an ad's days and hours. */
    public static function describe(array $ad): string
    {
        $mask = (int) $ad['days'];
        if ($mask === self::ALL_DAYS) {
            $days = 'Every day';
        } elseif ($mask === 31) {
            $days = 'Mon–Fri';
        } elseif ($mask === 96) {
            $days = 'Sat–Sun';
        } else {
            $days = implode(', ', array_filter(self::DAY_NAMES, fn($bit) => ($mask & $bit) !== 0, ARRAY_FILTER_USE_KEY));
        }
        $hours = ($ad['window_start'] ?? null) !== null && ($ad['window_end'] ?? null) !== null && $ad['window_start'] !== $ad['window_end']
            ? $ad['window_start'] . '–' . $ad['window_end'] : 'all day';
        return "$days · $hours";
    }

    /** An ad's state on the station's day $today ('Y-m-d'): [label, whether it is running]. */
    public static function status(array $ad, string $today): array
    {
        if ((int) $ad['active'] !== 1 || (int) $ad['daily_target'] === 0) {
            return ['Paused', false];
        }
        if ($ad['end_date'] !== null && $today > $ad['end_date']) {
            return ['Ended', false];
        }
        if ($ad['start_date'] !== null && $today < $ad['start_date']) {
            return ['Starts ' . (new DateTimeImmutable($ad['start_date']))->format('j M'), false];
        }
        return ['Running', true];
    }

    // ---- Choosing an ad for a break --------------------------------------------------------------------

    /**
     * The ad for a break that starts at $at (unix seconds), or null when none is due: the ad that is furthest
     * behind its pace today. $skip are ad track ids already in this break.
     */
    public function pick(float $at, array $skip = []): ?int
    {
        $a = $this->db->table('ads');
        $t = $this->db->table('tracks');
        $rows = $this->db->run(
            "SELECT a.* FROM $a a JOIN $t t ON t.id = a.track_id
             WHERE a.station_id = ? AND a.active = 1 AND a.daily_target > 0 AND t.kind = 'ad' AND t.status = 'ready'",
            [$this->station['id']]
        )->fetchAll();
        if (!$rows) {
            return null;
        }
        $tz = $this->zone();
        $local = (new DateTimeImmutable('@' . (int) floor($at)))->setTimezone($tz);
        $today = $local->format('Y-m-d');
        $bit = 1 << ((int) $local->format('N') - 1);
        $secs = (int) $local->format('G') * 3600 + (int) $local->format('i') * 60 + (int) $local->format('s');
        [$from, $to] = $this->dayBounds($at);
        $count = $this->counts($from, $to, $at);
        $best = null;
        foreach ($rows as $r) {
            $id = (int) $r['track_id'];
            if (in_array($id, $skip, true)
                || ($r['start_date'] !== null && $today < $r['start_date']) || ($r['end_date'] !== null && $today > $r['end_date'])
                || ((int) $r['days'] & $bit) === 0) {
                continue;
            }
            [$ws, $we] = [self::seconds($r['window_start']) ?? 0, self::seconds($r['window_end']) ?? 0];
            $length = ($we - $ws + self::DAY) % self::DAY ?: self::DAY; // equal start and end (or none) is all day; an end before the start runs past midnight
            $into = ($secs - $ws + self::DAY) % self::DAY;
            if ($into >= $length) {
                continue; // outside its hours
            }
            // an ad added today paces over what is left of its hours, not over hours that had passed before it was there
            $added = (new DateTimeImmutable((string) $r['created_at'], new DateTimeZone('UTC')))->setTimezone($tz);
            if ($added->format('Y-m-d') === $today && ($ws === $we || $we > $ws)) { // (hours that run past midnight are paced as they are)
                $end = $ws === $we ? self::DAY : $we;
                $from0 = max($ws, (int) $added->format('G') * 3600 + (int) $added->format('i') * 60);
                if ($from0 >= $end) {
                    continue;
                }
                [$ws, $length, $into] = [$from0, $end - $from0, max(0, $secs - $from0)];
            }
            $target = (int) $r['daily_target'];
            $played = ($count[$id]['aired'] ?? 0) + ($count[$id]['planned'] ?? 0);
            $due = $target * $into / $length;
            if ($played >= min($target, max(1, (int) ceil($due - 1e-9)))) {
                continue; // not due yet, or done for today
            }
            // never nearer to its last play than half its usual spacing, so an ad that fell behind catches up without bunching
            if (isset($count[$id]) && abs($at - $count[$id]['last']) < 0.5 * $length / $target) {
                continue;
            }
            $score = [$due - $played, -($count[$id]['last'] ?? 0.0), (int) $r['priority'], -$id];
            if ($best === null || $score > $best[0]) {
                $best = [$score, $id];
            }
        }
        return $best[1] ?? null;
    }

    /** The audio facts the planner needs to place an ad (like Planner::tracks() for a song), or null. */
    public function track(int $trackId): ?array
    {
        $r = $this->db->run('SELECT id, duration_s, outro FROM ' . $this->db->table('tracks') . " WHERE id = ? AND station_id = ? AND kind = 'ad' AND status = 'ready'",
            [$trackId, $this->station['id']])->fetch();
        return $r ? ['id' => (int) $r['id'], 'duration' => (float) $r['duration_s'], 'outro' => $r['outro']] : null;
    }

    /**
     * Where the ad breaks stand at the end of the timeline: songs since the last ad (a live show or an ad
     * ends the count) and when the last ad ended (for the minutes between breaks).
     * @return array{since:int,last_end:?float}
     */
    public function state(): array
    {
        $rows = $this->db->run(
            'SELECT tl.type, tl.start_at, tl.play_s, t.kind FROM ' . $this->db->table('timeline') . ' tl LEFT JOIN ' . $this->db->table('tracks') . ' t ON t.id = tl.track_id
             WHERE tl.station_id = ? ORDER BY tl.n DESC LIMIT 60',
            [$this->station['id']]
        )->fetchAll();
        $since = 0;
        $lastEnd = null;
        foreach ($rows as $r) {
            if ($r['type'] === 'live') {
                break;
            }
            if ($r['kind'] === 'ad') {
                $lastEnd = (float) $r['start_at'] + (float) $r['play_s'];
                break;
            }
            $since++;
        }
        return ['since' => $since, 'last_end' => $lastEnd];
    }

    // ---- What aired ------------------------------------------------------------------------------------

    /** Log the ads that have finished playing (each once); called by the planner whenever it plans. */
    public function logAired(float $now): void
    {
        $this->db->run(
            'INSERT IGNORE INTO ' . $this->db->table('ad_plays') . ' (station_id, n, track_id, title, advertiser, started_at, played_s)
             SELECT tl.station_id, tl.n, tl.track_id, t.title, a.advertiser, tl.start_at, tl.play_s
             FROM ' . $this->db->table('timeline') . ' tl JOIN ' . $this->db->table('tracks') . " t ON t.id = tl.track_id AND t.kind = 'ad'
             LEFT JOIN " . $this->db->table('ads') . " a ON a.track_id = t.id
             WHERE tl.station_id = ? AND tl.type = 'song' AND tl.start_at + tl.play_s <= ?",
            [$this->station['id'], $now]
        );
    }

    /** How many times each ad aired on each of the last $days days (the station's days, newest first), from the log. @return array<string,array<int,int>> day => track id => plays */
    public function history(float $now, int $days = 7): array
    {
        $tz = $this->zone();
        $from = (new DateTimeImmutable('@' . (int) $now))->setTimezone($tz)->setTime(0, 0)->modify('-' . max(0, $days - 1) . ' days')->getTimestamp();
        $rows = $this->db->run('SELECT track_id, started_at FROM ' . $this->db->table('ad_plays') . ' WHERE station_id = ? AND started_at >= ? ORDER BY started_at DESC', [$this->station['id'], $from])->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $day = (new DateTimeImmutable('@' . (int) $r['started_at']))->setTimezone($tz)->format('Y-m-d');
            $out[$day][(int) $r['track_id']] = ($out[$day][(int) $r['track_id']] ?? 0) + 1;
        }
        return $out;
    }

    // ---- Helpers ---------------------------------------------------------------------------------------

    private function zone(): DateTimeZone
    {
        try {
            return new DateTimeZone((string) ($this->station['timezone'] ?? 'UTC') ?: 'UTC');
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }

    /** The start and end of the station's day that holds $at, in unix seconds. @return array{0:int,1:int} */
    private function dayBounds(float $at): array
    {
        $start = (new DateTimeImmutable('@' . (int) floor($at)))->setTimezone($this->zone())->setTime(0, 0);
        return [$start->getTimestamp(), $start->modify('+1 day')->getTimestamp()];
    }

    /**
     * Each ad's plays on the timeline between $from and $to: aired (started by $now) and planned (still to come),
     * and when it last started (on the timeline, which keeps a day).
     * @return array<int,array{aired:int,planned:int,last:float}>
     */
    private function counts(int $from, int $to, float $now): array
    {
        $rows = $this->db->run(
            'SELECT tl.track_id, SUM(tl.start_at <= ?) AS aired, SUM(tl.start_at > ?) AS planned, MAX(tl.start_at) AS last
             FROM ' . $this->db->table('timeline') . ' tl JOIN ' . $this->db->table('tracks') . " t ON t.id = tl.track_id AND t.kind = 'ad'
             WHERE tl.station_id = ? AND tl.type = 'song' AND tl.start_at >= ? AND tl.start_at < ? GROUP BY tl.track_id",
            [$now, $now, $this->station['id'], $from, $to]
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['track_id']] = ['aired' => (int) $r['aired'], 'planned' => (int) $r['planned'], 'last' => (float) $r['last']];
        }
        return $out;
    }

    /** 'HH:MM' to seconds into the day, or null. */
    private static function seconds(?string $hhmm): ?int
    {
        return $hhmm !== null && preg_match('/^(\d\d):(\d\d)$/', $hhmm, $m) ? (int) $m[1] * 3600 + (int) $m[2] * 60 : null;
    }
}
