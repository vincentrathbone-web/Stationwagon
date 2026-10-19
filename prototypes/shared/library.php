<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/mp3.php';

/**
 * Scans media/ for MP3s and caches each file's piece index in data/library.json.
 * A file is re-scanned only when its size or modified time changes.
 */
function sw_library(): array
{
    $config = sw_config();
    $cachePath = $config['data_dir'] . '/library.json';

    return sw_with_lock('library', function () use ($config, $cachePath) {
        $cache = sw_read_json($cachePath, []);
        $tracks = [];
        $changed = false;
        foreach (glob($config['media_dir'] . '/*') ?: [] as $path) {
            if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'mp3') {
                continue;
            }
            $file = basename($path);
            $stamp = filesize($path) . ':' . filemtime($path);
            if (!isset($cache[$file]) || $cache[$file]['stamp'] !== $stamp
                || $cache[$file]['piece_seconds'] !== $config['segment_seconds']) {
                $scan = sw_mp3_scan($path, $config['segment_seconds']);
                if ($scan['frames'] === 0) {
                    continue; // not a usable MP3
                }
                $cache[$file] = [
                    'id'            => substr(sha1($file), 0, 10),
                    'file'          => $file,
                    'title'         => sw_title_from_filename($file),
                    'stamp'         => $stamp,
                    'piece_seconds' => $config['segment_seconds'],
                ] + $scan;
                $changed = true;
            }
            $tracks[$file] = $cache[$file];
        }
        if ($changed || count($tracks) !== count($cache)) {
            sw_write_json($cachePath, $tracks);
        }
        return array_values($tracks);
    });
}

function sw_title_from_filename(string $file): string
{
    $name = preg_replace('/\.mp3$/i', '', $file);
    $name = trim(preg_replace('/[_]+/', ' ', $name));
    return $name === '' ? $file : $name;
}
