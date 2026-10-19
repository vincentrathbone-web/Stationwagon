<?php
declare(strict_types=1);

namespace Stationwagon;

use RuntimeException;

/**
 * A station's songs. Each file is stored once under its content hash (storage/media/<sha1>.mp3),
 * so a file's address never changes and Cloudflare may cache it for a year. Adding the same
 * file twice returns the track that's already there.
 */
final class Library
{
    public function __construct(private readonly Db $db, private readonly array $station, private readonly string $root)
    {
    }

    /** @return array{id:int,duplicate:bool,title:string} */
    public function import(string $path, ?string $title = null, ?string $artist = null): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("No file at $path");
        }
        $sha1 = (string) sha1_file($path);
        $t = $this->db->table('tracks');
        $existing = $this->db->run("SELECT id, title FROM $t WHERE station_id = ? AND sha1 = ?", [$this->station['id'], $sha1])->fetch();
        if ($existing) {
            return ['id' => (int) $existing['id'], 'duplicate' => true, 'title' => $existing['title']];
        }
        $settings = Planner::settings($this->station);
        $scan = Mp3::scan($path, (float) $settings['segment_seconds']);
        if ($scan['frames'] === 0 || !$scan['pieces']) {
            throw new RuntimeException(basename($path) . ' is not an MP3 file this station can play.');
        }
        $tags = Mp3::tags($path);
        $file = "$sha1.mp3";
        $dir = $this->root . '/storage/media';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (!is_file("$dir/$file") && !copy($path, "$dir/$file")) {
            throw new RuntimeException("Couldn't save the file in storage/media.");
        }
        @chmod("$dir/$file", 0644);
        $title = trim((string) ($title ?? $tags['title'] ?? '')) ?: self::titleFromFilename(basename($path));
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
            'status' => 'ready',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return ['id' => $id, 'duplicate' => false, 'title' => $title];
    }

    /** Ready tracks, without their piece lists. */
    public function tracks(): array
    {
        $t = $this->db->table('tracks');
        return $this->db->run("SELECT id, file, title, artist, duration_s FROM $t WHERE station_id = ? AND status = 'ready' ORDER BY title", [$this->station['id']])->fetchAll();
    }

    public static function titleFromFilename(string $file): string
    {
        $name = trim((string) preg_replace('/[_]+/', ' ', (string) preg_replace('/\.mp3$/i', '', $file)));
        return $name === '' ? $file : $name;
    }
}
