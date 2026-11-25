<?php
declare(strict_types=1);

namespace Stationwagon;

use RuntimeException;
use Throwable;

/**
 * Uploads prepared on the server (round 85, v1.13), so a bulk upload goes on when the page is closed. Used when the
 * station's Node is set up (NodeRuntime::ready); otherwise songs are prepared in the browser as before.
 *
 *  1. begin: the page sends a picked file's name, size and SHA-1. The answer: already in the library, already being
 *     prepared, or "send from byte N" (a transfer cut off earlier carries on where it stopped).
 *  2. chunk: the file arrives in pieces of up to 8 MB (under Cloudflare's and PHP's 100 MB), each at the offset the
 *     server has; storage/incoming/<id>.part.
 *  3. finish: the size and SHA-1 are checked; it becomes <id>.src and waits in the queue. The worker is started.
 *  4. cli/prep.php (one at a time, its own lock) runs cli/prep-node.mjs on it with the station's prep settings as they
 *     were when it was picked, then adds the song as the upload action does (Library::import, the Ads or Jingles row,
 *     the details window's choices, the original kept) and replans.
 * A job whose details window is still open (hold) is prepared but added only when its details arrive. Every device
 * sees the list (the Music library, Ads and Jingles screens) with each job's stage and progress.
 */
final class PrepQueue
{
    public const CHUNK = 8 * 1024 * 1024;
    public const MAX_BYTES = 1024 * 1024 * 1024;    // a raw upload; prep-node.mjs has its own limits per format
    public const KEEP_PARTIAL = 2 * 86400;          // a transfer that stopped: dropped after 2 days
    public const KEEP_FAILED = 7 * 86400;           // a failed or cancelled job's files: 7 days (Try again)
    public const KEEP_DONE = 86400;                 // a finished row, so other devices see it finish
    public const STALE = 1800;                      // a "working" job not touched for 30 min (the worker died)
    public const ATTEMPTS = 2;
    public const REDO_WAIT = 3 * 3600;              // a song made again but on air: tried again for up to 3 hours
    public const REDO_RETRY = 15;                   // ... every 15 seconds
    public static ?float $clock = null;             // tests: the time a made-again song is put in place at
    public const KINDS = ['song', 'ad', 'jingle'];
    private const AUDIO = '/\.(mp3|wav|flac|m4a|mp4|aac|ogg|oga|opus|wma)$/i';

    public function __construct(private readonly Db $db, private readonly array $station, private readonly string $root)
    {
    }

    public function dir(): string
    {
        return $this->root . '/storage/incoming';
    }

