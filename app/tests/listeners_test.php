<?php
// Command-line test of the listener count and its stats: pings from players (a random id per
// Listen), who counts as listening now, Stop, pings too close together, the hourly stats (the most
// at once, minutes, listens), the 48-hour and 30-day views in the station's time zone, what each
// Listen keeps (address, browser details, device, browser, app, country, city), the daily tallies,
// unique listeners by day and month (and the monthly salt), test runs kept out of the stats, the
// cap on new Listens per address, tidying up, and the schema upgrades to version 5.
//   php app/tests/listeners_test.php      (same SW_TEST_DB_* settings as install_test.php)
// Needs the location database: php app/cli/geo-update.php
declare(strict_types=1);
require __DIR__ . '/lib.php';
use Stationwagon\{Agent, Db, Geo, Listeners, Schema};

if (!is_file(Geo::path())) {
    fwrite(STDERR, "No location database at " . Geo::path() . ": run php app/cli/geo-update.php first.\n");
    exit(1);
}
$geo = new Geo(Geo::path());
$t = test_database('listeners');
$root = temp_root('listeners');
try {
    $cfg = test_install($t, $root);
    $db = Db::connect($cfg['db']);
    $station = $db->run('SELECT * FROM ' . $db->table('stations'))->fetch();
    $l = new Listeners($db, $station, $geo);
    $now = 1800000000.0; // 2027-01-15 08:00 UTC, 10:00 in Johannesburg
    $id = fn(int $n): string => str_pad(dechex($n), 16, '0', STR_PAD_LEFT);
    $hours = fn() => $db->run('SELECT `hour`, peak, minutes, listens FROM ' . $db->table('listener_hours') . ' ORDER BY `hour`')->fetchAll();
    $count = fn(string $table) => (int) $db->run('SELECT COUNT(*) FROM ' . $db->table($table))->fetchColumn();
    $phone = 'Mozilla/5.0 (Linux; Android 16; 25062RN2DA) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36';
    $laptop = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36 Edg/155.0.0.0';

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
    check('  a late ping after Stop doesn’t bring it back', $l->ping($id(1), 'player', $now + 125) === 0 && (int) $hours()[0]['minutes'] === 3);

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

    echo "The location database\n";
    check('8.8.8.8 is in the United States', ($geo->lookup('8.8.8.8')['country_code'] ?? '') === 'US', json_encode($geo->lookup('8.8.8.8')));
    $za = $geo->lookup('196.25.1.1');
    check('196.25.1.1 (Telkom) is in South Africa, with a city', ($za['country'] ?? '') === 'South Africa' && ($za['city'] ?? '') !== '', json_encode($za));
    check('an IPv6 address is found too', ($geo->lookup('2c0f:fb50::1')['country_code'] ?? '') !== '', json_encode($geo->lookup('2c0f:fb50::1')));
    check('local and private addresses have no place', $geo->lookup('127.0.0.1') === null && $geo->lookup('10.0.0.1') === null && $geo->lookup('::1') === null);
    check('  nor does something that isn’t an address', $geo->lookup('not an address') === null);
    check('the file is DB-IP’s city database, with its month noted', ($geo->metadata['database_type'] ?? '') === 'DBIP-City-Lite' && Geo::version() !== null, ($geo->metadata['database_type'] ?? '?') . ' ' . Geo::version());
    $err = null;
    try { new Geo(__FILE__); } catch (RuntimeException $ex) { $err = $ex->getMessage(); }
    check('a file that isn’t one is refused', $err !== null, (string) $err);

    echo "Devices, browsers and apps\n";
    $agents = [
        [$phone, 'Phone', 'Chrome', 'Web browser'],
        ['Mozilla/5.0 (iPhone; CPU iPhone OS 19_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/19.0 Mobile/15E148 Safari/604.1', 'Phone', 'Safari', 'Web browser'],
        ['Mozilla/5.0 (iPad; CPU OS 19_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/19.0 Mobile/15E148 Safari/604.1', 'Tablet', 'Safari', 'Web browser'],
        ['Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', 'Tablet', 'Chrome', 'Web browser'],
        ['Mozilla/5.0 (Linux; Android 15; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/28.0 Chrome/130.0.0.0 Mobile Safari/537.36', 'Phone', 'Samsung Internet', 'Web browser'],
        [$laptop, 'Computer', 'Edge', 'Web browser'],
        ['Mozilla/5.0 (Macintosh; Intel Mac OS X 15_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/19.0 Safari/605.1.15', 'Computer', 'Safari', 'Web browser'],
        ['Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0', 'Computer', 'Firefox', 'Web browser'],
        ['Mozilla/5.0 (Linux; Android 16; Pixel 9 Build/BP1A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/155.0.0.0 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/500.0.0.0;]', 'Phone', 'Chrome', 'Facebook'],
        ['Mozilla/5.0 (iPhone; CPU iPhone OS 19_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 390.0.0 (iPhone16,2; iOS 19_0)', 'Phone', 'Safari', 'Instagram'],
        ['Mozilla/5.0 (Linux; Android 16; Pixel 9; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/155.0.0.0 Mobile Safari/537.36', 'Phone', 'Chrome', 'Another app'],
        ['Mozilla/5.0 (Linux; Android 12; SmartTV) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 CrKey/1.56', 'TV', 'Chrome', 'Web browser'],
        ['curl/8.9.1', 'Other', 'Other', 'Web browser'],
        ['', 'Unknown', 'Other', 'Web browser'],
    ];
    foreach ($agents as [$ua, $device, $browser, $app]) {
        $a = Agent::parse($ua);
        check("  $device, $browser, $app: " . ($ua === '' ? '(no browser details)' : substr($ua, 0, 48) . '…'),
            $a['device'] === $device && $a['browser'] === $browser && $a['app'] === $app && !$a['test'], "{$a['device']}, {$a['browser']}, {$a['app']}");
    }
    check('  our test runs are recognised by their mark', Agent::parse($laptop . ' ' . Agent::TEST_MARK)['test']);

    echo "What a Listen keeps\n";
    $d0 = $now + 3 * 86400; // Mon 18 Jan, 10:00 local
    $l->ping($id(40), 'player', $d0, false, '196.25.1.1', $phone);
    $row = $db->run('SELECT * FROM ' . $db->table('listeners') . ' WHERE sid = ?', [$id(40)])->fetch();
    check('the address and browser details it came from', $row['ip'] === '196.25.1.1' && $row['user_agent'] === $phone);
    check('  what they say: phone, Chrome, a web browser', $row['device'] === 'Phone' && $row['browser'] === 'Chrome' && $row['app'] === 'Web browser', "{$row['device']}, {$row['browser']}, {$row['app']}");
    check('  and where: South Africa and a city', $row['country_code'] === 'ZA' && $row['country'] === 'South Africa' && $row['city'] === $za['city'], "{$row['country']}, {$row['city']}");
    check('  and how long (minutes)', (int) $row['minutes'] === 1);
    $l->ping($id(40), 'player', $d0 + 60, false, '196.25.1.1', $phone);
    check('  one more per ping', (int) $db->run('SELECT minutes FROM ' . $db->table('listeners') . ' WHERE sid = ?', [$id(40)])->fetchColumn() === 2);
    $l->ping($id(41), 'embed', $d0 + 10, false, '127.0.0.1', "bad\x01agent\nwith controls" . str_repeat('x', 500));
    $row = $db->run('SELECT * FROM ' . $db->table('listeners') . ' WHERE sid = ?', [$id(41)])->fetch();
    check('a local address has no place; long or odd browser details are cut short and cleaned', $row['country'] === '' && $row['city'] === '' && strlen($row['user_agent']) === 400 && !preg_match('/[\x00-\x1F]/', $row['user_agent']));
    $l->ping($id(42), 'player', $d0 + 20, false, 'not-an-ip', $laptop);
    check('  an address that isn’t one isn’t kept', $db->run('SELECT ip FROM ' . $db->table('listeners') . ' WHERE sid = ?', [$id(42)])->fetchColumn() === '');

    echo "Totals by kind\n";
    $b = $l->breakdown($d0 + 120, 30);
    $find = fn(string $kind, string $value) => array_values(array_filter($b[$kind], fn($r) => $r['value'] === $value))[0] ?? null;
    check('every kind is there', array_keys($b) === array_keys(Listeners::KINDS));
    check('  the phone: 1 listen, 2 minutes', ($find('device', 'Phone')['listens'] ?? 0) === 1 && ($find('device', 'Phone')['minutes'] ?? 0) === 2, json_encode($find('device', 'Phone')));
    check('  the city with its country', $find('city', $za['city'] . ', South Africa') !== null, json_encode(array_column($b['city'], 'value')));
    check('  no place shows as Unknown', $find('country', 'Unknown') !== null);
    check('  the embed and the station page apart', $find('source', 'Embed on a website') !== null && $find('source', 'Station page') !== null);
    check('  most listened first', $b['device'][0]['minutes'] >= end($b['device'])['minutes']);

    echo "Unique listeners\n";
    $u0 = $count('listener_visitors');
    $l->ping($id(43), 'player', $d0 + 30, false, '196.25.1.1', $phone); // the same person presses Listen again
    check('the same address and browser twice in a day is one listener', $count('listener_visitors') === $u0, $count('listener_visitors') . ' marks');
    $l->ping($id(44), 'player', $d0 + 40, false, '196.25.1.1', $laptop); // someone else in the same house
    check('  another browser at the same address is another', $count('listener_visitors') === $u0 + 1);
    $l->ping($id(45), 'player', $d0 + 86400, false, '196.25.1.1', $phone); // the first person the next day
    $days = array_column($l->daily($d0 + 86400, 2), 'uniques', 'date');
    check('  counted each day they listen', $days['2027-01-18'] === 4 && $days['2027-01-19'] === 1, json_encode($days));
    $months = $l->monthly($d0 + 86400, 12);
    $month = end($months) ?: [];
    check('  but once in the month', ($month['month'] ?? '') === '2027-01' && $month['uniques'] === 4, json_encode($month));
    check('  12 months, this one last', count($l->monthly($d0, 12)) === 12 && $l->monthly($d0, 12)[0]['month'] === '2026-02');
    $mark = fn(string $day) => $db->run('SELECT visitor FROM ' . $db->table('listener_visitors') . ' WHERE `day` = ?', [$day])->fetchColumn();
    $salt = fn() => json_decode((string) $db->run('SELECT value FROM ' . $db->table('settings') . " WHERE name = 'listener_salt'")->fetchColumn(), true);
    $janSalt = $salt();
    $l->ping($id(46), 'player', $now + 20 * 86400, false, '196.25.1.1', $phone); // Thu 4 Feb
    check('a new month gets a new salt', $salt()['month'] === '2027-02' && $salt()['salt'] !== $janSalt['salt']);
    check('  so the same listener gets a different mark', strlen((string) $mark('2027-02-04')) === 8 && $mark('2027-02-04') !== $mark('2027-01-19'));

    echo "Test runs\n";
    $before = [$hours(), $count('listener_tally'), $count('listener_visitors'), $db->run('SELECT SUM(minutes) FROM ' . $db->table('listener_tally'))->fetchColumn()];
    $tt = $now + 21 * 86400;
    check('a test run counts as listening now', $l->ping($id(50), 'player', $tt, false, '196.25.1.1', $laptop . ' ' . Agent::TEST_MARK) >= 1);
    $l->ping($id(50), 'player', $tt + 60, false, '196.25.1.1', $laptop . ' ' . Agent::TEST_MARK);
    $after = [$hours(), $count('listener_tally'), $count('listener_visitors'), $db->run('SELECT SUM(minutes) FROM ' . $db->table('listener_tally'))->fetchColumn()];
    check('  but adds nothing to the hours, the totals or the unique listeners', $before == $after);

    echo "Too many from one address\n";
    $c0 = $now + 22 * 86400;
    $err = null;
    for ($i = 0; $i < Listeners::NEW_PER_ADDRESS; $i++) {
        try { $l->ping($id(100 + $i), 'player', $c0 + $i, false, '203.0.113.9', $laptop); } catch (OverflowException $ex) { $err = $ex; }
    }
    check(Listeners::NEW_PER_ADDRESS . ' new Listens from one address in ' . (Listeners::CAP_SECONDS / 60) . ' minutes are fine', $err === null);
    try { $l->ping($id(200), 'player', $c0 + 30, false, '203.0.113.9', $laptop); } catch (OverflowException $ex) { $err = $ex; }
    check('  one more is refused', $err !== null);
    check('  but a Listen already counted carries on', $l->ping($id(100), 'player', $c0 + 60, false, '203.0.113.9', $laptop) >= 1);
    $err = null;
    try { $l->ping($id(201), 'player', $c0 + 31, false, '203.0.113.10', $laptop); } catch (OverflowException $ex) { $err = $ex; }
    check('  and another address is fine', $err === null);
    try { $l->ping($id(202), 'player', $c0 + Listeners::CAP_SECONDS + 30, false, '203.0.113.9', $laptop); } catch (OverflowException $ex) { $err = $ex; }
    check('  as is the same one later', $err === null);

    echo "Tidying up\n";
    $later = $now + (Listeners::RAW_DAYS + 30) * 86400;
    $l->ping($id(300), 'player', $later, false, '196.25.1.1', $phone);
    $left = $db->run('SELECT sid FROM ' . $db->table('listeners'))->fetchAll(PDO::FETCH_COLUMN);
    check('a new Listen deletes Listens older than ' . Listeners::RAW_DAYS . ' days, with their addresses', count($left) === 1 && $left[0] === $id(300), count($left) . ' left');
    $l->ping($id(301), 'player', $later + (Listeners::KEEP_DAYS - 10) * 86400);
    check('  totals are kept for ' . Listeners::KEEP_DAYS . ' days', count($hours()) === 2 && $count('listener_tally') > 0 && $count('listener_visitors') === 1, count($hours()) . ' hours, ' . $count('listener_visitors') . ' marks');
    $l->ping($id(302), 'player', $later + (Listeners::KEEP_DAYS + 1) * 86400);
    $oldest = $db->run('SELECT MIN(`day`) FROM ' . $db->table('listener_tally'))->fetchColumn();
    check('  and go after that', count($hours()) === 2 && $count('listener_visitors') === 0 && $oldest > gmdate('Y-m-d', (int) $later), count($hours()) . " hours, tally from $oldest");

    echo "Upgrade\n";
    $p = $cfg['db']['prefix'];
    foreach (['listener_visitors', 'listener_tally', 'listener_hours', 'listeners'] as $table) {
        $db->pdo->exec("DROP TABLE `{$p}$table`");
    }
    $db->run('UPDATE ' . $db->table('settings') . " SET value = '3' WHERE name = 'schema_version'");
    check('a version 3 database upgrades to ' . Schema::VERSION, Schema::upgrade($db, $root) === range(4, Schema::VERSION) && Schema::installed($db) === Schema::VERSION);
    $tables = fn() => array_map(fn($x) => (bool) $db->run("SHOW TABLES LIKE '{$p}$x'")->fetchColumn(), ['listeners', 'listener_hours', 'listener_tally', 'listener_visitors']);
    check('  with all four tables', $tables() === [true, true, true, true]);
    // a version 4 database: the old listeners table, open players in it
    $db->pdo->exec("DROP TABLE `{$p}listeners`");
    $db->pdo->exec("DROP TABLE `{$p}listener_tally`");
    $db->pdo->exec("DROP TABLE `{$p}listener_visitors`");
    $db->pdo->exec("CREATE TABLE `{$p}listeners` (`station_id` INT UNSIGNED NOT NULL, `sid` CHAR(16) NOT NULL, `source` ENUM('player','embed') NOT NULL,
        `first_seen` DECIMAL(15,3) NOT NULL, `last_seen` DECIMAL(15,3) NOT NULL, PRIMARY KEY (`station_id`, `sid`)) ENGINE=InnoDB");
    $db->run("INSERT INTO `{$p}listeners` VALUES (?, ?, 'player', ?, ?)", [$station['id'], $id(9), $now, $now]);
    $db->run('UPDATE ' . $db->table('settings') . " SET value = '4' WHERE name = 'schema_version'");
    check('a version 4 database upgrades to ' . Schema::VERSION, Schema::upgrade($db, $root) === range(5, Schema::VERSION) && $tables() === [true, true, true, true]);
    check('  and pings work in the new shape', $l->ping($id(9), 'player', $now, false, '8.8.8.8', $laptop) === 1
        && $db->run('SELECT country_code FROM ' . $db->table('listeners') . ' WHERE sid = ?', [$id(9)])->fetchColumn() === 'US');
    $db->run('UPDATE ' . $db->table('settings') . " SET value = '4' WHERE name = 'schema_version'");
    check('  the step can run again if it was cut short', Schema::upgrade($db, $root) === range(5, Schema::VERSION));
} finally {
    drop_test_database($t);
    remove_tree($root);
}
finish();
