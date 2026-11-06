<?php
// Command-line test of what the station's apps read (round 46): the Slides note (the pictures, the note, the
// listeners' view, tidying up), the verse of the day (the list, Afrikaans names, the text kept for the day, a
// failed fetch), the public schedule JSON, and the podcast JSON's notes placed in the episode's audio (where the
// mixdown cut things out, deleted clips, rejoined shows).
//   php app/tests/station_api_test.php      (same SW_TEST_DB_* settings as install_test.php)
declare(strict_types=1);
require __DIR__ . '/lib.php';
use Stationwagon\{Db, Notes, Planner, Podcast, Schedule, Slides, Verse};

$t = test_database('stationapi');
$root = temp_root('stationapi');
try {
    $cfg = test_install($t, $root);
    $db = Db::connect($cfg['db']);
    $station = $db->run('SELECT * FROM ' . $db->table('stations'))->fetch();
    $dj = (int) $db->run('SELECT id FROM ' . $db->table('users'))->fetchColumn();
    $djName = (string) $db->run('SELECT name FROM ' . $db->table('users') . ' WHERE id = ?', [$dj])->fetchColumn();
    $jpeg = function (int $w, int $h, int $shade = 120): string {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, $shade, 90, 200));
        ob_start();
        imagejpeg($im, null, 80);
        return (string) ob_get_clean();
    };

    echo "Slides: the pictures\n";
    $slides = new Slides($db, $root);
    $a = $slides->store($jpeg(1600, 900));
    check('a JPEG is kept under a name made from its content', isset($a['file']) && Slides::valid($a['file']) && is_file("$root/storage/slides/{$a['file']}") && $a['url'] === 'storage/slides/' . $a['file'] && $a['width'] === 1600, json_encode($a));
    check('  the same picture again is the same file', $slides->store($jpeg(1600, 900))['file'] === $a['file']);
    $b = $slides->store($jpeg(800, 600, 30));
    check('  another picture is another file', ($b['file'] ?? '') !== $a['file'] && Slides::valid($b['file'] ?? ''));
    ob_start(); $png = imagecreatetruecolor(40, 40); imagepng($png); $pngBytes = (string) ob_get_clean();
    check('a PNG is refused', isset($slides->store($pngBytes)['error']));
    check('  and text, and nothing, and a huge picture', isset($slides->store('not a picture')['error']) && isset($slides->store('')['error']) && isset($slides->store($jpeg(4100, 100))['error']));
    check('  and one over ' . Slides::MAX_BYTES . ' bytes', isset($slides->store(str_repeat("\xFF\xD8", Slides::MAX_BYTES))['error']));
    check('file names are only ever the station’s own', !Slides::valid('../x.jpg') && !Slides::valid('slide-123.jpg') && !Slides::valid('slide-0123456789ab.png') && Slides::valid('slide-0123456789ab.jpg'));

    echo "Slides: the note\n";
    $n = Notes::clean('slides', ['text' => '  Sunday’s   message ', 'images' => [$a['file'], $b['file'], $a['file']]]);
    check('a slides note keeps its title and its pictures in order, once each', ($n['body']['text'] ?? '') === 'Sunday’s message' && ($n['body']['images'] ?? []) === [$a['file'], $b['file']], json_encode($n));
    check('  it needs a picture', isset(Notes::clean('slides', ['text' => 'x', 'images' => []])['error']) && isset(Notes::clean('slides', [])['error']));
    check('  only the station’s own names', isset(Notes::clean('slides', ['images' => ['../../etc/passwd']])['error']) && isset(Notes::clean('slides', ['images' => 'slide-0123456789ab.jpg'])['error']) === true);
    check('  and ' . Slides::MAX_PER_NOTE . ' at most', isset(Notes::clean('slides', ['images' => array_map(fn($i) => sprintf('slide-%012x.jpg', $i), range(1, Slides::MAX_PER_NOTE + 1))])['error']));
    $p = new Planner($db, $station, $root);
    $notes = new Notes($db, $station);
    $lib = new Stationwagon\Library($db, $station, $root);
    mkdir("$root/in");
    foreach (['Amazing Grace' => 150, 'Be Thou My Vision' => 170] as $name => $secs) {
        make_mp3("$root/in/tmp", $secs, [], $secs);
        $lib->import("$root/in/tmp", null, null, "$name.mp3");
    }
    $now = 1800000000.0;
    $p->timeline($now);
    $live = $p->goLive($now, 'Morning message', $dj)['result']['live'];
    $posted = $notes->post($live['public_id'], $dj, 'slides', ['text' => 'Sunday’s message', 'images' => [$a['file'], $b['file']]], $now + 5);
    check('a slides note posts to a show on air', isset($posted['note']) && $posted['note']['kind'] === 'slides', json_encode($posted));
    check('  and listeners get its pictures, addressed from the app', array_column($posted['note']['slides'] ?? [], 'url') === ['storage/slides/' . $a['file'], 'storage/slides/' . $b['file']]);
    check('  in the listeners’ view as well', ($notes->forListeners($now + 30)['items'][0]['slides'][1]['file'] ?? '') === $b['file']);
    $edited = $notes->edit($live['public_id'], $dj, $posted['note']['id'], ['text' => 'Sunday', 'images' => [$b['file']]], $now + 60);
    check('  a change keeps the kind', ($edited['note']['kind'] ?? '') === 'slides' && count($edited['note']['slides']) === 1);

    echo "Slides: tidying up\n";
    $orphan = $slides->store($jpeg(300, 300, 5))['file'];
    $real = microtime(true);
    check('a picture no note uses stays for a day', $slides->cleanup($real) === 0 && is_file("$root/storage/slides/$orphan"));
    touch("$root/storage/slides/$orphan", (int) $real - 2 * 86400);
    touch("$root/storage/slides/{$a['file']}", (int) $real - 2 * 86400);
    check('  then goes, and a picture in a note never does', $slides->cleanup($real) === 1 && !is_file("$root/storage/slides/$orphan") && is_file("$root/storage/slides/{$a['file']}"));

    echo "Verse of the day\n";
    $calls = [];
    $okFetch = function (string $url) use (&$calls): ?string {
        $calls[] = $url;
        return json_encode(['reference' => 'x', 'text' => "The LORD is my shepherd;\n I shall not want. \u{00a0}"]);
    };
    $verse = new Verse($station, $root, $okFetch);
    $day = 1800000000.0; // 2027-01-15, 10:00 in Johannesburg
    $v = $verse->today('en', $day);
    $ref = Verse::referenceFor('2027-01-15');
    check('English: the day’s reference, its public-domain text and where it came from', $v['date'] === '2027-01-15' && $v['reference'] === $ref && $v['text'] === 'The LORD is my shepherd; I shall not want.'
        && $v['version'] === 'WEB' && $v['translation'] === 'World English Bible' && str_contains($v['read'], 'version=WEB') && str_contains($v['read'], rawurlencode($ref)), json_encode($v));
    check('  asked for from the public Bible API by reference and translation', count($calls) === 1 && str_starts_with($calls[0], 'https://bible-api.com/' . rawurlencode($ref) . '?translation=web'), $calls[0] ?? '');
    $verse->today('en', $day + 3600);
    check('  and kept for the day: the next ask costs nothing', count($calls) === 1 && is_file("$root/storage/cache/verse-2027-01-15.json"));
    $af = $verse->today('af', $day);
    check('Afrikaans: the reference in Afrikaans book names, a link to read it, no text', $af['lang'] === 'af' && $af['text'] === null && $af['version'] === null && str_contains($af['read'], 'version=AFR53'), json_encode($af));
    check('  Afrikaans names: John → Johannes, 1 Corinthians → 1 Korintiërs, Psalm stays', Verse::localRef('John 3:16', 'af') === 'Johannes 3:16' && Verse::localRef('1 Corinthians 13:4-7', 'af') === '1 Korintiërs 13:4-7'
        && Verse::localRef('Psalm 23:1', 'af') === 'Psalm 23:1' && Verse::localRef('John 3:16', 'en') === 'John 3:16');
    check('  every reference in the list has its Afrikaans book', (function () {
        foreach (Verse::REFERENCES as $r) {
            preg_match('/^(\d? ?[A-Za-z ]+?) \d/', $r, $m);
            if (!isset(Verse::BOOKS_AF[$m[1] ?? ''])) {
                return false;
            }
        }
        return true;
    })());
    check('another language answers like English', $verse->today('zu', $day)['lang'] === 'en');
    $next = $verse->today('en', $day + 86400);
    check('the next day is the next reference', $next['date'] === '2027-01-16' && $next['reference'] === Verse::REFERENCES[(array_search($ref, Verse::REFERENCES, true) + 1) % count(Verse::REFERENCES)] && count($calls) === 2);
    check('  the old day’s file is tidied away', !is_file("$root/storage/cache/verse-2027-01-15.json") && is_file("$root/storage/cache/verse-2027-01-16.json"));
    check('the station’s own day decides: 23:30 UTC is already tomorrow in Johannesburg', $verse->today('en', gmmktime(23, 30, 0, 1, 15, 2027))['date'] === '2027-01-16');
    $failing = 0;
    $bad = new Verse($station, $root . '/nowhere-cache', function (string $url) use (&$failing): ?string { $failing++; return null; });
    @mkdir("$root/nowhere-cache/storage/cache", 0775, true);
    $none = $bad->today('en', $day);
    check('when the text can’t be fetched the answer still has the reference and the link, no text', $none['text'] === null && $none['reference'] === $ref && $none['read'] !== '' && $failing === 1);
    $bad->today('en', $day + 10);
    check('  and a failure isn’t tried again on every request', $failing === 1);
    $bad->today('en', $day + Verse::RETRY_SECONDS + 10);
    check('  only after a few minutes', $failing === 2);
    $junk = new Verse($station, $root, fn(string $u) => 'not json');
    @unlink("$root/storage/cache/verse-2027-01-16.json");
    check('  a reply that isn’t a verse is no text', $junk->today('en', $day + 86400 + 9000)['text'] === null);

    echo "Public schedule\n";
    $sched = new Schedule($db, $station);
    [$id1] = $sched->add(['title' => 'Morning Word', 'kind' => 'live', 'dj_user_id' => $dj, 'days' => [1, 2, 4, 8, 16], 'start' => '06:00', 'end' => '07:30']);
    $plId = $db->insert('playlists', ['station_id' => $station['id'], 'name' => 'Worship', 'mode' => 'shuffle', 'active' => 1, 'created_at' => gmdate('Y-m-d H:i:s')]);
    [$id2] = $sched->add(['title' => 'Night worship', 'kind' => 'playlist', 'playlist_id' => $plId, 'days' => [1, 2, 4, 8, 16, 32, 64], 'start' => '22:00', 'end' => '02:00']);
    $blocks = $sched->publicBlocks();
    $byId = array_column($blocks, null, 'id');
    check('blocks list in time order with ISO weekdays and HH:MM', count($blocks) === 2 && $blocks[0]['title'] === 'Morning Word' && $byId[$id1]['days'] === [1, 2, 3, 4, 5] && $byId[$id1]['start'] === '06:00' && $byId[$id1]['end'] === '07:30', json_encode($blocks));
    check('  a live block names its presenter, an AutoDJ block doesn’t', $byId[$id1]['host'] === $djName && $byId[$id2]['host'] === null && $byId[$id1]['kind'] === 'live' && $byId[$id2]['kind'] === 'playlist');
    check('  every day, past midnight, no validity dates', $byId[$id2]['days'] === [1, 2, 3, 4, 5, 6, 7] && $byId[$id2]['start'] === '22:00' && $byId[$id2]['end'] === '02:00' && $byId[$id1]['valid_from'] === null);

    echo "Podcast JSON: notes placed in the audio\n";
    $frame = "\xFF\xFB\x90\x00" . str_repeat("\0", 413);
    $show = function (string $pid, float $start, int $n, ?int $continues = null) use ($db, $station, $root, $frame, $dj): int {
        $id = $db->insert('live_shows', ['station_id' => $station['id'], 'public_id' => $pid, 'dj_user_id' => $dj, 'title' => 'Sunday service', 'start_at' => $start,
            'open' => 0, 'closed_reason' => 'ended by DJ', 'last_piece_at' => $start + 6 * $n, 'continues_show_id' => $continues, 'created_at' => gmdate('Y-m-d H:i:s')]);
        @mkdir("$root/storage/live/$pid", 0775, true);
        for ($s = 0; $s < $n; $s++) {
            file_put_contents(sprintf('%s/storage/live/%s/%05d.mp3', $root, $pid, $s), str_repeat($frame, 230));
            $db->insert('live_pieces', ['show_id' => $id, 'seq' => $s, 'file' => sprintf('%05d.mp3', $s), 'duration_s' => 6.0, 'received_at' => $start + 6 * ($s + 1)]);
        }
        return $id;
    };
    $t0 = microtime(true);
    $pod = new Podcast($db, $station, $root);
    $pod->sync($t0 - 100000); // sets when podcasts began
    $S = floor($t0) - 5000;
    $A = $show('live-pa', $S, 25);                 // 150 s
    $B = $show('live-pb', $S + 200, 10, $A);       // rejoined, 60 s
    $pod->sync($t0);
    $ep = $db->run('SELECT * FROM ' . $db->table('episodes') . ' WHERE live_show_id = ?', [$A])->fetch();
    $db->run('UPDATE ' . $db->table('episodes') . " SET status = 'published', audio_file = 'sunday.mp3', audio_seconds = 210, audio_bytes = 1000, title = 'Sunday service', description = 'Hello' WHERE id = ?", [$ep['id']]);
    $put = function (int $showId, float $at, string $kind, array $body) use ($db, $station, $dj) {
        $db->insert('presenter_notes', ['station_id' => $station['id'], 'live_show_id' => $showId, 'user_id' => $dj, 'kind' => $kind, 'body' => json_encode($body), 'posted_at' => gmdate('Y-m-d H:i:s', (int) $at)]);
    };
    $put($A, $S + 10, 'note', ['text' => 'Welcome']);
    $put($A, $S + 45, 'verse', ['ref' => 'John 3:16', 'version' => 'KJV', 'text' => 'For God so loved the world']);
    $put($A, $S + 100, 'slides', ['text' => 'Points', 'images' => [$b['file']]]);
    $put($B, $S + 205, 'note', ['text' => 'We are back']);
    $ep = $db->run('SELECT * FROM ' . $db->table('episodes') . ' WHERE id = ?', [$ep['id']])->fetch();
    $at = fn(array $items) => array_map(fn($i) => $i['at_s'], $items);
    check('with no analysis every note lands where it was posted, the rejoined show after the first', $at($pod->notes($ep)) === [10.0, 45.0, 100.0, 155.0], json_encode($at($pod->notes($ep))));
    $takes = $pod->takes((int) $ep['id']);
    $takeA = (int) $takes[0]['id'];
    $db->run('UPDATE ' . $db->table('episode_takes') . ' SET analysis = ? WHERE id = ?', [json_encode(['rate' => 10, 'tracks' => ['aired' => ['peaks' => '', 'clips' => [[0, 30], [60, 150]]]]]), $takeA]);
    check('a long silence is cut, so a note after it moves earlier (30 s gap, 1 s kept)', $at($pod->notes($ep)) === [10.0, 30.5, 71.0, 126.0], json_encode($at($pod->notes($ep))));
    $db->run('UPDATE ' . $db->table('episodes') . ' SET edits = ? WHERE id = ?', [json_encode(['deleted' => [], 'mixed' => ["$takeA:aired:1"]]), $ep['id']]);
    $ep = $db->run('SELECT * FROM ' . $db->table('episodes') . ' WHERE id = ?', [$ep['id']])->fetch();
    check('  a deleted clip leaves its stretch out: a note in it lands where the cut is', $at($pod->notes($ep))[2] === 30.5, json_encode($at($pod->notes($ep))));
    $items = $pod->notes($ep);
    check('  in order, each with its kind, text and slide pictures', array_column($items, 'kind') === ['note', 'verse', 'slides', 'note'] && $items[2]['slides'][0]['file'] === $b['file'] && $items[0]['text'] === 'Welcome');
    check('segments: a pause of 2 s or less stays, longer is cut to 1 s', Podcast::segments(['tracks' => ['aired' => ['clips' => [[1, 5], [7, 9], [20, 25]]]]], 1, [], 0, 100) === [[0.5, 9.5], [19.5, 25.5]]);
    check('  a take no clip of which is left contributes nothing', Podcast::segments(['tracks' => ['aired' => ['clips' => [[1, 5]]]]], 1, ['1:aired:0' => 0], 0, 100) === []);
    check('  where a time lands: inside, in a cut, after the end', Podcast::placeIn([[0.5, 9.5], [19.5, 25.5]], 3.0) === 2.5 && Podcast::placeIn([[0.5, 9.5], [19.5, 25.5]], 15.0) === 9.0 && Podcast::placeIn([[0.5, 9.5], [19.5, 25.5]], 99.0) === 15.0);
    $one = $pod->publicEpisodeWithNotes($ep['public_id'], 'https://radio.example.test/radio');
    check('one episode as JSON: absolute audio and slide addresses, its notes, no internal fields', $one['audio'] === 'https://radio.example.test/radio/storage/podcasts/' . $ep['public_id'] . '/sunday.mp3'
        && $one['items'][2]['slides'][0]['url'] === 'https://radio.example.test/radio/storage/slides/' . $b['file'] && !isset($one['items'][0]['show']) && $one['host'] === $djName && $one['seconds'] === 210, json_encode($one));
    $list = $pod->publicList('https://radio.example.test/radio/');
    check('the list: the podcast’s details and the published episodes', $list['episodes'][0]['id'] === $ep['public_id'] && $list['podcast']['feed'] === 'https://radio.example.test/radio/podcast/feed' && !isset($list['episodes'][0]['items']), json_encode($list));
    check('a draft, a hidden or unknown episode has no JSON', $pod->publicEpisodeWithNotes('ep-20200101-00000000', 'https://x.test/') === null);
    $db->run('UPDATE ' . $db->table('episodes') . " SET status = 'hidden' WHERE id = ?", [$ep['id']]);
    check('  a hidden one neither', $pod->publicEpisodeWithNotes($ep['public_id'], 'https://x.test/') === null && $pod->publicList('https://x.test/')['episodes'] === []);
    finish();
} finally {
    drop_test_database($t);
    remove_tree($root);
}
