<?php
// Command-line test of the listener count and its stats: pings from players (a random id per
// Listen), who counts as listening now, Stop, pings too close together, the hourly stats (the most
// at once, minutes, listens), the 48-hour and 30-day views in the station's time zone, tidying up,
// and the schema upgrade to version 4.
//   php app/tests/listeners_test.php      (same SW_TEST_DB_* settings as install_test.php)
declare(strict_types=1);
require __DIR__ . '/lib.php';
use Stationwagon\{Db, Listeners, Schema};

$t = test_database('listeners');
$root = temp_root('listeners');
try {
    $cfg = test_install($t, $root);
    $db = Db::connect($cfg['db']);
    $station = $db->run('SELECT * FROM ' . $db->table('stations'))->fetch();
    $l = new Listeners($db, $station);
    $now = 1800000000.0; // 2027-01-15 08:00 UTC, 10:00 in Johannesburg
    $id = fn(int $n): string => str_pad(dechex($n), 16, '0', STR_PAD_LEFT);
    $hours = fn() => $db->run('SELECT `hour`, peak, minutes, listens FROM ' . $db->table('listener_hours') . ' ORDER BY `hour`')->fetchAll();

    echo "Pings\n";
    check('nobody listening on a new station', $l->now($now) === 0);
    check('a ping counts as listening now', $l->ping($id(1), 'player', $now) === 1);
    check('  a second player too', $l->ping($id(2), 'embed', $now + 5) === 2);
    foreach (['not-hex-at-all!!', 'abc', 'ABCDEF0123456789', $id(3) . '0'] as $bad) {
        $err = null;
        try { $l->ping($bad, 'player', $now); } catch (InvalidArgumentException $ex) { $err = $ex->getMessage(); }
        check("  an id that isn't 16 hex digits is refused ($bad)", $err !== null);
    }
    $err = null;
    try { $l->ping($id(3), 'radio-app', $now); } catch (InvalidArgumentException $ex) { $err = $ex->getMessage(); }
    check('  and an unknown source', $err !== null);
    $row = $db->run('SELECT * FROM ' . $db->table('listeners') . ' WHERE sid = ?', [$id(1)])->fetch();
    check('nothing is kept but the id, its source and times', array_keys($row) === ['station_id', 'sid', 'source', 'first_seen', 'last_seen'], implode(',', array_keys($row)));
    $h = $hours();
    check('the hour keeps the most at once, minutes and listens', count($h) === 1 && (int) $h[0]['peak'] === 2 && (int) $h[0]['minutes'] === 2 && (int) $h[0]['listens'] === 2, json_encode($h));
    check('  stored as the UTC hour', $h[0]['hour'] === '2027-01-15 08:00:00');
    $l->ping($id(1), 'player', $now + 20);
    check('a ping sooner than ' . Listeners::MIN_GAP_SECONDS . ' s after the last adds no minute', (int) $hours()[0]['minutes'] === 2);
    $l->ping($id(1), 'player', $now + 60);
    check('  one a minute later does, and is no new listen', (int) $hours()[0]['minutes'] === 3 && (int) $hours()[0]['listens'] === 2);
    check('a player quiet for over ' . Listeners::ACTIVE_SECONDS . ' s is no longer counted', $l->now($now + 5 + Listeners::ACTIVE_SECONDS + 1) === 1);
    check('Stop takes it off at once', $l->ping($id(1), 'player', $now + 70, true) === 1 && $l->now($now + 71) === 1);
    check('  and leaves the stats as they were', (int) $hours()[0]['minutes'] === 3);

    echo "Hours and days\n";
    // 10:15 to 12:45 local: three listeners an hour apart, the third overlapping the second
    $t0 = $now + 15 * 60;
    for ($m = 0; $m < 30; $m++) { $l->ping($id(10), 'player', $t0 + $m * 60); }
    for ($m = 0; $m < 90; $m++) { $l->ping($id(11), 'player', $t0 + 3600 + $m * 60); }
    for ($m = 0; $m < 20; $m++) { $l->ping($id(12), 'embed', $t0 + 3600 + 1800 + $m * 60); }
    $hr = $l->hourly($t0 + 3 * 3600, 48);
    check('48 hours, oldest first, ending with this hour', count($hr) === 48 && end($hr)['start'] === (int) floor(($t0 + 3 * 3600) / 3600) * 3600);
    $byLabel = array_column($hr, null, 'label');
    check('  labelled in the station’s time zone', isset($byLabel['10:00']) && $byLabel['10:00']['day'] === 'Fri 15 Jan', json_encode($byLabel['10:00'] ?? null));
    check('  10:00 had 2 at once at most (before Stop)', $byLabel['10:00']['peak'] === 2, json_encode($byLabel['10:00']));
    check('  11:00 had 2 at once (the overlap)', $byLabel['11:00']['peak'] === 2 && $byLabel['11:00']['listens'] === 2, json_encode($byLabel['11:00']));
    check('  12:00: the overlap until 12:04, 45 + 5 minutes, no new listens', $byLabel['12:00']['peak'] === 2 && $byLabel['12:00']['minutes'] === 50 && $byLabel['12:00']['listens'] === 0, json_encode($byLabel['12:00']));
    check('  hours without listening are zeros', $byLabel['09:00']['peak'] === 0 && $byLabel['09:00']['minutes'] === 0);
    $day = $l->daily($t0 + 3 * 3600, 30);
    $today = end($day);
    check('30 days, today last', count($day) === 30 && $today['date'] === '2027-01-15' && $day[0]['date'] === '2026-12-17', $today['date'] . ' / ' . $day[0]['date']);
    check('  today adds up the hours', $today['minutes'] === 3 + 30 + 90 + 20 && $today['listens'] === 5 && $today['peak'] === 2, json_encode($today));
    // 23:30 local on the 15th is 21:30 UTC; 00:30 local on the 16th is still the 15th in UTC
    $l->ping($id(20), 'player', $now + 13.5 * 3600);
    $l->ping($id(21), 'player', $now + 14.5 * 3600);
    $day2 = $l->daily($now + 15 * 3600, 2);
    check('days follow the station’s midnight, not UTC’s', $day2[0]['date'] === '2027-01-15' && $day2[0]['listens'] === 6 && $day2[1]['date'] === '2027-01-16' && $day2[1]['listens'] === 1, json_encode($day2));

    echo "Tidying up\n";
    $l->ping($id(30), 'player', $now + 20 * 3600);
    check('a new listen deletes players quiet for ' . (Listeners::FORGET_SECONDS / 60) . ' minutes', (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('listeners'))->fetchColumn() === 1);
    $l->ping($id(31), 'player', $now + (Listeners::KEEP_DAYS + 1) * 86400);
    $left = $hours();
    check('  and stats older than ' . Listeners::KEEP_DAYS . ' days', count($left) === 1, count($left) . ' hours left');

    echo "Upgrade\n";
    $p = $cfg['db']['prefix'];
    $db->pdo->exec("DROP TABLE `{$p}listener_hours`");
    $db->pdo->exec("DROP TABLE `{$p}listeners`");
    $db->run('UPDATE ' . $db->table('settings') . " SET value = '3' WHERE name = 'schema_version'");
    Schema::upgrade($db, $root);
    check('a version 3 database upgrades to 4 with both tables', Schema::installed($db) === 4 && $l->now($now) === 0 && $db->run("SHOW TABLES LIKE '{$p}listener_hours'")->fetchColumn() !== false);
} finally {
    drop_test_database($t);
    remove_tree($root);
}
finish();
