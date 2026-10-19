<?php
// Command-line test of the library and the MySQL timeline planner (the POC engine test, ported,
// plus playlists, the schedule, locking, history and the HLS playlist). Makes its own silent
// test MP3s and installs into a throwaway database, so it runs the same on the server.
//   php app/tests/planner_test.php      (same SW_TEST_DB_* settings as install_test.php)
declare(strict_types=1);
require __DIR__ . '/lib.php';
use Stationwagon\{Db, Library, Planner, Stream};

$t = test_database('planner');
$root = temp_root('planner');
$near = fn(float $a, float $b, float $tol = 1e-3) => abs($a - $b) <= $tol;
try {
    $cfg = test_install($t, $root);
    $db = Db::connect($cfg['db']);
    $station = $db->run('SELECT * FROM ' . $db->table('stations'))->fetch();

    echo "Library\n";
    mkdir("$root/in");
    $lengths = ['Alpha' => 150, 'Bravo' => 190, 'Charlie' => 230, 'Delta' => 170];
    $i = 0;
    foreach ($lengths as $name => $secs) {
        make_mp3("$root/in/$name.mp3", $secs, $name === 'Bravo' ? ['title' => 'Bravo Song', 'artist' => 'The Testers'] : [], $i++);
    }
    file_put_contents("$root/in/notes.mp3", str_repeat('not audio ', 2000));
    $lib = new Library($db, $station, $root);
    $ids = [];
    foreach (array_keys($lengths) as $name) {
        $ids[$name] = $lib->import("$root/in/$name.mp3")['id'];
    }
    check('4 songs added', count($lib->tracks()) === 4);
    $again = $lib->import("$root/in/Alpha.mp3");
    check('the same file twice is one song', $again['duplicate'] && $again['id'] === $ids['Alpha']);
    $bad = null;
    try { $lib->import("$root/in/notes.mp3"); } catch (RuntimeException $ex) { $bad = $ex->getMessage(); }
    check('a file that is not MP3 is refused', $bad !== null && str_contains($bad, 'not an MP3'), (string) $bad);
    $row = $db->run('SELECT * FROM ' . $db->table('tracks') . ' WHERE id = ?', [$ids['Bravo']])->fetch();
    check('title and artist read from the ID3 tag', $row['title'] === 'Bravo Song' && $row['artist'] === 'The Testers');
    check('title from the file name when there is no tag', $db->run('SELECT title FROM ' . $db->table('tracks') . ' WHERE id = ?', [$ids['Alpha']])->fetchColumn() === 'Alpha');
    check('stored under its content hash', $row['file'] === sha1_file("$root/in/Bravo.mp3") . '.mp3' && is_file("$root/storage/media/{$row['file']}"));
    $pieces = json_decode($row['pieces'], true);
    $sizeOk = true;
    foreach (array_slice($pieces, 0, -1) as $p) { $sizeOk = $sizeOk && $p[2] >= 6.0 && $p[2] < 6.1; }
    check('pieces are 6.0–6.1 s (the last may be shorter)', $sizeOk && $near(array_sum(array_column($pieces, 2)), (float) $row['duration_s'], 0.01), count($pieces) . ' pieces');
    $fh = fopen("$root/storage/media/{$row['file']}", 'rb');
    $starts = true;
    foreach ($pieces as $p) { fseek($fh, $p[0]); $h = fread($fh, 2); $starts = $starts && ord($h[0]) === 0xFF && (ord($h[1]) & 0xE0) === 0xE0; }
    fclose($fh);
    check('every piece starts on an MP3 frame', $starts);

    echo "Planning\n";
    $p = new Planner($db, $station, $root);
    $t0 = 1800000000.0; // a fixed "now" keeps the test repeatable
    $entries = $p->timeline($t0);
    $ahead = (float) $p->setting('plan_ahead_seconds');
    check('planned 3 hours ahead', end($entries)['end'] >= $t0 + $ahead, count($entries) . ' entries');
    $joined = true; $seqOk = true; $nOk = true; $twice = false; $tailOk = true; $spread = true;
    $xf = (float) $p->setting('crossfade_seconds');
    foreach ($entries as $k => $e) {
        $tailOk = $tailOk && $e['full'] - $e['play'] >= $xf - 1e-6 && $e['full'] - $e['play'] <= $xf + 6.1;
        if ($k === 0) { continue; }
        $prev = $entries[$k - 1];
        $joined = $joined && $near($e['start'], $prev['end']);
        $seqOk = $seqOk && $e['seq'] === $prev['seq'] + $prev['piece_count'];
        $nOk = $nOk && $e['n'] === $prev['n'] + 1;
        $twice = $twice || $e['track_id'] === $prev['track_id'];
        if ($k >= 3) { $spread = $spread && count(array_unique(array_column(array_slice($entries, $k - 3, 4), 'track_id'))) === 4; }
    }
    check('entries are back to back', $joined);
    check('HLS numbering is continuous', $seqOk && $nOk);
    check('no song plays twice in a row', !$twice);
    check('with 4 songs, any 4 in a row are all different', $spread);
    check('every song leaves a full crossfade, and at most one piece more', $tailOk);
    $rows = fn() => (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('timeline'))->fetchColumn();
    $before = $rows();
    $p->timeline($t0 + 60);
    check('reading again doesn’t plan again', $rows() === $before);
    $p->timeline($t0 + $ahead / 2 + 300);
    check('with less than half the plan left it plans more', $rows() > $before);
    $np = $p->nowPlaying($t0 + 10);
    check('now playing and next', $np['current'] && $np['next'] && $np['current']['start'] <= $t0 + 10 && $np['next']['start'] > $t0 + 10);

    echo "HLS playlist\n";
    $parse = function (string $body): array {
        preg_match_all('/^#EXTINF:([\d.]+),/m', $body, $inf);
        preg_match('/#EXT-X-MEDIA-SEQUENCE:(\d+)/', $body, $ms);
        preg_match_all('/^#EXT-X-BYTERANGE:(\d+)@(\d+)\n(.+)$/m', $body, $br);
        preg_match_all('/^#EXT-X-PROGRAM-DATE-TIME:(\S+)$/m', $body, $pdt);
        return ['pieces' => count($inf[1]), 'msn' => (int) ($ms[1] ?? -1), 'ranges' => $br[3], 'pdt' => $pdt[1], 'disc' => substr_count($body, "#EXT-X-DISCONTINUITY\n")];
    };
    $a = Stream::playlist($p, $t0 + 100);
    $b = Stream::playlist($p, $t0 + 106.5);
    $pa = $parse($a['body']); $pb = $parse($b['body']);
    check('playlist answers 200 with 5 pieces', $a['status'] === 200 && $pa['pieces'] === 5, $a['status'] . ', ' . $pa['pieces']);
    check('the window slides forward', $pb['msn'] > $pa['msn']);
    check('songs are byte ranges of the stored file', count($pa['ranges']) === 5 && str_starts_with($pa['ranges'][0], '../storage/media/') && str_ends_with($pa['ranges'][0], '.mp3'));
    check('each piece has a millisecond date-time', count($pa['pdt']) === 5 && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $pa['pdt'][0]) === 1);
    $second = $p->timeline($t0)[1];
    $c = $parse(Stream::playlist($p, $second['start'] + 3)['body']);
    check('a song change is marked as a discontinuity', $c['disc'] === 1);

    echo "Skip\n";
    $now = $t0 + 30;
    $playing = null;
    foreach ($p->timeline($now) as $e) { if ($e['start'] <= $now && $now < $e['end']) { $playing = $e; } }
    $r = $p->skip($now);
    $cut = $r['result']['cut_at'];
    check('skip cuts 1–7 s ahead', $cut !== null && $cut >= $now + 1 && $cut <= $now + 7.1, sprintf('+%.2f s', $cut - $now));
    $cutEntry = null; $next = null;
    foreach ($r['entries'] as $e) {
        if ($e['cut']) { $cutEntry = $e; }
        if ($near($e['start'], $cut)) { $next = $e; }
    }
    check('the song is cut with the skip fade', $cutEntry && $cutEntry['n'] === $playing['n'] && $near($cutEntry['end'], $cut) && $cutEntry['fade_out'] == $p->setting('skip_fade_seconds'));
    check('the next song starts exactly at the cut', $next !== null);
    check('numbering still continuous after skip', $next && $next['seq'] === $cutEntry['seq'] + $cutEntry['piece_count'] && $next['n'] === $cutEntry['n'] + 1);
    check('the cut song doesn’t come straight back', $next && $next['track_id'] !== $cutEntry['track_id']);
    check('planned 3 hours ahead again', end($r['entries'])['end'] >= $now + $ahead);

    echo "Live\n";
    $now = $t0 + 60;
    $r = $p->goLive($now, 'Test DJ');
    $live = $r['result']['live'] ?? null;
    check('go live opens a live block', $live && $live['open'] && $live['start'] >= $now + 1, $live ? sprintf('starts +%.2f s', $live['start'] - $now) : json_encode($r['result']));
    check('no AutoDJ songs planned during the show', end($r['entries'])['type'] === 'live');
    check('the song before hands over with the full crossfade', $r['result']['handover']['fade_out'] == $xf && $r['result']['handover']['cut']);
    $again = $p->goLive($now + 1, 'Second DJ')['result'];
    check('a second go-live is refused while on air', isset($again['error']));
    $mp3 = file_get_contents("$root/in/Alpha.mp3", false, null, 0, 417 * 230);
    $p0 = $p->addPiece($live['start'] + 7, $live['public_id'], 0, 6.0, $mp3)['result'];
    check('piece 0 accepted', !empty($p0['ok']) && empty($p0['duplicate']));
    $dup = $p->addPiece($live['start'] + 8, $live['public_id'], 0, 6.0, $mp3)['result'];
    check('a repeated piece is ignored', !empty($dup['duplicate']));
    $gap = $p->addPiece($live['start'] + 9, $live['public_id'], 2, 6.0, $mp3)['result'];
    check('a piece out of order is refused with the expected number', ($gap['status'] ?? 0) === 409 && $gap['expected'] === 1);
    $p1 = $p->addPiece($live['start'] + 13, $live['public_id'], 1, 6.0, $mp3)['result'];
    check('piece 1 accepted', !empty($p1['ok']) && $near($p1['stream_end'], $live['start'] + 12));
    check('pieces saved in storage/live', is_file("$root/storage/live/{$live['public_id']}/00001.mp3"));
    $segs = $p->segments($live['start'] + 13);
    $lastSeg = end($segs);
    check('the playlist ends with the live pieces', $lastSeg['msn'] === $live['seq'] + 1 && $lastSeg['dsn'] === $live['n'] && str_starts_with($lastSeg['uri'], 'storage/live/'));
    check('on air shows the live show', ($p->nowPlaying($live['start'] + 13)['current']['type'] ?? '') === 'live');
    $end = $p->endLive($live['start'] + 14, $live['public_id']);
    check('end show closes the block', !empty($end['result']['ended']) && $near($end['result']['stream_end'], $live['start'] + 12));
    $resume = null;
    foreach ($end['entries'] as $e) { if ($e['type'] === 'song' && $near($e['start'], $live['start'] + 12)) { $resume = $e; } }
    check('AutoDJ resumes right after the live block', $resume !== null && $resume['seq'] === $live['seq'] + 2);
    $late = $p->addPiece($live['start'] + 15, $live['public_id'], 2, 6.0, $mp3)['result'];
    check('a piece after the show ended is refused', ($late['status'] ?? 0) === 410);

    echo "Live timeout\n";
    $now = $t0 + 400;
    $live2 = $p->goLive($now, 'Dropped DJ')['result']['live'];
    $p->addPiece($live2['start'] + 7, $live2['public_id'], 0, 6.0, $mp3);
    $later = $live2['start'] + 7 + $p->setting('live_timeout_seconds') + 1;
    $tl = $p->timeline($later);
    $closed = null;
    foreach ($tl as $e) { if (($e['public_id'] ?? '') === $live2['public_id']) { $closed = $e; } }
    check('a silent live block closes itself', $closed && !$closed['open'] && $closed['closed_reason'] === 'timeout');
    check('AutoDJ planned again after the timeout', end($tl)['type'] === 'song');
    $late = $p->addPiece($live2['start'] + 30, $live2['public_id'], 1, 6.0, $mp3)['result'];
    check('a late piece after the timeout is refused', ($late['status'] ?? 0) === 410);

    echo "Skip from a lagging listener\n";
    $now = $t0 + 1000;
    $tl = $p->timeline($now);
    $heardEntry = null; $edgeEntry = null; $edge = null;
    foreach ($tl as $k => $e) {
        if ($k > 0 && $e['start'] > $now + 20) { $heardEntry = $tl[$k - 1]; $edgeEntry = $e; $edge = $e['start'] + 2; break; }
    }
    $r = $p->skip($edge, max($heardEntry['start'] + 0.5, $edge - 18.6));
    check('skip from a listener whose song is ending does nothing', $r['result']['cut_at'] === null && !empty($r['result']['reason']));
    $still = null;
    foreach ($r['entries'] as $e) { if ($e['n'] === $edgeEntry['n']) { $still = $e; } }
    check('  and the song at the live edge is left alone', $still && !$still['cut']);
    check('skip from a listener hearing the current song cuts it', $p->skip($edge + 10, $edge + 8)['result']['cut_at'] !== null);

    echo "Playlists and schedule\n";
    $now = $t0 + 2000;
    $pl = $db->insert('playlists', ['station_id' => $station['id'], 'name' => 'Morning in order', 'mode' => 'in_order', 'created_at' => gmdate('Y-m-d H:i:s')]);
    $db->insert('playlist_tracks', ['playlist_id' => $pl, 'track_id' => $ids['Charlie'], 'position' => 0]);
    $db->insert('playlist_tracks', ['playlist_id' => $pl, 'track_id' => $ids['Alpha'], 'position' => 1]);
    $db->insert('schedule', ['station_id' => $station['id'], 'kind' => 'playlist', 'title' => 'All day', 'playlist_id' => $pl,
                             'days' => 127, 'start_time' => '00:00:00', 'end_time' => '00:00:00', 'created_at' => gmdate('Y-m-d H:i:s')]);
    $r = $p->replan($now);
    check('replan drops what wasn’t on air yet', $r['result']['replanned'] > 0, $r['result']['replanned'] . ' entries');
    $future = array_values(array_filter($r['entries'], fn($e) => $e['start'] > $now + 1));
    $order = array_column(array_slice($future, 0, 6), 'track_id');
    $alternates = count($order) === 6;
    for ($k = 1; $k < count($order); $k++) { $alternates = $alternates && $order[$k] !== $order[$k - 1] && in_array($order[$k], [$ids['Charlie'], $ids['Alpha']], true); }
    check('an in-order playlist plays its songs in turn', $alternates, implode(',', $order));
    $db->run('UPDATE ' . $db->table('playlists') . " SET mode = 'shuffle' WHERE id = ?", [$pl]);
    $db->run('INSERT INTO ' . $db->table('playlist_tracks') . ' (playlist_id, track_id, position) VALUES (?, ?, 2)', [$pl, $ids['Delta']]);
    $future = array_values(array_filter($p->replan($now)['entries'], fn($e) => $e['start'] > $now + 1));
    $used = array_unique(array_column($future, 'track_id'));
    check('a shuffled playlist only uses its own songs', !in_array($ids['Bravo'], $used, true) && count($used) === 3, count($future) . ' songs');
    $db->run('UPDATE ' . $db->table('playlists') . ' SET active = 0 WHERE id = ?', [$pl]);
    $future = array_values(array_filter($p->replan($now)['entries'], fn($e) => $e['start'] > $now + 1));
    check('a switched-off playlist falls back to the whole library', in_array($ids['Bravo'], array_column($future, 'track_id'), true));

    // Overnight Monday 22:00–02:00, station clock (Africa/Johannesburg)
    $db->run('DELETE FROM ' . $db->table('schedule'));
    $db->run('UPDATE ' . $db->table('playlists') . ' SET active = 1 WHERE id = ?', [$pl]);
    $db->insert('schedule', ['station_id' => $station['id'], 'kind' => 'playlist', 'title' => 'Late Monday', 'playlist_id' => $pl,
                             'days' => 1, 'start_time' => '22:00:00', 'end_time' => '02:00:00', 'created_at' => gmdate('Y-m-d H:i:s')]);
    $at = fn(string $local) => (float) (new DateTimeImmutable($local, new DateTimeZone('Africa/Johannesburg')))->getTimestamp();
    $fresh = new Planner($db, $station, $root);
    check('Monday 23:00 is in the overnight block', $fresh->blockAt($at('2027-01-04 23:00')) !== null);
    check('Tuesday 01:30 is still in it (it began Monday)', $fresh->blockAt($at('2027-01-05 01:30')) !== null);
    check('Tuesday 23:00 is not', $fresh->blockAt($at('2027-01-05 23:00')) === null);
    check('Monday 21:59 is not', $fresh->blockAt($at('2027-01-04 21:59')) === null);
    $db->run('UPDATE ' . $db->table('schedule') . " SET valid_to = '2026-12-31'");
    check('a block past its end date is ignored', (new Planner($db, $station, $root))->blockAt($at('2027-01-04 23:00')) === null);
    $db->run('DELETE FROM ' . $db->table('schedule'));

    echo "Locking\n";
    $other = new PDO("mysql:host={$t['server']['host']};dbname={$t['name']};charset=utf8mb4", $t['server']['user'], $t['server']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $busy = new Planner($db, $station, $root, 1);
    $st = $other->prepare('SELECT GET_LOCK(?, 0)');
    $st->execute([$busy->lockName()]);
    check('another request can hold the planning lock', (int) $st->fetchColumn() === 1);
    $err = null;
    try { $busy->skip($t0 + 2100); } catch (RuntimeException $ex) { $err = $ex->getMessage(); }
    check('then a change waits, and gives up politely', $err !== null && str_contains($err, 'busy'), (string) $err);
    $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$busy->lockName()]);
    check('once it’s free, changes work again', $busy->skip($t0 + 2100)['result']['cut_at'] !== null);
    check('the lock name is per database and station', $busy->lockName() !== (new Planner($db, ['id' => 99] + $station, $root))->lockName());

    echo "History\n";
    $maxN = (int) $db->run('SELECT MAX(n) FROM ' . $db->table('timeline'))->fetchColumn();
    $twoDays = $t0 + 2 * 86400;
    $tl = $p->timeline($twoDays);
    $old = (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('timeline') . ' WHERE start_at + play_s < ?', [$twoDays - 86400])->fetchColumn();
    check('entries older than a day are cleared', $old <= 1, "$old left");
    $firstNew = null;
    foreach ($tl as $e) { if ($e['start'] >= $twoDays - 1) { $firstNew = $e; break; } }
    check('after a quiet spell the stream starts again at "now"', $firstNew && $near($firstNew['start'], $twoDays) && end($tl)['end'] >= $twoDays + $ahead);
    check('without filling the gap with unheard songs', count($tl) < 80, count($tl) . ' entries');
    check('numbering carries on after the gap', $firstNew && $firstNew['n'] === $maxN + 1, ($firstNew['n'] ?? '?') . " after $maxN");

    echo "Reset and an empty station\n";
    $tl = $p->reset($t0)['entries'];
    check('reset starts the numbering again', $tl[0]['n'] === 0 && $tl[0]['seq'] === 0);
    $sid = $db->insert('stations', ['slug' => 'empty', 'name' => 'Empty FM', 'timezone' => 'UTC', 'settings' => null, 'created_at' => gmdate('Y-m-d H:i:s')]);
    $empty = new Planner($db, ['id' => $sid, 'name' => 'Empty FM', 'timezone' => 'UTC', 'settings' => null], $root);
    check('a station without songs has an empty timeline', $empty->timeline($t0) === []);
    check('and its playlist says so with 503', Stream::playlist($empty, $t0)['status'] === 503);
    check('station settings fall back to the proven defaults', $empty->setting('crossfade_seconds') == 6 && $empty->setting('segment_seconds') == 6);

    echo "Player feed\n";
    $w = $p->window($t0 + 50, $t0 + 20, $t0 + 900);
    check('window lists songs with their file address', $w && str_starts_with($w[0]['url'], 'storage/media/') && isset($w[0]['full'], $w[0]['fade_out'], $w[0]['end']));
    check('window covers the requested span', $w[0]['start'] <= $t0 + 20 && end($w)['end'] >= $t0 + 900);
} finally {
    drop_test_database($t);
    remove_tree($root);
}
finish();
