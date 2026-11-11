<?php
declare(strict_types=1);

namespace Stationwagon;

use RuntimeException;
use Throwable;

/**
 * The timeline planner: the whole "radio server". Nothing plays on the server; the timeline says
 * which song (or live block) covers which stretch of stream time, and every listener derives what
 * to play from it, so they all hear the same thing at the same moment. Ported from the proof of
 * concept (prototypes/shared/timeline.php) onto MySQL.
 *
 * Planning is lazy: any request that finds less than half the plan-ahead left plans more, under
 * a MySQL named lock so two requests never plan at once. cli/plan.php can do it from cron too.
 *
 * An entry (see entries()): n, type song|live, start, play, end, seq, piece_count, fade_out, cut;
 * songs add track_id, file, title, artist, full; live blocks add public_id, open, last_piece_at,
 * closed_reason, title. Times are unix seconds of stream time.
 *   play   seconds of the entry that belong to the stream (pieces end on this boundary)
 *   full   the song's whole length; the two-deck player lets the tail ring out under the fade
 *   seq    HLS media sequence number of the entry's first piece (continuous across entries)
 *   n      entry counter, used as the HLS discontinuity sequence
 */
final class Planner
{
    public const KEEP_HISTORY_SECONDS = 86400;
    public const WINDOW_SEGMENTS = 5;
    public const RESTART_AFTER_SECONDS = 60;

    private array $settings;
    private ?array $tracks = null;
    private array $pieceCache = [];
    private ?array $blocks = null;

    public function __construct(
        private readonly Db $db,
        private readonly array $station,
        private readonly string $root,
        private readonly int $lockSeconds = 10,
    ) {
        $this->settings = self::settings($station);
    }

    public static function forFirstStation(): self
    {
        $db = Db::fromConfig();
        $station = $db->run('SELECT * FROM ' . $db->table('stations') . ' ORDER BY id LIMIT 1')->fetch();
        if (!$station) {
            throw new RuntimeException('No station is set up yet.');
        }
        return new self($db, $station, SW_ROOT);
    }

    /** The station's settings over the defaults the POC proved. */
    public static function settings(array $station): array
    {
        $own = json_decode((string) ($station['settings'] ?? ''), true);
        return array_replace(Installer::defaultStationSettings(), is_array($own) ? $own : []);
    }

    public function setting(string $name): mixed
    {
        return $this->settings[$name];
    }

    public function db(): Db
    {
        return $this->db;
    }

    public function station(): array
    {
        return $this->station;
    }

    // ---- Reading -------------------------------------------------------------------------

    /** The timeline from 10 minutes ago onwards, planning ahead first if it needs it. */
    public function timeline(float $now): array
    {
        $entries = $this->entries($now - 600);
        if (!$this->needsPlan($entries, $now)) {
            return $entries;
        }
        return $this->change($now, fn() => null)['entries'];
    }

    /** Entries ending after $from (plus an open live block and always the last entry), by n. */
    public function entries(float $from): array
    {
        $tl = $this->db->table('timeline');
        $tr = $this->db->table('tracks');
        $ls = $this->db->table('live_shows');
        $rows = $this->db->run(
            "SELECT tl.*, tr.file, tr.kind AS track_kind, tr.title AS track_title, tr.artist, tr.album, tr.art, tr.rights, tr.duration_s,
                    ls.public_id, ls.`open`, ls.last_piece_at, ls.closed_reason, ls.title AS live_title
             FROM $tl tl LEFT JOIN $tr tr ON tr.id = tl.track_id LEFT JOIN $ls ls ON ls.id = tl.live_show_id
             WHERE tl.station_id = ? AND (tl.start_at + tl.play_s >= ? OR ls.`open` = 1
                   OR tl.n = (SELECT MAX(n) FROM $tl WHERE station_id = ?))
             ORDER BY tl.n",
            [$this->station['id'], $from, $this->station['id']]
        )->fetchAll();
        return array_map([self::class, 'entry'], $rows);
    }

