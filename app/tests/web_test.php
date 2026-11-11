<?php
// End-to-end test of the sign-in pages over HTTP, the way a browser uses them (cookies, form
// keys, redirects).
//   php app/tests/web_test.php <base url> [--public]
//   php app/tests/web_test.php http://localhost:8080/stationwagon-app
// Full mode makes a throwaway DJ account in this copy's database (config.local.php next to it,
// so the base URL must serve this same copy), signs in with it and deletes it afterwards.
// --public only checks what anyone can see without an account; use it against a public server.
// On Windows, curl checks https certificates against the Windows store (Laragon's PHP has no CA
// bundle of its own); SW_TEST_CAINFO can point it at a bundle file instead.
declare(strict_types=1);
require __DIR__ . '/lib.php';
use Stationwagon\{Config, Db};

$base = rtrim((string) ($argv[1] ?? ''), '/');
$public = in_array('--public', $argv, true);
if ($base === '' || !extension_loaded('curl')) {
    fwrite(STDERR, "Usage: php tests/web_test.php <base url> [--public]  (needs the curl extension)\n");
    exit(1);
}
$https = str_starts_with($base, 'https://');
$basePath = (string) parse_url($base, PHP_URL_PATH);

/** A tiny browser: one cookie jar, no automatic redirects. */
final class Browser
{
    public array $cookies = [];
    public array $setCookies = [];
    public array $allSetCookies = [];

    public function __construct(private readonly string $base)
    {
    }

    /** @return array{status:int,headers:array<string,list<string>>,body:string} */
    /** $post: form fields, or a string sent as the raw body (JSON or a live piece). */
    public function go(string $path, array|string|null $post = null, array $extra = []): array
    {
        $ch = curl_init($this->base . '/' . ltrim($path, '/'));
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
            // the test mark keeps this run's pings out of the station's listener stats (src/Agent.php)
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; web_test.php) StationwagonTest',
            CURLOPT_HTTPHEADER => array_merge($extra, $this->cookies ? ['Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies))] : []),
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))][] = trim($v);
                }
                return strlen($line);
            },
        ]);
        if (getenv('SW_TEST_CAINFO')) {
            curl_setopt($ch, CURLOPT_CAINFO, getenv('SW_TEST_CAINFO'));
        } elseif (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            // A file to upload makes it a multipart form, like a browser's
            $multipart = is_array($post) && array_filter($post, fn($v) => $v instanceof CURLFile);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($post) || $multipart ? $post : http_build_query($post));
        }
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($status === 0) {
            fwrite(STDERR, 'Request failed: ' . curl_error($ch) . PHP_EOL);
        }
        curl_close($ch);
        $this->setCookies = $headers['set-cookie'] ?? [];
        array_push($this->allSetCookies, ...$this->setCookies);
        foreach ($this->setCookies as $c) {
            [$pair] = explode(';', $c, 2);
            [$k, $v] = array_map('trim', explode('=', $pair, 2) + [1 => '']);
            if ($v === '' || $v === 'deleted') { unset($this->cookies[$k]); } else { $this->cookies[$k] = $v; }
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    public static function csrf(array $r): string
    {
        return preg_match('/name="csrf" value="([0-9a-f]{64})"/', $r['body'], $m) ? $m[1] : '';
    }
}

$location = fn(array $r) => $r['headers']['location'][0] ?? '';
$created = null;
$db = null;
echo "Testing $base" . ($public ? " (public checks only)" : '') . "\n";

