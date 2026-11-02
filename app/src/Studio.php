<?php
declare(strict_types=1);

namespace Stationwagon;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The live studio's rules, on top of the planner (go live, pieces, end show) and the presenter
 * notes. Thin like ManagePage: the audio work happens in the browser (assets/studio.js).
 *
 * Who may go live (the user's choice, 2026-10-20): a DJ only during their own live block in the
 * schedule, from EARLY_SECONDS before it starts until it ends; a live block with no DJ named is
 * open to any of the station's DJs. Managers and admins may go live any time. A show runs on past
 * its block's end until the DJ ends it (or the safety net does).
 *
 * The running order is a show plan: songs and station IDs from the library and talk items with a
 * planned length. It belongs to the live block ("block:<id>"), or to the person when they go
 * live outside a block ("user:<id>").
 */
final class Studio
{
    public const EARLY_SECONDS = 300;
    public const ORDER_MAX = 60;
    public const ORDER_KINDS = ['song', 'id', 'talk'];

    private Db $db;
    private array $station;

    public function __construct(private readonly Planner $planner)
    {
        $this->db = $planner->db();
        $this->station = $planner->station();
    }

    // ---- When a person may go live --------------------------------------------------------

    /**
     * The block that frames this person's show now, whether they may go live, and their next
     * block if they can't yet. @return array{may:bool, reason:?string, block:?array, next:?array, title:string, scope:string}
     */
    public function slot(array $user, float $now): array
    {
        $manager = Auth::allows($user, 'onair');
        $current = $mine = $next = null;
        foreach ($this->liveBlocks() as $b) {
            foreach ($this->occurrences($b, $now) as $o) {
                $forMe = $b['dj_user_id'] === null || (int) $b['dj_user_id'] === (int) $user['id'];
                $covers = $o['start'] <= $now && $now < $o['end'];
                $early = $o['start'] - self::EARLY_SECONDS <= $now && $now < $o['end'];
                if ($covers && ($current === null || $o['id'] > $current['id'])) {
                    $current = $o;
                }
                if ($forMe && $early && ($mine === null || $o['start'] < $mine['start'] || ($o['start'] === $mine['start'] && $o['id'] > $mine['id']))) {
                    $mine = $o;
                }
                if ($forMe && $o['start'] > $now && ($next === null || $o['start'] < $next['start'])) {
                    $next = $o;
                }
            }
        }
        $block = $mine ?? ($manager ? $current : null);
        $out = [
            'may' => $manager || $mine !== null,
            'reason' => null,
            'block' => $block,
            'next' => $mine ? null : $next,
            'title' => $block['title'] ?? 'Live with ' . $user['name'],
            'scope' => $block ? 'block:' . $block['id'] : 'user:' . $user['id'],
        ];
        if (!$out['may']) {
            $out['reason'] = $next
                ? sprintf('Your next show, %s, starts %s. You can go live from %s.', $next['title'], $this->when($next['start'], $now), $this->clock($next['start'] - self::EARLY_SECONDS))
                : 'You have no live show in the schedule. Ask a manager to add one for you.';
        }
        $open = $this->openShow($now);
        if ($open) {
            $out['may'] = false;
            $who = $open['dj_name'] ?: 'Another presenter';
            $out['reason'] = match (true) {
                (int) $open['dj_user_id'] === (int) $user['id'] => $this->rejoinMinutes() > 0
                    ? 'Your show is still on air from before. Rejoin it to carry on, or end it.'
                    : 'Your show is still on air from before. End it, then go live again.',
                $this->canControl($user, $open) => sprintf('%s is on air now (%s). As a manager you can end that show here.', $who, $open['title']),
                default => sprintf('%s is on air now (%s). Wait until that show ends.', $who, $open['title']),
            };
        }
        $out['rejoin'] = $this->rejoinable($user, $open, $out['may'], $now);
        return $out;
    }

    private function rejoinMinutes(): int
    {
        return (int) $this->planner->setting('rejoin_minutes');
    }

    /**
     * The DJ's own show they may carry on (Sound & safety: DJ rejoin window): one still open (the
     * studio was reloaded) or one the safety net ended less than the window ago, while they may go
     * live. @return ?array{public_id:string, title:string, open:bool, until:?float}
     */
    private function rejoinable(array $user, ?array $open, bool $may, float $now): ?array
    {
        $window = $this->rejoinMinutes() * 60;
        if ($window <= 0) {
            return null;
        }
        if ($open) {
            return (int) $open['dj_user_id'] === (int) $user['id']
                ? ['public_id' => $open['public_id'], 'title' => $open['title'], 'open' => true, 'until' => null] : null;
        }
        if (!$may) {
            return null;
        }
        $ls = $this->db->table('live_shows');
        $last = $this->db->run("SELECT * FROM $ls WHERE station_id = ? AND dj_user_id = ? ORDER BY id DESC LIMIT 1",
            [$this->station['id'], $user['id']])->fetch();
        if (!$last || (int) $last['open'] === 1 || $last['closed_reason'] !== 'timeout' || (float) $last['last_piece_at'] + $window < $now) {
            return null;
        }
        return ['public_id' => $last['public_id'], 'title' => $last['title'], 'open' => false, 'until' => (float) $last['last_piece_at'] + $window];
    }

    /**
     * Carry on the DJ's own show, open (the studio was reloaded) or ended by the safety net: it goes
     * live again as a new show that continues it, taking over its notes. An open one is closed first
     * and the AutoDJ fills the moments between. (Sending the open show's next pieces instead would
     * put the new audio at the stream time where the old pieces stopped, in the past: after a gap
     * of more than about 12 s every later piece would reach listeners too late.)
     */
    public function rejoin(array $user, float $now): array
    {
        $slot = $this->slot($user, $now);
        $r = $slot['rejoin'];
        if ($r === null) {
            return ['error' => 'There’s no show of yours to rejoin.', 'status' => 409];
        }
        $prev = $this->show($r['public_id']);
        if ($r['open']) {
            $this->planner->endLive($now, $prev['public_id'], 'rejoined');
        }
        $res = $this->planner->goLive($now, $prev['title'], (int) $user['id'], (int) $prev['id'])['result'];
        if (isset($res['error'])) {
            return ['error' => $res['error'], 'status' => 409];
        }
        $this->db->run('UPDATE ' . $this->db->table('presenter_notes') . ' SET live_show_id = ? WHERE live_show_id = ?', [$res['live']['live_show_id'], $prev['id']]);
        return ['live' => $res['live'], 'handover' => $this->forStudio($res['handover']), 'continued' => $prev['public_id'], 'slot' => $slot];
    }

    /** Start the show: checks the rules, then the planner hands over at the next piece boundary. */
    public function goLive(array $user, float $now): array
    {
        $slot = $this->slot($user, $now);
        if (!$slot['may']) {
            return ['error' => $slot['reason'], 'status' => 409];
        }
        $r = $this->planner->goLive($now, $slot['title'], (int) $user['id'])['result'];
        if (isset($r['error'])) {
            return ['error' => $r['error'], 'status' => 409];
        }
        return ['live' => $r['live'], 'handover' => $this->forStudio($r['handover']), 'slot' => $slot];
    }

    public function addPiece(array $user, string $publicId, int $seq, float $duration, string $bytes, float $now): array
    {
        $show = $this->show($publicId);
        if (!$show || !$this->canControl($user, $show)) {
            return ['error' => 'This isn’t your show.', 'status' => 403];
        }
        if ($duration <= 0 || $duration > 2 * (float) $this->planner->setting('segment_seconds') + 1 || $bytes === '' || strlen($bytes) > 2_000_000) {
            return ['error' => 'That piece has the wrong length.', 'status' => 400];
        }
        if (!self::looksLikeMp3($bytes)) {
            return ['error' => 'That piece isn’t MP3 audio.', 'status' => 400];
        }
        $r = $this->planner->addPiece($now, $publicId, $seq, $duration, $bytes)['result'];
        return $r + (isset($r['error']) ? [] : ['status' => 200]);
    }

    public function endLive(array $user, string $publicId, float $now): array
    {
        $show = $this->show($publicId);
        if (!$show || !$this->canControl($user, $show)) {
            return ['error' => 'This isn’t your show.', 'status' => 403];
        }
        return $this->planner->endLive($now, $publicId)['result'];
    }

    /** The open live show with its DJ's name, if any. */
    public function openShow(?float $now = null): ?array
    {
        $now ??= microtime(true);
        $ls = $this->db->table('live_shows');
        $row = $this->db->run("SELECT ls.*, u.name AS dj_name FROM $ls ls LEFT JOIN {$this->db->table('users')} u ON u.id = ls.dj_user_id
            WHERE ls.station_id = ? AND ls.`open` = 1 ORDER BY ls.id DESC LIMIT 1", [$this->station['id']])->fetch();
        if (!$row) {
            return null;
        }
        // the safety net closes a silent show on the next planner change; say so here too
        $timeout = (float) $this->planner->setting('live_timeout_seconds');
        if ($now - (float) $row['last_piece_at'] > $timeout + 2) {
            $this->planner->timeline($now); // the planner closes it as it plans on
            $open = (int) $this->db->run("SELECT `open` FROM $ls WHERE id = ?", [$row['id']])->fetchColumn();
            $row = $open === 1 ? $row : null;
        }
        return $row;
    }

    public function canControl(array $user, array $show): bool
    {
        return (int) ($show['dj_user_id'] ?? 0) === (int) $user['id'] || Auth::allows($user, 'onair');
    }

    // ---- Notes from the studio -------------------------------------------------------------

    public function postNote(array $user, string $publicId, string $kind, array $fields, float $now): array
    {
        $show = $this->show($publicId);
        if (!$show || !$this->canControl($user, $show)) {
            return ['error' => 'This isn’t your show.', 'status' => 403];
        }
        $r = (new Notes($this->db, $this->station))->post($publicId, (int) $user['id'], $kind, $fields, $now);
        return $r + ['status' => isset($r['error']) ? 422 : 200];
    }

    public function editNote(array $user, string $publicId, int $id, array $fields, float $now): array
    {
        $show = $this->show($publicId);
        if (!$show || !$this->canControl($user, $show)) {
            return ['error' => 'This isn’t your show.', 'status' => 403];
        }
        $r = (new Notes($this->db, $this->station))->edit($publicId, (int) $user['id'], $id, $fields, $now);
        return $r + ['status' => isset($r['error']) ? 422 : 200];
    }

    public function removeNote(array $user, string $publicId, int $id): array
    {
        $show = $this->show($publicId);
        if (!$show || !$this->canControl($user, $show)) {
            return ['error' => 'This isn’t your show.', 'status' => 403];
        }
        return (new Notes($this->db, $this->station))->remove($publicId, $id) ? ['removed' => $id] : ['error' => 'That note is already gone.', 'status' => 404];
    }

    public function notes(string $publicId): array
    {
        return (new Notes($this->db, $this->station))->forShow($publicId);
    }

    // ---- Running order ---------------------------------------------------------------------

    /** May this person change the running order with this scope? Their own block or their own list; managers any. */
    public function mayEditOrder(array $user, string $scope): bool
    {
        if (Auth::allows($user, 'onair')) {
            return preg_match('/^(block|user):\d+$/', $scope) === 1;
        }
        if ($scope === 'user:' . $user['id']) {
            return true;
        }
        if (preg_match('/^block:(\d+)$/', $scope, $m) !== 1) {
            return false;
        }
        foreach ($this->liveBlocks() as $b) {
            if ((int) $b['id'] === (int) $m[1]) {
                return $b['dj_user_id'] === null || (int) $b['dj_user_id'] === (int) $user['id'];
            }
        }
        return false;
    }

    /** The items, with each song's title, artist, length and address filled in from the library. */
    public function order(string $scope): array
    {
        $json = $this->db->run('SELECT items FROM ' . $this->db->table('running_orders') . ' WHERE station_id = ? AND scope = ?', [$this->station['id'], $scope])->fetchColumn();
        $items = json_decode((string) $json, true) ?: [];
        $tracks = $this->tracksById(array_column(array_filter($items, fn($i) => $i['kind'] !== 'talk'), 'track_id'));
        $out = [];
        foreach ($items as $i) {
            if ($i['kind'] === 'talk') {
                $out[] = ['kind' => 'talk', 'title' => $i['title'], 'seconds' => (int) $i['seconds']];
            } elseif (isset($tracks[(int) $i['track_id']])) {
                $out[] = ['kind' => $i['kind']] + $tracks[(int) $i['track_id']];
            } // a song removed from the library drops out of the list
        }
        return $out;
    }

    /** Replace the running order. $items: [{kind: song|id, track_id} | {kind: talk, title, seconds}] */
    public function saveOrder(array $user, string $scope, mixed $items): array
    {
        if (!$this->mayEditOrder($user, $scope)) {
            return ['error' => 'You can’t change this running order.', 'status' => 403];
        }
        if (!is_array($items) || !array_is_list($items)) {
            return ['error' => 'The running order must be a list.', 'status' => 400];
        }
        if (count($items) > self::ORDER_MAX) {
            return ['error' => 'A running order holds ' . self::ORDER_MAX . ' items at most.', 'status' => 422];
        }
        $clean = [];
        $wanted = [];
        foreach ($items as $n => $i) {
            $kind = is_array($i) ? (string) ($i['kind'] ?? '') : '';
            if (!in_array($kind, self::ORDER_KINDS, true)) {
                return ['error' => 'Item ' . ($n + 1) . ' has an unknown kind.', 'status' => 422];
            }
            if ($kind === 'talk') {
                $title = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($i['title'] ?? ''))), 0, 120);
                $seconds = (int) ($i['seconds'] ?? 0);
                if ($title === '') {
                    return ['error' => 'Give talk item ' . ($n + 1) . ' a name.', 'status' => 422];
                }
                if ($seconds < 1 || $seconds > 7200) {
                    return ['error' => 'Talk item ' . ($n + 1) . ' must last between 1 second and 2 hours.', 'status' => 422];
                }
                $clean[] = ['kind' => 'talk', 'title' => $title, 'seconds' => $seconds];
            } else {
                $id = (int) ($i['track_id'] ?? 0);
                $wanted[] = $id;
                $clean[] = ['kind' => $kind, 'track_id' => $id];
            }
        }
        $found = $this->tracksById($wanted);
        foreach ($wanted as $id) {
            if (!isset($found[$id])) {
                return ['error' => 'One of the songs isn’t in the library any more.', 'status' => 422];
            }
        }
        $this->db->run('INSERT INTO ' . $this->db->table('running_orders') . ' (station_id, scope, items, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE items = VALUES(items), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$this->station['id'], $scope, json_encode($clean), gmdate('Y-m-d H:i:s'), (int) $user['id']]);
        return ['items' => $this->order($scope), 'status' => 200];
    }

    /** Library songs for Deck B and the running order (40 at most). */
    public function songs(string $search): array
    {
        $rows = array_slice((new Library($this->db, $this->station, SW_ROOT))->tracks($search), 0, 40);
        return array_map(fn($r) => $this->track($r), $rows);
    }

    // ---- Inside ----------------------------------------------------------------------------

    /** A timeline entry as the studio needs it (song address relative to the app). */
    private function forStudio(?array $e): ?array
    {
        if ($e === null) {
            return null;
        }
        unset($e['row']);
        if ($e['type'] === 'song') {
            $e['url'] = 'storage/media/' . rawurlencode($e['file']);
        }
        return $e;
    }

    private function track(array $r): array
    {
        return ['track_id' => (int) $r['id'], 'title' => (string) $r['title'], 'artist' => (string) ($r['artist'] ?? ''),
                'seconds' => round((float) $r['duration_s'], 1), 'url' => 'storage/media/' . rawurlencode((string) $r['file'])];
    }

    /** @return array<int, array> ready tracks of this station by id */
    private function tracksById(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $rows = $this->db->run('SELECT id, file, title, artist, duration_s FROM ' . $this->db->table('tracks') .
            " WHERE station_id = ? AND status = 'ready' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', [$this->station['id'], ...$ids])->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $this->track($r);
        }
        return $out;
    }

    private function show(string $publicId): ?array
    {
        $row = $this->db->run('SELECT * FROM ' . $this->db->table('live_shows') . ' WHERE station_id = ? AND public_id = ?', [$this->station['id'], $publicId])->fetch();
        return $row ?: null;
    }

    private function liveBlocks(): array
    {
        $us = $this->db->table('users');
        return $this->db->run('SELECT s.*, u.name AS dj_name FROM ' . $this->db->table('schedule') . " s LEFT JOIN $us u ON u.id = s.dj_user_id
            WHERE s.station_id = ? AND s.kind = 'live' ORDER BY s.id", [$this->station['id']])->fetchAll();
    }

    /**
     * The times a block runs from yesterday to a week ahead, as unix seconds. A block ending before
     * it starts runs past midnight; start = end means the whole day.
     * @return list<array{id:int, title:string, dj:?string, dj_user_id:?int, start:float, end:float}>
     */
    public function occurrences(array $b, float $now): array
    {
        $tz = new DateTimeZone($this->station['timezone'] ?: 'UTC');
        $today = (new DateTimeImmutable('@' . (int) floor($now)))->setTimezone($tz)->setTime(0, 0);
        $out = [];
        for ($d = -1; $d <= 7; $d++) {
            $day = $today->modify(($d >= 0 ? '+' : '') . $d . ' day');
            if (!((int) $b['days'] & (1 << ((int) $day->format('N') - 1)))) {
                continue;
            }
            $date = $day->format('Y-m-d');
            if (($b['valid_from'] && $date < $b['valid_from']) || ($b['valid_to'] && $date > $b['valid_to'])) {
                continue;
            }
            [$sh, $sm] = array_map('intval', explode(':', $b['start_time']));
            [$eh, $em] = array_map('intval', explode(':', $b['end_time']));
            $start = $day->setTime($sh, $sm);
            $end = $day->setTime($eh, $em);
            if ($end <= $start) {
                $end = $end->modify('+1 day');
            }
            $out[] = ['id' => (int) $b['id'], 'title' => (string) $b['title'], 'dj' => $b['dj_name'] ?? null,
                      'dj_user_id' => $b['dj_user_id'] === null ? null : (int) $b['dj_user_id'],
                      'start' => (float) $start->getTimestamp(), 'end' => (float) $end->getTimestamp()];
        }
        return $out;
    }

    private function clock(float $t): string
    {
        return (new DateTimeImmutable('@' . (int) floor($t)))->setTimezone(new DateTimeZone($this->station['timezone'] ?: 'UTC'))->format('H:i');
    }

    /** "today at 09:00", "tomorrow at 09:00" or "on Sat 25 Oct at 09:00". */
    private function when(float $t, float $now): string
    {
        $tz = new DateTimeZone($this->station['timezone'] ?: 'UTC');
        $day = (new DateTimeImmutable('@' . (int) floor($t)))->setTimezone($tz);
        $today = (new DateTimeImmutable('@' . (int) floor($now)))->setTimezone($tz);
        $at = ' at ' . $day->format('H:i');
        if ($day->format('Y-m-d') === $today->format('Y-m-d')) {
            return 'today' . $at;
        }
        if ($day->format('Y-m-d') === $today->modify('+1 day')->format('Y-m-d')) {
            return 'tomorrow' . $at;
        }
        return 'on ' . $day->format('D j M') . $at;
    }

    /** The first bytes are an MPEG audio frame header (the studio sends whole frames, no tags). */
    public static function looksLikeMp3(string $bytes): bool
    {
        return strlen($bytes) >= 4 && ord($bytes[0]) === 0xFF && (ord($bytes[1]) & 0xE0) === 0xE0;
    }
}
