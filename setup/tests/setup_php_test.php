<?php
// The standalone setup (setup.php) end to end on a real web server: it uploads a built setup.php
// into throwaway folders, runs it through a browser's steps and the station installer it hands
// over to, checks each station works, then removes everything it made.
//   A: a subfolder (the default), with the safety checks: an old upload, a bad name, a failed
//      download, a download that isn't Stationwagon, a folder already in use; then the installer
//      with a database typed in and the cron line shown to copy
//   B: this folder (refused while other files are there), then the installer with a database made
//      through cPanel and a cron job added through cPanel (the cPanel stand-in only)
//   C: a subdomain made through cPanel: a wrong password, a new one, one made earlier, one in use
// Settings come from the environment:
//   SW_WEBROOT       a folder the web server serves (the test makes and removes its own folder in it)
//   SW_WEBROOT_URL   its address
//   SW_SETUP_DIR     the built setup (python tools/build-setup.py): setup.php and stationwagon.zip
//   SW_SOURCE        optional: download from here (the real release) instead of a copy of the built zip
//   SW_CPANEL        "stub" (starts wordpress/tests/cpanel-stub.php here; needs Apache's SetEnv) or
//                    "none" (the real cPanel, with no login to use: only a wrong password is tried)
//   SW_DB            JSON {host,name,user,pass}: an empty database the test's stations share by prefix
//   SW_CPANEL_USER   for "none": the account name
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$failures = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . ($detail !== '' ? '  (' . substr($detail, 0, 300) . ')' : '') . "\n";
    if (!$ok) { $failures++; }
}
function need(string $name): string
{
    $v = getenv($name);
    if ($v === false || $v === '') { fwrite(STDERR, "Set $name.\n"); exit(2); }
    return $v;
}
function http(string $method, string $url, array $post = [], bool $follow = true, array $headers = []): array
{
    $c = curl_init($url);
    curl_setopt($c, CURLOPT_HTTPHEADER, $headers);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_HEADER => !$follow,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 300, CURLOPT_USERAGENT => 'Stationwagon setup test']);
    if ($method === 'POST') {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($c);
    $r = ['code' => (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), 'body' => $body, 'error' => curl_error($c),
        'location' => (string) curl_getinfo($c, CURLINFO_REDIRECT_URL)];
    curl_close($c);
    return $r;
}
function text(string $html): string
{
    $html = (string) preg_replace('#<(style|script)\b.*?</\1>#s', '', $html);
    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));
}
function rmTree(string $p): void
{
    if (is_dir($p) && !is_link($p)) {
        foreach (array_diff(scandir($p), ['.', '..']) as $e) { rmTree("$p/$e"); }
        @rmdir($p);
    } elseif (file_exists($p)) {
        @unlink($p);
    }
}
function leftovers(string $dir): array
{
    return array_values(array_filter((array) @scandir($dir), fn($e) => str_starts_with($e, '.sw-setup-')));
}

$webroot = rtrim(str_replace('\\', '/', need('SW_WEBROOT')), '/');
$webUrl = rtrim(need('SW_WEBROOT_URL'), '/');
$built = rtrim(need('SW_SETUP_DIR'), '/\\');
$mode = need('SW_CPANEL');
$db = json_decode(need('SW_DB'), true);
$run = 'sws' . substr((string) getmypid(), -4) . random_int(10, 99);
$base = "$webroot/$run";
$url = "$webUrl/$run";
$siteHost = preg_replace('/^www\./', '', (string) parse_url($webUrl, PHP_URL_HOST));
$admin = ['admin_name' => 'Setup Tester', 'admin_email' => "$run@example.test", 'admin_password' => 'station-' . bin2hex(random_bytes(6)), 'timezone' => 'Africa/Johannesburg'];
$prefixes = [];
mkdir("$base/src", 0775, true);
copy("$built/stationwagon.zip", "$base/src/stationwagon.zip");
$z = new ZipArchive();
$z->open("$base/src/junk.zip", ZipArchive::CREATE);
$z->addFromString('README.txt', 'not a station');
$z->close();
$source = getenv('SW_SOURCE') ?: "$url/src/stationwagon.zip";

