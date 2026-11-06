<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * Podcasts: every live show becomes a draft episode (a show and the shows that rejoined it are one
 * episode). Each show of it has a take: the studio's own recording when its device uploaded one
 * (two tracks: the music as it went out, in stereo, and the mic, in mono, 10 s a chunk),
 * otherwise the pieces listeners heard ("as aired", one track). In the editor (any browser) clips
 * are deleted and the rest mixed down to one MP3, which the browser makes and uploads; someone
 * then publishes it with a title and description. The podcast page and its RSS feed list the
 * published episodes. A published episode's raw tracks go after RAW_KEEP_DAYS.
 *
 * Files under storage/podcasts/<episode public id>/: raw/<take>-<track>-<n>.chunk (the device's
 * chunks), mix-<n>.chunk (a mixdown on its way up), episode.chunk (a draft's mixdown) and, once
 * published, <slug>.mp3 (public). `.chunk` files are never served (storage/.htaccess).
 */
final class Podcast
{
    public const RAW_KEEP_DAYS = 30;
    public const MIN_SECONDS = 120;          // shorter shows are skipped (station setting podcast_min_seconds)
    public const TRACKS = ['music', 'mic'];
    public const SAMPLE_RATE = 44100;        // the studio records at this rate
    public const SPLIT_SECONDS = 2;          // a silence this long ends a clip (podcast-core.js: SPLIT_SECONDS)
    public const GAP_KEEP = 1;               // an empty stretch over SPLIT_SECONDS is shortened to this (GAP_KEEP there too)
    public const CHUNK_MAX_BYTES = 6_000_000;
    public const MAX_CHUNKS = 6000;          // per track: over 16 hours of 10 s chunks
    public const TAIL_MIN_SECONDS = 1.0;     // a device recording this much shorter than the show gets the rest as aired
    public const CATEGORIES = [
        'Religion & Spirituality|Christianity' => 'Religion & Spirituality › Christianity',
        'Religion & Spirituality' => 'Religion & Spirituality',
        'Music' => 'Music', 'Society & Culture' => 'Society & Culture', 'Education' => 'Education',
        'News' => 'News', 'Kids & Family' => 'Kids & Family', 'Health & Fitness' => 'Health & Fitness',
    ];

    public function __construct(private readonly Db $db, private readonly array $station, private readonly string $root)
    {
    }

    public static function forFirstStation(): self
    {
        $planner = Planner::forFirstStation();
        return new self($planner->db(), $planner->station(), SW_ROOT);
    }

    public function station(): array
    {
        return $this->station;
    }

    // ---- Episodes from live shows ---------------------------------------------------------------

    /**
     * Give every closed live show an episode (with an "as aired" take), and set each unpublished
     * episode to draft or skipped by its length. Shows from before podcasts existed are left out
     * (settings.podcasts_from). Returns how many takes were added.
     */
    public function sync(float $now): int
    {
        $from = $this->db->run('SELECT value FROM ' . $this->db->table('settings') . " WHERE name = 'podcasts_from'")->fetchColumn();
        if ($from === false) {
            $this->db->run('INSERT IGNORE INTO ' . $this->db->table('settings') . " (name, value, updated_at) VALUES ('podcasts_from', ?, ?)",
                [gmdate('Y-m-d H:i:s', (int) $now - 3600), gmdate('Y-m-d H:i:s')]);
            $from = gmdate('Y-m-d H:i:s', (int) $now - 3600);
        }
        $ls = $this->db->table('live_shows');
        $shows = $this->db->run("SELECT * FROM $ls s WHERE s.station_id = ? AND s.`open` = 0 AND s.created_at >= ?
            AND NOT EXISTS (SELECT 1 FROM " . $this->db->table('episode_takes') . " t WHERE t.live_show_id = s.id AND t.kind = 'aired') ORDER BY s.id",
            [$this->station['id'], $from])->fetchAll();
        $added = 0;
        foreach ($shows as $show) {
            $seconds = (float) $this->db->run('SELECT COALESCE(SUM(duration_s), 0) FROM ' . $this->db->table('live_pieces') . ' WHERE show_id = ?', [$show['id']])->fetchColumn();
            $ep = $this->ensureEpisode($this->rootShow((int) $show['id']));
            $this->db->run('INSERT IGNORE INTO ' . $this->db->table('episode_takes') . " (episode_id, live_show_id, kind, start_at, duration_s, complete, created_at) VALUES (?, ?, 'aired', ?, ?, 1, ?)",
                [$ep['id'], $show['id'], $show['start_at'], round($seconds, 3), gmdate('Y-m-d H:i:s')]);
            $added++;
            $this->settle((int) $ep['id']);
        }
        return $added;
    }

    /** The first show of a chain of rejoins. */
    public function rootShow(int $showId): array
    {
        $ls = $this->db->table('live_shows');
        $show = $this->db->run("SELECT * FROM $ls WHERE id = ?", [$showId])->fetch();
        for ($i = 0; $show && $show['continues_show_id'] && $i < 50; $i++) {
            $prev = $this->db->run("SELECT * FROM $ls WHERE id = ?", [$show['continues_show_id']])->fetch();
            if (!$prev) {
                break;
            }
            $show = $prev;
        }
        return $show;
    }

    private function ensureEpisode(array $root): array
    {
        $t = $this->db->table('episodes');
        $ep = $this->db->run("SELECT * FROM $t WHERE live_show_id = ?", [$root['id']])->fetch();
        if ($ep) {
            return $ep;
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->db->run("INSERT IGNORE INTO $t (station_id, public_id, live_show_id, dj_user_id, title, status, recorded_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'skipped', ?, ?, ?)",
            [$this->station['id'], 'ep-' . gmdate('Ymd', (int) $root['start_at']) . '-' . bin2hex(random_bytes(4)), $root['id'], $root['dj_user_id'], mb_substr((string) $root['title'], 0, 190), $root['start_at'], $now, $now]);
        return $this->db->run("SELECT * FROM $t WHERE live_show_id = ?", [$root['id']])->fetch();
    }

    /** An unpublished episode is a draft when its takes add up to the minimum length, else skipped. */
    private function settle(int $episodeId): void
    {
        $seconds = array_sum(array_column($this->takes($episodeId), 'duration_s'));
        $min = (float) ($this->setting('podcast_min_seconds') ?? self::MIN_SECONDS);
        $this->db->run('UPDATE ' . $this->db->table('episodes') . " SET status = ?, updated_at = ? WHERE id = ? AND status IN ('draft', 'skipped')",
            [$seconds >= $min ? 'draft' : 'skipped', gmdate('Y-m-d H:i:s'), $episodeId]);
    }

    /**
     * The takes the episode uses for each of its shows, in show order: the device's when complete,
     * else as aired. When the device's recording stops early (the studio page reloaded or crashed:
     * up to its last unsaved 10 s are lost), the rest comes from the take as aired: that take
     * follows with `skip_s` (the seconds of it already covered) and only the rest as its length.
     * @return list<array>
     */
    public function takes(int $episodeId): array
    {
        $rows = $this->db->run('SELECT * FROM ' . $this->db->table('episode_takes') . ' WHERE episode_id = ? ORDER BY start_at', [$episodeId])->fetchAll();
        $device = $aired = $start = [];
        foreach ($rows as $r) {
            $show = (int) $r['live_show_id'];
            $r['duration_s'] = (float) $r['duration_s'];
            $r['skip_s'] = 0.0;
            $start[$show] = (float) $r['start_at'];
            if ($r['kind'] === 'device' && (int) $r['complete'] === 1) {
                $device[$show] = $r;
            } elseif ($r['kind'] === 'aired') {
                $aired[$show] = $r;
            }
        }
        asort($start);
        $out = [];
        foreach (array_keys($start) as $show) {
            $d = $device[$show] ?? null;
            $a = $aired[$show] ?? null;
            if ($d === null) {
                if ($a !== null) {
                    $out[] = $a;
                }
                continue;
            }
            $out[] = $d;
            if ($a !== null && $a['duration_s'] - $d['duration_s'] > self::TAIL_MIN_SECONDS) {
                $a['skip_s'] = $d['duration_s'];
                $a['duration_s'] -= $d['duration_s'];
                $out[] = $a;
            }
        }
        return $out;
    }

    // ---- The studio's own recording -------------------------------------------------------------

    /** One chunk of a device take (POST podcast/api/rec-chunk). */
    public function addChunk(array $user, string $showPublicId, string $track, int $n, int $samples, string $bytes): array
    {
        $show = $this->show($showPublicId);
        if ($show === null) {
            return ['error' => 'No such show.', 'status' => 404];
        }
        if (!$this->mayRecord($user, $show)) {
            return ['error' => 'Only the show’s presenter or a manager can upload its recording.', 'status' => 403];
        }
        if (!in_array($track, self::TRACKS, true) || $n < 0 || $n >= self::MAX_CHUNKS || $samples <= 0 || $samples > self::SAMPLE_RATE * 600) {
            return ['error' => 'Not a recording chunk.', 'status' => 400];
        }
        if ($bytes === '' || strlen($bytes) > self::CHUNK_MAX_BYTES || !self::looksLikeMp3($bytes)) {
            return ['error' => 'The chunk isn’t MP3 audio.', 'status' => 400];
        }
        $take = $this->deviceTake($show);
        if ((int) $take['complete'] === 1) {
            return ['error' => 'This recording is already uploaded.', 'status' => 409];
        }
        $ep = $this->episodeById((int) $take['episode_id']);
        $dir = $this->episodeDir($ep) . '/raw';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Can't make $dir.");
        }
        $file = sprintf('raw/%d-%s-%04d.chunk', $take['id'], $track, $n);
        file_put_contents($this->episodeDir($ep) . '/' . $file, $bytes);
        $this->db->run('INSERT INTO ' . $this->db->table('episode_chunks') . ' (take_id, track, n, samples, bytes, file) VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE samples = VALUES(samples), bytes = VALUES(bytes), file = VALUES(file)',
            [$take['id'], $track, $n, $samples, strlen($bytes), $file]);
        return ['ok' => true, 'episode' => $ep['public_id']];
    }

    /**
     * The device has sent every chunk (POST podcast/api/rec-done): check the counts, keep the
     * analysis (peaks and clips per track), and the episode uses this take from now on.
     */
    public function finishDevice(array $user, string $showPublicId, array $counts, mixed $analysis): array
    {
        $show = $this->show($showPublicId);
        if ($show === null) {
            return ['error' => 'No such show.', 'status' => 404];
        }
        if (!$this->mayRecord($user, $show)) {
            return ['error' => 'Only the show’s presenter or a manager can upload its recording.', 'status' => 403];
        }
        $take = $this->deviceTake($show);
        $samples = 0;
        foreach (self::TRACKS as $track) {
            $rows = $this->db->run('SELECT n, samples FROM ' . $this->db->table('episode_chunks') . ' WHERE take_id = ? AND track = ? ORDER BY n', [$take['id'], $track])->fetchAll();
            $want = (int) ($counts[$track] ?? -1);
            if (count($rows) !== $want || ($want > 0 && (int) end($rows)['n'] !== $want - 1)) {
                return ['error' => "The $track track is missing chunks (" . count($rows) . " of $want).", 'status' => 409, 'have' => array_map('intval', array_column($rows, 'n'))];
            }
            if ($track === 'music') {
                $samples = array_sum(array_map('intval', array_column($rows, 'samples')));
            }
        }
        $clean = self::cleanAnalysis($analysis, self::TRACKS);
        if ($clean === null) {
            return ['error' => 'The recording’s clip list isn’t readable.', 'status' => 400];
        }
        $this->db->run('UPDATE ' . $this->db->table('episode_takes') . ' SET complete = 1, duration_s = ?, analysis = ? WHERE id = ?',
            [round($samples / self::SAMPLE_RATE, 3), json_encode($clean), $take['id']]);
        $this->settle((int) $take['episode_id']);
        $ep = $this->episodeById((int) $take['episode_id']);
        return ['ok' => true, 'episode' => $ep['public_id'], 'id' => (int) $ep['id'], 'state' => $ep['status'], 'status' => 200];
    }

    private function show(string $publicId): ?array
    {
        return $this->db->run('SELECT * FROM ' . $this->db->table('live_shows') . ' WHERE public_id = ? AND station_id = ?', [$publicId, $this->station['id']])->fetch() ?: null;
    }

    private function mayRecord(array $user, array $show): bool
    {
        return (int) ($show['dj_user_id'] ?? 0) === (int) $user['id'] || Auth::allows($user, 'podcast');
    }

    private function deviceTake(array $show): array
    {
        $ep = $this->ensureEpisode($this->rootShow((int) $show['id']));
        $t = $this->db->table('episode_takes');
        $this->db->run("INSERT IGNORE INTO $t (episode_id, live_show_id, kind, start_at, duration_s, complete, created_at) VALUES (?, ?, 'device', ?, 0, 0, ?)",
            [$ep['id'], $show['id'], $show['start_at'], gmdate('Y-m-d H:i:s')]);
        return $this->db->run("SELECT * FROM $t WHERE episode_id = ? AND live_show_id = ? AND kind = 'device'", [$ep['id'], $show['id']])->fetch();
    }

    /** A frame sync at the start (MP3 chunks are cut on whole frames). */
    public static function looksLikeMp3(string $bytes): bool
    {
        return strlen($bytes) >= 4 && ord($bytes[0]) === 0xFF && (ord($bytes[1]) & 0xE0) === 0xE0;
    }

    /**
     * The browser's analysis of a take, checked: {rate, tracks: {name: {peaks: base64, clips: [[s, e]…]}}}.
     * Peaks are one byte per 1/rate s; clips are seconds from the take's start, in order.
     */
    public static function cleanAnalysis(mixed $a, array $tracks): ?array
    {
        if (!is_array($a) || !is_array($a['tracks'] ?? null) || (int) ($a['rate'] ?? 0) < 1 || (int) $a['rate'] > 100) {
            return null;
        }
        $out = ['rate' => (int) $a['rate'], 'tracks' => []];
        foreach ($tracks as $track) {
            $t = $a['tracks'][$track] ?? null;
            if (!is_array($t) || !is_string($t['peaks'] ?? null) || strlen($t['peaks']) > 2_000_000 || base64_decode($t['peaks'], true) === false || !is_array($t['clips'] ?? null) || count($t['clips']) > 20000) {
                return null;
            }
            $clips = [];
            $last = -1.0;
            foreach ($t['clips'] as $c) {
                if (!is_array($c) || count($c) !== 2 || !is_numeric($c[0]) || !is_numeric($c[1]) || (float) $c[0] < $last || (float) $c[1] <= (float) $c[0]) {
                    return null;
                }
                $clips[] = [round((float) $c[0], 3), round((float) $c[1], 3)];
                $last = (float) $c[1];
            }
            $out['tracks'][$track] = ['peaks' => $t['peaks'], 'clips' => $clips];
        }
        return $out;
    }

    // ---- The editor -----------------------------------------------------------------------------

    public function episodeById(int $id): ?array
    {
        return $this->db->run('SELECT * FROM ' . $this->db->table('episodes') . ' WHERE id = ? AND station_id = ?', [$id, $this->station['id']])->fetch() ?: null;
    }

    public function mayEdit(array $user, array $ep): bool
    {
        return Auth::allows($user, 'podcast') || ((int) ($ep['dj_user_id'] ?? 0) === (int) $user['id'] && Auth::allows($user, 'dj'));
    }

    /** Episodes a person may see in the list: all for managers, their own for DJs. Skipped ones aren't listed. */
    public function list(array $user): array
    {
        $t = $this->db->table('episodes');
        $mine = !Auth::allows($user, 'podcast');
        $rows = $this->db->run("SELECT * FROM $t WHERE station_id = ? AND status <> 'skipped'" . ($mine ? ' AND dj_user_id = ?' : '') . ' ORDER BY recorded_at DESC LIMIT 500',
            $mine ? [$this->station['id'], $user['id']] : [$this->station['id']])->fetchAll();
        foreach ($rows as &$r) {
            $takes = $this->takes((int) $r['id']);
            $r['raw_seconds'] = array_sum(array_column($takes, 'duration_s'));
            $r['source'] = in_array('device', array_column($takes, 'kind'), true) ? 'device' : 'aired';
        }
        return $rows;
    }

    /** What the editor needs: the episode, its takes with their chunks and analysis, and the edits. */
    public function view(array $ep): array
    {
        $takes = [];
        $offset = 0.0;
        foreach ($this->takes((int) $ep['id']) as $t) {
            $tracks = [];
            if ($t['kind'] === 'device') {
                foreach (self::TRACKS as $track) {
                    $tracks[$track] = array_map(fn($r) => ['n' => (int) $r['n'], 'samples' => (int) $r['samples']],
                        $this->db->run('SELECT n, samples FROM ' . $this->db->table('episode_chunks') . ' WHERE take_id = ? AND track = ? ORDER BY n', [$t['id'], $track])->fetchAll());
                }
            } else {
                // a piece is whole MP3 frames of 1152 samples; its stored length is rounded to 0.1 ms
                $tracks['aired'] = array_map(fn($r) => ['n' => (int) $r['seq'], 'samples' => 1152 * (int) round((float) $r['duration_s'] * self::SAMPLE_RATE / 1152)],
                    $this->db->run('SELECT seq, duration_s FROM ' . $this->db->table('live_pieces') . ' WHERE show_id = ? ORDER BY seq', [$t['live_show_id']])->fetchAll());
            }
            $takes[] = ['id' => (int) $t['id'], 'kind' => $t['kind'], 'start' => (float) $t['start_at'], 'offset' => round($offset, 3), 'duration' => (float) $t['duration_s'], 'skip' => round($t['skip_s'], 3),
                'tracks' => $tracks, 'analysis' => $t['analysis'] ? json_decode($t['analysis'], true) : null,
                'raw' => $t['kind'] === 'aired' || $ep['raw_deleted_at'] === null];
            $offset += $t['duration_s'];
        }
        $edits = json_decode((string) ($ep['edits'] ?? ''), true);
        return [
            'id' => (int) $ep['id'], 'public_id' => $ep['public_id'], 'title' => $ep['title'], 'description' => (string) $ep['description'],
            'state' => $ep['status'], 'recorded_at' => (float) $ep['recorded_at'], 'deleted' => is_array($edits['deleted'] ?? null) ? $edits['deleted'] : [],
            'mixed_deleted' => is_array($edits['mixed'] ?? null) ? $edits['mixed'] : null,
            'audio' => $ep['audio_file'] ? ['seconds' => (float) $ep['audio_seconds'], 'bytes' => (int) $ep['audio_bytes'], 'mixed_at' => $ep['mixed_at'],
                'url' => $ep['status'] === 'published' || $ep['status'] === 'hidden' ? $this->publicUrl($ep) : null] : null,
            'raw_deleted' => $ep['raw_deleted_at'] !== null,
            'takes' => $takes, 'sample_rate' => self::SAMPLE_RATE,
        ];
    }

    /** The file of one chunk the editor reads: a device chunk, or a piece as aired. */
    public function chunkPath(array $ep, int $takeId, string $track, int $n): ?string
    {
        $take = $this->db->run('SELECT * FROM ' . $this->db->table('episode_takes') . ' WHERE id = ? AND episode_id = ?', [$takeId, $ep['id']])->fetch();
        if (!$take) {
            return null;
        }
        if ($take['kind'] === 'aired' && $track === 'aired') {
            $r = $this->db->run('SELECT p.file, s.public_id FROM ' . $this->db->table('live_pieces') . ' p JOIN ' . $this->db->table('live_shows') . ' s ON s.id = p.show_id WHERE p.show_id = ? AND p.seq = ?',
                [$take['live_show_id'], $n])->fetch();
            $path = $r ? $this->root . '/storage/live/' . $r['public_id'] . '/' . basename($r['file']) : null;
        } else {
            $file = $this->db->run('SELECT file FROM ' . $this->db->table('episode_chunks') . ' WHERE take_id = ? AND track = ? AND n = ?', [$takeId, $track, $n])->fetchColumn();
            $path = $file ? $this->episodeDir($ep) . '/' . $file : null;
        }
        return $path !== null && is_file($path) ? $path : null;
    }

    /** The editor's analysis of an as-aired take (made in the browser the first time it opens). */
    public function saveAnalysis(array $ep, int $takeId, mixed $analysis): array
    {
        $take = $this->db->run('SELECT * FROM ' . $this->db->table('episode_takes') . " WHERE id = ? AND episode_id = ? AND kind = 'aired'", [$takeId, $ep['id']])->fetch();
        $clean = self::cleanAnalysis($analysis, ['aired']);
        if (!$take || $clean === null) {
            return ['error' => 'Not an analysis of this episode.', 'status' => 400];
        }
        $this->db->run('UPDATE ' . $this->db->table('episode_takes') . ' SET analysis = ? WHERE id = ?', [json_encode($clean), $takeId]);
        return ['ok' => true];
    }

    /** The clips deleted in the editor ("<take>:<track>:<index>"). */
    public function saveEdits(array $ep, mixed $deleted): array
    {
        $clean = self::cleanDeleted($deleted);
        if ($clean === null) {
            return ['error' => 'Not a list of clips.', 'status' => 400];
        }
        $edits = json_decode((string) ($ep['edits'] ?? ''), true);
        $edits = (is_array($edits) ? $edits : []);
        $edits['deleted'] = $clean;
        $this->db->run('UPDATE ' . $this->db->table('episodes') . ' SET edits = ?, updated_at = ? WHERE id = ?', [json_encode($edits), gmdate('Y-m-d H:i:s'), $ep['id']]);
        return ['ok' => true, 'deleted' => count($clean)];
    }

    /** @return ?list<string> clip ids ("<take>:<track>:<index>"), sorted, or null if it isn't a list */
    private static function cleanDeleted(mixed $deleted): ?array
    {
        if (!is_array($deleted) || count($deleted) > 20000) {
            return null;
        }
        $clean = array_values(array_unique(array_filter($deleted, fn($d) => is_string($d) && preg_match('/^\d+:(music|mic|aired):\d+$/', $d) === 1)));
        sort($clean);
        return $clean;
    }

    /** One part of a mixdown on its way up (POST podcast/api/mix-chunk). */
    public function addMixPart(array $ep, int $n, string $bytes): array
    {
        if ($n < 0 || $n >= 10000 || $bytes === '' || strlen($bytes) > self::CHUNK_MAX_BYTES || !self::looksLikeMp3($bytes)) {
            return ['error' => 'Not a part of an MP3.', 'status' => 400];
        }
        $dir = $this->episodeDir($ep);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Can't make $dir.");
        }
        file_put_contents(sprintf('%s/mix-%04d.chunk', $dir, $n), $bytes);
        return ['ok' => true];
    }

    /**
     * Join the parts into the episode's audio (POST podcast/api/mix-done). A draft keeps it private;
     * a published episode gets a new public file at once (a new name, so apps fetch it again).
     * $deleted: the cuts this mixdown was made with (so the editor can tell when it's out of date).
     */
    public function finishMix(array $ep, int $parts, float $seconds, mixed $deleted = []): array
    {
        $dir = $this->episodeDir($ep);
        if ($parts < 1 || $parts > 10000 || $seconds <= 0) {
            return ['error' => 'Not a mixdown.', 'status' => 400];
        }
        for ($n = 0; $n < $parts; $n++) {
            if (!is_file(sprintf('%s/mix-%04d.chunk', $dir, $n))) {
                return ['error' => "Part $n of the mixdown is missing.", 'status' => 409];
            }
        }
        $published = in_array($ep['status'], ['published', 'hidden'], true);
        $file = $published ? $this->slug($ep['title']) . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.mp3' : 'episode.chunk';
        $out = fopen("$dir/$file.tmp", 'wb');
        for ($n = 0; $n < $parts; $n++) {
            $part = sprintf('%s/mix-%04d.chunk', $dir, $n);
            fwrite($out, (string) file_get_contents($part));
            @unlink($part);
        }
        fclose($out);
        rename("$dir/$file.tmp", "$dir/$file");
        if ($ep['audio_file'] && $ep['audio_file'] !== $file) {
            @unlink("$dir/" . $ep['audio_file']);
        }
        $edits = json_decode((string) ($ep['edits'] ?? ''), true);
        $edits = is_array($edits) ? $edits : ['deleted' => []];
        $edits['mixed'] = self::cleanDeleted($deleted) ?? [];
        $this->db->run('UPDATE ' . $this->db->table('episodes') . ' SET audio_file = ?, audio_bytes = ?, audio_seconds = ?, mixed_at = ?, edits = ?, updated_at = ? WHERE id = ?',
            [$file, filesize("$dir/$file"), round($seconds, 3), gmdate('Y-m-d H:i:s'), json_encode($edits), gmdate('Y-m-d H:i:s'), $ep['id']]);
        return ['ok' => true, 'seconds' => round($seconds, 3), 'bytes' => filesize("$dir/$file")];
    }

    /** The private mixdown of a draft, for the editor's player. */
    public function audioPath(array $ep): ?string
    {
        $path = $ep['audio_file'] ? $this->episodeDir($ep) . '/' . $ep['audio_file'] : null;
        return $path !== null && is_file($path) ? $path : null;
    }

    /** Publish (or save the text of) an episode: it needs a mixdown and a title. */
    public function publish(array $ep, string $title, string $description, bool $publish): array
    {
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? '');
        $description = trim(str_replace("\r", '', $description));
        if ($title === '' || mb_strlen($title) > 190) {
            return ['error' => 'Give the episode a title (up to 190 characters).', 'status' => 400];
        }
        if (mb_strlen($description) > 4000) {
            return ['error' => 'The description can be up to 4000 characters.', 'status' => 400];
        }
        $t = $this->db->table('episodes');
        $now = gmdate('Y-m-d H:i:s');
        $this->db->run("UPDATE $t SET title = ?, description = ?, updated_at = ? WHERE id = ?", [$title, $description, $now, $ep['id']]);
        if (!$publish) {
            return ['ok' => true];
        }
        if (!$this->audioPath($ep)) {
            return ['error' => 'Mix the episode down first.', 'status' => 409];
        }
        $dir = $this->episodeDir($ep);
        $file = $ep['audio_file'];
        if (str_ends_with($file, '.chunk')) {
            $new = $this->slug($title) . '.mp3';
            rename("$dir/$file", "$dir/$new");
            $file = $new;
        }
        $this->db->run("UPDATE $t SET status = 'published', audio_file = ?, published_at = COALESCE(published_at, ?), updated_at = ? WHERE id = ?", [$file, $now, $now, $ep['id']]);
        return ['ok' => true, 'url' => $this->publicUrl($this->episodeById((int) $ep['id']))];
    }

    /** Hide a published episode from the page and feed, or show it again. */
    public function setHidden(array $ep, bool $hidden): array
    {
        if (!in_array($ep['status'], ['published', 'hidden'], true)) {
            return ['error' => 'Only a published episode can be hidden.', 'status' => 409];
        }
        $this->db->run('UPDATE ' . $this->db->table('episodes') . ' SET status = ?, updated_at = ? WHERE id = ?', [$hidden ? 'hidden' : 'published', gmdate('Y-m-d H:i:s'), $ep['id']]);
        return ['ok' => true];
    }

    // ---- The podcast page and feed ---------------------------------------------------------------

    /** @return list<array> published episodes, newest first */
    public function published(int $limit = 300): array
    {
        return $this->db->run('SELECT * FROM ' . $this->db->table('episodes') . " WHERE station_id = ? AND status = 'published' ORDER BY recorded_at DESC LIMIT " . max(1, $limit),
            [$this->station['id']])->fetchAll();
    }

    /** The episode's public address (relative to the app), or null if it has none. */
    public function publicUrl(array $ep): ?string
    {
        return $ep['audio_file'] && str_ends_with($ep['audio_file'], '.mp3') ? 'storage/podcasts/' . $ep['public_id'] . '/' . rawurlencode($ep['audio_file']) : null;
    }

    /** The podcast's own details (station settings JSON), with defaults. */
    public function details(): array
    {
        $own = json_decode((string) ($this->station['settings'] ?? ''), true);
        $own = is_array($own) ? $own : [];
        return [
            'title' => (string) ($own['podcast_title'] ?? '') ?: (string) $this->station['name'],
            'description' => (string) ($own['podcast_description'] ?? '') ?: 'Shows from ' . $this->station['name'] . '.',
            'author' => (string) ($own['podcast_author'] ?? '') ?: (string) $this->station['name'],
            'email' => (string) ($own['podcast_email'] ?? '') ?: StationSettings::privacyEmail($this->station),
            'language' => (string) ($own['podcast_language'] ?? 'en'),
            'category' => isset(self::CATEGORIES[$own['podcast_category'] ?? '']) ? $own['podcast_category'] : 'Religion & Spirituality|Christianity',
            'explicit' => (bool) ($own['podcast_explicit'] ?? false),
            'cover' => is_string($own['podcast_cover'] ?? null) && is_file($this->root . '/storage/podcasts/' . $own['podcast_cover']) ? 'storage/podcasts/' . $own['podcast_cover'] : null,
        ];
    }

    /**
     * Save the podcast's details; $coverTmp is an uploaded image (JPEG or PNG, square, 1400–3000 px
     * as Apple asks). @return array{0: ?array, 1: ?string} [the station row, or null with what's wrong]
     */
    public function saveDetails(array $in, ?string $coverTmp): array
    {
        $own = json_decode((string) ($this->station['settings'] ?? ''), true);
        $own = is_array($own) ? $own : [];
        $text = fn(string $k, int $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $email = $text('email', 190);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [null, 'The owner’s email doesn’t look like an email address.'];
        }
        $lang = $text('language', 10);
        if (preg_match('/^[a-z]{2}(-[A-Za-z]{2})?$/', $lang) !== 1) {
            return [null, 'Give the language as a code like en or af.'];
        }
        if (!isset(self::CATEGORIES[(string) ($in['category'] ?? '')])) {
            return [null, 'Choose one of the categories.'];
        }
        if ($coverTmp !== null) {
            $info = @getimagesize($coverTmp);
            if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
                return [null, 'The cover must be a JPEG or PNG image.'];
            }
            if ($info[0] !== $info[1] || $info[0] < 1400 || $info[0] > 3000) {
                return [null, "The cover must be square, 1400 to 3000 pixels (this one is {$info[0]} × {$info[1]})."];
            }
            $dir = $this->root . '/storage/podcasts';
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Can't make $dir.");
            }
            $name = 'cover-' . substr(sha1_file($coverTmp), 0, 12) . ($info[2] === IMAGETYPE_PNG ? '.png' : '.jpg');
            if (!@copy($coverTmp, "$dir/$name")) {
                throw new \RuntimeException("Can't save the cover.");
            }
            if (is_string($own['podcast_cover'] ?? null) && $own['podcast_cover'] !== $name) {
                @unlink("$dir/" . basename($own['podcast_cover']));
            }
            $own['podcast_cover'] = $name;
        }
        $own = array_replace($own, ['podcast_title' => $text('title', 190), 'podcast_description' => $text('description', 4000), 'podcast_author' => $text('author', 190),
            'podcast_email' => $email, 'podcast_language' => $lang, 'podcast_category' => (string) $in['category'], 'podcast_explicit' => !empty($in['explicit'])]);
        $json = json_encode($own, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->db->run('UPDATE ' . $this->db->table('stations') . ' SET settings = ? WHERE id = ?', [$json, $this->station['id']]);
        return [['settings' => $json] + $this->station, null];
    }

    /** The RSS feed (Apple's podcast tags), with absolute addresses from $base (the app's URL). */
    public function feed(string $base): string
    {
        $d = $this->details();
        $base = rtrim($base, '/') . '/';
        $x = fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        [$cat, $sub] = array_pad(explode('|', $d['category']), 2, null);
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n<channel>\n"
            . '<title>' . $x($d['title']) . "</title>\n"
            . '<link>' . $x($base . 'podcast') . "</link>\n"
            . '<atom:link href="' . $x($base . 'podcast/feed') . '" rel="self" type="application/rss+xml"/>' . "\n"
            . '<description>' . $x($d['description']) . "</description>\n"
            . '<language>' . $x($d['language']) . "</language>\n"
            . '<itunes:author>' . $x($d['author']) . "</itunes:author>\n"
            . '<itunes:explicit>' . ($d['explicit'] ? 'true' : 'false') . "</itunes:explicit>\n"
            . '<itunes:type>episodic</itunes:type>' . "\n"
            . '<itunes:category text="' . $x($cat) . '">' . ($sub ? '<itunes:category text="' . $x($sub) . '"/>' : '') . "</itunes:category>\n"
            . ($d['email'] !== '' ? '<itunes:owner><itunes:name>' . $x($d['author']) . '</itunes:name><itunes:email>' . $x($d['email']) . "</itunes:email></itunes:owner>\n" : '')
            . ($d['cover'] ? '<itunes:image href="' . $x($base . $d['cover']) . '"/>' . "\n<image><url>" . $x($base . $d['cover']) . '</url><title>' . $x($d['title']) . '</title><link>' . $x($base . 'podcast') . "</link></image>\n" : '');
        foreach ($this->published() as $ep) {
            $url = $this->publicUrl($ep);
            if ($url === null) {
                continue;
            }
            $s = (int) round((float) $ep['audio_seconds']);
            $out .= "<item>\n<title>" . $x($ep['title']) . "</title>\n"
                . '<guid isPermaLink="false">' . $x($ep['public_id']) . "</guid>\n"
                . '<pubDate>' . gmdate(DATE_RSS, (int) $ep['recorded_at']) . "</pubDate>\n"
                . '<description>' . $x((string) $ep['description']) . "</description>\n"
                . '<enclosure url="' . $x($base . $url) . '" length="' . (int) $ep['audio_bytes'] . '" type="audio/mpeg"/>' . "\n"
                . '<itunes:duration>' . sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) . "</itunes:duration>\n"
                . '<itunes:episodeType>full</itunes:episodeType>' . "\n"
                . "</item>\n";
        }
        return $out . "</channel>\n</rss>\n";
    }

    // ---- The public JSON (api/podcast.php) -------------------------------------------------------

    /** The podcast and its published episodes for apps; `$base` is the app's absolute address. */
    public function publicList(string $base): array
    {
        $base = rtrim($base, '/') . '/';
        $d = $this->details();
        $episodes = [];
        foreach ($this->published() as $ep) {
            if (($e = $this->publicEpisode($ep, $base)) !== null) {
                $episodes[] = $e;
            }
        }
        return [
            'podcast' => ['title' => $d['title'], 'description' => $d['description'], 'author' => $d['author'], 'language' => $d['language'],
                          'cover' => $d['cover'] ? $base . $d['cover'] : null, 'feed' => $base . 'podcast/feed', 'page' => $base . 'podcast'],
            'episodes' => $episodes,
        ];
    }

    /** One published episode as the public JSON has it (without its notes), or null when it has no audio. */
    public function publicEpisode(array $ep, string $base): ?array
    {
        $url = $this->publicUrl($ep);
        if ($url === null) {
            return null;
        }
        $tz = new \DateTimeZone($this->station['timezone'] ?: 'UTC');
        $host = $ep['dj_user_id'] ? $this->db->run('SELECT name FROM ' . $this->db->table('users') . ' WHERE id = ?', [$ep['dj_user_id']])->fetchColumn() : false;
        return [
            'id' => $ep['public_id'], 'title' => $ep['title'], 'description' => (string) $ep['description'],
            'recorded_at' => (int) $ep['recorded_at'], 'date' => (new \DateTimeImmutable('@' . (int) $ep['recorded_at']))->setTimezone($tz)->format('Y-m-d'),
            'seconds' => (int) round((float) $ep['audio_seconds']), 'bytes' => (int) $ep['audio_bytes'], 'audio' => rtrim($base, '/') . '/' . $url,
            'host' => $host !== false && $host !== '' ? (string) $host : null,
        ];
    }

    /** A published episode by its public id, with its notes and slides placed in its audio; null when there isn't one. */
    public function publicEpisodeWithNotes(string $publicId, string $base): ?array
    {
        $ep = $this->db->run('SELECT * FROM ' . $this->db->table('episodes') . " WHERE station_id = ? AND public_id = ? AND status = 'published'", [$this->station['id'], $publicId])->fetch();
        $out = $ep ? $this->publicEpisode($ep, $base) : null;
        if ($out === null) {
            return null;
        }
        $base = rtrim($base, '/') . '/';
        $items = $this->notes($ep);
        foreach ($items as &$item) {
            foreach ($item['slides'] ?? [] as $i => $slide) {
                $item['slides'][$i]['url'] = $base . $slide['url'];
            }
            unset($item['show']);
        }
        unset($item);
        return $out + ['items' => $items];
    }

    /**
     * The notes the presenter posted in an episode's shows, each with `at_s`: the second of the published
     * audio it belongs to (when the presenter posted it, less what the mixdown cut out before it: the
     * deleted clips and long silences, as `planTake` in assets/podcast-core.js works them out), so a player
     * can show a note when listeners reach it. Oldest first.
     * @return list<array>
     */
    public function notes(array $ep): array
    {
        $edits = json_decode((string) ($ep['edits'] ?? ''), true);
        $edits = is_array($edits) ? $edits : [];
        $deleted = array_flip(is_array($edits['mixed'] ?? null) ? $edits['mixed'] : (is_array($edits['deleted'] ?? null) ? $edits['deleted'] : []));
        $out = 0.0;
        $entries = [];
        foreach ($this->takes((int) $ep['id']) as $t) {
            $skip = (float) $t['skip_s'];
            $segments = self::segments($t['analysis'] ? (json_decode((string) $t['analysis'], true) ?: null) : null, (int) $t['id'], $deleted, $skip, $skip + (float) $t['duration_s']);
            $entries[(int) $t['live_show_id']][] = ['start' => (float) $t['start_at'], 'to' => $skip + (float) $t['duration_s'], 'segments' => $segments, 'out' => $out];
            $out += array_sum(array_map(fn($s) => $s[1] - $s[0], $segments));
        }
        $items = (new Notes($this->db, $this->station))->forShows(array_keys($entries));
        foreach ($items as &$item) {
            $mine = $entries[$item['show']];
            $at = 0.0;
            foreach ($mine as $i => $e) {
                $t = max(0.0, $item['posted'] - $e['start']);
                if ($t < $e['to'] || $i === count($mine) - 1) {
                    $at = $e['out'] + self::placeIn($e['segments'], $t);
                    break;
                }
            }
            $item['at_s'] = round($at, 1);
        }
        unset($item);
        usort($items, fn($a, $b) => [$a['at_s'], $a['posted'], $a['id']] <=> [$b['at_s'], $b['posted'], $b['id']]);
        return $items;
    }

    /**
     * What one take puts into the episode, as [from, to] seconds of the take in order: the same as
     * `planTake` in assets/podcast-core.js (keep the two in step). A take with no analysis (never opened
     * in the editor) goes in whole, from `$from` to `$end`.
     * @param ?array $analysis the take's stored analysis; $deleted "<take>:<track>:<index>" => index
     * @return list<array{0: float, 1: float}>
     */
    public static function segments(?array $analysis, int $takeId, array $deleted, float $from, float $end): array
    {
        if (!$analysis) {
            return $end > $from ? [[$from, $end]] : [];
        }
        $kept = [];
        foreach (($analysis['tracks'] ?? []) as $name => $track) {
            foreach (($track['clips'] ?? []) as $i => $c) {
                if ($c[1] > $c[0] && $c[1] > $from && !isset($deleted["$takeId:$name:$i"])) {
                    $kept[] = $c;
                }
            }
        }
        usort($kept, fn($a, $b) => $a[0] <=> $b[0]);
        $union = [];
        foreach ($kept as $c) {
            $last = count($union) - 1;
            if ($last >= 0 && $c[0] <= $union[$last][1]) {
                $union[$last][1] = max($union[$last][1], $c[1]);
            } else {
                $union[] = [$c[0], $c[1]];
            }
        }
        $segments = [];
        if (!$union) {
            return $segments;
        }
        $half = self::GAP_KEEP / 2;
        $start = max(0.0, $union[0][0] - $half);
        foreach ($union as $i => $u) {
            $next = $union[$i + 1] ?? null;
            if ($next !== null && $next[0] - $u[1] <= self::SPLIT_SECONDS) {
                continue; // a short pause stays as it is
            }
            $segments[] = [round($start, 3), round(min($end, $u[1] + $half), 3)];
            if ($next !== null) {
                $start = $next[0] - $half;
            }
        }
        return $segments;
    }

    /** Where second `$t` of a take lands in what it contributes: inside a cut stretch, at the start of what follows it. */
    public static function placeIn(array $segments, float $t): float
    {
        $acc = 0.0;
        foreach ($segments as [$a, $b]) {
            if ($t < $a) {
                return $acc;
            }
            if ($t <= $b) {
                return $acc + ($t - $a);
            }
            $acc += $b - $a;
        }
        return $acc;
    }

    // ---- Housekeeping ---------------------------------------------------------------------------

    /** Delete the raw tracks of episodes published over RAW_KEEP_DAYS ago. Returns how many episodes. */
    public function cleanup(float $now): int
    {
        $t = $this->db->table('episodes');
        $eps = $this->db->run("SELECT * FROM $t WHERE station_id = ? AND status IN ('published', 'hidden') AND raw_deleted_at IS NULL AND published_at < ?",
            [$this->station['id'], gmdate('Y-m-d H:i:s', (int) $now - self::RAW_KEEP_DAYS * 86400)])->fetchAll();
        foreach ($eps as $ep) {
            $dir = $this->episodeDir($ep) . '/raw';
            foreach (glob("$dir/*.chunk") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
            $this->db->run('DELETE c FROM ' . $this->db->table('episode_chunks') . ' c JOIN ' . $this->db->table('episode_takes') . ' k ON k.id = c.take_id WHERE k.episode_id = ?', [$ep['id']]);
            $this->db->run("UPDATE $t SET raw_deleted_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', (int) $now), $ep['id']]);
        }
        return count($eps);
    }

    private function episodeDir(array $ep): string
    {
        return $this->root . '/storage/podcasts/' . $ep['public_id'];
    }

    private function slug(string $title): string
    {
        $s = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title), '-'));
        return substr($s !== '' ? $s : 'episode', 0, 60);
    }

    private function setting(string $key): mixed
    {
        $own = json_decode((string) ($this->station['settings'] ?? ''), true);
        return is_array($own) ? ($own[$key] ?? null) : null;
    }
}
