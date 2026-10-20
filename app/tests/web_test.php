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
    public function go(string $path, ?array $post = null, array $extra = []): array
    {
        $ch = curl_init($this->base . '/' . ltrim($path, '/'));
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
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
            $multipart = (bool) array_filter($post, fn($v) => $v instanceof CURLFile);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
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
    check('station page links to sign in', str_contains($r['body'], 'href="' . $basePath . '/signin"'));
    $r = $b->go('manage');
    check('manager page sends you to sign in', $r['status'] === 303 && str_ends_with($location($r), '/signin?next=manage'), $location($r));
    $r = $b->go('account');
    check('account page sends you to sign in, then back', $r['status'] === 303 && str_ends_with($location($r), '/signin?next=account'), $location($r));
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
    check('player feed gives the server clock and settings', $r['status'] === 200 && is_float($j['server_now'] ?? null) && abs($j['server_now'] - microtime(true)) < 30 && ($j['settings']['crossfade_seconds'] ?? 0) == 6, (string) $r['status']);
    check('player feed is never cached', str_contains(implode(',', $r['headers']['cache-control'] ?? []), 'no-store'));
    foreach (['config.local.php', 'install.key', 'src/Auth.php', 'cli/set-password.php', 'cli/plan.php', 'tests/web_test.php', 'storage/sessions/', 'storage/cache/throttle/'] as $private) {
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
        $r = $a->go('manage');
        check('manager page greets the DJ', $r['status'] === 200 && preg_match('/Good (morning|afternoon|evening), Web</', $r['body']) === 1, (string) $r['status']);
        check('a DJ sees the overview but not the library', str_contains($r['body'], '>Overview</a>') && !str_contains($r['body'], '>Music library</a>'));
        check('a DJ can’t open the library', $a->go('manage/library')['status'] === 403);
        check('or Sound & safety', $a->go('manage/sound')['status'] === 403);
        check('a DJ has no Skip button', !str_contains($r['body'], 'Skip this song'));
        check('a DJ can’t skip by sending the form', $a->go('manage/skip', ['csrf' => Browser::csrf($r)])['status'] === 403);
        check('header shows the name and sign out', str_contains($r['body'], 'Web Test DJ</a>') && str_contains($r['body'], 'Sign out'));
        $headerCsrf = Browser::csrf($r);
        $r = $a->go('signin');
        check('sign-in page sends a signed-in user on', $r['status'] === 303 && str_ends_with($location($r), '/manage'));

        $phone = new Browser($base);
        $phone->go('signin', ['csrf' => Browser::csrf($phone->go('signin')), 'email' => $email, 'password' => $pass]);
        check('a second device can sign in too', $phone->go('manage')['status'] === 200);

        $r = $a->go('account');
        check('account page opens', $r['status'] === 200 && str_contains($r['body'], $email));
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
        check('a manager sees every section', $r['status'] === 200 && str_contains($r['body'], '>Music library</a>') && str_contains($r['body'], '>Playlists</a>') && str_contains($r['body'], '>Schedule</a>') && str_contains($r['body'], '>DJs</a>') && str_contains($r['body'], '>Sound &amp; safety</a>'));
        $csrf = Browser::csrf($r);
        check('a manager has a Skip button', str_contains($r['body'], 'Skip this song'));
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
        check('the library opens with a drop zone', $r['status'] === 200 && str_contains($r['body'], 'id="drop"') && str_contains($r['body'], 'upload.js'));
        $u = $upload("$tmpDir/song.mp3", 'my song.mp3');
        $tracks[] = $trackId = (int) ($u['id'] ?? 0);
        check('an MP3 uploads, with its tag title', ($u['ok'] ?? false) && !$u['duplicate'] && $u['title'] === $title, json_encode($u));
        $again = $upload("$tmpDir/song.mp3", 'copy.mp3');
        check('the same file again is “already there”', ($again['ok'] ?? false) && $again['duplicate'] && (int) $again['id'] === $trackId);
        $fake = $upload("$tmpDir/fake.mp3", 'fake.mp3');
        check('a file that isn’t audio is refused', !($fake['ok'] ?? true) && str_contains((string) $fake['error'], 'not an MP3'), (string) ($fake['error'] ?? ''));
        $txt = $upload("$tmpDir/fake.mp3", 'notes.txt');
        check('only MP3 files are taken', !($txt['ok'] ?? true) && str_contains((string) $txt['error'], 'Only MP3'));
        $file = $db->run('SELECT file FROM ' . $db->table('tracks') . ' WHERE id = ?', [$trackId])->fetchColumn();
        check('the song is served from storage', (new Browser($base))->go("storage/media/$file", null, ['Range: bytes=0-1'])['status'] === 206);
        $r = $m->go('manage/library?q=' . rawurlencode($tag));
        check('search finds it', substr_count($r['body'], '<td><b>') === 1 && str_contains($r['body'], htmlspecialchars($title)));
        $r = $m->go('manage/library', ['csrf' => $csrf, 'action' => 'update', 'id' => $trackId, 'title' => "$title (edited)", 'artist' => 'Test Choir']);
        check('its title can be changed', $flashed($p = $after($m, $r), 'Saved.') && str_contains($p['body'], 'edited'));

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

        // DJs
        $r = $m->go('manage/djs');
        check('DJs lists everyone, you marked', $r['status'] === 200 && str_contains($r['body'], 'Web Test Manager</b> <span class="pill">You</span>'));
        $djEmail = "web-test-$tag-dj@example.test";
        $r = $m->go('manage/djs', ['csrf' => $csrf, 'action' => 'add', 'name' => 'Web Test New DJ', 'email' => $djEmail, 'role' => 'dj', 'password' => 'first pass 123']);
        check('a manager adds a DJ', $flashed($after($m, $r), 'Added.'));
        $users[] = $newDj = (int) $db->run('SELECT id FROM ' . $db->table('users') . ' WHERE email = ?', [$djEmail])->fetchColumn();
        $r = $m->go('manage/djs', ['csrf' => $csrf, 'action' => 'add', 'name' => 'Web Test Boss', 'email' => "web-test-$tag-boss@example.test", 'role' => 'admin', 'password' => 'first pass 123']);
        check('a manager can’t add an admin', $flashed($after($m, $r), 'Not added'));
        $d = new Browser($base);
        $d->go('signin', ['csrf' => Browser::csrf($d->go('signin')), 'email' => $djEmail, 'password' => 'first pass 123']);
        check('the new DJ signs in', $d->go('manage')['status'] === 200);
        $r = $m->go('manage/djs', ['csrf' => $csrf, 'action' => 'password', 'id' => $newDj, 'password' => 'second pass 123']);
        check('a manager sets a new password', $flashed($after($m, $r), 'New password set.'));
        check('which signs the DJ out', $d->go('manage')['status'] === 303);
        $r = $m->go('manage/djs', ['csrf' => $csrf, 'action' => 'remove', 'id' => $newDj]);
        check('a manager removes the DJ', $flashed($after($m, $r), 'Removed.'));
        $d2 = new Browser($base);
        $r = $d2->go('signin', ['csrf' => Browser::csrf($d2->go('signin')), 'email' => $djEmail, 'password' => 'second pass 123']);
        check('who can no longer sign in', $r['status'] === 401);
        $r = $m->go('manage/djs', ['csrf' => $csrf, 'action' => 'role', 'id' => $mgrId, 'role' => 'dj']);
        check('nobody changes their own role', $flashed($after($m, $r), 'You can’t change'));

        // Sound & safety (the station's own values are put back in the cleanup)
        $origSettings = $db->run('SELECT settings FROM ' . $db->table('stations') . ' WHERE id = ?', [$stationId])->fetchColumn();
        $r = $m->go('manage/sound');
        check('Sound & safety opens with five sliders', $r['status'] === 200 && substr_count($r['body'], 'type="range"') === 5, (string) $r['status']);
        $values = ['csrf' => $csrf, 'crossfade_seconds' => '5', 'skip_fade_seconds' => '1.5', 'live_fade_seconds' => '4', 'live_timeout_seconds' => '10', 'recent_tracks' => '3'];
        $r = $m->go('manage/sound', ['crossfade_seconds' => '99'] + $values);
        $p = $after($m, $r);
        check('a crossfade of 99 s is refused', $flashed($p, 'Not saved: Crossfade between songs: choose 0 to 12 s.') && str_contains($p['body'], 'value="99"'));
        $r = $m->go('manage/sound', $values);
        check('good values are saved', $flashed($after($m, $r), 'Saved. New settings apply from the next song.'));
        $feed = json_decode((new Browser($base))->go('api/timeline.php')['body'], true);
        check('the player gets the new fades', ($feed['settings']['crossfade_seconds'] ?? null) == 5 && ($feed['settings']['live_fade_seconds'] ?? null) == 4, json_encode($feed['settings'] ?? null));
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
        }
        $db->run('DELETE FROM ' . $db->table('playlists') . " WHERE name LIKE 'Web test list %'");
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
            }
        }
        $planner->replan(microtime(true)); // nothing planned may use a hidden song
        $left = (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('users') . " WHERE email LIKE 'web-test-%'")->fetchColumn();
        echo "Cleanup: throwaway accounts deleted ($left left)" . ($kept ? ", $kept test song hidden (it was on air)" : '') . "\n";
    }
    if (isset($tmpDir)) {
        remove_tree($tmpDir);
    }
}
finish();