    private static function entry(array $r): array
    {
        $e = [
            'row' => (int) $r['id'],
            'n' => (int) $r['n'],
            'type' => $r['type'],
            'start' => (float) $r['start_at'],
            'play' => (float) $r['play_s'],
            'seq' => (int) $r['seq'],
            'piece_count' => (int) $r['piece_count'],
            'fade_out' => (float) $r['fade_out_s'],
            'cut' => (bool) $r['cut'],
        ];
        $e['end'] = $e['start'] + $e['play'];
        if ($r['type'] === 'song' && ($r['track_kind'] ?? 'song') === 'ad') {
            // an ad: listeners and the apps see only "Advertisement" (no art, no copyright line); the station sees its title
            $e += ['track_id' => (int) $r['track_id'], 'file' => $r['file'], 'title' => Ads::PUBLIC_TITLE, 'ad' => true, 'ad_title' => $r['track_title'],
                   'artist' => null, 'album' => null, 'art' => null, 'rights' => null, 'full' => (float) $r['full_s']];
        } elseif ($r['type'] === 'song') {
            $e += ['track_id' => (int) $r['track_id'], 'file' => $r['file'], 'title' => $r['track_title'] ?? 'Removed song',
                   'artist' => $r['artist'], 'album' => $r['album'], 'art' => Library::artPath($r['art']),
                   'rights' => Rights::forListeners($r['rights']), 'full' => (float) $r['full_s']];
        } else {
            $e += ['live_show_id' => (int) $r['live_show_id'], 'public_id' => $r['public_id'], 'open' => (bool) $r['open'],
                   'last_piece_at' => (float) $r['last_piece_at'], 'closed_reason' => $r['closed_reason'], 'title' => (string) $r['live_title']];
        }
        return $e;
    }

    public function needsPlan(array $entries, float $now): bool
    {
        if (!$entries) {
            return $this->readyTrackCount() > 0;
        }
        $last = end($entries);
        if ($last['type'] === 'live' && $last['open']) {
            return $this->timedOut($last, $now);
        }
        return $last['end'] < $now + $this->settings['plan_ahead_seconds'] / 2;
    }

    private function timedOut(array $live, float $now): bool
    {
        return $now - $live['last_piece_at'] > $this->settings['live_timeout_seconds'];
    }

    /** The entry playing at $now, and the one after it. */
    public function nowPlaying(float $now): array
    {
        $current = $next = null;
        foreach ($this->timeline($now) as $e) {
            if ($current !== null) {
                $next = $e;
                break;
            }
            if ($e['start'] <= $now && ($now < $e['end'] || ($e['type'] === 'live' && $e['open']))) {
                $current = $e;
            }
        }
        return ['current' => $current, 'next' => $next];
    }

    /** Pieces of an entry: songs [offset, length, seconds] in their MP3; live [file, seconds]. */
    public function pieces(array $e): array
    {
        if ($e['type'] === 'song') {
            $id = $e['track_id'];
            if (!isset($this->pieceCache[$id])) {
                $json = $this->db->run('SELECT pieces FROM ' . $this->db->table('tracks') . ' WHERE id = ?', [$id])->fetchColumn();
                $this->pieceCache[$id] = json_decode((string) $json, true) ?: [];
            }
            return array_slice($this->pieceCache[$id], 0, $e['piece_count']);
        }
        $rows = $this->db->run('SELECT file, duration_s FROM ' . $this->db->table('live_pieces') . ' WHERE show_id = ? ORDER BY seq', [$e['live_show_id']])->fetchAll();
        return array_map(fn($r) => [$r['file'], (float) $r['duration_s']], $rows);
    }

