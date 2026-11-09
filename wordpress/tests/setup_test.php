<?php
// The Stationwagon WordPress plugin, end to end, on a real test WordPress: installs the built
// plugin zip, signs in to wp-admin and sets up a station through the wizard three ways, checks each
// station works, then removes everything it made.
//   A: a folder of the site, this site's database, jobs during visits (plus: errors, the shortcode,
//      the station following a plugin update, a second setup refused)
//   B: a folder, a new database made through cPanel, a cron job added through cPanel
//   C: a subdomain made through cPanel, a database typed in by hand, the cron line shown to copy
// Settings come from the environment:
//   SW_WP            the WP-CLI command for the test site (e.g. "php wp-cli.phar --path=C:/laragon/www/stationwagon-wp")
//   SW_WP_URL        the site's address
//   SW_WP_ADMIN      a file: the test site's admin username, then password (gitignored *.local.txt)
//   SW_PLUGIN_ZIP    the built plugin (python tools/build-wp-plugin.py)
//   SW_CPANEL        "stub" (starts wordpress/tests/cpanel-stub.php here) or "real" with
//                    SW_CPANEL_USER and SW_CPANEL_TOKEN (a temporary cPanel API token)
//   SW_MANUAL_DB     JSON {host,name,user,pass}: an empty database for setup C
//   SW_SUB_RESOLVE   for "real": the server's public IP, to reach a new subdomain before its DNS exists
//                    (127.0.0.1 gets the default web page on cPanel)
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
function wp(string $args, bool $quiet = false): string
{
    $out = []; $code = 0;
    exec(need('SW_WP') . ' ' . $args . ' 2>&1', $out, $code);
    $s = trim(implode("\n", $out));
    if ($code !== 0 && !$quiet) { echo "    (wp $args: $s)\n"; }
    return $s;
}
/** One browser: a cookie jar, redirects followed. */
function http(string $method, string $url, array $post = [], array $opt = []): array
{
    static $jar = null;
    $jar ??= tempnam(sys_get_temp_dir(), 'swjar');
    $c = curl_init($url);
    curl_setopt_array($c, $opt + [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 240, CURLOPT_USERAGENT => 'Stationwagon setup test',
    ]);
    if ($method === 'POST') {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($c);
    $r = ['code' => (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), 'url' => (string) curl_getinfo($c, CURLINFO_EFFECTIVE_URL), 'body' => $body, 'error' => curl_error($c)];
    curl_close($c);
    return $r;
}
function between(string $s, string $a, string $b): string
{
    $i = strpos($s, $a);
    if ($i === false) { return ''; }
    $i += strlen($a);
    $j = strpos($s, $b, $i);
    return $j === false ? '' : substr($s, $i, $j - $i);
}
function text(string $html): string
{
    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));
}

$site = rtrim(need('SW_WP_URL'), '/');
[$wpUser, $wpPass] = array_map('trim', array_slice(file(need('SW_WP_ADMIN')), 0, 2));
$zip = need('SW_PLUGIN_ZIP');
$mode = need('SW_CPANEL');
$manual = json_decode(need('SW_MANUAL_DB'), true);
$run = 'swt' . substr((string) getmypid(), -4) . random_int(10, 99);
$admin = ['name' => 'Setup Tester', 'email' => "$run@example.test", 'pass' => 'station-' . bin2hex(random_bytes(6))];
$page = "$site/wp-admin/admin.php?page=stationwagon";
$made = ['dirs' => [], 'dbs' => [], 'tables' => [], 'subdomains' => [], 'cron' => [], 'posts' => []];
$wpDb = ['host' => wp('config get DB_HOST'), 'name' => wp('config get DB_NAME'), 'user' => wp('config get DB_USER'), 'pass' => wp('config get DB_PASSWORD')];
$abspath = rtrim(str_replace('\\', '/', wp('eval "echo ABSPATH;"')), '/');

