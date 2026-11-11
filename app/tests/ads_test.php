<?php
// Command-line test of the ads (round 63): ads are kept apart from the songs, an ad break every N songs,
// pacing by plays a day, the days, hours and dates an ad runs, the log of ads that aired, and removing one.
// Makes its own silent MP3s and installs into a throwaway database, like planner_test.php.
//   php app/tests/ads_test.php      (same SW_TEST_DB_* settings as install_test.php)
declare(strict_types=1);
require __DIR__ . '/lib.php';
use Stationwagon\{Ads, Db, Library, Planner, Schema};

$t = test_database('ads');
$root = temp_root('ads');
try {
    $cfg = test_install($t, $root);
    $db = Db::connect($cfg['db']);
    $station = $db->run('SELECT * FROM ' . $db->table('stations'))->fetch();
    $tl = $db->table('timeline');
    $adsT = $db->table('ads');

    echo "Ads are kept apart from songs\n";
    mkdir("$root/in");
    $lib = new Library($db, $station, $root);
    $songs = [];
    foreach (['One', 'Two', 'Three', 'Four', 'Five', 'Six'] as $i => $name) {
        make_mp3("$root/in/$name.mp3", 120 + $i * 5, ['title' => "Song $name", 'artist' => 'The Testers', 'album' => 'Test Album', 'copyright' => '© 2020 Test Owner'], $i);
        $songs[$name] = $lib->import("$root/in/$name.mp3")['id'];
    }
    $newAd = function (string $name, int $salt, float $secs = 20) use ($lib, $root, $db, $station): int {
        make_mp3("$root/in/$name.mp3", $secs, ['title' => $name, 'artist' => 'Someone', 'album' => 'An Album', 'copyright' => '© 2020 Ad Owner'], $salt);
        $r = $lib->import("$root/in/$name.mp3", null, null, "$name.mp3", null, null, null, false, null, null, 'ad');
        (new Ads($db, $station))->create($r['id'], 'Acme');
        return $r['id'];
    };
    $a1 = $newAd('Ad Alpha', 50);
    $row = $lib->get($a1);
    check('an ad is a track of kind “ad”, with no album art, artist, album or copyright', $row['kind'] === 'ad' && $row['art'] === null && $row['artist'] === null && $row['album'] === null && $row['rights'] === null);
    check('the Music library lists only the songs', count($lib->tracks()) === 6 && !in_array($a1, array_column($lib->tracks(), 'id')));
    check('  and counts only their size', $lib->bytes() === (int) $db->run("SELECT SUM(bytes) FROM {$db->table('tracks')} WHERE kind = 'song'")->fetchColumn());
    $dup = null;
    try { $lib->import("$root/in/Ad Alpha.mp3"); } catch (RuntimeException $ex) { $dup = $ex->getMessage(); }
    check('the same recording as a song is refused, saying where it is', $dup !== null && str_contains($dup, 'already in the Ads'), (string) $dup);
    $dup = null;
    try { $lib->import("$root/in/One.mp3", null, null, null, null, null, null, false, null, null, 'ad'); } catch (RuntimeException $ex) { $dup = $ex->getMessage(); }
    check('  and a song’s recording can’t be an ad', $dup !== null && str_contains($dup, 'already in the Music library'), (string) $dup);
    $again = $lib->import("$root/in/Ad Alpha.mp3", null, null, null, null, null, null, false, null, null, 'ad');
    check('the same ad again is “already there”', $again['duplicate'] && $again['id'] === $a1);
    $lists = new Stationwagon\Playlists($db, $station);
    [$pl] = $lists->create('Test list', 'shuffle');
    $lists->add((int) $pl, [$a1, $songs['One']]);
    check('an ad can’t go into a playlist', array_column($lists->tracks((int) $pl), 'id') === [$songs['One']]);

    echo "The ad-break settings\n";
    $ads = new Ads($db, $station);
    check('they start on: a break after every 3 songs, one ad, no minimum gap', Ads::settings($station) === ['enabled' => true, 'every' => 3, 'per_break' => 1, 'gap_minutes' => 0], json_encode(Ads::settings($station)));
    $bad = [['every' => '0'], ['every' => '21'], ['every' => 'x'], ['per_break' => '4'], ['per_break' => '0'], ['gap_minutes' => '121'], ['gap_minutes' => '-1']];
    $ok = true;
    foreach ($bad as $b) {
        [$u, $problem] = $ads->saveSettings($b + ['every' => '3', 'per_break' => '1', 'gap_minutes' => '0']);
        $ok = $ok && $u === null && is_string($problem) && $problem !== '';
    }
    check('numbers out of range are refused, and nothing is stored', $ok && Ads::settings($db->run('SELECT * FROM ' . $db->table('stations'))->fetch())['every'] === 3);
    $save = function (array $in) use ($ads, $db, &$station): void {
        [$u, $problem] = $ads->saveSettings($in);
        if ($u === null) { throw new RuntimeException((string) $problem); }
        $station = $db->run('SELECT * FROM ' . $db->table('stations'))->fetch();
    };
    $save(['enabled' => '1', 'every' => '4', 'per_break' => '2', 'gap_minutes' => '30']);
    check('good values are saved and the other settings kept', Ads::settings($station) === ['enabled' => true, 'every' => 4, 'per_break' => 2, 'gap_minutes' => 30] && (float) Planner::settings($station)['crossfade_seconds'] === 6.0);
    $save(['every' => '3', 'per_break' => '1', 'gap_minutes' => '0']);
    check('the switch off is saved (an unchecked box sends nothing)', Ads::settings($station)['enabled'] === false);
    $save(['enabled' => '1', 'every' => '3', 'per_break' => '1', 'gap_minutes' => '0']);

    echo "An ad's plan\n";
    $good = ['title' => 'Ad Alpha', 'advertiser' => 'Acme', 'daily_target' => '8', 'active' => '1', 'days' => ['1', '2', '3', '4', '5', '6', '7']];
    $refuse = [
        'no title' => ['title' => ' '] + $good, 'plays -1' => ['daily_target' => '-1'] + $good, 'plays 501' => ['daily_target' => '501'] + $good, 'plays text' => ['daily_target' => 'lots'] + $good,
        'bad date' => ['start_date' => '2027-02-30'] + $good, 'end before start' => ['start_date' => '2027-03-01', 'end_date' => '2027-02-01'] + $good,
        'no days' => ['days' => []] + $good, 'bad time' => ['window_start' => '25:00', 'window_end' => '09:00'] + $good, 'only one time' => ['window_start' => '06:00'] + $good,
    ];
    $all = true;
    foreach ($refuse as $what => $in) {
        $r = $ads->update($a1, $in);
        $all = $all && is_string($r) && $r !== '';
        if (!is_string($r)) { echo "  (not refused: $what)\n"; }
    }
    check('a wrong title, number, date, day or time is refused with a reason', $all);
    check('  an ad that isn’t here is refused', $ads->update(999999, $good) !== null && $ads->update($songs['One'], $good) !== null);
    check('a good plan is saved', $ads->update($a1, $good + ['start_date' => '', 'end_date' => '2027-12-31', 'window_start' => '06:00', 'window_end' => '18:00', 'priority' => '1', 'notes' => ' Run all year '] ) === null
        && ($g = $ads->get($a1)) !== null && (int) $g['daily_target'] === 8 && (int) $g['days'] === 127 && $g['end_date'] === '2027-12-31' && $g['window_start'] === '06:00' && (int) $g['priority'] === 1 && $g['notes'] === 'Run all year', json_encode($g ?? null));
    check('  and says it in words', Ads::describe($g) === 'Every day · 06:00–18:00' && Ads::describe(['days' => 31, 'window_start' => null, 'window_end' => null]) === 'Mon–Fri · all day'
        && Ads::describe(['days' => 96, 'window_start' => '22:00', 'window_end' => '02:00']) === 'Sat–Sun · 22:00–02:00' && Ads::describe(['days' => 5, 'window_start' => null, 'window_end' => null]) === 'Mon, Wed · all day');
    check('  a new ad title is on its track', $lib->update($a1, 'Ad Alpha', '') === null && $lib->get($a1)['title'] === 'Ad Alpha');

    echo "Which ad is due\n";
    $tz = new DateTimeZone($station['timezone']);
    $at = fn(string $local) => (float) (new DateTimeImmutable($local, $tz))->getTimestamp();
    $old = '2020-01-01 00:00:00'; // made long ago: pacing counts from the start of its hours
    $plan = function (int $id, array $set) use ($db, $adsT): void {
        $cols = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($set)));
        $db->run("UPDATE $adsT SET $cols, created_at = '2020-01-01 00:00:00', active = COALESCE(?, 1) WHERE track_id = ?", [...array_values($set), $set['active'] ?? null, $id]);
    };
    $reset = fn() => $db->run("UPDATE $adsT SET active = 1, daily_target = 8, start_date = NULL, end_date = NULL, days = 127, window_start = NULL, window_end = NULL, priority = 0, created_at = '2020-01-01 00:00:00'");
    $reset();
    $db->run("DELETE FROM $tl");
    $fri10 = $at('2027-01-15 10:00:00'); // a Friday
    check('an ad that runs all day is due', $ads->pick($fri10) === $a1);
    check('  one the break already has is skipped', $ads->pick($fri10, [$a1]) === null);
    $db->run("UPDATE $adsT SET window_start = '06:00', window_end = '09:00'");
    check('outside its hours it is not due; inside them it is', $ads->pick($at('2027-01-15 10:00:00')) === null && $ads->pick($at('2027-01-15 07:00:00')) === $a1 && $ads->pick($at('2027-01-15 09:00:00')) === null);
    $db->run("UPDATE $adsT SET window_start = '22:00', window_end = '02:00'");
    check('hours that run past midnight work (22:00 to 02:00)', $ads->pick($at('2027-01-15 23:00:00')) === $a1 && $ads->pick($at('2027-01-16 01:00:00')) === $a1 && $ads->pick($at('2027-01-15 12:00:00')) === null);
    $reset();
    $db->run("UPDATE $adsT SET days = 96"); // Sat and Sun
    check('on days it doesn’t run it is not due (a Friday), and is on a Saturday', $ads->pick($fri10) === null && $ads->pick($at('2027-01-16 10:00:00')) === $a1);
    $reset();
    $db->run("UPDATE $adsT SET start_date = '2027-01-16'");
    check('before its start date it is not due', $ads->pick($fri10) === null && $ads->pick($at('2027-01-16 00:30:00')) === $a1);
    $db->run("UPDATE $adsT SET start_date = NULL, end_date = '2027-01-14'");
    check('after its end date it is not due', $ads->pick($fri10) === null);
    $db->run("UPDATE $adsT SET end_date = '2027-01-15'");
    check('  on its end date it still is', $ads->pick($fri10) === $a1);
    $reset();
    $db->run("UPDATE $adsT SET daily_target = 0");
    check('0 plays a day pauses it', $ads->pick($fri10) === null);
    $reset();
    $db->run("UPDATE $adsT SET active = 0");
    check('an ad that is not active is not due', $ads->pick($fri10) === null);
    $reset();
    $a2 = $newAd('Ad Beta', 51);
    $reset();
    $db->run("UPDATE $adsT SET daily_target = 24 WHERE track_id = $a1");
    $db->run("UPDATE $adsT SET daily_target = 2 WHERE track_id = $a2");
    check('the ad furthest behind its pace goes first (24 a day against 2 a day, at 10:00)', $ads->pick($fri10) === $a1 && $ads->pick($fri10, [$a1]) === $a2);
    $reset();
    $db->run("UPDATE $adsT SET priority = 1 WHERE track_id = $a2");
    check('two equally due: the one that plays first, then the lower id', $ads->pick($fri10) === $a2);
    $db->run("UPDATE $adsT SET priority = 0");
    check('  with nothing between them, the lower id', $ads->pick($fri10) === $a1);
    // an ad added today is paced over what is left of its hours
    $reset();
    $db->run("UPDATE $adsT SET created_at = '2027-01-15 07:30:00' WHERE track_id = $a1"); // 09:30 local
    $db->run("UPDATE $adsT SET active = 0 WHERE track_id = $a2");
    check('an ad added today is not owed the plays of the hours before it was there (8 a day, added at 09:30: not due again soon)', $ads->pick($at('2027-01-15 09:31:00')) === $a1
        && (function () use ($db, $tl, $ads, $a1, $at) {
            $db->run("INSERT INTO $tl (station_id, n, type, track_id, start_at, play_s, full_s, fade_out_s, seq, piece_count) VALUES (1, 1, 'song', $a1, ?, 20, 20, 0, 0, 3)", [$at('2027-01-15 09:31:00')]);
            $r = $ads->pick($at('2027-01-15 10:00:00'));
            $db->run("DELETE FROM $tl");
            return $r === null;
        })());
    $reset();
    $db->run("UPDATE $adsT SET active = 1");

    echo "Ad breaks in the plan\n";
    $db->run("DELETE FROM $tl");
    $db->run("UPDATE $adsT SET active = 0 WHERE track_id = $a2");
    $db->run("UPDATE $adsT SET daily_target = 6, window_start = NULL, window_end = NULL, created_at = '2020-01-01 00:00:00' WHERE track_id = $a1");
    $p = new Planner($db, $station, $root);
    $t0 = $fri10;
    $entries = $p->timeline($t0);
    $kinds = array_map(fn($e) => !empty($e['ad']) ? 'ad' : $e['type'], $entries);
    $adAt = array_keys(array_filter($kinds, fn($k) => $k === 'ad'));
    check('the plan has ads in it, and they are the ad (never the songs’ title)', count($adAt) >= 1 && $entries[$adAt[0]]['track_id'] === $a1 && $entries[$adAt[0]]['title'] === 'Advertisement' && $entries[$adAt[0]]['ad_title'] === 'Ad Alpha');
    check('  an ad shows no artist, album, art or copyright line', $entries[$adAt[0]]['artist'] === null && $entries[$adAt[0]]['album'] === null && $entries[$adAt[0]]['art'] === null && $entries[$adAt[0]]['rights'] === null);
    $gapsOk = true;
    for ($i = 1; $i < count($adAt); $i++) { $gapsOk = $gapsOk && $adAt[$i] - $adAt[$i - 1] > 3; }
    check('  the first comes after 3 songs, and each after at least 3 songs more', $adAt[0] >= 3 && $gapsOk, json_encode($kinds));
    $played = count($adAt);
    check('  6 a day, 10:00 to 13:00: no more than its pace allows (3 hours of 24: at most 2 plays, kept apart)', $played >= 1 && $played <= 2, (string) $played);
    $joined = true;
    foreach ($entries as $k => $e) { if ($k > 0) { $joined = $joined && abs($e['start'] - $entries[$k - 1]['end']) < 0.002; } }
    check('  and the entries still follow each other with no gap', $joined);
    $songsOnly = array_values(array_filter($entries, fn($e) => empty($e['ad'])));
    check('  no song is an ad, and the “not to repeat” rule counts songs only', !array_intersect(array_column($songsOnly, 'track_id'), [$a1]) && $p->setting('recent_tracks') === 5);
    // 1 a day: once planned today, never again today
    $db->run("DELETE FROM $tl");
    $db->run("UPDATE $adsT SET daily_target = 1 WHERE track_id = $a1");
    $p = new Planner($db, $station, $root);
    $e1 = $p->timeline($t0);
    check('1 play a day: one in the whole 3-hour plan', count(array_filter($e1, fn($e) => !empty($e['ad']))) === 1);
    $p->replan($t0 + 5);
    check('  and planning it all again puts it there once, not again', count(array_filter($p->timeline($t0 + 5), fn($e) => !empty($e['ad']))) === 1);

    // ads off
    $db->run("DELETE FROM $tl");
    $save(['every' => '3', 'per_break' => '1', 'gap_minutes' => '0']); // not enabled
    $pOff = new Planner($db, $station, $root);
    check('with ads off no ad is planned', !array_filter($pOff->timeline($t0), fn($e) => !empty($e['ad'])));
    $save(['enabled' => '1', 'every' => '3', 'per_break' => '1', 'gap_minutes' => '0']);

    // after every 2 songs; two ads in a break
    $db->run("DELETE FROM $tl");
    $db->run("UPDATE $adsT SET active = 1, daily_target = 60 WHERE track_id IN ($a1, $a2)");
    $save(['enabled' => '1', 'every' => '2', 'per_break' => '2', 'gap_minutes' => '0']);
    $pTwo = new Planner($db, $station, $root);
    $et = $pTwo->timeline($t0);
    $kt = array_map(fn($e) => !empty($e['ad']) ? 'A' : 'S', $et);
    $s = implode('', $kt);
    check('after every 2 songs, two different ads in a break (S S A A …)', str_starts_with($s, 'SSAA') && preg_match('/^(SSAA|SS|AA)+/', $s) === 1 && !preg_match('/AAA/', $s), $s);
    $pairsOk = true;
    foreach ($et as $i => $e) { if (!empty($e['ad']) && !empty($et[$i + 1]['ad'])) { $pairsOk = $pairsOk && $e['track_id'] !== $et[$i + 1]['track_id']; } }
    check('  the two in a break are different ads', $pairsOk);

    // minutes between breaks
    $db->run("DELETE FROM $tl");
    $save(['enabled' => '1', 'every' => '1', 'per_break' => '1', 'gap_minutes' => '45']);
    $db->run("UPDATE $adsT SET daily_target = 500");
    $pGap = new Planner($db, $station, $root);
    $eg = $pGap->timeline($t0);
    $last = null; $gapOk = true; $nAds = 0;
    foreach ($eg as $e) {
        if (!empty($e['ad'])) { $nAds++; if ($last !== null) { $gapOk = $gapOk && $e['start'] - $last >= 45 * 60 - 1; } $last = $e['end']; }
    }
    check('at least 45 minutes between breaks, though a song is enough for one', $nAds >= 2 && $gapOk, $nAds . ' ads');

    echo "What aired\n";
    $save(['enabled' => '1', 'every' => '3', 'per_break' => '1', 'gap_minutes' => '0']);
    $db->run("DELETE FROM $tl");
    $db->run("UPDATE $adsT SET daily_target = 6 WHERE track_id IN ($a1, $a2)");
    $pLog = new Planner($db, $station, $root);
    $planned = array_values(array_filter($pLog->timeline($t0), fn($e) => !empty($e['ad'])));
    $ads->logAired($t0 + 5);
    $logged = (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('ad_plays'))->fetchColumn();
    $ended = count(array_filter($planned, fn($e) => $e['end'] <= $t0 + 5));
    check('only ads that have finished are logged', $logged === $ended, "$logged logged, $ended ended");
    $ads->logAired($t0 + 86400);
    $logged = (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('ad_plays'))->fetchColumn();
    check('once the day is over every one is, each once (a second run adds none)', $logged === count($planned) && ($ads->logAired($t0 + 86400) ?? true) && (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('ad_plays'))->fetchColumn() === count($planned));
    $one = $db->run('SELECT * FROM ' . $db->table('ad_plays') . ' ORDER BY started_at LIMIT 1')->fetch();
    check('  with its title, advertiser, start and how long it played', $one['title'] === $lib->get((int) $one['track_id'])['title'] && $one['advertiser'] === 'Acme' && (float) $one['played_s'] > 10);
    $hist = $ads->history($t0 + 86400, 3);
    check('  and the log gives the plays of each of the last days', array_sum(array_map('array_sum', $hist)) === count($planned) && isset($hist['2027-01-15']));
    $list = $ads->all($t0 + 5);
    $byId = array_column($list, null, 'track_id');
    check('the Ads list says how many played and are planned today', ($byId[$a1]['aired_today'] ?? -1) + ($byId[$a1]['planned_today'] ?? -1) + ($byId[$a2]['aired_today'] ?? -1) + ($byId[$a2]['planned_today'] ?? -1) === count($planned)
        && $byId[$a1]['title'] === 'Ad Alpha');

    echo "Removing an ad\n";
    $pDel = new Planner($db, $station, $root);
    $now = $t0 + 86400 * 5; // long after it aired
    check('removing an ad takes its plan with it but keeps its log', $lib->delete($a2, $pDel, $now) === null && $ads->get($a2) === null && (int) $db->run("SELECT COUNT(*) FROM $adsT WHERE track_id = $a2")->fetchColumn() === 0);
    check('  the ad is no longer on the list or in the plan', !in_array($a2, array_column($ads->all($now), 'track_id')) && !array_filter($pDel->timeline($now), fn($e) => ($e['track_id'] ?? 0) === $a2));
    check('upgrading a version 16 database adds the ads (kind, plans and log)', (function () use ($db, $root) {
        $db->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $db->pdo->exec('DROP TABLE ' . $db->table('ad_plays'));
        $db->pdo->exec('DROP TABLE ' . $db->table('ads'));
        $db->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $db->pdo->exec('ALTER TABLE ' . $db->table('tracks') . ' DROP INDEX ' . $db->table('tracks_station_kind') . ', DROP COLUMN kind');
        $db->run('UPDATE ' . $db->table('settings') . " SET value = '16' WHERE name = 'schema_version'");
        $ran = Schema::upgrade($db, $root);
        return $ran === [17] && $db->run('SHOW COLUMNS FROM ' . $db->table('tracks') . " LIKE 'kind'")->fetch() !== false
            && $db->run("SHOW TABLES LIKE 'sw\_ads'")->fetch() !== false && $db->run("SHOW TABLES LIKE 'sw\_ad\_plays'")->fetch() !== false
            && Schema::upgrade($db, $root) === [];
    })());
} finally {
    drop_test_database($t);
    remove_tree($root);
}
finish();