    /**
     * The last few pieces that have begun, for the HLS playlist. uri is relative to the app root.
     * @return list<array{msn:int,dsn:int,start:float,dur:float,uri:string,range:?string,title:string}>
     */
    public function segments(float $now, int $count = self::WINDOW_SEGMENTS): array
    {
        $horizon = $now - $count * $this->settings['segment_seconds'] * 3;
        $out = [];
        foreach ($this->timeline($now) as $e) {
            if ($e['end'] < $horizon && !($e['type'] === 'live' && $e['open'])) {
                continue;
            }
            if ($e['start'] > $now) {
                break;
            }
            $t = $e['start'];
            foreach ($this->pieces($e) as $i => $p) {
                if ($t > $now) {
                    break;
                }
                if ($e['type'] === 'song') {
                    [$offset, $length, $dur] = $p;
                    $uri = 'storage/media/' . rawurlencode($e['file']);
                    $range = "$length@$offset";
                    $title = trim(($e['artist'] ? $e['artist'] . ' – ' : '') . $e['title']);
                } else {
                    [$name, $dur] = $p;
                    $uri = 'storage/live/' . rawurlencode($e['public_id']) . '/' . rawurlencode($name);
                    $range = null;
                    $title = 'LIVE · ' . $e['title'];
                }
                $out[] = ['msn' => $e['seq'] + $i, 'dsn' => $e['n'], 'start' => $t, 'dur' => (float) $dur, 'uri' => $uri, 'range' => $range, 'title' => $title];
                $t += $dur;
            }
        }
        return array_slice($out, -$count);
    }

    /** Entries overlapping [$from, $to] for the two-deck player; url is relative to the app root. */
    public function window(float $now, float $from, float $to): array
    {
        $out = [];
        foreach ($this->timeline($now) as $e) {
            $openLive = $e['type'] === 'live' && $e['open'] && $e['start'] <= $to;
            if (($e['end'] < $from || $e['start'] > $to) && !$openLive) {
                continue;
            }
            unset($e['row']);
            if ($e['type'] === 'song') {
                $e['url'] = 'storage/media/' . rawurlencode($e['file']);
            }
            $out[] = $e;
        }
        return $out;
    }

    // ---- Changing ------------------------------------------------------------------------

    /**
     * Skip the song. $heard is the stream time the person pressing Skip is hearing (an HLS listener
     * is about 20 s behind the live edge). If that song already ends before the cut could land,
     * nothing is cut: the next song, which they haven't heard yet, is left alone.
     */
    public function skip(float $now, ?float $heard = null): array
    {
        return $this->change($now, function (array $entries) use ($now, $heard) {
            if ($heard !== null && $heard < $now) {
                foreach ($entries as $e) {
                    if ($e['start'] <= $heard && $heard < $e['end']) {
                        if ($e['end'] <= $now + $this->settings['cut_lead_seconds']) {
                            return ['cut_at' => null, 'reason' => 'That song is already ending; the next one has started at the live edge.'];
                        }
                        break;
                    }
                }
            }
            $last = $entries ? end($entries) : null;
            if ($last && $last['type'] === 'live' && $last['open']) {
                return ['cut_at' => null, 'reason' => 'A live show is on air or about to start.'];
            }
            return ['cut_at' => $this->cut($entries, $now, (float) $this->settings['skip_fade_seconds'])];
        });
    }

