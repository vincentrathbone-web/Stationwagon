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
 * that song prep mastered (assets/master-core.js), with `preset` the mastering preset's name.
 * Since round 40 the file as the manager chose it is kept too (storage/originals/<sha1>.mp3, not
 * served to the web), so a song can be prepared again with another preset; a song that went up as
 * it was needs no copy, its media file is the original.
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
     * @param ?array $level song prep's normalization: source_lufs, gain_db, target, settings (see levelColumns)
     * @return array{id:int,duplicate:bool,title:string}
     */
    public function import(string $path, ?string $title = null, ?string $artist = null, ?string $name = null,
                           ?string $sourceSha1 = null, ?float $lufs = null, ?array $outro = null, bool $mastered = false,
                           ?string $preset = null, ?array $level = null): array
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
        $prepared = $sourceSha1 !== null;
        $id = $this->db->insert('tracks', self::levelColumns($level, $prepared && $lufs !== null, $mastered && $prepared) + [
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
            'preset' => $mastered && $sourceSha1 !== null && $preset !== null && trim($preset) !== '' ? mb_substr(trim($preset), 0, 60) : null,
            'outro' => self::outro($outro),
            'art' => isset($tags['art']) ? $this->storeArt($tags['art']['data']) : null,
            'rights' => Rights::encode(Rights::normalize(self::rightsFromTags($tags))),
            'status' => 'ready',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return ['id' => $id, 'duplicate' => false, 'title' => $title];
    }

    /**
     * A song's normalization as stored (schema 11): its loudness before levelling, the gain applied,
     * the station target it was levelled to (only when levelled) and the mastering settings it was
     * made with (only when mastered; {} is the built-in chain), so a re-level keeps its sound.
     * @param ?array{source_lufs?:mixed,gain_db?:mixed,target?:mixed,settings?:mixed} $level
     */
    public static function levelColumns(?array $level, bool $levelled, bool $mastered): array
    {
        $num = fn(string $k, float $lo, float $hi, int $d) => isset($level[$k]) && is_numeric($level[$k]) && is_finite((float) $level[$k])
            ? round(max($lo, min($hi, (float) $level[$k])), $d) : null;
        $settings = $level['settings'] ?? null;
        return [
            'source_lufs' => $num('source_lufs', -70.0, 0.0, 2),
            'level_gain_db' => $levelled ? $num('gain_db', -40.0, 40.0, 2) : null,
            'level_target' => $levelled ? $num('target', StationSettings::TARGET_MIN, StationSettings::TARGET_MAX, 1) : null,
            'preset_settings' => $mastered && is_array($settings) && PrepPresets::check($settings) === null
                ? json_encode((object) $settings, JSON_UNESCAPED_SLASHES) : null,
        ];
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
            "SELECT t.id, t.file, t.sha1, t.title, t.artist, t.album, t.duration_s, t.bitrate_kbps, t.bytes, t.loudness_lufs, t.source_lufs, t.level_gain_db, t.level_target, t.mastered, t.preset, t.preset_settings, t.art, t.rights, t.rating, t.created_at,
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

    /** Change the title, artist and album people see. Returns what's wrong, or null. */
    public function update(int $id, string $title, string $artist, ?string $album = null): ?string
    {
        $title = trim($title);
        if ($title === '') {
            return 'A song needs a title.';
        }
        if ($this->get($id) === null) {
            return 'That song isn’t in this library.';
        }
        $this->db->run('UPDATE ' . $this->db->table('tracks') . ' SET title = ?, artist = ?' . ($album !== null ? ', album = ?' : '') . ' WHERE id = ?',
            array_merge([mb_substr($title, 0, 255), trim($artist) !== '' ? mb_substr(trim($artist), 0, 255) : null],
                $album !== null ? [trim($album) !== '' ? mb_substr(trim($album), 0, 255) : null] : [], [$id]));
        return null;
    }

    /** Rate a song 1 to 5 stars, or null to clear it (unrated counts as 3). Returns what's wrong, or null. */
    public function setRating(int $id, ?int $stars): ?string
    {
        if ($stars !== null && ($stars < 1 || $stars > 5)) {
            return 'A rating is 1 to 5 stars.';
        }
        if ($this->get($id) === null) {
            return 'That song isn’t in this library.';
        }
        $this->db->run('UPDATE ' . $this->db->table('tracks') . ' SET rating = ? WHERE id = ? AND station_id = ?', [$stars, $id, $this->station['id']]);
        return null;
    }

    /** Copyright details from a file's tags: the © text without its © and year, the year, publisher, composers, ISRC, lyricists, track number. */
    public static function rightsFromTags(array $tags): array
    {
        $c = trim((string) ($tags['copyright'] ?? ''));
        $owner = trim((string) preg_replace('/^(?:©|\(c\))?\s*\d{4}\s*/iu', '', (string) preg_replace('/^\s*(?:©|\(c\)|copyright)\s*/iu', '', $c)));
        return ['owner' => $owner, 'year' => $tags['year'] ?? (preg_match('/\d{4}/', $c, $y) ? $y[0] : ''),
                'publisher' => $tags['publisher'] ?? '', 'writers' => $tags['writers'] ?? '',
                'isrc' => $tags['isrc'] ?? '', 'lyricists' => $tags['lyricists'] ?? '', 'track' => $tags['track'] ?? ''];
    }

    /** Schema 14: read every song's tags once for its album art and copyright details (where it has none). */
    public static function backfillTags(Db $db, string $root): void
    {
        $t = $db->table('tracks');
        $lib = new self($db, ['id' => 0], $root);
        foreach ($db->run("SELECT id, file FROM $t WHERE art IS NULL AND rights IS NULL")->fetchAll() as $r) {
            $path = $root . '/storage/media/' . basename((string) $r['file']);
            if (!is_file($path)) {
                continue;
            }
            $tags = Mp3::tags($path);
            $art = isset($tags['art']) ? $lib->storeArt($tags['art']['data']) : null;
            $rights = Rights::encode(Rights::normalize(self::rightsFromTags($tags)));
            if ($art !== null || $rights !== null) {
                $db->run("UPDATE $t SET art = ?, rights = ? WHERE id = ?", [$art, $rights, $r['id']]);
            }
        }
    }

    /** The folder album art is kept in (storage/art; its files are served, see storage/.htaccess). */
    private function artDir(): string
    {
        return $this->root . '/storage/art';
    }

    /**
     * Keep a picture as album art: a JPEG or PNG of up to 3 MB, made no bigger than 800 px where
     * the server can (GD). Returns the file's name, or null when it isn't a usable picture.
     */
    public function storeArt(string $bytes): ?string
    {
        if ($bytes === '' || strlen($bytes) > 3 * 1024 * 1024) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $info[0] < 16 || $info[1] < 16 || $info[0] > 6000 || $info[1] > 6000) {
            return null;
        }
        $png = $info[2] === IMAGETYPE_PNG;
        if (max($info[0], $info[1]) > 800 && function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($bytes)) !== false) {
            $wide = $info[0] >= $info[1];
            $small = imagescale($img, $wide ? 800 : (int) round(800 * $info[0] / $info[1]), $wide ? (int) round(800 * $info[1] / $info[0]) : 800);
            if ($small !== false) {
                ob_start();
                $png ? imagepng($small, null, 7) : imagejpeg($small, null, 86);
                $bytes = (string) ob_get_clean();
            }
        }
        $name = 'art-' . substr(sha1($bytes), 0, 12) . ($png ? '.png' : '.jpg');
        $dir = $this->artDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        if (!is_file("$dir/$name") && file_put_contents("$dir/$name", $bytes) === false) {
            return null;
        }
        @chmod("$dir/$name", 0644);
        return $name;
    }

    /** Put a picture on a song as its album art, or take the art off with null. Returns what's wrong, or null. */
    public function setArt(int $id, ?string $bytes): ?string
    {
        $track = $this->get($id);
        if ($track === null) {
            return 'That song isn’t in this library.';
        }
        $name = null;
        if ($bytes !== null) {
            $name = $this->storeArt($bytes);
            if ($name === null) {
                return 'The album art must be a JPEG or PNG picture of up to 3 MB.';
            }
        }
        $this->db->run('UPDATE ' . $this->db->table('tracks') . ' SET art = ? WHERE id = ?', [$name, $id]);
        $this->dropUnusedArt($track['art']);
        return null;
    }

    private function dropUnusedArt(?string $art): void
    {
        if (self::artPath($art) === null) {
            return;
        }
        if ((int) $this->db->run('SELECT COUNT(*) FROM ' . $this->db->table('tracks') . ' WHERE art = ?', [$art])->fetchColumn() === 0) {
            @unlink($this->artDir() . '/' . $art);
        }
    }

    /** Save a song's copyright details (see Rights); nothing set clears them. Returns what's wrong, or null. */
    public function setRights(int $id, array $in): ?string
    {
        if ($this->get($id) === null) {
            return 'That song isn’t in this library.';
        }
        if (($problem = Rights::problem($in)) !== null) {
            return $problem;
        }
        $this->db->run('UPDATE ' . $this->db->table('tracks') . ' SET rights = ? WHERE id = ?', [Rights::encode(Rights::normalize($in)), $id]);
        return null;
    }

    /** The address of a song's album art, relative to the app, or null. */
    public static function artPath(?string $art): ?string
    {
        return $art !== null && preg_match('/^art-[0-9a-f]{12}\.(jpg|png)$/', $art) ? 'storage/art/' . $art : null;
    }

    /** A song as the library screen's script wants it (edit row, copyright window), after any change. */
    public static function row(array $t): array
    {
        return ['id' => (int) $t['id'], 'title' => $t['title'], 'artist' => $t['artist'] ?? '', 'album' => $t['album'] ?? '',
                'art' => self::artPath($t['art'] ?? null), 'rights' => Rights::decode($t['rights'] ?? null) ?? new \stdClass(),
                'rating' => isset($t['rating']) ? (int) $t['rating'] : null];
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
        $this->dropUnused($track['file']);
        $this->dropUnusedArt($track['art']);
        $kept = (int) $this->db->run('SELECT COUNT(*) FROM ' . $this->db->table('tracks') . ' WHERE sha1 = ?', [$track['sha1']])->fetchColumn();
        if ($kept === 0) {
            @unlink($this->originalsDir() . '/' . $track['sha1'] . '.mp3');
        }
        return null;
    }

    private function dropUnused(string $file): void
    {
        $left = (int) $this->db->run('SELECT COUNT(*) FROM ' . $this->db->table('tracks') . ' WHERE file = ?', [$file])->fetchColumn();
        if ($left === 0) {
            @unlink($this->root . '/storage/media/' . $file);
        }
    }

    private function originalsDir(): string
    {
        return $this->root . '/storage/originals';
    }

    /**
     * Keep the file a song was prepared from. It must be that song's original (its hash is the
     * song's `sha1`). Returns what's wrong, or null.
     */
    public function keepOriginal(int $id, string $path): ?string
    {
        $track = $this->get($id);
        if ($track === null) {
            return 'That song isn’t in this library.';
        }
        if (!is_file($path) || sha1_file($path) !== $track['sha1']) {
            return 'That isn’t the file this song was made from.';
        }
        if ($track['file'] === $track['sha1'] . '.mp3') {
            return null; // it went up as it was: its media file is the original
        }
        return $this->storeOriginal($track['sha1'], $path);
    }

    private function storeOriginal(string $sha1, string $path): ?string
    {
        $dir = $this->originalsDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return 'Couldn’t make storage/originals.';
        }
        // never served to the web (storage/.htaccess lets .mp3 files through elsewhere)
        if (!is_file("$dir/.htaccess")) {
            file_put_contents("$dir/.htaccess", <<<'HT'
# The songs as their managers chose them, for song prep only (never served)
Require all denied
<FilesMatch ".">
  Require all denied
</FilesMatch>

HT);
        }
        $to = "$dir/$sha1.mp3";
        if (!is_file($to) && !copy($path, $to)) {
            return 'Couldn’t save the original.';
        }
        @chmod($to, 0644);
        return null;
    }

    /**
     * Whether a song's file was made by song prep before round 51 (lamejs, whose level came out 0.26 to
     * 0.45 dB under the target): it has no "STATIONWAGON_PREP" tag naming LAME 3.100. Songs that were
     * never prepared are not "old" here. Files are named by their content, so each answer is kept in
     * storage/cache/prep-encoder.json and the tag is read once per file.
     */
    public function madeWithOldEncoder(array $track): bool
    {
        $file = basename((string) $track['file']);
        $cacheFile = $this->root . '/storage/cache/prep-encoder.json';
        if ($this->encoderCache === null) {
            $this->encoderCache = is_file($cacheFile) ? (json_decode((string) file_get_contents($cacheFile), true) ?: []) : [];
        }
        if (!array_key_exists($file, $this->encoderCache)) {
            $old = false;
            $fh = @fopen($this->root . '/storage/media/' . $file, 'rb');
            if ($fh) {
                $head = (string) fread($fh, 10);
                if (strlen($head) === 10 && str_starts_with($head, 'ID3')) {
                    $size = ((ord($head[6]) & 0x7f) << 21) | ((ord($head[7]) & 0x7f) << 14) | ((ord($head[8]) & 0x7f) << 7) | (ord($head[9]) & 0x7f);
                    $tag = (string) fread($fh, min($size, 16 * 1024 * 1024));
                    // prepared (it carries song prep's own tags) but not by LAME 3.100
                    $old = str_contains($tag, 'STATIONWAGON_SOURCE_LUFS') && !preg_match('/STATIONWAGON_PREP\x00+LAME 3\.100/', $tag);
                }
                fclose($fh);
            }
            $this->encoderCache[$file] = $old;
            @file_put_contents($cacheFile, json_encode($this->encoderCache), LOCK_EX);
        }
        return (bool) $this->encoderCache[$file];
    }

    /** @var array<string,bool>|null which files were made before round 51 (see madeWithOldEncoder) */
    private ?array $encoderCache = null;

    /** The file a song was made from, when the station has it, or null. */
    public function original(array $track): ?string
    {
        $kept = $this->originalsDir() . '/' . $track['sha1'] . '.mp3';
        if (is_file($kept)) {
            return $kept;
        }
        $media = $this->root . '/storage/media/' . $track['file'];
        return $track['file'] === $track['sha1'] . '.mp3' && is_file($media) ? $media : null;
    }

    /**
     * Put a newly prepared copy in place of a song's file (the Song prep screen's "Apply to this
     * song"): same song, same id, its new length and loudness. Refused while it plays or just played,
     * like removing it. Returns what's wrong, or null.
     */
    public function replace(int $id, string $path, ?float $lufs, ?array $outro, bool $mastered, ?string $preset, Planner $planner, float $now, ?array $level = null): ?string
    {
        $track = $this->get($id);
        if ($track === null) {
            return 'That song isn’t in this library.';
        }
        if ($this->original($track) === null) {
            return 'The station doesn’t have this song’s original, so it can’t be prepared again. Upload it again instead.';
        }
        foreach ($planner->timeline($now) as $e) {
            if ($e['type'] === 'song' && $e['track_id'] === $id && $e['start'] <= $now + 2 * (float) $planner->setting('cut_lead_seconds') + 1 && $e['end'] > $now - self::LISTENER_GRACE_SECONDS) {
                return '“' . $track['title'] . '” is on air (or just was). Try again in a few minutes.';
            }
        }
        $scan = Mp3::scan($path, (float) Planner::settings($this->station)['segment_seconds']);
        if ($scan['frames'] === 0 || !$scan['pieces']) {
            return 'That isn’t an MP3 file this station can play.';
        }
        $content = (string) sha1_file($path);
        $file = "$content.mp3";
        $dir = $this->root . '/storage/media';
        // a song that went up as it was: its media file is its original, so keep that first
        if ($track['file'] === $track['sha1'] . '.mp3' && ($problem = $this->storeOriginal($track['sha1'], "$dir/{$track['file']}")) !== null) {
            return $problem;
        }
        if (!is_file("$dir/$file") && !copy($path, "$dir/$file")) {
            return 'Couldn’t save the file in storage/media.';
        }
        @chmod("$dir/$file", 0644);
        $cols = self::levelColumns($level, $lufs !== null, $mastered);
        $this->db->run('UPDATE ' . $this->db->table('tracks') . ' SET file = ?, duration_s = ?, bitrate_kbps = ?, sample_rate = ?, bytes = ?, pieces = ?,
            loudness_lufs = ?, mastered = ?, preset = ?, outro = ?, source_lufs = ?, level_gain_db = ?, level_target = ?, preset_settings = ? WHERE id = ?', [
            $file, $scan['duration'], $scan['bitrate'], $scan['sample_rate'], filesize($path), json_encode($scan['pieces']),
            $lufs !== null && is_finite($lufs) ? round(max(-70.0, min(0.0, $lufs)), 2) : null, $mastered ? 1 : 0,
            $mastered && $preset !== null && trim($preset) !== '' ? mb_substr(trim($preset), 0, 60) : null, self::outro($outro),
            $cols['source_lufs'], $cols['level_gain_db'], $cols['level_target'], $cols['preset_settings'], $id,
        ]);
        $planner->replan($now); // its coming plays use the new file
        if ($track['file'] !== $file) {
            $this->dropUnused($track['file']);
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