function pdo(array $c): PDO
{
    [$h, $p] = preg_match('/^(.+):(\d+)$/', $c['host'], $m) ? [$m[1], (int) $m[2]] : [$c['host'], 3306];
    return new PDO("mysql:host=$h;port=$p;dbname={$c['name']}", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
function rmTree(?string $p): void
{
    if ($p === null || $p === '') { return; }
    if (is_dir($p) && !is_link($p)) {
        foreach (array_diff(scandir($p), ['.', '..']) as $e) { rmTree("$p/$e"); }
        @rmdir($p);
    } elseif (file_exists($p)) {
        @unlink($p);
    }
}

// ---- cPanel: the stand-in here, or the real one with a temporary token -------------------------
$stub = null;
if ($mode === 'stub') {
    $cpUser = 'swstub';
    $cpSecret = 'stub-' . bin2hex(random_bytes(8));
    $stubState = sys_get_temp_dir() . "/sw-cpanel-$run.json";
    $stubHome = dirname($abspath) . '/sw-subtest';
    @mkdir($stubHome, 0775, true);
    $port = 9600 + random_int(0, 300);
    $env = ['SW_STUB_STATE' => $stubState, 'SW_STUB_USER' => $cpUser, 'SW_STUB_SECRET' => $cpSecret, 'SW_STUB_HOME' => $stubHome,
        'SW_STUB_SITE' => (string) parse_url($site, PHP_URL_HOST)] + getenv();
    $stub = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/cpanel-stub.php'], [['pipe', 'r'], ['file', sys_get_temp_dir() . "/sw-stub-$run.log", 'a'], ['file', sys_get_temp_dir() . "/sw-stub-$run.log", 'a']], $pipes, null, $env);
    usleep(500000);
    wp("config set STATIONWAGON_CPANEL_URL http://127.0.0.1:$port --type=constant --quiet");
    $cpState = fn() => json_decode((string) @file_get_contents($stubState), true) ?: [];
} elseif ($mode === 'none') {
    // Real cPanel, but no login to use (a host without API tokens): only a wrong password is tried,
    // and the setups that need cPanel are left to the stand-in on this PC
    $cpUser = need('SW_CPANEL_USER');
    $cpSecret = 'not-the-password-' . bin2hex(random_bytes(6));
    $cpState = fn() => ['cron' => [], 'subdomains' => [], 'databases' => [], 'users' => []];
} else {
    $cpUser = need('SW_CPANEL_USER');
    $cpSecret = need('SW_CPANEL_TOKEN');
    $cp = function (string $path, array $args = []) use ($cpUser, $cpSecret): array {
        $c = curl_init('https://127.0.0.1:2083' . $path . '?' . http_build_query($args));
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => ["Authorization: cpanel $cpUser:$cpSecret"], CURLOPT_TIMEOUT => 60]);
        $r = json_decode((string) curl_exec($c), true) ?: [];
        curl_close($c);
        return $r;
    };
    $cpState = function () use ($cp): array {
        $cron = array_column($cp('/json-api/cpanel', ['cpanel_jsonapi_apiversion' => 2, 'cpanel_jsonapi_module' => 'Cron', 'cpanel_jsonapi_func' => 'listcron'])['cpanelresult']['data'] ?? [], 'command');
        $subs = $cp('/execute/DomainInfo/list_domains')['data']['sub_domains'] ?? [];
        $dbs = array_column($cp('/execute/Mysql/list_databases')['data'] ?? [], 'database');
        $users = array_column($cp('/execute/Mysql/list_users')['data'] ?? [], 'user');
        return ['cron' => array_values(array_filter($cron)), 'subdomains' => array_flip($subs), 'databases' => $dbs, 'users' => $users];
    };
}

/** Fill the wizard's form the way a person would, from what the page shows. */
function wizard(string $page, array $choices): array
{
    $form = http('GET', $page);
    $nonce = between($form['body'], 'name="_wpnonce" value="', '"');
    return http('POST', preg_replace('#admin\.php\?page=stationwagon$#', 'admin-post.php', $page), $choices + [
        'action' => 'stationwagon_install', '_wpnonce' => $nonce, '_wp_http_referer' => '/wp-admin/admin.php?page=stationwagon',
    ]);
}
function stationOption(): array
{
    $j = wp('option get stationwagon_station --format=json', true);
    return json_decode($j, true) ?: [];
}
/** Put the plugin's option back (through PHP, so no JSON goes through the shell's quoting). */
function setOption(array $v): void
{
    wp('eval ' . escapeshellarg("update_option('stationwagon_station', json_decode(base64_decode('" . base64_encode(json_encode($v)) . "'), true), false);"));
}
function signInStation(string $url, array $admin): array
{
    $form = http('GET', $url . 'signin');
    $csrf = between($form['body'], 'name="csrf" value="', '"');
    return http('POST', $url . 'signin', ['csrf' => $csrf, 'email' => $admin['email'], 'password' => $admin['pass']]);
}

