<?php
declare(strict_types=1);

namespace Stationwagon;

use Throwable;

/**
 * Each song file's peaks for the listeners' output strip (round 87). The strip draws the coming 20 s; it used to read
 * the song's audio again for that, in pieces, and while a deck's own download of the song was still open (the browser
 * pauses a long download without closing it) those pieces came over the network a second time. Now it draws from a
 * small file the station makes once per song file: the highest sample of every 10 ms, one byte each (about 6 KB a
 * minute; the format is in assets/prep-core.js, SwPrepCore.peaks). Kept in storage/peaks/<song file>.peaks, private,
 * and served by api/peaks.php at peaks/<song file>.bin, which never changes (a new song file is a new name).
 *
 * Every song file gets them the same way, whatever brought it in (an upload, an ad, a jingle, Apply, Re-level): a
 * station that can run Node makes the missing ones in the background (fill(), from cli/prep.php: cron every minute,
 * and started by the background jobs when some are missing); one that can't has the manager's browser make them
 * while a library page is open (assets/peaks-fill.js, ManagePage's peaks actions). A song without peaks yet is drawn
 * as before, from its audio.
 */
final class Peaks
{
    public const STEP_MS = 10;
    private const FAILED_WAIT = 86400; // a file Node couldn't read is tried again a day later

    /** The song file's name without .mp3, when it is a plain song file name; else null. */
    public static function base(string $file): ?string
    {
        return preg_match('/^([A-Za-z0-9._-]+)\.mp3$/', $file, $m) ? $m[1] : null;
    }

    public static function dir(string $root): string
    {
        return $root . '/storage/peaks';
    }

    public static function path(string $root, string $file): ?string
    {
        $b = self::base($file);
        return $b === null ? null : self::dir($root) . '/' . $b . '.peaks';
    }

    /** The address the player reads them at (relative to the app root). */
    public static function url(string $file): ?string
    {
        $b = self::base($file);
        return $b === null ? null : 'peaks/' . $b . '.bin';
    }

    public static function has(string $root, string $file): bool
    {
        $p = self::path($root, $file);
        return $p !== null && is_file($p);
    }

    /**
     * Whether $bytes are peaks in the station's format, for a song of about $duration seconds (when given). Returns
     * null when they are, else what is wrong.
     */
    public static function invalid(string $bytes, ?float $duration = null): ?string
    {
        if (strlen($bytes) < 17 || substr($bytes, 0, 4) !== 'SWPK') {
            return 'not a peaks file';
        }
        if (ord($bytes[4]) !== 1 || ord($bytes[5]) !== self::STEP_MS) {
            return 'an unknown peaks version';
        }
        $n = unpack('V', substr($bytes, 8, 4))[1];
        if ($n !== strlen($bytes) - 16) {
            return 'the peaks are cut short';
        }
        if ($duration !== null && $duration > 0 && abs($n * self::STEP_MS / 1000 - $duration) > max(1.0, $duration * 0.02)) {
            return sprintf('the peaks are %.1f s long, the song %.1f s', $n * self::STEP_MS / 1000, $duration);
        }
        return null;
    }