    private function t(): string
    {
        return $this->db->table('prep_jobs');
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public function get(int $id): ?array
    {
        $row = $this->db->run("SELECT * FROM {$this->t()} WHERE id = ? AND station_id = ?", [$id, $this->station['id']])->fetch();
        return $row ?: null;
    }

    private function set(int $id, array $values): void
    {
        $values['updated_at'] = self::now();
        $cols = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($values)));
        $this->db->run("UPDATE {$this->t()} SET $cols WHERE id = ?", [...array_values($values), $id]);
    }

    /** The station's prep settings now, as the upload form has them (kept with each job). */
    public function settingsNow(): array
    {
        [$master, $level, $target] = StationSettings::prep($this->station);
        [$presetName, $presetSettings] = (new PrepPresets($this->db, $this->station))->forImport();
        $q = StationSettings::prepQuality($this->station);
        return ['kbps' => $q['kbps'], 'quality' => ['rate' => $q['rate'], 'precision' => $q['precision'], 'dither' => $q['dither'], 'q' => $q['q']],
            'master' => $master ? (is_array($presetSettings) && $presetSettings !== [] ? $presetSettings : new \stdClass()) : false,
            'level' => $level, 'target' => $target, 'preset' => $presetName];
    }

    private function path(int $id, string $ext): string
    {
        return $this->dir() . "/$id.$ext";
    }

    private function ensureDir(): void
    {
        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Couldn’t make storage/incoming.');
        }
        if (!is_file("$dir/.htaccess")) {
            @file_put_contents("$dir/.htaccess", "# Uploads waiting to be prepared (never served)\nRequire all denied\n<FilesMatch \".\">\n  Require all denied\n</FilesMatch>\n");
        }
    }

    /**
     * Step 1. $hold: the details window is open for it (the song waits for its details).
     * @return array{state:string,id?:int,received?:int,chunk?:int,title?:string,error?:string}
     */
    public function begin(?int $userId, string $kind, string $name, int $bytes, string $sha1, bool $hold = false): array
    {
        $kind = in_array($kind, self::KINDS, true) ? $kind : 'song';
        $name = trim(str_replace(["\0", '/', '\\'], '', $name));
        $sha1 = strtolower($sha1);
        if ($name === '' || preg_match(self::AUDIO, $name) !== 1) {
            return ['state' => 'refused', 'error' => 'Only MP3, WAV, FLAC, M4A, AAC, Ogg, Opus or WMA files.'];
        }
        if ($bytes <= 0 || $bytes > self::MAX_BYTES) {
            return ['state' => 'refused', 'error' => $bytes <= 0 ? 'The file is empty.' : 'Bigger than the station takes (' . megabytes(self::MAX_BYTES) . ').'];
        }
        if (preg_match('/^[0-9a-f]{40}$/', $sha1) !== 1) {
            return ['state' => 'refused', 'error' => 'The file’s fingerprint didn’t arrive.'];
        }
        // already in this station's clips: as it came (its hash is the song's), or made from it before (a converted one)
        $tr = $this->db->table('tracks');
        $have = $this->db->run("SELECT id, title FROM $tr WHERE station_id = ? AND (sha1 = ? OR file = ? OR upload_sha1 = ?) LIMIT 1", [$this->station['id'], $sha1, "$sha1.mp3", $sha1])->fetch()
            ?: $this->db->run("SELECT t.id, t.title FROM {$this->t()} j JOIN $tr t ON t.id = j.track_id WHERE j.station_id = ? AND j.sha1 = ? AND j.state = 'done' LIMIT 1",
                [$this->station['id'], $sha1])->fetch();
        if ($have) {
            return ['state' => 'have', 'track' => (int) $have['id'], 'title' => (string) $have['title']];
        }
        $job = $this->db->run("SELECT * FROM {$this->t()} WHERE station_id = ? AND sha1 = ? AND kind = ? AND state IN ('uploading','queued','working','prepared') ORDER BY id DESC LIMIT 1",
            [$this->station['id'], $sha1, $kind])->fetch();
        if ($job) {
            if ($job['state'] === 'uploading') {
                $have = is_file($this->path((int) $job['id'], 'part')) ? (int) filesize($this->path((int) $job['id'], 'part')) : 0;
                if ($have !== (int) $job['received']) {
                    $this->set((int) $job['id'], ['received' => $have]);
                }
                if ($hold) {
                    $this->set((int) $job['id'], ['hold' => 1]);
                }
                return ['state' => 'uploading', 'id' => (int) $job['id'], 'received' => $have, 'chunk' => self::CHUNK];
            }
            return ['state' => (string) $job['state'], 'id' => (int) $job['id']];
        }
        $this->ensureDir();
        $id = $this->db->insert('prep_jobs', [
            'station_id' => $this->station['id'], 'user_id' => $userId, 'kind' => $kind, 'name' => mb_substr($name, 0, 255),
            'bytes' => $bytes, 'received' => 0, 'sha1' => $sha1, 'state' => 'uploading', 'hold' => $hold ? 1 : 0,
            'opts' => json_encode($this->settingsNow(), JSON_UNESCAPED_SLASHES), 'created_at' => self::now(), 'updated_at' => self::now(),
        ]);
        @file_put_contents($this->path($id, 'part'), '');
        return ['state' => 'uploading', 'id' => $id, 'received' => 0, 'chunk' => self::CHUNK];
    }

    /**
     * Step 2: a piece at $offset. A piece at another offset than the server has isn't kept; the answer says where to go on.
     * @return array{ok:bool,received:int,error?:string}
     */
    public function chunk(int $id, int $offset, string $data): array
    {
        $job = $this->get($id);
        if ($job === null || $job['state'] !== 'uploading') {
            return ['ok' => false, 'received' => 0, 'error' => $job === null ? 'That upload isn’t here any more. Pick the file again.' : 'That upload has already arrived.'];
        }
        $part = $this->path($id, 'part');
        clearstatcache(true, $part);
        $have = is_file($part) ? (int) filesize($part) : 0;
        if ($offset !== $have || $data === '' || strlen($data) > self::CHUNK + 65536 || $have + strlen($data) > (int) $job['bytes']) {
            return ['ok' => false, 'received' => $have, 'error' => $offset !== $have ? 'out of step' : 'That piece doesn’t fit the file.'];
        }
        $fh = @fopen($part, 'ab');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            return ['ok' => false, 'received' => $have, 'error' => 'Couldn’t write in storage/incoming.'];
        }
        clearstatcache(true, $part);
        if ((int) filesize($part) !== $offset) { // another request added this piece meanwhile
            flock($fh, LOCK_UN);
            fclose($fh);
            clearstatcache(true, $part);
            return ['ok' => false, 'received' => (int) filesize($part), 'error' => 'out of step'];
        }
        $wrote = fwrite($fh, $data);
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        if ($wrote !== strlen($data)) {
            @ftruncate(fopen($part, 'r+'), $offset);
            return ['ok' => false, 'received' => $offset, 'error' => 'The server’s disk is full.'];
        }
        $received = $offset + $wrote;
        $this->set($id, ['received' => $received]);
        return ['ok' => true, 'received' => $received];
    }

    /** Step 3: the whole file has arrived. @return array{ok:bool,error?:string,received?:int} */
    public function finish(int $id): array
    {
        $job = $this->get($id);
        if ($job === null) {
            return ['ok' => false, 'error' => 'That upload isn’t here any more. Pick the file again.'];
        }
        if ($job['state'] !== 'uploading') {
            return ['ok' => true];
        }
        $part = $this->path($id, 'part');
        clearstatcache(true, $part);
        $size = is_file($part) ? (int) filesize($part) : 0;
        if ($size !== (int) $job['bytes']) {
            return ['ok' => false, 'error' => 'Not all of it has arrived yet.', 'received' => $size];
        }
        if (sha1_file($part) !== $job['sha1']) {
            @unlink($part);
            @file_put_contents($part, '');
            $this->set($id, ['received' => 0]);
            return ['ok' => false, 'error' => 'The file didn’t arrive whole, so it is being sent again.', 'received' => 0];
        }
        if (!@rename($part, $this->path($id, 'src'))) {
            return ['ok' => false, 'error' => 'Couldn’t keep the upload in storage/incoming.'];
        }
        $this->set($id, ['state' => 'queued', 'stage' => null]);
        return ['ok' => true];
    }

    /**
     * The details window's choices (round 56's shape, ManagePage::detailsPosted); $art: a new picture's bytes. When the
     * song is waiting for them it is added now; when it was added already they are put on it.
     * @return array{ok:bool,error?:string,track?:int,note?:string}
     */
    public function details(int $id, array $details, ?string $art, Planner $planner): array
    {
        $job = $this->get($id);
        if ($job === null || in_array($job['state'], ['failed', 'cancelled'], true)) {
            return ['ok' => false, 'error' => $job === null ? 'That upload isn’t here any more.' : 'That upload didn’t go through: ' . ($job['error'] ?? 'it was cancelled') . '.'];
        }
        if ($art !== null) {
            $this->ensureDir();
            file_put_contents($this->path($id, 'art'), $art);
            $details['art'] = 'new';
        }
        $json = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($job['state'] === 'done' && $job['track_id']) {
            $note = $this->applyDetails((int) $job['track_id'], $details, $id);
            $this->set($id, ['details' => $json, 'hold' => 0]);
            return ['ok' => true, 'track' => (int) $job['track_id']] + ($note !== null ? ['note' => $note] : []);
        }
        $this->set($id, ['details' => $json, 'hold' => 0]);
        if ($job['state'] === 'prepared') {
            return $this->add($id, $planner);
        }
        return ['ok' => true];
    }

    /** Cancel (the window's Skip, or Cancel in the list). A job being prepared stops when Node is done with it. */
    public function cancel(int $id): bool
    {
        $job = $this->get($id);
        if ($job === null || in_array($job['state'], ['done', 'cancelled'], true)) {
            return false;
        }
        $this->set($id, ['state' => 'cancelled', 'error' => 'cancelled', 'finished_at' => self::now()]);
        if ($job['state'] !== 'working') {
            $this->dropFiles($id);
        }
        return true;
    }

    /** Try again (a failed job whose file is still here). */
    public function retry(int $id): bool
    {
        $job = $this->get($id);
        if ($job === null || $job['state'] !== 'failed' || ((int) $job['redo'] ? $this->redoSource($job) === null : !is_file($this->path($id, 'src')))) {
            return false;
        }
        $this->set($id, ['state' => 'queued', 'attempts' => 0, 'error' => null, 'stage' => null, 'finished_at' => null]);
        return true;
    }

    /** Remove a finished, failed or cancelled job from the list (and its files). */
    public function remove(int $id): bool
    {
        $job = $this->get($id);
        if ($job === null || in_array($job['state'], ['queued', 'working'], true)) {
            return false;
        }
        $this->dropFiles($id);
        $this->db->run("DELETE FROM {$this->t()} WHERE id = ?", [$id]);
        return true;
    }

    /** "Add with the file's details": a song whose window was closed with the page waits no longer. */
    public function release(int $id, Planner $planner): array
    {
        $job = $this->get($id);
        if ($job === null || !(int) $job['hold']) {
            return ['ok' => false, 'error' => 'That upload isn’t waiting for its details.'];
        }
        $this->set($id, ['hold' => 0]);
        return $job['state'] === 'prepared' ? $this->add($id, $planner) : ['ok' => true];
    }

    private function dropFiles(int $id): void
    {
        foreach (['part', 'src', 'art'] as $ext) {
            @unlink($this->path($id, $ext));
        }
        NodeRuntime::rmTree($this->dir() . "/$id");
        @unlink($this->dir() . "/$id.progress");
    }

    /** Uploads not added yet, and those finished in the last day, for the list. @return list<array> */
    public function list(?string $kind = null): array
    {
        $sql = "SELECT j.*, u.name AS who FROM {$this->t()} j LEFT JOIN {$this->db->table('users')} u ON u.id = j.user_id WHERE j.station_id = ? AND j.redo = 0";
        $args = [$this->station['id']];
        if ($kind !== null) {
            $sql .= ' AND j.kind = ?';
            $args[] = $kind;
        }
        $rows = $this->db->run("$sql ORDER BY j.id", $args)->fetchAll();
        $node = NodeRuntime::ready() !== null;
        return array_map(function (array $j) use ($node): array {
            $progress = null;
            $stage = $j['stage'];
            if ($j['state'] === 'working') {
                $p = json_decode((string) @file_get_contents($this->dir() . "/{$j['id']}.progress"), true);
                if (is_array($p)) {
                    $stage = (string) ($p['stage'] ?? $stage);
                    $progress = isset($p['progress']) && is_numeric($p['progress']) ? (float) $p['progress'] : null;
                }
            } elseif ($j['state'] === 'uploading') {
                $progress = (int) $j['bytes'] > 0 ? (int) $j['received'] / (int) $j['bytes'] : 0;
            }
            $result = json_decode((string) $j['result'], true);
            return [
                'id' => (int) $j['id'], 'kind' => $j['kind'], 'name' => $j['name'], 'bytes' => (int) $j['bytes'],
                'state' => $j['state'], 'stage' => $stage, 'progress' => $progress, 'hold' => (bool) $j['hold'],
                'who' => $j['who'], 'error' => $j['error'], 'track' => $j['track_id'] !== null ? (int) $j['track_id'] : null,
                'result' => is_array($result) ? $result : null, 'created' => $j['created_at'], 'updated' => $j['updated_at'],
                // stopped part-way: only the person with the file can finish it (pick it again); else it waits for Node
                'stale' => $j['state'] === 'uploading' && strtotime($j['updated_at'] . ' UTC') < time() - 120,
                'waiting_for_node' => $j['state'] === 'queued' && !$node,
            ];
        }, $rows);
    }

    /** How many uploads aren't added yet (for the Overview). */
    public function pending(): int
    {
        return (int) $this->db->run("SELECT COUNT(*) FROM {$this->t()} WHERE station_id = ? AND redo = 0 AND state IN ('uploading','queued','working','prepared')",
            [$this->station['id']])->fetchColumn();
    }

    // ---- The worker (cli/prep.php) -------------------------------------------------------------

    public static function lockPath(string $root): string
    {
        return $root . '/storage/cache/prep.lock';
    }

    /** Whether a worker holds the lock now. */
    public static function working(string $root): bool
    {
        $fh = @fopen(self::lockPath($root), 'c');
        if ($fh === false) {
            return false;
        }
        $free = flock($fh, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($fh, LOCK_UN);
        }
        fclose($fh);
        return !$free;
    }

    /** Whether any station's queue has something to prepare (or a stale job to look at). */
    public static function due(Db $db): bool
    {
        $t = $db->table('prep_jobs');
        try {
            return (bool) $db->run("SELECT 1 FROM $t WHERE state = 'queued' OR (state = 'working' AND updated_at < ?) OR (redo = 1 AND state = 'prepared' AND updated_at < ?) LIMIT 1",
                [gmdate('Y-m-d H:i:s', time() - self::STALE), gmdate('Y-m-d H:i:s', time() - self::REDO_RETRY)])->fetchColumn();
        } catch (Throwable) {
            return false; // the table isn't there yet (before the upgrade)
        }
    }

    /** The PHP program for cli/prep.php (FPM's own binary can't run scripts). */
    public static function phpCli(): ?string
    {
        if (is_string($c = Config::get('php_cli')) && $c !== '' && is_file($c)) {
            return $c;
        }
        if (PHP_SAPI === 'cli' && PHP_BINARY !== '') {
            return PHP_BINARY;
        }
        $v = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $c = [];
        if (($ini = php_ini_loaded_file()) !== false) {
            $c[] = dirname($ini) . (NodeRuntime::isWindows() ? '\\php.exe' : '/php');
        }
        array_push($c, PHP_BINDIR . "/php$v", PHP_BINDIR . '/php', "/usr/bin/php$v", "/usr/local/bin/php$v", '/usr/local/bin/php', '/usr/bin/php');
        foreach ($c as $p) {
            if (is_file($p) && (NodeRuntime::isWindows() || is_executable($p))) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Start cli/prep.php in the background when something waits and no worker runs (after an upload arrives, and from
     * the background jobs). Returns whether one was started.
     */
    public static function kick(Db $db, string $root, bool $force = false): bool
    {
        if (NodeRuntime::ready() === null || !NodeRuntime::canStartPrograms() || (!$force && !self::due($db)) || self::working($root)) {
            return false;
        }
        $php = self::phpCli();
        if ($php === null) {
            return false;
        }
        $script = SW_ROOT . '/cli/prep.php';
        if (NodeRuntime::isWindows()) {
            $h = @popen('start "" /B /LOW ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' > NUL 2>&1', 'r');
            if ($h === false) {
                return false;
            }
            pclose($h);
            return true;
        }
        $nice = is_file('/usr/bin/nice') || is_file('/bin/nice') ? 'nice -n 19 ' : '';
        $setsid = is_file('/usr/bin/setsid') || is_file('/bin/setsid') ? 'setsid ' : '';
        $p = @proc_open(['/bin/sh', '-c', $setsid . 'nohup ' . $nice . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($p)) {
            return false;
        }
        proc_close($p);
        return true;
    }

    /**
     * The worker: take the queue's lock and prepare every waiting upload, oldest first, until none is left. Returns
     * what it did (one line each), or [] when another worker already runs. $node overrides the station's Node (tests).
     * @return list<string>
     */
    public static function work(Db $db, string $root, ?string $node = null, ?callable $log = null): array
    {
        $dir = dirname(self::lockPath($root));
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $lock = @fopen(self::lockPath($root), 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return [];
        }
        $out = [];
        try {
            $station = Streams::main($db);
            $q = new self($db, $station, $root);
            $q->recover();
            while (($node ??= NodeRuntime::ready()) !== null && ($job = $q->next()) !== null) {
                $line = $q->prepare($job, $node);
                $out[] = $line;
                if ($log) {
                    $log($line);
                }
            }
            foreach ($q->retryWaiting() as $line) {
                $out[] = $line;
                if ($log) {
                    $log($line);
                }
            }
            $q->cleanup(time());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $out;
    }

    /** A job left "working" by a worker that died (the lock is ours now): queued again, or failed after its tries. */
    private function recover(): void
    {
        foreach ($this->db->run("SELECT id, attempts FROM {$this->t()} WHERE station_id = ? AND state = 'working'", [$this->station['id']])->fetchAll() as $j) {
            if ((int) $j['attempts'] >= self::ATTEMPTS) {
                $this->set((int) $j['id'], ['state' => 'failed', 'error' => 'Preparing it stopped twice (the server may be short of memory for a song this long).', 'finished_at' => self::now()]);
            } else {
                $this->set((int) $j['id'], ['state' => 'queued']);
            }
        }
    }

    /** The oldest waiting upload, marked working (null when none). */
    private function next(): ?array
    {
        $job = $this->db->run("SELECT * FROM {$this->t()} WHERE station_id = ? AND state = 'queued' ORDER BY id LIMIT 1", [$this->station['id']])->fetch();
        if (!$job) {
            return null;
        }
        $taken = $this->db->run("UPDATE {$this->t()} SET state = 'working', stage = 'Starting', attempts = attempts + 1, updated_at = ? WHERE id = ? AND state = 'queued'",
            [self::now(), $job['id']])->rowCount();
        return $taken ? $this->get((int) $job['id']) : $this->next();
    }

    /** Prepare one upload with Node, then add it (or keep it for its details). Returns a line for the log. */
    private function prepare(array $job, string $node): string
    {
        $id = (int) $job['id'];
        $redo = (int) $job['redo'] === 1;
        $src = $redo ? $this->redoSource($job) : $this->path($id, 'src');
        if ($src === null) {
            $this->set($id, ['state' => 'failed', 'error' => 'The station doesn’t have this song’s original any more, so it can’t be made again.', 'finished_at' => self::now()]);
            return "#$id {$job['name']}: no original";
        }
        $work = $this->dir() . "/$id";
        NodeRuntime::rmTree($work);
        @mkdir($work, 0775, true);
        $opts = json_decode((string) $job['opts'], true) ?: $this->settingsNow();
        file_put_contents("$work/job.json", json_encode(['in' => $src, 'name' => $job['name'], 'out' => $work,
            'progress' => $this->dir() . "/$id.progress", 'opts' => $opts], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $cmd = [$node, SW_ROOT . '/cli/prep-node.mjs', "$work/job.json"]; // the code is the app's; the storage may be elsewhere (tests)
        if (!NodeRuntime::isWindows() && is_file('/usr/bin/nice')) {
            array_unshift($cmd, '/usr/bin/nice', '-n', '19');
        }
        // keep the job's row fresh while Node works, so it isn't taken for a dead one
        $t0 = microtime(true);
        [$code, , $err] = $this->runWatched($cmd, $id);
        $took = microtime(true) - $t0;
        $r = json_decode((string) @file_get_contents("$work/result.json"), true);
        @unlink($this->dir() . "/$id.progress");
        $now = $this->get($id);
        if ($now === null || $now['state'] === 'cancelled') {
            $this->dropFiles($id);
            return "#$id {$job['name']}: cancelled";
        }
        if ($code !== 0 || !is_array($r)) {
            $why = trim($err) !== '' ? mb_substr(trim((string) preg_replace('/\s+/', ' ', $err)), 0, 300) : 'Node stopped (exit ' . $code . ')';
            if ((int) $now['attempts'] < self::ATTEMPTS) {
                $this->set($id, ['state' => 'queued', 'stage' => null]);
                return "#$id {$job['name']}: failed, will try again ($why)";
            }
            $this->set($id, ['state' => 'failed', 'error' => 'Preparing it failed: ' . $why, 'finished_at' => self::now()]);
            return "#$id {$job['name']}: failed ($why)";
        }
        if (!empty($r['refused'])) {
            $this->set($id, ['state' => 'failed', 'error' => 'This file can’t be added: ' . ($r['note'] ?? 'not audio') . '.', 'result' => json_encode($r), 'finished_at' => self::now()]);
            return "#$id {$job['name']}: refused ({$r['note']})";
        }
        if ($redo && empty($r['prepared'])) {
            $this->set($id, ['state' => 'failed', 'error' => 'Song prep didn’t run: ' . ($r['note'] ?? '?') . '.', 'stage' => null, 'result' => json_encode(self::slim($r)), 'finished_at' => self::now()]);
            return "#$id {$job['name']}: not made again (" . ($r['note'] ?? '?') . ')';
        }
        $this->set($id, ['state' => 'prepared', 'stage' => null, 'result' => json_encode(self::slim($r) + ['took_s' => round($took, 1)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        if ($redo) {
            return "#$id {$job['name']}: made again in " . round($took, 1) . ' s, ' . $this->putInPlace($id);
        }
        if ((int) $now['hold']) {
            return "#$id {$job['name']}: prepared, waiting for its details";
        }
        $added = $this->add($id, null);
        return "#$id {$job['name']}: " . ($added['ok'] ? ($added['duplicate'] ?? false ? 'already there' : 'added') . ' in ' . round($took, 1) . ' s' : 'not added (' . ($added['error'] ?? '?') . ')');
    }

    /** What the list and the log keep of Node's answer. */
    private static function slim(array $r): array
    {
        return array_intersect_key($r, array_flip(['prepared', 'kept', 'note', 'notice', 'info', 'sourceSha1', 'name', 'kind', 'song', 'original']));
    }

    /** @return array{0:int,1:string,2:string} */
    private function runWatched(array $cmd, int $id): array
    {
        $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($p)) {
            return [-1, '', 'Node couldn’t be started.'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        $touched = microtime(true);
        $end = microtime(true) + 3600;
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $st = proc_get_status($p);
            if (!$st['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                $err .= (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($p);
                return [(int) $st['exitcode'], $out, $err];
            }
            if (microtime(true) - $touched > 20) {
                $touched = microtime(true);
                $this->db->run("UPDATE {$this->t()} SET updated_at = ? WHERE id = ? AND state = 'working'", [self::now(), $id]);
                $state = $this->db->run("SELECT state FROM {$this->t()} WHERE id = ?", [$id])->fetchColumn();
                if ($state === 'cancelled') {
                    proc_terminate($p, 9);
                }
            }
            if (microtime(true) > $end) {
                proc_terminate($p, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($p);
                return [-2, $out, 'It took longer than an hour.'];
            }
            usleep(100000);
        }
    }

    /**
     * Add a prepared upload as the upload action does (ManagePage::upload, then its "original" step).
     * @return array{ok:bool,error?:string,track?:int,duplicate?:bool,note?:string}
     */
    private function add(int $id, ?Planner $planner): array
    {
        $job = $this->get($id);
        if ($job === null || $job['state'] !== 'prepared') {
            return ['ok' => false, 'error' => 'That upload isn’t ready.'];
        }
        $r = json_decode((string) $job['result'], true) ?: [];
        $opts = json_decode((string) $job['opts'], true) ?: [];
        $work = $this->dir() . "/$id";
        $song = !empty($r['song']) && is_file("$work/song.out") ? "$work/song.out" : $this->path($id, 'src');
        $info = is_array($r['info'] ?? null) ? $r['info'] : [];
        $prepared = !empty($r['prepared']);
        $mastered = $prepared && !empty($info['mastered']);
        $level = $prepared ? ['source_lufs' => $info['lufs_before'] ?? null, 'gain_db' => $info['gain_db'] ?? null,
            'target' => $info['target'] ?? null, 'settings' => $mastered ? (is_array($opts['master'] ?? null) ? $opts['master'] : []) : null] : null;
        $library = new Library($this->db, $this->station, $this->root);
        $kind = (string) $job['kind'];
        try {
            $details = $kind === 'song' ? json_decode((string) $job['details'], true) : null;
            if (is_array($details) && ($problem = self::checkDetails($details)) !== null) {
                throw new RuntimeException($problem);
            }
            $sourceSha1 = isset($r['sourceSha1']) && is_string($r['sourceSha1']) ? strtolower($r['sourceSha1']) : null;
            $imp = $library->import($song, null, null, (string) ($r['name'] ?? $job['name']), $sourceSha1,
                $prepared && !empty($info['levelled']) && isset($info['lufs']) ? (float) $info['lufs'] : null,
                is_array($info['outro'] ?? null) ? $info['outro'] : null, $mastered, $mastered ? (string) ($opts['preset'] ?? '') : null, $level, $kind,
                (string) $job['sha1']); // the file as it was picked: a converted one is found by it later (round 87)
            $tid = (int) $imp['id'];
            if ($kind === 'ad' && !$imp['duplicate']) {
                (new Ads($this->db, $this->station))->create($tid);
            }
            if ($kind === 'jingle' && !$imp['duplicate']) {
                (new Jingles($this->db, $this->station))->create($tid);
            }
            $note = null;
            if (is_array($details) && !$imp['duplicate']) {
                $note = $this->applyDetails($tid, $details, $id);
            }
            // the file the song was made from, kept for Song prep (an MP3 itself, or the 320 kbps MP3 made from another format)
            $track = $library->get($tid);
            if ($track !== null && $library->original($track) === null) {
                $orig = !empty($r['original']) && is_file("$work/original.out") ? "$work/original.out" : $this->path($id, 'src');
                if (($problem = $library->keepOriginal($tid, $orig)) !== null) {
                    $note = trim(($note ?? '') . ' Original not kept: ' . $problem);
                }
            }
            $res = ($r ?: []) + ['duplicate' => $imp['duplicate'], 'title' => $library->get($tid)['title'] ?? $imp['title']] + ($note !== null ? ['added_note' => $note] : []);
            $this->set($id, ['state' => 'done', 'track_id' => $tid, 'result' => json_encode(self::slim($res) + array_intersect_key($res, ['duplicate' => 1, 'title' => 1, 'added_note' => 1, 'took_s' => 1]),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'finished_at' => self::now(), 'stage' => null]);
            foreach (['src', 'part', 'art'] as $ext) {
                @unlink($this->path($id, $ext));
            }
            NodeRuntime::rmTree($work);
            if (!$imp['duplicate']) {
                $this->replan();
            }
            return ['ok' => true, 'track' => $tid, 'duplicate' => $imp['duplicate']] + ($note !== null ? ['note' => $note] : []);
        } catch (RuntimeException $ex) {
            $this->set($id, ['state' => 'failed', 'error' => mb_substr($ex->getMessage(), 0, 500), 'finished_at' => self::now()]);
            return ['ok' => false, 'error' => $ex->getMessage()];
        }
    }

    private function replan(): void
    {
        $now = microtime(true);
        foreach (Streams::all($this->db) as $s) {
            Streams::planner($this->db, $s['main'] ? null : (string) $s['slug'])?->replan($now);
        }
    }

    /** The details window's choices, checked as ManagePage::detailsPosted does. */
    public static function checkDetails(array $d): ?string
    {
        if (trim((string) ($d['title'] ?? '')) === '') {
            return 'A song needs a title.';
        }
        return Rights::problem(is_array($d['rights'] ?? null) ? $d['rights'] : []);
    }

    /** Put the details window's choices on the song (ManagePage::applyDetails). Returns what couldn't be kept, or null. */
    private function applyDetails(int $tid, array $d, int $id): ?string
    {
        $library = new Library($this->db, $this->station, $this->root);
        $library->update($tid, trim((string) $d['title']), (string) ($d['artist'] ?? ''), (string) ($d['album'] ?? ''));
        $library->setRights($tid, is_array($d['rights'] ?? null) ? $d['rights'] : []);
        $art = in_array($d['art'] ?? 'keep', ['keep', 'remove', 'new'], true) ? (string) ($d['art'] ?? 'keep') : 'keep';
        if ($art === 'remove') {
            return $library->setArt($tid, null);
        }
        if ($art === 'new') {
            $file = $this->path($id, 'art');
            $note = is_file($file) ? $library->setArt($tid, (string) file_get_contents($file)) : 'The album art didn’t arrive.';
            @unlink($file);
            return $note;
        }
        return null;
    }

    // ---- Making a song again from its original (round 87, v1.14): Song prep's Apply and Re-level -----

    /**
     * Queue song $trackId to be made again from its kept original. $choice: master (false, or the mastering settings;
     * {} is the built-in chain), level, target, preset (the name to show), mastered (Song prep: always counted as
     * mastered). An earlier job for the same song that hasn't started is cancelled. @return array{ok:bool,id?:int,error?:string}
     */
    public function redo(?int $userId, int $trackId, array $choice): array
    {
        $library = new Library($this->db, $this->station, $this->root);
        $track = $library->get($trackId);
        if ($track === null || $track['kind'] !== 'song') {
            return ['ok' => false, 'error' => 'That song isn’t in this library.'];
        }
        if (($orig = $library->original($track)) === null) {
            return ['ok' => false, 'error' => 'The station doesn’t have this song’s original, so it can’t be prepared again. Upload it again instead.'];
        }
        $master = $choice['master'] ?? false;
        $master = is_array($master) || is_object($master) ? (object) (array) $master : false;
        $target = $choice['target'] ?? null;
        $opts = ['master' => $master, 'level' => (bool) ($choice['level'] ?? true),
            'target' => is_numeric($target) ? max(-30.0, min(-8.0, (float) $target)) : StationSettings::prep($this->station)[2],
            'preset' => $master === false ? null : mb_substr(trim((string) ($choice['preset'] ?? '')), 0, 60),
            'mastered' => !empty($choice['mastered'])] + $this->settingsNow();
        $this->db->run("UPDATE {$this->t()} SET state = 'cancelled', error = 'cancelled', finished_at = ?, updated_at = ? WHERE station_id = ? AND redo = 1 AND track_id = ? AND state IN ('queued','prepared')",
            [self::now(), self::now(), $this->station['id'], $trackId]);
        $this->ensureDir();
        $this->db->run("INSERT INTO {$this->t()} (station_id, user_id, kind, name, bytes, received, sha1, state, redo, opts, track_id, created_at, updated_at)
            VALUES (?, ?, 'song', ?, ?, ?, ?, 'queued', 1, ?, ?, ?, ?)", [
            $this->station['id'], $userId, mb_substr((string) $track['title'], 0, 255), (int) filesize($orig), (int) filesize($orig), (string) $track['sha1'],
            json_encode($opts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $trackId, self::now(), self::now(),
        ]);
        return ['ok' => true, 'id' => (int) $this->db->pdo->lastInsertId()];
    }

    /** The original a redo job is made from (null when it's gone). */
    private function redoSource(array $job): ?string
    {
        $library = new Library($this->db, $this->station, $this->root);
        $track = $job['track_id'] !== null ? $library->get((int) $job['track_id']) : null;
        return $track === null ? null : $library->original($track);
    }

    /** Where redo jobs $ids stand, for the page that asked for them. @return list<array> */
    public function redoStatus(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn(int $i) => $i > 0)));
        if (!$ids) {
            return [];
        }
        $rows = $this->db->run("SELECT * FROM {$this->t()} WHERE station_id = ? AND redo = 1 AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id',
            array_merge([$this->station['id']], $ids))->fetchAll();
        $node = NodeRuntime::ready() !== null;
        return array_map(function (array $j) use ($node): array {
            $progress = null;
            $stage = $j['stage'];
            if ($j['state'] === 'working') {
                $p = json_decode((string) @file_get_contents($this->dir() . "/{$j['id']}.progress"), true);
                if (is_array($p)) {
                    $stage = (string) ($p['stage'] ?? $stage);
                    $progress = isset($p['progress']) && is_numeric($p['progress']) ? (float) $p['progress'] : null;
                }
            }
            $r = json_decode((string) $j['result'], true);
            $info = is_array($r['info'] ?? null) ? $r['info'] : [];
            return ['id' => (int) $j['id'], 'track' => (int) $j['track_id'], 'state' => $j['state'], 'stage' => $stage, 'progress' => $progress,
                'error' => $j['error'], 'kept' => !empty($r['kept']), 'waiting_for_node' => $j['state'] === 'queued' && !$node,
                'info' => $info ? array_intersect_key($info, array_flip(['lufs', 'lufs_before', 'gain_db', 'target', 'levelled', 'mastered'])) : null];
        }, $rows);
    }

    /**
     * Put a made-again song in place of the station's copy (as Song prep's apply action does). A song on air stays
     * "prepared" and is tried again (retryWaiting) for up to REDO_WAIT. Returns a few words for the log.
     */
    private function putInPlace(int $id): string
    {
        $job = $this->get($id);
        if ($job === null || $job['state'] !== 'prepared' || !(int) $job['redo']) {
            return 'not waiting';
        }
        $r = json_decode((string) $job['result'], true) ?: [];
        $opts = json_decode((string) $job['opts'], true) ?: [];
        $info = is_array($r['info'] ?? null) ? $r['info'] : [];
        $work = $this->dir() . "/$id";
        $song = !empty($r['song']) && is_file("$work/song.out") ? "$work/song.out" : $this->redoSource($job); // kept: the original is right as it is
        if ($song === null) {
            $this->set($id, ['state' => 'failed', 'error' => 'The song’s original is gone.', 'finished_at' => self::now()]);
            return 'original gone';
        }
        $master = is_array($opts['master'] ?? null) ? $opts['master'] : null;
        $mastered = !empty($opts['mastered']) || !empty($info['mastered']);
        $level = ['source_lufs' => $info['lufs_before'] ?? null, 'gain_db' => $info['gain_db'] ?? null, 'target' => $info['target'] ?? null,
            'settings' => $mastered ? $master : null];
        $planner = Streams::planner($this->db, null);
        $library = new Library($this->db, $this->station, $this->root);
        $problem = $planner === null ? 'The station’s plan couldn’t be read.' : $library->replace((int) $job['track_id'], $song,
            !empty($info['levelled']) && isset($info['lufs']) ? (float) $info['lufs'] : null, is_array($info['outro'] ?? null) ? $info['outro'] : null,
            $mastered, $mastered ? (string) ($opts['preset'] ?? '') : null, $planner, self::$clock ?? microtime(true), $level);
        if ($problem !== null && str_contains($problem, 'is on air') && strtotime($job['created_at'] . ' UTC') > time() - self::REDO_WAIT) {
            $this->set($id, ['stage' => 'On air: waiting until it isn’t']);
            return 'on air, waiting';
        }
        if ($problem !== null) {
            $this->set($id, ['state' => 'failed', 'error' => mb_substr($problem, 0, 500), 'finished_at' => self::now(), 'stage' => null]);
            return "not put in place ($problem)";
        }
        $this->set($id, ['state' => 'done', 'stage' => null, 'finished_at' => self::now()]);
        NodeRuntime::rmTree($work);
        return 'in place';
    }

    /** Try again the songs made again that were on air, last tried at least $after seconds ago. @return list<string> */
    public function retryWaiting(int $after = self::REDO_RETRY): array
    {
        $out = [];
        foreach ($this->db->run("SELECT id, name FROM {$this->t()} WHERE station_id = ? AND redo = 1 AND state = 'prepared' AND updated_at <= ? ORDER BY id",
            [$this->station['id'], gmdate('Y-m-d H:i:s', time() - $after)])->fetchAll() as $j) {
            $out[] = "#{$j['id']} {$j['name']}: " . $this->putInPlace((int) $j['id']);
        }
        return $out;
    }

    /** Old pieces, finished rows and files nothing refers to. */
    public function cleanup(int $now): void
    {
        $at = fn(int $ago) => gmdate('Y-m-d H:i:s', $now - $ago);
        foreach ($this->db->run("SELECT id FROM {$this->t()} WHERE station_id = ? AND ((state = 'uploading' AND updated_at < ?) OR (state IN ('failed','cancelled') AND updated_at < ?) OR (state = 'done' AND updated_at < ?))",
            [$this->station['id'], $at(self::KEEP_PARTIAL), $at(self::KEEP_FAILED), $at(self::KEEP_DONE)])->fetchAll() as $j) {
            $this->dropFiles((int) $j['id']);
            $this->db->run("DELETE FROM {$this->t()} WHERE id = ?", [$j['id']]);
        }
        // files without a row (a row removed by hand, a database restored)
        $ids = array_map('intval', $this->db->run("SELECT id FROM {$this->t()}")->fetchAll(\PDO::FETCH_COLUMN));
        $keep = array_flip($ids);
        foreach (is_dir($this->dir()) ? (scandir($this->dir()) ?: []) : [] as $f) {
            if (preg_match('/^(\d+)(\.(part|src|art|progress))?$/', $f, $m) === 1 && !isset($keep[(int) $m[1]])
                && @filemtime($this->dir() . "/$f") < $now - 3600) {
                NodeRuntime::rmTree($this->dir() . "/$f");
            }
        }
    }
}
