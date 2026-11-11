<?php
// Command-line test of "Look up details" (round 65): MusicBrainz searches, recording details, the work's writers and
// publishers, the release's album, label and track, the pause between requests, the 30-day cache and the errors.
// It never reaches the internet: the answers are real MusicBrainz responses for one song, kept in fixtures-musicbrainz.json.
//   php app/tests/lookup_test.php
declare(strict_types=1);
require __DIR__ . '/lib.php';
use Stationwagon\MusicBrainz;

$fixtures = json_decode((string) file_get_contents(__DIR__ . '/fixtures-musicbrainz.json'), true);
$root = temp_root('lookup');
try {
    $calls = [];
    $transport = function (string $url, string $ua) use ($fixtures, &$calls): array {
        $calls[] = ['url' => $url, 'ua' => $ua, 'at' => microtime(true)];
        $f = $fixtures[$url] ?? null;
        return $f ? ['code' => (int) $f['code'], 'body' => (string) $f['body']] : ['code' => 404, 'body' => '{}'];
    };
    $mb = new MusicBrainz($root, 'Stationwagon/0.9.10 ( https://radio.example.test/radio/ )', $transport);

    echo "Small helpers\n";
    check('the User-Agent says who asks: the program, its version and the station’s address', MusicBrainz::userAgent(['settings' => '{}'], '0.9.10-dev', 'https://radio.example.test/radio/') === 'Stationwagon/0.9.10-dev ( https://radio.example.test/radio/ )');
    check('  or the station’s privacy email when it has one', MusicBrainz::userAgent(['settings' => json_encode(['privacy_email' => 'office@radio.example.test'])], '0.9.10', 'https://x.test') === 'Stationwagon/0.9.10 ( office@radio.example.test )');
    check('an ISRC is cleaned (dashes, spaces, capitals) or refused', MusicBrainz::cleanIsrc(' us-wfe-17-90445 ') === 'USWFE1790445' && MusicBrainz::cleanIsrc('US-WFE-17') === null && MusicBrainz::cleanIsrc('') === null);
    check('only a UUID is a MusicBrainz id', MusicBrainz::mbid('0DF9F5B3-a8c0-4be3-8cbb-6aae9a84c322') === '0df9f5b3-a8c0-4be3-8cbb-6aae9a84c322'
        && (function () { foreach (['', 'abc', '../etc/passwd', '0df9f5b3-a8c0-4be3-8cbb-6aae9a84c32', "0df9f5b3-a8c0-4be3-8cbb-6aae9a84c322\n/x"] as $bad) { try { MusicBrainz::mbid($bad); return false; } catch (RuntimeException) { } } return true; })());
    check('the search query’s own characters are made plain', MusicBrainz::lucene('AC/DC: "Hells" (live) +1') === 'AC\\/DC\\: \\"Hells\\" \\(live\\) \\+1');

    echo "Searching\n";
    $found = $mb->search('Reckless Love', 'Cory Asbury', '', 333.0);
    check('a search by title and artist finds the recordings, the one whose length agrees first', count($found) === 4 && $found[0]['title'] === 'Reckless Love' && $found[0]['artist'] === 'Cory Asbury' && $found[0]['mbid'] === '0df9f5b3-a8c0-4be3-8cbb-6aae9a84c322', json_encode(array_column($found, 'title')));
    check('  it is “likely” (a strong match, 0.4 s off); the radio version, 92 s off, only “check”', $found[0]['confidence'] === 'likely' && abs($found[0]['diff'] - 0.4) < 0.05 && $found[1]['confidence'] === 'check' && $found[1]['diff'] > 90);
    check('  each carries its releases and first year', $found[0]['year'] === '2017' && count($found[0]['releases']) >= 1 && isset($found[0]['releases'][0]['mbid']));
    check('  the request names the title and the artist, and says who asks', count($calls) === 1 && str_contains(rawurldecode($calls[0]['url']), 'recording:"Reckless Love" AND artist:"Cory Asbury"') && str_contains($calls[0]['ua'], 'Stationwagon/0.9.10') && str_starts_with($calls[0]['url'], 'https://musicbrainz.org/ws/2/'));
    $byIsrc = $mb->search('', '', 'US-WFE-17-90445', null);
    check('an ISRC finds the recording that carries it, “likely” with no length needed', count($byIsrc) === 1 && $byIsrc[0]['confidence'] === 'likely' && $byIsrc[0]['title'] === 'Reckless Love');
    $n = count($calls);
    $again = $mb->search('Reckless Love', 'Cory Asbury', '', 333.0);
    check('asking again costs no request (kept for 30 days)', count($calls) === $n && $again[0]['mbid'] === $found[0]['mbid']);
    check('no title and no ISRC is refused', (function () use ($mb) { try { $mb->search('  ', 'x'); return false; } catch (RuntimeException $e) { return str_contains($e->getMessage(), 'title'); } })());

    echo "The details of a recording\n";
    $calls = [];
    $d = $mb->details('0df9f5b3-a8c0-4be3-8cbb-6aae9a84c322');
    check('the ISRC, album, year and label from the recording and its best release', $d['isrc'] === 'USWFE1790445' && $d['album'] === 'Reckless Love' && $d['year'] === '2017' && $d['label'] === 'Bethel Music', json_encode(array_intersect_key($d, array_flip(['isrc', 'album', 'year', 'label']))));
    check('  the track number', $d['track'] !== '' && preg_match('/^\d+(\/\d+)?$/', $d['track']) === 1, $d['track']);
    check('  the release, with its address on MusicBrainz and whether it has a cover', $d['release']['mbid'] === '79381438-6273-4ff0-8035-a4d7bb6fa8ef' && str_starts_with($d['release']['url'], 'https://musicbrainz.org/release/') && $d['cover'] === true);
    check('  the releases to choose from, official ones first', count($d['releases']) >= 1 && $d['releases'][0]['mbid'] === $d['release']['mbid'] && $d['releases'][0]['status'] === 'Official');
    check('this recording has no work linked, so the work of the same name is offered as a guess, and says so', $d['work'] !== null && $d['work']['linked'] === false && $d['work']['title'] === 'Reckless Love');
    check('  with its ISWC, writers and publishers', $d['iswc'] === 'T-921.709.275-6' && $d['writers'] === ['Cory Asbury', 'Caleb Culver', 'Ran Jackson'] && count($d['publishers']) === 5 && in_array('Bethel Music Publishing', $d['publishers'], true) && $d['lyricists'] === [], json_encode([$d['writers'], $d['publishers']]));
    check('  the source: the recording’s page on MusicBrainz', $d['recording']['url'] === 'https://musicbrainz.org/recording/0df9f5b3-a8c0-4be3-8cbb-6aae9a84c322');
    check('a lookup is a few requests (recording, release, work search, work), all to MusicBrainz over https', count($calls) === 4 && !array_filter($calls, fn($c) => !str_starts_with($c['url'], 'https://musicbrainz.org/ws/2/')), (string) count($calls));
    $gaps = [];
    for ($i = 1; $i < count($calls); $i++) { $gaps[] = $calls[$i]['at'] - $calls[$i - 1]['at']; }
    check('  with at least a second between them (MusicBrainz allows about one a second)', $gaps && min($gaps) >= 1.0, implode(' ', array_map(fn($g) => round($g, 2), $gaps)));
    $n = count($calls);
    $mb->details('0df9f5b3-a8c0-4be3-8cbb-6aae9a84c322');
    check('  and the same lookup again is answered from what is kept', count($calls) === $n);
    check('a release the person picked is used instead of the best', (function () use ($mb, $d) {
        try { $mb->details('0df9f5b3-a8c0-4be3-8cbb-6aae9a84c322', 'not-an-id'); return false; } catch (RuntimeException $e) { return str_contains($e->getMessage(), 'MusicBrainz id'); }
    })());

    echo "When it goes wrong\n";
    $busy = new MusicBrainz($root . '/x', 'Stationwagon/test ( x )', fn() => ['code' => 503, 'body' => '']);
    mkdir($root . '/x/storage', 0775, true);
    check('a busy MusicBrainz (503) says so and to try again', (function () use ($busy) { try { $busy->search('Any Song'); return false; } catch (RuntimeException $e) { return str_contains($e->getMessage(), 'busy') && str_contains($e->getMessage(), 'minute'); } })());
    $down = new MusicBrainz($root . '/x', 'Stationwagon/test ( x )', fn() => ['code' => 0, 'body' => '']);
    check('  no answer at all says the server may not reach the internet', (function () use ($down) { try { $down->search('Another Song'); return false; } catch (RuntimeException $e) { return str_contains($e->getMessage(), 'internet'); } })());
    $junk = new MusicBrainz($root . '/x', 'Stationwagon/test ( x )', fn() => ['code' => 200, 'body' => '<html>nope</html>']);
    check('  an answer that isn’t data is said to be unreadable', (function () use ($junk) { try { $junk->search('Third Song'); return false; } catch (RuntimeException $e) { return str_contains($e->getMessage(), 'couldn’t read'); } })());
    $none = new MusicBrainz($root . '/x', 'Stationwagon/test ( x )', fn() => ['code' => 200, 'body' => '{"recordings":[]}']);
    check('a song nobody has is an empty list, not an error', $none->search('A Song Nobody Has') === []);
    check('a failed request is not kept (the next try asks again)', (function () use ($root) {
        $n = 0;
        $flaky = new MusicBrainz($root . '/x', 'Stationwagon/test ( x )', function () use (&$n) { $n++; return $n === 1 ? ['code' => 503, 'body' => ''] : ['code' => 200, 'body' => '{"recordings":[]}']; });
        try { $flaky->search('Flaky Song'); } catch (RuntimeException) { }
        $flaky->search('Flaky Song');
        return $n === 2;
    })());

    echo "Cover art\n";
    $png = (function () { $im = imagecreatetruecolor(40, 40); ob_start(); imagepng($im); return (string) ob_get_clean(); })();
    $rel = '79381438-6273-4ff0-8035-a4d7bb6fa8ef';
    $seen = null;
    $cov = new MusicBrainz($root . '/c', 'Stationwagon/test ( x )', function (string $url) use ($png, &$seen) { $seen = $url; return ['code' => 200, 'body' => $png]; });
    mkdir($root . '/c/storage', 0775, true);
    check('the front cover is the Cover Art Archive’s 500 px picture, as bytes', $cov->cover($rel) === $png && $seen === "https://coverartarchive.org/release/$rel/front-500");
    $text = new MusicBrainz($root . '/c', 'Stationwagon/test ( x )', fn() => ['code' => 200, 'body' => 'not a picture']);
    $gone = new MusicBrainz($root . '/c', 'Stationwagon/test ( x )', fn() => ['code' => 404, 'body' => '']);
    check('  something that isn’t a picture, or a release without a cover, gives nothing', $text->cover($rel) === null && $gone->cover($rel) === null);
    check('  and only a MusicBrainz id is asked for', (function () use ($cov) { try { $cov->cover('../../x'); return false; } catch (RuntimeException) { return true; } })());
} finally {
    remove_tree($root);
}
finish();