    /** Keep a song file's peaks (checked first). Returns null when kept, else why not. */
    public static function save(string $root, string $file, string $bytes, ?float $duration = null): ?string
    {
        $p = self::path($root, $file);
        if ($p === null || !is_file($root . '/storage/media/' . $file)) {
            return 'there is no such song file';
        }
        if (($bad = self::invalid($bytes, $duration)) !== null) {
            return $bad;
        }
        if (!is_dir(self::dir($root)) && !@mkdir(self::dir($root), 0775, true) && !is_dir(self::dir($root))) {
            return 'storage/peaks can’t be made';
        }
        $tmp = $p . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $bytes) !== strlen($bytes) || !@rename($tmp, $p)) {
            @unlink($tmp);
            return 'the peaks couldn’t be saved';
        }
        return null;
    }

    /** A song file is gone: its peaks go too. */
    public static function drop(string $root, string $file): void
    {
        $p = self::path($root, $file);
        if ($p !== null) {
            @unlink($p);
        }
    }

    /**
     * Song files in use that have no peaks yet, newest first: [{ file, duration }]. Files Node couldn't read lately
     * are left out ($skipFailed), and the files in $except (a browser couldn't make theirs).
     * @return list<array{file:string,duration:float}>
     */
    public static function missing(Db $db, string $root, int $limit = 50, bool $skipFailed = true, array $except = []): array
    {
        $failed = ($skipFailed ? self::failed($root) : []) + array_fill_keys($except, 1);
        $rows = $db->run('SELECT file, MAX(duration_s) AS d, MAX(id) AS last FROM ' . $db->table('tracks')
            . " WHERE status = 'ready' GROUP BY file ORDER BY last DESC")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $f = (string) $r['file'];
            if (self::base($f) === null || isset($failed[$f]) || self::has($root, $f) || !is_file($root . '/storage/media/' . $f)) {
                continue;
            }
            $out[] = ['file' => $f, 'duration' => (float) $r['d']];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** How long a song file is, as the library has it (the longest of its tracks), or null when no track has it. */
    public static function durationOf(Db $db, string $file): ?float
    {
        $d = $db->run('SELECT MAX(duration_s) FROM ' . $db->table('tracks') . ' WHERE file = ?', [$file])->fetchColumn();
        return $d === null || $d === false ? null : (float) $d;
    }

    /** Whether some song files wait for their peaks (the background jobs then start the worker). */
    public static function due(Db $db, string $root): bool
    {
        try {
            return self::missing($db, $root, 1) !== [];
        } catch (Throwable $ex) {
            return false; // before the tables exist
        }
    }

    private static function failedPath(string $root): string
    {
        return $root . '/storage/cache/peaks-failed.json';
    }

    /** @return array<string,int> file => when Node last failed on it, within the wait */
    private static function failed(string $root): array
    {
        $j = @file_get_contents(self::failedPath($root));
        $list = is_string($j) ? (json_decode($j, true) ?: []) : [];
        return array_filter(is_array($list) ? $list : [], static fn ($at) => is_int($at) && $at > time() - self::FAILED_WAIT);
    }

    /**
     * Make the missing peaks with Node, newest songs first, for about $budget seconds. Returns what it did (one line
     * each; [] when there was nothing to do, no Node, or another copy works). $node overrides the station's (tests).
     * @return list<string>
     */
    public static function fill(Db $db, string $root, ?string $node = null, int $budget = 40): array
    {
        $node ??= NodeRuntime::ready();
        if ($node === null || !NodeRuntime::canStartPrograms()) {
            return [];
        }
        $cache = $root . '/storage/cache';
        if (!is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }
        $lock = @fopen($cache . '/peaks.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return [];
        }
        try {
            $todo = self::missing($db, $root, 200);
            if (!$todo) {
                return [];
            }
            if (!is_dir(self::dir($root))) {
                @mkdir(self::dir($root), 0775, true);
            }
            $files = [];
            foreach ($todo as $t) {
                $files[] = ['in' => $root . '/storage/media/' . $t['file'], 'out' => self::path($root, $t['file'])];
            }
            $list = $cache . '/peaks-list.json';
            file_put_contents($list, json_encode(['until' => (int) ((microtime(true) + $budget) * 1000), 'files' => $files], JSON_UNESCAPED_SLASHES));
            [$code, $out, $err] = NodeRuntime::run([$node, SW_ROOT . '/cli/prep-node.mjs', '--peaks', $list], $budget + 60);
            @unlink($list);
            $r = json_decode(trim($out), true);
            if ($code !== 0 || !is_array($r)) {
                return ['Song waveforms: Node stopped (' . trim(substr($err !== '' ? $err : $out, 0, 300)) . ')'];
            }
            $lines = [];
            if (($r['made'] ?? 0) > 0) {
                $lines[] = sprintf('%d song waveform(s) made for the listeners’ player%s', $r['made'], ($r['left'] ?? 0) > 0 ? ', more to come' : '');
            }
            if (!empty($r['failed'])) {
                $failed = self::failed($root);
                foreach ($r['failed'] as $f) {
                    $name = basename((string) ($f['in'] ?? ''));
                    $failed[$name] = time();
                    $lines[] = 'Song waveform not made for ' . $name . ': ' . ($f['error'] ?? 'unknown');
                }
                @file_put_contents(self::failedPath($root), json_encode($failed));
            }
            return $lines;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
