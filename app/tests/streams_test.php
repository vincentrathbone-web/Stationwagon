<?php
// Command-line test of the streams (round 73, phase v1.3): a further stream is a station row under the main station; it has its own
// timeline, schedule and live shows and shares the library, the settings and the people; the address names; who may go live where;
// and the upgrade to schema 20.
// Makes its own silent MP3s and installs into a throwaway database, like ads_test.php.
//   php app/tests/streams_test.php      (same SW_TEST_DB_* settings as install_test.php)
declare(strict_types=1);
require __DIR__ . '/lib.php';
use Stationwagon\{Ads, Db, Jingles, Library, Planner, Schedule, Schema, Streams};

$t = test_database('streams');
$root = temp_root('streams');
try {
    $cfg = test_install($t, $root);
    $db = Db::connect($cfg['db']);
    $main = $db->run('SELECT * FROM ' . $db->table('stations'))->fetch();
    $tl = $db->table('timeline');
    $st = $db->table('stations');

    echo "The main station is stream 1\n";
    $all = Streams::all($db);
    check('there is one stream: the main station', count($all) === 1 && $all[0]['main'] === true && (int) $all[0]['id'] === (int) $main['id'] && $main['parent_id'] === null);
    check('its address name finds it, and so does none', Streams::bySlug($db, null)['id'] === $main['id'] && Streams::bySlug($db, '')['main'] === true && Streams::bySlug($db, $main['slug'])['main'] === true);
    check('an unknown name finds nothing', Streams::bySlug($db, 'nobody') === null && Streams::planner($db, 'nobody') === null);

    echo "Address names\n";
    check('good names pass', Streams::slugProblem('gansbaai') === null && Streams::slugProblem('gospel-2') === null && Streams::slugProblem('ab') === null);
    foreach (['A', 'a', 'Upper', 'has space', '-start', 'end-', 'x_y', 'manage', 'api', 'studio', '12345', str_repeat('a', 41)] as $bad) {
        check('  “' . $bad . '” is refused', Streams::slugProblem($bad) !== null, (string) Streams::slugProblem($bad));
    }
    check('a name becomes an address name', Streams::slugFor('Gansbaai Gospel!') === 'gansbaai-gospel' && Streams::slugFor('  Ré-mix  Radio ') === 're-mix-radio');

    echo "Making and changing a stream\n";
    [$none, $why] = Streams::create($db, '  ', 'x1');
    check('a stream needs a name', $none === null && $why === 'A stream needs a name.');
    [$none, $why] = Streams::create($db, 'Gospel', 'manage');
    check('  and an address name the app does not use', $none === null && $why !== null && str_contains($why, 'already uses'));
    [$none, $why] = Streams::create($db, 'Gospel', $main['slug']);
    check('  or the main station’s own', $none === null && $why !== null);
    [$g, $why] = Streams::create($db, 'Gospel Hits', 'gospel');
    check('a stream is made', is_int($g) && $why === null && count(Streams::all($db)) === 2);
    [$none, $why] = Streams::create($db, 'Again', 'gospel');
    check('  two streams cannot share an address name', $none === null && $why === 'There is already a stream with that address name.');
    $gs = Streams::bySlug($db, 'gospel');
    check('it is found by its name, not the main one, under the main station, with the station’s time zone', $gs !== null && (int) $gs['id'] === $g && $gs['main'] === false && (int) $gs['parent_id'] === (int) $main['id'] && $gs['timezone'] === $main['timezone']);
    check('  it is not the first station, whatever its id', Streams::main($db)['id'] === $main['id']);
    check('it can be renamed and moved to another address name', Streams::update($db, $g, 'Gospel Classics', 'classics') === null && Streams::bySlug($db, 'classics')['name'] === 'Gospel Classics' && Streams::bySlug($db, 'gospel') === null);
    check('  not onto a name in use, and not the main stream', Streams::update($db, $g, 'x', $main['slug']) !== null && Streams::update($db, (int) $main['id'], 'x', 'other') === 'That stream isn’t here.');
    Streams::update($db, $g, 'Gospel Hits', 'gospel');

    echo "A stream shares the library and the settings\n";
    mkdir("$root/in");
    $lib = new Library($db, $main, $root);
    $songs = [];
    foreach (['One', 'Two', 'Three', 'Four', 'Five', 'Six'] as $i => $name) {
        make_mp3("$root/in/$name.mp3", 120 + $i * 5, ['title' => "Song $name", 'artist' => 'The Testers'], $i);
        $songs[$name] = $lib->import("$root/in/$name.mp3")['id'];
    }
    $mainView = Streams::view(Streams::bySlug($db, null), $main);
    $gView = Streams::station($db, 'gospel');
    check('a stream’s view is its own row with the main station’s settings and library', (int) $gView['id'] === $g && (int) $gView['library_id'] === (int) $main['id'] && $gView['settings'] === $main['settings'] && $gView['name'] === 'Gospel Hits');
    check('  the main stream’s view has its own library', (int) $mainView['library_id'] === (int) $main['id'] && (int) $mainView['id'] === (int) $main['id']);
    $t0 = (float) (new DateTimeImmutable('2027-01-15 10:00:00', new DateTimeZone($main['timezone'])))->getTimestamp();
    $pm = Streams::planner($db, null);
    $pg = Streams::planner($db, 'gospel');
    $em = $pm->timeline($t0);
    $eg = $pg->timeline($t0);
    check('each stream plans its own timeline from the same songs', count($em) > 10 && count($eg) > 10 && !array_diff(array_column($eg, 'track_id'), array_values($songs)) && !array_diff(array_column($em, 'track_id'), array_values($songs)));
    $nm = (int) $db->run("SELECT COUNT(*) FROM $tl WHERE station_id = ?", [$main['id']])->fetchColumn();
    $ng = (int) $db->run("SELECT COUNT(*) FROM $tl WHERE station_id = ?", [$g])->fetchColumn();
    check('  and keeps it apart: every row says which stream it is', $nm === count($em) && $ng === count($eg) && $nm + $ng === (int) $db->run("SELECT COUNT(*) FROM $tl")->fetchColumn());
    check('  the two do not play the same sequence (each shuffles on its own)', array_column($em, 'track_id') !== array_column($eg, 'track_id'));
    $pm->replan($t0 + 30);
    check('replanning one leaves the other’s timeline alone', (int) $db->run("SELECT COUNT(*) FROM $tl WHERE station_id = ?", [$g])->fetchColumn() === $ng);

    echo "Ads and jingles on a stream\n";
    make_mp3("$root/in/Ad A.mp3", 20, ['title' => 'Ad A'], 70);
    $ad = $lib->import("$root/in/Ad A.mp3", null, null, 'Ad A.mp3', null, null, null, false, null, null, 'ad')['id'];
    (new Ads($db, $main))->create($ad, 'Acme');
    make_mp3("$root/in/Jingle A.mp3", 4, ['title' => 'Jingle A'], 71);
    $jg = $lib->import("$root/in/Jingle A.mp3", null, null, 'Jingle A.mp3', null, null, null, false, null, null, 'jingle')['id'];
    (new Jingles($db, $main))->create($jg);
    $db->run('UPDATE ' . $db->table('ads') . " SET daily_target = 500, created_at = '2020-01-01 00:00:00'");
    $adsG = new Ads($db, $gView);
    $jinG = new Jingles($db, $gView);
    check('the ads and jingles are the library’s: the stream sees them', array_column($adsG->all($t0), 'track_id') === [$ad] && array_column($jinG->all($t0), 'track_id') === [$jg]);
    check('  and picks them (one library)', $adsG->pick($t0) === $ad && $jinG->pick() === $jg);
    $pg->replan($t0);
    $kinds = implode('', array_map(fn($e) => !empty($e['ad']) ? 'A' : (!empty($e['jingle']) ? 'J' : 'S'), $pg->timeline($t0)));
    check('the stream’s timeline has the ad breaks and jingles', substr_count($kinds, 'A') >= 2 && substr_count($kinds, 'J') >= 2, $kinds);
    $pm->replan($t0);
    $kindsM = implode('', array_map(fn($e) => !empty($e['ad']) ? 'A' : (!empty($e['jingle']) ? 'J' : 'S'), $pm->timeline($t0)));
    check('  and so does the main one, each counting its own plays', substr_count($kindsM, 'A') >= 2 && substr_count($kindsM, 'J') >= 2, $kindsM);
    $db->run('UPDATE ' . $db->table('ads') . ' SET active = 0');
    $db->run('UPDATE ' . $db->table('jingles') . ' SET active = 0');

    echo "The schedule is each stream’s own\n";
    $sg = new Schedule($db, $gView);
    $sm = new Schedule($db, $mainView);
    check('a stream starts with an empty week, the main one is untouched', $sg->all() === [] );
    $lists = new Stationwagon\Playlists($db, $main);
    [$pl] = $lists->create('Gospel list', 'shuffle');
    $lists->add((int) $pl, [$songs['One'], $songs['Two']]);
    $db->insert('schedule', ['station_id' => $g, 'kind' => 'playlist', 'playlist_id' => (int) $pl, 'title' => 'Gospel morning', 'days' => 127, 'start_time' => '00:00:00', 'end_time' => '23:59:00', 'created_at' => gmdate('Y-m-d H:i:s')]);
    check('a block on the stream is on its schedule only', count($sg->all()) === 1 && $sm->all() === []);
    $pg->replan($t0 + 5);
    $ids = array_values(array_unique(array_column(array_filter($pg->timeline($t0 + 5), fn($e) => empty($e['ad']) && empty($e['jingle']) && $e['start'] > $t0 + 60), 'track_id')));
    check('  and the stream plays that playlist, the main stream still plays everything', !array_diff($ids, [$songs['One'], $songs['Two']]) && count(array_unique(array_column($pm->timeline($t0), 'track_id'))) > 2, json_encode($ids));

    echo "Each stream counts its own listeners\n";
    $lm = new Stationwagon\Listeners($db, $mainView);
    $lg = new Stationwagon\Listeners($db, $gView);
    $tn = $t0 + 100;
    $lg->ping('0123456789abcdef', 'player', $tn);
    check('a listener on a stream is counted there and nowhere else', $lg->now($tn + 1) === 1 && $lm->now($tn + 1) === 0);
    $lm->ping('fedcba9876543210', 'embed', $tn);
    $lm->ping('aaaaaaaaaaaaaaaa', 'embed', $tn);
    check('  the main stream counts its own', $lm->now($tn + 1) === 2 && $lg->now($tn + 1) === 1);
    check('  the day’s peak and the hour’s numbers are each stream’s own', $lg->daily($tn + 2, 1)[0]['peak'] === 1 && $lm->daily($tn + 2, 1)[0]['peak'] === 2);
    $lg->ping('0123456789abcdef', 'player', $tn + 5, true);
    check('  stopping on one stream leaves the other’s listeners alone', $lg->now($tn + 6) === 0 && $lm->now($tn + 6) === 2);

    echo "Who may go live where\n";
    $mkUser = function (string $role, string $name) use ($db, $main): array {
        $id = $db->insert('users', ['email' => "$name@example.test", 'name' => $name, 'password_hash' => 'x', 'role' => $role, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $db->insert('station_users', ['station_id' => $main['id'], 'user_id' => $id]);
        return ['id' => $id, 'role' => $role];
    };
    $admin = $mkUser('admin', 'ann');
    $mgr = $mkUser('manager', 'mo');
    $dj = $mkUser('dj', 'dee');
    $ids = array_map(fn($s) => (int) $s['id'], Streams::all($db));
    check('an admin and a manager may use every stream', Streams::allowedIds($db, $admin) === $ids && Streams::allowedIds($db, $mgr) === $ids && Streams::may($db, $mgr, $g));
    check('a DJ nobody has given streams to has the main stream, as every DJ always had', Streams::allowedIds($db, $dj) === [(int) $main['id']] && !Streams::may($db, $dj, $g));
    Streams::setFor($db, $dj['id'], [$g, 999999, (int) $g]);
    check('a DJ may go live on the streams they are given (an unknown id is dropped, a repeat counts once)', Streams::allowedIds($db, $dj) === [$g] && Streams::may($db, $dj, $g) && !Streams::may($db, $dj, (int) $main['id']));
    Streams::setFor($db, $dj['id'], [(int) $main['id']]);
    check('  and it can be changed', Streams::allowedIds($db, $dj) === [(int) $main['id']]);

    echo "People are given streams, and go live on them\n";
    [$g2] = Streams::create($db, 'Live Stream', 'live-two');
    $gv = Streams::station($db, 'live-two');
    $pL = Streams::planner($db, 'live-two');
    $staff = new Stationwagon\Staff($db, $main);
    $adminU = $staff->get($admin['id']);
    $mgrU = $staff->get($mgr['id']);
    $djU = $staff->get($dj['id']);
    check('an admin gives a DJ streams', $staff->setStreams($adminU, $dj['id'], [$g2]) === null && Streams::allowedIds($db, $djU) === [$g2]);
    check('  not to a manager or an admin (they have them all)', $staff->setStreams($adminU, $mgr['id'], [$g2]) === 'Admins and managers may use every stream.');
    check('  and at least one is needed', $staff->setStreams($adminU, $dj['id'], []) === 'Choose at least one stream. To stop someone going live, block them or change their role.' && $staff->setStreams($adminU, $dj['id'], [999999]) !== null && Streams::allowedIds($db, $djU) === [$g2]);
    check('  and a DJ cannot give anyone streams', $staff->setStreams($djU, $dj['id'], [(int) $main['id']]) === 'You can’t change that person.' && Streams::allowedIds($db, $djU) === [$g2]);
    [$newId] = $staff->add($adminU, 'New Person', 'new.person@example.test', 'dj', 'a long enough password 1');
    check('a new person starts with the main stream, as before', Streams::allowedIds($db, $staff->get($newId)) === [(int) $main['id']]);
    $now = $t0 + 600;
    $pL->timeline($now);
    $studioL = new Stationwagon\Studio($pL);
    $r = $studioL->goLive($mgrU, $now);
    check('a manager goes live on a stream: the show is that stream’s, the other streams have none', isset($r['live']) || isset($r['public_id']) || !isset($r['error']), json_encode($r));
    check('  the live show is on the stream’s timeline and only there', (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('live_shows') . ' WHERE station_id = ? AND `open` = 1', [$g2])->fetchColumn() === 1
        && (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('live_shows') . ' WHERE station_id = ? AND `open` = 1', [$main['id']])->fetchColumn() === 0
        && !array_filter(Streams::planner($db, null)->timeline($now + 1), fn($e) => $e['type'] === 'live'), '');
    check('  the stream cannot be removed while it is on', Streams::delete($db, $g2) === 'A live show is on this stream now. End it first.');
    $open = $db->run('SELECT public_id FROM ' . $db->table('live_shows') . ' WHERE station_id = ? AND `open` = 1', [$g2])->fetchColumn();
    $studioL->endLive($mgrU, (string) $open, $now + 5);
    $pod = new Stationwagon\Podcast($db, $main, $root);
    $pod->sync($now + 3600); // (the first run only notes the day podcasts began)
    $db->run('UPDATE ' . $db->table('settings') . " SET value = '2020-01-01 00:00:00' WHERE name = 'podcasts_from'");
    $pod->sync($now + 3600);
    check('the podcast is the station’s, whichever stream a show was on: a closed show on a stream becomes an episode of it', (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('episode_takes') . ' t JOIN ' . $db->table('live_shows') . ' s ON s.id = t.live_show_id WHERE s.station_id = ?', [$g2])->fetchColumn() === 1
        && (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('episodes') . ' WHERE station_id = ?', [$main['id']])->fetchColumn() >= 1);
    check('  ended, it can', Streams::delete($db, $g2) === null);

    echo "A song on air on another stream\n";
    $pg->replan($t0 + 5);
    $onG = null;
    foreach ($pg->timeline($t0 + 5) as $e) {
        if ($e['type'] === 'song' && empty($e['ad']) && empty($e['jingle']) && $e['start'] > $t0 + 60) {
            $mid = $e['start'] + 1;
            $onMain = array_filter($pm->timeline($t0), fn($m) => $m['type'] === 'song' && $m['track_id'] === $e['track_id'] && $m['start'] <= $mid + 30 && $m['end'] > $mid - 30);
            if (!$onMain) {
                $onG = [$e['track_id'], $mid];
                break;
            }
        }
    }
    check('(a song is on air on the stream and not on the main one)', $onG !== null);
    [$busyId, $tq] = $onG;
    $why = $lib->delete($busyId, $pm, $tq);
    check('removing it from the library is refused, though the main stream’s planner was given', is_string($why) && str_contains($why, 'is on air') && $lib->get($busyId) !== null, (string) $why);
    $why = $lib->replace($busyId, "$root/in/One.mp3", null, null, false, null, $pm, $tq);
    check('  and so is giving it a new file', is_string($why) && str_contains($why, 'is on air'), (string) $why);
    check('the main planner sees every stream, itself first', array_map(fn($p) => (int) $p->station()['id'], $pm->everyStream()) === array_map(fn($s) => (int) $s['id'], Streams::all($db))
        && (int) $pg->everyStream()[0]->station()['id'] === $g && (int) $pg->everyStream()[1]->station()['library_id'] === (int) $main['id']);

    echo "Blocking a DJ who is live on another stream\n";
    [$g3] = Streams::create($db, 'Night', 'night');
    $schedN = new Schedule($db, Streams::station($db, 'night'));
    $liveIn = ['kind' => 'live', 'title' => 'Night show', 'dj_user_id' => $dj['id'], 'days' => array_keys(Schedule::DAYS), 'start' => '00:00', 'end' => '00:00'];
    Streams::setFor($db, $dj['id'], [(int) $main['id']]);
    [$none, $errs] = $schedN->add($liveIn);
    check('a live block on a stream for a DJ who may not use it is refused, with the reason', $none === null && str_contains($errs['dj_user_id'] ?? '', 'may not go live on this stream'), json_encode($errs));
    [$plBlock, $errs] = $schedN->add(['kind' => 'playlist', 'playlist_id' => (int) $pl, 'days' => [1], 'start' => '05:00', 'end' => '06:00']);
    check('a block with one of the library’s playlists is added to a stream', is_int($plBlock) && !$errs && $schedN->all()[0]['playlist_name'] === 'Gospel list', json_encode($errs));
    $schedN->delete((int) $plBlock);
    Streams::setFor($db, $dj['id'], [$g3]);
    $djNow = $staff->get($dj['id']);
    $pN = Streams::planner($db, 'night');
    $added = $schedN->add($liveIn);
    check('  given the stream, the DJ’s live block is added', is_int($added[0]) && !$added[1], json_encode($added));
    $tb = $t0 + 1800;
    $pN->timeline($tb);
    $r = (new Stationwagon\Studio($pN))->goLive($djNow, $tb);
    $openN = fn() => (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('live_shows') . ' WHERE station_id = ? AND `open` = 1', [$g3])->fetchColumn();
    check('(the DJ is live on the Night stream)', $openN() === 1, $openN() === 1 ? '' : json_encode([$added, $r]));
    check('blocking them on the main stream’s screen ends that show too', $staff->block($adminU, $dj['id'], $pm, $tb + 2) === null && $openN() === 0
        && $db->run('SELECT closed_reason FROM ' . $db->table('live_shows') . ' WHERE station_id = ? ORDER BY id DESC LIMIT 1', [$g3])->fetchColumn() === 'DJ blocked'
        && !array_filter($pN->timeline($tb + 60), fn($e) => $e['type'] === 'live' && $e['start'] > $tb + 30));
    $staff->unblock($adminU, $dj['id']);
    check('  the stream goes on with its AutoDJ, and it can be removed', Streams::delete($db, $g3) === null);
    [$g4] = Streams::create($db, 'Fresh', 'fresh');
    $pF = Streams::planner($db, 'fresh');
    $noTimeline = (int) $db->run("SELECT COUNT(*) FROM $tl WHERE station_id = ?", [$g4])->fetchColumn() === 0;
    $r = (new Stationwagon\Studio($pF))->goLive($mgrU, $tb + 100);
    check('going live on a new stream nobody has listened to yet (no timeline) hands over from its AutoDJ', $noTimeline && !isset($r['error']) && isset($r['live']), json_encode($r['error'] ?? null));
    $pF->endLive($tb + 130, (string) $r['live']['public_id']);
    Streams::delete($db, $g4);

    echo "Removing a stream\n";
    $db->insert('live_shows', ['station_id' => $g, 'public_id' => bin2hex(random_bytes(8)), 'dj_user_id' => $dj['id'], 'title' => 'Live', 'open' => 1, 'start_at' => (float) $t0, 'last_piece_at' => (float) $t0, 'created_at' => gmdate('Y-m-d H:i:s')]);
    check('one with a live show on it is not removed', Streams::delete($db, $g) === 'A live show is on this stream now. End it first.' && Streams::bySlug($db, 'gospel') !== null);
    $db->run('UPDATE ' . $db->table('live_shows') . ' SET `open` = 0 WHERE station_id = ?', [$g]);
    check('the main stream is never removed', Streams::delete($db, (int) $main['id']) === 'That stream isn’t here.');
    Streams::setFor($db, $dj['id'], [$g]);
    check('removing a stream takes its timeline, schedule and allocations with it, and keeps the library', Streams::delete($db, $g) === null && Streams::bySlug($db, 'gospel') === null
        && (int) $db->run("SELECT COUNT(*) FROM $tl WHERE station_id = ?", [$g])->fetchColumn() === 0
        && (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('schedule') . ' WHERE station_id = ?', [$g])->fetchColumn() === 0
        && (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('user_streams') . ' WHERE station_id = ?', [$g])->fetchColumn() === 0
        && count($lib->tracks()) === 6 && count(Streams::all($db)) === 1);

    echo "Upgrading a version 19 database\n";
    check('it adds the stream column and the allocations, and everybody there keeps the main stream', (function () use ($db, $root, $main, $dj, $mgr) {
        $db->pdo->exec('DROP TABLE ' . $db->table('user_streams'));
        $db->pdo->exec('ALTER TABLE ' . $db->table('stations') . ' DROP COLUMN parent_id');
        $db->run('UPDATE ' . $db->table('settings') . " SET value = '19' WHERE name = 'schema_version'");
        $ran = Schema::upgrade($db, $root);
        $has = $db->run('SHOW COLUMNS FROM ' . $db->table('stations') . " LIKE 'parent_id'")->fetch() !== false;
        $rows = $db->run('SELECT user_id FROM ' . $db->table('user_streams') . ' WHERE station_id = ? ORDER BY user_id', [$main['id']])->fetchAll(PDO::FETCH_COLUMN);
        $all = $db->run('SELECT user_id FROM ' . $db->table('station_users') . ' WHERE station_id = ? ORDER BY user_id', [$main['id']])->fetchAll(PDO::FETCH_COLUMN);
        return $ran === [20] && $has && $rows === $all && in_array((string) $dj['id'], array_map('strval', $rows), true) && Schema::upgrade($db, $root) === [];
    })());
} finally {
    drop_test_database($t);
    remove_tree($root);
}
finish();
