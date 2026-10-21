<?php
// Command-line test of podcasts: the schema upgrade to version 6, every ended live show becoming
// an episode (rejoined shows joining their first show's episode, short ones skipped, shows from
// before podcasts left out), the studio's own two-track recording (chunks, who may send them, the
// counts check, it replacing the take as aired), the editor's view and files, cuts, the mixdown
// parts, publishing, the RSS feed, hiding, the podcast's details and cover, and the raw tracks
// going 30 days after publishing.
//   php app/tests/podcast_test.php      (same SW_TEST_DB_* settings as install_test.php)
declare(strict_types=1);
require __DIR__ . '/lib.php';
use Stationwagon\{Db, Podcast, Schema};

$t = test_database('podcast');
$root = temp_root('podcast');
try {
    $cfg = test_install($t, $root);
    $db = Db::connect($cfg['db']);
    $p = $cfg['db']['prefix'];
    $station = $db->run('SELECT * FROM ' . $db->table('stations'))->fetch();
    $count = fn(string $table) => (int) $db->run('SELECT COUNT(*) FROM ' . $db->table($table))->fetchColumn();

    echo "Upgrade\n";
    check('a new install is at version ' . Schema::VERSION . ' with the three podcast tables', Schema::installed($db) === 6
        && count(array_filter(['episodes', 'episode_takes', 'episode_chunks'], fn($x) => (bool) $db->run("SHOW TABLES LIKE '{$p}$x'")->fetchColumn())) === 3);
    foreach (['episode_chunks', 'episode_takes', 'episodes'] as $table) {
        $db->pdo->exec("DROP TABLE `{$p}$table`");
    }
    $db->run('UPDATE ' . $db->table('settings') . " SET value = '5' WHERE name = 'schema_version'");
    check('a version 5 database upgrades to 6', Schema::upgrade($db, $root) === [6] && Schema::installed($db) === 6 && $count('episodes') === 0);

    $user = function (string $name, string $role) use ($db, $station): array {
        $id = $db->insert('users', ['email' => strtolower(str_replace(' ', '.', $name)) . '@example.test', 'name' => $name, 'password_hash' => 'x', 'role' => $role, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $db->insert('station_users', ['station_id' => $station['id'], 'user_id' => $id]);
        return ['id' => $id, 'name' => $name, 'role' => $role];
    };
    $mgr = $user('Mary Manager', 'manager');
    $sidney = $user('Sidney Lottering', 'dj');
    $anna = $user('Anna Botha', 'dj');

    // a closed live show with $n pieces of 6 s, as the studio would have sent them
    $frame = "\xFF\xFB\x90\x00" . str_repeat("\0", 413);
    $show = function (string $pid, array $dj, float $start, int $n, ?int $continues = null, ?string $created = null) use ($db, $station, $root, $frame): int {
        $id = $db->insert('live_shows', ['station_id' => $station['id'], 'public_id' => $pid, 'dj_user_id' => $dj['id'], 'title' => 'Morning message', 'start_at' => $start,
            'open' => 0, 'closed_reason' => 'ended by DJ', 'last_piece_at' => $start + 6 * $n, 'continues_show_id' => $continues, 'created_at' => $created ?? gmdate('Y-m-d H:i:s')]);
        @mkdir("$root/storage/live/$pid", 0775, true);
        for ($s = 0; $s < $n; $s++) {
            file_put_contents(sprintf('%s/storage/live/%s/%05d.mp3', $root, $pid, $s), str_repeat($frame, 230));
            $db->insert('live_pieces', ['show_id' => $id, 'seq' => $s, 'file' => sprintf('%05d.mp3', $s), 'duration_s' => 6.0, 'received_at' => $start + 6 * ($s + 1)]);
        }
        return $id;
    };
    $now = microtime(true);
    $pod = new Podcast($db, $station, $root);

    echo "Episodes from live shows\n";
    $old = $show('live-old', $sidney, $now - 90000, 40, null, gmdate('Y-m-d H:i:s', (int) $now - 90000));
    check('the first look sets when podcasts began and leaves older shows out', $pod->sync($now) === 0 && $count('episodes') === 0);
    $a = $show('live-a', $sidney, $now - 3000, 25);            // 150 s
    $b = $show('live-b', $sidney, $now - 2700, 10, $a);        // rejoined: 60 s more
    $short = $show('live-short', $anna, $now - 2000, 15);      // 90 s
    check('each ended show gets a take as aired', $pod->sync($now) === 3 && $count('episode_takes') === 3);
    check('  and again adds nothing', $pod->sync($now) === 0);
    $epA = $db->run('SELECT * FROM ' . $db->table('episodes') . ' WHERE live_show_id = ?', [$a])->fetch();
    $epShort = $db->run('SELECT * FROM ' . $db->table('episodes') . ' WHERE live_show_id = ?', [$short])->fetch();
    check('a rejoined show is part of its first show’s episode', $count('episodes') === 2 && count($pod->takes((int) $epA['id'])) === 2);
    check('  a draft, in the DJ’s name, titled after the show', $epA['status'] === 'draft' && (int) $epA['dj_user_id'] === $sidney['id'] && $epA['title'] === 'Morning message'
        && preg_match('/^ep-\d{8}-[0-9a-f]{8}$/', $epA['public_id']) === 1, json_encode($epA));
    check('a show under ' . Podcast::MIN_SECONDS . ' s is skipped', $epShort['status'] === 'skipped');
    check('the station can set the shortest show itself', (function () use ($db, $station, $root, $epShort, $short, $show, $now) {
        $s = $station; $s['settings'] = json_encode(['podcast_min_seconds' => 60]);
        $pod2 = new Podcast($db, $s, $root);
        $show('live-short2', ['id' => $epShort['dj_user_id']], $now - 1000, 1, $short); // a 6 s rejoin re-settles it
        $pod2->sync($now);
        $st = $db->run('SELECT status FROM ' . $db->table('episodes') . ' WHERE id = ?', [$epShort['id']])->fetchColumn();
        $db->run('UPDATE ' . $db->table('episodes') . " SET status = 'skipped' WHERE id = ?", [$epShort['id']]);
        return $st === 'draft';
    })());
    check('the list: a manager sees the drafts, a DJ only their own, nobody the skipped', count($pod->list($mgr)) === 1 && count($pod->list($sidney)) === 1 && count($pod->list($anna)) === 0);
    check('  with the raw length and where it came from', abs($pod->list($mgr)[0]['raw_seconds'] - 210) < 0.01 && $pod->list($mgr)[0]['source'] === 'aired');
    check('who may edit: the show’s DJ and managers', $pod->mayEdit($sidney, $epA) && $pod->mayEdit($mgr, $epA) && !$pod->mayEdit($anna, $epA));

    echo "The studio’s own recording\n";
    $chunk = str_repeat($frame, 100); // stands for a minute of audio (the server takes the count the studio gives)
    $min = 60 * 44100;
    check('another DJ can’t send a show’s recording', $pod->addChunk($anna, 'live-a', 'music', 0, 115200, $chunk)['status'] === 403);
    check('  nor anyone a chunk of a show that doesn’t exist', $pod->addChunk($sidney, 'live-nope', 'music', 0, 115200, $chunk)['status'] === 404);
    check('only the music and mic tracks', $pod->addChunk($sidney, 'live-a', 'aired', 0, 115200, $chunk)['status'] === 400);
    check('  of MP3 audio', $pod->addChunk($sidney, 'live-a', 'music', 0, 115200, 'not audio at all')['status'] === 400);
    check('  with a sane number and length', $pod->addChunk($sidney, 'live-a', 'music', -1, 115200, $chunk)['status'] === 400 && $pod->addChunk($sidney, 'live-a', 'music', 0, 0, $chunk)['status'] === 400);
    foreach ([['music', 0], ['music', 1], ['mic', 0], ['mic', 1], ['mic', 1]] as [$track, $n]) {
        $r = $pod->addChunk($sidney, 'live-a', $track, $n, $min, $chunk);
    }
    check('the DJ’s chunks are kept (sending one again replaces it)', ($r['ok'] ?? false) && $count('episode_chunks') === 4
        && is_file("$root/storage/podcasts/{$epA['public_id']}/raw/" . $db->run('SELECT id FROM ' . $db->table('episode_takes') . " WHERE kind = 'device'")->fetchColumn() . '-music-0001.chunk'));
    check('  a manager may send them too', $pod->addChunk($mgr, 'live-a', 'music', 2, $min / 2, str_repeat($frame, 50))['ok'] ?? false);
    $peaks = base64_encode(str_repeat("\x80", 70));
    $analysis = ['rate' => 10, 'tracks' => ['music' => ['peaks' => $peaks, 'clips' => [[0, 3.2], [5.5, 7.0]]], 'mic' => ['peaks' => $peaks, 'clips' => [[1, 2.5]]]]];
    $bad = $pod->finishDevice($sidney, 'live-a', ['music' => 4, 'mic' => 2], $analysis);
    check('done with a chunk missing: refused, with what arrived', $bad['status'] === 409 && $bad['have'] === [0, 1, 2], json_encode($bad));
    check('  an unreadable clip list is refused', $pod->finishDevice($sidney, 'live-a', ['music' => 3, 'mic' => 2], ['rate' => 10, 'tracks' => ['music' => ['peaks' => $peaks, 'clips' => [[3, 1]]]]])['status'] === 400);
    check('  and clips out of order', $pod->finishDevice($sidney, 'live-a', ['music' => 3, 'mic' => 2], ['rate' => 10, 'tracks' => ['music' => ['peaks' => $peaks, 'clips' => [[5, 6], [1, 2]]], 'mic' => ['peaks' => $peaks, 'clips' => []]]])['status'] === 400);
    $done = $pod->finishDevice($sidney, 'live-a', ['music' => 3, 'mic' => 2], $analysis);
    check('all there: the recording is complete', ($done['ok'] ?? false) && $done['id'] === (int) $epA['id'] && $done['episode'] === $epA['public_id'], json_encode($done));
    $takes = $pod->takes((int) $epA['id']);
    check('  and the episode uses it for that show (the rejoin stays as aired)', count($takes) === 2 && $takes[0]['kind'] === 'device' && $takes[1]['kind'] === 'aired');
    check('  its length is the music track’s samples', abs($takes[0]['duration_s'] - 150) < 0.001, (string) $takes[0]['duration_s']);
    check('  no more chunks after that', $pod->addChunk($sidney, 'live-a', 'music', 3, 115200, $chunk)['status'] === 409);
    check('the list says it was recorded in the studio', $pod->list($mgr)[0]['source'] === 'device');
    // a studio recording that stopped a minute early (the page reloaded): the rest comes as aired
    $c = $show('live-c', $anna, $now - 1500, 30);               // 180 s went out
    $pod->sync($now);
    $pod->addChunk($anna, 'live-c', 'music', 0, $min * 2, $chunk);
    $pod->addChunk($anna, 'live-c', 'mic', 0, $min * 2, $chunk);
    $pod->finishDevice($anna, 'live-c', ['music' => 1, 'mic' => 1], ['rate' => 10, 'tracks' => ['music' => ['peaks' => $peaks, 'clips' => [[0, 120]]], 'mic' => ['peaks' => $peaks, 'clips' => []]]]);
    $epC = $db->run('SELECT * FROM ' . $db->table('episodes') . ' WHERE live_show_id = ?', [$c])->fetch();
    $tc = $pod->takes((int) $epC['id']);
    check('a studio recording that stopped early is followed by the rest as aired', count($tc) === 2 && $tc[0]['kind'] === 'device' && $tc[1]['kind'] === 'aired'
        && abs($tc[1]['skip_s'] - 120) < 0.001 && abs($tc[1]['duration_s'] - 60) < 0.01, json_encode(array_map(fn($x) => [$x['kind'], $x['skip_s'], $x['duration_s']], $tc)));
    $vc = $pod->view($epC);
    check('  the editor gets it after the recording, to skip the first 2 minutes of it', $vc['takes'][1]['skip'] === 120.0 && abs($vc['takes'][1]['offset'] - 120) < 0.01 && count($vc['takes'][1]['tracks']['aired']) === 30);
    check('  and the episode is the whole show long', abs(array_sum(array_column($pod->list($anna), 'raw_seconds')) - 180) < 0.01 && $epC['status'] === 'draft');
    check('a recording that ends with the show has no such tail', count($pod->takes((int) $epA['id'])) === 2 && $pod->takes((int) $epA['id'])[0]['skip_s'] === 0.0);

    echo "The editor\n";
    $epA = $pod->episodeById((int) $epA['id']);
    $v = $pod->view($epA);
    check('the view: both takes, back to back, with their chunks', count($v['takes']) === 2 && $v['takes'][0]['kind'] === 'device' && $v['takes'][1]['offset'] === round($v['takes'][0]['duration'], 3)
        && count($v['takes'][0]['tracks']['music']) === 3 && count($v['takes'][0]['tracks']['mic']) === 2 && count($v['takes'][1]['tracks']['aired']) === 10
        && $v['takes'][1]['tracks']['aired'][0]['samples'] === 264960 && $v['state'] === 'draft' && $v['audio'] === null && $v['sample_rate'] === 44100, json_encode($v['takes'][1]['tracks']['aired'][0]));
    check('  with the device’s clips, and none yet for the take as aired', $v['takes'][0]['analysis']['tracks']['mic']['clips'] === [[1, 2.5]] && $v['takes'][1]['analysis'] === null);
    $dev = $v['takes'][0]['id'];
    $aired = $v['takes'][1]['id'];
    check('chunk files: a device chunk and a piece as aired', is_file((string) $pod->chunkPath($epA, $dev, 'mic', 1)) && str_ends_with((string) $pod->chunkPath($epA, $aired, 'aired', 3), 'live-b' . DIRECTORY_SEPARATOR . '00003.mp3') || str_ends_with((string) $pod->chunkPath($epA, $aired, 'aired', 3), 'live-b/00003.mp3'));
    check('  nothing for another episode’s take or a missing chunk', $pod->chunkPath($epA, 99999, 'music', 0) === null && $pod->chunkPath($epA, $dev, 'music', 9) === null);
    $airedA = ['rate' => 10, 'tracks' => ['aired' => ['peaks' => base64_encode(str_repeat("\x40", 600)), 'clips' => [[0, 20], [30, 60]]]]];
    check('the editor’s clips for a take as aired are kept', $pod->saveAnalysis($epA, $aired, $airedA)['ok'] ?? false);
    check('  but not over the device’s', $pod->saveAnalysis($epA, $dev, $airedA)['status'] === 400);
    check('cuts are kept, sorted, without anything that isn’t a clip id', $pod->saveEdits($epA, ["$aired:aired:1", "$dev:mic:0", 'drop table', 5])['deleted'] === 2
        && $pod->view($pod->episodeById((int) $epA['id']))['deleted'] === ["$aired:aired:1", "$dev:mic:0"]);
    check('  a non-list is refused', $pod->saveEdits($epA, 'x')['status'] === 400);

    echo "Mixdown and publishing\n";
    check('a mixdown part must be MP3', $pod->addMixPart($epA, 0, 'nope')['status'] === 400);
    $pod->addMixPart($epA, 0, str_repeat($frame, 300));
    $pod->addMixPart($epA, 1, str_repeat($frame, 200));
    check('done with a part missing is refused', $pod->finishMix($epA, 3, 13.0)['status'] === 409);
    $mix = $pod->finishMix($epA, 2, 13.06, ["$dev:mic:0", "$aired:aired:1"]);
    $epA = $pod->episodeById((int) $epA['id']);
    $dir = "$root/storage/podcasts/{$epA['public_id']}";
    check('the parts are joined into a private file', ($mix['ok'] ?? false) && $epA['audio_file'] === 'episode.chunk' && filesize("$dir/episode.chunk") === 500 * 417 && !glob("$dir/mix-*"));
    check('  the editor knows which cuts it was made with', $pod->view($epA)['mixed_deleted'] === ["$aired:aired:1", "$dev:mic:0"] && $pod->view($epA)['audio']['url'] === null);
    check('  the draft isn’t in the feed or on the page', $pod->published() === [] && !str_contains($pod->feed('https://radio.test/app/'), '<item>'));
    check('publishing needs a title', $pod->publish($epA, '   ', '', true)['status'] === 400);
    check('saving the text alone keeps it a draft', ($pod->publish($epA, 'Grace for today', 'A word for the morning.', false)['ok'] ?? false) && $pod->episodeById((int) $epA['id'])['status'] === 'draft');
    $pub = $pod->publish($pod->episodeById((int) $epA['id']), 'Grace for today', "A word for the morning.\nWith songs.", true);
    $epA = $pod->episodeById((int) $epA['id']);
    check('published: a public MP3 named after the title', ($pub['ok'] ?? false) && $epA['status'] === 'published' && $epA['audio_file'] === 'grace-for-today.mp3' && is_file("$dir/grace-for-today.mp3")
        && $pub['url'] === "storage/podcasts/{$epA['public_id']}/grace-for-today.mp3" && $epA['published_at'] !== null, json_encode($pub));
    $xml = simplexml_load_string($pod->feed('https://radio.test/app/'));
    $item = $xml ? $xml->channel->item[0] : null;
    $it = $item ? $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd') : null;
    check('the feed is valid XML with the episode', $item !== null && (string) $item->title === 'Grace for today' && (string) $item->guid === $epA['public_id']);
    check('  its enclosure is the MP3 with its size, its duration h:mm:ss', (string) $item->enclosure['url'] === "https://radio.test/app/storage/podcasts/{$epA['public_id']}/grace-for-today.mp3"
        && (int) $item->enclosure['length'] === 500 * 417 && (string) $item->enclosure['type'] === 'audio/mpeg' && (string) $it->duration === '0:00:13');
    $chan = $xml->channel->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
    check('  the channel has Apple’s tags (author, category, explicit)', (string) $xml->channel->title === $station['name'] && (string) $chan->author === $station['name']
        && (string) $chan->category->attributes()['text'] === 'Religion & Spirituality' && (string) $chan->category->category->attributes()['text'] === 'Christianity' && (string) $chan->explicit === 'false');
    $first = $epA['published_at'];
    $pod->addMixPart($epA, 0, str_repeat($frame, 120));
    $pod->finishMix($epA, 1, 3.1, []);
    $epA = $pod->episodeById((int) $epA['id']);
    check('a new mixdown of a published episode is public at once, under a new name', preg_match('/^grace-for-today-[0-9a-f]{6}\.mp3$/', $epA['audio_file']) === 1 && !is_file("$dir/grace-for-today.mp3")
        && filesize("$dir/{$epA['audio_file']}") === 120 * 417 && $epA['status'] === 'published', (string) $epA['audio_file']);
    $pod->publish($epA, 'Grace for today (edited)', '', true);
    check('  publishing again keeps the first date and the file', $pod->episodeById((int) $epA['id'])['published_at'] === $first && $pod->episodeById((int) $epA['id'])['audio_file'] === $epA['audio_file']);
    check('hiding takes it off the feed', ($pod->setHidden($epA, true)['ok'] ?? false) && $pod->published() === [] && !str_contains($pod->feed('https://radio.test/'), '<item>'));
    check('  showing it brings it back', ($pod->setHidden($pod->episodeById((int) $epA['id']), false)['ok'] ?? false) && count($pod->published()) === 1);
    check('a draft can’t be hidden', $pod->setHidden($pod->episodeById((int) $epShort['id']), true)['status'] === 409);
    check('storage serves published MP3s and the cover, not raw tracks or drafts (storage/.htaccess)', (function () {
        $h = (string) file_get_contents(__DIR__ . '/../storage/.htaccess');
        preg_match_all('/<FilesMatch "([^"]+)">/', $h, $m);
        return str_contains($h, 'Require all denied') && $m[1] === ['\.mp3$', '^cover-[0-9a-f]{12}\.(jpg|png)$'];
    })());

    echo "The podcast’s details\n";
    check('defaults from the station', $pod->details()['title'] === $station['name'] && $pod->details()['language'] === 'en' && $pod->details()['cover'] === null);
    $in = ['title' => 'Word for the Day', 'description' => 'Daily.', 'author' => 'Grace Radio', 'email' => 'radio@example.test', 'language' => 'af', 'category' => 'Music', 'explicit' => '1'];
    check('a wrong email, language or category is refused', $pod->saveDetails(['email' => 'nope'] + $in, null)[1] !== null && $pod->saveDetails(['language' => 'Afrikaans'] + $in, null)[1] !== null
        && $pod->saveDetails(['category' => 'Gaming'] + $in, null)[1] !== null);
    $img = function (int $w, int $h, string $path, string $type = 'png') { $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, 30, 200, 90)); $type === 'png' ? imagepng($im, $path) : imagejpeg($im, $path); imagedestroy($im); };
    $img(1400, 1200, "$root/wide.png");
    $img(800, 800, "$root/small.png");
    file_put_contents("$root/notimage.png", 'hello');
    check('the cover must be a square JPEG or PNG of 1400 to 3000 px', str_contains((string) $pod->saveDetails($in, "$root/wide.png")[1], 'square') && $pod->saveDetails($in, "$root/small.png")[1] !== null
        && str_contains((string) $pod->saveDetails($in, "$root/notimage.png")[1], 'JPEG or PNG'));
    $img(1400, 1400, "$root/cover.jpg", 'jpg');
    [$st, $problem] = $pod->saveDetails($in, "$root/cover.jpg");
    $pod = new Podcast($db, $st, $root);
    $d = $pod->details();
    check('saved, with the cover', $problem === null && $d['title'] === 'Word for the Day' && $d['language'] === 'af' && $d['explicit'] === true && preg_match('#^storage/podcasts/cover-[0-9a-f]{12}\.jpg$#', (string) $d['cover']) === 1, json_encode($d));
    $xml = simplexml_load_string($pod->feed('https://radio.test/'));
    $chan = $xml->channel->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
    check('  and the feed has them: cover, owner, language', (string) $xml->channel->title === 'Word for the Day' && (string) $chan->image->attributes()['href'] === 'https://radio.test/' . $d['cover']
        && (string) $chan->owner->email === 'radio@example.test' && (string) $xml->channel->language === 'af' && (string) $chan->explicit === 'true');
    $oldCover = $d['cover'];
    $img(1500, 1500, "$root/cover2.png");
    [$st] = $pod->saveDetails($in, "$root/cover2.png");
    $pod = new Podcast($db, $st, $root);
    check('a new cover replaces the old file', str_ends_with((string) $pod->details()['cover'], '.png') && !is_file("$root/$oldCover"));
    [$st] = $pod->saveDetails(['email' => ''] + $in, null);
    $pod = new Podcast($db, $st, $root);
    check('  saving without one keeps it', $pod->details()['cover'] !== null);

    echo "Raw tracks after 30 days\n";
    $raw = glob("$dir/raw/*.chunk");
    check('a published episode keeps them for ' . Podcast::RAW_KEEP_DAYS . ' days', $pod->cleanup($now) === 0 && count($raw) === 5);
    check('  after that they go (the mixdown stays)', $pod->cleanup($now + 31 * 86400) === 1 && !is_dir("$dir/raw") && $count('episode_chunks') === 2 && is_file("$dir/{$epA['audio_file']}"));
    $v = $pod->view($pod->episodeById((int) $epA['id']));
    check('  the editor says so, and the take as aired can still be read', $v['raw_deleted'] && !$v['takes'][0]['raw'] && $v['takes'][1]['raw']);
    check('  and nothing more to do', $pod->cleanup($now + 32 * 86400) === 0);
    check('a draft’s raw tracks stay', (function () use ($pod, $now, $anna, $db) {
        $pod->addChunk($anna, 'live-short', 'music', 0, 115200, str_repeat("\xFF\xFB\x90\x00" . str_repeat("\0", 413), 100));
        $n = $pod->cleanup($now + 400 * 86400);
        return $n === 0 && (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('episode_chunks'))->fetchColumn() === 3; // two of live-c's, and this one
    })());
} finally {
    drop_test_database($t);
    remove_tree($root);
}
finish();
