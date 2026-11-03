<?php
/**
 * Stationwagon setup: upload this one file to your web space and open it in a browser.
 *
 * It checks the server, asks where the station should live (a subfolder, this folder, or a new
 * subdomain made through cPanel), downloads the newest Stationwagon from its public releases,
 * unpacks it there, deletes itself, and hands over to the station's own installer (the database,
 * background jobs, the station's name and your sign-in). Modelled on Nextcloud's setup-nextcloud.php.
 *
 * It works for an hour after it was uploaded, so a forgotten copy can't be used by someone else
 * later; the installer it hands over to asks for a one-time setup key, which it passes along.
 * Kept to plain PHP so that an old PHP version still gets a clear message instead of an error.
 */

define('SW_SOURCE', 'https://github.com/vincentrathbone-web/stationwagon-releases/releases/latest/download/stationwagon.zip');
define('SW_MAX_AGE', 3600);

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Stationwagon needs PHP 8.1 or newer, and this site runs PHP ' . PHP_VERSION . ".\n"
        . "In cPanel, open MultiPHP Manager (or Select PHP Version), choose 8.1 or newer for this site, then open this page again.\n";
    exit;
}
@set_time_limit(300);
header('Cache-Control: no-store');

function sw_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function sw_host() {
    $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    return (string) preg_replace('/^www\./', '', $host);
}

function sw_scheme() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    return $https ? 'https' : 'http';
}

/** This folder's address, ending in / */
function sw_here_url() {
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/setup.php'))), '/');
    return sw_scheme() . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

function sw_checks() {
    $mb = function ($v) {
        $n = (float) $v;
        $u = strtolower(substr(trim((string) $v), -1));
        return $u === 'g' ? $n * 1024 : ($u === 'm' ? $n : ($u === 'k' ? $n / 1024 : $n / 1048576));
    };
    $upload = min($mb(ini_get('post_max_size')), $mb(ini_get('upload_max_filesize')));
    $age = time() - (int) @filemtime(__FILE__);
    $download = function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    return [
        ['PHP 8.1 or newer', true, PHP_VERSION, true, ''],
        ['MySQL support (PDO)', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'Yes' : 'No', true, 'Turn on pdo_mysql in cPanel → Select PHP Version → Extensions.'],
        ['Unpacking zip files', class_exists('ZipArchive'), class_exists('ZipArchive') ? 'Yes' : 'No', true, 'Turn on the zip extension in cPanel → Select PHP Version → Extensions.'],
        ['Downloading', $download, $download ? 'Yes' : 'No', true, 'Turn on the curl extension in cPanel → Select PHP Version → Extensions.'],
        ['This folder can be written to', is_writable(__DIR__), is_writable(__DIR__) ? 'Yes' : 'No', true, 'Set this folder to 755 in cPanel → File Manager → Permissions.'],
        ['Uploaded in the last hour', $age < SW_MAX_AGE, $age < 120 ? 'just now' : floor($age / 60) . ' min ago', true, 'For safety this file works for an hour after it is uploaded. Upload setup.php again.'],
        ['Upload size', $upload >= 32, round($upload) . ' MB', false, 'Song uploads need at least 32 MB (64 MB is better): raise post_max_size and upload_max_filesize in cPanel → Select PHP Version → Options.'],
    ];
}

function sw_ready() {
    foreach (sw_checks() as $c) {
        if ($c[3] && !$c[1]) {
            return false;
        }
    }
    return true;
}

/** Download the station to $to. */
function sw_download($to) {
    if (function_exists('curl_init')) {
        $fh = fopen($to, 'wb');
        $c = curl_init(SW_SOURCE);
        curl_setopt_array($c, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 180,
            CURLOPT_USERAGENT => 'Stationwagon setup', CURLOPT_FAILONERROR => true]);
        $ok = curl_exec($c);
        $err = curl_error($c);
        curl_close($c);
        fclose($fh);
        if (!$ok) {
            throw new RuntimeException("Downloading Stationwagon failed ($err).");
        }
        return;
    }
    $data = @file_get_contents(SW_SOURCE, false, stream_context_create(['http' => ['user_agent' => 'Stationwagon setup', 'timeout' => 180]]));
    if ($data === false || @file_put_contents($to, $data) === false) {
        throw new RuntimeException('Downloading Stationwagon failed.');
    }
}