try {
    echo "Anyone\n";
    $b = new Browser($base);
    $r = $b->go('');
    check('station page opens', $r['status'] === 200, (string) $r['status']);
    check('listeners get no cookie', $b->setCookies === [], implode(' | ', $b->setCookies));
    $hb = new Browser($base);
    $hb->cookies['sw_staff'] = '1';
    $hm = $hb->go('api/me');
    check('api/me for someone signed out: signedIn false, never cached, the stale hint removed, no session', $hm['status'] === 200 && (json_decode($hm['body'], true)['signedIn'] ?? null) === false
        && str_contains(implode(',', $hm['headers']['cache-control'] ?? []), 'no-store') && !isset($hb->cookies['sw_staff']) && !isset($hb->cookies['sw_session']), $hm['body'] . ' ' . implode(',', $hm['headers']['cache-control'] ?? []));
    check('station page links to sign in', str_contains($r['body'], 'href="' . $basePath . '/signin"'));
    check('station page is the listener player', str_contains($r['body'], 'id="player"') && str_contains($r['body'], 'data-delay="20"') && str_contains($r['body'], 'assets/player.js'));
    check('with today’s schedule', str_contains($r['body'], '<h2>Today · '));
    check('and a Diagnostics panel, closed', (bool) preg_match('~<details class="lp-win lp-diag" id="lpDiag">~', $r['body']) && str_contains($r['body'], 'assets/diag.js'));
    echo "Diagnostics reports\n";
    $d = new Browser($base);
    if (!$public) { // this copy: clear this computer's hourly count, so test runs don't run into it
        foreach (['127.0.0.1', '::1'] as $a) { @unlink(SW_ROOT . '/storage/diag/.limit-' . substr(hash('sha256', 'sw-diag|' . $a), 0, 16) . '.json'); }
    }
    check('reports are only sent, never read back', $d->go('api/diag.php')['status'] === 405);
    $dr = $d->go('api/diag.php', '{"report": ""}', ['Content-Type: application/json']);
    check('an empty report is refused', $dr['status'] === 400, (string) $dr['status']);
    $dr = $d->go('api/diag.php', json_encode(['report' => "web_test report\nAll good"]), ['Content-Type: application/json']);
    $diagId = (string) (json_decode($dr['body'], true)['id'] ?? '');
    check('a report is kept and answered with its number', $dr['status'] === 200 && preg_match('~^\d{8}-\d{6}-[0-9a-f]{6}$~', $diagId), $dr['status'] . ' ' . $dr['body']);
    check('  and nobody can open it from the web', $diagId !== '' && $d->go("storage/diag/$diagId.txt")['status'] === 403);
    check('listeners get no cookie from it', $d->setCookies === []);
    if (!$public && $diagId !== '' && is_file(SW_ROOT . "/storage/diag/$diagId.txt")) {
        unlink(SW_ROOT . "/storage/diag/$diagId.txt"); // the test's own report
    }
    // scripts and styles are tagged by content, so a phone never keeps an old copy (the host says a week)
    preg_match('~assets/player\.js\?v=([0-9a-f]{10})"~', $r['body'], $pm);
    preg_match('~assets/app\.css\?v=([0-9a-f]{10})"~', $r['body'], $cm);
    // fetched by the tagged address, as browsers do (a CDN may still hold an old copy of the bare one)
    $tag = fn (string $path, string $v) => substr(sha1(sha1($b->go("$path?v=$v")['body'])), 0, 10);
    check('the player and style links carry their content tags', ($pm[1] ?? '') !== '' && $pm[1] === $tag('assets/player.js', $pm[1]) && ($cm[1] ?? '') !== '' && $cm[1] === $tag('assets/app.css', $cm[1]), ($pm[1] ?? '-') . ' ' . ($cm[1] ?? '-'));
    foreach (['assets/player.js', 'assets/vendor/hls.min.js'] as $asset) {
        $a = $b->go($asset);
        check("$asset is served", $a['status'] === 200 && strlen($a['body']) > 1000, (string) $a['status']);
    }
    $r = $b->go('manage');
    check('manager page sends you to sign in', $r['status'] === 303 && str_ends_with($location($r), '/signin?next=manage'), $location($r));
    $r = $b->go('manage/songprep');
    check('Song prep sends you to sign in, and so does a song’s original', $r['status'] === 303 && str_ends_with($location($r), '/signin?next=manage%2Fsongprep')
        && $b->go('manage/songprep/original?id=1')['status'] === 303, $location($r));
    $r = $b->go('account');
    check('account page sends you to sign in, then back', $r['status'] === 303 && str_ends_with($location($r), '/signin?next=account'), $location($r));
    $r = $b->go('studio');
    check('the studio sends you to sign in', $r['status'] === 303 && str_ends_with($location($r), '/signin?next=studio'), $location($r));
    $r = $b->go('studio/api/state');
    check('  and its feed answers 401, as JSON', $r['status'] === 401 && isset(json_decode($r['body'], true)['error']), (string) $r['status']);
    // as the studio sends it: a full piece raw, a short one as base64 in JSON (a small binary body gets 406 from
    // some hosts' firewalls before PHP, as on wordwave.co.za)
    $frame = "\xFF\xFB\x90\x00" . str_repeat("\0", 413);
    $r = $b->go('studio/api/piece?live=x&seq=0&dur=6', str_repeat($frame, 60), ['Content-Type: audio/mpeg']);
    $r2 = $b->go('studio/api/piece?live=x&seq=0&dur=0.03', json_encode(['b64' => base64_encode($frame)]), ['Content-Type: application/json']);
    check('  nobody signed out can send audio (a piece raw, a short one as base64)', $r['status'] === 401 && $r2['status'] === 401, $r['status'] . ', ' . $r2['status']);
    $r = $b->go('signin');
    $csrf = Browser::csrf($r);
    check('sign-in page opens with a form key', $r['status'] === 200 && strlen($csrf) === 64);
    $cookie = implode(' | ', array_filter($b->allSetCookies, fn($c) => str_starts_with($c, 'sw_session=')));
    check('session cookie is HttpOnly and SameSite=Lax', str_contains($cookie, 'sw_session=') && stripos($cookie, 'HttpOnly') !== false && stripos($cookie, 'SameSite=Lax') !== false, $cookie);
    check('session cookie is Secure on https', !$https || stripos($cookie, 'secure') !== false);
    check('session cookie only covers this app', stripos($cookie, 'path=' . $basePath . '/') !== false);
    check('sign-in page is never stored by caches', !preg_match('/public|max-age=[1-9]/', implode(',', $r['headers']['cache-control'] ?? [])), implode(',', $r['headers']['cache-control'] ?? []));
    $r = $b->go('signin', ['email' => 'a@b.test', 'password' => 'whatever pass']);
    check('form without its key is refused', $r['status'] === 400 && str_contains($r['body'], 'expired'), (string) $r['status']);
    $r = $b->go('signin', ['csrf' => $csrf, 'email' => 'nobody-' . bin2hex(random_bytes(3)) . '@example.test', 'password' => 'not a real password']);
    check('unknown account refused', $r['status'] === 401 && str_contains($r['body'], 'don’t match'), (string) $r['status']);
    check('the typed email is kept, the password is not', str_contains($r['body'], 'value="nobody-') && !str_contains($r['body'], 'not a real password'));
    $r = $b->go('signin?next=' . rawurlencode('//evil.example/steal'));
    check('a link to another site after sign-in is ignored', str_contains($r['body'], 'name="next" value="manage"'));
    $r = $b->go('signout', ['csrf' => 'wrong']);
    check('sign-out without the right key does nothing harmful', $r['status'] === 303 && str_ends_with($location($r), '/signin'));
    echo "Stream\n";
    $s = new Browser($base);
    $r = $s->go('api/stream.php');
    $cc = implode(',', $r['headers']['cache-control'] ?? []);
    check('playlist is an HLS playlist', in_array($r['status'], [200, 503], true) && str_starts_with($r['body'], '#EXTM3U') && str_contains(implode(',', $r['headers']['content-type'] ?? []), 'mpegurl'), (string) $r['status']);
    check('playlist may be kept 2 s by Cloudflare, never by browsers', str_contains($cc, 's-maxage=2') && str_contains($cc, 'max-age=0'), $cc);
    check('listeners get no cookie from the stream', $s->setCookies === []);
    if ($r['status'] === 200) {
        preg_match_all('/^#EXT-X-BYTERANGE:(\d+)@(\d+)\n(\S+)$/m', $r['body'], $m);
        $count = substr_count($r['body'], '#EXTINF:');
        check('playlist lists the last 5 pieces (fewer just after the station starts)', $count >= 1 && $count <= 5, "$count");
        if ($m[0]) {
            $len = (int) $m[1][0]; $off = (int) $m[2][0];
            $file = (new Browser($base))->go('api/' . $m[3][0], null, ['Range: bytes=' . $off . '-' . ($off + $len - 1)]);
            check('a piece is served as a byte range', $file['status'] === 206 && strlen($file['body']) === $len, $file['status'] . ', ' . strlen($file['body']) . " of $len bytes");
            check('and starts on an MP3 frame', strlen($file['body']) > 1 && ord($file['body'][0]) === 0xFF && (ord($file['body'][1]) & 0xE0) === 0xE0);
            $fcc = implode(',', $file['headers']['cache-control'] ?? []);
            check('songs are cached for a year', str_contains($fcc, 'max-age=31536000') && str_contains($fcc, 'immutable'), $fcc);
        }
    } else {
        check('with an empty library the playlist says so', str_contains($r['body'], 'library'));
    }
    $r = $s->go('api/timeline.php');
    $j = json_decode($r['body'], true);
    // a real station may have its own crossfade (wordwave.co.za: 8 s); a test install has the default 6 s
    $xf = $j['settings']['crossfade_seconds'] ?? null;
    check('player feed gives the server clock and settings', $r['status'] === 200 && is_float($j['server_now'] ?? null) && abs($j['server_now'] - microtime(true)) < 30 && ($public ? is_numeric($xf) && $xf >= 0 && $xf <= 30 : $xf == 6), $r['status'] . ', crossfade ' . var_export($xf, true));
    check('player feed is never cached', str_contains(implode(',', $r['headers']['cache-control'] ?? []), 'no-store'));
    // the player reads its songs ahead for the output strip: songs near now come with their pieces,
    // and a piece is a byte range the web server serves on its own
    $songs = array_values(array_filter($j['entries'] ?? [], fn($e) => $e['type'] === 'song'));
    $near = array_values(array_filter($songs, fn($e) => $e['end'] > $j['server_now'] - 60 && $e['start'] < $j['server_now'] + 30));
    $far = array_values(array_filter($songs, fn($e) => $e['start'] > $j['server_now'] + 120));
    $withPieces = array_values(array_filter($near, fn($e) => !empty($e['pieces'])));
    if ($near) {
        check('songs near now come with their pieces (for the look-ahead)', count($withPieces) === count($near) && count($withPieces[0]['pieces'][0]) === 3, count($withPieces) . ' of ' . count($near));
        check('  songs far ahead don\'t (the feed stays small)', !$far || !array_filter($far, fn($e) => isset($e['pieces'])));
        [$off, $len] = $withPieces[0]['pieces'][1] ?? $withPieces[0]['pieces'][0];
        $rr = $s->go($withPieces[0]['url'], null, ['Range: bytes=' . $off . '-' . ($off + $len - 1)]);
        check('  a piece comes back as a byte range of the song file', $rr['status'] === 206 && strlen($rr['body']) === $len && ord($rr['body'][0]) === 0xFF, $rr['status'] . ', ' . strlen($rr['body']) . ' of ' . $len . ' bytes');
    }
    $r2 = $s->go('api/notes.php');
    $nj = json_decode($r2['body'], true);
    check('presenter notes feed answers, with the same marker as the timeline', $r2['status'] === 200 && is_array($nj['items'] ?? null) && array_key_exists('show', $nj ?? []) && preg_match('/^\d+\.\d+\.\d+\.\d{8}$/', (string) ($j['notes_rev'] ?? '')) === 1 && ($nj['rev'] ?? '') === $j['notes_rev'], ($j['notes_rev'] ?? '-') . ' / ' . ($nj['rev'] ?? '-'));
    check('  and is never cached', str_contains(implode(',', $r2['headers']['cache-control'] ?? []), 'no-store'));
    $page = $s->go('')['body'];
    check('the station page has the notes panel (hidden until there are notes)', str_contains($page, 'id="lpNotes"') && preg_match('~assets/notes\.js\?v=[0-9a-f]{10}"~', $page) === 1);
    echo "What the apps read (schedule, podcast, verse of the day)\n";
    $r = $s->go('api/schedule.php');
    $sj = json_decode($r['body'], true);
    check('the schedule answers as JSON for any website', $r['status'] === 200 && ($r['headers']['access-control-allow-origin'][0] ?? '') === '*' && str_contains(implode(',', $r['headers']['content-type'] ?? []), 'json') && str_contains(implode(',', $r['headers']['cache-control'] ?? []), 'max-age=60'));
    check('  with the station’s time zone, the clock and the blocks (ISO weekdays, HH:MM)', isset($sj['station']['timezone']) && abs(($sj['now'] ?? 0) - time()) < 30 && is_array($sj['blocks'] ?? null)
        && array_reduce($sj['blocks'] ?? [], fn($ok, $b) => $ok && in_array($b['kind'], ['live', 'playlist'], true) && preg_match('/^\d\d:\d\d$/', $b['start']) === 1 && preg_match('/^\d\d:\d\d$/', $b['end']) === 1 && $b['days'] !== [] && max($b['days']) <= 7, true));
    $r = $s->go('api/podcast.php');
    $pj = json_decode($r['body'], true);
    $feedBase = rtrim($base, '/') . '/';
    check('the podcast list answers as JSON with absolute addresses of the app (not of its api folder)', $r['status'] === 200 && ($r['headers']['access-control-allow-origin'][0] ?? '') === '*' && ($pj['podcast']['feed'] ?? '') === $feedBase . 'podcast/feed' && is_array($pj['episodes'] ?? null)
        && array_reduce($pj['episodes'] ?? [], fn($ok, $e) => $ok && str_starts_with($e['audio'], $feedBase . 'storage/podcasts/'), true), $r['body']);
    $r = $s->go('api/podcast.php?episode=ep-20200101-00000000');
    check('  an unknown episode is a 404 with an error', $r['status'] === 404 && isset(json_decode($r['body'], true)['error']));
    $r = $s->go('api/verse.php?lang=en');
    $ve = json_decode($r['body'], true);
    check('the verse of the day: a reference, a link to read it, and the text when the server could fetch it', $r['status'] === 200 && ($r['headers']['access-control-allow-origin'][0] ?? '') === '*' && preg_match('/^\d{4}-\d\d-\d\d$/', (string) ($ve['date'] ?? '')) === 1
        && ($ve['reference'] ?? '') !== '' && str_starts_with((string) ($ve['read'] ?? ''), 'https://www.biblegateway.com/passage/?search=') && (($ve['text'] ?? null) === null || ($ve['version'] ?? '') === 'WEB'), $r['body']);
    $af = json_decode($s->go('api/verse.php?lang=af')['body'], true);
    check('  in Afrikaans: the same day’s verse, no text, a link to the Afrikaans Bible', ($af['lang'] ?? '') === 'af' && array_key_exists('text', $af ?? []) && $af['text'] === null && str_contains((string) ($af['read'] ?? ''), 'version=AFR53') && ($af['date'] ?? '') === ($ve['date'] ?? 'x'));
    check('  nothing in storage is served but the station’s songs, pictures and episodes', $s->go('storage/cache/schema-version')['status'] === 403 && $s->go('storage/slides/notes.txt')['status'] === 403);
    if (!$public) {
        // a slide picture is served (this copy's own storage folder; the base URL serves it)
        $dir = dirname(__DIR__) . '/storage/slides';
        @mkdir($dir, 0775, true);
        $im = imagecreatetruecolor(32, 18);
        ob_start(); imagejpeg($im); $jpeg = (string) ob_get_clean();
        $slide = 'slide-' . substr(sha1($jpeg), 0, 12) . '.jpg';
        file_put_contents("$dir/$slide", $jpeg);
        $r = $s->go("storage/slides/$slide");
        check('  a slide picture is served as an image, kept for good', $r['status'] === 200 && str_contains(implode(',', $r['headers']['content-type'] ?? []), 'image/jpeg') && $r['body'] === $jpeg, (string) $r['status']);
        unlink("$dir/$slide");
    }
    echo "Now playing and the embed\n";
    $r = $s->go('api/nowplaying.php');
    $np = json_decode($r['body'], true);
    $tl = json_decode($s->go('api/timeline.php')['body'], true);
    check('now-playing answers as JSON', $r['status'] === 200 && is_array($np) && str_contains(implode(',', $r['headers']['content-type'] ?? []), 'json'), (string) $r['status']);
    check('  any website may read it (CORS)', ($r['headers']['access-control-allow-origin'][0] ?? '') === '*');
    $ncc = implode(',', $r['headers']['cache-control'] ?? []);
    check('  browsers always ask again; Cloudflare may keep it 2 s', str_contains($ncc, 'max-age=0') && str_contains($ncc, 's-maxage=2'), $ncc);
    if (isset($r['headers']['cf-cache-status'])) {
        // behind Cloudflare its Cache Rule covers nowplaying.php too: a second ask straight after is served from the edge
        // (a few asks: the 2 s can run out between two of them, and an edge has several machines that each fill on their own)
        $seen = [$r['headers']['cf-cache-status'][0] ?? ''];
        for ($i = 0; $i < 4 && !in_array('HIT', $seen, true); $i++) {
            $seen[] = $s->go('api/nowplaying.php')['headers']['cf-cache-status'][0] ?? '';
        }
        check('  Cloudflare keeps it (the embeds’ every-15 s asks mostly don’t reach PHP)', in_array('HIT', $seen, true), implode(' → ', $seen));
    }
    check('  it is told at the listener delay', ($np['delay'] ?? 0) === 20 && abs(($np['server_now'] ?? 0) - ($np['heard_at'] ?? 0) - 20) < 0.2 && abs(($np['server_now'] ?? 0) - microtime(true)) < 30);
    check('  with the station, now, next and recent', isset($np['station']['name']) && array_key_exists('on_air', $np) && is_array($np['next'] ?? null) && is_array($np['recent'] ?? null));
    $heardEntry = null;
    foreach ($tl['entries'] ?? [] as $e) {
        if ($e['start'] <= $np['heard_at'] && ($np['heard_at'] < $e['end'] || ($e['type'] === 'live' && !empty($e['open'])))) {
            $heardEntry = $e;
            break;
        }
    }
    check('  on air is what the timeline plays at the listener delay', $heardEntry !== null && ($np['on_air']['title'] ?? null) === $heardEntry['title'] && abs($np['on_air']['started'] - $heardEntry['start']) < 0.2,
        ($np['on_air']['title'] ?? '-') . ' / ' . ($heardEntry['title'] ?? '-'));
    $songsAfter = array_values(array_filter($tl['entries'] ?? [], fn($e) => $e['type'] === 'song' && $heardEntry && $e['start'] > $heardEntry['start']));
    check('  next is the coming songs, in order', $np['next'] && $songsAfter && $np['next'][0]['title'] === $songsAfter[0]['title'] && abs($np['next'][0]['starts'] - $songsAfter[0]['start']) < 0.2 && count($np['next']) <= 3);
    $starts = array_column($np['recent'], 'started');
    $sorted = $starts;
    rsort($sorted);
    check('  recent songs are newest first and already over', count($starts) <= 5 && $starts === $sorted && !array_filter($starts, fn($t) => $t >= $np['on_air']['started']), implode(' ', $starts));
    check('  no file addresses in it', !str_contains($r['body'], '.mp3') && !str_contains(str_replace(['storage\/art\/', 'storage/art/'], '', $r['body']), 'storage/'));
    $e = new Browser($base);
    $r = $e->go('embed');
    check('the embed page opens', $r['status'] === 200 && str_contains($r['body'], 'id="embed"') && preg_match('~assets/embed\.js\?v=[0-9a-f]{10}"~', $r['body']) === 1, (string) $r['status']);
    check('  with the station page\'s player (two decks, crossfades: round 60) and the listener delay', preg_match('~assets/player\.js\?v=[0-9a-f]{10}"~', $r['body']) === 1 && preg_match('~assets/fades\.js\?v=[0-9a-f]{10}"~', $r['body']) === 1
        && preg_match('~id="embed"[^>]*data-delay="\d+"~', $r['body']) === 1 && strpos($r['body'], 'assets/player.js') < strpos($r['body'], 'assets/embed.js'));
    check('  without the site header, and no cookie', !str_contains($r['body'], 'class="brand"') && $e->setCookies === []);
    check('  any website may frame it', !isset($r['headers']['x-frame-options']) && str_contains(implode(',', $r['headers']['content-security-policy'] ?? []), 'frame-ancestors *'),
        implode(',', $r['headers']['x-frame-options'] ?? []) . ' | ' . implode(',', $r['headers']['content-security-policy'] ?? []));
    check('  with the full layout’s parts for a tall frame: album art, the copyright line, the spectrum and the strip', str_contains($r['body'], 'id="emArt"') && str_contains($r['body'], 'class="cd-disc"') && str_contains($r['body'], 'id="emRights"') && str_contains($r['body'], 'id="emSpec"') && str_contains($r['body'], 'id="emWave"')
        && preg_match('~assets/spectrum\.js\?v=[0-9a-f]{10}"~', $r['body']) === 1 && preg_match('~assets/outwave\.js\?v=[0-9a-f]{10}"~', $r['body']) === 1);
    check('  ?look=light gives the light look', str_contains($e->go('embed?look=light')['body'], '<html lang="en" class="light">') && !str_contains($r['body'], 'class="light"'));
    // round 61: the bar under the staff's screens (the embed's long, flat form) and the small Now playing card
    $r = $e->go('bar');
    check('the bar opens: the station page\'s player, long and flat, with a speaker and its volume slider, no play button', $r['status'] === 200 && str_contains($r['body'], 'class="em bar"')
        && str_contains($r['body'], 'data-kind="bar"') && str_contains($r['body'], 'id="emMute"') && str_contains($r['body'], 'id="emVol"') && !str_contains($r['body'], 'id="emPlay"')
        && preg_match('~assets/player\.js\?v=[0-9a-f]{10}"~', $r['body']) === 1 && preg_match('~assets/embed\.js\?v=[0-9a-f]{10}"~', $r['body']) === 1, (string) $r['status']);
    check('  only the station itself may frame it', str_contains(implode(',', $r['headers']['content-security-policy'] ?? []), "frame-ancestors 'self'"), implode(',', $r['headers']['content-security-policy'] ?? []));
    $r = $e->go('now?link=' . rawurlencode('https://radio.example.org/listen/'));
    check('the Now playing card opens, with its link and no sound', $r['status'] === 200 && str_contains($r['body'], 'id="nwLink" href="https://radio.example.org/listen/" target="_top"')
        && preg_match('~assets/now\.js\?v=[0-9a-f]{10}"~', $r['body']) === 1 && !str_contains($r['body'], 'player.js') && !str_contains($r['body'], '<audio'), (string) $r['status']);
    check('  any website may frame it', str_contains(implode(',', $r['headers']['content-security-policy'] ?? []), 'frame-ancestors *'));
    $r = $e->go('now?link=' . rawurlencode('javascript:alert(1)') . '&target=new');
    check('  a link that isn\'t http(s) is refused (the station page instead); ?target=new opens a new tab', str_contains($r['body'], 'id="nwLink" href="' . $basePath . '/" target="_blank" rel="noopener"'));
    check('  signed out, a screen opened in a browser isn\'t framed: the sign-in page', !str_contains((new Browser($base))->go('manage/library', null, ['Sec-Fetch-Dest: document'])['body'], 'id="swScreen"'));
    check('the station page no longer has the embed-code card (the user, 2026-11-02)', !str_contains($page, 'id="lpEmbed"') && !str_contains($page, 'Put the player on your website'));
    echo "Listener count\n";
    $json = ['Content-Type: application/json'];
    $sid = bin2hex(random_bytes(8));
    $lb = new Browser($base);
    $r = $lb->go('api/listen.php', json_encode(['sid' => $sid, 'source' => 'player']), $json);
    $n = json_decode($r['body'], true)['listening'] ?? null;
    check('a ping from a player counts it as listening', $r['status'] === 200 && is_int($n) && $n >= 1, $r['status'] . ' ' . $r['body']);
    check('  with no cookie, and never cached', $lb->setCookies === [] && str_contains(implode(',', $r['headers']['cache-control'] ?? []), 'no-store'));
    $tl = json_decode($lb->go('api/timeline.php')['body'], true);
    check('  the player feed says how many are listening', is_int($tl['listeners'] ?? null) && $tl['listeners'] >= 1, (string) ($tl['listeners'] ?? '-'));
    check('  and so does now playing', is_int(json_decode($lb->go('api/nowplaying.php')['body'], true)['listeners'] ?? null));
    $r = $lb->go('api/listen.php', json_encode(['sid' => $sid, 'source' => 'player', 'stop' => true]), ['Content-Type: text/plain']);
    check('Stop (sent as a beacon) answers the new count', $r['status'] === 200 && (json_decode($r['body'], true)['listening'] ?? -1) === $n - 1, $r['body']);
    check('a ping with a made-up id is refused', $lb->go('api/listen.php', json_encode(['sid' => 'me', 'source' => 'player']), $json)['status'] === 400);
    check('  and listening is counted only by POST', $lb->go('api/listen.php')['status'] === 405);
    check('the station page shows “Listening now” and loads the ping script', str_contains($page, 'id="lpListeners"') && preg_match('~assets/listeners\.js\?v=[0-9a-f]{10}"~', $page) === 1);
    check('  and so does the embed', preg_match('~assets/listeners\.js\?v=[0-9a-f]{10}"~', $e->go('embed')['body']) === 1);
    $pv = new Browser($base);
    $r = $pv->go('privacy');
    check('the Privacy page says what is kept, for how long, and who to ask', $r['status'] === 200 && str_contains($r['body'], '<h1>Privacy</h1>')
        && str_contains($r['body'], '<b>30 days</b>') && str_contains($r['body'], 'IP address') && str_contains($r['body'], 'Information Regulator') && str_contains($r['body'], 'Who to ask'), (string) $r['status']);
    check('  with no cookie', $pv->setCookies === []);
    check('  linked under the player and in the embed', str_contains($page, 'class="lp-foot"') && str_contains($page, 'href="' . $basePath . '/privacy"')
        && str_contains($e->go('embed')['body'], 'class="em-priv" href="' . $basePath . '/privacy"'));
    $pc = new Browser($base);
    $r = $pc->go('podcast');
    check('the podcast page opens for anyone, with its feed', $r['status'] === 200 && str_contains($r['body'], 'class="pod"') && str_contains($r['body'], 'href="' . $basePath . '/podcast/feed"'), (string) $r['status']);
    check('  with no cookie', $pc->setCookies === []);
    check('  linked under the player', str_contains($page, 'href="' . $basePath . '/podcast"'));
    $r = $pc->go('podcast/feed');
    $xml = @simplexml_load_string($r['body']);
    check('the feed is RSS for podcast apps', $r['status'] === 200 && str_starts_with($r['headers']['content-type'][0] ?? '', 'application/rss+xml') && $xml !== false && (string) $xml->channel->title !== '',
        $r['status'] . ' ' . ($r['headers']['content-type'][0] ?? ''));
    $self = $xml ? (string) $xml->channel->children('http://www.w3.org/2005/Atom')->link->attributes()['href'] : '';
    check('  pointing at itself with a full address', preg_match('~^https?://[^/]+' . preg_quote($basePath, '~') . '/podcast/feed$~', $self) === 1, $self);
    check('  cached briefly (apps check often)', str_contains(implode(',', $r['headers']['cache-control'] ?? []), 'max-age=300'));
    $r = $pc->go('podcast/api/episode?id=1');
    check('the podcast API needs a signed-in DJ, and is never kept by caches', $r['status'] === 401 && implode(',', $r['headers']['cache-control'] ?? []) === 'private, no-store', implode(',', $r['headers']['cache-control'] ?? []));
    check('  and so does the podcast screen', $pc->go('manage/podcast')['status'] === 303);
    check('the About screen needs a sign-in too', $pc->go('manage/about')['status'] === 303);

    echo "Skins (the look, picked per device)\n";
    $sk = new Browser($base);
    $headFirst = fn(string $b): bool => ($s = strpos($b, 'localStorage.getItem("sw.skin")')) !== false && ($c = strpos($b, 'assets/app.css')) !== false && $s < $c && $s < strpos($b, '</head>');
    foreach (['' => 'the station page', 'podcast' => 'the podcast page', 'privacy' => 'the Privacy page', 'signin' => 'sign-in'] as $path => $what) {
        $b = ($path === 'signin' ? new Browser($base) : $sk)->go($path)['body']; // sign-in sets its own session cookie
        check("$what sets the device's look in <head> and has the Look picker", $headFirst($b) && str_contains($b, 'class="skinpick"') && str_contains($b, '<option value="hifi">Hi-fi</option>')
            && preg_match('~assets/skin\.js\?v=[0-9a-f]{10}"~', $b) === 1);
    }
    $b = $e->go('embed')['body'];
    check('  and the embed, with its own small Look button', $headFirst($b) && str_contains($b, 'class="skinbtn"') && preg_match('~assets/skin\.js\?v=[0-9a-f]{10}"~', $b) === 1);
    check('  picking a look sets no cookie (it stays in the browser)', $sk->setCookies === []);
    $css = $sk->go(preg_replace('~^.*?(assets/app\.css\?v=[0-9a-f]{10}).*$~s', '$1', $page))['body'];
    $css = str_replace("\r\n", "\n", $css); // a Windows checkout may serve it with CRLF
    $blocks = preg_match_all('~^(:root|:root\[data-skin="[a-z0-9-]+"\]|:root\.light) \{.*?\n\}?~ms', $css, $m);
    $rest = preg_replace('~^(:root|:root\[data-skin="[a-z0-9-]+"\]|:root\.light) \{.*?\}\n~ms', '', $css);
    preg_match_all('~#[0-9a-fA-F]{3,8}\b(?![-\w])|rgba?\(~', preg_replace('~#[a-zA-Z][\w-]*\s*[{,]~', '', $rest), $raw);
    check('the stylesheet keeps every colour in its token blocks (green, hi-fi, light): none in the components', $blocks === 3 && $raw[0] === [], $blocks . ' blocks; ' . implode(' ', array_slice($raw[0], 0, 6)));
    preg_match_all('~border-radius: (\d+px)~', $rest, $radii);
    check('  and every corner size except the tiny ones (1–2 px) and circles', array_diff(array_unique($radii[1]), ['1px', '2px']) === [], implode(' ', array_unique($radii[1])));
    foreach (['config.local.php', 'install.key', 'src/Auth.php', 'cli/set-password.php', 'cli/plan.php', 'tests/web_test.php', 'storage/sessions/', 'storage/cache/throttle/', 'storage/geo/dbip-city-lite.mmdb', 'storage/podcasts/ep-x/raw/1-music-0000.chunk', 'storage/podcasts/ep-x/episode.chunk', 'storage/originals/', 'storage/originals/0000000000000000000000000000000000000000.mp3'] as $private) {
        $code = (new Browser($base))->go($private)['status'];
        check("$private is not served", in_array($code, [403, 404], true), (string) $code);
    }

    if (!$public) {
        echo "Signed in (throwaway DJ account)\n";
        $db = Db::fromConfig();
        $email = 'web-test-' . bin2hex(random_bytes(4)) . '@example.test';
        $pass = bin2hex(random_bytes(12));
        $created = $db->insert('users', ['email' => $email, 'name' => 'Web Test DJ', 'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'role' => 'dj', 'created_at' => gmdate('Y-m-d H:i:s')]);
        $stationId = (int) $db->run('SELECT id FROM ' . $db->table('stations') . ' ORDER BY id LIMIT 1')->fetchColumn();
        $db->insert('station_users', ['station_id' => $stationId, 'user_id' => $created]);

        $a = new Browser($base);
        $csrf = Browser::csrf($a->go('signin'));
        $before = $a->cookies['sw_session'] ?? '';
        $r = $a->go('signin', ['csrf' => $csrf, 'email' => $email, 'password' => $pass, 'next' => 'manage']);
        check('right password goes to the manager', $r['status'] === 303 && str_ends_with($location($r), '/manage'), $r['status'] . ' ' . $location($r));
        check('a new session id after sign-in', ($a->cookies['sw_session'] ?? '') !== '' && ($a->cookies['sw_session'] ?? '') !== $before);
        $hint = implode(' | ', array_filter($a->setCookies, fn($x) => str_starts_with($x, 'sw_staff=')));
        check('  and the website’s hint: sw_staff=1 for the whole site, readable by its script, until the browser closes', str_starts_with($hint, 'sw_staff=1;') && preg_match('~;\s*path=/(;|$)~i', $hint) === 1
            && stripos($hint, 'HttpOnly') === false && stripos($hint, 'Max-Age') === false && stripos($hint, 'SameSite=Lax') !== false, $hint);
        $me = $a->go('api/me');
        $mj = json_decode($me['body'], true);
        check('api/me says who is signed in, with the links and a sign-out key', $me['status'] === 200 && ($mj['signedIn'] ?? null) === true && $mj['name'] === 'Web Test DJ' && $mj['role'] === 'DJ'
            && $mj['studio'] === "$basePath/studio" && $mj['manage'] === "$basePath/manage" && $mj['signout'] === "$basePath/signout" && strlen((string) $mj['csrf']) === 64, $me['body']);
        check('  never kept anywhere, and only for this site (no CORS)', str_contains(implode(',', $me['headers']['cache-control'] ?? []), 'no-store') && !isset($me['headers']['access-control-allow-origin']), implode(',', $me['headers']['cache-control'] ?? []));
        $anon = new Browser($base);
        $anon->cookies['sw_staff'] = '1';
        $am = $anon->go('api/me');
        check('  signed out: signedIn false, the stale hint removed, no session made', $am['status'] === 200 && (json_decode($am['body'], true)['signedIn'] ?? null) === false
            && !isset($anon->cookies['sw_staff']) && !isset($anon->cookies['sw_session']), $am['body'] . ' ' . implode(' | ', $anon->setCookies));
        $r = $a->go('manage');
        check('manager page greets the DJ', $r['status'] === 200 && preg_match('/Good (morning|afternoon|evening), Web</', $r['body']) === 1, (string) $r['status']);
        check('a DJ sees the overview but not the library', str_contains($r['body'], '>Overview</a>') && !str_contains($r['body'], '>Music library</a>'));
        check('a DJ can’t open the library', $a->go('manage/library')['status'] === 403);
        check('or the Ads', $a->go('manage/ads')['status'] === 403);
        check('or the Jingles', $a->go('manage/jingles')['status'] === 403);
        check('or download a clip (the Download tick)', $a->go('manage/download?id=1')['status'] === 403);
        check('or Sound & safety', $a->go('manage/sound')['status'] === 403);
        check('or Listeners', $a->go('manage/listeners')['status'] === 403);
        check('or Song prep, or a song’s original', $a->go('manage/songprep')['status'] === 403 && $a->go('manage/songprep/original?id=1')['status'] === 403);
        check('a DJ has no Skip button', !str_contains($r['body'], 'Skip this song'));
        check('a DJ can’t skip by sending the form', $a->go('manage/skip', ['csrf' => Browser::csrf($r)])['status'] === 403);
        check('the header and overview link to the studio', str_contains($r['body'], 'href="' . $basePath . '/studio">Studio</a>') && str_contains($r['body'], 'Open the studio'));
        $st = $a->go('studio');
        preg_match('/data-csrf="([0-9a-f]{64})"/', $st['body'], $sk);
        check('the studio opens for a DJ', $st['status'] === 200 && str_contains($st['body'], 'id="studio"') && isset($sk[1]), (string) $st['status']);
        check('  with its scripts tagged by content', preg_match('~assets/studio\.js\?v=[0-9a-f]{10}"~', $st['body']) && preg_match('~assets/studio-mic\.js\?v=[0-9a-f]{10}"~', $st['body']) && preg_match('~data-encoder="[^"]*assets/studio-encoder\.js\?v=[0-9a-f]{10}"~', $st['body']));
        check('  and the notes drawer', str_contains($st['body'], 'id="stDrawer"') && str_contains($st['body'], 'data-kind="verse"'));
        foreach (['assets/studio.js', 'assets/studio-mic.js'] as $asset) {
            $as = $a->go($asset);
            check("$asset is served", $as['status'] === 200 && strlen($as['body']) > 1000, (string) $as['status']);
        }
        $json = ['Content-Type: application/json', 'X-CSRF: ' . ($sk[1] ?? '')];
        $q = $a->go('studio/api/state');
        $sj = json_decode($q['body'], true);
        check('the studio feed knows the DJ and their (missing) show', $q['status'] === 200 && ($sj['me']['name'] ?? '') === 'Web Test DJ' && ($sj['slot']['may'] ?? true) === false && str_contains((string) ($sj['slot']['reason'] ?? ''), 'Ask a manager'), (string) ($sj['slot']['reason'] ?? $q['status']));
        check('  and how many are listening now', is_int($sj['listeners'] ?? null), (string) ($sj['listeners'] ?? '-'));
        check('  and is never kept by shared caches', !preg_match('/public|max-age=[1-9]/', implode(',', $q['headers']['cache-control'] ?? [])), implode(',', $q['headers']['cache-control'] ?? []));
        check('studio actions need the page’s key', $a->go('studio/api/golive', '{}', ['Content-Type: application/json'])['status'] === 400);
        $q = $a->go('studio/api/golive', '{}', $json);
        check('a DJ outside their block can’t go live', $q['status'] === 409 && str_contains((string) (json_decode($q['body'], true)['error'] ?? ''), 'Ask a manager'), $q['status'] . ' ' . $q['body']);
        $q = $a->go('studio/api/piece?live=live-nope&seq=0&dur=6', "\xFF\xFB\x90\x00" . str_repeat("\0", 413), ['Content-Type: audio/mpeg', 'X-CSRF: ' . ($sk[1] ?? '')]);
        check('  nor send audio to a show that isn’t theirs', $q['status'] === 403, (string) $q['status']);
        $q = $a->go('studio/api/songs?q=a');
        check('the studio searches the library', $q['status'] === 200 && is_array(json_decode($q['body'], true)['songs'] ?? null));
        $q = $a->go('studio/api/order', json_encode(['scope' => 'user:' . $created, 'items' => [['kind' => 'talk', 'title' => 'Welcome', 'seconds' => 120]]]), $json);
        check('a DJ saves their own running order', $q['status'] === 200 && (json_decode($q['body'], true)['items'][0]['title'] ?? '') === 'Welcome', $q['status'] . ' ' . $q['body']);
        check('  but not someone else’s', $a->go('studio/api/order', json_encode(['scope' => 'user:1', 'items' => []]), $json)['status'] === 403);
        check('unknown studio actions answer 404', $a->go('studio/api/explode')['status'] === 404);
        $pp = $a->go('manage/podcast');
        check('a DJ has the Podcast screen, with their own episodes', $pp['status'] === 200 && str_contains($pp['body'], '<h1>Podcast</h1>') && str_contains($pp['body'], '>Podcast</a>') && str_contains($pp['body'], 'You see your own shows.'), (string) $pp['status']);
        check('  but not the podcast’s details', !str_contains($pp['body'], 'class="card newform podform"'));
        $ab = $a->go('manage/about');
        check('a DJ has the About screen, last in the menu, with the station software version and the GitHub link', $ab['status'] === 200 && str_contains($ab['body'], '<h1>About</h1>') && str_contains($ab['body'], '>About</a>') && str_contains($ab['body'], 'id="aboutStation">' . Config::version() . '<') && str_contains($ab['body'], 'https://github.com/vincentrathbone-web/Stationwagon') && strrpos($ab['body'], '>About</a>') > strrpos($ab['body'], '>Podcast</a>'));
        check('  the version is no longer in the header next to the logo, and the Windows app part waits for the app', !str_contains($ab['body'], 'class="ver"') && str_contains($ab['body'], 'id="aboutCheck"') && str_contains($ab['body'], 'id="aboutUpd" hidden'));
        $other = $db->run('SELECT id FROM ' . $db->table('episodes') . " WHERE status <> 'skipped' AND (dj_user_id IS NULL OR dj_user_id <> ?) ORDER BY id DESC LIMIT 1", [$created])->fetchColumn();
        check('  an episode that isn’t theirs doesn’t open', $a->go('manage/podcast/' . ($other ?: 999999999))['status'] === 404 && $a->go('podcast/api/episode?id=' . ($other ?: 999999999))['status'] === ($other ? 403 : 404));
        check('  nor does sending a recording for a show that isn’t there', $a->go('podcast/api/rec-chunk?show=live-nope&track=music&n=0&samples=1152', "\xFF\xFB\x90\x00" . str_repeat("\0", 413), ['Content-Type: audio/mpeg', 'X-CSRF: ' . ($sk[1] ?? '')])['status'] === 404);
        check('  podcast actions need the page’s key', $a->go('podcast/api/edits', '{"id":1,"deleted":[]}', ['Content-Type: application/json'])['status'] === 400);
        check('header shows the name and sign out', str_contains($r['body'], 'Web Test DJ</a>') && str_contains($r['body'], 'Sign out'));
        $headerCsrf = Browser::csrf($r);
        $r = $a->go('signin');
        check('sign-in page sends a signed-in user on', $r['status'] === 303 && str_ends_with($location($r), '/manage'));

        $phone = new Browser($base);
        $phone->go('signin', ['csrf' => Browser::csrf($phone->go('signin')), 'email' => $email, 'password' => $pass]);
        check('a second device can sign in too', $phone->go('manage')['status'] === 200);

        $r = $a->go('account');
        // through Cloudflare, its Email Address Obfuscation swaps addresses in pages for a protected link that a script shows
        $hasEmail = fn(string $body, string $address) => str_contains($body, $address) || str_contains($body, '/cdn-cgi/l/email-protection');
        check('account page opens', $r['status'] === 200 && $hasEmail($r['body'], $email), $r['status'] . ' ' . (preg_match('~[^<>]{0,60}(?:example\.test|email-protection)[^<>]{0,40}~', $r['body'], $mm) ? $mm[0] : 'no address in the page'));
        $csrf = Browser::csrf($r);
        $r = $a->go('account', ['csrf' => $csrf, 'current_password' => 'not it at all', 'new_password' => 'a new password 1', 'confirm_password' => 'a new password 1']);
        check('wrong current password refused', $r['status'] === 422 && str_contains($r['body'], 'isn’t right'), (string) $r['status']);
        $r = $a->go('account', ['csrf' => $csrf, 'current_password' => $pass, 'new_password' => 'a new password 1', 'confirm_password' => 'a new password 2']);
        check('two different new passwords refused', $r['status'] === 422 && str_contains($r['body'], 'aren’t the same'));
        $r = $a->go('account', ['csrf' => $csrf, 'current_password' => $pass, 'new_password' => 'a new password 1', 'confirm_password' => 'a new password 1']);
        check('password changed', $r['status'] === 200 && str_contains($r['body'], 'Password changed'), (string) $r['status']);
        check('this browser is still signed in', $a->go('manage')['status'] === 200);
        check('the other device is signed out', $phone->go('manage')['status'] === 303);

        $r = $a->go('signout', ['csrf' => $headerCsrf]);
        check('sign out goes to the sign-in page', $r['status'] === 303 && str_ends_with($location($r), '/signin'));
        check('signed out for real', $a->go('manage')['status'] === 303);

        $c = new Browser($base);
        $r = $c->go('signin', ['csrf' => Browser::csrf($c->go('signin?next=account')), 'email' => strtoupper($email), 'password' => 'a new password 1', 'next' => 'account']);
        check('sign-in returns you to the page you wanted', $r['status'] === 303 && str_ends_with($location($r), '/account'), $location($r));

        $k = new Browser($base);
        $form = $k->go('signin');
        check('the sign-in page has Keep me signed in (off at first)', preg_match('/<input id="remember" name="remember" type="checkbox" value="1">/', $form['body']) === 1);
        check('  and tells password managers which fields are which', str_contains($form['body'], 'autocomplete="username"') && str_contains($form['body'], 'autocomplete="current-password"'));
        $k->go('signin', ['csrf' => Browser::csrf($form), 'email' => $email, 'password' => 'a new password 1', 'remember' => '1']);
        $rc = implode(' | ', array_filter($k->allSetCookies, fn($x) => str_starts_with($x, 'sw_remember=')));
        check('Keep me signed in: a 30-day cookie, HttpOnly, SameSite=Lax, for this app only', stripos($rc, 'Max-Age=2592000') !== false && stripos($rc, 'HttpOnly') !== false && stripos($rc, 'SameSite=Lax') !== false && stripos($rc, 'path=' . $basePath . '/') !== false && (!$https || stripos($rc, 'secure') !== false), preg_replace('/sw_remember=[^;]+/', 'sw_remember=…', $rc));
        unset($k->cookies['sw_session']); // the browser was closed
        $r = $k->go('studio');
        check('  the browser closed and opened again: the studio opens, signed in', $r['status'] === 200 && str_contains($r['body'], 'id="studio"') && isset($k->cookies['sw_session']), (string) $r['status']);
        $r = $k->go('signout', ['csrf' => Browser::csrf($k->go('manage'))]);
        check('  signing out there removes the cookie', $r['status'] === 303 && !isset($k->cookies['sw_remember']));
        unset($k->cookies['sw_session']);
        check('  and it stays signed out', $k->go('manage')['status'] === 303);
        $w = new Browser($base);
        $w->go('signin', ['csrf' => Browser::csrf($w->go('signin')), 'email' => $email, 'password' => 'a new password 1']);
        $wm = json_decode($w->go('api/me')['body'], true);
        $r = $w->go('signout', ['csrf' => $wm['csrf'] ?? '', 'back' => '/listen/']);
        check('signing out from the website’s header goes back to that page, without the hint', $r['status'] === 303 && $location($r) === '/listen/' && !isset($w->cookies['sw_staff']), $location($r));
        check('  and api/me then says signed out', (json_decode($w->go('api/me')['body'], true)['signedIn'] ?? null) === false);
        $w->go('signin', ['csrf' => Browser::csrf($w->go('signin')), 'email' => $email, 'password' => 'a new password 1']);
        $r = $w->go('signout', ['csrf' => Browser::csrf($w->go('manage')), 'back' => '//evil.example/']);
        check('  never to another site', $r['status'] === 303 && str_ends_with($location($r), "$basePath/signin"), $location($r));

        echo "Station manager (throwaway manager account)\n";
        $tag = bin2hex(random_bytes(3));
        $after = fn(Browser $b, array $r) => $b->go(ltrim(substr($location($r), strlen($basePath)), '/'));
        $flashed = fn(array $page, string $text) => str_contains($page['body'], 'toast') && str_contains($page['body'], htmlspecialchars($text, ENT_QUOTES));
        $mEmail = "web-test-$tag-mgr@example.test";
        $mPass = bin2hex(random_bytes(12));
        $users[] = $mgrId = $db->insert('users', ['email' => $mEmail, 'name' => 'Web Test Manager', 'password_hash' => password_hash($mPass, PASSWORD_DEFAULT), 'role' => 'manager', 'created_at' => gmdate('Y-m-d H:i:s')]);
        $db->insert('station_users', ['station_id' => $stationId, 'user_id' => $mgrId]);
        $m = new Browser($base);
        $m->go('signin', ['csrf' => Browser::csrf($m->go('signin')), 'email' => $mEmail, 'password' => $mPass]);
        $r = $m->go('manage');
        check('a manager sees every section', $r['status'] === 200 && str_contains($r['body'], '>Music library</a>') && str_contains($r['body'], '>Playlists</a>') && str_contains($r['body'], '>Schedule</a>') && str_contains($r['body'], '>People</a>') && str_contains($r['body'], '>Sound &amp; safety</a>'));
        $csrf = Browser::csrf($r);
        check('a manager has a Skip button', str_contains($r['body'], 'Skip this song'));
        check('the overview shows who is listening', !str_contains($r['body'], 'On air now') || !str_contains($r['body'], 'Skip this song') || (str_contains($r['body'], 'Listening now') && str_contains($r['body'], 'Most at once today')));
        $lr = $m->go('manage/listeners');
        check('Listeners opens for a manager, in the menu', $lr['status'] === 200 && str_contains($lr['body'], '>Listeners</a>') && str_contains($lr['body'], '<h1>Listeners</h1>'), (string) $lr['status']);
        check('  with a chart of 48 hours, 30 days and unique listeners', substr_count($lr['body'], 'class="lchart"') === 3 && substr_count($lr['body'], 'class="col"') === 108);
        check('  each with a table view, and unique listeners by month', substr_count($lr['body'], 'Show as a table') === 2 && str_contains($lr['body'], '<summary>By month</summary>'));
        check('  unique listeners today and this month', str_contains($lr['body'], 'Unique listeners today') && str_contains($lr['body'], 'Unique listeners in ' . date('F')));
        check('  who listens: where, device, browser, app, country, city', str_contains($lr['body'], 'id="lk-source"') && str_contains($lr['body'], 'id="lk-device"') && str_contains($lr['body'], 'id="lk-browser"')
            && str_contains($lr['body'], 'id="lk-app"') && str_contains($lr['body'], 'id="lk-country"') && str_contains($lr['body'], 'id="lk-city"'));
        check('  with DB-IP’s credit and its edition', str_contains($lr['body'], 'IP Geolocation by DB-IP</a> (CC BY 4.0), the ') && str_contains($lr['body'], 'edition, updated each month'));
        $pm = $m->go('manage/podcast');
        check('a manager’s Podcast screen has every episode and the podcast’s details, with the feed address', $pm['status'] === 200 && str_contains($pm['body'], 'You see every episode.')
            && str_contains($pm['body'], 'enctype="multipart/form-data"') && preg_match('~<code id="feedUrl">https?://[^<]+/podcast/feed</code>~', $pm['body']) === 1);
        $r = $m->go('manage/podcast', ['csrf' => Browser::csrf($pm), 'title' => 'X', 'description' => '', 'author' => 'X', 'email' => '', 'language' => 'Afrikaans', 'category' => 'Music']);
        check('  a language that isn’t a code is refused (and nothing saved)', $flashed($after($m, $r), 'Not saved: Give the language as a code'));
        // what this run's own ping kept: the address and browser details, marked as a test
        $mine = $db->run('SELECT * FROM ' . $db->table('listeners') . ' WHERE sid = ?', [$sid])->fetch();
        check('a ping keeps the address and browser details it came from', $mine && filter_var($mine['ip'], FILTER_VALIDATE_IP) && str_contains($mine['user_agent'], 'StationwagonTest') && (int) $mine['test'] === 1 && (int) $mine['ended'] === 1,
            $mine ? "{$mine['ip']} {$mine['device']} {$mine['country']}" : 'no row');
        check('  the real address, not Cloudflare’s', $mine && !Stationwagon\Request::inRanges($mine['ip'], (new ReflectionClassConstant(Stationwagon\Request::class, 'CLOUDFLARE'))->getValue()), $mine['ip'] ?? '');
        // the cap on new Listens from one address (the rows are removed again, so later runs aren't held up)
        $capIds = [];
        $capped = null;
        for ($i = 0; $i <= Stationwagon\Listeners::NEW_PER_ADDRESS && !$capped; $i++) {
            $capIds[] = $cs = bin2hex(random_bytes(8));
            $r = $lb->go('api/listen.php', json_encode(['sid' => $cs, 'source' => 'player']), $json);
            if ($r['status'] === 429) { $capped = $r; }
        }
        check('one address may start only ' . Stationwagon\Listeners::NEW_PER_ADDRESS . ' Listens in ' . (Stationwagon\Listeners::CAP_SECONDS / 60) . ' minutes (429, try later)',
            $capped !== null && ($capped['headers']['retry-after'][0] ?? '') === '600', count($capIds) . ' tries');
        $db->run('DELETE FROM ' . $db->table('listeners') . ' WHERE sid IN (' . implode(',', array_fill(0, count($capIds), '?')) . ')', $capIds);
        // the privacy contact
        $keepEmail = Stationwagon\StationSettings::privacyEmail($db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE id = ?', [$stationId])->fetch());
        $lcsrf = Browser::csrf($lr);
        check('the privacy contact refuses something that isn’t an email address', $flashed($after($m, $m->go('manage/listeners', ['csrf' => $lcsrf, 'privacy_email' => 'not an email'])), 'Not saved'));
        $r = $m->go('manage/listeners', ['csrf' => $lcsrf, 'privacy_email' => 'privacy@example.test']);
        check('  saves one', $flashed($after($m, $r), 'Saved. The Privacy page shows it now.'));
        $pb = (new Browser($base))->go('privacy')['body'];
        check('  and the Privacy page shows it', str_contains($pb, 'href="mailto:privacy@example.test"') || str_contains($pb, '/cdn-cgi/l/email-protection'),
            preg_match('~Who to ask</h2>\s*(.{0,160})~s', $pb, $mm) ? trim($mm[1]) : 'no Who to ask');
        $m->go('manage/listeners', ['csrf' => $lcsrf, 'privacy_email' => $keepEmail]);
        check('  (put back as it was)', Stationwagon\StationSettings::privacyEmail($db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE id = ?', [$stationId])->fetch()) === $keepEmail);
        $r = $m->go('manage/skip', ['csrf' => $csrf]);
        check('Skip cuts the song and says when the next starts', $r['status'] === 303 && $flashed($after($m, $r), 'Skipped. The next song starts at'));
        check('a form without its key is turned away', $flashed($after($m, $m->go('manage/skip', ['csrf' => 'nope'])), 'This form had expired'));

        // Music library
        $tmpDir = sys_get_temp_dir() . '/sw-web-test-' . getmypid();
        @mkdir($tmpDir);
        $title = "Web Test Song $tag";
        make_mp3("$tmpDir/song.mp3", 40, ['title' => $title, 'artist' => 'Test Choir'], random_int(0, 255));
        file_put_contents("$tmpDir/fake.mp3", str_repeat('not audio at all ', 500));
        $upload = function (string $file, string $name) use ($m, $csrf): array {
            $r = $m->go('manage/library', ['csrf' => $csrf, 'action' => 'upload', 'files[0]' => new CURLFile($file, 'audio/mpeg', $name)], ['Accept: application/json']);
            return (json_decode($r['body'], true)['results'] ?? [[]])[0] + ['status' => $r['status']];
        };
        $r = $m->go('manage/library');
        // round 61: a browser opening a screen gets the frame page with the bar; the apps, ?bare and the sw_bare mark don't
        $doc = ['Sec-Fetch-Dest: document'];
        $sh = $m->go('manage/library?x=1', null, $doc);
        check('a browser opening a screen gets the frame page: the screen in a frame, the bar under it', $sh['status'] === 200 && str_contains($sh['body'], 'id="swScreen"')
            && str_contains($sh['body'], 'src="' . $basePath . '/manage/library?x=1"') && str_contains($sh['body'], 'id="swBar" src="' . $basePath . '/bar"') && str_contains($sh['body'], 'microphone')
            && preg_match('~assets/shell\.js\?v=[0-9a-f]{10}"~', $sh['body']) === 1 && preg_match('~no-(store|cache)~', implode(',', $sh['headers']['cache-control'] ?? [])) === 1, $sh['status'] . ' ' . implode(',', $sh['headers']['cache-control'] ?? [])); // the station's .htaccess makes every page no-cache
        check('  the studio and the account page too; not the station page', str_contains($m->go('studio', null, $doc)['body'], 'id="swScreen"') && str_contains($m->go('account', null, $doc)['body'], 'id="swScreen"')
            && !str_contains($m->go('', null, $doc)['body'], 'id="swScreen"'));
        check('  not for the apps (their own bar), for ?bare, or with the sw_bare mark', !str_contains($m->go('manage/library', null, [...$doc, 'User-Agent: Mozilla/5.0 StationwagonStudio/0.7.5 (Android)'])['body'], 'id="swScreen"')
            && !str_contains($m->go('manage/library', null, [...$doc, 'User-Agent: Mozilla/5.0 Edg/157 StationwagonStudio/0.6.5 (Windows)'])['body'], 'id="swScreen"')
            && !str_contains($m->go('manage/library?bare=1', null, $doc)['body'], 'id="swScreen"')
            && (function () use ($m, $doc) { $m->cookies['sw_bare'] = '1'; $b = $m->go('manage/library', null, $doc)['body']; unset($m->cookies['sw_bare']); return !str_contains($b, 'id="swScreen"'); })());
        check('  nor for the screen\'s own frame (a request for a frame)', !str_contains($m->go('manage/library', null, ['Sec-Fetch-Dest: iframe'])['body'], 'id="swScreen"'));
        check('the library opens with a drop zone', $r['status'] === 200 && str_contains($r['body'], 'id="drop"') && str_contains($r['body'], 'upload.js?v=') && preg_match('~data-worker="[^"]*prep-worker\.js\?v=[0-9a-f]{10}"~', $r['body']));
        // round 64: mastering, levelling and the loudness target are the station's own (Sound & safety); the drop zone hands them to the page
        $prepAll = fn() => Stationwagon\StationSettings::prep($db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE id = ?', [$stationId])->fetch());
        [$keepMaster, $keepLevel, $keepTarget] = $prepAll();
        check('the drop zone hands the station’s choices to the page, and says them in words', str_contains($r['body'], 'data-master="' . (int) $keepMaster . '"') && str_contains($r['body'], 'data-level="' . (int) $keepLevel . '"')
            && str_contains($r['body'], 'data-target="' . $keepTarget . '"') && str_contains($r['body'], 'Prepared in your browser before upload') && str_contains($r['body'], 'Change this in Sound &amp; safety')
            && preg_match('~data-worker="[^"]*prep-worker\.js\?v=[0-9a-f]{10}"~', $r['body']) && str_contains($r['body'], 'relevel.js?v='));
        check('  the switches and the slider are not on the Music library any more', !str_contains($r['body'], 'id="prepMaster"') && !str_contains($r['body'], 'id="prepLevel"') && !str_contains($r['body'], 'id="prepTarget"') && !str_contains($r['body'], 'class="loudmark'));
        check('  the file chooser is a button like the others', str_contains($r['body'], 'class="filepick"') && str_contains($r['body'], '<label class="btn" for="files">Choose files</label>'));
        $r = $m->go('manage/library', ['csrf' => $csrf, 'action' => 'prep', 'level' => '1', 'target' => '-9'], ['Accept: application/json']);
        check('  and the old “prep” form no longer changes anything', $prepAll() === [$keepMaster, $keepLevel, $keepTarget] && !str_contains($r['body'], '"ok":true'), $r['body']);
        $r = $m->go('manage/sound');
        check('Sound & safety has them, in a Loudness group: pre-processing, normalization, and the target at the stored value with its four marked levels', $r['status'] === 200 && str_contains($r['body'], 'id="g-loudness"')
            && str_contains($r['body'], 'name="prep_master"') && str_contains($r['body'], 'name="prep_level"') && preg_match('~<input type="range" data-loud id="f-prep_target"[^>]*value="([^"]+)"~', $r['body'], $tm) === 1
            && (float) $tm[1] === $keepTarget && substr_count($r['body'], 'class="loudmark') === 4 && str_contains($r['body'], 'loudness.js?v=') && str_contains($r['body'], 'Spotify, YouTube'), $tm[1] ?? '');
        check('  and the crossfade for ads, under the crossfade between songs', preg_match('~name="crossfade_seconds".*name="ads_crossfade_seconds"~s', $r['body']) === 1 && str_contains($r['body'], 'Crossfade for ads'));
        make_mp3("$tmpDir/mastered.mp3", 20, ['title' => "Web Test Mastered " . strrev($tag)], random_int(0, 255));
        $r = $m->go('manage/library', ['csrf' => $csrf, 'action' => 'upload', 'files[0]' => new CURLFile("$tmpDir/mastered.mp3", 'audio/mpeg', 'mastered.mp3'),
            'source_sha1[0]' => sha1("mastered $tag"), 'prep_lufs[0]' => '-16', 'prep_mastered[0]' => '1'], ['Accept: application/json']);
        $mu = (json_decode($r['body'], true)['results'] ?? [[]])[0];
        $tracks[] = (int) ($mu['id'] ?? 0);
        $mrow = $db->run('SELECT mastered, loudness_lufs FROM ' . $db->table('tracks') . ' WHERE id = ?', [(int) ($mu['id'] ?? 0)])->fetch();
        check('a song prep mastered is stored as mastered', $mrow && (int) $mrow['mastered'] === 1 && (float) $mrow['loudness_lufs'] === -16.0, json_encode($mrow));
        $r = $m->go('manage/library?q=' . rawurlencode("Web Test Mastered " . strrev($tag)));
        check('  and the library tags it Mastered and Levelled, as symbols with their words on hover', str_contains($r['body'], 'sym mastered') && str_contains($r['body'], 'sym levelled') && str_contains($r['body'], 'title="Mastered with') && str_contains($r['body'], 'title="Levelled to') && !str_contains($r['body'], '>Mastered</span>') && str_contains($r['body'], 'id="badgeKey"'));
        $u = $upload("$tmpDir/song.mp3", 'my song.mp3');
        $tracks[] = $trackId = (int) ($u['id'] ?? 0);
        check('  a song uploaded without song prep is not', (int) $db->run('SELECT mastered FROM ' . $db->table('tracks') . ' WHERE id = ?', [$trackId])->fetchColumn() === 0);
        check('an MP3 uploads, with its tag title', ($u['ok'] ?? false) && !$u['duplicate'] && $u['title'] === $title, json_encode($u));
        $again = $upload("$tmpDir/song.mp3", 'copy.mp3');
        check('the same file again is “already there”', ($again['ok'] ?? false) && $again['duplicate'] && (int) $again['id'] === $trackId);
        $fake = $upload("$tmpDir/fake.mp3", 'fake.mp3');
        check('a file that isn’t audio is refused', !($fake['ok'] ?? true) && str_contains((string) $fake['error'], 'not an MP3'), (string) ($fake['error'] ?? ''));
        $txt = $upload("$tmpDir/fake.mp3", 'notes.txt');
        check('only MP3 files are taken', !($txt['ok'] ?? true) && str_contains((string) $txt['error'], 'Only songs in MP3'), (string) ($txt['error'] ?? ''));
        // other formats are converted in the browser (round 54): sent as they are, the server says so
        $wav = $upload("$tmpDir/fake.mp3", 'song.wav');
        check('  a WAV sent without the browser is refused, saying it is converted there', !($wav['ok'] ?? true) && str_contains((string) $wav['error'], 'converted to MP3 in your browser'), (string) ($wav['error'] ?? ''));
        // the upload window's details (round 56): they win over the file's own tag; a wrong code keeps the song out
        make_mp3("$tmpDir/details.mp3", 20, ['title' => "Tag Title " . strrev($tag), 'artist' => 'Tag Artist', 'writers' => 'Tag Writer'], random_int(0, 255));
        $im = imagecreatetruecolor(300, 300); imagefill($im, 0, 0, imagecolorallocate($im, 30, 140, 90)); imagejpeg($im, "$tmpDir/cover.jpg", 85);
        $details = ['title' => "Window Title " . strrev($tag), 'artist' => 'Window Artist', 'album' => 'Window Album', 'art' => 'new',
            'rights' => ['writers' => 'A. Writer', 'publisher' => 'Grace Publishing', 'licence' => 'CCLI Streaming Licence', 'licence_ref' => 'CCLI 7123456',
                'licence_url' => 'https://example.test/licence', 'licence_until' => '2027-12-31', 'isrc' => 'ZA-ABC-24-00009', 'label' => 'Grace Records', 'terms' => "Line one\nLine two"]];
        $bad = $details; $bad['rights']['isrc'] = 'not an isrc';
        $r = $m->go('manage/library', ['csrf' => $csrf, 'action' => 'upload', 'files[0]' => new CURLFile("$tmpDir/details.mp3", 'audio/mpeg', 'details.mp3'),
            'details[0]' => json_encode($bad)], ['Accept: application/json']);
        $bu = (json_decode($r['body'], true)['results'] ?? [[]])[0];
        check('an upload whose window has a wrong ISRC is refused with the reason, and not kept', !($bu['ok'] ?? true) && str_contains((string) ($bu['error'] ?? ''), 'ISRC')
            && !$db->run('SELECT id FROM ' . $db->table('tracks') . ' WHERE title = ?', ["Tag Title " . strrev($tag)])->fetch(), $r['body']);
        $r = $m->go('manage/library', ['csrf' => $csrf, 'action' => 'upload', 'files[0]' => new CURLFile("$tmpDir/details.mp3", 'audio/mpeg', 'details.mp3'),
            'details[0]' => json_encode($details), 'art_0' => new CURLFile("$tmpDir/cover.jpg", 'image/jpeg', 'cover.jpg')], ['Accept: application/json']);
        $du = (json_decode($r['body'], true)['results'] ?? [[]])[0];
        $tracks[] = (int) ($du['id'] ?? 0);
        $drow = $db->run('SELECT title, artist, album, art, rights FROM ' . $db->table('tracks') . ' WHERE id = ?', [(int) ($du['id'] ?? 0)])->fetch();
        $dr = $drow ? Stationwagon\Rights::decode($drow['rights']) : null;
        check('an upload with the window’s details keeps them over its tag: title, artist, album, licensing', ($du['ok'] ?? false) && ($du['title'] ?? '') === "Window Title " . strrev($tag)
            && $drow['artist'] === 'Window Artist' && $drow['album'] === 'Window Album' && ($dr['writers'] ?? '') === 'A. Writer' && ($dr['licence_ref'] ?? '') === 'CCLI 7123456'
            && ($dr['terms'] ?? '') === "Line one\nLine two" && ($dr['isrc'] ?? '') === 'ZA-ABC-24-00009', json_encode([$du, $drow]));
        check('  and the album art chosen in the window', $drow && $drow['art'] !== null && (new Browser($base))->go('storage/art/' . $drow['art'])['status'] === 200, (string) ($drow['art'] ?? 'none'));
        $r = $m->go('manage/library?q=' . rawurlencode("Window Title " . strrev($tag)));
        check('  the library page has the window with its three groups and the licence types', substr_count($r['body'], 'class="rgroup"') === 3 && str_contains($r['body'], 'id="sdAdd"')
            && str_contains($r['body'], '<datalist id="licenceTypes">') && str_contains($r['body'], 'name="terms"') && str_contains($r['body'], '>©</button>'));
        $file = $db->run('SELECT file FROM ' . $db->table('tracks') . ' WHERE id = ?', [$trackId])->fetchColumn();
        check('the song is served from storage', (new Browser($base))->go("storage/media/$file", null, ['Range: bytes=0-1'])['status'] === 206);
        $r = $m->go('manage/library?q=' . rawurlencode($tag));
        check('search finds it', substr_count($r['body'], '<b class="tt">') === 1 && str_contains($r['body'], htmlspecialchars($title)));
        $r = $m->go('manage/library', ['csrf' => $csrf, 'action' => 'update', 'id' => $trackId, 'title' => "$title (edited)", 'artist' => 'Test Choir']);
        check('its title can be changed', $flashed($p = $after($m, $r), 'Saved.') && str_contains($p['body'], 'edited'));

        // round 62: the wide table, its columns and star ratings
        $r = $m->go('manage/library?q=' . rawurlencode($tag));
        check('the library is a wide table: sortable headings, a column menu, the rating filter and one star group a song', str_contains($r['body'], 'id="libTable"') && str_contains($r['body'], 'class="sortb"')
            && str_contains($r['body'], 'id="colMenu"') && str_contains($r['body'], 'id="ratingFilter"') && str_contains($r['body'], 'id="bulkBar"') && substr_count($r['body'], 'role="radiogroup"') === 1
            && str_contains($r['body'], 'data-col="album"') && str_contains($r['body'], 'data-col="loudness"') && str_contains($r['body'], 'data-col="added"') && str_contains($r['body'], 'data-hidden="bitrate size preset"'));
        check('  the manager uses the whole window, the other pages keep their width', str_contains($r['body'], 'class="page wide fluid"') && str_contains($m->go('studio')['body'], 'class="page wide"'));
        $rate = fn(array $f) => json_decode($m->go('manage/library', ['csrf' => $csrf, 'action' => 'rate'] + $f, ['Accept: application/json'])['body'], true);
        $stored = fn() => $db->run('SELECT rating FROM ' . $db->table('tracks') . ' WHERE id = ?', [$trackId])->fetchColumn();
        $j = $rate(['id' => $trackId, 'rating' => '4']);
        check('a manager rates a song: 4 stars', ($j['ok'] ?? false) && ($j['rating'] ?? 0) === 4 && (int) $stored() === 4, json_encode($j));
        $r = $m->go('manage/library?q=' . rawurlencode($tag));
        check('  the page shows four filled stars', substr_count($r['body'], 'class="star on"') === 4 && str_contains($r['body'], 'aria-checked="true"') && str_contains($r['body'], '&quot;rating&quot;:4'));
        $j = $rate(['id' => $trackId, 'rating' => '9']);
        check('  9 stars, text and a song that isn’t there are refused, and the rating stays', !($j['ok'] ?? true) && !($rate(['id' => $trackId, 'rating' => 'lots'])['ok'] ?? true)
            && !($rate(['id' => 999999, 'rating' => '3'])['ok'] ?? true) && (int) $stored() === 4);
        $j = $rate(['ids[0]' => $trackId, 'ids[1]' => $du['id'] ?? 0, 'rating' => '2']);
        check('  several songs are rated at once', ($j['ok'] ?? false) && count($j['ids'] ?? []) === 2 && (int) $stored() === 2
            && (int) $db->run('SELECT rating FROM ' . $db->table('tracks') . ' WHERE id = ?', [(int) ($du['id'] ?? 0)])->fetchColumn() === 2, json_encode($j));
        $j = $rate(['id' => $trackId, 'rating' => '']);
        check('  an empty rating clears it', ($j['ok'] ?? false) && array_key_exists('rating', $j) && $j['rating'] === null && $stored() === null, json_encode($j));
        $r = (new Browser($base))->go('manage/library', ['csrf' => 'x', 'action' => 'rate', 'id' => $trackId, 'rating' => '5'], ['Accept: application/json']);
        check('  someone signed out can’t rate', $r['status'] !== 200 && $stored() === null, (string) $r['status']);

        // round 63: the Ads screen, kept apart from the Music library
        $r = $m->go('manage/ads');
        check('a manager opens the Ads: the ad-break settings, the drop zone, the list and a link in the side rail', $r['status'] === 200 && str_contains($r['body'], 'id="adsTable"') && str_contains($r['body'], 'name="every"')
            && str_contains($r['body'], 'name="per_break"') && str_contains($r['body'], 'name="gap_minutes"') && str_contains($r['body'], 'id="drop"') && str_contains($r['body'], '<b>Add ads</b>')
            && str_contains($r['body'], 'action="' . $basePath . '/manage/ads"') && str_contains($r['body'], '>Ads</a>') && str_contains($r['body'], 'upload.js?v='), (string) $r['status']);
        check('  with no copyright window and no album art in it', !str_contains($r['body'], 'id="rightsDlg"') && !str_contains($r['body'], 'library.js'));
        $adUpload = function (string $file, string $name) use ($m, $csrf): array {
            $r = $m->go('manage/ads', ['csrf' => $csrf, 'action' => 'upload', 'files[0]' => new CURLFile($file, 'audio/mpeg', $name)], ['Accept: application/json']);
            return (json_decode($r['body'], true)['results'] ?? [[]])[0] + ['status' => $r['status']];
        };
        $adTitle = "Web Test Ad " . strrev($tag);
        make_mp3("$tmpDir/ad.mp3", 20, ['title' => $adTitle, 'artist' => 'Some Artist', 'album' => 'Some Album'], random_int(0, 255));
        $au = $adUpload("$tmpDir/ad.mp3", 'ad.mp3');
        $adId = (int) ($au['id'] ?? 0);
        $arow = $db->run('SELECT kind, art, artist, album, rights FROM ' . $db->table('tracks') . ' WHERE id = ?', [$adId])->fetch();
        check('an MP3 uploads as an ad: its title from the file, kind “ad”, no artist, album, art or copyright', ($au['ok'] ?? false) && !$au['duplicate'] && $au['title'] === $adTitle
            && $arow && $arow['kind'] === 'ad' && $arow['art'] === null && $arow['artist'] === null && $arow['album'] === null && $arow['rights'] === null, json_encode([$au, $arow]));
        $plan = $db->run('SELECT * FROM ' . $db->table('ads') . ' WHERE track_id = ?', [$adId])->fetch();
        check('  with a plan of 5 plays a day, every day, all day', $plan && (int) $plan['daily_target'] === 5 && (int) $plan['days'] === 127 && $plan['window_start'] === null && (int) $plan['active'] === 1);
        $again = $adUpload("$tmpDir/ad.mp3", 'copy.mp3');
        check('  the same file again is “already there”', ($again['ok'] ?? false) && $again['duplicate'] && (int) $again['id'] === $adId);
        $r = $m->go('manage/ads');
        check('  the Ads list shows it, with its plan in words', str_contains($r['body'], 'id="a' . $adId . '"') && str_contains($r['body'], htmlspecialchars($adTitle)) && str_contains($r['body'], 'Every day · all day') && str_contains($r['body'], '>Running<'));
        $r = $m->go('manage/library?q=' . rawurlencode($adTitle));
        check('  the Music library does not list it', !str_contains($r['body'], 'id="t' . $adId . '"') && str_contains($r['body'], 'No songs match'));
        $asSong = $upload("$tmpDir/ad.mp3", 'ad-as-song.mp3');
        check('  and the same recording as a song is refused, saying where it is', !($asSong['ok'] ?? true) && str_contains((string) ($asSong['error'] ?? ''), 'already in the Ads'), (string) ($asSong['error'] ?? ''));

        $r = $m->go('manage/ads?edit=' . $adId);
        check('Edit opens the plan: plays a day, dates, hours, days, active and notes', str_contains($r['body'], 'id="adEdit"') && str_contains($r['body'], 'name="daily_target"') && str_contains($r['body'], 'name="start_date"')
            && str_contains($r['body'], 'name="window_start"') && substr_count($r['body'], 'name="days[]"') === 7 && str_contains($r['body'], 'name="active"') && str_contains($r['body'], 'name="notes"'));
        $planned = ['csrf' => $csrf, 'action' => 'update', 'id' => $adId, 'title' => "$adTitle (edited)", 'advertiser' => 'Acme Ltd', 'daily_target' => '12', 'active' => '1',
            'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'window_start' => '06:00', 'window_end' => '18:00', 'days[0]' => '1', 'days[1]' => '2', 'days[2]' => '3', 'days[3]' => '4', 'days[4]' => '5', 'notes' => 'Weekday mornings'];
        $r = $m->go('manage/ads', $planned);
        $p = $after($m, $r);
        $plan = $db->run('SELECT * FROM ' . $db->table('ads') . ' WHERE track_id = ?', [$adId])->fetch();
        check('a manager saves the plan', $flashed($p, 'Saved.') && $plan && (int) $plan['daily_target'] === 12 && (int) $plan['days'] === 31 && $plan['window_start'] === '06:00' && $plan['start_date'] === '2027-01-01'
            && $plan['advertiser'] === 'Acme Ltd' && $plan['notes'] === 'Weekday mornings' && $db->run('SELECT title FROM ' . $db->table('tracks') . ' WHERE id = ?', [$adId])->fetchColumn() === "$adTitle (edited)");
        check('  and the list says it: Mon–Fri, 06:00–18:00, the dates, the advertiser', str_contains($p['body'], 'Mon–Fri · 06:00–18:00') && str_contains($p['body'], '1 Jan 2027 – 31 Dec 2027') && str_contains($p['body'], 'Acme Ltd'));
        $r = $m->go('manage/ads', ['daily_target' => '999'] + $planned);
        $p = $after($m, $r);
        check('  a wrong value is refused with the reason, back at the form, and nothing changes', $flashed($p, 'Plays per day: choose a whole number from 0 to 500') && str_contains($p['body'], 'id="adEdit"')
            && (int) $db->run('SELECT daily_target FROM ' . $db->table('ads') . ' WHERE track_id = ?', [$adId])->fetchColumn() === 12);
        $r = (new Browser($base))->go('manage/ads', ['csrf' => 'x', 'daily_target' => '7'] + $planned);
        check('  someone signed out can’t', $r['status'] !== 200 && (int) $db->run('SELECT daily_target FROM ' . $db->table('ads') . ' WHERE track_id = ?', [$adId])->fetchColumn() === 12, (string) $r['status']);

        $origAds = Stationwagon\Ads::settings($db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE id = ?', [$stationId])->fetch());
        $putBack = fn() => $m->go('manage/ads', ['csrf' => $csrf, 'action' => 'settings', 'every' => (string) $origAds['every'], 'per_break' => (string) $origAds['per_break'], 'gap_minutes' => (string) $origAds['gap_minutes']] + ($origAds['enabled'] ? ['enabled' => '1'] : []));
        $r = $m->go('manage/ads', ['csrf' => $csrf, 'action' => 'settings', 'enabled' => '1', 'every' => '5', 'per_break' => '2', 'gap_minutes' => '20']);
        $p = $after($m, $r);
        check('the ad-break settings are saved and shown', $flashed($p, 'Saved.') && str_contains($p['body'], 'id="adsEvery" min="1" max="20" step="1" value="5"') && str_contains($p['body'], 'After every 5 songs the AutoDJ plays up to 2'));
        $r = $m->go('manage/ads', ['csrf' => $csrf, 'action' => 'settings', 'enabled' => '1', 'every' => '25', 'per_break' => '2', 'gap_minutes' => '20']);
        check('  25 songs is refused, and 5 stays', $flashed($after($m, $r), 'choose 1 to 20 songs') && Stationwagon\Ads::settings($db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE id = ?', [$stationId])->fetch())['every'] === 5);
        $putBack();
        check('  (put back as they were)', Stationwagon\Ads::settings($db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE id = ?', [$stationId])->fetch()) === $origAds);

        // round 65: "Look up details" (MusicBrainz). Only what stops before the internet is tried here (lookup_test.php and lookup_browser_test.mjs have the answers)
        $lk = fn(array $f, ?Browser $who = null) => ($who ?? $m)->go('manage/library', ['csrf' => $csrf, 'action' => 'lookup'] + $f, ['Accept: application/json']);
        $r = $lk(['title' => '  ']);
        $j = json_decode($r['body'], true);
        check('a look-up with no title and no ISRC is refused with a reason (nothing is asked of MusicBrainz)', ($j['ok'] ?? true) === false && str_contains((string) ($j['error'] ?? ''), 'title'), $r['body']);
        $r = $m->go('manage/library', ['csrf' => $csrf, 'action' => 'lookup_detail', 'recording' => '../../etc/passwd'], ['Accept: application/json']);
        $j = json_decode($r['body'], true);
        check('  a recording that isn’t a MusicBrainz id is refused', ($j['ok'] ?? true) === false && str_contains((string) ($j['error'] ?? ''), 'MusicBrainz id'), $r['body']);
        $r = $m->go('manage/library', ['csrf' => $csrf, 'action' => 'lookup_cover', 'release' => 'x'], ['Accept: application/json']);
        $j = json_decode($r['body'], true);
        check('  and so is a release', ($j['ok'] ?? true) === false && str_contains((string) ($j['error'] ?? ''), 'MusicBrainz id'), $r['body']);
        $r = (new Browser($base))->go('manage/library', ['csrf' => 'x', 'action' => 'lookup', 'title' => 'Anything'], ['Accept: application/json']);
        check('  someone signed out can’t look anything up', $r['status'] !== 200 && !str_contains($r['body'], 'candidates'), (string) $r['status']);
        $r = $a->go('manage/library', ['csrf' => $csrf, 'action' => 'lookup', 'title' => 'Anything'], ['Accept: application/json']);
        check('  nor a DJ', $r['status'] === 403 || $r['status'] === 303 || $r['status'] === 400, (string) $r['status']);
        $r = $m->go('manage/library');
        check('the details window has the Look up box, its notice about what is sent, and the album field', str_contains($r['body'], 'id="lkBox"') && str_contains($r['body'], 'musicbrainz.org') && str_contains($r['body'], 'id="lkAlbumIn"')
            && str_contains($r['body'], 'lookup.js?v=') && strpos($r['body'], 'lookup.js') < strpos($r['body'], 'library.js'));
        check('  and the Ads screen, which has no such window, has none of it', !str_contains($m->go('manage/ads')['body'], 'id="lkBox"'));

        $r = $m->go('manage/ads?confirm=' . $adId);
        check('Remove asks first', str_contains($r['body'], 'Remove this ad?') && str_contains($r['body'], 'Yes, remove'));
        $r = $m->go('manage/ads', ['csrf' => $csrf, 'action' => 'delete', 'id' => $adId]);
        $p = $after($m, $r);
        $gone = !$db->run('SELECT id FROM ' . $db->table('tracks') . ' WHERE id = ?', [$adId])->fetch();
        // an ad that is on air, or aired in the last two minutes, waits (like a song): the test station plays ads often
        check('  and Yes removes the ad and its plan (or says it is on air and to try again, as for a song)', ($flashed($p, 'Ad removed.') && $gone && !$db->run('SELECT track_id FROM ' . $db->table('ads') . ' WHERE track_id = ?', [$adId])->fetch())
            || (!$gone && $flashed($p, 'is on air')), $gone ? 'removed' : 'on air: kept for now');
        if (!$gone) {
            $db->run('UPDATE ' . $db->table('tracks') . " SET status = 'error', title = CONCAT('[test] ', title) WHERE id = ?", [$adId]); // hidden now; ads-clean removes it later
        }
        $r = $m->go('manage/ads', ['csrf' => $csrf, 'action' => 'delete', 'id' => $trackId]);
        check('  a song can’t be removed from here', !$flashed($after($m, $r), 'Ad removed.') && $db->run('SELECT id FROM ' . $db->table('tracks') . ' WHERE id = ?', [$trackId])->fetch() !== false);

        // round 71: downloads of the processed and the original file of any clip
        $dlTitle = 'Web Test Download ' . strrev($tag);
        make_mp3("$tmpDir/dl.mp3", 20, ['title' => $dlTitle, 'artist' => 'Dl: Artist/One'], random_int(0, 255));
        $dlUp = $adUpload("$tmpDir/dl.mp3", 'dl.mp3'); // uploaded as an ad: a download works for every kind of clip
        $dlId = (int) ($dlUp['id'] ?? 0);
        $dlRow = $db->run('SELECT file, sha1 FROM ' . $db->table('tracks') . ' WHERE id = ?', [$dlId])->fetch();
        $stored = $dlRow ? (string) file_get_contents(dirname(__DIR__) . '/storage/media/' . $dlRow['file']) : '';
        $r = $m->go('manage/download?id=' . $dlId);
        check('a manager downloads the processed file: an MP3 attachment with the exact bytes, never cached by others', $r['status'] === 200 && $r['body'] === $stored && $stored !== ''
            && str_contains($r['headers']['content-type'][0] ?? '', 'audio/mpeg') && str_starts_with($r['headers']['content-disposition'][0] ?? '', 'attachment;')
            && str_contains($r['headers']['content-disposition'][0] ?? '', '.mp3') && str_contains(implode(',', $r['headers']['cache-control'] ?? []), 'no-store'), $r['status'] . ' bytes ' . strlen($r['body']) . '/' . strlen($stored) . ' ' . ($r['headers']['content-type'][0] ?? '') . ' ' . implode(',', $r['headers']['cache-control'] ?? []));
        $r2 = $m->go('manage/download?id=' . $dlId . '&version=original');
        check('  and the original, saved with “(original)” in its name (an uploaded copy is its own original)', $r2['status'] === 200 && str_contains(rawurldecode($r2['headers']['content-disposition'][0] ?? ''), '(original).mp3'), (string) $r2['status'] . ' ' . ($r2['headers']['content-disposition'][0] ?? ''));
        check('  the name is the title (an ad has no artist), with nothing a file system dislikes', str_contains(rawurldecode($r['headers']['content-disposition'][0] ?? ''), $dlTitle . '.mp3'), $r['headers']['content-disposition'][0] ?? '');
        check('  a song and a jingle download too', ($m->go('manage/download?id=' . $trackId)['status'] === 200));
        check('  an unknown clip is “not here”', $m->go('manage/download?id=99999999')['status'] === 404 && $m->go('manage/download')['status'] === 404);
        check('  a path in place of an id is refused (nothing outside the clips is ever sent)', $m->go('manage/download?id=../../config.local.php')['status'] === 404);
        $r = $m->go('manage/ads');
        check('the lists have a Download button: the Music library and the Ads', str_contains($m->go('manage/library?q=' . rawurlencode($tag))['body'], 'manage/download?id=') && str_contains($r['body'], 'manage/download?id=' . $dlId));
        $r = (new Browser($base))->go('manage/download?id=' . $dlId);
        check('  someone signed out is sent to sign in, and gets no file', $r['status'] === 303 && $r['body'] !== $stored && !str_contains(implode(',', $r['headers']['content-type'] ?? []), 'audio'), (string) $r['status']);
        $m->go('manage/ads', ['csrf' => $csrf, 'action' => 'delete', 'id' => $dlId]);
        if ($db->run('SELECT id FROM ' . $db->table('tracks') . ' WHERE id = ?', [$dlId])->fetch()) { // on air: hidden now, removed by the next ads-clean
            $db->run('UPDATE ' . $db->table('tracks') . " SET status = 'error', title = CONCAT('[test] ', title) WHERE id = ?", [$dlId]);
        }

        // round 70: the Jingles screen, kept apart from the Music library and the Ads
        $r = $m->go('manage/jingles');
        check('a manager opens the Jingles: the settings, the drop zone, the list and a link in the side rail', $r['status'] === 200 && str_contains($r['body'], 'id="jinglesTable"') && str_contains($r['body'], 'name="every"')
            && str_contains($r['body'], 'id="drop"') && str_contains($r['body'], '<b>Add jingles</b>') && str_contains($r['body'], 'action="' . $basePath . '/manage/jingles"')
            && str_contains($r['body'], '>Jingles</a>') && str_contains($r['body'], 'upload.js?v=') && !str_contains($r['body'], 'name="gap_minutes"'), (string) $r['status']);
        check('  with no copyright window and no album art in it', !str_contains($r['body'], 'id="rightsDlg"') && !str_contains($r['body'], 'library.js'));
        $jUpload = function (string $file, string $name) use ($m, $csrf): array {
            $r = $m->go('manage/jingles', ['csrf' => $csrf, 'action' => 'upload', 'files[0]' => new CURLFile($file, 'audio/mpeg', $name)], ['Accept: application/json']);
            return (json_decode($r['body'], true)['results'] ?? [[]])[0] + ['status' => $r['status']];
        };
        $jTitle = 'Web Test Jingle ' . strrev($tag);
        make_mp3("$tmpDir/jingle.mp3", 4, ['title' => $jTitle, 'artist' => 'Some Artist', 'album' => 'Some Album'], random_int(0, 255));
        $ju = $jUpload("$tmpDir/jingle.mp3", 'jingle.mp3');
        $jId = (int) ($ju['id'] ?? 0);
        $jrow = $db->run('SELECT kind, art, artist, album, rights FROM ' . $db->table('tracks') . ' WHERE id = ?', [$jId])->fetch();
        check('an MP3 uploads as a jingle: its title from the file, kind “jingle”, no artist, album, art or copyright', ($ju['ok'] ?? false) && !$ju['duplicate'] && $ju['title'] === $jTitle
            && $jrow && $jrow['kind'] === 'jingle' && $jrow['art'] === null && $jrow['artist'] === null && $jrow['album'] === null && $jrow['rights'] === null, json_encode([$ju, $jrow]));
        $jr = $db->run('SELECT * FROM ' . $db->table('jingles') . ' WHERE track_id = ?', [$jId])->fetch();
        check('  and it is on', $jr && (int) $jr['active'] === 1);
        $again = $jUpload("$tmpDir/jingle.mp3", 'copy.mp3');
        check('  the same file again is “already there”', ($again['ok'] ?? false) && $again['duplicate'] && (int) $again['id'] === $jId);
        $r = $m->go('manage/jingles');
        check('  the Jingles list shows it, on, with a Download button', str_contains($r['body'], 'id="j' . $jId . '"') && str_contains($r['body'], htmlspecialchars($jTitle)) && str_contains($r['body'], '>On<') && str_contains($r['body'], 'manage/download?id=' . $jId));
        $r = $m->go('manage/library?q=' . rawurlencode($jTitle));
        check('  the Music library does not list it', !str_contains($r['body'], 'id="t' . $jId . '"') && str_contains($r['body'], 'No songs match'));
        check('  nor do the Ads', !str_contains($m->go('manage/ads')['body'], 'id="a' . $jId . '"'));
        $asSong = $upload("$tmpDir/jingle.mp3", 'jingle-as-song.mp3');
        check('  and the same recording as a song is refused, saying where it is', !($asSong['ok'] ?? true) && str_contains((string) ($asSong['error'] ?? ''), 'already in the Jingles'), (string) ($asSong['error'] ?? ''));
        $r = $m->go('manage/jingles?edit=' . $jId);
        check('Edit opens the title, on and notes', str_contains($r['body'], 'id="jingleEdit"') && str_contains($r['body'], 'name="title"') && str_contains($r['body'], 'name="active"') && str_contains($r['body'], 'name="notes"'));
        $r = $m->go('manage/jingles', ['csrf' => $csrf, 'action' => 'update', 'id' => $jId, 'title' => "$jTitle (edited)", 'notes' => 'Station ID']);
        $p = $after($m, $r);
        check('a manager saves it (an unticked On switches it off)', $flashed($p, 'Saved.') && (int) $db->run('SELECT active FROM ' . $db->table('jingles') . ' WHERE track_id = ?', [$jId])->fetchColumn() === 0
            && $db->run('SELECT title FROM ' . $db->table('tracks') . ' WHERE id = ?', [$jId])->fetchColumn() === "$jTitle (edited)" && str_contains($p['body'], '>Off<'));
        $r = $m->go('manage/jingles', ['csrf' => $csrf, 'action' => 'update', 'id' => $jId, 'title' => '  ', 'active' => '1']);
        $p = $after($m, $r);
        check('  a missing title is refused, back at the form', $flashed($p, 'A jingle needs a title.') && str_contains($p['body'], 'id="jingleEdit"'));
        $r = (new Browser($base))->go('manage/jingles', ['csrf' => 'x', 'action' => 'update', 'id' => $jId, 'title' => 'Hacked', 'active' => '1']);
        check('  someone signed out can’t', $r['status'] !== 200 && $db->run('SELECT title FROM ' . $db->table('tracks') . ' WHERE id = ?', [$jId])->fetchColumn() === "$jTitle (edited)", (string) $r['status']);
        $origJ = Stationwagon\Jingles::settings($db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE id = ?', [$stationId])->fetch());
        $r = $m->go('manage/jingles', ['csrf' => $csrf, 'action' => 'settings', 'enabled' => '1', 'every' => '6']);
        $p = $after($m, $r);
        check('the jingle settings are saved and shown', $flashed($p, 'Saved.') && str_contains($p['body'], 'id="jinglesEvery" min="1" max="20" step="1" value="6"') && str_contains($p['body'], 'After every 6 songs the AutoDJ plays a jingle'));
        $r = $m->go('manage/jingles', ['csrf' => $csrf, 'action' => 'settings', 'enabled' => '1', 'every' => '25']);
        check('  25 songs is refused, and 6 stays', $flashed($after($m, $r), 'choose 1 to 20 songs') && Stationwagon\Jingles::settings($db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE id = ?', [$stationId])->fetch())['every'] === 6);
        $m->go('manage/jingles', ['csrf' => $csrf, 'action' => 'settings', 'every' => (string) $origJ['every']] + ($origJ['enabled'] ? ['enabled' => '1'] : []));
        check('  (put back as they were)', Stationwagon\Jingles::settings($db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE id = ?', [$stationId])->fetch()) === $origJ);
        $r = $m->go('manage/sound');
        check('Sound & safety has the crossfade for jingles under the ads’ one', preg_match('~name="ads_crossfade_seconds".*name="jingles_crossfade_seconds"~s', $r['body']) === 1 && str_contains($r['body'], 'Crossfade for jingles'));
        $r = $m->go('manage/jingles?confirm=' . $jId);
        check('Remove asks first', str_contains($r['body'], 'Remove this jingle?') && str_contains($r['body'], 'Yes, remove'));
        $r = $m->go('manage/jingles', ['csrf' => $csrf, 'action' => 'delete', 'id' => $jId]);
        $p = $after($m, $r);
        $gone = !$db->run('SELECT id FROM ' . $db->table('tracks') . ' WHERE id = ?', [$jId])->fetch();
        // a jingle that is on air, or aired in the last two minutes, waits (like a song): the test station plays them every few songs
        check('  and Yes removes the jingle and its row (or says it is on air and to try again, as for a song)', ($flashed($p, 'Jingle removed.') && $gone && !$db->run('SELECT track_id FROM ' . $db->table('jingles') . ' WHERE track_id = ?', [$jId])->fetch())
            || (!$gone && $flashed($p, 'is on air')), $gone ? 'removed' : 'on air: kept for now');
        if (!$gone) {
            $db->run('UPDATE ' . $db->table('tracks') . " SET status = 'error', title = CONCAT('[test] ', title) WHERE id = ?", [$jId]); // hidden now; the next run's jingles-clean removes it
        }
        $r = $m->go('manage/jingles', ['csrf' => $csrf, 'action' => 'delete', 'id' => $trackId]);
        check('  a song can’t be removed from here', !$flashed($after($m, $r), 'Jingle removed.') && $db->run('SELECT id FROM ' . $db->table('tracks') . ' WHERE id = ?', [$trackId])->fetch() !== false);

        // Playlists and schedule
        $r = $m->go('manage/playlists', ['csrf' => $csrf, 'name' => "Web test list $tag", 'mode' => 'in_order']);
        check('a playlist is made and opened', $r['status'] === 303 && preg_match('#/manage/playlists/(\d+)$#', $location($r), $mm) === 1);
        $plId = (int) ($mm[1] ?? 0);
        $r = $m->go("manage/playlists/$plId", ['csrf' => $csrf, 'action' => 'add', 'tracks' => [$trackId]]);
        check('songs are added to it', $flashed($p = $after($m, $r), 'Songs added.') && str_contains($p['body'], 'edited'));
        $r = $m->go('manage/schedule', ['csrf' => $csrf, 'action' => 'add', 'kind' => 'playlist', 'playlist_id' => $plId, 'title' => "Web test block $tag", 'days' => [1, 2, 4, 8, 16, 32, 64], 'start' => '00:00', 'end' => '00:00']);
        check('an all-day block is added', $flashed($p = $after($m, $r), 'Block added.') && substr_count($p['body'], "Web test block $tag") >= 8, (string) substr_count($p['body'], "Web test block $tag"));
        $feed = json_decode((new Browser($base))->go('api/timeline.php')['body'], true);
        $soon = array_values(array_filter($feed['entries'] ?? [], fn($e) => $e['start'] > $feed['server_now'] + 10));
        check('the timeline switches to the playlist', $soon && count(array_filter($soon, fn($e) => ($e['track_id'] ?? 0) === $trackId)) === count($soon), count($soon) . ' upcoming');
        $r = $m->go('manage/schedule', ['csrf' => $csrf, 'action' => 'add', 'kind' => 'playlist', 'playlist_id' => $plId, 'start' => '06:00', 'end' => '07:00']);
        check('a block with no days is refused', $flashed($after($m, $r), 'The block wasn’t added'));
        $blockId = (int) $db->run('SELECT id FROM ' . $db->table('schedule') . ' WHERE playlist_id = ?', [$plId])->fetchColumn();
        $r = $m->go('manage/schedule', ['csrf' => $csrf, 'action' => 'delete', 'id' => $blockId]);
        check('a block is removed', $flashed($after($m, $r), 'Block removed.'));
        $feed = json_decode((new Browser($base))->go('api/timeline.php')['body'], true);
        $soon = array_values(array_filter($feed['entries'] ?? [], fn($e) => $e['start'] > $feed['server_now'] + 10));
        check('and the timeline goes back to the whole library', count(array_filter($soon, fn($e) => ($e['track_id'] ?? 0) !== $trackId)) > 0);
        $r = $m->go("manage/playlists/$plId", ['csrf' => $csrf, 'action' => 'delete']);
        check('a playlist is deleted', $r['status'] === 303 && str_ends_with($location($r), '/manage/playlists') && $flashed($after($m, $r), 'Playlist deleted'));

        // People
        $r = $m->go('manage/djs');
        check('the old DJs address goes to People', $r['status'] === 303 && str_ends_with($location($r), '/manage/people'), $location($r));
        $r = $m->go('manage/people');
        check('People lists everyone, you marked, with what each may do', $r['status'] === 200 && str_contains($r['body'], 'Web Test Manager</b> <span class="pill">You</span>') && str_contains($r['body'], '<th>May do</th>'));
        check('  and the Add form picks a role, no ticks', !str_contains($r['body'], 'name="perm[]"') && str_contains($r['body'], '<option value="dj"'));
        check('  a manager doesn’t see Roles, nor open it', !str_contains($r['body'], '>Roles</a>') && $m->go('manage/roles')['status'] === 403);
        // a role of the station's own, made the way an admin's Roles screen does (no admin account here)
        $roleKey = "web-test-$tag";
        $db->insert('roles', ['station_id' => $stationId, 'key' => $roleKey, 'name' => "Web Test Scheduler $tag", 'permissions' => '["schedule"]', 'built_in' => 0, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $r = $m->go('manage/people');
        check('  the station’s own roles are offered, with what they may do', str_contains($r['body'], "<option value=\"$roleKey\">Web Test Scheduler $tag</option>") && str_contains($r['body'], "<b>Web Test Scheduler $tag</b> Schedule."));
        $djEmail = "web-test-$tag-dj@example.test";
        $r = $m->go('manage/people', ['csrf' => $csrf, 'action' => 'add', 'name' => 'Web Test New DJ', 'email' => $djEmail, 'role' => $roleKey, 'password' => 'first pass 123']);
        check('a manager adds someone with that role', $flashed($after($m, $r), 'Added.'));
        $users[] = $newDj = (int) $db->run('SELECT id FROM ' . $db->table('users') . ' WHERE email = ?', [$djEmail])->fetchColumn();
        check('  stored', $db->run('SELECT role FROM ' . $db->table('users') . ' WHERE id = ?', [$newDj])->fetchColumn() === $roleKey);
        $r = $m->go('manage/people', ['csrf' => $csrf, 'action' => 'add', 'name' => 'Web Test Boss', 'email' => "web-test-$tag-boss@example.test", 'role' => 'admin', 'password' => 'first pass 123']);
        check('a manager can’t add an admin', $flashed($after($m, $r), 'Not added'));
        $d = new Browser($base);
        $d->go('signin', ['csrf' => Browser::csrf($d->go('signin')), 'email' => $djEmail, 'password' => 'first pass 123']);
        $dm = $d->go('manage');
        check('the new DJ signs in and sees Schedule, but not the library or People', $dm['status'] === 200 && str_contains($dm['body'], '>Schedule</a>') && !str_contains($dm['body'], '>Music library</a>') && !str_contains($dm['body'], '>People</a>'));
        check('  Schedule opens for them; People doesn’t', $d->go('manage/schedule')['status'] === 200 && $d->go('manage/people')['status'] === 403);
        $db->run('UPDATE ' . $db->table('roles') . ' SET permissions = ? WHERE `key` = ?', ['["library","listeners"]', $roleKey]);
        $dm = $d->go('manage');
        check('when the role’s ticks change, it applies to them at once', str_contains($dm['body'], '>Music library</a>') && str_contains($dm['body'], '>Listeners</a>') && !str_contains($dm['body'], '>Schedule</a>') && $d->go('manage/schedule')['status'] === 403);
        $r = $m->go('manage/people', ['csrf' => $csrf, 'action' => 'details', 'id' => $newDj, 'name' => 'Web Test Renamed DJ', 'email' => $djEmail]);
        $p = $after($m, $r);
        check('a manager renames them', $flashed($p, 'Name and email saved.') && str_contains($p['body'], 'Web Test Renamed DJ'));
        check('  with their forms open after the change', str_contains($p['body'], '>Change role</button>') && str_contains($p['body'], 'Yes, block Web Test Renamed DJ'));
        $r = $m->go('manage/people', ['csrf' => $csrf, 'action' => 'password', 'id' => $newDj, 'password' => 'second pass 123']);
        check('a manager sets a new password', $flashed($after($m, $r), 'New password set.'));
        check('which signs the DJ out', $d->go('manage')['status'] === 303);
        $d = new Browser($base);
        $d->go('signin', ['csrf' => Browser::csrf($d->go('signin')), 'email' => $djEmail, 'password' => 'second pass 123', 'remember' => '1']);
        check('  they sign in with it (Keep me signed in)', $d->go('manage')['status'] === 200 && isset($d->cookies['sw_remember']));
        $r = $m->go('manage/people', ['csrf' => $csrf, 'action' => 'block', 'id' => $newDj]);
        check('a manager blocks them', $flashed($p = $after($m, $r), 'Blocked.') && str_contains($p['body'], 'pill bad">Blocked</span>') && str_contains($p['body'], '>Unblock</button>'));
        check('  signed out at once', $d->go('manage')['status'] === 303);
        unset($d->cookies['sw_session']);
        check('  even with Keep me signed in', $d->go('studio')['status'] === 303);
        $d2 = new Browser($base);
        $r = $d2->go('signin', ['csrf' => Browser::csrf($d2->go('signin')), 'email' => $djEmail, 'password' => 'second pass 123']);
        check('  and can’t sign in: told they’re blocked', $r['status'] === 401 && str_contains($r['body'], 'This account is blocked'));
        $r = $m->go('manage/people', ['csrf' => $csrf, 'action' => 'unblock', 'id' => $newDj]);
        check('a manager unblocks them', $flashed($after($m, $r), 'Unblocked.'));
        $r = $d2->go('signin', ['csrf' => Browser::csrf($d2->go('signin')), 'email' => $djEmail, 'password' => 'second pass 123']);
        check('  who can sign in again', $r['status'] === 303);
        $r = $m->go('manage/people', ['csrf' => $csrf, 'action' => 'role', 'id' => $newDj, 'role' => 'manager']);
        check('a manager promotes them', $flashed($after($m, $r), 'Role changed.'));
        $r = $m->go('manage/people', ['csrf' => $csrf, 'action' => 'remove', 'id' => $newDj]);
        check('a manager removes them', $flashed($after($m, $r), 'Removed.'));
        $d3 = new Browser($base);
        $r = $d3->go('signin', ['csrf' => Browser::csrf($d3->go('signin')), 'email' => $djEmail, 'password' => 'second pass 123']);
        check('who can no longer sign in', $r['status'] === 401);
        $r = $m->go('manage/people', ['csrf' => $csrf, 'action' => 'role', 'id' => $mgrId, 'role' => 'dj']);
        check('nobody changes their own role', $flashed($after($m, $r), 'You can’t change'));
        $r = $m->go('manage/people', ['csrf' => $csrf, 'action' => 'block', 'id' => $mgrId]);
        check('or blocks themselves', $flashed($after($m, $r), 'You can’t block'));
        check('a DJ can’t send the People form', $c->go('manage/people', ['csrf' => Browser::csrf($c->go('manage')), 'action' => 'block', 'id' => $mgrId])['status'] === 403);

        // Roles (a throwaway admin)
        $aEmail = "web-test-$tag-admin@example.test";
        $aPass = bin2hex(random_bytes(12));
        $users[] = $admId = $db->insert('users', ['email' => $aEmail, 'name' => 'Web Test Admin', 'password_hash' => password_hash($aPass, PASSWORD_DEFAULT), 'role' => 'admin', 'created_at' => gmdate('Y-m-d H:i:s')]);
        $db->insert('station_users', ['station_id' => $stationId, 'user_id' => $admId]);
        $ad = new Browser($base);
        $ad->go('signin', ['csrf' => Browser::csrf($ad->go('signin')), 'email' => $aEmail, 'password' => $aPass]);
        $rr = $ad->go('manage/roles');
        check('an admin opens Roles from the menu: Admin, Manager and DJ with what each may do', $rr['status'] === 200 && str_contains($rr['body'], '>Roles</a>')
            && str_contains($rr['body'], 'data-role="admin"') && str_contains($rr['body'], 'data-role="manager"') && str_contains($rr['body'], 'data-role="dj"') && str_contains($rr['body'], 'Their own shows, in their own blocks'), (string) $rr['status']);
        check('  the Add form has a tick for each permission, the live ones split in two', substr_count(substr($rr['body'], (int) strpos($rr['body'], 'id="addRole"')), 'name="perm[]"') === 10
            && str_contains($rr['body'], 'Go live any time') && str_contains($rr['body'], 'Run the on-air desk'));
        $acsrf = Browser::csrf($rr);
        $r = $ad->go('manage/roles', ['csrf' => $acsrf, 'action' => 'add', 'name' => "Web Test Guest $tag", 'perm' => ['anytime']]);
        $guestKey = (string) $db->run('SELECT `key` FROM ' . $db->table('roles') . ' WHERE name = ?', ["Web Test Guest $tag"])->fetchColumn();
        check('an admin adds a role', $flashed($p = $after($ad, $r), 'Role added.') && str_contains($p['body'], 'value="' . $guestKey . '"') && str_contains($p['body'], 'Save</button>'));
        $r = $ad->go('manage/roles', ['csrf' => $acsrf, 'action' => 'save', 'key' => $guestKey, 'name' => "Web Test Guest $tag", 'perm' => ['anytime', 'podcast']]);
        check('  changes its ticks', $flashed($after($ad, $r), 'Saved.') && $db->run('SELECT permissions FROM ' . $db->table('roles') . ' WHERE `key` = ?', [$guestKey])->fetchColumn() === '["anytime","podcast"]');
        $r = $ad->go('manage/roles', ['csrf' => $acsrf, 'action' => 'save', 'key' => 'admin', 'name' => 'Boss', 'perm' => []]);
        check('  can’t change Admin', $flashed($after($ad, $r), 'The Admin role always has everything.'));
        $r = $ad->go('manage/roles', ['csrf' => $acsrf, 'action' => 'delete', 'key' => $guestKey]);
        check('  and deletes it', $flashed($after($ad, $r), 'Role deleted.') && !$db->run('SELECT COUNT(*) FROM ' . $db->table('roles') . ' WHERE `key` = ?', [$guestKey])->fetchColumn());
        check('a manager can’t send the Roles form', $m->go('manage/roles', ['csrf' => $csrf, 'action' => 'add', 'name' => 'Sneaky', 'perm' => ['people']])['status'] === 403);

        // round 73: the Streams screen (admins), and the stream switch on the Overview and the Schedule
        $mainSlug = (string) $db->run('SELECT slug FROM ' . $db->table('stations') . ' WHERE id = ?', [$stationId])->fetchColumn();
        $strName = "Web Test Stream $tag";
        $strSlug = Stationwagon\Streams::slugFor($strName);
        $r = $ad->go('manage/streams');
        check('an admin opens Streams from the menu: the main stream, the list and the Add form', $r['status'] === 200 && str_contains($r['body'], '>Streams</a>') && str_contains($r['body'], 'id="streamsTable"') && str_contains($r['body'], 'id="streamAdd"')
            && str_contains($r['body'], '>Main<'), (string) $r['status']);
        check('  with one stream there is no stream switch on the Overview', !str_contains($ad->go('manage')['body'], 'class="streamswitch"'));
        check('  a manager has no Streams (403), nor the form', $m->go('manage/streams')['status'] === 403 && $m->go('manage/streams', ['csrf' => $csrf, 'action' => 'create', 'name' => 'Sneaky'])['status'] === 403);
        $acsrf = Browser::csrf($r);
        $r = $ad->go('manage/streams', ['csrf' => $acsrf, 'action' => 'create', 'name' => '  ']);
        check('a stream needs a name', $flashed($after($ad, $r), 'A stream needs a name.'));
        $r = $ad->go('manage/streams', ['csrf' => $acsrf, 'action' => 'create', 'name' => 'Studio Stream', 'slug' => 'studio']);
        check('  and an address name the app does not already use', $flashed($after($ad, $r), 'already uses'));
        $r = $ad->go('manage/streams', ['csrf' => $acsrf, 'action' => 'create', 'name' => $strName]);
        $p = $after($ad, $r);
        $row = $db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE slug = ?', [$strSlug])->fetch();
        check('an admin adds a stream (its address name made from its name)', $flashed($p, 'Stream added.') && $row && (int) $row['parent_id'] === $stationId && $row['name'] === $strName, json_encode($row));
        check('  the list has it, with its address', str_contains($p['body'], htmlspecialchars($strName)) && str_contains($p['body'], '…/' . $strSlug . '/') && str_contains($p['body'], 'id="s' . ($row['id'] ?? 0) . '"'));
        $r = $ad->go('manage/streams', ['csrf' => $acsrf, 'action' => 'create', 'name' => 'Twin', 'slug' => $strSlug]);
        check('  two streams cannot share an address name', $flashed($after($ad, $r), 'already a stream with that address name'));
        $r = $ad->go('manage?stream=' . $strSlug);
        check('with two streams the Overview has the stream switch, the picked stream marked', str_contains($r['body'], 'class="streamswitch"') && preg_match('~aria-current="true">' . preg_quote(htmlspecialchars($strName), '~') . '<~', $r['body']) === 1, (string) $r['status']);
        $r = $ad->go('manage/schedule');
        check('  and the Schedule follows the stream picked (an empty week of its own)', str_contains($r['body'], 'class="streamswitch"') && preg_match('~aria-current="true">' . preg_quote(htmlspecialchars($strName), '~') . '<~', $r['body']) === 1);
        $r = $ad->go('manage?stream=' . $mainSlug);
        check('  the main stream is picked again with its own address name', preg_match('~aria-current="true">[^<]*<~', $r['body']) === 1 && !preg_match('~aria-current="true">' . preg_quote(htmlspecialchars($strName), '~') . '<~', $r['body']));
        $r = $ad->go('manage?stream=nonsense');
        check('  an unknown stream name changes nothing', $r['status'] === 200 && !preg_match('~aria-current="true">' . preg_quote(htmlspecialchars($strName), '~') . '<~', $r['body']));
        // the listeners' side of a further stream: its own page, embed and api addresses
        $pub = new Browser($base);
        $r = $pub->go($strSlug);
        check('a stream has its own player page at its address name, naming the stream and its api address', $r['status'] === 200 && str_contains($r['body'], 'data-stream="' . $strSlug . '"') && str_contains($r['body'], htmlspecialchars($strName))
            && str_contains($r['body'], 'api/stream.php?stream=' . $strSlug), (string) $r['status']);
        $r = $pub->go($strSlug . '/');
        check('  the same with a slash at the end', $r['status'] === 200 && str_contains($r['body'], 'data-stream="' . $strSlug . '"'), (string) $r['status']);
        check('  the station’s own page has no stream mark', !str_contains($pub->go('')['body'], 'data-stream='));
        $r = $pub->go($strSlug . '/embed');
        check('  its embed, framed by any site, knows the stream (also as ?stream=)', $r['status'] === 200 && str_contains($r['body'], 'data-stream="' . $strSlug . '"') && str_contains($pub->go('embed?stream=' . $strSlug)['body'], 'data-stream="' . $strSlug . '"')
            && !str_contains($pub->go('embed')['body'], 'data-stream='));
        $r = $pub->go($strSlug . '/now');
        check('  its Now playing card is its own too, and its link goes to the stream’s player (also as ?stream=)', $r['status'] === 200 && str_contains($r['body'], 'data-stream="' . $strSlug . '"') && str_contains($r['body'], 'href="' . $basePath . '/' . $strSlug . '"')
            && str_contains($pub->go('now?stream=' . $strSlug)['body'], 'data-stream="' . $strSlug . '"') && !str_contains($pub->go('now')['body'], 'data-stream=') && $pub->go('now?stream=no-such-stream')['status'] === 404, (string) $r['status']);
        $r = $ad->go('manage/streams');
        check('  the Streams screen has the code to copy for each stream: its player and its card', str_contains($r['body'], 'id="embedCodes"') && str_contains($r['body'], htmlspecialchars('/' . $strSlug . '/embed')) && str_contains($r['body'], htmlspecialchars('/' . $strSlug . '/now'))
            && str_contains($r['body'], htmlspecialchars($basePath . '/embed"')) && substr_count($r['body'], 'data-copy-btn') >= 4);
        $np = json_decode($pub->go('api/nowplaying.php?stream=' . $strSlug)['body'], true);
        $npMain = json_decode($pub->go('api/nowplaying.php')['body'], true);
        check('  its now-playing says the stream’s name, and the station’s own still says the station’s', ($np['station']['name'] ?? '') === $strName && ($npMain['station']['name'] ?? '') !== $strName && isset($np['delay'], $np['server_now']), json_encode($np['station'] ?? $np));
        $tlj = json_decode($pub->go('api/timeline.php?stream=' . $strSlug)['body'], true);
        $tlMain = json_decode($pub->go('api/timeline.php')['body'], true);
        check('  its timeline is its own (its name, its own listeners, its own entries, the same songs)', ($tlj['station']['name'] ?? '') === $strName && !empty($tlj['entries']) && ($tlMain['station']['name'] ?? '') !== $strName
            && json_encode(array_column($tlj['entries'], 'n')) !== '' && count($tlj['entries']) > 1, json_encode(array_keys($tlj ?? [])));
        $hls = $pub->go('api/stream.php?stream=' . $strSlug);
        check('  its stream playlist is its own HLS playlist', $hls['status'] === 200 && str_starts_with($hls['body'], '#EXTM3U') && str_contains(implode(',', $hls['headers']['content-type'] ?? []), 'mpegurl'), (string) $hls['status']);
        check('  an unknown stream is “not found” (a page, the api and the playlist), never the main stream', $pub->go('no-such-stream')['status'] === 404 && $pub->go('api/nowplaying.php?stream=no-such-stream')['status'] === 404
            && $pub->go('api/timeline.php?stream=no-such-stream')['status'] === 404 && $pub->go('api/stream.php?stream=no-such-stream')['status'] === 404 && $pub->go('embed?stream=no-such-stream')['status'] === 404);
        check('  the address names the app uses still go where they went (the manager, the studio, the api folder)', $pub->go('manage')['status'] !== 404 && $pub->go('studio')['status'] !== 404 && $pub->go('signin')['status'] === 200);

        // DJs and streams: the People screen gives them, the studio follows
        $djS = new Browser($base);
        $djS->go('signin', ['csrf' => Browser::csrf($djS->go('signin')), 'email' => $email, 'password' => 'a new password 1']);
        $djPage = (int) $db->run('SELECT id FROM ' . $db->table('users') . ' WHERE email = ?', [$email])->fetchColumn();
        $r = $djS->go('studio?stream=' . $strSlug);
        check('a DJ who has only the main stream may not open the studio on another (403, with the reason)', $r['status'] === 403 && str_contains($r['body'], 'You may not go live on this stream'), (string) $r['status']);
        check('  the studio’s own api says the same, with no state', $djS->go('studio/api/state?stream=' . $strSlug)['status'] === 403);
        $r = $djS->go('studio');
        check('  and the studio with no stream is the main one as ever (no stream switch for one stream)', $r['status'] === 200 && !str_contains($r['body'], 'data-stream=') && !str_contains($r['body'], 'class="streamswitch"'), (string) $r['status']);
        $r = $ad->go('manage/people?person=' . $djPage);
        check('the People screen lets an admin tick the streams a DJ may go live on', str_contains($r['body'], 'May go live on') && str_contains($r['body'], 'name="streams[]"') && str_contains($r['body'], htmlspecialchars($strName)), (string) $r['status']);
        $r = $ad->go('manage/people', ['csrf' => $acsrf, 'action' => 'streams', 'id' => $djPage]);
        check('  at least one is needed', $flashed($after($ad, $r), 'Choose at least one stream'));
        $r = $ad->go('manage/people', ['csrf' => $acsrf, 'action' => 'streams', 'id' => $djPage, 'streams' => [(string) $row['id']]]);
        check('  saved: they may go live on the stream, and no longer on the main one', $flashed($after($ad, $r), 'Saved.') && Stationwagon\Streams::allowedIds($db, ['id' => $djPage, 'role' => 'dj']) === [(int) $row['id']]);
        $r = $djS->go('studio?stream=' . $strSlug);
        check('  their studio opens on that stream, named, with its own api address', $r['status'] === 200 && str_contains($r['body'], 'data-stream="' . $strSlug . '"') && str_contains($r['body'], htmlspecialchars($strName)), (string) $r['status']);
        $sj = json_decode($djS->go('studio/api/state?stream=' . $strSlug)['body'], true);
        check('  the studio’s state is that stream’s', ($sj['station']['name'] ?? '') === $strName && isset($sj['slot']), json_encode($sj['station'] ?? $sj));
        $r = $djS->go('studio');
        check('  and with no stream picked they get the one they have (not a 403 for the main one)', $r['status'] === 200 && str_contains($r['body'], 'data-stream="' . $strSlug . '"'), (string) $r['status']);
        check('  the main stream is closed to them now', $djS->go('studio?stream=' . $mainSlug)['status'] === 403 || str_contains($djS->go('studio?stream=' . $mainSlug)['body'], 'You may not go live'));
        $r = $ad->go('manage/people', ['csrf' => $acsrf, 'action' => 'streams', 'id' => $djPage, 'streams' => [(string) $stationId, (string) $row['id']]]);
        $r2 = $djS->go('studio?stream=' . $strSlug);
        check('  with both ticked the studio has the switch between them', $flashed($after($ad, $r), 'Saved.') && str_contains($r2['body'], 'class="streamswitch"') && str_contains($r2['body'], 'Go live on'), (string) $r2['status']);
        $r = $ad->go('manage/people?person=' . $mgrId);
        check('  an admin or manager has every stream, no ticks to choose', str_contains($r['body'], 'may go live on every stream') && !str_contains(substr($r['body'], (int) strpos($r['body'], 'streamform')), 'name="streams[]"') || str_contains($r['body'], 'Admins and managers may go live on every stream'));
        $db->run('DELETE FROM ' . $db->table('user_streams') . ' WHERE user_id = ?', [$djPage]); // back to nobody having given them streams

        $r = $ad->go('manage/streams?edit=' . $row['id']);
        check('Edit opens the name and the address name', str_contains($r['body'], 'id="streamEdit"') && str_contains($r['body'], 'name="slug"'));
        $r = $ad->go('manage/streams', ['csrf' => $acsrf, 'action' => 'update', 'id' => $row['id'], 'name' => "$strName 2", 'slug' => $strSlug . '-2']);
        check('  and saves them', $flashed($after($ad, $r), 'Saved.') && $db->run('SELECT name FROM ' . $db->table('stations') . ' WHERE id = ?', [$row['id']])->fetchColumn() === "$strName 2");
        $r = $ad->go('manage/streams', ['csrf' => $acsrf, 'action' => 'update', 'id' => $row['id'], 'name' => 'x', 'slug' => 'manage']);
        check('  a name the app uses is refused, back at the form', $flashed($p = $after($ad, $r), 'already uses') && str_contains($p['body'], 'id="streamEdit"'));
        $r = $ad->go('manage/streams', ['csrf' => $acsrf, 'action' => 'update', 'id' => $stationId, 'name' => 'Hijack', 'slug' => 'hijack']);
        check('  the main stream is not changed here', $flashed($after($ad, $r), 'isn’t here') && $db->run('SELECT slug FROM ' . $db->table('stations') . ' WHERE id = ?', [$stationId])->fetchColumn() === $mainSlug);
        $r = $ad->go('manage/streams?confirm=' . $row['id']);
        check('Remove asks first', str_contains($r['body'], 'Remove this stream and its schedule?') && str_contains($r['body'], 'Yes, remove'));
        $ad->go('manage?stream=' . $strSlug . '-2');
        $r = $ad->go('manage/streams', ['csrf' => $acsrf, 'action' => 'delete', 'id' => $row['id']]);
        check('  and Yes removes the stream; the picked stream falls back to the main one', $flashed($after($ad, $r), 'Stream removed.') && !$db->run('SELECT id FROM ' . $db->table('stations') . ' WHERE id = ?', [$row['id']])->fetch()
            && !str_contains($ad->go('manage')['body'], 'class="streamswitch"'));
        $r = $ad->go('manage/streams', ['csrf' => $acsrf, 'action' => 'delete', 'id' => $stationId]);
        check('  the main stream can’t be removed', $flashed($after($ad, $r), 'isn’t here') && $db->run('SELECT id FROM ' . $db->table('stations') . ' WHERE id = ?', [$stationId])->fetch() !== false);

        // Sound & safety (the station's own values are put back in the cleanup)
        $origSettings = $db->run('SELECT settings FROM ' . $db->table('stations') . ' WHERE id = ?', [$stationId])->fetchColumn();
        $r = $m->go('manage/sound');
        check('Sound & safety opens with eleven sliders and ten choices', $r['status'] === 200 && substr_count($r['body'], 'type="range"') === 11 && substr_count($r['body'], '<select id="f-') === 10, (string) $r['status']);
        check('with the fade shape preview, the MB an hour and the listener delay', str_contains($r['body'], 'id="fadeCurve"') && str_contains($r['body'], 'fades.js') && str_contains($r['body'], '192 kbps · 86 MB an hour') && preg_match('/About \d+ s/', $r['body']) === 1);
        $values = ['csrf' => $csrf, 'crossfade_seconds' => '5', 'ads_crossfade_seconds' => '1.5', 'jingles_crossfade_seconds' => '1', 'fade_shape' => 's_curve', 'skip_fade_seconds' => '1.5', 'live_fade_seconds' => '4', 'duck_db' => '-10',
            'live_timeout_seconds' => '10', 'rejoin_minutes' => '5', 'silence_db' => '-50', 'recent_tracks' => '3', 'ratings_influence' => '0', 'prep_master' => (string) (int) $keepMaster, 'prep_level' => (string) (int) $keepLevel, 'prep_target' => (string) $keepTarget, 'live_kbps' => '160', 'prep_kbps' => '256',
            'prep_rate' => '44100', 'prep_precision' => '32', 'prep_dither' => '1', 'prep_quality' => '9'];
        $r = $m->go('manage/sound', ['fade_shape' => 'wobbly'] + $values);
        check('a fade shape not on the list is refused', $flashed($after($m, $r), 'Fade shape: choose one of the options.'));
        $r = $m->go('manage/sound', ['crossfade_seconds' => '99'] + $values);
        $p = $after($m, $r);
        check('a crossfade of 99 s is refused', $flashed($p, 'Not saved: Crossfade between songs: choose 0 to 12 s.') && str_contains($p['body'], 'value="99"'));
        $r = $m->go('manage/sound', $values);
        check('good values are saved', $flashed($after($m, $r), 'Saved. New settings apply from the next song.'));
        check('  and the crossfade for ads is saved, apart from the songs’ own', (float) Stationwagon\Planner::settings($db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE id = ?', [$stationId])->fetch())['ads_crossfade_seconds'] === 1.5
            && (float) Stationwagon\Planner::settings($db->run('SELECT * FROM ' . $db->table('stations') . ' WHERE id = ?', [$stationId])->fetch())['crossfade_seconds'] === 5.0);
        $r = $m->go('manage/sound', ['prep_target' => '-11.2'] + $values);
        check('a loudness target that isn’t a half dB step, or isn’t between −23 and −8, is refused', $flashed($after($m, $r), 'Loudness target: choose -23 to -8 LUFS in steps of 0.5.') && $prepAll()[2] === $keepTarget
            && str_contains($m->go('manage/sound', ['prep_target' => '-7'] + $values)['body'], '') && $prepAll()[2] === $keepTarget);
        $r = $m->go('manage/sound', ['prep_master' => '1', 'prep_level' => '1', 'prep_target' => '-11.5'] + $values);
        $p = $after($m, $r);
        check('a manager saves the loudness choices here: mastering on, levelling on, −11.5 LUFS', $flashed($p, 'Saved.') && $prepAll() === [true, true, -11.5], json_encode($prepAll()));
        $r = $m->go('manage/library');
        check('  and the Music library’s upload zone follows them', str_contains($r['body'], 'data-master="1"') && str_contains($r['body'], 'data-level="1"') && str_contains($r['body'], 'data-target="-11.5"') && str_contains($r['body'], 'mastered with') && str_contains($r['body'], '−11.5 LUFS'));
        $r = $m->go('manage/ads');
        check('  and so does the Ads screen', str_contains($r['body'], 'data-target="-11.5"') && str_contains($r['body'], '−11.5 LUFS') && !str_contains($r['body'], 'id="prepTarget"'));
        $r = (new Browser($base))->go('manage/sound', ['csrf' => 'x', 'prep_target' => '-9'] + $values);
        check('  someone signed out can’t', $r['status'] !== 200 && $prepAll()[2] === -11.5, (string) $r['status']);
        $m->go('manage/sound', $values); // as they were
        $feed = json_decode((new Browser($base))->go('api/timeline.php')['body'], true);
        check('the player gets the new fades', ($feed['settings']['crossfade_seconds'] ?? null) == 5 && ($feed['settings']['live_fade_seconds'] ?? null) == 4 && ($feed['settings']['fade_shape'] ?? null) === 's_curve', json_encode($feed['settings'] ?? null));
        $soon = array_values(array_filter($feed['entries'] ?? [], fn($e) => $e['type'] === 'song' && $e['start'] > $feed['server_now'] + 10));
        check('and the songs planned next crossfade over 5 s', $soon && array_unique(array_column($soon, 'fade_out')) == [5.0], json_encode(array_values(array_unique(array_column($soon, 'fade_out')))));

        // Removing the song
        $r = $m->go('manage/library', ['csrf' => $csrf, 'action' => 'delete', 'id' => $trackId]);
        $p = $after($m, $r);
        check('removing a song works, or waits while it is on air', $flashed($p, 'Song removed') || $flashed($p, 'on air'));
    }
} finally {
    if ($db !== null) {
        foreach (array_merge($created !== null ? [$created] : [], $users ?? []) as $id) {
            $db->run('DELETE FROM ' . $db->table('users') . ' WHERE id = ?', [$id]);
            $db->run('DELETE FROM ' . $db->table('running_orders') . ' WHERE scope = ?', ["user:$id"]);
        }
        $db->run('DELETE FROM ' . $db->table('playlists') . " WHERE name LIKE 'Web test list %'");
        $db->run('DELETE FROM ' . $db->table('roles') . " WHERE `key` LIKE 'web-test-%' OR name LIKE 'Web Test %'");
        if (isset($origSettings) && $origSettings !== false) {
            $db->run('UPDATE ' . $db->table('stations') . ' SET settings = ? WHERE id = ?', [$origSettings, $stationId]);
        }
        $planner = \Stationwagon\Planner::forFirstStation(); // with the station's own settings again
        $lib = new \Stationwagon\Library($db, $planner->station(), SW_ROOT);
        $kept = 0;
        // Test songs hidden by an earlier run (they were on air then) go now, if they can
        $old = $db->run('SELECT id FROM ' . $db->table('tracks') . " WHERE status = 'error' AND title LIKE '[test] Web Test Song %'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($old as $id) {
            $lib->delete((int) $id, $planner, microtime(true));
        }
        foreach (array_filter($tracks ?? []) as $id) {
            if ($lib->get($id) !== null && $lib->delete($id, $planner, microtime(true)) !== null) {
                // On air right now: hide it from the AutoDJ; it can be removed later
                $db->run('UPDATE ' . $db->table('tracks') . " SET status = 'error', title = CONCAT('[test] ', title) WHERE id = ?", [$id]);
                $kept++;
                // and if it's playing at the live edge, skip it, so the silent test song doesn't stay on air
                $t = microtime(true);
                foreach ($planner->timeline($t) as $e) {
                    if ($e['type'] === 'song' && $e['track_id'] === $id && $e['start'] <= $t && $e['end'] > $t + 5) {
                        $skipped = $planner->skip($t)['cut_at'] ?? null;
                    }
                }
            }
        }
        $planner->replan(microtime(true)); // nothing planned may use a hidden song
        $left = (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('users') . " WHERE email LIKE 'web-test-%'")->fetchColumn();
        echo "Cleanup: throwaway accounts deleted ($left left)" . ($kept ? ", $kept test song hidden (it was on air)" . (!empty($skipped) ? ' and skipped' : '') : '') . "\n";
    }
    if (isset($tmpDir)) {
        remove_tree($tmpDir);
    }
}
finish();
