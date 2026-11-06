<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * The pictures of a "Slides" presenter note. The studio makes each one a JPEG of up to 1600 px in the
 * browser and uploads it (POST studio/api/slide); here it is checked, kept once by content under
 * storage/slides (served: see storage/.htaccess) and named in the note. Pictures that no note uses
 * are dropped after a day (`cleanup`, run by the background jobs).
 */
final class Slides
{
    public const MAX_BYTES = 1_500_000;
    public const MAX_PER_NOTE = 30;
    public const MAX_PIXELS = 4000;

    public function __construct(private readonly Db $db, private readonly string $root)
    {
    }

    public static function valid(string $file): bool
    {
        return preg_match('/^slide-[0-9a-f]{12}\.jpg$/', $file) === 1;
    }

    /** A picture's address, relative to the app. */
    public static function path(string $file): string
    {
        return 'storage/slides/' . $file;
    }

    private function dir(): string
    {
        return $this->root . '/storage/slides';
    }

    /**
     * Keep a picture. @return array{file?: string, url?: string, width?: int, height?: int, error?: string}
     */
    public function store(string $bytes): array
    {
        if ($bytes === '') {
            return ['error' => 'No picture came through.'];
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return ['error' => 'A slide must be under ' . round(self::MAX_BYTES / 1_000_000, 1) . ' MB.'];
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] < 16 || $info[1] < 16 || $info[0] > self::MAX_PIXELS || $info[1] > self::MAX_PIXELS) {
            return ['error' => 'A slide must be a JPEG picture.'];
        }
        $file = 'slide-' . substr(sha1($bytes), 0, 12) . '.jpg';
        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Can't make $dir.");
        }
        if (!is_file("$dir/$file")) {
            if (file_put_contents("$dir/$file", $bytes) === false) {
                throw new \RuntimeException("Can't write $dir/$file.");
            }
            @chmod("$dir/$file", 0644);
        }
        return ['file' => $file, 'url' => self::path($file), 'width' => (int) $info[0], 'height' => (int) $info[1]];
    }

    /** Drop pictures that no note names and that are over a day old. @return int how many */
    public function cleanup(float $now): int
    {
        $files = glob($this->dir() . '/slide-*.jpg') ?: [];
        if (!$files) {
            return 0;
        }
        $used = [];
        foreach ($this->db->run('SELECT body FROM ' . $this->db->table('presenter_notes') . " WHERE kind = 'slides'")->fetchAll(\PDO::FETCH_COLUMN) as $body) {
            foreach ((json_decode((string) $body, true)['images'] ?? []) as $image) {
                $used[(string) $image] = true;
            }
        }
        $n = 0;
        foreach ($files as $path) {
            if (!isset($used[basename($path)]) && @filemtime($path) < $now - 86400 && @unlink($path)) {
                $n++;
            }
        }
        return $n;
    }
}