/** Does $url answer with the station's installer (not a default page or a "page not found")? */
function sw_is_installer($url) {
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
        $body = (string) curl_exec($c);
        $ok = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($c);
    } else {
        $body = (string) @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10], 'ssl' => ['verify_peer' => false]]));
        $ok = $body !== '';
    }
    return $ok && strpos($body, 'Install Stationwagon') !== false;
}

function sw_remove($path) {
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff((array) scandir($path), ['.', '..']) as $e) {
            sw_remove("$path/$e");
        }
        @rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        @unlink($path);
    }
}

/** Entries that may already be in a folder the station goes into. */
function sw_harmless($name, $extra = []) {
    return in_array($name, array_merge(['.', '..', 'cgi-bin', '.well-known', 'error_log', '.user.ini', 'php.ini'], $extra), true);
}

/** Install. Returns ['url' => …, 'install' => …, 'host' => …, 'ip' => …, 'reachable' => bool, 'steps' => [...]]. */
function sw_install(array $in) {
    $steps = [];
    $tmp = __DIR__ . '/.sw-setup-' . bin2hex(random_bytes(4));
    $zip = "$tmp.zip";
    try {
        sw_download($zip);
        $z = new ZipArchive();
        if ($z->open($zip) !== true) {
            throw new RuntimeException('The download isn’t a Stationwagon package. Try again in a minute.');
        }
        if ($z->locateName('VERSION') === false || $z->locateName('src/Installer.php') === false || $z->locateName('src/Cpanel.php') === false) {
            $z->close(); // so it can be deleted (Windows keeps an open file)
            throw new RuntimeException('The download isn’t a Stationwagon package. Try again in a minute.');
        }
        $version = trim((string) $z->getFromName('VERSION'));
        if (!$z->extractTo($tmp)) {
            throw new RuntimeException('Unpacking Stationwagon failed. Is there enough disk space?');
        }
        $z->close();
        @unlink($zip);
        $steps[] = "Downloaded Stationwagon $version";

        $ip = '';
        $host = '';
        $deep = false;
        if ($in['address'] === 'here') {
            $dir = __DIR__;
            $url = sw_here_url();
            $others = array_filter((array) scandir($dir), function ($e) use ($tmp) {
                return !sw_harmless($e, [basename(__FILE__), basename($tmp)]);
            });
            if ($others) {
                throw new RuntimeException('This folder already has other files (' . implode(', ', array_slice(array_values($others), 0, 5)) . '). Choose a subfolder instead.');
            }
        } elseif ($in['address'] === 'folder') {
            $dir = __DIR__ . '/' . $in['folder'];
            $url = sw_here_url() . $in['folder'] . '/';
            if (file_exists($dir) && array_filter((array) scandir($dir), function ($e) { return !sw_harmless($e); })) {
                throw new RuntimeException("The folder {$in['folder']} already has files in it. Choose another name.");
            }
        } else {
            require_once "$tmp/src/Cpanel.php";
            $cp = new Stationwagon\Cpanel($in['cpanel_user'], $in['cpanel_pass']);
            if (($problem = $cp->check()) !== null) {
                throw new RuntimeException($problem);
            }
            $steps[] = 'Signed in to cPanel';
            $domains = $cp->domains();
            $root = sw_host();
            if (!in_array($root, $domains['all'], true)) {
                throw new RuntimeException("This site's domain ($root) isn't one of this cPanel account's domains, so a subdomain of it can't be made here.");
            }
            $host = $in['subdomain'] . '.' . $root;
            $home = $domains['home'] !== '' ? $domains['home'] : (preg_match('#^(/home\d*/[^/]+)/#', __DIR__ . '/', $m) ? $m[1] : dirname(__DIR__));
            $rel = (is_dir("$home/public_html") ? 'public_html/' : '') . $host;
            $dir = "$home/$rel";
            $url = sw_scheme() . "://$host/";
            $ip = $domains['ip'];
            // a subdomain of a subdomain (radio.site.example.com): Cloudflare's free certificate doesn't cover it
            $deep = (bool) array_filter($domains['all'], function ($d) use ($root) { return substr($root, -strlen(".$d")) === ".$d"; });
            if (in_array($host, $domains['all'], true)) {
                if (!is_dir($dir) || array_filter((array) scandir($dir), function ($e) { return !sw_harmless($e); })) {
                    throw new RuntimeException("$host already exists. Choose another name.");
                }
                $steps[] = "Uses the subdomain $host made earlier";
            } else {
                if (file_exists($dir) && array_filter((array) scandir($dir), function ($e) { return !sw_harmless($e); })) {
                    throw new RuntimeException("The folder $rel already has files in it. Choose another name.");
                }
                $cp->addSubdomain($in['subdomain'], $root, $rel);
                $steps[] = "Made the subdomain $host";
            }
        }

        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException("Can't make the folder $dir.");
        }
        foreach (array_diff((array) scandir($tmp), ['.', '..']) as $e) {
            if (!@rename("$tmp/$e", "$dir/$e")) {
                throw new RuntimeException("Can't move the station's files into $dir.");
            }
        }
        sw_remove($tmp);
        @chmod("$dir/storage", 0775);
        $key = bin2hex(random_bytes(12));
        file_put_contents("$dir/install.key", $key);
        @chmod("$dir/install.key", 0600);
        $steps[] = "Put the station in $dir";

        // The hand-over goes to index.php/install, a real file: LiteSpeed takes a few seconds to
        // notice the station's new .htaccess in a folder it has already served (its workers
        // answering "not found" meanwhile). A subdomain may still be waiting for its DNS.
        $install = $url . 'index.php/install';
        $reachable = sw_is_installer($install);
        for ($i = 0; $i < 10 && !$reachable && $host === ''; $i++) {
            sleep(1);
            $reachable = sw_is_installer($install);
        }
        return ['url' => $url, 'install' => "$install?key=$key", 'host' => $host, 'ip' => $ip ?: (string) ($_SERVER['SERVER_ADDR'] ?? ''), 'reachable' => $reachable, 'steps' => $steps,
            'deep' => $deep, 'elsewhere' => $host !== '' && !$reachable ? array_values(array_diff((array) @gethostbynamel($host), [$ip])) : []];
    } catch (Throwable $ex) {
        sw_remove($tmp);
        @unlink($zip);
        throw new RuntimeException($ex->getMessage(), 0, $ex);
    }
}

