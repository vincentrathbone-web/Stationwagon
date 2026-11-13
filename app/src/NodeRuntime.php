<?php
declare(strict_types=1);

namespace Stationwagon;

use PharData;
use RuntimeException;
use Throwable;

/**
 * Song prep on the server (round 85, v1.13) needs one program: Node. LAME and the decoder ship with the app as
 * WebAssembly (assets/vendor), and cli/prep-node.mjs runs the browser's own chain (assets/prep-run.js).
 *
 * A station uses a Node the server already has (the `node` setting in config.local.php, then the PATH and the usual
 * places), version 18 or newer. If there is none, Sound & safety offers Set up: on an admin's click (never by itself)
 * the station downloads Node's official Linux build (x64 or arm64, the version pinned here and checked against its
 * SHA-256) into its own storage/runtime/, which needs no root, keeps only the `node` program, and then tests it by
 * preparing a short tone with cli/prep-node.mjs. When the host doesn't allow it (programs may not be run from PHP,
 * the folder is mounted noexec, memory or process limits, not Linux) the reason is kept and shown, and the station's
 * songs are prepared in the browser, as before round 85.
 *
 * The result is kept in storage/cache/node.json: { ok, path, version, source: server|fetched, at, error?, test_s? }.
 */
final class NodeRuntime
{
    public const MIN_MAJOR = 18;
    public const VERSION = '24.22.3'; // Node 24 LTS (Krypton), a security release of 2026-11-10
    /** SHA-256 of node-v<VERSION>-linux-<arch>.tar.gz, from nodejs.org/dist/v<VERSION>/SHASUMS256.txt */
    public const SHA256 = [
        'x64' => '061ec3e3062115705b56b51fa9cc330ab50d34b6969387d4e4b1a50c6809acc2',
        'arm64' => '261af2af250dfd7e9f25cf151d1b2713a0adf8f10a9ad116c4947f68627affe8',
    ];
    public const DIST = 'https://nodejs.org/dist/';

    /** Where the station's storage is (tests point it at a throwaway copy). */
    public static ?string $root = null;

    public static function statePath(): string
    {
        return (self::$root ?? SW_ROOT) . '/storage/cache/node.json';
    }

    public static function runtimeDir(): string
    {
        return (self::$root ?? SW_ROOT) . '/storage/runtime';
    }

    /** @return array{ok?:bool,path?:string,version?:string,source?:string,at?:float,error?:string,busy?:string,test_s?:float} */
    public static function state(): array
    {
        $s = json_decode((string) @file_get_contents(self::statePath()), true);
        return is_array($s) ? $s : [];
    }

    /** What Set up is doing now, or null (a run that stopped without finishing counts as done after 15 minutes). */
    public static function busy(): ?string
    {
        $s = self::state();
        return !empty($s['busy']) && microtime(true) - (float) ($s['busy_at'] ?? 0) < 900 ? (string) $s['busy'] : null;
    }

    public static function save(array $s): void
    {
        if (isset($s['busy'])) {
            $s['busy_at'] = microtime(true);
        } else {
            unset($s['busy_at']);
        }
        $dir = dirname(self::statePath());
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = self::statePath() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode($s, JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($tmp, self::statePath());
        }
    }

    /** The Node to run, when the station's setup test passed and it's still there; else null (prepare in the browser). */
    public static function ready(): ?string
    {
        $s = self::state();
        return !empty($s['ok']) && isset($s['path']) && self::usable((string) $s['path']) ? (string) $s['path'] : null;
    }

    /** Whether PHP may start programs here at all (some hosts turn proc_open off). */
    public static function canStartPrograms(): bool
    {
        $off = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return function_exists('proc_open') && !in_array('proc_open', $off, true);
    }

    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    private static function usable(string $path): bool
    {
        return $path !== '' && is_file($path) && (self::isWindows() || is_executable($path));
    }