// ---- cPanel -------------------------------------------------------------------------------------
$stub = null;
$cpUrl = null;
if ($mode === 'stub') {
    $cpUser = 'swstub';
    $cpSecret = 'stub-' . bin2hex(random_bytes(8));
    $stubState = sys_get_temp_dir() . "/sw-cpanel-$run.json";
    $stubHome = "$webroot/sw-subtest";
    @mkdir($stubHome, 0775, true);
    $port = 9600 + random_int(0, 300);
    $env = ['SW_STUB_STATE' => $stubState, 'SW_STUB_USER' => $cpUser, 'SW_STUB_SECRET' => $cpSecret, 'SW_STUB_HOME' => $stubHome, 'SW_STUB_SITE' => "$siteHost,deep.$siteHost"] + getenv();
    $stub = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", dirname(__DIR__, 2) . '/wordpress/tests/cpanel-stub.php'],
        [['pipe', 'r'], ['file', sys_get_temp_dir() . "/sw-stub-$run.log", 'a'], ['file', sys_get_temp_dir() . "/sw-stub-$run.log", 'a']], $pipes, null, $env);
    usleep(500000);
    $cpUrl = "http://127.0.0.1:$port";
    $cpState = fn() => json_decode((string) @file_get_contents($stubState), true) ?: [];
} else {
    $cpUser = need('SW_CPANEL_USER');
}

/** Upload setup.php (built to download from $from) into $dir; returns its address. */
function upload(string $dir, ?string $from = null): string
{
    global $built, $source, $cpUrl, $base, $url;
    $php = (string) file_get_contents("$built/setup.php");
    $php = (string) preg_replace("/define\('SW_SOURCE', '[^']*'\);/", "define('SW_SOURCE', '" . ($from ?? $source) . "');", $php);
    if ($cpUrl !== null) {
        $php = str_replace("define('SW_MAX_AGE', 3600);", "define('SW_MAX_AGE', 3600);\ndefine('SW_CPANEL_URL', '$cpUrl');", $php);
    }
    @mkdir($dir, 0775, true);
    file_put_contents("$dir/setup.php", $php);
    return $url . substr($dir, strlen($base)) . '/setup.php';
}

/** Fill in the station installer at $station (its address, ending in /) with the key from setup.php. */
function installer(string $station, string $key, array $in): array
{
    global $admin;
    return http('POST', $station . 'index.php/install', $in + $admin + ['action' => 'install', 'setup_key' => $key]);
}

/** POST to setup.php without following the redirect: [response, the key from the Location]. */
function runSetup(string $setupUrl, array $in): array
{
    $r = http('POST', $setupUrl, $in, false);
    parse_str((string) parse_url($r['location'], PHP_URL_QUERY), $q);
    return [$r, (string) ($q['key'] ?? '')];
}

