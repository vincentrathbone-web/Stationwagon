<?php
// Command-line test of the station manager's rules: library edit/search/delete, playlists,
// the weekly schedule, Sound & safety settings and who may do what to whom (Staff). Throwaway
// database and install.
//   php app/tests/manage_test.php      (same SW_TEST_DB_* settings as install_test.php)
declare(strict_types=1);
require __DIR__ . '/lib.php';
use Stationwagon\{Auth, Db, Library, Planner, Playlists, Schedule, Staff, StationSettings};

$t = test_database('manage');
$root = temp_root('manage');
try {
    $cfg = test_install($t, $root);
    $db = Db::connect($cfg['db']);
    $station = $db->run('SELECT * FROM ' . $db->table('stations'))->fetch();
    $lib = new Library($db, $station, $root);
    $p = new Planner($db, $station, $root);
    mkdir("$root/in");
    $ids = [];
    foreach (['Amazing Grace' => 150, 'Be Thou My Vision' => 170, 'Come Thou Fount' => 190] as $name => $secs) {
        make_mp3("$root/in/upload-tmp-" . count($ids), $secs, [], count($ids));
        $ids[$name] = $lib->import("$root/in/upload-tmp-" . count($ids), null, null, "$name.mp3")['id'];
    }

    echo "Library\n";
    check('an upload is named after its own file, not the temporary one', $lib->get($ids['Amazing Grace'])['title'] === 'Amazing Grace');
    check('search finds by title', count($lib->tracks('vision')) === 1 && count($lib->tracks('')) === 3);
    check('search treats % and _ as plain text', count($lib->tracks('%')) === 0 && count($lib->tracks('_')) === 0);
    check('a title can be changed', $lib->update($ids['Come Thou Fount'], 'Come, Thou Fount', 'Robert Robinson') === null && $lib->get($ids['Come Thou Fount'])['artist'] === 'Robert Robinson');
    check('an empty title is refused', $lib->update($ids['Come Thou Fount'], '  ', '') !== null);
    $now = 1800000000.0;
    $playing = $p->nowPlaying($now)['current'];
    $refused = $lib->delete($playing['track_id'], $p, $now);
    check('the song on air can’t be removed', $refused !== null && str_contains($refused, 'on air'), (string) $refused);
    $other = array_values(array_diff(array_values($ids), [$playing['track_id']]))[0];
    $file = $lib->get($other)['file'];
    $gone = $lib->delete($other, $p, $now);
    check('another song can be removed', $gone === null, (string) $gone);
    check('its file is deleted too', !is_file("$root/storage/media/$file"));
    $future = array_filter($p->timeline($now), fn($e) => $e['start'] > $now + 2);
    check('and it no longer comes up in the plan', !in_array($other, array_column($future, 'track_id'), true));
    $ids = array_filter($ids, fn($id) => $id !== $other);
    check('storage size is counted', $lib->bytes() > 0);
    // Song prep: a levelled copy is known by the original's hash and keeps its loudness
    make_mp3("$root/in/levelled-tmp", 160, ['title' => 'Doxology'], 7);
    $orig = sha1('the original upload, before levelling');
    $lev = $lib->import("$root/in/levelled-tmp", null, null, 'Doxology.mp3', $orig, -16.004);
    $row = $db->run('SELECT sha1, file, loudness_lufs FROM ' . $db->table('tracks') . ' WHERE id = ?', [$lev['id']])->fetch();
    check('a levelled song keeps the original’s fingerprint', $row['sha1'] === $orig && $row['file'] === sha1_file("$root/in/levelled-tmp") . '.mp3');
    check('  and its loudness', abs((float) $row['loudness_lufs'] + 16) < 0.01 && (float) $lib->tracks('Doxology')[0]['loudness_lufs'] === -16.0);
    make_mp3("$root/in/levelled-tmp2", 160, [], 8);
    $again = $lib->import("$root/in/levelled-tmp2", null, null, 'Doxology.mp3', $orig, -16.0);
    check('the same original levelled again is a duplicate', $again['duplicate'] && $again['id'] === $lev['id']);
    check('  even when the copy differs byte for byte', !is_file("$root/storage/media/" . sha1_file("$root/in/levelled-tmp2") . '.mp3'));
    $plain = $lib->import("$root/in/levelled-tmp2", null, null, 'Other.mp3', 'not a hash', 99.0);
    $prow = $db->run('SELECT sha1, loudness_lufs FROM ' . $db->table('tracks') . ' WHERE id = ?', [$plain['id']])->fetch();
    check('a bad fingerprint falls back to the file’s own; loudness is kept in range', !$plain['duplicate'] && $prow['sha1'] === sha1_file("$root/in/levelled-tmp2") && (float) $prow['loudness_lufs'] === 0.0);
    $lib->delete($plain['id'], $p, $now);
    $lib->delete($lev['id'], $p, $now);

    echo "Playlists\n";
    $lists = new Playlists($db, $station);
    [$pl, $problem] = $lists->create('Morning', 'in_order');
    check('a playlist can be made', $pl !== null && $problem === null);
    check('a nameless playlist is refused', $lists->create('  ', 'shuffle')[1] !== null);
    check('an unknown play mode is refused', $lists->create('X', 'random')[1] !== null);
    $a = array_values($ids)[0]; $b = array_values($ids)[1];
    $lists->add($pl, [$a, $b, $a, 999999]);
    check('songs are added once each, unknown ones skipped', array_map('intval', array_column($lists->tracks($pl), 'id')) === [$a, $b]);
    $lists->move($pl, $b, -1);
    check('a song can move up', array_map('intval', array_column($lists->tracks($pl), 'id')) === [$b, $a]);
    $lists->move($pl, $b, -1);
    check('moving the first song up does nothing', array_map('intval', array_column($lists->tracks($pl), 'id')) === [$b, $a]);
    $lists->remove($pl, $b);
    check('a song can be taken out', array_map('intval', array_column($lists->tracks($pl), 'id')) === [$a]);
    check('positions stay tidy', (int) $lists->tracks($pl)[0]['position'] === 0);
    check('rename, mode and switch off', $lists->update($pl, 'Mornings', 'shuffle', false) === null && $lists->get($pl)['name'] === 'Mornings' && !(int) $lists->get($pl)['active']);
    $all = $lists->all();
    check('the list shows songs and length', (int) $all[0]['songs'] === 1 && (float) $all[0]['seconds'] > 100);

    echo "Schedule\n";
    $sched = new Schedule($db, $station);
    [$blk, $errs] = $sched->add(['kind' => 'playlist', 'playlist_id' => $pl, 'days' => [1, 2, 4, 8, 16], 'start' => '6:00', 'end' => '09:30']);
    check('a playlist block can be added', $blk !== null && !$errs, json_encode($errs));
    check('it takes the playlist’s name', $sched->all()[0]['title'] === 'Mornings' && (int) $sched->all()[0]['days'] === 31);
    [, $errs] = $sched->add(['kind' => 'playlist', 'playlist_id' => 999, 'days' => [], 'start' => '25:00', 'end' => 'soon']);
    check('bad blocks are refused, field by field', isset($errs['playlist_id'], $errs['days'], $errs['start'], $errs['end']), implode(',', array_keys($errs)));
    [, $errs] = $sched->add(['kind' => 'live', 'days' => [64], 'start' => '18:00', 'end' => '19:00']);
    check('a live show needs a name', isset($errs['title']));
    [$live, $errs] = $sched->add(['kind' => 'live', 'title' => 'Sunday Service', 'days' => [64], 'start' => '22:00', 'end' => '01:00']);
    check('a live show past midnight can be added', $live !== null && !$errs);
    check('times: 24:00 is midnight, 6:5 is not a time', Schedule::time('24:00') === '00:00:00' && Schedule::time('6:5') === null && Schedule::time('07:15') === '07:15:00');
    check('day names', Schedule::dayNames(31) === 'Weekdays' && Schedule::dayNames(127) === 'Every day' && Schedule::dayNames(65) === 'Mon, Sun');
    $grid = Schedule::gridPieces($sched->all());
    $sunday = array_values(array_filter($grid, fn($g) => $g['block']['title'] === 'Sunday Service'));
    check('the grid shows weekday mornings 6:00–9:30', count(array_filter($grid, fn($g) => $g['block']['title'] === 'Mornings')) === 5 && abs($grid[0]['len'] - 3.5) < 1e-9 && $grid[0]['top'] == 0);
    check('a show past midnight shows on Sunday only (from 06:00 on Monday it’s over)', count($sunday) === 1 && $sunday[0]['day'] === 6 && $sunday[0]['top'] == 16 && $sunday[0]['len'] == 2);
    check('the planner follows the new block', $p->blockAt((float) (new DateTimeImmutable('2027-01-04 07:00', new DateTimeZone($station['timezone'])))->getTimestamp())['id'] == $blk);
    check('a block can be removed', $sched->delete((int) $live) === null && $sched->delete((int) $live) !== null);
    check('deleting a playlist removes its blocks', $lists->delete($pl) === null && $sched->all() === []);

    echo "Sound & safety\n";
    $fresh = fn() => $db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE id = ?', [$station['id']])->fetch();
    $set = new StationSettings($db, $fresh());
    check('the defaults are the values the POC proved', $set->values() === ['crossfade_seconds' => 6, 'skip_fade_seconds' => 2.0, 'live_fade_seconds' => 6.0, 'live_timeout_seconds' => 8, 'recent_tracks' => 5], json_encode($set->values()));
    check('with 6 s pieces a DJ gets at least 8 s', $set->minLiveTimeout() === 8);
    $good = ['crossfade_seconds' => '4', 'skip_fade_seconds' => '1.5', 'live_fade_seconds' => '3', 'live_timeout_seconds' => '12', 'recent_tracks' => '1'];
    [, $errs] = $set->save(['crossfade_seconds' => '13', 'skip_fade_seconds' => '0.3', 'live_fade_seconds' => 'soon', 'live_timeout_seconds' => '5'] );
    check('bad values are refused, field by field', array_keys($errs) === array_keys($good), implode(',', array_keys($errs)));
    check('  and say what is allowed', str_contains($errs['skip_fade_seconds'], '0 to 6 s in steps of 0.5') && str_contains($errs['live_timeout_seconds'], '8 to 30'), $errs['skip_fade_seconds']);
    check('  and nothing is stored', (new StationSettings($db, $fresh()))->values()['crossfade_seconds'] === 6);
    [$updated, $errs] = $set->save($good);
    check('good values are saved', !$errs && (new StationSettings($db, $fresh()))->values() === ['crossfade_seconds' => 4, 'skip_fade_seconds' => 1.5, 'live_fade_seconds' => 3.0, 'live_timeout_seconds' => 12, 'recent_tracks' => 1], json_encode($errs));
    $kept = Planner::settings($fresh());
    check('other settings are kept', $kept['skin'] === 'green' && (float) $kept['segment_seconds'] === 6.0);
    $p2 = new Planner($db, $updated, $root);
    $later = $now + 600;
    $p2->replan($later);
    $planned = array_values(array_filter($p2->timeline($later), fn($e) => $e['start'] > $later + 2 && $e['type'] === 'song'));
    check('songs planned after saving use the new crossfade', $planned && array_unique(array_column($planned, 'fade_out')) == [4.0], json_encode(array_values(array_unique(array_column($planned, 'fade_out')))));
    $live = $p2->goLive($later, 'Settings test')['entries'];
    $handover = array_values(array_filter($live, fn($e) => $e['cut']));
    check('going live fades the song out over the live cut-in fade', $handover && end($handover)['fade_out'] == 3.0, json_encode(array_column($handover, 'fade_out')));
    $show = end($live);
    check('  and the show times out after the new 12 s', !$p2->needsPlan($live, $show['last_piece_at'] + 11) && $p2->needsPlan($live, $show['last_piece_at'] + 13));
    $p2->endLive($later + 20, $show['public_id']);
    check('numbers show without needless decimals', StationSettings::show(6.0) === '6' && StationSettings::show(1.5) === '1.5');

    echo "Staff\n";
    $staff = new Staff($db, $station);
    $admin = $staff->all()[0];
    [$mgrId, $e] = $staff->add($admin, 'Mia Manager', 'Mia@Example.test', 'manager', 'manager pass 1');
    check('an admin adds a manager', $mgrId !== null && !$e);
    $mgr = $staff->get($mgrId);
    check('emails are stored in lower case', $mgr['email'] === 'mia@example.test');
    [$djId] = $staff->add($mgr, 'Dan DJ', 'dan@example.test', 'dj', 'dj password 1');
    check('a manager adds a DJ', $djId !== null);
    [, $e] = $staff->add($mgr, 'Eve', 'eve@example.test', 'admin', 'eve password 1');
    check('a manager can’t make an admin', isset($e['role']));
    [, $e] = $staff->add($mgr, 'Dup', 'DAN@example.test', 'dj', 'whatever pass');
    check('an email that’s taken is refused', isset($e['email']));
    [, $e] = $staff->add($mgr, '', 'bad', 'dj', 'short');
    check('name, email and password are checked', isset($e['name'], $e['email'], $e['password']));
    $dj = $staff->get($djId);
    check('a DJ can manage nobody', Staff::grantable($dj) === [] && !Staff::canManage($dj, $mgr));
    check('a manager can’t touch the admin', $staff->setRole($mgr, (int) $admin['id'], 'dj') !== null && $staff->remove($mgr, (int) $admin['id']) !== null);
    check('nobody changes their own role here', $staff->setRole($admin, (int) $admin['id'], 'dj') !== null);
    check('a manager promotes a DJ to manager', $staff->setRole($mgr, $djId, 'manager') === null && $staff->get($djId)['role'] === 'manager');
    $auth = new Auth($db, "$root/storage/cache/throttle");
    check('a manager sets a new password for a DJ', $staff->resetPassword($mgr, $djId, 'brand new pass', $auth) === null);
    $_SESSION = [];
    check('and the DJ signs in with it', $auth->attempt('dan@example.test', 'brand new pass', '192.0.2.9')['ok']);
    check('a password that’s too short is refused', $staff->resetPassword($mgr, $djId, 'short', $auth) !== null);
    [$admin2] = $staff->add($admin, 'Second Admin', 'admin2@example.test', 'admin', 'admin2 pass 1');
    check('one admin can demote another', $staff->setRole($admin, $admin2, 'manager') === null && $staff->get($admin2)['role'] === 'manager');
    check('and make them admin again', $staff->setRole($admin, $admin2, 'admin') === null);
    check('one admin can remove another', $staff->remove($staff->get($admin2), (int) $admin['id']) === null && $staff->get((int) $admin['id']) === null);
    $staff2 = new Staff($db, $station);
    $admins = array_values(array_filter($staff2->all(), fn($u) => $u['role'] === 'admin'));
    $lastAdmin = $admins[0];
    $someMgr = $staff2->get($mgrId);
    check('with one admin left, it stays', count($admins) === 1 && $staff2->remove($lastAdmin, (int) $lastAdmin['id']) !== null);
    check('a manager removes a DJ, who then can’t sign in', $staff2->remove($someMgr, $djId) === null && $staff2->get($djId) === null);
    $_SESSION = [];
    check('  (sign-in refused)', !$auth->attempt('dan@example.test', 'brand new pass', '192.0.2.10')['ok']);
} finally {
    drop_test_database($t);
    remove_tree($root);
}
finish();