try {
    echo "The plugin\n";
    wp('option delete stationwagon_station', true);
    $out = wp('plugin install ' . escapeshellarg($zip) . ' --force --activate');
    check('installs from its zip and activates', str_contains($out, 'activated') || str_contains($out, 'Success'), $out);
    $login = http('POST', "$site/wp-login.php", ['log' => $wpUser, 'pwd' => $wpPass, 'wp-submit' => 'Log In', 'testcookie' => '1'], [CURLOPT_COOKIE => 'wordpress_test_cookie=WP%20Cookie%20check']);
    check('signed in to wp-admin', str_contains($login['url'], '/wp-admin'), $login['url']);
    // WordPress is in maintenance mode for a moment while a plugin is replaced
    for ($i = 0; $i < 30 && str_contains(($wiz = http('GET', $page))['body'], '<title>Maintenance'); $i++) {
        sleep(1);
    }
    check('the Stationwagon page shows the wizard, ready to go', str_contains($wiz['body'], 'Set up my station</button>') && !str_contains($wiz['body'], 'button-hero" disabled') && !str_contains($wiz['body'], 'sw-bad'), text(between($wiz['body'], 'sw-lead', '</form>')));
    check('sensible answers already picked', (bool) preg_match('/name="address" value="folder" checked/', $wiz['body'])
        && (bool) preg_match('/name="database" value="wordpress" checked/', $wiz['body']) && (bool) preg_match('/name="jobs" value="visits" checked/', $wiz['body']));
    check('station name and email from the site', str_contains($wiz['body'], 'value="' . htmlspecialchars(wp('option get blogname')) . '"')
        && str_contains($wiz['body'], 'value="' . wp('user get ' . escapeshellarg($wpUser) . ' --field=user_email') . '"'));
    check('the timezone from the site', (bool) preg_match('/<option selected=.selected.>' . preg_quote(wp('option get timezone_string') ?: 'UTC', '/') . '</', $wiz['body']));
    $zipUrl = http('GET', "$site/wp-content/plugins/stationwagon/app.zip");
    check('the station files can\'t be downloaded from the plugin folder', $zipUrl['code'] === 403, (string) $zipUrl['code']);
    $before = ['dir_exists' => is_dir("$abspath/$run-a")];

    echo "Mistakes in the form\n";
    $bad = wizard($page, ['address' => 'folder', 'folder' => 'wp-admin', 'database' => 'wordpress', 'jobs' => 'visits', 'station_name' => "Radio $run",
        'timezone' => 'Africa/Johannesburg', 'admin_name' => $admin['name'], 'admin_email' => 'nope', 'admin_password' => 'short']);
    $t = text($bad['body']);
    check('caught and shown by each field', str_contains($t, 'Use lowercase letters, digits and dashes') && str_contains($t, 'doesn’t look right') && str_contains($t, 'at least 10 characters'), $t);
    check('what was typed is kept, passwords aren\'t', str_contains($bad['body'], "value=\"Radio $run\"") && !str_contains($bad['body'], 'value="short"'));
    $bad = wizard($page, ['address' => 'folder', 'folder' => "$run-a", 'database' => 'new', 'jobs' => 'visits', 'station_name' => "Radio $run",
        'timezone' => 'Africa/Johannesburg', 'admin_name' => $admin['name'], 'admin_email' => $admin['email'], 'admin_password' => $admin['pass']]);
    check('a cPanel choice without the login is caught', str_contains(text($bad['body']), 'need your cPanel username and password'));
    $bad = wizard($page, ['address' => 'folder', 'folder' => "$run-a", 'database' => 'new', 'jobs' => 'visits', 'cpanel_user' => $cpUser, 'cpanel_pass' => 'wrong-password',
        'station_name' => "Radio $run", 'timezone' => 'Africa/Johannesburg', 'admin_name' => $admin['name'], 'admin_email' => $admin['email'], 'admin_password' => $admin['pass']]);
    check('a wrong cPanel password stops setup before anything is made', str_contains(text($bad['body']), 'cPanel refused that username and password') && !is_dir("$abspath/$run-a"), text(between($bad['body'], 'notice-error', '</div>')));
    $bad = wizard($page, ['address' => 'folder', 'folder' => "$run-a", 'database' => 'manual', 'db_host' => $manual['host'], 'db_name' => $manual['name'],
        'db_user' => $manual['user'], 'db_pass' => 'not-the-password', 'jobs' => 'visits', 'station_name' => "Radio $run",
        'timezone' => 'Africa/Johannesburg', 'admin_name' => $admin['name'], 'admin_email' => $admin['email'], 'admin_password' => $admin['pass']]);
    $t = text($bad['body']);
    check('a wrong database password stops setup and takes its files away again', str_contains($t, 'The database user name or password is wrong')
        && str_contains($t, 'Removed the station files again') && !is_dir("$abspath/$run-a"), text(between($bad['body'], 'notice-error', '</div>')));
    check('nothing installed yet', stationOption() === [] && !$before['dir_exists']);

    // ---- A ----------------------------------------------------------------------------------------
    echo "A: a folder, this site's database, jobs during visits\n";
    $t0 = microtime(true);
    $res = wizard($page, ['address' => 'folder', 'folder' => "$run-a", 'database' => 'wordpress', 'jobs' => 'visits', 'station_name' => "Radio $run",
        'timezone' => 'Africa/Johannesburg', 'admin_name' => $admin['name'], 'admin_email' => $admin['email'], 'admin_password' => $admin['pass']]);
    $s = stationOption();
    $made['dirs'][] = "$abspath/$run-a";
    $t = text($res['body']);
    check('set up', str_contains($t, 'Your station is set up') && ($s['dir'] ?? '') !== '', text(between($res['body'], 'notice', '</div>')));
    printf("    (took %.1f s)\n", microtime(true) - $t0);
    check('the steps are listed', str_contains($t, 'Put the station files in place') && str_contains($t, 'Uses this site’s database') && str_contains($t, 'Background jobs run during visits') && str_contains($t, 'The station answers at'), $t);
    $url = $s['url'] ?? '';
    check('it lives at the site\'s folder', $url === "$site/$run-a/" && is_file("$abspath/$run-a/index.php"), $url);
    check('the status page leads to the manager and studio', str_contains($res['body'], $url . 'manage') && str_contains($res['body'], $url . 'studio') && !str_contains($res['body'], 'Set up my station'));
    $cfg = (string) @file_get_contents("$abspath/$run-a/config.local.php");
    check('settings written: this site\'s database, sw_ tables, made by WordPress, no cron', str_contains($cfg, "'name' => '{$wpDb['name']}'") && str_contains($cfg, "'prefix' => 'sw_'")
        && str_contains($cfg, "'managed_by' => 'wordpress'") && !str_contains($cfg, "'cron'"));
    check('the tests and settings of the source aren\'t copied', !is_dir("$abspath/$run-a/tests") && !is_file("$abspath/$run-a/install.key"));
    $tables = pdo($wpDb)->query("SHOW TABLES LIKE 'sw\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    $made['tables'][] = [$wpDb, 'sw_'];
    check('the station\'s tables sit next to WordPress\'s', count($tables) >= 10 && in_array('sw_stations', $tables, true), count($tables) . ' tables');
    $home = http('GET', $url);
    check('the station page answers', $home['code'] === 200 && str_contains($home['body'], "Radio $run"), (string) $home['code']);
    $in = signInStation($url, $admin);
    check('the station sign-in from the wizard works', str_contains($in['url'], '/manage') || str_contains($in['url'], '/studio'), $in['url']);
    $ov = http('GET', $url . 'manage');
    check('the Overview shows the jobs run during visits', str_contains(text($ov['body']), 'Background jobs') && str_contains(text($ov['body']), 'During visits'));

    echo "A: People in WordPress\n";
    $peoplePage = "$site/wp-admin/admin.php?page=stationwagon-people";
    $pp = http('GET', $peoplePage);
    check('Stationwagon → People lists the station’s own accounts', $pp['code'] === 200 && str_contains($pp['body'], 'admin.php?page=stationwagon-people') && str_contains($pp['body'], $admin['email'])
        && str_contains($pp['body'], 'not WordPress users') && str_contains($pp['body'], 'id="roles"') && substr_count($pp['body'], 'name="perm[]"') === 9, (string) $pp['code']);
    $people = function (string $do, array $fields) use ($peoplePage): array {
        $form = http('GET', $peoplePage . (isset($fields['id']) ? '&person=' . $fields['id'] : ''));
        $nonce = between($form['body'], 'name="_wpnonce" value="', '"');
        return http('POST', preg_replace('#admin\.php\?page=.*$#', 'admin-post.php', $peoplePage), $fields + ['action' => 'stationwagon_people', 'sw_action' => $do, '_wpnonce' => $nonce]);
    };
    $sw = pdo($wpDb);
    $r = $people('role_add', ['name' => 'WP Scheduler', 'perm' => ['schedule']]);
    check('an administrator adds a role with a tick', str_contains($r['body'], 'Role added.') && str_contains($r['body'], 'WP Scheduler')
        && $sw->query("SELECT permissions FROM sw_roles WHERE `key` = 'wp-scheduler'")->fetchColumn() === '["schedule"]', text(between($r['body'], 'notice', '</div>')));
    $djMail = "$run-dj@example.test";
    $r = $people('add', ['name' => 'WP Added DJ', 'email' => $djMail, 'role' => 'wp-scheduler', 'password' => 'wp dj pass 123']);
    $djRow = $sw->query('SELECT id, role, blocked_at FROM sw_users WHERE email = ' . $sw->quote($djMail))->fetch(PDO::FETCH_ASSOC);
    check('  and someone with that role', str_contains($r['body'], 'Added.') && str_contains($r['body'], 'WP Added DJ') && ($djRow['role'] ?? '') === 'wp-scheduler', text(between($r['body'], 'notice', '</div>')));
    $djId = (int) ($djRow['id'] ?? 0);
    $djJar = tempnam(sys_get_temp_dir(), 'swdj');
    $asDj = fn(string $m, string $u, array $p = []) => http($m, $u, $p, [CURLOPT_COOKIEJAR => $djJar, CURLOPT_COOKIEFILE => $djJar]);
    $f = $asDj('GET', $url . 'signin');
    $in = $asDj('POST', $url . 'signin', ['csrf' => between($f['body'], 'name="csrf" value="', '"'), 'email' => $djMail, 'password' => 'wp dj pass 123', 'remember' => '1']);
    check('  who signs in to the station and gets the Schedule', str_contains($in['url'], '/manage') && str_contains($in['body'], '>Schedule</a>') && !str_contains($in['body'], '>People</a>'), $in['url']);
    $r = $people('role_save', ['key' => 'wp-scheduler', 'name' => 'WP Scheduler', 'perm' => ['library', 'podcast']]);
    $in = $asDj('GET', $url . 'manage');
    check('the role’s ticks are changed, for its people at once', str_contains($r['body'], 'Saved. Everyone with this role') && str_contains($in['body'], '>Music library</a>') && !str_contains($in['body'], '>Schedule</a>'));
    $r = $people('details', ['id' => $djId, 'name' => 'WP Renamed DJ', 'email' => $djMail]);
    check('the name is changed', str_contains($r['body'], 'Name and email saved.') && str_contains($r['body'], 'WP Renamed DJ'));
    $r = $people('role', ['id' => $djId, 'role' => 'manager']);
    check('the person’s role is changed', str_contains($r['body'], 'Role changed.') && $sw->query("SELECT role FROM sw_users WHERE id = $djId")->fetchColumn() === 'manager');
    $r = $people('role_delete', ['key' => 'manager']);
    check('a built-in role can’t be deleted', str_contains($r['body'], 'can’t be deleted'));
    $r = $people('password', ['id' => $djId, 'password' => 'short']);
    check('a short password is refused', str_contains($r['body'], 'at least 10 characters'));
    $r = $people('block', ['id' => $djId]);
    check('blocking: the list says so', str_contains($r['body'], 'Blocked.') && str_contains($r['body'], 'sw-tag-bad">Blocked') && $sw->query("SELECT blocked_at IS NOT NULL FROM sw_users WHERE id = $djId")->fetchColumn() == 1);
    check('  and the person is signed out of the station at once', str_contains($asDj('GET', $url . 'manage')['url'], '/signin'));
    check('  their Keep me signed in is gone', (int) $sw->query("SELECT COUNT(*) FROM sw_remember_tokens WHERE user_id = $djId")->fetchColumn() === 0);
    $f = $asDj('GET', $url . 'signin');
    $in = $asDj('POST', $url . 'signin', ['csrf' => between($f['body'], 'name="csrf" value="', '"'), 'email' => $djMail, 'password' => 'wp dj pass 123']);
    check('  and can’t sign in', $in['code'] === 401 && str_contains($in['body'], 'This account is blocked'), (string) $in['code']);
    $r = $people('unblock', ['id' => $djId]);
    $r2 = $people('password', ['id' => $djId, 'password' => 'wp dj new pass 456']);
    $f = $asDj('GET', $url . 'signin');
    $in = $asDj('POST', $url . 'signin', ['csrf' => between($f['body'], 'name="csrf" value="', '"'), 'email' => $djMail, 'password' => 'wp dj new pass 456']);
    check('unblocked, with a new password set here, they sign in again', str_contains($r['body'], 'Unblocked.') && str_contains($r2['body'], 'New password set.') && str_contains($in['url'], '/manage'), $in['url']);
    $adminId = (int) $sw->query('SELECT id FROM sw_users WHERE email = ' . $sw->quote($admin['email']))->fetchColumn();
    $r = $people('block', ['id' => $adminId]);
    check('the station’s only admin can’t be blocked', str_contains($r['body'], 'at least one admin') && $sw->query("SELECT blocked_at IS NULL FROM sw_users WHERE id = $adminId")->fetchColumn() == 1);
    $r = $people('remove', ['id' => $djId]);
    check('a person is removed', str_contains($r['body'], 'Removed.') && !str_contains($r['body'], 'WP Renamed DJ'));
    $r = $people('role_delete', ['key' => 'wp-scheduler']);
    check('a role nobody has is deleted', str_contains($r['body'], 'Role deleted.') && !$sw->query("SELECT COUNT(*) FROM sw_roles WHERE `key` = 'wp-scheduler'")->fetchColumn());
    $forged = http('POST', "$site/wp-admin/admin-post.php", ['action' => 'stationwagon_people', 'sw_action' => 'remove', 'id' => $adminId, '_wpnonce' => 'bad']);
    check('a forged form is refused', $forged['code'] >= 400 && $sw->query("SELECT COUNT(*) FROM sw_users WHERE id = $adminId")->fetchColumn() == 1, (string) $forged['code']);
    @unlink($djJar);
    http('GET', $url . 'api/stream.php');
    usleep(800000);
    $jobs = json_decode((string) @file_get_contents("$abspath/$run-a/storage/cache/jobs.json"), true) ?: [];
    check('a visit ran the background jobs', ($jobs['last_by'] ?? '') === 'visit', json_encode($jobs));

    echo "A: the player on a page\n";
    $post = wp('post create --post_type=page --post_status=publish --post_title=' . escapeshellarg("Listen $run") . ' --post_content=' . escapeshellarg('[stationwagon_player look="light"]') . ' --porcelain');
    $made['posts'][] = $post;
    $p = http('GET', wp("post url $post"));
    check('the shortcode shows the station\'s player', str_contains($p['body'], 'src="' . $url . 'embed?look=light"'), $p['code'] . ' ' . $p['url'] . ' ' . (between($p['body'], '<iframe', '>') ?: substr(text($p['body']), 0, 200)));
    $embed = http('GET', $url . 'embed?look=light');
    check('and the player answers', $embed['code'] === 200);

    echo "A: once only\n";
    $again = wizard($page, ['address' => 'folder', 'folder' => "$run-x", 'database' => 'wordpress', 'jobs' => 'visits', 'station_name' => 'Again',
        'timezone' => 'UTC', 'admin_name' => 'X', 'admin_email' => 'x@example.test', 'admin_password' => 'xxxxxxxxxxxx']);
    check('the wizard is gone, and a forged second setup is refused', !str_contains(http('GET', $page)['body'], 'stationwagon_install') && $again['code'] >= 400 && !is_dir("$abspath/$run-x"), (string) $again['code']);
    $guard = wp('eval ' . escapeshellarg('try { Stationwagon_Setup::install(Stationwagon_Setup::defaults()); echo "installed"; } catch (Throwable $e) { echo $e->getMessage(); }'));
    check('and setup itself refuses a second station', str_contains($guard, 'already set up'), $guard);
    $opt = stationOption();
    wp('option delete stationwagon_station', true);
    $taken = wizard($page, ['address' => 'folder', 'folder' => "$run-a", 'database' => 'wordpress', 'jobs' => 'visits', 'station_name' => 'Again',
        'timezone' => 'UTC', 'admin_name' => 'X', 'admin_email' => 'x@example.test', 'admin_password' => 'xxxxxxxxxxxx']);
    check('a folder with a working station in it is never reused', str_contains(text($taken['body']), 'already has other files'), text(between($taken['body'], 'notice-error', '</div>')));
    setOption($opt);

    echo "A: updating the plugin updates the station\n";
    $pluginDir = wp('plugin path stationwagon --dir');
    $versionFile = "$pluginDir/app.version";
    $real = trim((string) file_get_contents($versionFile));
    file_put_contents($versionFile, "$real-next\n");
    file_put_contents("$abspath/$run-a/old-file.php", '<?php // from an older version');
    file_put_contents("$abspath/$run-a/storage/media/keep.txt", 'a song');
    $cfgBefore = md5_file("$abspath/$run-a/config.local.php");
    $after = http('GET', $page);
    check('the next admin page says the station was updated', str_contains(text($after['body']), "updated to version $real-next"), text(between($after['body'], 'notice', '</div>')));
    check('old code removed, songs and settings kept', !is_file("$abspath/$run-a/old-file.php") && is_file("$abspath/$run-a/storage/media/keep.txt")
        && md5_file("$abspath/$run-a/config.local.php") === $cfgBefore && is_file("$abspath/$run-a/src/Jobs.php"));
    check('and it still plays', http('GET', $url)['code'] === 200 && (stationOption()['version'] ?? '') === "$real-next");
    file_put_contents($versionFile, "$real\n");
    http('GET', $page);

    echo "A: removing it\n";
    $dir = $s['dir'] ?? null;
    rmTree($dir);
    $conn = pdo($wpDb);
    $conn->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $tb) { $conn->exec("DROP TABLE `$tb`"); }
    wp('option delete stationwagon_station', true);
    check('cleaned up', !is_dir($dir) && stationOption() === []);

    // ---- B ----------------------------------------------------------------------------------------
    if ($mode === 'none') {
        echo "B: left to the cPanel stand-in (no cPanel login here)\n";
    } else {
    echo "B: a folder, a new database and a cron job, both made in cPanel\n";
    $res = wizard($page, ['address' => 'folder', 'folder' => "$run-b", 'database' => 'new', 'jobs' => 'cpanel', 'cpanel_user' => $cpUser, 'cpanel_pass' => $cpSecret,
        'station_name' => "Radio $run B", 'timezone' => 'Africa/Johannesburg', 'admin_name' => $admin['name'], 'admin_email' => $admin['email'], 'admin_password' => $admin['pass']]);
    $s = stationOption();
    $made['dirs'][] = "$abspath/$run-b";
    $t = text($res['body']);
    check('set up', str_contains($t, 'Your station is set up'), text(between($res['body'], 'notice', '</div>')));
    check('the steps say cPanel made the database and the cron job', str_contains($t, 'Signed in to cPanel') && str_contains($t, 'Made the database') && str_contains($t, 'Added a cron job every 5 minutes'), $t);
    $cfg = (string) @file_get_contents("$abspath/$run-b/config.local.php");
    $cfgArr = $cfg !== '' ? (array) (function ($f) { return require $f; })("$abspath/$run-b/config.local.php") : [];
    $db = (array) ($cfgArr['db'] ?? []);
    $state = $cpState();
    $made['dbs'][] = [$db['name'] ?? '', $db['user'] ?? ''];
    check('a database and user of its own, not WordPress\'s', ($db['name'] ?? '') !== $wpDb['name'] && in_array($db['name'] ?? '', $state['databases'], true) && in_array($db['user'] ?? '', $state['users'], true), ($db['name'] ?? '?') . ' / ' . ($db['user'] ?? '?'));
    check('its password is random and strong', strlen((string) ($db['pass'] ?? '')) >= 20);
    if ($mode === 'stub') {
        check('signed in like cPanel\'s login page (HTTP Basic sign-in refused, as on the test server)', in_array('/login/ ok', $state['calls'] ?? [], true));
    }
    check('the settings promise a cron job', !empty($cfgArr['cron']));
    $line = array_values(array_filter($state['cron'], fn($c) => str_contains($c, "$run-b/cli/plan.php")));
    $made['cron'][] = $line[0] ?? '';
    check('the cron line runs plan.php with the version-free PHP', count($line) === 1 && (str_starts_with($line[0], '/usr/local/bin/php ') || $mode === 'stub'), $line[0] ?? 'none');
    $url = $s['url'];
    check('the station answers', http('GET', $url)['code'] === 200);
    signInStation($url, $admin);
    $ov = text(http('GET', $url . 'manage')['body']);
    check('the Overview warns until the cron job has run', str_contains($ov, "The cron job hasn't run yet"), between($ov, 'Background jobs', 'ago') ?: $ov);
    exec(escapeshellarg($mode === 'stub' ? PHP_BINARY : '/usr/local/bin/php') . ' ' . escapeshellarg("$abspath/$run-b/cli/plan.php"), $o, $code);
    $ov = text(http('GET', $url . 'manage')['body']);
    check('after it runs: by a cron job', $code === 0 && str_contains($ov, 'By a cron job every 5 minutes'), implode(' ', $o));
    check('a cron line isn\'t added twice', (function () use ($mode, $cpUser, $cpSecret, $run, $cpState, $abspath): bool {
        return count(array_filter($cpState()['cron'], fn($c) => str_contains($c, "$run-b/cli/plan.php"))) === 1;
    })());

    echo "B: removing it\n";
    rmTree($s['dir']);
    wp('option delete stationwagon_station', true);
    }

    // ---- C ----------------------------------------------------------------------------------------
    $sub = $mode !== 'none';
    echo $sub ? "C: a subdomain made in cPanel, a database typed in, the cron line shown\n" : "C: a folder, a database typed in, the cron line shown\n";
    $siteHost = preg_replace('/^www\./', '', (string) parse_url($site, PHP_URL_HOST));
    $host = "$run-c.$siteHost";
    if ($sub) {
        $first = wizard($page, ['address' => 'subdomain', 'subdomain' => "$run-c", 'database' => 'manual', 'db_host' => $manual['host'], 'db_name' => $manual['name'],
            'db_user' => $manual['user'], 'db_pass' => 'not-the-password', 'jobs' => 'manual', 'cpanel_user' => $cpUser, 'cpanel_pass' => $cpSecret,
            'station_name' => "Radio $run C", 'timezone' => 'Africa/Johannesburg', 'admin_name' => $admin['name'], 'admin_email' => $admin['email'], 'admin_password' => $admin['pass']]);
        $made['subdomains'][] = $host;
        $t = text($first['body']);
        check('a first try that stops keeps the subdomain with an empty folder', str_contains($t, 'The database user name or password is wrong') && str_contains($t, "Made the subdomain $host")
            && isset($cpState()['subdomains'][$host]), text(between($first['body'], 'notice-error', '</div>')));
    }
    $res = wizard($page, ['address' => $sub ? 'subdomain' : 'folder', 'subdomain' => "$run-c", 'folder' => "$run-c", 'database' => 'manual', 'db_host' => $manual['host'], 'db_name' => $manual['name'],
        'db_user' => $manual['user'], 'db_pass' => $manual['pass'], 'jobs' => 'manual', 'cpanel_user' => $cpUser, 'cpanel_pass' => $cpSecret,
        'station_name' => "Radio $run C", 'timezone' => 'Africa/Johannesburg', 'admin_name' => $admin['name'], 'admin_email' => $admin['email'], 'admin_password' => $admin['pass']]);
    $s = stationOption();
    $made['subdomains'][] = $host;
    $made['dirs'][] = $s['dir'] ?? '';
    $made['tables'][] = [$manual, 'sw_'];
    $t = text($res['body']);
    check('set up', str_contains($t, 'Your station is set up'), text(between($res['body'], 'notice', '</div>')));
    if ($sub) {
        check("the retry uses the subdomain $host made the first time", isset($cpState()['subdomains'][$host]) && str_contains($t, "Uses the subdomain $host made earlier"), $t);
        check('its folder is the subdomain\'s', str_ends_with($s['dir'] ?? '', "/$host") && is_file(($s['dir'] ?? '') . '/index.php'), $s['dir'] ?? '');
    } else {
        check('no cPanel login asked for or used', !str_contains($t, 'Signed in to cPanel') && is_file("$abspath/$run-c/index.php"));
    }
    $cfg = (string) @file_get_contents(($s['dir'] ?? '') . '/config.local.php');
    check('the database typed in is used, and a cron job is promised', str_contains($cfg, "'name' => '{$manual['name']}'") && str_contains($cfg, "'cron' => true"));
    if (empty($s['reachable'])) {
        check('until the DNS points here: the one A record to add', str_contains($t, 'One step left') && str_contains($res['body'], "<code>$host</code>")
            && (bool) preg_match('/<td>A<\/td><td><code>[^<]+<\/code><\/td><td><code>\d+\.\d+\.\d+\.\d+<\/code>/', $res['body']), between($res['body'], 'sw-dns', '</table>'));
        $form = http('GET', $page);
        $nonce = between(between($form['body'], 'stationwagon_check', '</form>'), 'name="_wpnonce" value="', '"');
        $chk = http('POST', "$site/wp-admin/admin-post.php", ['action' => 'stationwagon_check', '_wpnonce' => $nonce]);
        check('Check again says not yet, kindly', str_contains(text($chk['body']), 'Not yet:') && str_contains(text($chk['body']), 'One step left'));
        check('  no Cloudflare note for a subdomain one level down', !str_contains(text($chk['body']), 'DNS at Cloudflare?'));
        $opt = stationOption();
        setOption(['deep' => true] + $opt);
        $deepPage = text(http('GET', $page)['body']);
        setOption($opt);
        check('  a subdomain of a subdomain gets the Cloudflare note (DNS only)', str_contains($deepPage, 'DNS at Cloudflare?') && str_contains($deepPage, 'DNS only') && str_contains($deepPage, "$host is two"));
    } else {
        check('the subdomain already answers (DNS on this hosting)', true);
    }
    check('the cron line to copy is shown', str_contains($t, 'add this cron job in cPanel') && str_contains($res['body'], ($s['dir'] ?? '?') . '/cli/plan.php'));
    // the station itself, reached the way it will be once the DNS record is there
    if (!$sub) {
        $page2 = http('GET', $s['url'] ?? '');
    } elseif ($mode === 'stub') {
        $direct = 'http://' . parse_url($site, PHP_URL_HOST) . ':' . (parse_url($site, PHP_URL_PORT) ?: 80) . '/sw-subtest/' . $host . '/';
        $page2 = http('GET', $direct);
    } else {
        $resolve = need('SW_SUB_RESOLVE');
        $page2 = ['code' => 0, 'body' => ''];
        for ($i = 0; $i < 24 && $page2['code'] !== 200; $i++) {
            $page2 = http('GET', "https://$host/", [], [CURLOPT_RESOLVE => ["$host:443:$resolve"]]);
            if ($page2['code'] !== 200) { sleep(5); }
        }
    }
    check('the station answers at the subdomain\'s folder', $page2['code'] === 200 && str_contains($page2['body'], "Radio $run C"), $page2['code'] . ' ' . ($page2['error'] ?? ''));
} finally {
    echo "Cleaning up\n";
    foreach ($made['posts'] as $p) { wp("post delete $p --force", true); }
    foreach (array_filter($made['dirs']) as $d) { rmTree($d); }
    foreach ($made['tables'] as [$c, $prefix]) {
        try {
            $pdo = pdo($c);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($pdo->query("SHOW TABLES LIKE '" . str_replace('_', '\\_', $prefix) . "%'")->fetchAll(PDO::FETCH_COLUMN) as $tb) { $pdo->exec("DROP TABLE `$tb`"); }
        } catch (Throwable $ex) { echo "  (tables: {$ex->getMessage()})\n"; }
    }
    wp('option delete stationwagon_station', true);
    if ($mode === 'stub') {
        $st = $cpState();
        $root = new PDO('mysql:host=' . (getenv('SW_TEST_DB_HOST') ?: '127.0.0.1'), 'root', '');
        foreach ($st['databases'] ?? [] as $d) { $root->exec("DROP DATABASE IF EXISTS `$d`"); }
        foreach ($st['users'] ?? [] as $u) { $root->exec("DROP USER IF EXISTS '$u'@'%'"); }
        wp('config delete STATIONWAGON_CPANEL_URL --type=constant', true);
        if ($stub) { proc_terminate($stub); }
        @rmdir(dirname($abspath) . '/sw-subtest');
    } elseif ($mode !== 'none') {
        foreach ($made['dbs'] as [$d, $u]) {
            if ($u) { $cp('/execute/Mysql/delete_user', ['name' => $u]); }
            if ($d) { $cp('/execute/Mysql/delete_database', ['name' => $d]); }
        }
        $lines = $cp('/json-api/cpanel', ['cpanel_jsonapi_apiversion' => 2, 'cpanel_jsonapi_module' => 'Cron', 'cpanel_jsonapi_func' => 'listcron'])['cpanelresult']['data'] ?? [];
        foreach ($lines as $l) {
            if (str_contains((string) ($l['command'] ?? ''), "/$run-")) {
                $cp('/json-api/cpanel', ['cpanel_jsonapi_apiversion' => 2, 'cpanel_jsonapi_module' => 'Cron', 'cpanel_jsonapi_func' => 'remove_line', 'linekey' => $l['linekey']]);
            }
        }
        foreach ($made['subdomains'] as $h) {
            $cp('/json-api/cpanel', ['cpanel_jsonapi_apiversion' => 2, 'cpanel_jsonapi_module' => 'SubDomain', 'cpanel_jsonapi_func' => 'delsubdomain', 'domain' => $h]);
        }
        $st = $cpState();
        check('cPanel left as it was: no test database, user, cron line or subdomain', !array_filter($st['databases'], fn($d) => in_array($d, array_column($made['dbs'], 0), true))
            && !array_filter($st['cron'], fn($c) => str_contains($c, "/$run-")) && !array_intersect(array_keys($st['subdomains']), $made['subdomains']));
    }
    check('nothing left on disk', !array_filter($made['dirs'], 'is_dir'));
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
