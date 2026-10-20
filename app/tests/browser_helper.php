<?php
// Database and planner side of the browser tests (Laragon only): a throwaway manager, a look at
// uploaded tracks, a test live show, and the cleanup. Prints JSON.
//   php browser_helper.php manager            -> {"id", "email", "password"}
//   php browser_helper.php track <title>      -> the newest ready track with that title, or null
//   php browser_helper.php cleanup <user id> [<title> ...]
//   php browser_helper.php live-start <title> -> {"public_id", "start"}
//   php browser_helper.php live-piece <public id> <seq> <mp3 file> [<seconds> | auto]
//   php browser_helper.php live-end <public id>
//   php browser_helper.php note <public id> <kind> <fields as JSON>  -> {"note"} or {"error"}
//   php browser_helper.php notes-clear <public id>                     (the test show's notes)
//   php browser_helper.php dj [block]              -> a throwaway DJ {"id", "email", "password", "block"}
//   php browser_helper.php live-info <public id>   -> the show, its pieces (scanned) and how many notes
//   php browser_helper.php studio-cleanup <user id> [<block id>]   (shows, notes, running orders, block, DJ)
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Db, Library, Notes, Planner, Schedule};

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
    $row = $db->run('SELECT id, file, sha1, title, artist, duration_s, bitrate_kbps, sample_rate, loudness_lufs FROM ' . $db->table('tracks') .
        " WHERE station_id = ? AND title = ? AND status = 'ready' ORDER BY id DESC LIMIT 1", [$station['id'], $argv[2] ?? ''])->fetch();
    echo json_encode($row ?: null);
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
    $db->run('DELETE FROM ' . $db->table('running_orders') . ' WHERE station_id = ? AND scope IN (?, ?)', [$station['id'], "block:$blockId", "user:$userId"]);
    if ($blockId) {
        $db->run('DELETE FROM ' . $db->table('schedule') . " WHERE id = ? AND title LIKE '[test]%'", [$blockId]);
    }
    $db->run('DELETE FROM ' . $db->table('users') . ' WHERE id = ? AND email LIKE ?', [$userId, 'web-test-%']);
    echo json_encode(['cleaned' => true]);
} else {
    fwrite(STDERR, "Usage: php browser_helper.php manager | track <title> | cleanup <user id> [<title> ...] | live-start <title> | live-piece <id> <seq> <file> <s> | live-end <id> | note <id> <kind> <json> | notes-clear <id> | dj [block] | live-info <id> | studio-cleanup <user id> [<block id>]" . PHP_EOL);
    exit(1);
}
