<?php
// Shared bits for the command-line tests: check(), a throwaway database and a throwaway install.
// Database server from SW_TEST_DB_HOST / _USER / _PASS (default Laragon: 127.0.0.1, root, no
// password). SW_TEST_DB_NAME names an existing empty database to use instead of making one
// (cPanel users can't create databases); then only the test's own tables are dropped afterwards.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Installer, Schema};

$failures = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . ($detail !== '' ? "  ($detail)" : '') . "\n";
    if (!$ok) { $failures++; }
}

function finish(): never
{
    global $failures;
    echo $failures ? "\n$failures check(s) failed\n" : "\nAll checks passed\n";
    exit($failures ? 1 : 0);
}

/** @return array{server:array,name:string,given:bool,pdo:PDO} */
function test_database(string $tag): array
{
    $server = ['host' => getenv('SW_TEST_DB_HOST') ?: '127.0.0.1', 'user' => getenv('SW_TEST_DB_USER') ?: 'root', 'pass' => getenv('SW_TEST_DB_PASS') ?: ''];
    $given = getenv('SW_TEST_DB_NAME') ?: '';
    $name = $given !== '' ? $given : "sw_{$tag}_test_" . getmypid();
    $pdo = new PDO("mysql:host={$server['host']};charset=utf8mb4", $server['user'], $server['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($given === '') {
        $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    echo 'Server: ' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
    return ['server' => $server, 'name' => $name, 'given' => $given !== '', 'pdo' => $pdo];
}

function drop_test_database(array $t, array $prefixes = ['sw_']): void
{
    if (!$t['given']) {
        $t['pdo']->exec("DROP DATABASE IF EXISTS `{$t['name']}`");
        return;
    }
    $t['pdo']->exec("USE `{$t['name']}`");
    $t['pdo']->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($prefixes as $p) {
        foreach (Schema::TABLES as $table) {
            $t['pdo']->exec("DROP TABLE IF EXISTS `$p$table`");
        }
    }
    $t['pdo']->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function temp_root(string $tag): string
{
    $root = sys_get_temp_dir() . "/sw-$tag-test-" . getmypid();
    mkdir("$root/storage", 0775, true);
    return $root;
}

function remove_tree(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($root);
}

function install_form(array $t, array $extra = []): array
{
    return $extra + [
        'db_host' => $t['server']['host'], 'db_port' => '3306', 'db_name' => $t['name'], 'db_user' => $t['server']['user'],
        'db_pass' => $t['server']['pass'], 'db_prefix' => 'sw_', 'station_name' => 'Radio WordWave / Radio WoordGolf',
        'timezone' => 'Africa/Johannesburg', 'admin_name' => 'Test Admin', 'admin_email' => 'Admin@Example.test',
        'admin_password' => 'correct horse battery',
    ];
}

/** Install into a temp folder and return its settings. */
function test_install(array $t, string $root): array
{
    (new Installer($root))->install(install_form($t));
    return require "$root/config.local.php";
}

/**
 * Write a playable MP3 of about $seconds: MPEG-1 Layer III frames, 128 kbps, 44.1 kHz, silent
 * audio. Real frame headers are all the planner reads. $tags adds an ID3v2.3 title/artist.
 */
function make_mp3(string $path, float $seconds, array $tags = [], int $salt = 0): void
{
    $frame = "\xFF\xFB\x90\x00" . str_repeat("\0", 413); // 417 bytes, 1152 samples
    $id3 = '';
    foreach (['TIT2' => $tags['title'] ?? null, 'TPE1' => $tags['artist'] ?? null, 'TCOP' => $tags['copyright'] ?? null,
              'TPUB' => $tags['publisher'] ?? null, 'TCOM' => $tags['writers'] ?? null, 'TYER' => $tags['year'] ?? null,
              'TSRC' => $tags['isrc'] ?? null, 'TEXT' => $tags['lyricists'] ?? null, 'TRCK' => $tags['track'] ?? null, 'TALB' => $tags['album'] ?? null] as $id => $text) {
        if ($text !== null) {
            $body = "\x03" . $text; // UTF-8
            $id3 .= $id . pack('N', strlen($body)) . "\0\0" . $body;
        }
    }
    if (isset($tags['art'])) { // an attached front cover (PNG or JPEG bytes)
        $body = "\x00" . (str_starts_with($tags['art'], "\x89PNG") ? 'image/png' : 'image/jpeg') . "\0\x03\0" . $tags['art'];
        $id3 .= 'APIC' . pack('N', strlen($body)) . "\0\0" . $body;
    }
    if ($id3 !== '') {
        $n = strlen($id3);
        $id3 = "ID3\x03\x00\x00" . chr(($n >> 21) & 0x7f) . chr(($n >> 14) & 0x7f) . chr(($n >> 7) & 0x7f) . chr($n & 0x7f) . $id3;
    }
    $frames = (int) ceil($seconds * 44100 / 1152);
    // $salt changes one silent byte so files of the same length still differ
    file_put_contents($path, $id3 . str_repeat($frame, $frames - 1) . substr_replace($frame, chr($salt & 0xff), 100, 1));
}