try {
    // ---- A ------------------------------------------------------------------------------------
    echo "A: a subfolder (the default), with its safety checks, then a database typed in\n";
    $setupA = upload("$base/a");
    $r = http('GET', $setupA);
    $t = text($r['body']);
    check('setup.php opens with the server check', $r['code'] === 200 && str_contains($t, 'Server check') && str_contains($t, 'MySQL support (PDO)') && str_contains($t, 'Uploaded in the last hour'), $r['code'] . ' ' . substr($t, 0, 200));
    check('every required check passes here', !str_contains($r['body'], 'class="bad"') && !preg_match('/<button class="btn" type="submit" disabled/', $r['body']));
    check('a subfolder called radio is picked by default', (bool) preg_match('/value="folder" checked/', $r['body']) && str_contains($r['body'], 'name="folder" id="folder" value="radio"')
        && str_contains($t, 'Recommended'));
    check('it offers this folder and a subdomain too', str_contains($r['body'], 'value="here"') && str_contains($r['body'], 'value="subdomain"') && str_contains($t, ".$siteHost"));

    touch("$base/a/setup.php", time() - 7200);
    $r = http('GET', $setupA);
    check('an upload older than an hour: told to upload it again, button off', str_contains(text($r['body']), 'Upload setup.php again') && str_contains($r['body'], 'type="submit" disabled'));
    http('POST', $setupA, ['address' => 'folder', 'folder' => 'radio']);
    check('  and posting anyway does nothing', !file_exists("$base/a/radio") && is_file("$base/a/setup.php"));
    touch("$base/a/setup.php");

    $r = http('POST', $setupA, ['address' => 'folder', 'folder' => 'Bad Name!']);
    check('a folder name with spaces or capitals is refused', str_contains(text($r['body']), 'Use lowercase letters, digits and dashes') && !file_exists("$base/a/bad name!"));
    $r = http('POST', $setupA, ['address' => 'folder', 'folder' => 'wp-admin']);
    check('  and so is a WordPress folder', str_contains(text($r['body']), 'Use lowercase letters'));

    $setupBadSrc = upload("$base/a2", "$url/src/missing.zip");
    $r = http('POST', $setupBadSrc, ['address' => 'folder', 'folder' => 'radio']);
    check('a failed download stops with a clear message', str_contains(text($r['body']), 'Setup stopped: Downloading Stationwagon failed'), text($r['body']));
    check('  leaving nothing behind and setup.php still there', !file_exists("$base/a2/radio") && leftovers("$base/a2") === [] && is_file("$base/a2/setup.php"), implode(',', leftovers("$base/a2")));
    $setupJunk = upload("$base/a3", "$url/src/junk.zip");
    $r = http('POST', $setupJunk, ['address' => 'folder', 'folder' => 'radio']);
    check('a download that isn\'t Stationwagon is refused', str_contains(text($r['body']), 'isn’t a Stationwagon package') && leftovers("$base/a3") === [] && !file_exists("$base/a3/radio"), substr(strstr(text($r['body']), 'Download and set up') ?: text($r['body']), 0, 0) . (strstr(text($r['body']), 'Setup stopped') ?: 'no message') . ' / ' . implode(',', leftovers("$base/a3")));

    mkdir("$base/a/radio");
    file_put_contents("$base/a/radio/index.html", 'mine');
    $r = http('POST', $setupA, ['address' => 'folder', 'folder' => 'radio']);
    check('a folder already in use is refused', str_contains(text($r['body']), 'The folder radio already has files in it') && file_get_contents("$base/a/radio/index.html") === 'mine'
        && leftovers("$base/a") === [], text($r['body']));
    rmTree("$base/a/radio");
    file_put_contents("$base/a/error_log", 'old');
    mkdir("$base/a/radio");

    [$r, $key] = runSetup($setupA, ['address' => 'folder', 'folder' => 'radio']);
    $station = "$url/a/radio/";
    check('set up: it goes on to the station\'s installer with the setup key', $r['code'] === 303 && str_starts_with($r['location'], $station . 'index.php/install?key=') && strlen($key) === 24, $r['code'] . ' ' . $r['location'] . ' ' . text($r['body']));
    check('  the station is in the folder (an empty one is fine), with the same key in install.key', is_file("$base/a/radio/index.php") && is_file("$base/a/radio/src/Installer.php")
        && trim((string) @file_get_contents("$base/a/radio/install.key")) === $key);
    check('  and setup.php removed itself, leaving nothing else', !file_exists("$base/a/setup.php") && leftovers("$base/a") === [] && !file_exists("$base/a/radio/tests"));
    $r = http('GET', $r['location']);
    $t = text($r['body']);
    check('the installer opens with the key filled in', $r['code'] === 200 && str_contains($r['body'], 'name="setup_key" type="text" value="' . $key . '"') && str_contains($t, 'Filled in by setup.php'));
    check('  database "make one for me" and jobs "during visits" picked', (bool) preg_match('/name="database" value="new" checked/', $r['body']) && (bool) preg_match('/name="jobs" value="visits" checked/', $r['body']));
    $r = installer($station, 'not-the-key', ['database' => 'manual', 'jobs' => 'manual', 'station_name' => "Radio $run A"] + $db);
    check('a wrong key is refused', str_contains(text($r['body']), 'That setup key is wrong') && !file_exists("$base/a/radio/config.local.php"));
    $r = installer($station, $key, ['database' => 'manual', 'jobs' => 'manual', 'station_name' => "Radio $run A", 'db_host' => $db['host'], 'db_name' => $db['name'],
        'db_user' => $db['user'], 'db_pass' => 'not-the-password', 'db_prefix' => 'swa_', 'db_port' => '3306']);
    check('a wrong database password stops it, nothing saved', !str_contains(text($r['body']), 'is set up') && !file_exists("$base/a/radio/config.local.php"), substr(text($r['body']), 0, 200));
    $prefixes[] = 'swa_';
    $r = installer($station, $key, ['database' => 'manual', 'jobs' => 'manual', 'station_name' => "Radio $run A", 'db_host' => $db['host'], 'db_name' => $db['name'],
        'db_user' => $db['user'], 'db_pass' => $db['pass'], 'db_prefix' => 'swa_', 'db_port' => '3306']);
    $t = text($r['body']);
    check('the installer sets it up with the database typed in', str_contains($t, "Radio $run A is set up") && !str_contains($t, 'Signed in to cPanel'), substr($t, 0, 300));
    check('  the cron line to copy is shown, for this folder', str_contains($t, 'Your cron job') && str_contains(str_replace('\\', '/', $r['body']), '/a/radio/cli/plan.php &gt; /dev/null 2&gt;&amp;1'));
    $cfg = (string) @file_get_contents("$base/a/radio/config.local.php");
    check('  the settings say a cron job is coming, and the key is gone', str_contains($cfg, "'cron' => true") && !file_exists("$base/a/radio/install.key"));
    $r = http('GET', $station);
    check('the station answers', $r['code'] === 200 && str_contains($r['body'], "Radio $run A"), (string) $r['code']);

    // ---- B ------------------------------------------------------------------------------------
    echo "B: this folder, then a database and a cron job made through cPanel\n";
    $setupB = upload("$base/b");
    file_put_contents("$base/b/notes.txt", 'mine');
    $r = http('POST', $setupB, ['address' => 'here']);
    check('this folder is refused while other files are in it', str_contains(text($r['body']), 'This folder already has other files (notes.txt)') && !file_exists("$base/b/index.php"), text($r['body']));
    unlink("$base/b/notes.txt");
    [$r, $key] = runSetup($setupB, ['address' => 'here']);
    check('set up in this folder', $r['code'] === 303 && $r['location'] === "$url/b/index.php/install?key=$key" && is_file("$base/b/index.php") && !file_exists("$base/b/setup.php"), $r['code'] . ' ' . $r['location'] . ' ' . text($r['body']));
    // the web server has just served setup.php from this folder; the hand-over waits until it sees the station's .htaccess
    $r = http('GET', "$url/b/index.php/install?key=$key");
    check('  the installer answers straight away', $r['code'] === 200 && str_contains($r['body'], 'value="' . $key . '"'), $r['code'] . ' ' . substr(text($r['body']), 0, 100));
    if ($mode === 'stub') {
        // the station's installer finds the stand-in through Apache's environment
        file_put_contents("$base/b/.htaccess", "\nSetEnv SW_CPANEL_URL $cpUrl\n", FILE_APPEND);
        $r = installer("$url/b/", $key, ['database' => 'new', 'jobs' => 'cpanel', 'station_name' => "Radio $run B", 'cpanel_user' => $cpUser, 'cpanel_pass' => '']);
        check('without the cPanel password it asks for it', str_contains(text($r['body']), 'Your choices above need your cPanel username and password'));
        $r = installer("$url/b/", $key, ['database' => 'new', 'jobs' => 'cpanel', 'station_name' => "Radio $run B", 'cpanel_user' => $cpUser, 'cpanel_pass' => 'wrong-password']);
        check('a wrong cPanel password is refused, nothing made', str_contains(text($r['body']), 'cPanel refused that username and password') && ($cpState()['databases'] ?? []) === [], substr(text($r['body']), 0, 300));
        $r = installer("$url/b/", $key, ['database' => 'new', 'jobs' => 'cpanel', 'station_name' => "Radio $run B", 'cpanel_user' => $cpUser, 'cpanel_pass' => $cpSecret]);
        $t = text($r['body']);
        $st = $cpState();
        check('set up with a database made through cPanel', str_contains($t, "Radio $run B is set up") && str_contains($t, 'Signed in to cPanel') && count($st['databases'] ?? []) === 1
            && str_contains($t, 'Made the database ' . ($st['databases'][0] ?? '?')), substr($t, 0, 300));
        $line = array_values(array_filter($st['cron'] ?? [], fn($c) => str_contains(str_replace('\\', '/', $c), "/$run/b/cli/plan.php")));
        check('  and a cron job added for this folder', count($line) === 1 && str_contains($t, 'Added a cron job every 5 minutes') && !str_contains($t, 'Your cron job'), implode(' | ', $st['cron'] ?? []));
        $cfg = (string) @file_get_contents("$base/b/config.local.php");
        check('  its settings use the new database', str_contains($cfg, "'name' => '" . ($st['databases'][0] ?? '?') . "'") && str_contains($cfg, "'cron' => true"));
        $r = http('GET', "$url/b/");
        check('the station answers', $r['code'] === 200 && str_contains($r['body'], "Radio $run B"), (string) $r['code']);
    } else {
        $prefixes[] = 'swb_';
        $r = installer("$url/b/", $key, ['database' => 'manual', 'jobs' => 'visits', 'station_name' => "Radio $run B", 'db_host' => $db['host'], 'db_name' => $db['name'],
            'db_user' => $db['user'], 'db_pass' => $db['pass'], 'db_prefix' => 'swb_', 'db_port' => '3306']);
        $t = text($r['body']);
        check('set up with jobs during visits: no cPanel, no cron line', str_contains($t, "Radio $run B is set up") && str_contains($t, 'Background jobs run during visits') && !str_contains($t, 'Your cron job'), substr($t, 0, 300));
        $cfg = (string) @file_get_contents("$base/b/config.local.php");
        check('  its settings promise no cron job', $cfg !== '' && !str_contains($cfg, "'cron'"));
        $r = http('GET', "$url/b/");
        check('the station answers', $r['code'] === 200 && str_contains($r['body'], "Radio $run B"), (string) $r['code']);
    }

    // ---- C ------------------------------------------------------------------------------------
    echo "C: a subdomain made through cPanel\n";
    $setupC = upload("$base/c");
    $r = http('POST', $setupC, ['address' => 'subdomain', 'subdomain' => "$run-c", 'cpanel_user' => $cpUser, 'cpanel_pass' => '']);
    check('without the cPanel password it asks for it', str_contains(text($r['body']), 'needs your cPanel username and password'));
    $r = http('POST', $setupC, ['address' => 'subdomain', 'subdomain' => "$run-c", 'cpanel_user' => $cpUser, 'cpanel_pass' => 'wrong-password']);
    check('a wrong cPanel password is refused, setup.php still there', str_contains(text($r['body']), 'cPanel refused that username and password') && is_file("$base/c/setup.php") && leftovers("$base/c") === [], text($r['body']));
    if ($mode === 'stub') {
        $host = "$run-c.$siteHost";
        $r = http('POST', $setupC, ['address' => 'subdomain', 'subdomain' => "$run-c", 'cpanel_user' => $cpUser, 'cpanel_pass' => $cpSecret]);
        $t = text($r['body']);
        $dir = "$stubHome/$host";
        check("the subdomain $host is made, its folder holds the station", isset($cpState()['subdomains'][$host]) && str_contains($t, "Made the subdomain $host") && is_file("$dir/index.php"), substr($t, 0, 300));
        check('  until the DNS points here: the one A record to add', str_contains($t, 'One step left') && (bool) preg_match('#<td>A</td><td><code>' . preg_quote($host) . '</code></td><td><code>192\.0\.2\.10</code>#', $r['body']));
        $key = trim((string) @file_get_contents("$dir/install.key"));
        check('  no Cloudflare note for a subdomain one level down', !str_contains($t, 'DNS at Cloudflare?'));
        check('  and the installer link with its key', str_contains($r['body'], "/$host/index.php/install?key=$key") && strlen($key) === 24 && !file_exists("$base/c/setup.php"));
        $r = http('GET', "$webUrl/sw-subtest/$host/index.php/install?key=$key");
        check('the installer answers in the subdomain\'s folder, key filled in', $r['code'] === 200 && str_contains($r['body'], 'value="' . $key . '"'), (string) $r['code']);

        // one made earlier, by a try that stopped: its folder is empty, so it is used
        $host2 = "$run-d.$siteHost";
        $s = $cpState();
        $s['subdomains'][$host2] = "$host2";
        file_put_contents($stubState, json_encode($s));
        mkdir("$stubHome/$host2");
        $setupD = upload("$base/d");
        $r = http('POST', $setupD, ['address' => 'subdomain', 'subdomain' => "$run-d", 'cpanel_user' => $cpUser, 'cpanel_pass' => $cpSecret]);
        check('a subdomain made earlier with an empty folder is used', str_contains(text($r['body']), "Uses the subdomain $host2 made earlier") && is_file("$stubHome/$host2/index.php"), substr(text($r['body']), 0, 300));
        $setupE = upload("$base/e");
        $r = http('POST', $setupE, ['address' => 'subdomain', 'subdomain' => "$run-d", 'cpanel_user' => $cpUser, 'cpanel_pass' => $cpSecret]);
        check('one already holding a site is refused', str_contains(text($r['body']), "$host2 already exists. Choose another name.") && is_file("$base/e/setup.php") && leftovers("$base/e") === [], text($r['body']));
        // a site that is itself a subdomain (deep.localhost, as wordwave.controll.co.za is): the new name is two levels down
        $setupF = upload("$base/f");
        $r = http('POST', $setupF, ['address' => 'subdomain', 'subdomain' => "$run-f", 'cpanel_user' => $cpUser, 'cpanel_pass' => $cpSecret], true,
            ['Host: deep.' . parse_url($webUrl, PHP_URL_HOST) . (parse_url($webUrl, PHP_URL_PORT) ? ':' . parse_url($webUrl, PHP_URL_PORT) : '')]);
        $t = text($r['body']);
        check("a subdomain of a subdomain ($run-f.deep.$siteHost) gets the Cloudflare note: DNS only", str_contains($t, "Made the subdomain $run-f.deep.$siteHost")
            && str_contains($t, 'DNS at Cloudflare?') && str_contains($t, 'DNS only'), substr($t, 0, 300));
    }
} finally {
    echo "Cleaning up\n";
    rmTree($base);
    try {
        $pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']}", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($prefixes as $p) {
            foreach ($pdo->query("SHOW TABLES LIKE '" . str_replace('_', '\\_', $p) . "%'")->fetchAll(PDO::FETCH_COLUMN) as $tb) { $pdo->exec("DROP TABLE `$tb`"); }
        }
    } catch (Throwable $ex) { echo "  (tables: {$ex->getMessage()})\n"; }
    if ($mode === 'stub') {
        $st = $cpState();
        $root = new PDO('mysql:host=' . (getenv('SW_TEST_DB_HOST') ?: '127.0.0.1'), 'root', '');
        foreach ($st['databases'] ?? [] as $d) { $root->exec("DROP DATABASE IF EXISTS `$d`"); }
        foreach ($st['users'] ?? [] as $u) { $root->exec("DROP USER IF EXISTS '$u'@'%'"); }
        foreach (array_keys($st['subdomains'] ?? []) as $h) { rmTree("$stubHome/$h"); }
        @rmdir($stubHome);
        @unlink($stubState);
        if ($stub) { proc_terminate($stub); }
    }
}
echo $failures === 0 ? "All checks passed\n" : "$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