    /** Start a live block at the next piece boundary; the studio sends audio from that time. */
    public function goLive(float $now, string $title, ?int $djUserId = null, ?int $continuesShowId = null): array
    {
        return $this->change($now, function (array $entries) use ($now, $title, $djUserId, $continuesShowId) {
            $last = $entries ? end($entries) : null;
            if ($last && $last['type'] === 'live' && $last['open']) {
                return ['error' => 'A live show is already on air.', 'live' => $last];
            }
            $cutAt = $this->cut($entries, $now, (float) $this->settings['live_fade_seconds'], true);
            if ($cutAt === null) {
                return ['error' => 'Nothing is playing to hand over from. Add songs to the library first.'];
            }
            $prev = $this->entries($now - 600);
            $prev = end($prev);
            $publicId = 'live-' . gmdate('Ymd-His', (int) $cutAt) . '-' . bin2hex(random_bytes(3));
            $showId = $this->db->insert('live_shows', [
                'station_id' => $this->station['id'], 'public_id' => $publicId, 'dj_user_id' => $djUserId,
                'title' => mb_substr(trim($title) ?: 'Live', 0, 190), 'start_at' => $cutAt, 'open' => 1,
                // the first piece can only arrive one piece length after the start
                'last_piece_at' => $cutAt + $this->settings['segment_seconds'] + 2,
                'continues_show_id' => $continuesShowId,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->insertEntry(['n' => $prev['n'] + 1, 'type' => 'live', 'live_show_id' => $showId, 'start_at' => $cutAt,
                                'seq' => $prev['seq'] + $prev['piece_count']]);
            $dir = $this->root . '/storage/live/' . $publicId;
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $entries = $this->entries($now - 600);
            return ['live' => end($entries), 'handover' => $prev];
        });
    }

    /** One ~6 s piece from the studio, in order: 409 for a gap, 410 once the show is closed. */
    public function addPiece(float $now, string $publicId, int $seq, float $duration, string $bytes): array
    {
        return $this->change($now, function (array $entries) use ($now, $publicId, $seq, $duration, $bytes) {
            $live = $entries ? end($entries) : null;
            if (!$live || $live['type'] !== 'live' || $live['public_id'] !== $publicId) {
                return ['error' => 'This live show is not on air.', 'status' => 410];
            }
            if (!$live['open']) {
                return ['error' => 'The server ended this show: ' . ($live['closed_reason'] ?? 'closed'), 'status' => 410];
            }
            if ($this->timedOut($live, $now)) {
                $this->closeLive($live, 'timeout');
                return ['error' => 'The server ended this show: timeout', 'status' => 410];
            }
            $expected = $live['piece_count'];
            if ($seq < $expected) {
                return ['ok' => true, 'duplicate' => true, 'expected' => $expected];
            }
            if ($seq > $expected) {
                return ['error' => 'Missing earlier piece', 'expected' => $expected, 'status' => 409];
            }
            $name = sprintf('%05d.mp3', $seq);
            $dir = $this->root . '/storage/live/' . $publicId;
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            if (file_put_contents("$dir/$name", $bytes) === false) {
                throw new RuntimeException('Could not save the live piece.');
            }
            $duration = round($duration, 4);
            $this->db->insert('live_pieces', ['show_id' => $live['live_show_id'], 'seq' => $seq, 'file' => $name, 'duration_s' => $duration, 'received_at' => $now]);
            $this->db->run('UPDATE ' . $this->db->table('timeline') . ' SET piece_count = piece_count + 1, play_s = play_s + ? WHERE id = ?', [$duration, $live['row']]);
            $this->db->run('UPDATE ' . $this->db->table('live_shows') . ' SET last_piece_at = ? WHERE id = ?', [$now, $live['live_show_id']]);
            return ['ok' => true, 'expected' => $expected + 1, 'stream_end' => $live['end'] + $duration];
        });
    }

    public function endLive(float $now, string $publicId, string $reason = 'ended by DJ'): array
    {
        return $this->change($now, function (array $entries) use ($publicId, $reason) {
            foreach ($entries as $e) {
                if ($e['type'] === 'live' && $e['public_id'] === $publicId && $e['open']) {
                    $this->closeLive($e, $reason);
                    return ['ended' => true, 'stream_end' => $e['end']];
                }
            }
            return ['ended' => false];
        });
    }

    /** Start the timeline over (a test or a fresh station). */
    public function reset(float $now): array
    {
        return $this->change($now, function () {
            $this->db->run('UPDATE ' . $this->db->table('live_shows') . " SET `open` = 0, closed_reason = 'reset' WHERE station_id = ? AND `open` = 1", [$this->station['id']]);
            $this->db->run('DELETE FROM ' . $this->db->table('timeline') . ' WHERE station_id = ?', [$this->station['id']]);
            return ['ok' => true];
        });
    }

    /**
     * Throw away what's planned but not started (after the cut lead) and plan it again, e.g. after
     * the schedule or a playlist changed. The song on air finishes normally.
     */
    public function replan(float $now): array
    {
        return $this->change($now, function (array $entries) use ($now) {
            $last = $entries ? end($entries) : null;
            if ($last && $last['type'] === 'live' && $last['open']) {
                return ['replanned' => 0];
            }
            $from = null;
            foreach ($entries as $e) {
                if ($e['start'] > $now + $this->settings['cut_lead_seconds']) {
                    $from = $e['n'];
                    break;
                }
            }
            if ($from === null) {
                return ['replanned' => 0];
            }
            $gone = $this->db->run('DELETE FROM ' . $this->db->table('timeline') . ' WHERE station_id = ? AND n >= ?', [$this->station['id'], $from])->rowCount();
            return ['replanned' => $gone];
        });
    }

    /** Lock, run $fn on the fresh timeline, plan ahead, commit. Returns $fn's result and the timeline. */
    private function change(float $now, callable $fn): array
    {
        return $this->locked(function () use ($now, $fn) {
            $pdo = $this->db->pdo;
            $pdo->beginTransaction();
            // the songs as they are now: one may have changed since they were read (Song prep's
            // Apply gives a song a new file, length and pieces just before it replans)
            $this->tracks = null;
            $this->blocks = null;
            $this->pieceCache = [];
            try {
                $result = $fn($this->entries($now - 600));
                $this->plan($now);
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $ex;
            }
            $this->tracks = null;
            $this->blocks = null;
            return ['result' => $result, 'entries' => $this->entries($now - 600)];
        });
    }

    /** Named locks are server-wide (shared hosting!), so the name includes the database. */
    public function lockName(): string
    {
        $db = (string) $this->db->pdo->query('SELECT DATABASE()')->fetchColumn();
        return 'sw.' . substr(sha1($db . '|' . $this->db->table('timeline') . '|' . $this->station['id']), 0, 32);
    }

    private function locked(callable $fn): mixed
    {
        $name = $this->lockName();
        $got = (int) $this->db->run('SELECT GET_LOCK(?, ?)', [$name, $this->lockSeconds])->fetchColumn();
        if ($got !== 1) {
            throw new RuntimeException('The timeline is busy (another request is planning). Try again.');
        }
        try {
            return $fn();
        } finally {
            $this->db->run('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    private function plan(float $now): void
    {
        $tl = $this->db->table('timeline');
        $maxN = $this->db->run("SELECT MAX(n) FROM $tl WHERE station_id = ?", [$this->station['id']])->fetchColumn();
        if ($maxN !== null) {
            $this->db->run("DELETE FROM $tl WHERE station_id = ? AND start_at + play_s < ? AND n < ?", [$this->station['id'], $now - self::KEEP_HISTORY_SECONDS, $maxN]);
        }

        $entries = $this->entries($now - 600);
        $last = $entries ? end($entries) : null;
        if ($last && $last['type'] === 'live' && $last['open']) {
            if (!$this->timedOut($last, $now)) {
                return; // a live show is running: AutoDJ waits
            }
            $this->closeLive($last, 'timeout');
        }

        $ads = new Ads($this->db, $this->station);
        $ads->logAired($now);
        $breaks = Ads::settings($this->station);
        $state = $breaks['enabled'] ? $ads->state() : null;
        $recent = $this->recentTrackIds();
        $until = $now + $this->settings['plan_ahead_seconds'];
        while ($last === null || $last['end'] < $until) {
            // an ad break: after every N songs (and the minutes since the last one), the ads that are due now
            if ($state !== null && $last !== null && $state['since'] >= $breaks['every']
                && ($state['last_end'] === null || $this->startAfter($last, $now) - $state['last_end'] >= $breaks['gap_minutes'] * 60)) {
                $in = [];
                for ($i = 0; $i < $breaks['per_break']; $i++) {
                    $id = $ads->pick($this->startAfter($last, $now), $in);
                    $track = $id !== null ? $ads->track($id) : null;
                    if ($track === null) {
                        break;
                    }
                    $last = $this->appendSong($last, $now, $recent, $track);
                    $in[] = $id;
                }
                if ($in) {
                    $state = ['since' => 0, 'last_end' => $last['end']];
                    continue;
                }
            }
            $next = $this->appendSong($last, $now, $recent);
            if ($next === null) {
                return; // empty library
            }
            $recent[] = $next['track_id'];
            if ($state !== null) {
                $state['since']++;
            }
            $last = $next;
        }
    }

    /** When the entry after $prev starts: straight after it (see appendSong), or now after a quiet spell. */
    private function startAfter(?array $prev, float $now): float
    {
        return $prev && $prev['end'] >= $now - self::RESTART_AFTER_SECONDS ? round($prev['end'], 3) : floor($now * 1000) / 1000;
    }

    /** @param ?array $forced the track to place (an ad); null picks a song */
    private function appendSong(?array $prev, float $now, array $recent, ?array $forced = null): ?array
    {
        // Carry on straight after the previous entry, even if it ended a few seconds ago (an ended
        // live show: listeners are ~20 s behind and must hear no gap). But after a quiet spell (no
        // listeners, no cron) start again at "now" rather than fill the gap with songs nobody
        // heard. Numbering carries on either way.
        $start = $this->startAfter($prev, $now);
        $track = $forced ?? $this->pickTrack($start, $recent);
        if ($track === null) {
            return null;
        }
        $allPieces = $this->pieces(['type' => 'song', 'track_id' => $track['id'], 'piece_count' => PHP_INT_MAX]);
        // Move on at the last piece boundary that still leaves crossfade_seconds of the song, so
        // the two-deck player always has a full-length fade (it keeps the tail playing under the
        // next song). HLS listeners jump straight on and miss the last 6–12 s instead.
        // an ad is short: it blends with the song before it and with whatever follows over the ads' crossfade
        $xf = !empty($track['ad']) ? (float) $this->settings['ads_crossfade_seconds'] : (float) $this->settings['crossfade_seconds'];
        $target = max(0.0, $track['duration'] - $xf);
        // Silence detection: move on where the song goes quiet for good, so a long fade-out or a
        // silent tail doesn't become dead air; the quiet part still plays out under the next song
        $quiet = self::quietFrom($track, (float) $this->settings['silence_db']);
        if ($quiet !== null) {
            $target = min($target, $quiet);
        }
        $count = 0;
        $play = 0.0;
        foreach ($allPieces as $p) {
            if ($count > 0 && $play + $p[2] > $target + 1e-6) {
                break;
            }
            $count++;
            $play += $p[2];
        }
        $row = [
            'n' => $prev ? $prev['n'] + 1 : 0, 'type' => 'song', 'track_id' => $track['id'], 'start_at' => $start,
            'play_s' => round($play, 4), 'full_s' => $track['duration'], 'fade_out_s' => $xf,
            'seq' => $prev ? $prev['seq'] + $prev['piece_count'] : 0, 'piece_count' => $count,
        ];
        $this->insertEntry($row);
        if ($prev && !empty($track['ad']) && $prev['type'] === 'song') {
            // the song before an ad fades out over the ads' crossfade too (its end is where the ad starts; only the fade is shorter)
            $this->db->run('UPDATE ' . $this->db->table('timeline') . ' SET fade_out_s = ? WHERE station_id = ? AND n = ?', [$xf, $this->station['id'], $prev['n']]);
        }
        return ['n' => $row['n'], 'type' => 'song', 'track_id' => $track['id'], 'start' => $start, 'play' => $row['play_s'],
                'end' => $start + $row['play_s'], 'seq' => $row['seq'], 'piece_count' => $count];
    }

    /**
     * Where a track goes quiet for good: the end of the last second of its outro (song prep's
     * last-minute loudness) at or above $db LUFS, in seconds from the start. Null when silence
     * detection is off ($db 0), the track has no outro, or the quiet part is too short to matter.
     */
    public static function quietFrom(array $track, float $db): ?float
    {
        $outro = $db < 0 && !empty($track['outro']) ? json_decode((string) $track['outro'], true) : null;
        if (!is_array($outro) || !$outro) {
            return null;
        }
        $n = count($outro);
        $last = -1;
        foreach ($outro as $i => $v) {
            if ((float) $v >= $db) {
                $last = $i;
            }
        }
        $at = $track['duration'] - $n + $last + 1;
        // only for a real quiet tail (over 2 s), and never cutting a song shorter than 30 s
        return $track['duration'] - $at > 2.0 && $at >= 30.0 ? round($at, 3) : null;
    }

    private function insertEntry(array $row): void
    {
        $this->db->insert('timeline', $row + ['station_id' => $this->station['id']]);
    }

    /**
     * Cut whatever plays at $now on the first piece boundary at least cut_lead_seconds ahead and
     * drop everything planned after it. Returns the cut time, or null during a live block.
     * With $fullLead (going live) a song that ends inside the lead plays out and the next one is
     * cut instead, so the studio always hears the start time before it comes.
     */
    private function cut(array $entries, float $now, float $fade, bool $fullLead = false): ?float
    {
        $lead = $this->settings['cut_lead_seconds'];
        $last = $entries ? end($entries) : null;
        if ($last && $last['type'] === 'live' && $last['open']) {
            return null; // a live show is on air or about to start: cutting would drop it from the timeline
        }
        $entries = array_values($entries);
        foreach ($entries as $i => $e) {
            if ($e['end'] <= $now || ($fullLead && $e['end'] < $now + $lead && isset($entries[$i + 1]) && $entries[$i + 1]['type'] !== 'live')) {
                continue;
            }
            if ($e['type'] === 'live') {
                return null;
            }
            $t = $e['start'];
            $keep = 0;
            foreach ($this->pieces($e) as $p) {
                if ($t >= $now + $lead && $keep > 0) {
                    break;
                }
                $keep++;
                $t += $p[2];
            }
            $tl = $this->db->table('timeline');
            $this->db->run("UPDATE $tl SET piece_count = ?, play_s = ?, fade_out_s = ?, cut = 1 WHERE id = ?", [$keep, round($t - $e['start'], 4), $fade, $e['row']]);
            $this->db->run("DELETE FROM $tl WHERE station_id = ? AND n > ?", [$this->station['id'], $e['n']]);
            return round($e['start'] + round($t - $e['start'], 4), 3);
        }
        return null;
    }

    private function closeLive(array $live, string $reason): void
    {
        $this->db->run('UPDATE ' . $this->db->table('live_shows') . ' SET `open` = 0, closed_reason = ? WHERE id = ?', [$reason, $live['live_show_id']]);
    }

    // ---- Choosing songs ------------------------------------------------------------------

    /** Songs that played most recently, oldest first (what's planned counts as played). */
    private function recentTrackIds(): array
    {
        $ids = $this->db->run('SELECT tl.track_id FROM ' . $this->db->table('timeline') . ' tl JOIN ' . $this->db->table('tracks') . " t ON t.id = tl.track_id AND t.kind = 'song' WHERE tl.station_id = ? AND tl.type = 'song' ORDER BY tl.n DESC LIMIT 50", [$this->station['id']])->fetchAll(\PDO::FETCH_COLUMN);
        return array_reverse(array_map('intval', $ids));
    }

    private function readyTrackCount(): int
    {
        return count($this->tracks());
    }

    /** @return array<int,array{id:int,duration:float}> ready tracks by id */
    private function tracks(): array
    {
        if ($this->tracks === null) {
            $rows = $this->db->run('SELECT id, duration_s, outro FROM ' . $this->db->table('tracks') . " WHERE station_id = ? AND status = 'ready' AND kind = 'song'", [$this->station['id']])->fetchAll();
            $this->tracks = [];
            foreach ($rows as $r) {
                $this->tracks[(int) $r['id']] = ['id' => (int) $r['id'], 'duration' => (float) $r['duration_s'], 'outro' => $r['outro']];
            }
        }
        return $this->tracks;
    }

    /**
     * The song for an entry starting at $at: from the scheduled playlist if one covers that time
     * (shuffled or in order), otherwise any song in the library, never one of the last few.
     */
    private function pickTrack(float $at, array $recent): ?array
    {
        $all = $this->tracks();
        if (!$all) {
            return null;
        }
        $mode = 'shuffle';
        $pool = array_keys($all);
        $block = $this->blockAt($at);
        if ($block !== null) {
            $listed = $this->playlistTracks((int) $block['playlist_id']);
            $listed['ids'] = array_values(array_filter($listed['ids'], fn($id) => isset($all[$id])));
            if ($listed['ids']) {
                $pool = $listed['ids'];
                $mode = $listed['mode'];
            }
        }
        if ($mode === 'in_order') {
            $pos = false;
            foreach (array_reverse($recent) as $id) {
                if (($pos = array_search($id, $pool, true)) !== false) {
                    break;
                }
            }
            return $all[$pool[$pos === false ? 0 : ($pos + 1) % count($pool)]];
        }
        $keep = min((int) $this->settings['recent_tracks'], max(0, count($pool) - 1));
        $avoid = $keep > 0 ? array_slice($recent, -$keep) : [];
        $choices = array_values(array_diff($pool, $avoid));
        if (!$choices) {
            $choices = array_values(array_diff($pool, array_slice($recent, -1))) ?: $pool;
        }
        return $all[$choices[random_int(0, count($choices) - 1)]];
    }

    /** @return array{mode:string,ids:list<int>} */
    private function playlistTracks(int $playlistId): array
    {
        $pl = $this->db->table('playlists');
        $pt = $this->db->table('playlist_tracks');
        $mode = $this->db->run("SELECT mode FROM $pl WHERE id = ? AND station_id = ? AND active = 1", [$playlistId, $this->station['id']])->fetchColumn();
        if ($mode === false) {
            return ['mode' => 'shuffle', 'ids' => []];
        }
        $ids = $this->db->run("SELECT track_id FROM $pt WHERE playlist_id = ? ORDER BY position, track_id", [$playlistId])->fetchAll(\PDO::FETCH_COLUMN);
        return ['mode' => (string) $mode, 'ids' => array_map('intval', $ids)];
    }

    /**
     * The playlist block of the weekly schedule covering $at in the station's own clock. days is a
     * bitmask (Monday = 1 … Sunday = 64). A block whose end is before its start runs past midnight;
     * equal start and end means all day. The newest block wins where blocks overlap.
     */
    public function blockAt(float $at): ?array
    {
        if ($this->blocks === null) {
            $this->blocks = $this->db->run('SELECT * FROM ' . $this->db->table('schedule') . " WHERE station_id = ? AND kind = 'playlist' AND playlist_id IS NOT NULL ORDER BY id DESC", [$this->station['id']])->fetchAll();
        }
        if (!$this->blocks) {
            return null;
        }
        $local = (new \DateTimeImmutable('@' . (int) floor($at)))->setTimezone(new \DateTimeZone($this->station['timezone']));
        $day = 1 << ((int) $local->format('N') - 1);
        $prevDay = 1 << ((int) $local->modify('-1 day')->format('N') - 1);
        $time = $local->format('H:i:s');
        $date = $local->format('Y-m-d');
        foreach ($this->blocks as $b) {
            if (($b['valid_from'] && $date < $b['valid_from']) || ($b['valid_to'] && $date > $b['valid_to'])) {
                continue;
            }
            $days = (int) $b['days'];
            $s = $b['start_time'];
            $e = $b['end_time'];
            $hit = $s === $e ? ($days & $day) !== 0
                : ($s < $e ? ($days & $day) && $time >= $s && $time < $e
                           : (($days & $day) && $time >= $s) || (($days & $prevDay) && $time < $e));
            if ($hit) {
                return $b;
            }
        }
        return null;
    }
}
