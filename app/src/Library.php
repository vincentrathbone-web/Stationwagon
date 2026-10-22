<?php
declare(strict_types=1);

namespace Stationwagon;

use RuntimeException;

/**
 * A station's songs. Each file is stored once under its content hash (storage/media/<sha1>.mp3),
 * so a file's address never changes and Cloudflare may cache it for a year. A track's `sha1` is
 * the song's identity: the hash of the file as the manager chose it, before the browser levelled
 * it (see assets/prep.js). Adding the same song twice, prepared or not, returns the track that's
 * already there. `loudness_lufs` is set only for songs that were levelled, `mastered` for songs
 * that song prep mastered (assets/master-core.js).
 */
final class Library
{
    /** Listeners hear the stream ~20 s late and buffer a little more; keep a song's file this long after it plays. */
    public const LISTENER_GRACE_SECONDS = 120;

    public function __construct(private readonly Db $db, private readonly array $station, private readonly string $root)
    {
    }

    /**
     * @param ?string $name the file's own name when $path is an upload's temporary file
     * @param ?string $sourceSha1 the hash of the original file when $path is the levelled copy
     * @param ?float $lufs the levelled copy's loudness
     * @param bool $mastered song prep mastered the copy
     * @return array{id:int,duplicate:bool,title:string}
     */
    public function import(string $path, ?string $title = null, ?string $artist = null, ?string $name = null,
                           ?string $sourceSha1 = null, ?float $lufs = null, ?array $outro = null, bool $mastered = false): array
    {
        $name = basename($name ?? $path);
        if (!is_file($path)) {
            throw new RuntimeException("No file at $path");
        }
        $content = (string) sha1_file($path);
        $sha1 = $sourceSha1 !== null && preg_match('/^[0-9a-f]{40}$/', $sourceSha1) === 1 ? $sourceSha1 : $content;
        $t = $this->db->table('tracks');
        $existing = $this->db->run("SELECT id, title FROM $t WHERE station_id = ? AND (sha1 = ? OR sha1 = ? OR file = ?) ORDER BY id LIMIT 1",
            [$this->station['id'], $sha1, $content, "$content.mp3"])->fetch();
        if ($existing) {
            return ['id' => (int) $existing['id'], 'duplicate' => true, 'title' => $existing['title']];
        }
        $settings = Planner::settings($this->station);
        $scan = Mp3::scan($path, (float) $settings['segment_seconds']);
        if ($scan['frames'] === 0 || !$scan['pieces']) {
            throw new RuntimeException($name . ' is not an MP3 file this station can play.');
        }
        $tags = Mp3::tags($path);
        $file = "$content.mp3";
        $dir = $this->root . '/storage/media';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (!is_file("$dir/$file") && !copy($path, "$dir/$file")) {
            throw new RuntimeException("Couldn't save the file in storage/media.");
        }
        @chmod("$dir/$file", 0644);
        $title = trim((string) ($title ?? $tags['title'] ?? '')) ?: self::titleFromFilename($name);
        $id = $this->db->insert('tracks', [
            'station_id' => $this->station['id'],
            'file' => $file,
            'sha1' => $sha1,
            'title' => mb_substr($title, 0, 255),
            'artist' => ($a = trim((string) ($artist ?? $tags['artist'] ?? ''))) !== '' ? mb_substr($a, 0, 255) : null,
            'album' => isset($tags['album']) ? mb_substr($tags['album'], 0, 255) : null,
            'duration_s' => $scan['duration'],
            'bitrate_kbps' => $scan['bitrate'],
            'sample_rate' => $scan['sample_rate'],
            'bytes' => filesize($path),
            'pieces' => json_encode($scan['pieces']),
            'loudness_lufs' => $lufs !== null && is_finite($lufs) ? round(max(-70.0, min(0.0, $lufs)), 2) : null,
            'mastered' => $mastered && $sourceSha1 !== null ? 1 : 0,
            'outro' => self::outro($outro),
            'status' => 'ready',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return ['id' => $id, 'duplicate' => false, 'title' => $title];
    }

    /**
     * Song prep's last-minute loudness (one LUFS value a second, oldest first) as stored JSON, or
     * null when it's missing or doesn't look like one (then silence detection leaves the song be).
     */
    public static function outro(?array $values): ?string
    {
        if (!$values || count($values) > 120 || array_filter($values, fn($v) => !is_int($v) && !is_float($v))) {
            return null;
        }
        return json_encode(array_map(fn($v) => round(max(-99.0, min(0.0, (float) $v)), 1), array_values($values)));
    }

    /** Ready tracks, without their piece lists; $search matches title or artist. */
    public function tracks(string $search = ''): array
    {
        $t = $this->db->table('tracks');
        $pt = $this->db->table('playlist_tracks');
        $where = "t.station_id = ? AND t.status = 'ready'";
        $args = [$this->station['id']];
        if (trim($search) !== '') {
            $where .= ' AND (t.title LIKE ? OR t.artist LIKE ?)';
            $like = '%' . addcslashes(trim($search), '%_\\') . '%';
            array_push($args, $like, $like);
        }
        return $this->db->run(
            "SELECT t.id, t.file, t.title, t.artist, t.album, t.duration_s, t.bitrate_kbps, t.bytes, t.loudness_lufs, t.mastered, t.created_at,
                    (SELECT COUNT(*) FROM $pt pt WHERE pt.track_id = t.id) AS playlists
             FROM $t t WHERE $where ORDER BY t.title, t.id",
            $args
        )->fetchAll();
    }

    public function get(int $id): ?array
    {
        $row = $this->db->run('SELECT * FROM ' . $this->db->table('tracks') . ' WHERE id = ? AND station_id = ?', [$id, $this->station['id']])->fetch();
        return $row ?: null;
    }

    /** Change the title and artist people see. Returns what's wrong, or null. */
    public function update(int $id, string $title, string $artist): ?string
    {
        $title = trim($title);
        if ($title === '') {
            return 'A song needs a title.';
        }
        if ($this->get($id) === null) {
            return 'That song isn’t in this library.';
        }
        $this->db->run('UPDATE ' . $this->db->table('tracks') . ' SET title = ?, artist = ? WHERE id = ?',
            [mb_substr($title, 0, 255), trim($artist) !== '' ? mb_substr(trim($artist), 0, 255) : null, $id]);
        return null;
    }

    /**
     * Remove a song and its file. Refused while it plays or just played, because listeners are
     * about 20 s behind and still need its pieces. Planned plays are planned again by the caller.
     * Returns what's wrong, or null.
     */
    public function delete(int $id, Planner $planner, float $now): ?string
    {
        $track = $this->get($id);
        if ($track === null) {
            return 'That song isn’t in this library.';
        }
        foreach ($planner->timeline($now) as $e) {
            if ($e['type'] === 'song' && $e['track_id'] === $id && $e['start'] <= $now + 2 * (float) $planner->setting('cut_lead_seconds') + 1 && $e['end'] > $now - self::LISTENER_GRACE_SECONDS) {
                return '“' . $track['title'] . '” is on air (or just was). Try again in a few minutes.';
            }
        }
        $this->db->run('DELETE FROM ' . $this->db->table('tracks') . ' WHERE id = ?', [$id]);
        $planner->replan($now); // drops planned plays of the song
        $left = (int) $this->db->run('SELECT COUNT(*) FROM ' . $this->db->table('tracks') . ' WHERE file = ?', [$track['file']])->fetchColumn();
        if ($left === 0) {
            @unlink($this->root . '/storage/media/' . $track['file']);
        }
        return null;
    }

    /** Space the station's songs take, in bytes. */
    public function bytes(): int
    {
        return (int) $this->db->run('SELECT COALESCE(SUM(bytes), 0) FROM ' . $this->db->table('tracks') . ' WHERE station_id = ?', [$this->station['id']])->fetchColumn();
    }

    public static function titleFromFilename(string $file): string
    {
        $name = trim((string) preg_replace('/[_]+/', ' ', (string) preg_replace('/\.mp3$/i', '', $file)));
        return $name === '' ? $file : $name;
    }
}
