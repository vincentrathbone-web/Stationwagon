<?php
// Command-line test of the station manager's rules: library edit/search/delete, song prep's
// presets, kept originals and Apply (round 40), song prep on the server (round 85: the queue, the worker with Node, Set up), playlists, the weekly schedule, Sound & safety settings and who may do what to whom (Staff). Throwaway
// database and install.
//   php app/tests/manage_test.php      (same SW_TEST_DB_* settings as install_test.php)
declare(strict_types=1);
require __DIR__ . '/lib.php';
use Stationwagon\{Auth, Db, DiagReports, Library, NodeRuntime, Permissions, Planner, Playlists, PrepPresets, PrepQueue, Roles, Schedule, Schema, Staff, StationSettings, Studio};

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

    echo "Song prep presets (round 40)\n";
    $pp = new PrepPresets($db, $station);
    check('new uploads start with the built-in Audition chain', $pp->forImport()[0] === 'Audition chain' && $pp->forImport()[1] == (object) [] && PrepPresets::defaultId($station) === 0);
    $warm = ['ratio' => [4, 3, 3, 4], 'eqOn' => true, 'eq' => ['low' => ['hz' => 90, 'db' => 2.5], 'bells' => [['hz' => 250, 'db' => -1, 'q' => 1.2]]], 'simple' => ['amount' => 0.7]];
    [$wid, $problem] = $pp->save(0, '  Warm   evening ', $warm, $now);
    check('a preset is saved, its name tidied', $problem === null && $pp->get($wid)['name'] === 'Warm evening' && $pp->get($wid)['settings'] === $warm, (string) $problem);
    check('  the same name again is refused', $pp->save(0, 'Warm evening', $warm, $now)[1] !== null);
    check('  so are no name, a long one and the built-in one’s', $pp->save(0, ' ', $warm, $now)[1] !== null && $pp->save(0, str_repeat('n', 61), $warm, $now)[1] !== null
        && $pp->save(0, 'audition CHAIN', $warm, $now)[1] !== null);
    $bad = [
        'a list, not named settings' => [1, 2],
        'an unknown setting' => ['ratio' => [2], 'volume' => 3],
        'text for a number' => ['ratio' => ['4']],
        'text inside' => ['eq' => ['low' => ['hz' => '<b>']]],
        'too deep' => ['eq' => ['a' => ['b' => ['c' => [1]]]]],
        'too wide' => ['ratio' => range(1, 11)],
        'too big' => ['eq' => array_fill(0, 10, array_fill(0, 10, array_fill(0, 10, 1.23456789)))],
        'a strange key' => ['eq' => ['low<' => ['hz' => 90]]],
        'not a set at all' => 'ratio',
    ];
    $let = array_keys(array_filter($bad, fn($x) => PrepPresets::check($x) === null));
    check('  a preset with ' . implode(', ', array_keys($bad)) . ' is refused', $let === [], implode(', ', $let));
    check('  an empty preset is the built-in chain, and allowed', PrepPresets::check([]) === null);
    check('a preset can be changed and renamed', $pp->save($wid, 'Warm night', ['ratio' => [5, 3, 3, 5]], $now)[1] === null && $pp->get($wid)['name'] === 'Warm night'
        && $pp->get($wid)['settings'] === ['ratio' => [5, 3, 3, 5]]);
    check('  but not one that isn’t there', $pp->save(99999, 'Ghost', [], $now)[1] !== null);
    for ($i = count($pp->all()); $i < PrepPresets::MAX; $i++) { $pp->save(0, "Filler $i", [], $now); }
    check('at most ' . PrepPresets::MAX . ' presets', count($pp->all()) === PrepPresets::MAX && $pp->save(0, 'One too many', [], $now)[1] !== null);
    $station = $pp->setDefault($wid);
    $pp = new PrepPresets($db, $station);
    check('one is the default for new uploads', PrepPresets::defaultId($station) === $wid && $pp->forImport() === ['Warm night', ['ratio' => [5, 3, 3, 5]]]);
    check('  a default that isn’t a preset is the built-in one', PrepPresets::defaultId((new PrepPresets($db, $station))->setDefault(99999)) === 0);
    $station = $pp->setDefault($wid);
    $pp = new PrepPresets($db, $station);
    check('removing the default goes back to the built-in one', $pp->delete($wid) === null && $pp->get($wid) === null
        && PrepPresets::defaultId($db->run('SELECT * FROM ' . $db->table('stations'))->fetch()) === 0);
    check('  removing it twice says so', $pp->delete($wid) !== null);
    foreach ($pp->all() as $x) { $pp->delete($x['id']); }
    $station = $db->run('SELECT * FROM ' . $db->table('stations'))->fetch();
    $lib = new Library($db, $station, $root);

    echo "Originals and Apply (round 40)\n";
    // as uploaded with song prep: the station's copy differs from the file the manager chose
    make_mp3("$root/in/chosen", 140, ['title' => 'Gloria'], 21);
    make_mp3("$root/in/prepared", 138, ['title' => 'Gloria'], 22);
    $chosen = sha1_file("$root/in/chosen");
    $gl = $lib->import("$root/in/prepared", null, null, 'Gloria.mp3', $chosen, -16.0, null, true, 'Warm night');
    $glr = $lib->get($gl['id']);
    check('a prepared song records its preset', (int) $glr['mastered'] === 1 && $glr['preset'] === 'Warm night');
    check('  it has no original until one is kept', $lib->original($glr) === null);
    check('  only its own original is kept', $lib->keepOriginal($gl['id'], "$root/in/prepared") !== null && $lib->keepOriginal(99999, "$root/in/chosen") !== null);
    check('  its original is kept', $lib->keepOriginal($gl['id'], "$root/in/chosen") === null && $lib->original($glr) === "$root/storage/originals/$chosen.mp3"
        && sha1_file("$root/storage/originals/$chosen.mp3") === $chosen);
    $ht = (string) file_get_contents("$root/storage/originals/.htaccess");
    check('  where the web can’t fetch it', str_contains($ht, 'Require all denied') && str_contains($ht, '<FilesMatch ".">'));
    // as uploaded without song prep: its media file is its original
    make_mp3("$root/in/asis", 130, ['title' => 'Sanctus'], 23);
    $sa = $lib->import("$root/in/asis", null, null, 'Sanctus.mp3');
    $sar = $lib->get($sa['id']);
    check('a song uploaded as it was is its own original', $lib->keepOriginal($sa['id'], "$root/in/asis") === null && $lib->original($sar) === "$root/storage/media/{$sar['file']}"
        && !is_file("$root/storage/originals/{$sar['sha1']}.mp3"));
    // the planner's rule for "on air (or just was)", and a time when a song is off air
    $onAir = function (int $id, float $t) use ($p): bool {
        foreach ($p->timeline($t) as $e) {
            if ($e['type'] === 'song' && $e['track_id'] === $id && $e['start'] <= $t + 2 * (float) $p->setting('cut_lead_seconds') + 1 && $e['end'] > $t - Library::LISTENER_GRACE_SECONDS) {
                return true;
            }
        }
        return false;
    };
    $offAir = function (int $id, float $from) use ($onAir): float { for ($t = $from; $onAir($id, $t); $t += 10) {} return $t; };
    $at = $offAir($gl['id'], $now);
    make_mp3("$root/in/again", 136, ['title' => 'Gloria'], 24);
    $oldFile = $glr['file'];
    // the song's length its coming plays are planned with
    $played = fn(float $at) => array_values(array_map(fn($e) => $e['full'], array_filter($p->timeline($at), fn($e) => $e['track_id'] === $gl['id'] && $e['start'] > $at + 30)));
    $p->replan($at);
    $before = $played($at);
    check('Apply puts the new copy in place of the song’s file', $lib->replace($gl['id'], "$root/in/again", -15.5, null, true, 'Bright', $p, $at) === null);
    $glr2 = $lib->get($gl['id']);
    check('  same song, its new file, loudness and preset', $glr2['sha1'] === $chosen && $glr2['file'] === sha1_file("$root/in/again") . '.mp3' && (float) $glr2['loudness_lufs'] === -15.5
        && $glr2['preset'] === 'Bright' && abs((float) $glr2['duration_s'] - 136) < 1);
    check('  the old copy is gone, the original kept', !is_file("$root/storage/media/$oldFile") && is_file("$root/storage/originals/$chosen.mp3"));
    $after = $played($at);
    check('  and its coming plays use the new length (2 s shorter)', $before && $after && abs(($before[0] - $after[0]) - 2) < 0.1, ($before[0] ?? '-') . ' → ' . ($after[0] ?? '-') . ' s');
    $newPieces = json_decode((string) $db->run('SELECT pieces FROM ' . $db->table('tracks') . ' WHERE id = ?', [$gl['id']])->fetchColumn(), true);
    check('  cut at the new file’s pieces, not the old one’s', $p->pieces(['type' => 'song', 'track_id' => $gl['id'], 'piece_count' => PHP_INT_MAX]) === $newPieces);
    check('  not a file that isn’t an MP3', $lib->replace($gl['id'], "$root/storage/originals/.htaccess", null, null, false, null, $p, $at) !== null);
    $t2 = $offAir($sa['id'], $at);
    check('Apply on a song uploaded as it was keeps its file as the original first', $lib->replace($sa['id'], "$root/in/again", null, null, true, 'Bright', $p, $t2) === null
        && is_file("$root/storage/originals/{$sar['sha1']}.mp3") && !is_file("$root/storage/media/{$sar['file']}")
        && $lib->original($lib->get($sa['id'])) === "$root/storage/originals/{$sar['sha1']}.mp3");
    $playing = $p->nowPlaying($t2)['current'];
    $r = $lib->replace((int) $playing['track_id'], "$root/in/again", null, null, false, null, $p, $t2);
    check('the song on air can’t be prepared again', $r !== null && (str_contains($r, 'on air') || str_contains($r, 'original')), (string) $r);
    $noOrig = array_values(array_filter($lib->tracks(''), fn($x) => $lib->original($lib->get((int) $x['id'])) === null));
    check('  nor one whose original the station hasn’t got', $noOrig === [] || str_contains((string) $lib->replace((int) $noOrig[0]['id'], "$root/in/again", null, null, false, null, $p, $t2), 'original'));
    $lib->delete($sa['id'], $p, $offAir($sa['id'], $t2));
    check('removing a song removes its original', !is_file("$root/storage/originals/{$sar['sha1']}.mp3"));
    $lib->delete($gl['id'], $p, $offAir($gl['id'], $t2));
    check('  (both kinds)', !is_file("$root/storage/originals/$chosen.mp3") && $lib->get($gl['id']) === null);

    echo "The loudness target and each song's normalization (round 41)\n";
    $ss = new StationSettings($db, $station);
    check('the station starts at -16 LUFS', StationSettings::prep($station)[2] === -16.0);
    $station = $ss->savePrep(false, true, -14.3);
    check('a target is kept in half-dB steps', StationSettings::prep($station)[2] === -14.5);
    check('  and in range (-23 to -8 LUFS)', StationSettings::target(-40) === -23.0 && StationSettings::target(0) === -8.0 && StationSettings::target('loud') === -16.0);
    $station = (new StationSettings($db, $station))->savePrep(false, true);
    check('  saving the switches alone keeps it', StationSettings::prep($station)[2] === -14.5);
    $station = (new StationSettings($db, $station))->savePrep(false, true, -16.0);
    $lib = new Library($db, $station, $root);
    make_mp3("$root/in/chosen2", 140, ['title' => 'Kyrie'], 31);
    make_mp3("$root/in/prepared2", 138, ['title' => 'Kyrie'], 32);
    $warmSettings = ['ratio' => [5, 3, 3, 5], 'eqOn' => true];
    $ky = $lib->import("$root/in/prepared2", null, null, 'Kyrie.mp3', sha1_file("$root/in/chosen2"), -14.0, null, true, 'Warm',
        ['source_lufs' => '-9.876', 'gain_db' => '-4.124', 'target' => '-14', 'settings' => $warmSettings]);
    $kr = $db->run('SELECT source_lufs, level_gain_db, level_target, preset_settings FROM ' . $db->table('tracks') . ' WHERE id = ?', [$ky['id']])->fetch();
    check('an upload keeps its loudness before levelling, the gain, the target and its mastering', (float) $kr['source_lufs'] === -9.88 && (float) $kr['level_gain_db'] === -4.12
        && (float) $kr['level_target'] === -14.0 && json_decode($kr['preset_settings'], true) === $warmSettings, json_encode($kr));
    $cols = Library::levelColumns(['source_lufs' => 'x', 'gain_db' => 99, 'target' => -3, 'settings' => ['hack' => 1]], true, true);
    check('  values are kept in range; a mastering setting song prep doesn’t know isn’t kept', $cols['source_lufs'] === null && $cols['level_gain_db'] === 40.0 && $cols['level_target'] === -8.0 && $cols['preset_settings'] === null, json_encode($cols));
    $cols = Library::levelColumns(['source_lufs' => -12, 'gain_db' => -4, 'target' => -16, 'settings' => []], false, false);
    check('  not levelled: no gain or target; not mastered: no settings', $cols['source_lufs'] === -12.0 && $cols['level_gain_db'] === null && $cols['level_target'] === null && $cols['preset_settings'] === null);
    check('  the built-in chain is kept as {}', Library::levelColumns(['settings' => []], true, true)['preset_settings'] === '{}');
    $lib->keepOriginal($ky['id'], "$root/in/chosen2");
    $at = $offAir($ky['id'], $now);
    make_mp3("$root/in/relevelled", 138, ['title' => 'Kyrie'], 33);
    check('re-levelled (Apply): the new values replace the old', $lib->replace($ky['id'], "$root/in/relevelled", -11.0, null, true, 'Warm', $p, $at,
        ['source_lufs' => -9.88, 'gain_db' => -1.12, 'target' => -11, 'settings' => $warmSettings]) === null);
    $kr = $db->run('SELECT loudness_lufs, level_gain_db, level_target, preset_settings FROM ' . $db->table('tracks') . ' WHERE id = ?', [$ky['id']])->fetch();
    check('  its loudness, gain and target, its mastering kept', (float) $kr['loudness_lufs'] === -11.0 && (float) $kr['level_gain_db'] === -1.12 && (float) $kr['level_target'] === -11.0
        && json_decode($kr['preset_settings'], true) === $warmSettings, json_encode($kr));
    $tk = array_values(array_filter($lib->tracks('Kyrie'), fn($x) => (int) $x['id'] === $ky['id']))[0] ?? [];
    check('  and the library lists them', (float) ($tk['level_target'] ?? 0) === -11.0 && array_key_exists('preset_settings', $tk) && array_key_exists('source_lufs', $tk));
    $lib->delete($ky['id'], $p, $offAir($ky['id'], $at));

    echo "Songs made before round 51 (the old encoder)\n";
    // an ID3v2.4 tag holding TXXX frames, as song prep writes them, in front of an MP3's frames
    $tagged = function (array $frames, string $mp3): string {
        $body = '';
        foreach ($frames as $desc => $value) {
            $f = "\0$desc\0$value";
            $body .= 'TXXX' . pack('N', strlen($f)) . "\0\0" . $f; // small enough that a sync-safe size is the same
        }
        $n = strlen($body);
        return 'ID3' . "\4\0\0" . chr(($n >> 21) & 0x7f) . chr(($n >> 14) & 0x7f) . chr(($n >> 7) & 0x7f) . chr($n & 0x7f) . $body . $mp3;
    };
    make_mp3("$root/in/enc-plain", 20, [], 41);
    $plain = (string) file_get_contents("$root/in/enc-plain");
    $kinds = [
        'old' => $tagged(['STATIONWAGON_SOURCE_LUFS' => '-20.00 LUFS', 'STATIONWAGON_LEVEL_TARGET' => '-16.0 LUFS'], $plain),
        'new' => $tagged(['STATIONWAGON_SOURCE_LUFS' => '-20.00 LUFS', 'STATIONWAGON_LEVEL_TARGET' => '-16.0 LUFS', 'STATIONWAGON_PREP' => 'LAME 3.100 q0, 160 kbps CBR, 44100 Hz, 32-bit float, TPDF dither'], $plain),
        'plain' => $plain,
    ];
    $enc = [];
    foreach ($kinds as $k => $bytes) {
        $name = sha1($bytes) . '.mp3';
        file_put_contents("$root/storage/media/$name", $bytes);
        $enc[$k] = $lib->madeWithOldEncoder(['file' => $name]);
    }
    check('a levelled song without the LAME 3.100 tag is old', $enc['old'] === true);
    check('  one made with LAME 3.100 is not', $enc['new'] === false);
    check('  nor is one song prep never touched', $enc['plain'] === false);
    $cache = json_decode((string) file_get_contents("$root/storage/cache/prep-encoder.json"), true);
    check('  each answer is kept, so a tag is read once', is_array($cache) && count($cache) >= 3 && $cache[sha1($kinds['old']) . '.mp3'] === true);
    check('  and the kept answer is what a new library reads', (new Library($db, $station, $root))->madeWithOldEncoder(['file' => sha1($kinds['old']) . '.mp3']) === true);
    foreach ($kinds as $bytes) { @unlink("$root/storage/media/" . sha1($bytes) . '.mp3'); }

    echo "Schema 10\n";
    $pfx = $cfg['db']['prefix'];
    // a song levelled before version 11 (levelled to -16 LUFS, the only target there was), and one not levelled
    make_mp3("$root/in/old-lev", 120, ['title' => 'Old levelled'], 41);
    make_mp3("$root/in/old-plain", 120, ['title' => 'Old plain'], 42);
    $oldLev = $lib->import("$root/in/old-lev", null, null, 'a.mp3', sha1('old levelled'), -16.0)['id'];
    $oldPlain = $lib->import("$root/in/old-plain", null, null, 'b.mp3')['id'];
    $db->pdo->exec("DROP TABLE `{$pfx}prep_presets`");
    $db->pdo->exec("ALTER TABLE `{$pfx}tracks` DROP COLUMN preset");
    $db->run('UPDATE ' . $db->table('settings') . " SET value = '9' WHERE name = 'schema_version'");
    foreach (['source_lufs', 'level_gain_db', 'level_target', 'preset_settings'] as $col) {
        $db->pdo->exec("ALTER TABLE `{$pfx}tracks` DROP COLUMN $col");
    }
    check('a version 9 database upgrades to ' . Schema::VERSION . ' with presets', Schema::upgrade($db, $root) === range(10, Schema::VERSION) && Schema::installed($db) === Schema::VERSION
        && count($pp->all()) === 0 && array_key_exists('preset', $db->run('SELECT * FROM ' . $db->table('tracks') . ' LIMIT 1')->fetch()));
    $tg = fn(int $id) => $db->run('SELECT level_target FROM ' . $db->table('tracks') . ' WHERE id = ?', [$id])->fetchColumn();
    check('  (11) songs levelled before it count as levelled to -16 LUFS; others as not levelled', (float) $tg($oldLev) === -16.0 && $tg($oldPlain) === null);

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
    $at = fn(string $local) => (float) (new DateTimeImmutable($local, new DateTimeZone($station['timezone'])))->getTimestamp();
    $mon = Schedule::today($sched->all(), $at('2027-01-04 07:00'), $station['timezone']);
    check('today’s list: Monday has the morning block, on now at 07:00', count($mon) === 1 && $mon[0]['title'] === 'Mornings' && $mon[0]['on_now']);
    check('  not on at 10:00', !Schedule::today($sched->all(), $at('2027-01-04 10:00'), $station['timezone'])[0]['on_now']);
    $sun = Schedule::today($sched->all(), $at('2027-01-10 23:30'), $station['timezone']);
    check('  Sunday has only the late show, on at 23:30', count($sun) === 1 && $sun[0]['title'] === 'Sunday Service' && $sun[0]['on_now']);
    check('the planner follows the new block', $p->blockAt((float) (new DateTimeImmutable('2027-01-04 07:00', new DateTimeZone($station['timezone'])))->getTimestamp())['id'] == $blk);
    check('a block can be removed', $sched->delete((int) $live) === null && $sched->delete((int) $live) !== null);
    check('deleting a playlist removes its blocks', $lists->delete($pl) === null && $sched->all() === []);

    echo "Sound & safety\n";
    $fresh = fn() => $db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE id = ?', [$station['id']])->fetch();
    $set = new StationSettings($db, $fresh());
    $defaults = ['crossfade_seconds' => 6, 'ads_crossfade_seconds' => 2.0, 'jingles_crossfade_seconds' => 1.0, 'fade_shape' => 'equal_power', 'skip_fade_seconds' => 2.0, 'live_fade_seconds' => 6.0, 'duck_db' => -12,
        'live_timeout_seconds' => 8, 'rejoin_minutes' => 10, 'silence_db' => -40, 'recent_tracks' => 5, 'ratings_influence' => 0, 'prep_master' => 0, 'prep_level' => 1, 'prep_target' => -16.0, 'live_kbps' => 192, 'prep_kbps' => 160,
        'prep_rate' => 44100, 'prep_precision' => 32, 'prep_dither' => 1, 'prep_quality' => 9, 'look' => 'green'];
    check('the defaults are the values the POC proved', $set->values() === $defaults, json_encode($set->values()));
    check('with 6 s pieces a DJ gets at least 8 s', $set->minLiveTimeout() === 8);
    $good = ['crossfade_seconds' => '4', 'ads_crossfade_seconds' => '1.5', 'jingles_crossfade_seconds' => '0.5', 'fade_shape' => 'linear', 'skip_fade_seconds' => '1.5', 'live_fade_seconds' => '3', 'duck_db' => '-18',
        'live_timeout_seconds' => '12', 'rejoin_minutes' => '0', 'silence_db' => '0', 'recent_tracks' => '1', 'ratings_influence' => '1', 'prep_master' => '1', 'prep_level' => '0', 'prep_target' => '-14.5', 'live_kbps' => '128', 'prep_kbps' => '256',
        'prep_rate' => '48000', 'prep_precision' => '24', 'prep_dither' => '0', 'prep_quality' => '7'];
    [, $errs] = $set->save(['crossfade_seconds' => '13', 'ads_crossfade_seconds' => '12.3', 'fade_shape' => 'wobbly', 'skip_fade_seconds' => '0.3', 'live_fade_seconds' => 'soon', 'duck_db' => '3',
        'live_timeout_seconds' => '5', 'rejoin_minutes' => '31', 'silence_db' => '-45', 'ratings_influence' => '2', 'prep_master' => '2', 'prep_level' => 'x', 'prep_target' => '-14.2', 'live_kbps' => '320', 'prep_kbps' => '',
        'prep_rate' => '32000', 'prep_precision' => '8', 'prep_dither' => '2', 'prep_quality' => '10']);
    check('bad values are refused, field by field', array_keys($errs) === array_keys($good), implode(',', array_keys($errs)));
    check('  and say what is allowed', str_contains($errs['skip_fade_seconds'], '0 to 6 s in steps of 0.5') && str_contains($errs['live_timeout_seconds'], '8 to 30')
        && str_contains($errs['duck_db'], '-24 to 0 dB') && $errs['silence_db'] === 'Move on when a song goes quiet: choose one of the options.', $errs['duck_db']);
    check('  and nothing is stored', (new StationSettings($db, $fresh()))->values() === $defaults);
    [$updated, $errs] = $set->save($good);
    check('good values are saved, choices as their own type', !$errs && (new StationSettings($db, $fresh()))->values() === ['crossfade_seconds' => 4, 'ads_crossfade_seconds' => 1.5, 'jingles_crossfade_seconds' => 0.5, 'fade_shape' => 'linear', 'skip_fade_seconds' => 1.5,
        'live_fade_seconds' => 3.0, 'duck_db' => -18, 'live_timeout_seconds' => 12, 'rejoin_minutes' => 0, 'silence_db' => 0, 'recent_tracks' => 1, 'ratings_influence' => 1, 'prep_master' => 1, 'prep_level' => 0, 'prep_target' => -14.5, 'live_kbps' => 128, 'prep_kbps' => 256,
        'prep_rate' => 48000, 'prep_precision' => 24, 'prep_dither' => 0, 'prep_quality' => 7, 'look' => 'green'], json_encode($errs));
    check('  a form without the station’s look (an older page) leaves it as it is (round 83)', (new StationSettings($db, $fresh()))->values()['look'] === 'green');
    [, $errs] = $set->save(['look' => 'purple'] + $good);
    check('the station’s look must be one of the looks', ($errs['look'] ?? '') === 'The station’s look: choose one of the options.', json_encode($errs));
    [$updated, $errs] = (new StationSettings($db, $fresh()))->save(['look' => 'ocean'] + $good);
    check('  Ocean is saved', !$errs && (new StationSettings($db, $fresh()))->values()['look'] === 'ocean' && \Stationwagon\Planner::settings($fresh())['look'] === 'ocean');
    (new StationSettings($db, $fresh()))->save(['look' => 'green'] + $good);
    check('the quality choices show megabytes an hour', StationSettings::mbPerHour(192) === 86 && StationSettings::mbPerHour(128) === 58);
    $kept = Planner::settings($fresh());
    check('other settings are kept', (int) $kept['plan_ahead_seconds'] === 10800 && (float) $kept['segment_seconds'] === 6.0);
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
    check('a new station levels songs to -16 LUFS but leaves mastering off', StationSettings::prep(['settings' => '{}']) === [false, true, -16.0]);

    echo "Silence detection\n";
    $tail = array_merge(array_fill(0, 40, -14.0), array_fill(0, 20, -99.0));   // 20 silent seconds at the end
    check('song prep’s last minute is kept, rounded and in range', Library::outro([-14.04, -120, 3, 7]) === '[-14,-99,0,0]');
    check('  anything else is dropped', Library::outro(null) === null && Library::outro([]) === null && Library::outro(['loud']) === null && Library::outro(array_fill(0, 121, -20)) === null);
    $song = ['duration' => 200.0, 'outro' => Library::outro($tail)];
    check('a song with 20 silent seconds goes quiet 20 s from its end', Planner::quietFrom($song, -40) === 180.0);
    check('  not when silence detection is off', Planner::quietFrom($song, 0) === null);
    $fade = array_merge(array_fill(0, 50, -14.0), [-30, -33, -36, -38, -42, -45, -48, -52, -60, -99]);
    check('a fade-out counts from where it drops below the level', Planner::quietFrom(['duration' => 200.0, 'outro' => json_encode($fade)], -40) === 194.0
        && Planner::quietFrom(['duration' => 200.0, 'outro' => json_encode($fade)], -30) === 191.0);
    check('a quiet part of 2 s or less is left alone', Planner::quietFrom(['duration' => 200.0, 'outro' => json_encode(array_merge(array_fill(0, 58, -14.0), [-99, -99]))], -40) === null);
    check('a song is never cut shorter than 30 s', Planner::quietFrom(['duration' => 40.0, 'outro' => json_encode(array_merge(array_fill(0, 5, -14.0), array_fill(0, 35, -99.0)))], -40) === null);
    check('no outro, no change', Planner::quietFrom(['duration' => 200.0, 'outro' => null], -40) === null);
    $tracksT = $db->table('tracks');
    $db->run("UPDATE $tracksT SET outro = ? WHERE station_id = ?", [Library::outro($tail), $station['id']]);
    $durations = array_column($db->run("SELECT id, duration_s FROM $tracksT WHERE station_id = ? AND status = 'ready'", [$station['id']])->fetchAll(), 'duration_s', 'id');
    [$quietOn] = $set->save(['silence_db' => '-40'] + $good);
    $p3 = new Planner($db, $quietOn, $root);
    $at3 = $now + 3600;
    $p3->replan($at3);
    $songs = array_values(array_filter($p3->timeline($at3), fn($e) => $e['start'] > $at3 + 2 && $e['type'] === 'song'));
    $early = array_filter($songs, fn($e) => $e['play'] <= $durations[$e['track_id']] - 20 + 1e-6 && $e['play'] > $durations[$e['track_id']] - 26);
    check('the planner moves on at the last piece before the silence', $songs && count($early) === count($songs),
        count($early) . ' of ' . count($songs));
    [$quietOff] = $set->save(['silence_db' => '0'] + $good);
    $p4 = new Planner($db, $quietOff, $root);
    $p4->replan($at3);
    $songs = array_values(array_filter($p4->timeline($at3), fn($e) => $e['start'] > $at3 + 2 && $e['type'] === 'song'));
    check('  and plays to the end again when it is off', $songs && !array_filter($songs, fn($e) => $e['play'] < $durations[$e['track_id']] - 4 - 6 - 1e-6));
    $db->run("UPDATE $tracksT SET outro = NULL WHERE station_id = ?", [$station['id']]);

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
    check('a DJ can manage nobody', $staff->grantable($dj) === [] && !$staff->canManage($dj, $mgr));
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

    echo "Roles\n";
    $boss = $lastAdmin;
    $roles = new Roles($db, $station);
    check('Admin, Manager and DJ come built in, with their usual ticks', array_column($roles->all(), 'key') === ['admin', 'manager', 'dj']
        && $roles->get('manager')['permissions'] === array_keys(Permissions::ALL) && $roles->get('dj')['permissions'] === []);
    [$pk, $pr] = $roles->add($boss, 'Scheduler', ['schedule', 'people', 'nonsense']);
    check('an admin adds a role with some ticks (unknown ones dropped)', $pk === 'scheduler' && $pr === null && $roles->get('scheduler')['permissions'] === ['schedule', 'people']);
    check('  a name already used is refused, whatever its case', $roles->add($boss, 'SCHEDULER', [])[1] !== null && $roles->add($boss, '  ', [])[1] !== null);
    $mia = $staff2->get($mgrId);
    check('  only admins add, change or delete roles', $roles->add($mia, 'Mine', [])[1] !== null && $roles->update($mia, 'dj', 'DJ', ['library']) !== null && $roles->delete($mia, 'scheduler') !== null);
    [$pmId] = $staff2->add($boss, 'Pat Partial', 'pat@example.test', 'scheduler', 'pat password 1');
    $pm = $staff2->get($pmId);
    check('a person gets exactly their role’s ticks', Permissions::of($pm) === ['schedule', 'people'] && Auth::allows($pm, 'schedule') && !Auth::allows($pm, 'library') && $pm['role_name'] === 'Scheduler');
    check('  and counts as staff, like a DJ', Auth::allows($pm, 'dj') && !Auth::allows($pm, 'manager'));
    check('with People, only roles that can do no more than you can are yours to give', $staff2->grantable($pm) === ['scheduler', 'dj'], json_encode($staff2->grantable($pm)));
    [$djId2] = $staff2->add($pm, 'Sam DJ', 'sam@example.test', 'dj', 'sam password 1');
    check('  so Pat adds a DJ', $djId2 !== null);
    check('  but not a manager', isset($staff2->add($pm, 'Max', 'max@example.test', 'manager', 'max password 1')[1]['role']));
    [$fullId] = $staff2->add($boss, 'Full Manager', 'full@example.test', 'manager', 'full password 1');
    check('  and can’t change a manager', !$staff2->canManage($pm, $staff2->get($fullId)) && $staff2->setRole($pm, $fullId, 'dj') !== null);
    check('a DJ manages nobody', $staff2->grantable($staff2->get($djId2)) === [] && !$staff2->canManage($staff2->get($djId2), $pm));
    check('changing a role’s ticks applies to its people at once', $roles->update($boss, 'dj', 'DJ', ['anytime', 'podcast']) === null && Permissions::of($staff2->get($djId2)) === ['anytime', 'podcast']);
    check('  Pat can then no longer give the DJ role (it can do more than Pat)', !in_array('dj', $staff2->grantable($pm), true) && !$staff2->canManage($pm, $staff2->get($djId2)));
    check('a role can be renamed', $roles->update($boss, 'dj', 'Presenter', ['anytime']) === null && $staff2->get($djId2)['role_name'] === 'Presenter' && $roles->get('dj')['permissions'] === ['anytime']);
    check('the Admin role can’t be changed; built-in roles can’t be deleted', $roles->update($boss, 'admin', 'Boss', []) !== null && $roles->delete($boss, 'manager') !== null && $roles->delete($boss, 'dj') !== null);
    check('a role that people have can’t be deleted', $roles->delete($boss, 'scheduler') !== null);
    check('a person’s role is changed, and with it what they may do', $staff2->setRole($boss, $pmId, 'manager') === null && Permissions::of($staff2->get($pmId)) === array_keys(Permissions::ALL));
    check('  then the empty role can be deleted', $roles->delete($boss, 'scheduler') === null && $roles->get('scheduler') === null);
    $roles->update($boss, 'dj', 'DJ', []);
    check('name and email can be changed', $staff2->edit($boss, $djId2, 'Samuel DJ', 'Samuel@Example.test') === [] && $staff2->get($djId2)['email'] === 'samuel@example.test');
    check('  a taken email is refused', isset($staff2->edit($boss, $djId2, 'Samuel DJ', 'pat@example.test')['email']));
    check('  nobody edits themselves here', $staff2->edit($boss, (int) $boss['id'], 'X', 'x@example.test') !== []);

    echo "Schema 13: each person's own ticks move to a role\n";
    $u = $db->table('users');
    $db->pdo->exec("ALTER TABLE $u ADD COLUMN `permissions` TEXT NULL");
    $mk = function (string $name, string $role, ?string $perms) use ($db, $station): int {
        $id = $db->insert('users', ['email' => strtolower(str_replace(' ', '.', $name)) . '@old.test', 'name' => $name, 'password_hash' => 'x', 'role' => $role, 'created_at' => gmdate('Y-m-d H:i:s'), 'permissions' => $perms]);
        $db->insert('station_users', ['station_id' => $station['id'], 'user_id' => $id]);
        return $id;
    };
    $o1 = $mk('Old Onair', 'dj', '["onair","schedule"]');
    $o2 = $mk('Old Twin', 'manager', '["schedule","onair"]');
    $o3 = $mk('Old Full', 'manager', json_encode(['onair', 'library', 'playlists', 'schedule', 'podcast', 'listeners', 'sound', 'people', 'download'])); // (the download tick came in schema 19: an older list would not have it)
    $o4 = $mk('Old Plain', 'manager', null);
    $db->run('UPDATE ' . $db->table('settings') . " SET value = '12' WHERE name = 'schema_version'");
    check('the upgrade runs', Schema::upgrade($db) === range(13, Schema::VERSION));
    $role = fn(int $id) => (string) $db->run("SELECT role FROM $u WHERE id = ?", [$id])->fetchColumn();
    $custom = $roles->get($role($o1));
    check('  "On air" becomes Go live any time and Run the on-air desk, in a new role', $custom !== null && str_starts_with($custom['name'], 'Custom role') && $custom['permissions'] === ['anytime', 'desk', 'schedule'], json_encode($custom));
    check('  the same ticks share that role', $role($o2) === $role($o1));
    check('  a manager with every tick is simply a Manager; one without own ticks stays one', $role($o3) === 'manager' && $role($o4) === 'manager');
    check('  the per-person column is gone', !$db->run("SHOW COLUMNS FROM $u LIKE 'permissions'")->fetch());
    check('  and a second run changes nothing', Schema::upgrade($db) === []);
    foreach ([$o1, $o2, $o3, $o4] as $id) { $db->run("DELETE FROM $u WHERE id = ?", [$id]); }
    $roles->delete($boss, $custom['key']);

    echo "Blocking\n";
    $_SESSION = [];
    $auth2 = new Auth($db, "$root/storage/cache/throttle");
    check('Samuel signs in', $auth2->attempt('samuel@example.test', 'sam password 1', '192.0.2.30', true)['ok'] && $auth2->user() !== null);
    $tokens = fn() => (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('remember_tokens') . ' WHERE user_id = ?', [$djId2])->fetchColumn();
    check('  with "Keep me signed in"', $tokens() === 1);
    $at = 1800010000.0;
    $p->replan($at);
    $live = $p->goLive($at, 'Samuel live', $djId2)['result'] ?? [];
    check('  and goes live', isset($live['live']['public_id']), json_encode($live));
    check('a DJ can’t block', $staff2->block($staff2->get($djId2), $fullId, $p, $at + 30) !== null);
    check('blocking works', $staff2->block($boss, $djId2, $p, $at + 30) === null && $staff2->get($djId2)['blocked_at'] !== null);
    $show = $db->run('SELECT `open`, closed_reason FROM ' . $db->table('live_shows') . ' WHERE public_id = ?', [$live['live']['public_id']])->fetch();
    check('  their live show ends at once', (int) $show['open'] === 0 && $show['closed_reason'] === 'DJ blocked', json_encode($show));
    $after = array_values(array_filter($p->timeline($at + 60), fn($e) => $e['start'] > $at + 40));
    check('  and the AutoDJ takes over', $after && $after[0]['type'] === 'song');
    check('  their "Keep me signed in" devices are forgotten', $tokens() === 0);
    $auth3 = new Auth($db, "$root/storage/cache/throttle");
    check('  their open session is signed out on the next request', $auth3->user() === null && !isset($_SESSION['user_id']));
    $_SESSION = [];
    $try = $auth3->attempt('samuel@example.test', 'sam password 1', '192.0.2.31');
    check('  and they can’t sign in', !$try['ok'] && str_contains((string) ($try['error'] ?? ''), 'blocked'), json_encode($try));
    check('  a blocked person may do nothing', !Auth::allows($staff2->get($djId2), 'dj') && !Auth::allows($staff2->get($djId2), 'anytime'));
    check('blocking twice keeps the first time', $staff2->block($boss, $djId2, $p, $at + 90) === null);
    check('unblocking lets them sign in again', $staff2->unblock($boss, $djId2) === null && (new Auth($db, "$root/storage/cache/throttle"))->attempt('samuel@example.test', 'sam password 1', '192.0.2.32')['ok']);
    check('nobody blocks themselves', $staff2->block($boss, (int) $boss['id'], $p) !== null);
    check('the last admin who can sign in can’t be blocked', $staff2->block(['id' => 0, 'role' => 'admin'], (int) $boss['id'], $p) !== null);
    [$adm3] = $staff2->add($boss, 'Third Admin', 'adm3@example.test', 'admin', 'adm3 password 1');
    check('  but one of two admins can', $staff2->block($boss, $adm3, $p) === null);
    check('  and then the other is the last one', $staff2->block(['id' => 0, 'role' => 'admin'], (int) $boss['id'], $p) !== null && $staff2->remove(['id' => 0, 'role' => 'admin'], (int) $boss['id']) !== null);
    check('the WordPress page’s actor (an admin with no account here) may manage anyone', $staff2->canManage(['id' => 0, 'role' => 'admin'], $boss));

    echo "Album art and copyright (round 45)\n";
    $png = (function () { $im = imagecreatetruecolor(1200, 900); imagefill($im, 0, 0, imagecolorallocate($im, 200, 60, 40)); ob_start(); imagepng($im); return (string) ob_get_clean(); })();
    make_mp3("$root/in/tagged", 120, ['title' => 'Tagged Song', 'artist' => 'The Band', 'copyright' => '© 2019 Alruin Music', 'publisher' => 'Grace Publishing',
        'writers' => 'A. Smith; B. Jones', 'year' => '2019', 'art' => $png], 31);
    $tg = Stationwagon\Mp3::tags("$root/in/tagged");
    check('the reader finds the cover and the copyright tags', ($tg['art']['mime'] ?? '') === 'image/png' && $tg['art']['data'] === $png && $tg['copyright'] === '© 2019 Alruin Music' && $tg['publisher'] === 'Grace Publishing' && $tg['writers'] === 'A. Smith; B. Jones' && $tg['year'] === '2019');
    $tid = $lib->import("$root/in/tagged", null, null, 'tagged.mp3')['id'];
    $tr = $lib->get($tid);
    check('an upload keeps its own album art (made 800 px at most)', $tr['art'] !== null && is_file("$root/storage/art/{$tr['art']}") && getimagesize("$root/storage/art/{$tr['art']}")[0] === 800);
    $rt = Stationwagon\Rights::decode($tr['rights']);
    check('  and its copyright tags: owner without the ©, year, publisher, writers', $rt !== null && $rt['owner'] === 'Alruin Music' && $rt['year'] === '2019' && $rt['publisher'] === 'Grace Publishing' && $rt['writers'] === 'A. Smith; B. Jones', json_encode($rt));
    check('  the © badge’s line', Stationwagon\Rights::line($rt) === '© 2019 Alruin Music · Written by A. Smith; B. Jones');
    check('a song without tags has neither', $lib->get((int) reset($ids))['art'] === null);
    $seen = null;
    foreach ($p->entries($now - 100000) as $e) {
        if ($e['type'] === 'song' && $e['track_id'] === $tid) { $seen = $e; break; }
    }
    check('the plan tells listeners the art and the copyright details', $seen === null || (str_starts_with((string) $seen['art'], 'storage/art/art-') && ($seen['rights']['line'] ?? '') !== ''));
    check('the album can be changed with title and artist', $lib->update($tid, 'Tagged Song', 'The Band', 'The Album') === null && $lib->get($tid)['album'] === 'The Album');
    check('  and is left alone when not sent', $lib->update($tid, 'Tagged Song', 'The Band') === null && $lib->get($tid)['album'] === 'The Album');
    $jpg = (function () { $im = imagecreatetruecolor(300, 300); ob_start(); imagejpeg($im); return (string) ob_get_clean(); })();
    $old = $lib->get($tid)['art'];
    check('album art can be replaced by a JPEG', $lib->setArt($tid, $jpg) === null && $lib->get($tid)['art'] !== $old && str_ends_with((string) $lib->get($tid)['art'], '.jpg'));
    check('  and the old picture’s file is removed', !is_file("$root/storage/art/$old"));
    $art = $lib->get($tid)['art'];
    check('a text file is not album art, nor is a 4 MB one', $lib->setArt($tid, 'hello, I am not a picture') !== null && $lib->setArt($tid, str_repeat('x', 4 * 1024 * 1024)) !== null);
    check('  and the song keeps what it had', $lib->get($tid)['art'] === $art && is_file("$root/storage/art/$art"));
    $dirty = '<p style="font-family:Comic Sans;font-size:40px">Watch <b>this</b>:</p><script>alert(1)</script><div>https://youtu.be/abc</div>'
        . '<a href="https://example.com/artist" onclick="x()" style="color:red">the artist</a> <a href="javascript:alert(1)">bad</a> <a href="mailto:a@b.co">mail</a>'
        . '<font face="Arial" size="7">big</font><br><br><br><br><br>end';
    $clean = Stationwagon\Rights::cleanHtml($dirty);
    check('rich text keeps only text, line breaks and http, https or mailto links', !preg_match('/<(?!\/?(a|br)\b)/i', $clean) && !str_contains($clean, 'script') && !str_contains($clean, 'alert') && !str_contains($clean, 'style=') && !str_contains($clean, 'onclick')
        && str_contains($clean, '<a href="https://example.com/artist" target="_blank" rel="noopener noreferrer">the artist</a>') && str_contains($clean, '<a href="mailto:a@b.co"') && !str_contains($clean, 'javascript:') && str_contains($clean, 'bad'), $clean);
    check('  fonts and sizes go but their words stay; runs of line breaks are cut to one blank line', str_contains($clean, 'Watch this:') && str_contains($clean, 'big') && !str_contains($clean, '<br><br><br>') && str_ends_with($clean, 'end'), $clean);
    check('  nothing set is nothing', Stationwagon\Rights::cleanHtml('  <p> </p> ') === '' && Stationwagon\Rights::normalize(['owner' => ' ', 'notes' => '<b></b>']) === null);
    check('copyright details are saved with the notes cleaned', $lib->setRights($tid, ['owner' => '  Alruin   Music ', 'year' => '2024', 'licence' => 'CC BY 4.0', 'publisher' => 'P', 'writers' => 'W', 'notes' => $dirty]) === null
        && ($r2 = Stationwagon\Rights::decode($lib->get($tid)['rights'])) !== null && $r2['owner'] === 'Alruin Music' && $r2['notes'] === $clean && $r2['licence'] === 'CC BY 4.0');
    check('  the line puts the owner, year, writers and licence together', Stationwagon\Rights::line($r2) === '© 2024 Alruin Music · Written by W · CC BY 4.0');
    check('  a song with only a publisher or a licence still has a line', Stationwagon\Rights::line(Stationwagon\Rights::normalize(['publisher' => 'Grace'])) === '© Grace' && Stationwagon\Rights::line(Stationwagon\Rights::normalize(['licence' => 'CC0'])) === 'CC0');
    check('  clearing them takes the © away', $lib->setRights($tid, []) === null && $lib->get($tid)['rights'] === null);
    check('a song that isn’t here is refused', $lib->setRights(999999, ['owner' => 'x']) !== null && $lib->setArt(999999, $jpg) !== null);

    echo "The recording, the composition and the licence (round 56)\n";
    $full = ['owner' => 'Grace Music', 'year' => '2024', 'licence' => 'CCLI Streaming Licence', 'publisher' => 'Grace Publishing', 'writers' => 'A. Smith',
        'track' => '3/12', 'label' => 'Grace Records', 'isrc' => 'za-abc-24-00001', 'pline' => 'Grace Records', 'lyricists' => 'B. Jones', 'iswc' => 'T0345246801',
        'licence_ref' => 'CCLI 7123456', 'licence_url' => 'https://ccli.com/licence/1', 'licence_until' => '2027-12-31', 'terms' => "Clause 1.\r\n\r\n\r\n\r\nClause 2.\t \n"];
    check('every new field is saved', $lib->setRights($tid, $full) === null && ($r3 = Stationwagon\Rights::decode($lib->get($tid)['rights'])) !== null
        && $r3['track'] === '3/12' && $r3['label'] === 'Grace Records' && $r3['pline'] === 'Grace Records' && $r3['lyricists'] === 'B. Jones'
        && $r3['licence_ref'] === 'CCLI 7123456' && $r3['licence_url'] === 'https://ccli.com/licence/1' && $r3['licence_until'] === '2027-12-31', json_encode($r3 ?? null));
    check('  the codes in their usual written form', $r3['isrc'] === 'ZA-ABC-24-00001' && $r3['iswc'] === 'T-034.524.680-1', $r3['isrc'] . ' ' . $r3['iswc']);
    check('  the terms as plain text, line breaks kept (at most one blank line)', $r3['terms'] === "Clause 1.\n\nClause 2.", json_encode($r3['terms']));
    check('  listeners get all of it (the user: both public)', ($fl = Stationwagon\Rights::forListeners($lib->get($tid)['rights'])) !== null && $fl['terms'] === $r3['terms'] && $fl['licence_url'] === $r3['licence_url'] && $fl['isrc'] === $r3['isrc']);
    $bad = ['isrc' => 'ZA123' , 'iswc' => 'T12', 'licence_url' => 'javascript:alert(1)', 'licence_until' => '2027-02-30'];
    $said = [];
    foreach ($bad as $k => $v) { $said[$k] = $lib->setRights($tid, [$k => $v]); }
    check('a wrong ISRC, ISWC, link or date is refused with a reason, and nothing changes', count(array_filter($said, fn($v) => is_string($v) && $v !== '')) === 4
        && Stationwagon\Rights::decode($lib->get($tid)['rights'])['isrc'] === 'ZA-ABC-24-00001', json_encode($said));
    check('  a mail link is no licence link', $lib->setRights($tid, ['licence_url' => 'mailto:a@b.test']) !== null);
    check('  terms past 20,000 characters are cut', mb_strlen((string) Stationwagon\Rights::normalize(['terms' => str_repeat('é', 25000)])['terms']) === 20000);
    make_mp3("$root/in/tagged2", 60, ['title' => 'Tagged Two', 'isrc' => 'ZAABC2400002', 'lyricists' => 'C. Lyric', 'track' => '7', 'writers' => 'D. Writer'], 32);
    $t2 = $lib->get($lib->import("$root/in/tagged2", null, null, 'tagged2.mp3')['id']);
    $r4 = Stationwagon\Rights::decode($t2['rights']);
    check('an upload reads its ISRC, lyricists and track number from its tag', ($r4['isrc'] ?? '') === 'ZA-ABC-24-00002' && ($r4['lyricists'] ?? '') === 'C. Lyric' && ($r4['track'] ?? '') === '7', json_encode($r4));
    $lib->setRights($tid, []);
    $db->pdo->exec('ALTER TABLE ' . $db->table('tracks') . ' DROP COLUMN art, DROP COLUMN rights');
    $db->run('UPDATE ' . $db->table('settings') . " SET value = '13' WHERE name = 'schema_version'");
    check('the upgrade to 14 adds the columns', Schema::upgrade($db, $root) === range(14, Schema::VERSION) && $db->run('SHOW COLUMNS FROM ' . $db->table('tracks') . " LIKE 'rights'")->fetch() !== false);
    check('  and reads the songs already in the library for their art and copyright', $lib->get($tid)['art'] !== null && Stationwagon\Rights::decode($lib->get($tid)['rights'])['owner'] === 'Alruin Music');
    $art = $lib->get($tid)['art'];
    check('removing a song removes its art file too', $lib->delete($tid, $p, $now) === null && !is_file("$root/storage/art/$art"));

    echo "Star ratings (round 62)\n";
    make_mp3("$root/in/rate1", 30, ['title' => 'Rate One'], 41);
    make_mp3("$root/in/rate2", 30, ['title' => 'Rate Two'], 42);
    $s1 = $lib->import("$root/in/rate1", null, null, 'rate1.mp3')['id'];
    $s2 = $lib->import("$root/in/rate2", null, null, 'rate2.mp3')['id'];
    $starsOf = fn(int $id) => $lib->get($id)['rating'];
    check('a new song has no rating (it counts as 3 until it is rated)', $starsOf($s1) === null && Stationwagon\Library::row($lib->get($s1))['rating'] === null);
    $ok = true;
    foreach ([1, 2, 3, 4, 5] as $n) {
        $ok = $ok && $lib->setRating($s1, $n) === null && (int) $starsOf($s1) === $n && Stationwagon\Library::row($lib->get($s1))['rating'] === $n;
    }
    check('a song can be rated 1 to 5 stars', $ok);
    check('  0, 6 and −1 stars are refused, and the rating stays', $lib->setRating($s1, 0) !== null && $lib->setRating($s1, 6) !== null && $lib->setRating($s1, -1) !== null && (int) $starsOf($s1) === 5);
    check('  a song that isn’t here is refused', $lib->setRating(999999, 3) !== null);
    check('  another song is not touched', $starsOf($s2) === null);
    check('  the library list carries each song’s rating', (function () use ($lib, $s1, $s2) {
        $by = array_column($lib->tracks(), null, 'id');
        return (int) $by[$s1]['rating'] === 5 && $by[$s2]['rating'] === null;
    })());
    check('  null takes the rating off', $lib->setRating($s1, null) === null && $starsOf($s1) === null);
    $lib->setRating($s2, 2);
    $db->pdo->exec('ALTER TABLE ' . $db->table('tracks') . ' DROP COLUMN rating');
    $db->run('UPDATE ' . $db->table('settings') . " SET value = '15' WHERE name = 'schema_version'");
    check('the upgrade to 16 adds the rating column (songs start unrated)', Schema::upgrade($db, $root) === range(16, Schema::VERSION)
        && $db->run('SHOW COLUMNS FROM ' . $db->table('tracks') . " LIKE 'rating'")->fetch() !== false && $starsOf($s2) === null);
    check('  and a second upgrade has nothing to do', Schema::upgrade($db, $root) === []);
    $lib->delete($s1, $p, $now);
    $lib->delete($s2, $p, $now);

    echo "Songs on and off (round 84)\n";
    $tt = $db->table('tracks');
    $idsOf = fn(array $rows) => array_map('intval', array_column($rows, 'id'));
    $all = $idsOf($lib->tracks());
    check('songs start on', count($all) >= 2 && $lib->onCount() === count($all) && $idsOf($lib->tracks('', true)) === $all);
    $cur = $p->nowPlaying($now)['current'];
    $onAir = (int) $cur['track_id'];
    $refused = (string) $lib->delete($onAir, $p, $now);
    check('Remove refused on air says to turn the song off', str_contains($refused, 'Turn it off'), $refused);
    check('turning it off counts only real songs', $lib->setActive([$onAir, 0, 'x', 99999999], false, $p, $now) === 1 && (int) $lib->get($onAir)['active'] === 0);
    check('  the songs on are one fewer; the studio’s search leaves it out', $lib->onCount() === count($all) - 1 && !in_array($onAir, $idsOf($lib->tracks('', true)), true)
        && !in_array($onAir, array_map(fn($s) => $s['track_id'], (new Studio($p))->songs('')), true));
    check('  the library list still shows it, as off', in_array($onAir, $idsOf($lib->tracks()), true)
        && (int) array_column($lib->tracks(), null, 'id')[$onAir]['active'] === 0);
    $lead = (float) $p->setting('cut_lead_seconds');
    $planned = array_filter($p->timeline($now), fn($e) => $e['type'] === 'song' && $e['start'] > $now + $lead + 1);
    check('  the AutoDJ plans it no more (the play on air goes on)', $planned !== [] && !in_array($onAir, array_column($planned, 'track_id'), true)
        && (int) $p->nowPlaying($now)['current']['track_id'] === $onAir);
    $refused = (string) $lib->delete($onAir, $p, $now);
    check('  Remove still waits while it is on air, and says it is off', str_contains($refused, 'It’s off now'), $refused);
    $after = (float) $cur['end'] + Library::LISTENER_GRACE_SECONDS + 1;
    $gone = $lib->delete($onAir, $p, $after);
    check('  once it has played (and the listeners’ grace), it can be removed', $gone === null, (string) $gone);
    $rest = array_values(array_diff($all, [$onAir]));
    $known = $db->run("SELECT sha1, file FROM $tt WHERE id = ?", [$rest[0]])->fetch();
    $sums = array_values(array_unique([$known['sha1'], basename($known['file'], '.mp3')]));
    $found = $lib->haveSongs([...array_map('strtoupper', $sums), sha1('not here'), 'junk', $known['sha1']]);
    sort($found); sort($sums);
    check('the upload check finds songs already here by their hash, and nothing else', $found === $sums && $lib->haveSongs([]) === [], json_encode($found));
    $songsAt = fn(float $t) => array_filter($p->timeline($t), fn($e) => $e['type'] === 'song' && $e['start'] > $t + $lead + 1);
    check('every song may be off', $lib->setActive($rest, false, $p, $after) === count($rest) && $lib->onCount() === 0);
    $t3 = $after + 3600;
    check('  then the AutoDJ has nothing to play (the station is quiet)', $songsAt($t3) === []);
    check('  and on again, they play again', $lib->setActive($rest, true, $p, $t3) === count($rest) && $lib->onCount() === count($rest) && $songsAt($t3) !== []);
    check('nothing chosen turns nothing', $lib->setActive([], false, $p, $t3) === 0 && $lib->onCount() === count($rest));
    $lib->setActive([$rest[0]], false, $p, $t3);
    $db->pdo->exec("ALTER TABLE $tt DROP COLUMN active");
    $db->run('UPDATE ' . $db->table('settings') . " SET value = '20' WHERE name = 'schema_version'");
    check('the upgrade to 21 adds the on/off column, with every song on', Schema::upgrade($db, $root) === range(21, Schema::VERSION) && $lib->onCount() === count($rest));
    check('  and a second upgrade has nothing to do', Schema::upgrade($db, $root) === []);

    echo "Song prep on the server (round 85)\n";
    NodeRuntime::$root = $root;
    $node = NodeRuntime::find();
    check('this computer has a Node to test with (version ' . NodeRuntime::MIN_MAJOR . ' or newer)', $node !== null, $node ? $node[0] . ' ' . $node[1] : 'none found');
    $node = $node[0] ?? 'node';
    $pq = new PrepQueue($db, $station, $root);
    [$uid, $uname] = array_values($db->run('SELECT id, name FROM ' . $db->table('users') . " WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch());
    $uid = (int) $uid;
    $jt = $db->table('prep_jobs');
    check('the upgrade to 22 makes the queue', (function () use ($db, $jt) {
        $db->pdo->exec("DROP TABLE $jt");
        $db->run('UPDATE ' . $db->table('settings') . " SET value = '21' WHERE name = 'schema_version'");
        return Schema::upgrade($db) === [22] && $db->run("SHOW TABLES LIKE 'sw\\_prep\\_jobs'")->fetch() !== false && Schema::upgrade($db) === [];
    })());
    $set = $pq->settingsNow();
    [$m0, $l0, $tgt] = StationSettings::prep($station);
    check('a job keeps the station’s prep settings as they are now', $set['level'] === $l0 && $set['target'] === $tgt && ($set['master'] !== false) === $m0
        && $set['kbps'] === StationSettings::prepQuality($station)['kbps'] && $set['preset'] === (new PrepPresets($db, $station))->forImport()[0], json_encode($set));
    $wav = NodeRuntime::toneWav(6.0, 330);
    file_put_contents("$root/in/tone.wav", $wav);
    $sum = sha1($wav);
    check('refused: not audio by its name, empty, too big, no fingerprint',
        $pq->begin($uid, 'song', 'notes.txt', 10, $sum)['state'] === 'refused' && $pq->begin($uid, 'song', 'a.wav', 0, $sum)['state'] === 'refused'
        && $pq->begin($uid, 'song', 'a.wav', PrepQueue::MAX_BYTES + 1, $sum)['state'] === 'refused' && $pq->begin($uid, 'song', 'a.wav', 10, 'abc')['state'] === 'refused');
    $b = $pq->begin($uid, 'song', 'Tone 330.wav', strlen($wav), strtoupper($sum));
    $jid = $b['id'] ?? 0;
    check('a new upload starts at byte 0, in 8 MB pieces', $b['state'] === 'uploading' && $b['received'] === 0 && $b['chunk'] === PrepQueue::CHUNK && is_file("$root/storage/incoming/$jid.part"));
    check('  storage/incoming is never served', str_contains((string) @file_get_contents("$root/storage/incoming/.htaccess"), 'Require all denied'));
    $third = intdiv(strlen($wav), 3);
    $c = $pq->chunk($jid, 5, substr($wav, 5, 100));
    check('a piece at the wrong place isn’t kept; the answer says where to go on', !$c['ok'] && $c['received'] === 0 && $c['error'] === 'out of step');
    $c = $pq->chunk($jid, 0, substr($wav, 0, $third));
    check('a piece at the right place is kept', $c['ok'] && $c['received'] === $third);
    check('  one that would run past the end is refused', !$pq->chunk($jid, $third, str_repeat('x', strlen($wav)))['ok']);
    $again = $pq->begin($uid, 'song', 'Tone 330.wav', strlen($wav), $sum);
    check('picking the same file again carries on where it stopped', $again['state'] === 'uploading' && $again['id'] === $jid && $again['received'] === $third);
    check('  finishing early says so', !$pq->finish($jid)['ok'] && $pq->finish($jid)['received'] === $third);
    $pq->chunk($jid, $third, substr($wav, $third, $third));
    $pq->chunk($jid, 2 * $third, str_repeat("\0", strlen($wav) - 2 * $third)); // the wrong bytes at the end
    $f = $pq->finish($jid);
    check('a file that didn’t arrive whole (its fingerprint differs) is sent again from the start', !$f['ok'] && $f['received'] === 0 && filesize("$root/storage/incoming/$jid.part") === 0);
    for ($at = 0; $at < strlen($wav); $at += 100000) {
        $pq->chunk($jid, $at, substr($wav, $at, 100000));
    }
    check('whole: it waits in the queue', $pq->finish($jid)['ok'] && $pq->get($jid)['state'] === 'queued' && is_file("$root/storage/incoming/$jid.src") && !is_file("$root/storage/incoming/$jid.part"));
    check('  the same file again: already being prepared', $pq->begin($uid, 'song', 'copy.wav', strlen($wav), $sum)['state'] === 'queued');
    $listed = $pq->list('song');
    check('the list shows it with who sent it', count($listed) === 1 && $listed[0]['state'] === 'queued' && $listed[0]['who'] === $uname && $listed[0]['name'] === 'Tone 330.wav', json_encode($listed));
    check('  and says it waits for Node while the station has none set up', $listed[0]['waiting_for_node'] === true);

    // a real MP3 (with sound) for the details window's path: the tone as LAME makes it
    @mkdir("$root/in/mp3");
    file_put_contents("$root/in/t2.wav", NodeRuntime::toneWav(5.0, 550));
    file_put_contents("$root/in/mp3/job.json", json_encode(['in' => "$root/in/t2.wav", 'name' => 't2.wav', 'out' => "$root/in/mp3",
        'opts' => ['kbps' => 128, 'master' => false, 'level' => false, 'quality' => ['rate' => 44100, 'precision' => 32, 'dither' => true, 'q' => 5]]]));
    NodeRuntime::run([$node, SW_ROOT . '/cli/prep-node.mjs', "$root/in/mp3/job.json"], 120);
    $mp3 = (string) @file_get_contents("$root/in/mp3/original.out");
    check('(a test MP3 made with Node)', strlen($mp3) > 10000);
    $b2 = $pq->begin($uid, 'song', 'Tone 550.mp3', strlen($mp3), sha1($mp3), true);
    $pq->chunk($b2['id'], 0, $mp3);
    $pq->finish($b2['id']);
    $bad = "This is a text file, not music.\n" . str_repeat('x', 5000);
    $b3 = $pq->begin($uid, 'song', 'not music.mp3', strlen($bad), sha1($bad));
    $pq->chunk($b3['id'], 0, $bad);
    $pq->finish($b3['id']);
    $ad = NodeRuntime::toneWav(4.0, 660);
    $b4 = $pq->begin($uid, 'ad', 'Advert.wav', strlen($ad), sha1($ad));
    $pq->chunk($b4['id'], 0, $ad);
    $pq->finish($b4['id']);
    $b5 = $pq->begin($uid, 'song', 'Cancel me.wav', strlen($ad) + 1, sha1($ad . 'x'));
    $pq->chunk($b5['id'], 0, $ad . 'x');
    $pq->finish($b5['id']);
    check('Cancel takes a waiting upload out, files and all', $pq->cancel($b5['id']) && $pq->get($b5['id'])['state'] === 'cancelled' && !is_file("$root/storage/incoming/{$b5['id']}.src"));

    echo "  the worker\n";
    $lock = fopen(PrepQueue::lockPath($root), 'c');
    flock($lock, LOCK_EX);
    check('one worker at a time: a second does nothing', PrepQueue::working($root) && PrepQueue::work($db, $root, $node) === []);
    flock($lock, LOCK_UN);
    fclose($lock);
    $tw = microtime(true);
    $lines = PrepQueue::work($db, $root, $node);
    printf("  (%d uploads in %.1f s)\n", count($lines), microtime(true) - $tw);
    $j1 = $pq->get($jid);
    $r1 = json_decode((string) $j1['result'], true);
    $tr = $j1['track_id'] ? $lib->get((int) $j1['track_id']) : null;
    check('the WAV is prepared and added', $j1['state'] === 'done' && $tr !== null && $tr['kind'] === 'song' && $tr['title'] === 'Tone 330', implode(' | ', $lines));
    check('  converted to MP3, levelled to the station’s target, with the details the list shows', ($r1['info']['converted']['kind'] ?? '') === 'WAV'
        && abs((float) $tr['loudness_lufs'] - $tgt) < 0.6 && isset($r1['title'], $r1['info']['quality']['encoder']), json_encode([$tr['loudness_lufs'] ?? null, $r1['info']['converted'] ?? null]));
    check('  mastered as the station says, the preset named', (int) $tr['mastered'] === ($m0 ? 1 : 0) && (!$m0 || $tr['preset'] === $set['preset']));
    check('  its 320 kbps original is kept for Song prep', $lib->original($tr) !== null && is_file((string) $lib->original($tr)));
    check('  the raw upload and Node’s files are gone', !is_file("$root/storage/incoming/$jid.src") && !is_dir("$root/storage/incoming/$jid"));
    check('  it is in the plan', in_array((int) $tr['id'], array_map('intval', array_column($p->timeline(microtime(true)), 'track_id')), true) || count($lib->tracks()) > 4);
    check('the same WAV picked again: already there', $pq->begin($uid, 'song', 'Tone 330.wav', strlen($wav), $sum)['state'] === 'have');
    $j2 = $pq->get($b2['id']);
    check('a song whose details window is open is prepared, then waits for its details', $j2['state'] === 'prepared' && (int) $j2['hold'] === 1 && $j2['track_id'] === null);
    $r = $pq->details($b2['id'], ['title' => 'Five Fifty', 'artist' => 'The Tones', 'album' => 'Test', 'rights' => [], 'art' => 'keep'], null, $p);
    $tr2 = $r['ok'] ? $lib->get((int) $r['track']) : null;
    check('  its details arrive: added with them', $r['ok'] && $tr2 !== null && $tr2['title'] === 'Five Fifty' && $tr2['artist'] === 'The Tones' && $pq->get($b2['id'])['state'] === 'done', json_encode($r));
    check('  an MP3 keeps its own file as the original', $lib->original($tr2) !== null);
    $j3 = $pq->get($b3['id']);
    check('a file that isn’t audio is not added, with the reason', $j3['state'] === 'failed' && str_contains((string) $j3['error'], 'not an MP3 file this station can play') && $j3['track_id'] === null, (string) $j3['error']);
    check('  Try again queues it once more', $pq->retry($b3['id']) && $pq->get($b3['id'])['state'] === 'queued');
    $j4 = $pq->get($b4['id']);
    check('an ad goes to the Ads screen', $j4['state'] === 'done' && $lib->get((int) $j4['track_id'])['kind'] === 'ad'
        && (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('ads') . ' WHERE track_id = ?', [$j4['track_id']])->fetchColumn() === 1);
    PrepQueue::work($db, $root, $node);
    check('  failed again, it can be removed', $pq->get($b3['id'])['state'] === 'failed' && $pq->remove($b3['id']) && $pq->get($b3['id']) === null && !is_file("$root/storage/incoming/{$b3['id']}.src"));
    check('details after it was added are put on the song', $pq->details($b2['id'], ['title' => 'Five Fifty (edit)', 'artist' => '', 'album' => '', 'rights' => [], 'art' => 'keep'], null, $p)['ok']
        && $lib->get((int) $pq->get($b2['id'])['track_id'])['title'] === 'Five Fifty (edit)');
    check('details without a title are refused', PrepQueue::checkDetails(['title' => ' ']) !== null);

    echo "  a worker that died\n";
    $stuck = NodeRuntime::toneWav(4.0, 770); // its own sound: a copy of another upload's sound would be that song again
    $b6 = $pq->begin($uid, 'song', 'Stuck.wav', strlen($stuck), sha1($stuck));
    $pq->chunk($b6['id'], 0, $stuck);
    $pq->finish($b6['id']);
    $db->run("UPDATE $jt SET state = 'working', attempts = 1, updated_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', time() - PrepQueue::STALE - 60), $b6['id']]);
    check('a job left "working" is due', PrepQueue::due($db));
    PrepQueue::work($db, $root, $node);
    check('  it is prepared again', $pq->get($b6['id'])['state'] === 'done', json_encode($pq->get($b6['id'])));
    $b7 = $pq->begin($uid, 'song', 'Stuck twice.wav', strlen($ad) + 3, sha1($ad . 'zzz'));
    $pq->chunk($b7['id'], 0, $ad . 'zzz');
    $pq->finish($b7['id']);
    $db->run("UPDATE $jt SET state = 'working', attempts = ? WHERE id = ?", [PrepQueue::ATTEMPTS, $b7['id']]);
    PrepQueue::work($db, $root, $node);
    check('  after its tries it fails, with the reason', $pq->get($b7['id'])['state'] === 'failed' && str_contains((string) $pq->get($b7['id'])['error'], 'stopped twice'));

    echo "  tidying up\n";
    $b8 = $pq->begin($uid, 'song', 'Half.wav', 999999, sha1('half'));
    $pq->chunk($b8['id'], 0, 'part of it');
    $db->run("UPDATE $jt SET updated_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', time() - PrepQueue::KEEP_PARTIAL - 60), $b8['id']]);
    file_put_contents("$root/storage/incoming/99999.src", 'left over');
    touch("$root/storage/incoming/99999.src", time() - 7200);
    $pq->cleanup(time());
    check('a transfer stopped 2 days ago is dropped, with its piece', $pq->get($b8['id']) === null && !is_file("$root/storage/incoming/{$b8['id']}.part"));
    check('  a file no job knows is deleted', !is_file("$root/storage/incoming/99999.src"));
    check('  finished jobs stay a day, so other devices see them finish', $pq->get($jid) !== null);
    $db->run("UPDATE $jt SET updated_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', time() - PrepQueue::KEEP_DONE - 60), $jid]);
    $pq->cleanup(time());
    check('  then go', $pq->get($jid) === null && $lib->get((int) $tr['id']) !== null);
    check('nothing is left pending', $pq->pending() === 0, (string) $pq->pending());

    echo "  Node (NodeRuntime)\n";
    check('the station finds this computer’s Node', NodeRuntime::find() !== null);
    check('nothing is set up yet: songs are prepared in the browser', NodeRuntime::ready() === null && NodeRuntime::summary()['server'] === false
        && NodeRuntime::summary()['text'] === 'In the browser');
    $s = NodeRuntime::setUp();
    check('Set up uses the server’s own Node and tests it on a tone', !empty($s['ok']) && $s['source'] === 'server' && $s['test_s'] > 0 && NodeRuntime::ready() === $s['path'], json_encode($s));
    check('  the station now prepares on the server', NodeRuntime::summary()['server'] && str_contains(NodeRuntime::summary()['detail'], 'Node ' . $s['version']));
    check('  the queue says nothing waits for Node now', $pq->list() === [] || !array_filter($pq->list(), fn($j) => $j['waiting_for_node']));
    NodeRuntime::save(['busy' => 'Downloading']);
    check('while Set up works it says what it is doing', NodeRuntime::busy() === 'Downloading' && NodeRuntime::summary()['text'] === 'Setting up');
    NodeRuntime::save(['busy' => 'Downloading', 'busy_at' => 1.0]);
    check('  a Set up that stopped part-way doesn’t stay busy', NodeRuntime::busy() === 'Downloading'); // save() stamps it now
    $raw = json_decode((string) file_get_contents(NodeRuntime::statePath()), true);
    $raw['busy_at'] = microtime(true) - 1000;
    file_put_contents(NodeRuntime::statePath(), json_encode($raw));
    check('  (after 15 minutes it is over)', NodeRuntime::busy() === null);
    NodeRuntime::save($s);
    NodeRuntime::remove();
    check('Prepare in the browser undoes it', NodeRuntime::ready() === null && !is_file(NodeRuntime::statePath()));
    // fetching: a tarball laid out as nodejs.org's, from a local file; the checksum decides
    $name = 'node-v' . NodeRuntime::VERSION . '-linux-x64';
    @mkdir("$root/in/tar/$name/bin", 0775, true);
    file_put_contents("$root/in/tar/$name/bin/node", "#!/bin/sh\necho not really node\n");
    file_put_contents("$root/in/tar/$name/README.md", 'only bin/node is kept');
    $tarFile = "$root/in/$name.tar";
    @unlink($tarFile);
    $ph = new PharData($tarFile);
    $ph->buildFromDirectory("$root/in/tar");
    $ph->compress(Phar::GZ);
    unset($ph);
    $gz = "$tarFile.gz";
    $err = '';
    try { NodeRuntime::fetch($gz, ['x64' => str_repeat('0', 64)]); } catch (RuntimeException $ex) { $err = $ex->getMessage(); }
    check('a download that doesn’t match its checksum isn’t used', str_contains($err, 'checksum') && !is_file(NodeRuntime::runtimeDir() . "/$name.tar.gz") && !is_file(NodeRuntime::runtimeDir() . '/node/bin/node'), $err);
    $err = '';
    try { NodeRuntime::fetch($gz, ['x64' => hash_file('sha256', $gz)]); } catch (RuntimeException $ex) { $err = $ex->getMessage(); }
    check('one that matches is unpacked into storage/runtime, only bin/node kept', is_file(NodeRuntime::runtimeDir() . '/node/bin/node') && !is_file(NodeRuntime::runtimeDir() . '/node/README.md')
        && !is_file(NodeRuntime::runtimeDir() . "/$name.tar.gz") && str_contains((string) @file_get_contents(NodeRuntime::runtimeDir() . '/.htaccess'), 'denied'));
    check('  a program the server won’t run is said plainly', str_contains($err, 'won’t run it'), $err);
    if (PHP_OS_FAMILY !== 'Linux') {
        $err = '';
        try { NodeRuntime::fetch(); } catch (RuntimeException $ex) { $err = $ex->getMessage(); }
        check('Set up fetches Node only for Linux servers, and says so elsewhere', NodeRuntime::arch() === null && str_contains($err, 'only for Linux'), $err);
    } else {
        check('this Linux server has a Node build to fetch', NodeRuntime::arch() !== null, php_uname('m'));
    }
    NodeRuntime::$root = null;

    echo "Diagnostics reports\n";
    $reports = new DiagReports("$root/storage/diag");
    $now = 1_800_000_000.0;
    $id = $reports->save("Phone: test\r\nAll good", '192.0.2.20', $now);
    $kept = (string) file_get_contents("$root/storage/diag/$id.txt");
    check('a report is kept with when it came in', str_starts_with($kept, 'Received: ') && str_contains($kept, "Phone: test\nAll good"));
    $refused = 0;
    foreach (['', str_repeat('x', DiagReports::MAX_BYTES + 1), "bad \xC3("] as $bad) {
        try { $reports->save($bad, '192.0.2.20', $now); } catch (InvalidArgumentException) { $refused++; }
    }
    check('  empty, too big or broken text is refused', $refused === 3);
    for ($i = 1; $i < DiagReports::PER_HOUR; $i++) { $reports->save("report $i", '192.0.2.20', $now + $i); }
    try { $reports->save('one too many', '192.0.2.20', $now + 30); $over = false; } catch (OverflowException) { $over = true; }
    check('  20 an hour from one address, then no more', $over && count($reports->ids()) === DiagReports::PER_HOUR);
    check('  another address may still send', $reports->save('other', '192.0.2.21', $now + 31) !== '');
    check('  and an hour later the first may again', $reports->save('later', '192.0.2.20', $now + 3700) !== '');
    for ($i = 0; $i < DiagReports::KEEP + 5; $i++) { $reports->save("fill $i", "198.51.100." . intdiv($i, 20), $now + 4000 + $i); }
    check('  only the newest 200 are kept', count(glob("$root/storage/diag/*.txt")) === DiagReports::KEEP, (string) count(glob("$root/storage/diag/*.txt")));

    echo "Downloads (round 71)\n";
    $name = fn(string $title, ?string $artist = null, bool $orig = false) => Library::downloadName(['id' => 7, 'title' => $title, 'artist' => $artist], $orig);
    check('a download is named “Artist - Title.mp3”, or just the title', $name('Hello', 'Me') === 'Me - Hello.mp3' && $name('Hello') === 'Hello.mp3' && $name('Hello', '') === 'Hello.mp3');
    check('  the original says so', $name('Hello', 'Me', true) === 'Me - Hello (original).mp3');
    check('  nothing a file system dislikes is kept (slashes, colons, quotes, stars, control characters)', $name("A/B: \"C\" *?<>|\\ D\t\n", 'X') === 'X - A B C D.mp3', $name("A/B: \"C\" *?<>|\\ D\t\n", 'X'));
    check('  dots and spaces at the ends go, accents stay, a long name is cut', $name('  ..Café..  ') === 'Café.mp3' && mb_strlen($name(str_repeat('é', 400))) <= 150 + 4);
    check('  a clip with no title or artist is still named', $name('', '') === 'clip-7.mp3' && $name('', 'Only Artist') === 'Only Artist.mp3');
    check('the Download tick is one of the permissions, and the built-in Manager has it, the DJ does not', isset(Permissions::ALL['download']) && in_array('download', Permissions::defaults('manager'), true) && Permissions::defaults('dj') === []);
    check('  an admin always may; a person may only by their role', Auth::allows(['role' => 'admin'], 'download') && !Auth::allows(['role' => 'dj', 'role_permissions' => []], 'download') && Auth::allows(['role' => 'dj', 'role_permissions' => ['download']], 'download'));
    check('  upgrading from 18 gives the built-in Manager role the tick, and leaves a custom role as its admin chose', (function () use ($db, $station) {
        $rt = $db->table('roles');
        $db->run("UPDATE $rt SET permissions = ? WHERE `key` = 'manager' AND station_id = ?", [json_encode(['library', 'sound']), $station['id']]);
        $db->insert('roles', ['station_id' => $station['id'], 'key' => 'custom-dl', 'name' => 'Custom dl', 'permissions' => json_encode(['library']), 'built_in' => 0, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $db->run('UPDATE ' . $db->table('settings') . " SET value = '18' WHERE name = 'schema_version'");
        $ran = Schema::upgrade($db);
        $mgr = json_decode((string) $db->run("SELECT permissions FROM $rt WHERE `key` = 'manager' AND station_id = ?", [$station['id']])->fetchColumn(), true);
        $cus = json_decode((string) $db->run("SELECT permissions FROM $rt WHERE `key` = 'custom-dl' AND station_id = ?", [$station['id']])->fetchColumn(), true);
        $dj = json_decode((string) $db->run("SELECT permissions FROM $rt WHERE `key` = 'dj' AND station_id = ?", [$station['id']])->fetchColumn(), true);
        return $ran === range(19, Schema::VERSION) && $mgr === ['library', 'download', 'sound'] && $cus === ['library'] && !in_array('download', $dj ?: [], true) && Schema::upgrade($db) === [];
    })());
} finally {
    drop_test_database($t);
    remove_tree($root);
}
finish();
