<?php
// Command-line test of the jingles (round 70): jingles are kept apart from songs and ads, the jingle settings,
// which jingle is next, jingles in the plan (between two songs, after every N songs, never beside an ad or another
// jingle), what listeners see (the station's name), the short crossfade, removing one, and the upgrade to schema 18.
// Makes its own silent MP3s and installs into a throwaway database, like ads_test.php.
//   php app/tests/jingles_test.php      (same SW_TEST_DB_* settings as install_test.php)
declare(strict_types=1);
require __DIR__ . '/lib.php';
use Stationwagon\{Ads, Db, Jingles, Library, NowPlaying, Planner, Schema};

$t = test_database('jingles');
$root = temp_root('jingles');
try {
    $cfg = test_install($t, $root);
    $db = Db::connect($cfg['db']);
    $station = $db->run('SELECT * FROM ' . $db->table('stations'))->fetch();
    $tl = $db->table('timeline');
    $jT = $db->table('jingles');
    $aT = $db->table('ads');
    $fresh = fn() => $db->run('SELECT * FROM ' . $db->table('stations'))->fetch();

    echo "Jingles are kept apart from songs and ads\n";
    mkdir("$root/in");
    $lib = new Library($db, $station, $root);
    $songs = [];
    foreach (['One', 'Two', 'Three', 'Four', 'Five', 'Six'] as $i => $name) {
        make_mp3("$root/in/$name.mp3", 120 + $i * 5, ['title' => "Song $name", 'artist' => 'The Testers', 'album' => 'Test Album', 'copyright' => '© 2020 Test Owner'], $i);
        $songs[$name] = $lib->import("$root/in/$name.mp3")['id'];
    }
    $newJingle = function (string $name, int $salt, float $secs = 4) use ($lib, $root, $db, $station): int {
        make_mp3("$root/in/$name.mp3", $secs, ['title' => $name, 'artist' => 'Someone', 'album' => 'An Album', 'copyright' => '© 2020 Jingle Owner'], $salt);
        $r = $lib->import("$root/in/$name.mp3", null, null, "$name.mp3", null, null, null, false, null, null, 'jingle');
        (new Jingles($db, $station))->create($r['id']);
        return $r['id'];
    };
    $j1 = $newJingle('Jingle Alpha', 70);
    $row = $lib->get($j1);
    check('a jingle is a track of kind “jingle”, with no album art, artist, album or copyright', $row['kind'] === 'jingle' && $row['art'] === null && $row['artist'] === null && $row['album'] === null && $row['rights'] === null);
    check('the Music library lists only the songs', count($lib->tracks()) === 6 && !in_array($j1, array_column($lib->tracks(), 'id')));
    $dup = null;
    try { $lib->import("$root/in/Jingle Alpha.mp3"); } catch (RuntimeException $ex) { $dup = $ex->getMessage(); }
    check('the same recording as a song is refused, saying where it is', $dup !== null && str_contains($dup, 'already in the Jingles'), (string) $dup);
    $dup = null;
    try { $lib->import("$root/in/Jingle Alpha.mp3", null, null, null, null, null, null, false, null, null, 'ad'); } catch (RuntimeException $ex) { $dup = $ex->getMessage(); }
    check('  and as an ad', $dup !== null && str_contains($dup, 'already in the Jingles'), (string) $dup);
    $dup = null;
    try { $lib->import("$root/in/One.mp3", null, null, null, null, null, null, false, null, null, 'jingle'); } catch (RuntimeException $ex) { $dup = $ex->getMessage(); }
    check('  and a song’s recording can’t be a jingle', $dup !== null && str_contains($dup, 'already in the Music library'), (string) $dup);
    $again = $lib->import("$root/in/Jingle Alpha.mp3", null, null, null, null, null, null, false, null, null, 'jingle');
    check('the same jingle again is “already there”', $again['duplicate'] && $again['id'] === $j1);
    $lists = new Stationwagon\Playlists($db, $station);
    [$pl] = $lists->create('Test list', 'shuffle');
    $lists->add((int) $pl, [$j1, $songs['One']]);
    check('a jingle can’t go into a playlist', array_column($lists->tracks((int) $pl), 'id') === [$songs['One']]);

    echo "The jingle settings\n";
    $jin = new Jingles($db, $station);
    check('they start on: a jingle after every 3 songs (WordWave\x27s, round 86), blending over 1 s', Jingles::settings($station) === ['enabled' => true, 'every' => 3, 'crossfade' => 1.0], json_encode(Jingles::settings($station)));
    [$u, $why] = $jin->saveSettings(['enabled' => '1', 'every' => '25']);
    check('25 songs is refused, and nothing is stored', $u === null && $why !== null && Jingles::settings($fresh())['every'] === 3);
    [$u, $why] = $jin->saveSettings(['enabled' => '1', 'every' => 'often']);
    check('  so is a word', $u === null && $why !== null);
    [$u, $why] = $jin->saveSettings(['enabled' => '1', 'every' => '2']);
    $station = $fresh();
    $jin = new Jingles($db, $station);
    check('2 songs is saved, and the other settings are kept', $u !== null && $why === null && Jingles::settings($station)['every'] === 2 && (float) Planner::settings($station)['crossfade_seconds'] === 8.0 && Ads::settings($station)['every'] === 3);
    [$u] = $jin->saveSettings(['every' => '2']); // an unchecked box sends nothing
    check('the switch off is saved', $u !== null && Jingles::settings($fresh())['enabled'] === false);
    $jin->saveSettings(['enabled' => '1', 'every' => '2']);
    $station = $fresh();
    $jin = new Jingles($db, $station);
    check('a jingle needs a title', Jingles::check(['title' => '  ', 'active' => '1'])[1] !== null);
    check('title, on and notes are checked', Jingles::check(['title' => ' Radio ID ', 'active' => '1', 'notes' => ' hi '])[0] === ['title' => 'Radio ID', 'active' => 1, 'notes' => 'hi']
        && Jingles::check(['title' => 'x'])[0]['active'] === 0);
    check('a jingle’s title and on/off are saved', $jin->update($j1, ['title' => 'Jingle A2', 'active' => '1', 'notes' => 'for mornings']) === null && $lib->get($j1)['title'] === 'Jingle A2' && $jin->get($j1)['notes'] === 'for mornings'
        && $jin->update(99999, ['title' => 'x']) === 'That jingle isn’t here.');
    $jin->update($j1, ['title' => 'Jingle Alpha', 'active' => '1']);

    echo "Which jingle is next\n";
    $db->run("DELETE FROM $tl");
    check('the only jingle that is on', $jin->pick() === $j1);
    $j2 = $newJingle('Jingle Beta', 71);
    check('two never played: the older one', $jin->pick() === $j1);
    $db->run("INSERT INTO $tl (station_id, n, type, track_id, start_at, play_s, full_s, fade_out_s, seq, piece_count) VALUES (1, 1, 'song', $j1, 1000, 4, 4, 1, 0, 1)");
    check('one played (or planned) is behind the one that never was', $jin->pick() === $j2);
    $db->run("INSERT INTO $tl (station_id, n, type, track_id, start_at, play_s, full_s, fade_out_s, seq, piece_count) VALUES (1, 2, 'song', $j2, 2000, 4, 4, 1, 1, 1)");
    check('both played: the one played longest ago', $jin->pick() === $j1);
    $jin->update($j1, ['title' => 'Jingle Alpha', 'active' => '0']);
    check('one that is off is skipped', $jin->pick() === $j2);
    $jin->update($j2, ['title' => 'Jingle Beta', 'active' => '0']);
    check('none on: none is picked', $jin->pick() === null);
    $jin->update($j1, ['title' => 'Jingle Alpha', 'active' => '1']);
    $jin->update($j2, ['title' => 'Jingle Beta', 'active' => '1']);
    check('the planner’s facts for it', $jin->track($j1) === ['id' => $j1, 'duration' => (float) $lib->get($j1)['duration_s'], 'outro' => null, 'jingle' => true] && $jin->track($songs['One']) === null);
    $db->run("DELETE FROM $tl");

    echo "Jingles in the plan\n";
    $tz = new DateTimeZone($station['timezone']);
    $t0 = (float) (new DateTimeImmutable('2027-01-15 10:00:00', $tz))->getTimestamp();
    $kinds = fn(array $es) => array_map(fn($e) => $e['type'] === 'live' ? 'L' : (!empty($e['ad']) ? 'A' : (!empty($e['jingle']) ? 'J' : 'S')), $es);
    // one jingle on, a jingle after every 2 songs, no ads
    $jin->update($j2, ['title' => 'Jingle Beta', 'active' => '0']);
    $db->run("UPDATE $aT SET active = 0");
    $p = new Planner($db, $station, $root);
    $es = $p->timeline($t0);
    $k = implode('', $kinds($es));
    check('the plan is songs with a jingle after every 2', preg_match('/^(SSJ)+/', $k) === 1 && substr_count($k, 'J') >= 5, $k);
    $first = array_values(array_filter($es, fn($e) => !empty($e['jingle'])))[0];
    check('a jingle shows the station’s name, never its own title, and no artist, album, art or copyright line', $first['title'] === $station['name'] && $first['jingle_title'] === 'Jingle Alpha'
        && $first['artist'] === null && $first['album'] === null && $first['art'] === null && $first['rights'] === null && $first['track_id'] === $j1);
    $ji = array_keys(array_filter($kinds($es), fn($x) => $x === 'J'))[0];
    check('it blends over its own short crossfade, and the song before it fades out over it too', abs($es[$ji]['fade_out'] - 1.0) < 1e-9 && abs($es[$ji - 1]['fade_out'] - 1.0) < 1e-9 && abs($es[$ji + 1]['fade_out'] - 8.0) < 1e-9);
    check('  a jingle plays whole (its whole length, give or take a piece)', $es[$ji]['play'] > 0 && $es[$ji]['play'] <= $lib->get($j1)['duration_s'] + 0.01);
    check('songs never repeat because of jingles (the recent songs ignore them)', count(array_unique(array_map(fn($e) => $e['track_id'], array_slice(array_filter($es, fn($e) => empty($e['jingle'])), 0, 4)))) === 4);

    echo "What listeners see\n";
    $now = $es[$ji - 1]['start'] + 21; // the song before the first jingle is on air (listeners are 20 s behind)
    $np = (new NowPlaying($p))->at($now);
    check('the jingle is not listed under Next', $np['on_air']['title'] === $es[$ji - 1]['title'] && !in_array($station['name'], array_column($np['next'], 'title'), true) && count($np['next']) === 3, json_encode(array_column($np['next'], 'title')));
    $now = $es[$ji]['start'] + 21;
    $np = (new NowPlaying($p))->at($now);
    check('  and on air it is the station’s name', $np['on_air']['title'] === $station['name'] && $np['on_air']['artist'] === null && $np['on_air']['art'] === null);
    $now = $es[$ji + 1]['start'] + 21;
    $np = (new NowPlaying($p))->at($now);
    check('  nor under Recent', !in_array($station['name'], array_column($np['recent'], 'title'), true) && count($np['recent']) >= 1);

    echo "Switching them off, and a station with no jingle\n";
    [$u] = $jin->saveSettings(['every' => '2']); // off
    $pOff = new Planner($db, $fresh(), $root);
    $pOff->replan($t0);
    check('with jingles off none is planned', !str_contains(implode('', $kinds($pOff->timeline($t0))), 'J'));
    $jin->saveSettings(['enabled' => '1', 'every' => '2']);
    $jin->update($j1, ['title' => 'Jingle Alpha', 'active' => '0']);
    $pNone = new Planner($db, $fresh(), $root);
    $pNone->replan($t0);
    check('with no jingle on none is planned (and the songs carry on)', !str_contains(implode('', $kinds($pNone->timeline($t0))), 'J') && count($pNone->timeline($t0)) > 20);
    $jin->update($j1, ['title' => 'Jingle Alpha', 'active' => '1']);

    echo "Jingles and ad breaks\n";
    $j2on = $jin->update($j2, ['title' => 'Jingle Beta', 'active' => '1']) === null;
    $a1 = (function () use ($lib, $root, $db, $station) {
        make_mp3("$root/in/Ad One.mp3", 20, ['title' => 'Ad One'], 90);
        $r = $lib->import("$root/in/Ad One.mp3", null, null, 'Ad One.mp3', null, null, null, false, null, null, 'ad');
        (new Ads($db, $station))->create($r['id'], 'Acme');
        return $r['id'];
    })();
    $db->run("UPDATE $aT SET active = 1, daily_target = 500, window_start = NULL, window_end = NULL, created_at = '2020-01-01 00:00:00'");
    $pBoth = new Planner($db, $fresh(), $root);
    $pBoth->replan($t0);
    $es = $pBoth->timeline($t0);
    $ks = $kinds($es);
    $k = implode('', $ks);
    check('the plan has both ads and jingles', substr_count($k, 'A') >= 2 && substr_count($k, 'J') >= 2, $k);
    $clean = true;
    foreach ($ks as $i => $x) {
        if ($x === 'J' && (($ks[$i - 1] ?? 'S') !== 'S' || ($ks[$i + 1] ?? 'S') !== 'S')) { $clean = false; }
    }
    check('a jingle always sits between two songs (never beside an ad or another jingle)', $clean, $k);
    $gaps = [];
    $since = 0;
    $seenAd = false;
    foreach ($ks as $x) {
        if ($x === 'A') { if ($seenAd) { $gaps[] = $since; } $seenAd = true; $since = 0; } elseif ($x === 'S') { $since++; }
    }
    check('an ad break comes after every 3 songs, and jingles do not count as songs', $gaps && min($gaps) >= 3 && max($gaps) <= 4, json_encode($gaps) . ' ' . $k);
    check('a jingle placed after an ad break waits for a song first (no ad then jingle)', !str_contains($k, 'AJ') && !str_contains($k, 'JA') && !str_contains($k, 'JJ'));
    $db->run("UPDATE $aT SET active = 0");

    echo "Removing a jingle\n";
    $pDel = new Planner($db, $fresh(), $root);
    $pDel->replan($t0);
    $on = array_values(array_filter($pDel->timeline($t0), fn($e) => !empty($e['jingle']) && $e['track_id'] === $j2));
    $later = $t0 + 100000;
    check('removing a jingle takes its row with it, and it leaves the list and the plan', $lib->delete($j2, $pDel, $later) === null && $jin->get($j2) === null
        && (int) $db->run("SELECT COUNT(*) FROM $jT WHERE track_id = $j2")->fetchColumn() === 0
        && !in_array($j2, array_column($jin->all($later), 'track_id'), true) && !array_filter($pDel->timeline($later), fn($e) => ($e['track_id'] ?? 0) === $j2));
    $list = $jin->all($t0);
    check('the list has the jingle, with what it did today', count($list) === 1 && $list[0]['track_id'] === $j1 && isset($list[0]['aired_today'], $list[0]['planned_today']));

    echo "Upgrading a version 17 database\n";
    check('it adds the jingle kind and its table, and runs once', (function () use ($db, $root) {
        $db->pdo->exec('DROP TABLE ' . $db->table('jingles'));
        $db->pdo->exec('DELETE FROM ' . $db->table('tracks') . " WHERE kind = 'jingle'");
        $db->pdo->exec('ALTER TABLE ' . $db->table('tracks') . " MODIFY `kind` ENUM('song','ad') NOT NULL DEFAULT 'song'");
        $db->run('UPDATE ' . $db->table('settings') . " SET value = '17' WHERE name = 'schema_version'");
        $ran = Schema::upgrade($db, $root);
        $type = (string) $db->run('SHOW COLUMNS FROM ' . $db->table('tracks') . " LIKE 'kind'")->fetch()['Type'];
        return $ran === range(18, Schema::VERSION) && str_contains($type, "'jingle'") && $db->run("SHOW TABLES LIKE 'sw\_jingles'")->fetch() !== false && Schema::upgrade($db, $root) === [];
    })());
} finally {
    drop_test_database($t);
    remove_tree($root);
}
finish();