// ---- The page ----------------------------------------------------------------------------------

$in = ['address' => 'folder', 'folder' => 'radio', 'subdomain' => 'radio', 'cpanel_user' => '', 'cpanel_pass' => ''];
if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    $in['cpanel_user'] = (string) (@posix_getpwuid(posix_geteuid())['name'] ?? '');
}
$errors = [];
$problem = null;
$done = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach ($in as $k => $v) {
        if (isset($_POST[$k]) && is_string($_POST[$k])) {
            $in[$k] = $k === 'cpanel_pass' ? $_POST[$k] : strtolower(trim($_POST[$k]));
        }
    }
    $in['cpanel_user'] = trim((string) ($_POST['cpanel_user'] ?? $in['cpanel_user']));
    $name = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/';
    if (!in_array($in['address'], ['folder', 'here', 'subdomain'], true)) {
        $errors['address'] = 'Choose where the station lives.';
    } elseif ($in['address'] === 'folder' && (!preg_match($name, $in['folder']) || in_array($in['folder'], ['cgi-bin', 'wp-admin', 'wp-content', 'wp-includes'], true))) {
        $errors['folder'] = 'Use lowercase letters, digits and dashes, for example radio.';
    } elseif ($in['address'] === 'subdomain' && !preg_match($name, $in['subdomain'])) {
        $errors['subdomain'] = 'Use lowercase letters, digits and dashes, for example radio.';
    } elseif ($in['address'] === 'subdomain' && ($in['cpanel_user'] === '' || $in['cpanel_pass'] === '')) {
        $errors['cpanel_pass'] = 'A subdomain is made in cPanel, so this needs your cPanel username and password.';
    }
    if (!$errors && sw_ready()) {
        try {
            $done = sw_install($in);
            @unlink(__FILE__); // one use only
            if ($done['reachable']) {
                header('Location: ' . $done['install'], true, 303);
                exit;
            }
        } catch (RuntimeException $ex) {
            $problem = $ex->getMessage();
        }
    }
}
$checks = sw_checks();
$ready = sw_ready();
$here = sw_here_url();
$site = sw_host();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Stationwagon setup</title>
<!--SW_ICON-->
<style>
:root { --ground: #f6f8f6; --paper: #fff; --ink: #17201a; --ink-2: #4a564d; --line: #dde4de; --green: #138a43; --green-bg: #e7f4ec;
  --amber: #a15c00; --amber-bg: #fff4e2; --red: #b42318; --red-bg: #fdecea; }
* { box-sizing: border-box; }
body { margin: 0; background: var(--ground); color: var(--ink); font: 16px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif; }
main { max-width: 680px; margin: 0 auto; padding: 32px 16px 64px; }
h1 { font-size: 30px; margin: 0 0 6px; letter-spacing: -.01em; display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
h1 .logo { display: block; height: 46px; width: auto; } h1 .lg-mark { color: var(--green); } h1 .lg-word { color: var(--ink); }
h1 small { font-size: 22px; font-weight: 600; color: var(--ink-2); }
h2 { font-size: 18px; margin: 0 0 10px; }
.lede { color: var(--ink-2); margin: 0 0 22px; }
.card { background: var(--paper); border: 1px solid var(--line); border-radius: 10px; padding: 16px 18px; margin: 0 0 16px; }
ul.checks { list-style: none; margin: 0; padding: 0; }
.checks li { display: flex; gap: 10px; padding: 6px 0; border-top: 1px solid var(--line); } .checks li:first-child { border-top: 0; }
.checks .dot { flex: none; width: 9px; height: 9px; border-radius: 50%; background: var(--green); margin-top: 8px; }
.checks .warn .dot { background: var(--amber); } .checks .bad .dot { background: var(--red); }
.checks .what { flex: 1; } .checks small { display: block; color: var(--ink-2); } .checks .found { color: var(--ink-2); font-size: 14px; white-space: nowrap; }
.choice { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; margin: 0 0 8px; cursor: pointer; }
.choice:has(input:checked) { border-color: var(--green); background: var(--green-bg); }
.choice input { margin-top: 5px; accent-color: var(--green); } .choice small { display: block; color: var(--ink-2); font-size: 14px; }
.tag { font-style: normal; font-size: 11px; border: 1px solid var(--line); border-radius: 999px; padding: 0 7px; margin-left: 6px; color: var(--ink-2); }
.tag.easy { border-color: var(--green); color: var(--green); }
.sub { margin: 0 0 10px 34px; display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
label.f { display: block; margin: 0 0 10px; } label.f span { display: block; font-weight: 600; font-size: 14px; margin-bottom: 3px; }
input[type=text], input[type=password] { font: inherit; padding: 8px 10px; border: 1px solid var(--line); border-radius: 6px; width: 100%; max-width: 320px; }
.sub input { width: 9em; }
code { background: var(--green-bg); border-radius: 4px; padding: 1px 5px; font-size: 14px; overflow-wrap: anywhere; }
.btn { font: 600 16px system-ui, sans-serif; background: var(--green); color: #fff; border: 0; border-radius: 8px; padding: 12px 22px; cursor: pointer; }
.btn[disabled] { opacity: .45; cursor: not-allowed; }
.alert { border-radius: 8px; padding: 10px 14px; margin: 0 0 16px; font-weight: 600; } .alert.bad { background: var(--red-bg); color: var(--red); }
.warnbox { background: var(--amber-bg); border-color: var(--amber); }
.err { color: var(--red); font-weight: 600; font-size: 14px; margin: 0 0 8px; }
table { border-collapse: collapse; width: 100%; } th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--line); }
.wait { color: var(--ink-2); margin-left: 12px; }
@media (max-width: 520px) { .sub { margin-left: 0; } }
</style></head>
<body><main>
<h1><!--SW_LOGO--><small>setup</small></h1>
<?php if ($done): ?>
  <p class="lede">The station's files are in place and this setup file has removed itself.</p>
  <div class="card"><ul class="checks"><?php foreach ($done['steps'] as $s): ?><li><span class="dot"></span><span class="what"><?= sw_h($s) ?></span></li><?php endforeach ?></ul></div>
  <?php if ($done['host'] === ''): ?>
  <div class="card warnbox">
    <h2>One step left: the station's installer</h2>
    <p>The web server is still catching up with the new files. Open the installer (keep this link, it holds your one-time setup key). If it says the page isn't found, wait a minute and open it again.</p>
    <p><a href="<?= sw_h($done['install']) ?>"><code><?= sw_h($done['install']) ?></code></a></p>
  </div>
  <?php else: ?>
  <div class="card warnbox">
    <h2>One step left: point the subdomain at this server</h2>
    <p><?= sw_h($done['host']) ?> doesn't reach this server yet. Where your domain's DNS is kept (for example Cloudflare, or your domain registrar), add this record:</p>
    <table><tr><th>Type</th><th>Name</th><th>Points to</th></tr><tr><td>A</td><td><code><?= sw_h($done['host']) ?></code></td><td><code><?= sw_h($done['ip']) ?></code></td></tr></table>
    <?php if ($done['elsewhere']): ?><p><?= sw_h($done['host']) ?> already leads somewhere else (<?= sw_h(implode(', ', $done['elsewhere'])) ?>), probably through a catch-all (*) record. This record for the name itself takes its place.</p><?php endif ?>
    <?php if ($done['deep']): ?><p><b>DNS at Cloudflare?</b> Cloudflare's free certificate only covers names one level below your domain, and <?= sw_h($done['host']) ?> is two. Set this record's <b>Proxy status</b> to <b>DNS only</b> (the grey cloud); this hosting then makes the certificate itself (cPanel AutoSSL), which can take a few hours.</p><?php endif ?>
    <p>If the DNS is at this hosting, it works by itself within a few minutes. Then open the station's installer (keep this link, it holds your one-time setup key):</p>
    <p><a href="<?= sw_h($done['install']) ?>"><code><?= sw_h($done['install']) ?></code></a></p>
  </div>
  <?php endif ?>
<?php else: ?>
  <p class="lede">This puts a Stationwagon radio station on your web space: it checks the server, downloads the newest Stationwagon, puts it where you choose, and hands over to the station's own installer.</p>
  <div class="card"><h2>Server check</h2><ul class="checks">
    <?php foreach ($checks as $c): ?><li class="<?= $c[1] ? 'ok' : ($c[3] ? 'bad' : 'warn') ?>"><span class="dot"></span><span class="what"><b><?= sw_h($c[0]) ?></b><?php if (!$c[1]): ?><small><?= sw_h($c[4]) ?></small><?php endif ?></span><span class="found"><?= sw_h($c[2]) ?></span></li><?php endforeach ?>
  </ul></div>
  <?php if ($problem): ?><p class="alert bad">Setup stopped: <?= sw_h($problem) ?></p><?php endif ?>
  <form method="post" id="sw">
    <div class="card"><h2>Where should the station live?</h2>
      <label class="choice"><input type="radio" name="address" value="folder"<?= $in['address'] === 'folder' ? ' checked' : '' ?>><span><b>A subfolder</b> <em class="tag easy">Recommended</em><small>Listeners go to <code><?= sw_h($here) ?><b class="nm" data-for="folder"><?= sw_h($in['folder']) ?></b></code>.</small></span></label>
      <p class="sub" data-show="address=folder"><code><?= sw_h($here) ?></code><input type="text" name="folder" id="folder" value="<?= sw_h($in['folder']) ?>" aria-label="Folder name"></p>
      <?php if (isset($errors['folder'])): ?><p class="err"><?= sw_h($errors['folder']) ?></p><?php endif ?>
      <label class="choice"><input type="radio" name="address" value="here"<?= $in['address'] === 'here' ? ' checked' : '' ?>><span><b>This folder</b><small>Listeners go to <code><?= sw_h($here) ?></code>. Only when the folder is empty apart from this file.</small></span></label>
      <label class="choice"><input type="radio" name="address" value="subdomain" data-cpanel="1"<?= $in['address'] === 'subdomain' ? ' checked' : '' ?>><span><b>Its own subdomain</b> <em class="tag">cPanel login</em><small>Listeners go to <code><b class="nm" data-for="subdomain"><?= sw_h($in['subdomain']) ?></b>.<?= sw_h($site) ?></code>. Made for you in cPanel. If your DNS is kept elsewhere (like Cloudflare), you add one record afterwards.</small></span></label>
      <p class="sub" data-show="address=subdomain"><input type="text" name="subdomain" id="subdomain" value="<?= sw_h($in['subdomain']) ?>" aria-label="Subdomain"><code>.<?= sw_h($site) ?></code></p>
      <?php if (isset($errors['subdomain'])): ?><p class="err"><?= sw_h($errors['subdomain']) ?></p><?php endif ?>
    </div>
    <div class="card" id="cp"><h2>Your cPanel login</h2>
      <p>Used once to make the subdomain, never saved. (A cPanel API token works in place of the password.)</p>
      <label class="f"><span>cPanel username</span><input type="text" name="cpanel_user" value="<?= sw_h($in['cpanel_user']) ?>" autocomplete="off"></label>
      <label class="f"><span>cPanel password</span><input type="password" name="cpanel_pass" autocomplete="off"></label>
      <?php if (isset($errors['cpanel_pass'])): ?><p class="err"><?= sw_h($errors['cpanel_pass']) ?></p><?php endif ?>
    </div>
    <p><button class="btn" type="submit"<?= $ready ? '' : ' disabled' ?>>Download and set up</button><span class="wait" hidden>Downloading and unpacking… about a minute.</span></p>
  </form>
  <script>
  (function () {
    var f = document.getElementById('sw'); if (!f) return;
    function update() {
      var addr = (f.querySelector('input[name=address]:checked') || {}).value;
      f.querySelectorAll('[data-show]').forEach(function (el) { el.hidden = el.dataset.show !== 'address=' + addr; });
      document.getElementById('cp').hidden = addr !== 'subdomain';
      f.querySelectorAll('.nm').forEach(function (b) { b.textContent = document.getElementById(b.dataset.for).value.trim() || 'radio'; });
    }
    f.addEventListener('change', update); f.addEventListener('input', update); update();
    f.addEventListener('submit', function () { f.querySelector('.wait').hidden = false; setTimeout(function () { f.querySelector('.btn').disabled = true; }, 0); });
  })();
  </script>
<?php endif ?>
</main></body></html>
