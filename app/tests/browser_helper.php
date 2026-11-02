<?php
// Database and planner side of the browser tests (Laragon only): a throwaway manager, a look at
// uploaded tracks, a test live show, and the cleanup. Prints JSON.
//   php browser_helper.php manager            -> {"id", "email", "password"}
//   php browser_helper.php track <title>      -> the newest ready track with that title, or null
//   php browser_helper.php presets [clear <name prefix> | default <id>]  -> {"presets", "default"}
//   php browser_helper.php cleanup <user id> [<title> ...]
//   php browser_helper.php lib-songs <n>    -> n throwaway songs "Web Test Lib NN" and one tagged with a cover and copyright: {"ids", "tagged"}
//   php browser_helper.php lib-info <id>    -> the song's title, art file and rights
//   php browser_helper.php lib-clean        (every "Web Test Lib" song, and their art)
//   php browser_helper.php live-start <title> -> {"public_id", "start"}
//   php browser_helper.php live-piece <public id> <seq> <mp3 file> [<seconds> | auto]
//   php browser_helper.php live-end <public id>
//   php browser_helper.php note <public id> <kind> <fields as JSON>  -> {"note"} or {"error"}
//   php browser_helper.php note-edit <public id> <note id> <fields as JSON>  -> {"note", "replaces"} or {"error"}
//   php browser_helper.php notes-clear <public id>                     (the test show's notes)
//   php browser_helper.php dj [block]              -> a throwaway DJ {"id", "email", "password", "block"}
//   php browser_helper.php live-info <public id>   -> the show, its pieces (scanned) and how many notes
//   php browser_helper.php studio-cleanup <user id> [<block id>]   (shows, notes, running orders, block, DJ, podcast episodes)
//   php browser_helper.php podcast-show <user id> [<minutes ago>]   -> a closed test show of theirs {"public_id", "start"}
//   php browser_helper.php podcast-aired <user id> < mp3 as base64 -> the same, with that audio as its pieces (as aired)
//   php browser_helper.php episode-info <show public id>           -> its episode, takes (chunks per track) and files
//   php browser_helper.php podcast-min <seconds | default>          -> the shortest show made into an episode, before
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Db, Library, Notes, Planner, Podcast, Schedule, StationSettings};

const PHONE_DJ = 'stationwagon-phone-test@example.test';

$db = Db::fromConfig();
$planner = Planner::forFirstStation();
$station = $planner->station();
$cmd = $argv[1] ?? '';

