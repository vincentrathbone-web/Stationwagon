<?php
// Command-line test of the live studio's rules: the schema upgrade that adds running orders, when
// a DJ may go live (only in their own live block, from 5 minutes before; managers any time), who
// may send pieces, post notes and end a show, and the running order.
//   php app/tests/studio_test.php      (same SW_TEST_DB_* settings as install_test.php)
declare(strict_types=1);
require __DIR__ . '/lib.php';
use Stationwagon\{Db, Library, Planner, Schedule, Schema, Studio};

$t = test_database('studio');
$root = temp_root('studio');
try {
    $cfg = test_install($t, $root);
    $db = Db::connect($cfg['db']);
    $station = $db->run('SELECT * FROM ' . $db->table('stations'))->fetch();

    echo "Database upgrades (to version " . Schema::VERSION . ")\n";
    $tables = array_column($db->run('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0);
    $cols = fn(string $t) => array_column($db->run('SHOW COLUMNS FROM ' . $db->table($t))->fetchAll(), 'Field');
    check('a new install has the running order table and the version 3 columns, at the latest version', in_array('sw_running_orders', $tables, true) && Schema::installed($db) === Schema::VERSION
        && in_array('outro', $cols('tracks'), true) && in_array('continues_show_id', $cols('live_shows'), true));
    $db->pdo->exec('DROP TABLE ' . $db->table('running_orders'));
    $db->pdo->exec('ALTER TABLE ' . $db->table('tracks') . ' DROP COLUMN outro');
    $db->pdo->exec('ALTER TABLE ' . $db->table('live_shows') . ' DROP COLUMN continues_show_id');
    $db->pdo->exec('DROP TABLE ' . $db->table('listener_hours'));
    $db->pdo->exec('DROP TABLE ' . $db->table('listeners'));
    $db->pdo->exec('ALTER TABLE ' . $db->table('presenter_notes') . ' DROP COLUMN replaced_by');
    $db->run('UPDATE ' . $db->table('settings') . " SET value = '1' WHERE name = 'schema_version'");
    $done = Schema::upgrade($db);
    $tables = array_column($db->run('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0);
    check('a version 1 database is brought up to the latest version, step by step', $done === range(2, Schema::VERSION) && in_array('sw_running_orders', $tables, true) && in_array('sw_listeners', $tables, true) && Schema::installed($db) === Schema::VERSION
        && in_array('outro', $cols('tracks'), true) && in_array('continues_show_id', $cols('live_shows'), true), json_encode($done));
    check('  and upgrading again changes nothing', Schema::upgrade($db) === []);
    @mkdir("$root/storage/cache", 0775, true);
    Schema::ensure($db, $root);
    check('the quick check remembers the version in a small file', trim((string) @file_get_contents("$root/storage/cache/schema-version")) === (string) Schema::VERSION);

    // people: the installer's admin, a manager and three DJs
    $user = function (string $name, string $role) use ($db, $station): array {
        $id = $db->insert('users', ['email' => strtolower(str_replace(' ', '.', $name)) . '@example.test', 'name' => $name, 'password_hash' => 'x', 'role' => $role, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $db->insert('station_users', ['station_id' => $station['id'], 'user_id' => $id]);
        return ['id' => $id, 'name' => $name, 'role' => $role];
    };
    $mgr = $user('Mary Manager', 'manager');
    $sidney = $user('Sidney Lottering', 'dj');
    $anna = $user('Anna Botha', 'dj');
    $pieter = $user('Pieter Nel', 'dj');

    $lib = new Library($db, $station, $root);
    mkdir("$root/in");
    $tracks = [];
    foreach (['Amazing Grace' => 150, 'Be Thou My Vision' => 170, 'Station ID' => 12] as $name => $secs) {
        make_mp3("$root/in/tmp", $secs, [], $secs);
        $tracks[$name] = (int) $lib->import("$root/in/tmp", null, null, "$name.mp3")['id'];
    }
    $sched = new Schedule($db, $station);
    // 1800000000 = Friday 15 January 2027, 10:00 in Johannesburg
    [$morning] = $sched->add(['kind' => 'live', 'title' => 'Morning message', 'dj_user_id' => $sidney['id'], 'days' => [16], 'start' => '09:30', 'end' => '10:30']);
    [$evening] = $sched->add(['kind' => 'live', 'title' => 'Evening praise', 'dj_user_id' => $anna['id'], 'days' => [1, 2, 4, 8, 16, 32, 64], 'start' => '19:00', 'end' => '20:00']);
    [$night] = $sched->add(['kind' => 'live', 'title' => 'Night watch', 'dj_user_id' => $anna['id'], 'days' => [8], 'start' => '23:00', 'end' => '01:00']);
    [$open] = $sched->add(['kind' => 'live', 'title' => 'Open mic', 'dj_user_id' => '', 'days' => [32], 'start' => '08:00', 'end' => '09:00']);
    check('the test blocks are in the schedule', $morning && $evening && $night && $open);

    $p = new Planner($db, $station, $root);
    $studio = new Studio($p);
    $now = 1800000000.0;

    echo "When a DJ may go live\n";
    $s = $studio->slot($sidney, $now);
    check('a DJ may go live in their own block', $s['may'] && $s['title'] === 'Morning message' && $s['scope'] === "block:$morning", json_encode($s));
    check('  which runs 09:30–10:30 on the station’s clock', $s['block']['start'] === $now - 1800 && $s['block']['end'] === $now + 1800 && $s['block']['dj'] === 'Sidney Lottering');
    $s = $studio->slot($anna, $now);
    check('another DJ may not', !$s['may']);
    check('  and is told when their own show is', str_contains((string) $s['reason'], 'Evening praise') && str_contains((string) $s['reason'], 'today at 19:00') && str_contains((string) $s['reason'], 'from 18:55'), (string) $s['reason']);
    $at1856 = $now + 8 * 3600 + 56 * 60;
    check('a DJ may go live from 5 minutes before their block', $studio->slot($anna, $at1856)['may'] && !$studio->slot($anna, $at1856 - 120)['may']);
    check('  until it ends', $studio->slot($anna, $now + 9.5 * 3600)['may'] && !$studio->slot($anna, $now + 10 * 3600 + 1)['may']);
    $s = $studio->slot($anna, $now - 9.5 * 3600); // Friday 00:30
    check('a block past midnight belongs to the day it starts', $s['may'] && $s['title'] === 'Night watch' && $s['block']['start'] === $now - 11 * 3600, json_encode($s['block']));
    $sat0830 = $now + 86400 - 5400;
    check('a block with no DJ named is open to every DJ', $studio->slot($pieter, $sat0830)['may'] && $studio->slot($pieter, $sat0830)['title'] === 'Open mic');
    check('  and counts as their next show', str_contains((string) $studio->slot($pieter, $now)['reason'], 'Open mic, starts tomorrow at 08:00'), (string) $studio->slot($pieter, $now)['reason']);
    $s = $studio->slot($mgr, $now + 5 * 3600);
    check('a manager may go live any time', $s['may'] && $s['title'] === 'Live with Mary Manager' && $s['scope'] === 'user:' . $mgr['id'] && $s['block'] === null, json_encode($s));
    $s = $studio->slot($mgr, $now);
    check('  inside a DJ’s block the show takes that block’s name', $s['may'] && $s['title'] === 'Morning message');

    echo "On air\n";
    $p->timeline($now);
    check('a DJ outside their block can’t go live', ($studio->goLive($anna, $now)['status'] ?? 0) === 409);
    $went = $studio->goLive($sidney, $now);
    check('a DJ goes live in their block', isset($went['live']['public_id']) && $went['live']['title'] === 'Morning message', json_encode($went['error'] ?? ''));
    check('  handing over from the AutoDJ song, with its address', ($went['handover']['type'] ?? '') === 'song' && str_starts_with((string) $went['handover']['url'], 'storage/media/'));
    $pid = $went['live']['public_id'];
    $start = $went['live']['start'];
    check('  under their own name', (int) $db->run('SELECT dj_user_id FROM ' . $db->table('live_shows') . ' WHERE public_id = ?', [$pid])->fetchColumn() === $sidney['id']);
    check('nobody else can go live meanwhile', !$studio->slot($mgr, $now + 1)['may'] && str_contains((string) $studio->slot($mgr, $now + 1)['reason'], 'Sidney Lottering is on air now'), (string) $studio->slot($mgr, $now + 1)['reason']);
    check('  and the DJ is told their show is still on', str_contains((string) $studio->slot($sidney, $now + 1)['reason'], 'still on air'));
    make_mp3("$root/piece.mp3", 6.0, [], 1);
    $piece = (string) file_get_contents("$root/piece.mp3");
    check('another DJ can’t send pieces to the show', ($studio->addPiece($anna, $pid, 0, 6.0, $piece, $start + 6)['status'] ?? 0) === 403);
    check('pieces must be MP3 audio', ($studio->addPiece($sidney, $pid, 0, 6.0, 'hello world', $start + 6)['status'] ?? 0) === 400);
    check('  of a sensible length', ($studio->addPiece($sidney, $pid, 0, 0.0, $piece, $start + 6)['status'] ?? 0) === 400 && ($studio->addPiece($sidney, $pid, 0, 60.0, $piece, $start + 6)['status'] ?? 0) === 400);
    $r = $studio->addPiece($sidney, $pid, 0, 6.0, $piece, $start + 6);
    check('the DJ sends the first piece', ($r['ok'] ?? false) && $r['expected'] === 1, json_encode($r));
    check('  a gap is refused (409)', ($studio->addPiece($sidney, $pid, 2, 6.0, $piece, $start + 7)['status'] ?? 0) === 409);
    check('  a piece sent twice is taken once', ($studio->addPiece($sidney, $pid, 0, 6.0, $piece, $start + 7)['duplicate'] ?? false) === true);
    check('a manager may send pieces to any show', ($studio->addPiece($mgr, $pid, 1, 6.0, $piece, $start + 12)['ok'] ?? false) === true);
    check('  the show is still open', $studio->openShow($start + 13)['public_id'] === $pid);

    echo "Notes from the studio\n";
    $n = $studio->postNote($sidney, $pid, 'verse', ['ref' => 'Psalm 23:1', 'text' => 'The LORD is my shepherd; I shall not want.'], $start + 14);
    check('the DJ posts a note to their show', ($n['status'] ?? 0) === 200 && isset($n['note']['id']), json_encode($n));
    check('  a bad note says why (422)', ($studio->postNote($sidney, $pid, 'video', ['text' => 'x', 'url' => 'ftp://x'], $start + 14)['status'] ?? 0) === 422);
    check('another DJ can’t post to it', ($studio->postNote($anna, $pid, 'note', ['text' => 'Hi'], $start + 14)['status'] ?? 0) === 403);
    check('  or remove its notes', ($studio->removeNote($anna, $pid, $n['note']['id'])['status'] ?? 0) === 403);
    check('  or change them', ($studio->editNote($anna, $pid, $n['note']['id'], ['ref' => 'Psalm 1', 'text' => 'Blessed'], $start + 14)['status'] ?? 0) === 403);
    $e = $studio->editNote($sidney, $pid, $n['note']['id'], ['ref' => 'Psalm 23:1', 'text' => 'The LORD is my shepherd; I shall not want!'], $start + 14.1);
    check('the DJ changes a note: it comes back as a new one', ($e['status'] ?? 0) === 200 && $e['replaces'] === $n['note']['id'] && array_column($studio->notes($pid), 'id') === [$e['note']['id']]);
    check('  a bad change says why (422)', ($studio->editNote($sidney, $pid, $e['note']['id'], ['ref' => '', 'text' => 'x'], $start + 14.1)['status'] ?? 0) === 422);
    $n = $e;
    check('the studio lists the show’s notes', array_column($studio->notes($pid), 'id') === [$n['note']['id']]);
    check('the DJ removes a note', ($studio->removeNote($sidney, $pid, $n['note']['id'])['removed'] ?? 0) === $n['note']['id'] && $studio->notes($pid) === []);
    check('  once', ($studio->removeNote($sidney, $pid, $n['note']['id'])['status'] ?? 0) === 404);

    echo "Rejoining\n";
    $rj = $studio->slot($sidney, $start + 14)['rejoin'];
    check('a DJ whose studio reloaded may rejoin their open show', $rj && $rj['open'] && $rj['public_id'] === $pid, json_encode($rj));
    check('  another DJ may not', $studio->slot($anna, $start + 14)['rejoin'] === null && ($studio->rejoin($anna, $start + 14)['status'] ?? 0) === 409);
    $kept = $studio->postNote($sidney, $pid, 'note', ['text' => 'Still here'], $start + 14.2);
    $back = $studio->rejoin($sidney, $start + 14.5);
    $old = $pid;
    $pid = $back['live']['public_id'] ?? '';
    check('rejoining carries on as a new show that continues it', ($back['continued'] ?? '') === $old && $pid !== $old && $back['live']['title'] === 'Morning message', json_encode($back));
    check('  with fresh timing: it starts ahead, at a piece boundary', $back['live']['start'] > $start + 14.5 && ($back['handover']['type'] ?? '') === 'song', json_encode($back['handover'] ?? null));
    check('  the first show is closed as rejoined', $db->run('SELECT closed_reason FROM ' . $db->table('live_shows') . ' WHERE public_id = ?', [$old])->fetchColumn() === 'rejoined');
    check('  the notes come along', array_column($studio->notes($pid), 'id') === [$kept['note']['id']]);
    $start = $back['live']['start'];
    check('  and its pieces start from 0', ($studio->addPiece($sidney, $pid, 0, 6.0, $piece, $start + 6)['expected'] ?? 0) === 1);

    echo "Ending\n";
    check('another DJ can’t end the show', ($studio->endLive($anna, $pid, $start + 15)['status'] ?? 0) === 403);
    $end = $studio->endLive($sidney, $pid, $start + 15);
    check('the DJ ends it', ($end['ended'] ?? false) === true, json_encode($end));
    check('  and the AutoDJ is free again', $studio->openShow($start + 16) === null && $studio->slot($mgr, $start + 16)['may']);
    $t2 = $now + 5 * 3600;
    $p->timeline($t2);
    $quiet = $studio->goLive($mgr, $t2);
    check('a manager goes live outside any block', isset($quiet['live']) && $quiet['live']['title'] === 'Live with Mary Manager', json_encode($quiet['error'] ?? ''));
    $qpid = $quiet['live']['public_id'];
    $qnote = $studio->postNote($mgr, $qpid, 'note', ['text' => 'Back after this'], $quiet['live']['start'] + 1);
    check('a show that sends nothing is closed by the safety net', $studio->openShow($quiet['live']['start'] + 30) === null
        && $db->run('SELECT closed_reason FROM ' . $db->table('live_shows') . ' WHERE public_id = ?', [$quiet['live']['public_id']])->fetchColumn() === 'timeout');

    echo "Rejoin window\n";
    $qs = $quiet['live']['start'] + 30;
    $ls = $db->table('live_shows');
    $qlast = (float) $db->run("SELECT last_piece_at FROM $ls WHERE public_id = ?", [$qpid])->fetchColumn();
    $rj = $studio->slot($mgr, $qs)['rejoin'];
    check('a show the safety net ended may be carried on for 10 minutes', $rj && !$rj['open'] && $rj['public_id'] === $qpid && abs($rj['until'] - ($qlast + 600)) < 1e-6, json_encode($rj));
    $off = json_decode((string) $station['settings'], true);
    $studioOff = new Studio(new Planner($db, ['settings' => json_encode(['rejoin_minutes' => 0] + (is_array($off) ? $off : []))] + $station, $root));
    check('  not when the window is 0', $studioOff->slot($mgr, $qs)['rejoin'] === null && ($studioOff->rejoin($mgr, $qs)['status'] ?? 0) === 409);
    $c = $studio->rejoin($mgr, $qs);
    $cpid = $c['live']['public_id'] ?? '';
    check('rejoining goes live again as a show that continues it', ($c['continued'] ?? '') === $qpid && $cpid !== $qpid && $c['live']['title'] === 'Live with Mary Manager' && ($c['handover']['type'] ?? '') === 'song', json_encode($c));
    check('  linked to the first show', (int) $db->run("SELECT continues_show_id FROM $ls WHERE public_id = ?", [$cpid])->fetchColumn()
        === (int) $db->run("SELECT id FROM $ls WHERE public_id = ?", [$qpid])->fetchColumn());
    check('  taking its notes along', array_column($studio->notes($cpid), 'id') === [$qnote['note']['id']] && $studio->notes($qpid) === []);
    $again = $studio->rejoin($mgr, $qs + 1);
    check('  rejoining again carries on the new show, not the old one', ($again['continued'] ?? '') === $cpid && ($again['live']['public_id'] ?? $cpid) !== $cpid);
    $cpid = $again['live']['public_id'] ?? '';
    $cs = $again['live']['start'] + 30;
    check('the second show also times out', $studio->openShow($cs) === null);
    $clast = (float) $db->run("SELECT last_piece_at FROM $ls WHERE public_id = ?", [$cpid])->fetchColumn();
    check('  and may be carried on in its window', ($studio->slot($mgr, $cs)['rejoin']['public_id'] ?? '') === $cpid);
    check('  but not after it', $studio->slot($mgr, $clast + 601)['rejoin'] === null && ($studio->rejoin($mgr, $clast + 601)['status'] ?? 0) === 409);

    echo "Running order\n";
    check('a DJ may plan their own block', $studio->mayEditOrder($sidney, "block:$morning") && !$studio->mayEditOrder($sidney, "block:$evening"));
    check('  and their own list outside blocks, not someone else’s', $studio->mayEditOrder($sidney, 'user:' . $sidney['id']) && !$studio->mayEditOrder($sidney, 'user:' . $anna['id']));
    check('  and an open block', $studio->mayEditOrder($pieter, "block:$open"));
    check('a manager may plan any show', $studio->mayEditOrder($mgr, "block:$evening") && $studio->mayEditOrder($mgr, 'user:' . $anna['id']) && !$studio->mayEditOrder($mgr, 'other'));
    $items = [['kind' => 'id', 'track_id' => $tracks['Station ID']], ['kind' => 'talk', 'title' => '  Welcome   and prayer ', 'seconds' => 240],
              ['kind' => 'song', 'track_id' => $tracks['Amazing Grace']], ['kind' => 'talk', 'title' => 'Message: Psalm 23', 'seconds' => 540]];
    $r = $studio->saveOrder($sidney, "block:$morning", $items);
    check('a running order is saved', ($r['status'] ?? 0) === 200 && count($r['items']) === 4, json_encode($r));
    check('  songs come back with title, length and address', $r['items'][2]['title'] === 'Amazing Grace' && abs($r['items'][2]['seconds'] - 150) < 1 && str_starts_with($r['items'][2]['url'], 'storage/media/'), json_encode($r['items'][2]));
    check('  talk keeps its tidied name and length', $r['items'][1] === ['kind' => 'talk', 'title' => 'Welcome and prayer', 'seconds' => 240]);
    check('  it is there next time', array_column($studio->order("block:$morning"), 'kind') === ['id', 'talk', 'song', 'talk']);
    check('saving again replaces it', count($studio->saveOrder($sidney, "block:$morning", [$items[2]])['items']) === 1 && count($studio->order("block:$morning")) === 1);
    check('another DJ can’t change it', ($studio->saveOrder($anna, "block:$morning", [])['status'] ?? 0) === 403);
    check('unknown kinds are refused', ($studio->saveOrder($sidney, "block:$morning", [['kind' => 'advert']])['status'] ?? 0) === 422);
    check('talk needs a name and 1 s to 2 h', ($studio->saveOrder($sidney, "block:$morning", [['kind' => 'talk', 'title' => '', 'seconds' => 60]])['status'] ?? 0) === 422
        && ($studio->saveOrder($sidney, "block:$morning", [['kind' => 'talk', 'title' => 'x', 'seconds' => 7201]])['status'] ?? 0) === 422);
    check('songs must be in the library', ($studio->saveOrder($sidney, "block:$morning", [['kind' => 'song', 'track_id' => 99999]])['status'] ?? 0) === 422);
    check('a running order holds ' . Studio::ORDER_MAX . ' items at most', ($studio->saveOrder($sidney, "block:$morning", array_fill(0, Studio::ORDER_MAX + 1, $items[1]))['status'] ?? 0) === 422);
    check('  and must be a list', ($studio->saveOrder($sidney, "block:$morning", ['a' => $items[1]])['status'] ?? 0) === 400);
    $studio->saveOrder($sidney, "block:$morning", $items);
    $db->run('UPDATE ' . $db->table('tracks') . " SET status = 'error' WHERE id = ?", [$tracks['Amazing Grace']]);
    check('a song taken out of the library drops out of the list', array_column($studio->order("block:$morning"), 'kind') === ['id', 'talk', 'talk']);

    $sched->delete($open);
    check('a DJ with no block at all is told to ask a manager', !$studio->slot($pieter, $now)['may'] && str_contains((string) $studio->slot($pieter, $now)['reason'], 'Ask a manager'));
    check('  but with the "On air" tick they may go live any time', $studio->slot($pieter + ['permissions' => '["onair"]'], $now)['may']);
    check('a manager without "On air" can’t go live outside a block', !$studio->slot($mgr + ['permissions' => '["schedule"]'], $now)['may']
        && !$studio->mayEditOrder($mgr + ['permissions' => '[]'], 'user:' . $anna['id']));
    check('a blocked manager may do nothing here', !$studio->slot($mgr + ['blocked_at' => '2027-01-01 00:00:00'], $now)['may']);

    echo "Library search\n";
    $found = $studio->songs('vision');
    check('the studio finds songs by title', count($found) === 1 && $found[0]['title'] === 'Be Thou My Vision' && $found[0]['track_id'] === $tracks['Be Thou My Vision'], json_encode($found));
    check('  only ready songs', $studio->songs('Amazing') === []);
} finally {
    drop_test_database($t);
    remove_tree($root);
}
finish();