    /**
     * Run a program with arguments (no shell), its standard input closed; [exit code, stdout, stderr].
     * @param list<string> $cmd
     * @return array{0:int,1:string,2:string}
     */
    public static function run(array $cmd, int $timeout = 60): array
    {
        if (!self::canStartPrograms()) {
            return [-1, '', 'This server doesn’t let PHP start programs (proc_open is turned off).'];
        }
        $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($p)) {
            return [-1, '', 'The program couldn’t be started.'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        $end = microtime(true) + $timeout;
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
            if (microtime(true) > $end) {
                proc_terminate($p, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($p);
                return [-2, $out, $err . "\nStopped after {$timeout} s."];
            }
            usleep(50000);
        }
    }

    /** Node's version at $path ("24.22.3"), or null when it doesn't run. */
    public static function versionOf(string $path): ?string
    {
        if (!self::usable($path)) {
            return null;
        }
        [$code, $out] = self::run([$path, '--version'], 20);
        return $code === 0 && preg_match('/^v?(\d+\.\d+\.\d+)/', trim($out), $m) === 1 ? $m[1] : null;
    }

    private static function majorOk(?string $version): bool
    {
        return $version !== null && (int) $version >= self::MIN_MAJOR;
    }

    /** The places a server's own Node may be: the setting, the PATH, the usual folders, then one fetched before. @return list<string> */
    public static function candidates(): array
    {
        $out = [];
        if (is_string($c = Config::get('node')) && $c !== '') {
            $out[] = $c;
        }
        $exe = self::isWindows() ? 'node.exe' : 'node';
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '') {
                $out[] = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $exe;
            }
        }
        if (self::isWindows()) {
            foreach (['ProgramFiles', 'ProgramW6432', 'ProgramFiles(x86)'] as $env) {
                if (($pf = getenv($env)) !== false && $pf !== '') {
                    $out[] = "$pf\\nodejs\\node.exe"; // a web server's PATH often lacks it
                }
            }
        } else {
            array_push($out, '/usr/local/bin/node', '/usr/bin/node', '/opt/node/bin/node', '/opt/nodejs/bin/node');
            if (($home = getenv('HOME')) !== false && $home !== '') {
                $out[] = "$home/bin/node";
            }
        }
        $out[] = self::runtimeDir() . '/node/bin/' . $exe;
        return array_values(array_unique($out));
    }

    /** A Node the server already has: [path, version], or null. */
    public static function find(): ?array
    {
        foreach (self::candidates() as $path) {
            $v = self::versionOf($path);
            if (self::majorOk($v)) {
                return [$path, $v];
            }
        }
        return null;
    }

    /** The build for this server: "x64" or "arm64", or null when Set up can't fetch one (not Linux, another processor). */
    public static function arch(): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }
        return match (strtolower(php_uname('m'))) {
            'x86_64', 'amd64' => 'x64',
            'aarch64', 'arm64' => 'arm64',
            default => null,
        };
    }

    /**
     * Set up (an admin's click): use the server's Node, else fetch the pinned build, then test it. Returns the new state.
     * $source overrides where the tarball comes from (tests: a local file with the same name and checksum).
     */
    public static function setUp(?string $source = null, ?array $sha = null): array
    {
        @set_time_limit(600);
        self::save(['busy' => 'Looking for Node on this server']);
        try {
            $found = self::find();
            $how = 'server';
            if ($found === null) {
                $found = self::fetch($source, $sha);
                $how = 'fetched';
            }
            [$path, $version] = $found;
            self::save(['busy' => 'Testing Node ' . $version . ' on a short tone', 'path' => $path, 'version' => $version, 'source' => $how]);
            $seconds = self::test($path);
            $s = ['ok' => true, 'path' => $path, 'version' => $version, 'source' => $how, 'at' => microtime(true), 'test_s' => round($seconds, 1)];
        } catch (Throwable $ex) {
            $s = ['ok' => false, 'error' => $ex->getMessage(), 'at' => microtime(true)] + array_intersect_key(self::state(), ['path' => 1, 'version' => 1, 'source' => 1]);
        }
        self::save($s);
        return $s;
    }

    /** Turn server prep off again (Sound & safety): songs are prepared in the browser. A fetched Node is deleted. */
    public static function remove(): void
    {
        $s = self::state();
        if (($s['source'] ?? '') === 'fetched') {
            self::rmTree(self::runtimeDir() . '/node');
        }
        @unlink(self::statePath());
    }

    /**
     * Download node-v<VERSION>-linux-<arch>.tar.gz, check its SHA-256, keep only bin/node in storage/runtime/node/bin.
     * @return array{0:string,1:string} [path, version]
     */
    public static function fetch(?string $source = null, ?array $sha = null): array
    {
        $arch = self::arch();
        if ($arch === null && $source === null) {
            throw new RuntimeException(PHP_OS_FAMILY === 'Linux'
                ? 'This server’s processor (' . php_uname('m') . ') has no Node build to fetch. Ask your host to install Node ' . self::MIN_MAJOR . ' or newer.'
                : 'There is no Node on this server, and Set up can fetch one only for Linux servers. Install Node ' . self::MIN_MAJOR . ' or newer, or name it in config.local.php (`node`).');
        }
        $arch ??= 'x64';
        $name = 'node-v' . self::VERSION . '-linux-' . $arch;
        $dir = self::runtimeDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Couldn’t make storage/runtime.');
        }
        self::guard($dir);
        $tar = "$dir/$name.tar.gz";
        self::save(['busy' => 'Downloading Node ' . self::VERSION . ' (about 56 MB)'] + self::state());
        $url = $source ?? self::DIST . 'v' . self::VERSION . "/$name.tar.gz";
        $in = @fopen($url, 'rb', false, stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'Stationwagon']]));
        if ($in === false) {
            throw new RuntimeException('Couldn’t download Node from nodejs.org: this server may not be allowed to fetch files from the internet.');
        }
        $out = @fopen($tar, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Couldn’t write in storage/runtime.');
        }
        $bytes = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        $want = ($sha ?? self::SHA256)[$arch] ?? '';
        if (!$bytes || hash_file('sha256', $tar) !== $want) {
            @unlink($tar);
            throw new RuntimeException('The Node download didn’t match its checksum, so it wasn’t used. Try again later.');
        }
        self::save(['busy' => 'Unpacking Node'] + self::state());
        $bin = "$dir/node/bin";
        self::rmTree("$dir/node");
        @mkdir($bin, 0775, true);
        $ok = false;
        if (self::canStartPrograms() && !self::isWindows()) {
            // tar is quick and streams; PharData is the fallback where there is no tar
            [$code] = self::run(['tar', '-xzf', $tar, '-C', $bin, '--strip-components=2', "$name/bin/node"], 300);
            $ok = $code === 0 && is_file("$bin/node");
        }
        if (!$ok) {
            try {
                $work = "$dir/unpack";
                self::rmTree($work);
                (new PharData($tar))->extractTo($work, "$name/bin/node", true);
                $ok = @rename("$work/$name/bin/node", "$bin/node");
                self::rmTree($work);
            } catch (Throwable) {
                $ok = false;
            }
        }
        @unlink($tar);
        if (!$ok) {
            throw new RuntimeException('Couldn’t unpack Node in storage/runtime.');
        }
        @chmod("$bin/node", 0755);
        $version = self::versionOf("$bin/node");
        if ($version === null) {
            throw new RuntimeException('Node was fetched, but this server won’t run it (the hosting may not allow programs in your folders, or it is out of memory). Songs are prepared in the browser instead.');
        }
        return ["$bin/node", $version];
    }

    /**
     * Prepare a 3-second tone (stereo, 44.1 kHz) with cli/prep-node.mjs, mastered and levelled, and check the MP3 that
     * comes out. Returns the seconds it took; throws with the reason otherwise.
     */
    public static function test(string $node): float
    {
        $work = self::runtimeDir() . '/test-' . bin2hex(random_bytes(4));
        @mkdir($work, 0775, true);
        try {
            file_put_contents("$work/tone.wav", self::toneWav(3.0));
            file_put_contents("$work/job.json", json_encode(['in' => "$work/tone.wav", 'name' => 'tone.wav', 'out' => $work,
                'opts' => ['kbps' => 128, 'master' => new \stdClass(), 'level' => true, 'target' => -16,
                    'quality' => ['rate' => 44100, 'precision' => 32, 'dither' => true, 'q' => 2]]], JSON_UNESCAPED_SLASHES));
            $t = microtime(true);
            [$code, , $err] = self::run([$node, SW_ROOT . '/cli/prep-node.mjs', "$work/job.json"], 240);
            $took = microtime(true) - $t;
            $r = json_decode((string) @file_get_contents("$work/result.json"), true);
            if ($code !== 0 || !is_array($r)) {
                throw new RuntimeException('Node ran but couldn’t prepare a test song' . (trim($err) !== '' ? ': ' . self::firstLine($err) : '.'));
            }
            if (empty($r['prepared']) || empty($r['song']) || !is_file("$work/song.out")) {
                throw new RuntimeException('Node couldn’t prepare a test song: ' . ($r['note'] ?? 'no song came out') . '.');
            }
            $scan = Mp3::scan("$work/song.out", 6.0);
            if ($scan['frames'] < 50 || abs((float) ($r['info']['lufs'] ?? 0) + 16) > 1.0) {
                throw new RuntimeException('The test song Node made isn’t right (' . $scan['frames'] . ' frames, ' . ($r['info']['lufs'] ?? '?') . ' LUFS).');
            }
            return $took;
        } finally {
            self::rmTree($work);
        }
    }

    private static function firstLine(string $s): string
    {
        foreach (preg_split('/\R/', trim($s)) ?: [] as $line) {
            if (preg_match('/Error|error|denied|memory|killed/i', $line)) {
                return mb_substr(trim($line), 0, 200);
            }
        }
        return mb_substr(trim(strtok(trim($s), "\n") ?: ''), 0, 200);
    }

    /** A stereo 16-bit 44.1 kHz WAV: a $hz tone with a little 3 kHz in it, at about −14 dBFS. */
    public static function toneWav(float $seconds, float $hz = 440): string
    {
        $rate = 44100;
        $n = (int) round($seconds * $rate);
        $pcm = '';
        for ($i = 0; $i < $n; $i++) {
            $v = 0.2 * sin(2 * M_PI * $hz * $i / $rate) + 0.03 * sin(2 * M_PI * 3000 * $i / $rate);
            $s = (int) round($v * 32767);
            $pcm .= pack('vv', $s & 0xffff, $s & 0xffff);
        }
        return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 2, $rate, $rate * 4, 4, 16) . 'data' . pack('V', strlen($pcm)) . $pcm;
    }

    /** storage/runtime is never served (it isn't under media; this .htaccess says so to Apache too). */
    private static function guard(string $dir): void
    {
        if (!is_file("$dir/.htaccess")) {
            @file_put_contents("$dir/.htaccess", "# Programs the station runs (never served)\nRequire all denied\n<FilesMatch \".\">\n  Require all denied\n</FilesMatch>\n");
        }
    }

    public static function rmTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                self::rmTree("$path/$f");
            }
        }
        @rmdir($path);
    }

    /**
     * For the Overview and Sound & safety: where songs are prepared and why.
     * @return array{server:bool,text:string,detail:string,busy:?string}
     */
    public static function summary(): array
    {
        $s = self::state();
        if (self::busy() !== null) {
            return ['server' => false, 'text' => 'Setting up', 'detail' => (string) $s['busy'] . '…', 'busy' => (string) $s['busy']];
        }
        if (self::ready() !== null) {
            return ['server' => true, 'text' => 'On the server', 'busy' => null,
                'detail' => 'Node ' . $s['version'] . (($s['source'] ?? '') === 'fetched' ? ' (fetched by the station into its storage)' : ' (the server’s own)')
                    . '. Uploads keep going when the page is closed.'];
        }
        if (!empty($s['ok']) && empty($s['busy'])) {
            return ['server' => false, 'text' => 'In the browser', 'busy' => null, 'detail' => 'The Node this station used is gone. Set it up again.'];
        }
        if (isset($s['error'])) {
            return ['server' => false, 'text' => 'In the browser', 'busy' => null, 'detail' => 'Set up didn’t work: ' . $s['error']];
        }
        return ['server' => false, 'text' => 'In the browser', 'busy' => null,
            'detail' => 'Keep the page open while songs are prepared. Set up lets the server do it, so uploads go on when the page is closed.'];
    }
}