if ($cmd === 'manager') {
    $tag = bin2hex(random_bytes(3));
    $email = "web-test-$tag-prep@example.test";
    $password = bin2hex(random_bytes(12));
    $id = $db->insert('users', ['email' => $email, 'name' => 'Web Test Prep', 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'manager', 'created_at' => gmdate('Y-m-d H:i:s')]);
    $db->insert('station_users', ['station_id' => $station['id'], 'user_id' => $id]);
    echo json_encode(['id' => $id, 'email' => $email, 'password' => $password]);
} elseif ($cmd === 'track') {
    $row = $db->run('SELECT id, file, sha1, title, artist, duration_s, bitrate_kbps, sample_rate, loudness_lufs, source_lufs, level_gain_db, level_target, mastered, preset, preset_settings, outro FROM ' . $db->table('tracks') .
        " WHERE station_id = ? AND title = ? AND status = 'ready' ORDER BY id DESC LIMIT 1", [$station['id'], $argv[2] ?? ''])->fetch();
    echo json_encode($row ?: null);
} elseif ($cmd === 'presets') {
    // song prep's presets and the import default; with "clear <prefix>", first remove those named so
    // and set the default back (default <id>) when given
    $pp = new Stationwagon\PrepPresets($db, $station);
    if (($argv[2] ?? '') === 'clear' && ($argv[3] ?? '') !== '') {
        foreach ($pp->all() as $x) {
            if (str_starts_with($x['name'], $argv[3])) {
                $pp->delete($x['id']);
            }
        }
        $station = $db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE id = ?', [$station['id']])->fetch();
        $pp = new Stationwagon\PrepPresets($db, $station);
    }
    if (($argv[2] ?? '') === 'default') {
        $station = $pp->setDefault((int) ($argv[3] ?? 0));
    }
    echo json_encode(['presets' => $pp->all(), 'default' => Stationwagon\PrepPresets::defaultId($station)]);
} elseif ($cmd === 'prep') {
    // song prep's two switches and loudness target: print them, or set them first (prep <master 0|1> <level 0|1> [<target LUFS>])
    if (isset($argv[3])) {
        $station = (new StationSettings($db, $station))->savePrep($argv[2] === '1', $argv[3] === '1', isset($argv[4]) ? (float) $argv[4] : null);
    }
    [$m, $l, $tg] = StationSettings::prep($station);
    echo json_encode(['master' => $m, 'level' => $l, 'target' => $tg]);
} elseif ($cmd === 'lib-songs') {
    $dir = sys_get_temp_dir() . '/sw-lib-test-' . bin2hex(random_bytes(3));
    mkdir($dir);
    $lib = new Library($db, $station, SW_ROOT);
    $frame = "\xFF\xFB\x90\x00" . str_repeat("\0", 413);
    $mp3 = function (string $path, int $salt, array $tags = []) use ($frame): void {
        $id3 = '';
        foreach (['TIT2' => $tags['title'] ?? null, 'TPE1' => $tags['artist'] ?? null, 'TCOP' => $tags['copyright'] ?? null, 'TPUB' => $tags['publisher'] ?? null, 'TCOM' => $tags['writers'] ?? null] as $fid => $text) {
            if ($text !== null) {
                $body = "\x03" . $text;
                $id3 .= $fid . pack('N', strlen($body)) . "\0\0" . $body;
            }
        }
        if (isset($tags['art'])) {
            $body = "\x00image/png\0\x03\0" . $tags['art'];
            $id3 .= 'APIC' . pack('N', strlen($body)) . "\0\0" . $body;
        }
        if ($id3 !== '') {
            $n = strlen($id3);
            $id3 = "ID3\x03\x00\x00" . chr(($n >> 21) & 0x7f) . chr(($n >> 14) & 0x7f) . chr(($n >> 7) & 0x7f) . chr($n & 0x7f) . $id3;
        }
        file_put_contents($path, $id3 . str_repeat($frame, 100) . substr_replace($frame, chr($salt & 0xff) . chr(($salt >> 8) & 0xff), 100, 2));
    };
    $ids = [];
    for ($i = 1; $i <= (int) ($argv[2] ?? 30); $i++) {
        $mp3("$dir/s$i.mp3", $i + random_int(0, 60000));
        $ids[] = $lib->import("$dir/s$i.mp3", sprintf('Web Test Lib %02d', $i), 'Web Tester', "s$i.mp3")['id'];
    }
    $im = imagecreatetruecolor(400, 400);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
    ob_start();
    imagepng($im);
    $png = (string) ob_get_clean();
    $mp3("$dir/tagged.mp3", random_int(0, 60000), ['title' => 'Web Test Lib Tagged', 'artist' => 'Web Tester', 'copyright' => '© 2021 Test Owner', 'publisher' => 'Test Press', 'writers' => 'T. Writer', 'art' => $png]);
    $tagged = $lib->import("$dir/tagged.mp3", null, null, 'tagged.mp3')['id'];
    array_map('unlink', glob("$dir/*.mp3") ?: []);
    @rmdir($dir);
    echo json_encode(['ids' => $ids, 'tagged' => $tagged]);
} elseif ($cmd === 'lib-info') {
    $r = (new Library($db, $station, SW_ROOT))->get((int) ($argv[2] ?? 0));
    echo json_encode($r ? ['title' => $r['title'], 'artist' => $r['artist'], 'album' => $r['album'], 'art' => $r['art'], 'rights' => $r['rights'] === null ? null : json_decode($r['rights'], true)] : null);
} elseif ($cmd === 'lib-clean') {
    $lib = new Library($db, $station, SW_ROOT);
    $n = 0;
    foreach ($db->run('SELECT id FROM ' . $db->table('tracks') . ' WHERE station_id = ? AND title LIKE ?', [$station['id'], 'Web Test Lib%'])->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if ($lib->delete((int) $id, $planner, microtime(true)) !== null) {
            $db->run('UPDATE ' . $db->table('tracks') . " SET status = 'error', title = CONCAT('[test] ', title) WHERE id = ?", [$id]); // on air now: hidden, removed by the next run
        }
        $n++;
    }
    foreach ($db->run('SELECT id FROM ' . $db->table('tracks') . ' WHERE station_id = ? AND title LIKE ?', [$station['id'], '[test] Web Test Lib%'])->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $lib->delete((int) $id, $planner, microtime(true));
    }
    echo json_encode(['cleaned' => $n]);
} elseif ($cmd === 'cleanup') {
    $db->run('DELETE FROM ' . $db->table('users') . ' WHERE id = ? AND email LIKE ?', [(int) ($argv[2] ?? 0), 'web-test-%']);
    $lib = new Library($db, $station, SW_ROOT);
    $hidden = 0;
    foreach (array_slice($argv, 3) as $title) {
        $ids = $db->run('SELECT id FROM ' . $db->table('tracks') . ' WHERE station_id = ? AND title = ?', [$station['id'], $title])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            if ($lib->delete((int) $id, $planner, microtime(true)) !== null) {
                // On air right now: hide it like web_test.php does; its next run removes it
                $db->run('UPDATE ' . $db->table('tracks') . " SET status = 'error', title = CONCAT('[test] Web Test Song ', title) WHERE id = ?", [$id]);
                $hidden++;
            }
        }
    }
    $planner->replan(microtime(true));
    echo json_encode(['hidden' => $hidden]);
} elseif ($cmd === 'live-start') {
    $r = $planner->goLive(microtime(true), $argv[2] ?? 'Browser test show')['result'];
    echo json_encode(isset($r['live']) ? ['public_id' => $r['live']['public_id'], 'start' => $r['live']['start']] : ['error' => $r['error'] ?? 'no show']);
} elseif ($cmd === 'live-piece') {
    $file = $argv[4] ?? '';
    $seconds = ($argv[5] ?? 'auto') === 'auto' ? (float) Stationwagon\Mp3::scan($file, 6.0)['duration'] : (float) $argv[5];
    $r = $planner->addPiece(microtime(true), $argv[2] ?? '', (int) ($argv[3] ?? 0), $seconds, (string) file_get_contents($file))['result'];
    echo json_encode($r);
} elseif ($cmd === 'live-end') {
    echo json_encode($planner->endLive(microtime(true), $argv[2] ?? '')['result']);
} elseif ($cmd === 'note') {
    echo json_encode((new Notes($db, $station))->post($argv[2] ?? '', null, $argv[3] ?? '', json_decode($argv[4] ?? '{}', true) ?: []));
} elseif ($cmd === 'note-edit') {
    echo json_encode((new Notes($db, $station))->edit($argv[2] ?? '', null, (int) ($argv[3] ?? 0), json_decode($argv[4] ?? '{}', true) ?: []));
} elseif ($cmd === 'notes-clear') {
    $notes = new Notes($db, $station);
    foreach ($notes->forShow($argv[2] ?? '') as $n) {
        $notes->remove($argv[2], $n['id']);
    }
    echo json_encode(['cleared' => true]);
} elseif ($cmd === 'dj') {
    // a throwaway DJ; with "block", also a live block of theirs that covers every day, all day
    $tag = bin2hex(random_bytes(3));
    $email = "web-test-$tag-studio@example.test";
    $password = bin2hex(random_bytes(12));
    if (in_array('phone', array_slice($argv, 2), true)) {
        // the phone test's DJ, always the same, so the sign-in it saves to Google Password Manager is only ever one entry
        $email = PHONE_DJ; // a new password each run: Google Password Manager updates its one entry
        $db->run('DELETE FROM ' . $db->table('users') . ' WHERE email = ?', [$email]);
    }
    $id = $db->insert('users', ['email' => $email, 'name' => 'Studio Test DJ', 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'dj', 'created_at' => gmdate('Y-m-d H:i:s')]);
    $db->insert('station_users', ['station_id' => $station['id'], 'user_id' => $id]);
    $block = null;
    if (($argv[2] ?? '') === 'block') {
        [$block] = (new Schedule($db, $station))->add(['kind' => 'live', 'title' => '[test] Studio test show', 'dj_user_id' => $id, 'days' => array_keys(Schedule::DAYS), 'start' => '00:00', 'end' => '00:00']);
    }
    echo json_encode(['id' => $id, 'email' => $email, 'password' => $password, 'block' => $block]);
} elseif ($cmd === 'live-info') {
    $show = $db->run('SELECT * FROM ' . $db->table('live_shows') . ' WHERE public_id = ?', [$argv[2] ?? ''])->fetch();
    $pieces = [];
    if ($show) {
        foreach ($db->run('SELECT seq, file, duration_s FROM ' . $db->table('live_pieces') . ' WHERE show_id = ? ORDER BY seq', [$show['id']])->fetchAll() as $r) {
            $path = SW_ROOT . '/storage/live/' . $show['public_id'] . '/' . $r['file'];
            $scan = is_file($path) ? Stationwagon\Mp3::scan($path, 6.0) : null;
            $pieces[] = ['seq' => (int) $r['seq'], 'duration' => (float) $r['duration_s'], 'bytes' => is_file($path) ? filesize($path) : 0, 'scanned' => $scan ? round((float) $scan['duration'], 3) : null];
        }
    }
    echo json_encode($show ? ['open' => (bool) $show['open'], 'closed_reason' => $show['closed_reason'], 'dj_user_id' => (int) $show['dj_user_id'], 'title' => $show['title'], 'pieces' => $pieces,
        'notes' => count((new Notes($db, $station))->forShow($show['public_id']))] : null);
} elseif ($cmd === 'studio-cleanup') {
    // end the DJ's open shows, clear their notes, remove their running orders, block and account
    $userId = (int) ($argv[2] ?? 0);
    $blockId = (int) ($argv[3] ?? 0);
    $notes = new Notes($db, $station);
    foreach ($db->run('SELECT public_id, `open` FROM ' . $db->table('live_shows') . ' WHERE dj_user_id = ?', [$userId])->fetchAll() as $s) {
        if ((int) $s['open'] === 1) {
            $planner->endLive(microtime(true), $s['public_id']);
        }
        foreach ($notes->forShow($s['public_id']) as $n) {
            $notes->remove($s['public_id'], $n['id']);
        }
    }
    test_episodes_gone($db, $db->run('SELECT id FROM ' . $db->table('live_shows') . ' WHERE dj_user_id = ?', [$userId])->fetchAll(PDO::FETCH_COLUMN));
    $db->run('DELETE FROM ' . $db->table('running_orders') . ' WHERE station_id = ? AND scope IN (?, ?)', [$station['id'], "block:$blockId", "user:$userId"]);
    if ($blockId) {
        $db->run('DELETE FROM ' . $db->table('schedule') . " WHERE id = ? AND title LIKE '[test]%'", [$blockId]);
    }
    $db->run('DELETE FROM ' . $db->table('users') . ' WHERE id = ? AND (email LIKE ? OR email = ?)', [$userId, 'web-test-%', PHONE_DJ]);
    echo json_encode(['cleaned' => true]);
} elseif ($cmd === 'podcast-show') {
    $start = microtime(true) - 60 * (float) ($argv[3] ?? 10);
    $pid = 'live-' . gmdate('Ymd-His', (int) $start) . '-' . bin2hex(random_bytes(3));
    $db->insert('live_shows', ['station_id' => $station['id'], 'public_id' => $pid, 'dj_user_id' => (int) ($argv[2] ?? 0), 'title' => '[test] Podcast test show', 'start_at' => $start,
        'open' => 0, 'closed_reason' => 'ended by DJ', 'last_piece_at' => $start, 'created_at' => gmdate('Y-m-d H:i:s')]);
    echo json_encode(['public_id' => $pid, 'start' => $start]);
} elseif ($cmd === 'podcast-aired') {
    // a closed test show whose audio (read from stdin, base64) went out as ~6 s pieces, as the studio sends them
    $mp3 = base64_decode((string) stream_get_contents(STDIN), true);
    $tmp = tempnam(sys_get_temp_dir(), 'swa');
    file_put_contents($tmp, (string) $mp3);
    $scan = Stationwagon\Mp3::scan($tmp, 6.0);
    $start = microtime(true) - 600;
    $pid = 'live-' . gmdate('Ymd-His', (int) $start) . '-' . bin2hex(random_bytes(3));
    $showId = $db->insert('live_shows', ['station_id' => $station['id'], 'public_id' => $pid, 'dj_user_id' => (int) ($argv[2] ?? 0), 'title' => '[test] Podcast test show as aired', 'start_at' => $start,
        'open' => 0, 'closed_reason' => 'ended by DJ', 'last_piece_at' => $start + $scan['duration'], 'created_at' => gmdate('Y-m-d H:i:s')]);
    $dir = SW_ROOT . "/storage/live/$pid";
    mkdir($dir, 0775, true);
    foreach ($scan['pieces'] as $seq => [$offset, $length, $seconds]) {
        file_put_contents(sprintf('%s/%05d.mp3', $dir, $seq), substr((string) $mp3, $offset, $length));
        $db->insert('live_pieces', ['show_id' => $showId, 'seq' => $seq, 'file' => sprintf('%05d.mp3', $seq), 'duration_s' => round($seconds, 4), 'received_at' => $start + 6 * ($seq + 1)]);
    }
    @unlink($tmp);
    echo json_encode(['public_id' => $pid, 'start' => $start, 'pieces' => count($scan['pieces']), 'seconds' => round($scan['duration'], 3)]);
} elseif ($cmd === 'episode-info') {
    $show = $db->run('SELECT * FROM ' . $db->table('live_shows') . ' WHERE public_id = ?', [$argv[2] ?? ''])->fetch();
    $podcast = Podcast::forFirstStation();
    $podcast->sync(microtime(true));
    $ep = $show ? $db->run('SELECT * FROM ' . $db->table('episodes') . ' WHERE live_show_id = ?', [$podcast->rootShow((int) $show['id'])['id']])->fetch() : null;
    $takes = [];
    foreach ($ep ? $db->run('SELECT * FROM ' . $db->table('episode_takes') . ' WHERE episode_id = ? ORDER BY start_at, kind', [$ep['id']])->fetchAll() : [] as $t) {
        $chunks = $db->run('SELECT track, COUNT(*) n, SUM(samples) samples FROM ' . $db->table('episode_chunks') . ' WHERE take_id = ? GROUP BY track', [$t['id']])->fetchAll();
        $an = json_decode((string) $t['analysis'], true);
        $takes[] = ['id' => (int) $t['id'], 'show' => (int) $t['live_show_id'], 'kind' => $t['kind'], 'complete' => (bool) $t['complete'], 'duration' => (float) $t['duration_s'],
            'chunks' => array_map('intval', array_column($chunks, 'n', 'track')), 'samples' => array_map('intval', array_column($chunks, 'samples', 'track')),
            'clips' => is_array($an) ? array_map(fn($x) => $x['clips'], $an['tracks']) : null];
    }
    echo json_encode($ep ? ['id' => (int) $ep['id'], 'public_id' => $ep['public_id'], 'status' => $ep['status'], 'title' => $ep['title'], 'edits' => json_decode((string) $ep['edits'], true),
        'audio_file' => $ep['audio_file'], 'audio_seconds' => (float) $ep['audio_seconds'], 'audio_bytes' => (int) $ep['audio_bytes'], 'takes' => $takes,
        'files' => array_map('basename', array_merge(glob(SW_ROOT . '/storage/podcasts/' . $ep['public_id'] . '/*') ?: [], glob(SW_ROOT . '/storage/podcasts/' . $ep['public_id'] . '/raw/*') ?: []))] : null);
} elseif ($cmd === 'podcast-min') {
    // the studio test's show is shorter than an episode needs: lower the bar while it runs
    $own = json_decode((string) ($station['settings'] ?? ''), true) ?: [];
    $before = $own['podcast_min_seconds'] ?? 'default';
    if (($argv[2] ?? 'default') === 'default') {
        unset($own['podcast_min_seconds']);
    } else {
        $own['podcast_min_seconds'] = (int) $argv[2];
    }
    $db->run('UPDATE ' . $db->table('stations') . ' SET settings = ? WHERE id = ?', [json_encode($own, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $station['id']]);
    echo json_encode(['before' => $before]);
} elseif ($cmd === 'podcast-cleanup') {
    // a test show made with podcast-show: its episode emptied and skipped
    $show = $db->run('SELECT * FROM ' . $db->table('live_shows') . ' WHERE public_id = ?', [$argv[2] ?? ''])->fetch();
    test_episodes_gone($db, $show ? [$show['id']] : []);
    // podcast-aired made its pieces just for the test (they never went out): remove them too
    if ($show && $show['title'] === '[test] Podcast test show as aired') {
        foreach (glob(SW_ROOT . '/storage/live/' . basename($show['public_id']) . '/*.mp3') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(SW_ROOT . '/storage/live/' . basename($show['public_id']));
        $db->run('DELETE FROM ' . $db->table('live_pieces') . ' WHERE show_id = ?', [$show['id']]);
    }
    echo json_encode(['cleaned' => true]);
} else {
    fwrite(STDERR, "Usage: php browser_helper.php manager | track <title> | cleanup <user id> [<title> ...] | live-start <title> | live-piece <id> <seq> <file> <s> | live-end <id> | note <id> <kind> <json> | notes-clear <id> | dj [block] | live-info <id> | studio-cleanup <user id> [<block id>] | podcast-show <user id> [<min>] | podcast-aired <user id> | episode-info <id> | podcast-cleanup <id>" . PHP_EOL);
    exit(1);
}

/**
 * A test DJ's shows leave podcast episodes behind: give each show its episode first (so the cron
 * job won't make one later), then empty them out and skip them, so no list shows them.
 */
function test_episodes_gone(Db $db, array $showIds): void
{
    if (!$showIds) {
        return;
    }
    Podcast::forFirstStation()->sync(microtime(true));
    $in = implode(',', array_map('intval', $showIds));
    foreach ($db->run('SELECT DISTINCT e.* FROM ' . $db->table('episodes') . ' e JOIN ' . $db->table('episode_takes') . " k ON k.episode_id = e.id WHERE k.live_show_id IN ($in)")->fetchAll() as $ep) {
        $dir = SW_ROOT . '/storage/podcasts/' . $ep['public_id'];
        foreach (array_merge(glob("$dir/raw/*") ?: [], glob("$dir/*.*") ?: []) as $f) {
            @unlink($f);
        }
        @rmdir("$dir/raw");
        @rmdir($dir);
        $db->run('DELETE c FROM ' . $db->table('episode_chunks') . ' c JOIN ' . $db->table('episode_takes') . ' k ON k.id = c.take_id WHERE k.episode_id = ?', [$ep['id']]);
        $db->run('UPDATE ' . $db->table('episodes') . " SET status = 'skipped', audio_file = NULL, audio_bytes = 0, edits = NULL, title = ? WHERE id = ?",
            [str_starts_with($ep['title'], '[test]') ? $ep['title'] : '[test] ' . $ep['title'], $ep['id']]);
    }
}
