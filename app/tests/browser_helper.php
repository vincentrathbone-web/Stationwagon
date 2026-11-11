<?php
// Database and planner side of the browser tests (Laragon only): a throwaway manager, a look at
// uploaded tracks, a test live show, and the cleanup. Prints JSON.
//   php browser_helper.php manager            -> {"id", "email", "password"}
//   php browser_helper.php track <title>      -> the newest ready track with that title, or null
//   php browser_helper.php presets [clear <name prefix> | default <id>]  -> {"presets", "default"}
//   php browser_helper.php cleanup <user id> [<title> ...]
//   php browser_helper.php make-old <title>  (its stored file made to look prepared before round 51: the LAME 3.100 tag renamed, a new file name) -> {file}
//   php browser_helper.php cleanup-like <SQL LIKE pattern> ...   (songs a killed run left, e.g. "Loudness %"; one on air is hidden) -> {removed, hidden}
//   php browser_helper.php lib-songs <n>    -> n throwaway songs "Web Test Lib NN" and one tagged with a cover and copyright: {"ids", "tagged"}
//   php browser_helper.php lib-info <id>    -> the song's title, art file and rights
//   php browser_helper.php lib-set <id> <title> <artist> [<seconds>];  mb-seed / mb-unseed (the saved MusicBrainz answers in the lookup's cache)
//   php browser_helper.php ads-info <title> -> an ad's track and plan (or null);  ads-clean (every "Web Test Ad")
//   php browser_helper.php jingles-info <title> -> a jingle's track and row (or null);  jingles-clean (every "Web Test Jingle")
//   php browser_helper.php lib-clean        (every "Web Test Lib" song, and their art)
//   php browser_helper.php rate-fixture "<mp3>|<title>" ...  -> the songs imported, in order in a "[ww-test] Rate switch" playlist that a block plays from now: {ids, playlist, block, tracks}
//   php browser_helper.php rate-fixture-clean                 (the block, the playlist and the "[ww-test] Rate" songs)
//   php browser_helper.php live-start <title> -> {"public_id", "start"}
//   php browser_helper.php live-piece <public id> <seq> <mp3 file> [<seconds> | auto]
//   php browser_helper.php live-end <public id>
//   php browser_helper.php note <public id> <kind> <fields as JSON>  -> {"note"} or {"error"}
//   php browser_helper.php note-edit <public id> <note id> <fields as JSON>  -> {"note", "replaces"} or {"error"}
//   php browser_helper.php app-fixture [<minutes until the first live block>]  -> {blocks: [{id, title, start}], episode, show} for the listener app's phone test:
//        two live blocks ("[ww-test] ...") every day, and a published 3-minute episode with notes and slides placed in it
//        (add "pretty" for nice names, a presenter Pastor Daniel and drawn slides, for screenshots)
//   php browser_helper.php app-fixture-clean                                  (all of it, its files too)
//   php browser_helper.php verse-seed [<text>] | verse-clean               today's verse text put in the cache (-> {reference, text}) / the cached verse files removed
//   php browser_helper.php slide <shade> [<width> <height>]       -> {"file", "url"}: a generated JPEG kept as a slide picture
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
use Stationwagon\{Db, Library, Notes, Planner, Playlists, Podcast, Schedule, Slides, StationSettings, Verse};

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
    $row = $db->run('SELECT id, file, sha1, title, artist, duration_s, bitrate_kbps, sample_rate, loudness_lufs, source_lufs, level_gain_db, level_target, mastered, preset, preset_settings, outro, album, art, rights FROM ' . $db->table('tracks') .
        " WHERE station_id = ? AND title = ? AND status = 'ready' ORDER BY id DESC LIMIT 1", [$station['id'], $argv[2] ?? ''])->fetch();
    echo json_encode($row ?: null);
} elseif ($cmd === 'make-old') {
    $row = $db->run('SELECT id, file FROM ' . $db->table('tracks') . " WHERE station_id = ? AND title = ? AND status = 'ready' ORDER BY id DESC LIMIT 1", [$station['id'], $argv[2] ?? ''])->fetch();
    $from = $row ? SW_ROOT . '/storage/media/' . $row['file'] : '';
    $bytes = $row && is_file($from) ? (string) file_get_contents($from) : '';
    $old = str_replace("STATIONWAGON_PREP\0LAME 3.100", "STATIONWAGON_PREP\0lamejs 1.2", $bytes); // same length: the tag's size holds
    if ($bytes === '' || $old === $bytes) {
        echo json_encode(['error' => 'no song with a LAME 3.100 tag']);
    } else {
        $name = sha1($old) . '.mp3';
        file_put_contents(SW_ROOT . '/storage/media/' . $name, $old);
        $db->run('UPDATE ' . $db->table('tracks') . ' SET file = ? WHERE id = ?', [$name, $row['id']]);
        @unlink($from);
        echo json_encode(['file' => $name]);
    }
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
} elseif ($cmd === 'rate-fixture') {
    // round 51: songs at 44.1 and 48 kHz one after another. The MP3s (paths) are imported with the titles
    // given, put in order in a "[ww-test] Rate switch" playlist, and an all-hour block plays it from now.
    $files = array_slice($argv, 2);
    $lib = new Library($db, $station, SW_ROOT);
    $ids = [];
    foreach ($files as $i => $spec) {
        [$path, $title] = explode('|', $spec, 2);
        $ids[] = $lib->import($path, $title, 'Rate Tester', basename($path))['id'];
    }
    $lists = new Playlists($db, $station);
    [$pl] = $lists->create('[ww-test] Rate switch', 'in_order');
    $lists->add((int) $pl, $ids);
    $tz = new DateTimeZone($station['timezone'] ?: 'UTC');
    $at = fn(int $plus) => (new DateTimeImmutable('now', $tz))->modify("+$plus minutes")->format('H:i');
    [$block, $err] = (new Schedule($db, $station))->add(['title' => '[ww-test] Rate switch', 'kind' => 'playlist', 'playlist_id' => $pl, 'days' => [1, 2, 4, 8, 16, 32, 64],
        'start' => $at(-2), 'end' => $at(58)]);
    $planner->replan(microtime(true));
    $tracks = $db->run('SELECT id, title, duration_s, file FROM ' . $db->table('tracks') . ' WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchAll();
    echo json_encode(['ids' => $ids, 'playlist' => $pl, 'block' => $block, 'error' => $err, 'tracks' => $tracks]);
} elseif ($cmd === 'rate-fixture-clean') {
    $n = 0;
    foreach ($db->run('SELECT id FROM ' . $db->table('schedule') . " WHERE station_id = ? AND title = '[ww-test] Rate switch'", [$station['id']])->fetchAll(PDO::FETCH_COLUMN) as $b) {
        (new Schedule($db, $station))->delete((int) $b);
    }
    foreach ($db->run('SELECT id FROM ' . $db->table('playlists') . " WHERE station_id = ? AND name = '[ww-test] Rate switch'", [$station['id']])->fetchAll(PDO::FETCH_COLUMN) as $p) {
        (new Playlists($db, $station))->delete((int) $p);
    }
    $lib = new Library($db, $station, SW_ROOT);
    foreach ($db->run('SELECT id FROM ' . $db->table('tracks') . " WHERE station_id = ? AND (title LIKE '[ww-test] Rate %' OR title LIKE '[test] [ww-test] Rate %')", [$station['id']])->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if ($lib->delete((int) $id, $planner, microtime(true)) !== null) {
            $db->run('UPDATE ' . $db->table('tracks') . " SET status = 'error', title = CONCAT('[test] ', title) WHERE id = ? AND title NOT LIKE '[test] %'", [$id]); // on air now: hidden, removed by the next run
        }
        $n++;
    }
    $planner->replan(microtime(true));
    echo json_encode(['cleaned' => $n]);
} elseif ($cmd === 'lib-info') {
    $r = (new Library($db, $station, SW_ROOT))->get((int) ($argv[2] ?? 0));
    echo json_encode($r ? ['title' => $r['title'], 'artist' => $r['artist'], 'album' => $r['album'], 'art' => $r['art'], 'rating' => $r['rating'] === null ? null : (int) $r['rating'], 'rights' => $r['rights'] === null ? null : json_decode($r['rights'], true)] : null);
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
} elseif ($cmd === 'lib-set') {
    // a song's title, artist and length as the lookup test wants them: lib-set <id> <title> <artist> [<seconds>] (album and rights cleared)
    $db->run('UPDATE ' . $db->table('tracks') . ' SET title = ?, artist = ?, album = NULL, rights = NULL' . (isset($argv[5]) && (float) $argv[5] > 0 ? ', duration_s = ' . (float) $argv[5] : '') . ' WHERE id = ? AND station_id = ?',
        [$argv[3] ?? '', ($argv[4] ?? '') !== '' ? $argv[4] : null, (int) ($argv[2] ?? 0), $station['id']]);
    echo json_encode(['ok' => true]);
} elseif ($cmd === 'mb-seed' || $cmd === 'mb-unseed') {
    // the MusicBrainz answers kept in fixtures-musicbrainz.json, put in (or taken out of) the lookup's cache, so the tests never reach the internet
    $mb = new Stationwagon\MusicBrainz(SW_ROOT, 'Stationwagon/test ( test )');
    $n = 0;
    foreach (json_decode((string) file_get_contents(__DIR__ . '/fixtures-musicbrainz.json'), true) as $url => $f) {
        if ($cmd === 'mb-seed') {
            $mb->seed((string) $url, (string) $f['body'], (int) $f['code']);
        } else {
            @unlink(SW_ROOT . '/storage/cache/mb/' . sha1((string) $url) . '.json');
        }
        $n++;
    }
    echo json_encode(['answers' => $n]);
} elseif ($cmd === 'ads-info') {
    // an ad by its title: its track and its plan
    $r = $db->run('SELECT t.id, t.title, t.kind, t.art, t.artist, t.album, t.rights, t.loudness_lufs, t.mastered, t.file, t.sha1, a.* FROM ' . $db->table('tracks') . ' t LEFT JOIN ' . $db->table('ads') . ' a ON a.track_id = t.id WHERE t.station_id = ? AND t.title = ? ORDER BY t.id DESC LIMIT 1',
        [$station['id'], $argv[2] ?? ''])->fetch();
    echo json_encode($r ? $r + ['original' => is_file(SW_ROOT . '/storage/originals/' . $r['sha1'] . '.mp3') || $r['file'] === $r['sha1'] . '.mp3'] : null);
} elseif ($cmd === 'ads-clean') {
    // every "Web Test Ad" the tests made (one on air right now is hidden and removed by the next run)
    $lib = new Library($db, $station, SW_ROOT);
    $n = 0;
    foreach ($db->run('SELECT id FROM ' . $db->table('tracks') . ' WHERE station_id = ? AND kind = ? AND (title LIKE ? OR title LIKE ?)', [$station['id'], 'ad', 'Web Test Ad%', '[test] Web Test Ad%'])->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if ($lib->delete((int) $id, $planner, microtime(true)) !== null) {
            $db->run('UPDATE ' . $db->table('tracks') . " SET status = 'error', title = CONCAT('[test] ', title) WHERE id = ? AND title NOT LIKE '[test] %'", [$id]);
        }
        $n++;
    }
    $planner->replan(microtime(true));
    echo json_encode(['cleaned' => $n]);
} elseif ($cmd === 'jingles-info') {
    // a jingle by its title: its track and its row
    $r = $db->run('SELECT t.id, t.title, t.kind, t.art, t.artist, t.album, t.rights, t.loudness_lufs, t.mastered, t.file, t.sha1, j.* FROM ' . $db->table('tracks') . ' t LEFT JOIN ' . $db->table('jingles') . ' j ON j.track_id = t.id WHERE t.station_id = ? AND t.title = ? ORDER BY t.id DESC LIMIT 1',
        [$station['id'], $argv[2] ?? ''])->fetch();
    echo json_encode($r ? $r + ['original' => is_file(SW_ROOT . '/storage/originals/' . $r['sha1'] . '.mp3') || $r['file'] === $r['sha1'] . '.mp3'] : null);
} elseif ($cmd === 'jingles-clean') {
    // every "Web Test Jingle" the tests made (one on air right now is hidden and removed by the next run)
    $lib = new Library($db, $station, SW_ROOT);
    $n = 0;
    foreach ($db->run('SELECT id FROM ' . $db->table('tracks') . ' WHERE station_id = ? AND kind = ? AND (title LIKE ? OR title LIKE ?)', [$station['id'], 'jingle', 'Web Test Jingle%', '[test] Web Test Jingle%'])->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if ($lib->delete((int) $id, $planner, microtime(true)) !== null) {
            $db->run('UPDATE ' . $db->table('tracks') . " SET status = 'error', title = CONCAT('[test] ', title) WHERE id = ? AND title NOT LIKE '[test] %'", [$id]);
        }
        $n++;
    }
    $planner->replan(microtime(true));
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
} elseif ($cmd === 'cleanup-like') {
    $lib = new Library($db, $station, SW_ROOT);
    $removed = $hidden = 0;
    foreach (array_slice($argv, 2) as $like) {
        // also one an earlier run hid because it was on air then (its file would make this one "already there")
        foreach ($db->run('SELECT id, title FROM ' . $db->table('tracks') . " WHERE station_id = ? AND (title LIKE ? AND title NOT LIKE '[test] %' OR title LIKE ?)",
            [$station['id'], $like, '[test] Web Test Song ' . $like])->fetchAll() as $row) {
            $id = $row['id'];
            if ($lib->delete((int) $id, $planner, microtime(true)) === null) { $removed++; continue; }
            if (str_starts_with((string) $row['title'], '[test] ')) { continue; } // still on air: already hidden
            // on air right now: hidden like 'cleanup' does; web_test.php's next run removes it
            $db->run('UPDATE ' . $db->table('tracks') . " SET status = 'error', title = CONCAT('[test] Web Test Song ', title) WHERE id = ?", [$id]);
            $hidden++;
        }
    }
    $planner->replan(microtime(true));
    echo json_encode(['removed' => $removed, 'hidden' => $hidden]);
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
} elseif ($cmd === 'app-fixture-clean') {
    $n = 0;
    // the nicely named demo (app-fixture <minutes> pretty): everything of its presenter
    $demo = (int) $db->run('SELECT id FROM ' . $db->table('users') . ' WHERE email = ?', ['ww-demo@example.test'])->fetchColumn();
    if ($demo) {
        foreach ($db->run('SELECT id, public_id FROM ' . $db->table('episodes') . ' WHERE dj_user_id = ?', [$demo])->fetchAll() as $ep) {
            foreach (glob(SW_ROOT . '/storage/podcasts/' . $ep['public_id'] . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir(SW_ROOT . '/storage/podcasts/' . $ep['public_id']);
            $db->run('DELETE FROM ' . $db->table('episodes') . ' WHERE id = ?', [$ep['id']]);
            $n++;
        }
        foreach ($db->run('SELECT id FROM ' . $db->table('live_shows') . ' WHERE dj_user_id = ?', [$demo])->fetchAll(PDO::FETCH_COLUMN) as $show) {
            $db->run('DELETE FROM ' . $db->table('presenter_notes') . ' WHERE live_show_id = ?', [$show]);
            $db->run('DELETE FROM ' . $db->table('live_shows') . ' WHERE id = ?', [$show]);
        }
        $db->run('DELETE FROM ' . $db->table('schedule') . ' WHERE dj_user_id = ?', [$demo]);
        $db->run('DELETE FROM ' . $db->table('station_users') . ' WHERE user_id = ?', [$demo]);
        $db->run('DELETE FROM ' . $db->table('users') . ' WHERE id = ?', [$demo]);
    }
    foreach ($db->run('SELECT id, public_id FROM ' . $db->table('episodes') . " WHERE title LIKE '[ww-test]%'")->fetchAll() as $ep) {
        foreach (glob(SW_ROOT . '/storage/podcasts/' . $ep['public_id'] . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(SW_ROOT . '/storage/podcasts/' . $ep['public_id']);
        $db->run('DELETE FROM ' . $db->table('episodes') . ' WHERE id = ?', [$ep['id']]);
        $n++;
    }
    foreach ($db->run('SELECT id FROM ' . $db->table('live_shows') . " WHERE title LIKE '[ww-test]%'")->fetchAll(PDO::FETCH_COLUMN) as $show) {
        $db->run('DELETE FROM ' . $db->table('presenter_notes') . ' WHERE live_show_id = ?', [$show]);
        $db->run('DELETE FROM ' . $db->table('live_shows') . ' WHERE id = ?', [$show]);
    }
    $db->run('DELETE FROM ' . $db->table('schedule') . " WHERE title LIKE '[ww-test]%'");
    echo json_encode(['cleaned' => $n]);
} elseif ($cmd === 'app-fixture') {
    $tz = new DateTimeZone($station['timezone'] ?: 'UTC');
    $mins = (int) ($argv[2] ?? 20);
    $clock = fn(int $plus) => (new DateTimeImmutable('now', $tz))->modify("+$plus minutes")->format('H:i');
    $dj = (int) $db->run('SELECT id FROM ' . $db->table('users') . ' ORDER BY id LIMIT 1')->fetchColumn();
    $pretty = ($argv[3] ?? '') === 'pretty'; // nice names and slide pictures, for screenshots (clean with app-fixture-clean too)
    $tag = $pretty ? '' : '[ww-test] ';
    if ($pretty) {
        $dj = $db->insert('users', ['email' => 'ww-demo@example.test', 'name' => 'Pastor Daniel', 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), 'role' => 'dj', 'created_at' => gmdate('Y-m-d H:i:s')]);
        $db->insert('station_users', ['station_id' => $station['id'], 'user_id' => $dj]);
    }
    $sched = new Schedule($db, $station);
    $blocks = [];
    foreach ([[$tag . 'Morning Word', $mins, $mins + 45], [$tag . 'Evening Praise', $mins + 300, $mins + 360]] as [$title, $from, $to]) {
        [$id, $err] = $sched->add(['title' => $title, 'kind' => 'live', 'dj_user_id' => $dj, 'days' => [1, 2, 4, 8, 16, 32, 64], 'start' => $clock($from), 'end' => $clock($to)]);
        $blocks[] = ['id' => $id, 'title' => $title, 'start' => $clock($from), 'error' => $err];
    }
    // a published episode: a closed show of 3 minutes, its silent MP3, and notes posted along the way
    $start = floor(microtime(true)) - 7200;
    $pid = 'live-' . gmdate('Ymd-His', (int) $start) . '-' . bin2hex(random_bytes(3));
    $showId = $db->insert('live_shows', ['station_id' => $station['id'], 'public_id' => $pid, 'dj_user_id' => $dj, 'title' => $tag . 'Sunday Service', 'start_at' => $start,
        'open' => 0, 'closed_reason' => 'ended by DJ', 'last_piece_at' => $start + 180, 'created_at' => gmdate('Y-m-d H:i:s')]);
    $slides = new Slides($db, SW_ROOT);
    $pics = [];
    foreach ([[60, 640, 360, ['Three things', 'to hold on to', '1  Grace', '2  Faith', '3  Hope']], [160, 640, 360, ['Romans 8:28', 'All things work together', 'for good']]] as [$shade, $w, $h, $lines]) {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, $shade, 70, 160));
        if ($pretty) {
            imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 10, 42, 106));
            imagefilledrectangle($im, 0, $h - 10, $w, $h, imagecolorallocate($im, 53, 242, 160));
            foreach ($lines as $i => $line) {
                imagettftext($im, $i === 0 ? 40 : 30, 0, 40, 80 + $i * 56, imagecolorallocate($im, 244, 247, 255), 'C:/Windows/Fonts/arialbd.ttf', $line);
            }
        }
        ob_start();
        imagejpeg($im, null, 80);
        $pics[] = $slides->store((string) ob_get_clean())['file'];
    }
    $put = function (float $at, string $kind, array $body) use ($db, $station, $showId, $dj) {
        $db->insert('presenter_notes', ['station_id' => $station['id'], 'live_show_id' => $showId, 'user_id' => $dj, 'kind' => $kind, 'body' => json_encode($body, JSON_UNESCAPED_UNICODE), 'posted_at' => gmdate('Y-m-d H:i:s', (int) $at)]);
    };
    $put($start + 10, 'note', ['text' => 'Welcome to the service']);
    $put($start + 40, 'verse', ['ref' => 'John 3:16', 'version' => 'KJV', 'text' => 'For God so loved the world, that he gave his only begotten Son.', 'passage' => 'John 3:16']);
    $put($start + 70, 'slides', ['text' => 'Three points', 'images' => $pics]);
    $put($start + 100, 'refs', ['text' => 'Read along', 'refs' => ['Romans 8:28', 'Psalm 23'], 'version' => 'KJV']);
    $epPid = 'ep-' . gmdate('Ymd', (int) $start) . '-' . bin2hex(random_bytes(4));
    $mp3 = SW_ROOT . "/storage/podcasts/$epPid";
    mkdir($mp3, 0775, true);
    $frame = "\xFF\xFB\x90\x00" . str_repeat("\0", 413);
    file_put_contents("$mp3/sunday.mp3", str_repeat($frame, (int) ceil(180 * 44100 / 1152)));
    $now = gmdate('Y-m-d H:i:s');
    $epId = $db->insert('episodes', ['station_id' => $station['id'], 'public_id' => $epPid, 'live_show_id' => $showId, 'dj_user_id' => $dj, 'title' => $tag . 'Sunday Service',
        'description' => $pretty ? 'Pastor Daniel on grace, faith and hope.' : 'A test episode with notes and slides.', 'status' => 'published', 'recorded_at' => $start, 'audio_file' => 'sunday.mp3', 'audio_bytes' => filesize("$mp3/sunday.mp3"),
        'audio_seconds' => 180, 'mixed_at' => $now, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $db->insert('episode_takes', ['episode_id' => $epId, 'live_show_id' => $showId, 'kind' => 'aired', 'start_at' => $start, 'duration_s' => 180, 'complete' => 1, 'created_at' => $now]);
    echo json_encode(['blocks' => $blocks, 'episode' => $epPid, 'show' => $pid, 'slides' => $pics]);
} elseif ($cmd === 'verse-seed') {
    // today's verse text kept in the cache (this PC's PHP has no CA bundle to fetch it): -> {reference, text}
    $tz = new DateTimeZone($station['timezone'] ?: 'UTC');
    $date = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $ref = Verse::referenceFor($date);
    $text = ($argv[2] ?? '') !== '' ? $argv[2] : '[ww-test] verse text for ' . $ref;
    @mkdir(SW_ROOT . '/storage/cache', 0775, true);
    file_put_contents(SW_ROOT . '/storage/cache/verse-' . $date . '.json', json_encode(['ref' => $ref, 'text' => $text]));
    echo json_encode(['reference' => $ref, 'text' => $text, 'date' => $date]);
} elseif ($cmd === 'verse-clean') {
    foreach (glob(SW_ROOT . '/storage/cache/verse-*') ?: [] as $f) {
        @unlink($f);
    }
    echo json_encode(['cleaned' => true]);
} elseif ($cmd === 'slide') {
    $im = imagecreatetruecolor((int) ($argv[3] ?? 640), (int) ($argv[4] ?? 360));
    imagefill($im, 0, 0, imagecolorallocate($im, (int) ($argv[2] ?? 100), 80, 180));
    ob_start();
    imagejpeg($im, null, 80);
    echo json_encode((new Slides($db, SW_ROOT))->store((string) ob_get_clean()));
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
    // the show's own stream (round 73): notes are kept per stream
    $showStation = $show ? (Stationwagon\Streams::byId($db, (int) $show['station_id']) ?? $station) : $station;
    echo json_encode($show ? ['open' => (bool) $show['open'], 'closed_reason' => $show['closed_reason'], 'dj_user_id' => (int) $show['dj_user_id'], 'title' => $show['title'], 'pieces' => $pieces,
        'station_id' => (int) $show['station_id'], 'notes' => count((new Notes($db, Stationwagon\Streams::view($showStation, Stationwagon\Streams::main($db))))->forShow($show['public_id']))] : null);
} elseif ($cmd === 'stream-fixture') {
    // round 73: a throwaway further stream, a DJ who may go live only there, and their live block on it (every day, all day)
    $tag = bin2hex(random_bytes(3));
    [$sid, $why] = Stationwagon\Streams::create($db, "[test] Stream $tag", "test-stream-$tag");
    if ($sid === null) {
        echo json_encode(['error' => $why]);
        exit(1);
    }
    $email = "web-test-$tag-stream@example.test";
    $password = bin2hex(random_bytes(12));
    $id = $db->insert('users', ['email' => $email, 'name' => 'Stream Test DJ', 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'dj', 'created_at' => gmdate('Y-m-d H:i:s')]);
    $db->insert('station_users', ['station_id' => $station['id'], 'user_id' => $id]);
    Stationwagon\Streams::setFor($db, $id, [$sid]);
    [$block, $errs] = (new Schedule($db, Stationwagon\Streams::station($db, "test-stream-$tag")))->add(['kind' => 'live', 'title' => '[test] Stream test show', 'dj_user_id' => $id, 'days' => array_keys(Schedule::DAYS), 'start' => '00:00', 'end' => '00:00']);
    echo json_encode(['stream' => "test-stream-$tag", 'stream_id' => $sid, 'name' => "[test] Stream $tag", 'id' => $id, 'email' => $email, 'password' => $password, 'block' => $block, 'errors' => $errs]);
} elseif ($cmd === 'stream-listeners') {
    // how many listen to a stream now (no name: the main one)
    $st = Stationwagon\Streams::station($db, ($argv[2] ?? '') ?: null);
    echo json_encode(['now' => $st ? (new Stationwagon\Listeners($db, $st))->now(microtime(true)) : null]);
} elseif ($cmd === 'stream-clean') {
    // the stream-fixture's: shows ended on their stream, notes, episodes, running orders, the DJ and the stream itself (and any "[test] Stream" a killed run left)
    $userId = (int) ($argv[2] ?? 0);
    foreach (Stationwagon\Streams::all($db) as $s) {
        if ($s['main'] || !str_starts_with((string) $s['name'], '[test] Stream ')) {
            continue;
        }
        $view = Stationwagon\Streams::view($s, Stationwagon\Streams::main($db));
        $pl = new Planner($db, $view, SW_ROOT);
        $notes = new Notes($db, $view);
        $shows = $db->run('SELECT id, public_id, `open` FROM ' . $db->table('live_shows') . ' WHERE station_id = ?', [$s['id']])->fetchAll();
        foreach ($shows as $sh) {
            if ((int) $sh['open'] === 1) {
                $pl->endLive(microtime(true), $sh['public_id']);
            }
            foreach ($notes->forShow($sh['public_id']) as $n) {
                $notes->remove($sh['public_id'], $n['id']);
            }
        }
        test_episodes_gone($db, array_column($shows, 'id'));
        $db->run('DELETE FROM ' . $db->table('running_orders') . ' WHERE station_id = ?', [$s['id']]);
        Stationwagon\Streams::delete($db, (int) $s['id']);
    }
    $db->run('DELETE FROM ' . $db->table('users') . " WHERE (id = ? OR email LIKE 'web-test-%-stream@example.test') AND email LIKE 'web-test-%'", [$userId]);
    echo json_encode(['cleaned' => true]);
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
