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
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($status === 0) {
            fwrite(STDERR, "Request failed: " . curl_error($ch) . "
");
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
        check('manager page greets the DJ', $r['status'] === 200 && str_contains($r['body'], 'Hello, Web Test DJ'), (string) $r['status']);
        check('a DJ sees the studio but not the library', str_contains($r['body'], '<b>Studio</b>') && !str_contains($r['body'], '<b>Library</b>'));
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
    }
} finally {
    if ($db !== null && $created !== null) {
        $db->run('DELETE FROM ' . $db->table('users') . ' WHERE id = ?', [$created]);
        $left = (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('users') . " WHERE email LIKE 'web-test-%'")->fetchColumn();
        echo "Cleanup: throwaway account deleted ($left left)\n";
    }
}
finish();
