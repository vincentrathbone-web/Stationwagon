<?php
/**
 * Installs and updates the station for the setup wizard.
 *
 * The station is the standalone Stationwagon app, unpacked from app.zip (inside this plugin) into
 * its own folder: a folder of this site (example.com/radio) or a subdomain's folder. It runs on its
 * own there, so listeners' requests never start WordPress. The app is carried zipped so its files
 * can't be opened from inside wp-content/plugins, where its own installer would otherwise answer.
 *
 * Setup asks three things besides the station's name and sign-in: where it lives, which database it
 * uses (this site's own, a new one made through cPanel, or one the person made), and how its
 * background jobs run (during visits, or also a cron job, added through cPanel or by hand).
 */

defined('ABSPATH') || exit;

final class Stationwagon_Setup
{
    const OPTION = 'stationwagon_station';
    const RESERVED = ['wp-admin', 'wp-content', 'wp-includes', 'cgi-bin', '.well-known'];

    // ---- The bundled app ------------------------------------------------------------------------

    public static function zipPath(): string
    {
        return defined('STATIONWAGON_APP_ZIP') ? STATIONWAGON_APP_ZIP : dirname(__DIR__) . '/app.zip';
    }

    /** The app version this plugin carries (written next to app.zip by the build). */
    public static function bundledVersion(): string
    {
        $file = dirname(self::zipPath()) . '/app.version';
        return is_file($file) ? trim((string) file_get_contents($file)) : '';
    }

    /** The installed station, or null before setup. */
    public static function station(): ?array
    {
        $s = get_option(self::OPTION);
        return is_array($s) && !empty($s['dir']) ? $s : null;
    }

    // ---- Checks --------------------------------------------------------------------------------

    /** @return list<array{label:string,ok:bool,found:string,required:bool,why:string}> */
    public static function requirements(): array
    {
        $mb = function (string $v): float {
            $n = (float) $v;
            switch (strtolower(substr(trim($v), -1))) {
                case 'g': return $n * 1024;
                case 'm': return $n;
                case 'k': return $n / 1024;
                default: return $n / 1048576;
            }
        };
        $upload = min($mb((string) ini_get('post_max_size')), $mb((string) ini_get('upload_max_filesize')));
        $zip = class_exists('ZipArchive') || is_file(ABSPATH . 'wp-admin/includes/class-pclzip.php');
        return [
            ['label' => 'PHP 8.1 or newer', 'ok' => PHP_VERSION_ID >= 80100, 'found' => PHP_VERSION, 'required' => true,
                'why' => 'Choose PHP 8.1 or newer for this site in cPanel → MultiPHP Manager (or Select PHP Version).'],
            ['label' => 'MySQL support (PDO)', 'ok' => extension_loaded('pdo_mysql'), 'found' => extension_loaded('pdo_mysql') ? 'Yes' : 'No', 'required' => true,
                'why' => 'Turn on the pdo_mysql extension in cPanel → Select PHP Version → Extensions.'],
            ['label' => 'The station files', 'ok' => is_file(self::zipPath()) && $zip, 'found' => is_file(self::zipPath()) ? self::bundledVersion() : 'missing', 'required' => true,
                'why' => 'This copy of the plugin is incomplete. Download it again and upload it once more.'],
            ['label' => 'Room to make the station folder', 'ok' => wp_is_writable(ABSPATH), 'found' => wp_is_writable(ABSPATH) ? 'Yes' : 'No', 'required' => false,
                'why' => "This site's folder can't be written to, so only a subdomain can be used."],
            ['label' => 'Upload size', 'ok' => $upload >= 32, 'found' => round($upload) . ' MB', 'required' => false,
                'why' => 'Song uploads need at least 32 MB (64 MB is better): raise post_max_size and upload_max_filesize in cPanel → Select PHP Version → Options.'],
        ];
    }

    public static function requirementsMet(): bool
    {
        foreach (self::requirements() as $r) {
            if ($r['required'] && !$r['ok']) {
                return false;
            }
        }
        return true;
    }

    // ---- The form -------------------------------------------------------------------------------

    public static function siteHost(): string
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        return preg_replace('/^www\./', '', strtolower($host));
    }

    public static function defaults(): array
    {
        $me = wp_get_current_user();
        $tz = (string) get_option('timezone_string');
        return [
            'address' => 'folder', 'folder' => 'radio', 'subdomain' => 'radio',
            'database' => 'wordpress', 'db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
            'jobs' => 'visits', 'cpanel_user' => Stationwagon_Cpanel::guessUser(), 'cpanel_pass' => '',
            'station_name' => (string) get_bloginfo('name'),
            'timezone' => in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC',
            'admin_name' => $me->exists() ? (string) $me->display_name : '',
            'admin_email' => $me->exists() ? (string) $me->user_email : '',
            'admin_password' => '',
        ];
    }

    public static function needsCpanel(array $in): bool
    {
        return $in['address'] === 'subdomain' || $in['database'] === 'new' || $in['jobs'] === 'cpanel';
    }

    /** @return array<string,string> field => problem */
    public static function validate(array $in): array
    {
        $e = [];
        $name = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/';
        if (!in_array($in['address'], ['folder', 'subdomain'], true)) {
            $e['address'] = 'Choose where the station lives.';
        } elseif ($in['address'] === 'folder' && (!preg_match($name, $in['folder']) || in_array($in['folder'], self::RESERVED, true))) {
            $e['folder'] = 'Use lowercase letters, digits and dashes, for example radio.';
        } elseif ($in['address'] === 'subdomain' && !preg_match($name, $in['subdomain'])) {
            $e['subdomain'] = 'Use lowercase letters, digits and dashes, for example radio.';
        }
        if (!in_array($in['database'], ['wordpress', 'new', 'manual'], true)) {
            $e['database'] = 'Choose a database.';
        } elseif ($in['database'] === 'manual') {
            foreach (['db_host' => 'Database server', 'db_name' => 'Database name', 'db_user' => 'Database user'] as $k => $label) {
                if (trim($in[$k]) === '') {
                    $e[$k] = "$label is needed.";
                }
            }
        }
        if (!in_array($in['jobs'], ['visits', 'cpanel', 'manual'], true)) {
            $e['jobs'] = 'Choose how the background jobs run.';
        }
        if (self::needsCpanel($in) && (trim($in['cpanel_user']) === '' || $in['cpanel_pass'] === '')) {
            $e['cpanel_pass'] = 'Your choices above need your cPanel username and password.';
        }
        if (trim($in['station_name']) === '') {
            $e['station_name'] = 'Station name is needed.';
        }
        if (!in_array($in['timezone'], timezone_identifiers_list(), true)) {
            $e['timezone'] = 'Pick a timezone from the list.';
        }
        if (trim($in['admin_name']) === '') {
            $e['admin_name'] = 'Your name is needed.';
        }
        if (!is_email($in['admin_email'])) {
            $e['admin_email'] = 'That email address doesn’t look right.';
        }
        if (strlen($in['admin_password']) < 10) {
            $e['admin_password'] = 'Use at least 10 characters.';
        }
        return $e;
    }

    // ---- Install ---------------------------------------------------------------------------------

    /**
     * Install the station. Returns the steps done; on a problem throws Stationwagon_Setup_Failed,
     * which carries the steps done so far.
     * @return array{steps:list<array{0:string,1:string}>,station:array}
     */
    public static function install(array $in): array
    {
        $steps = [];
        $made = null;
        $done = function (string $what, string $detail = '') use (&$steps): void {
            $steps[] = [$what, $detail];
        };
        try {
            if (self::station()) {
                throw new RuntimeException('Stationwagon is already set up on this site.');
            }
            if (!self::requirementsMet()) {
                throw new RuntimeException('Fix the server checks marked in red first.');
            }
            $cp = null;
            if (self::needsCpanel($in)) {
                $cp = new Stationwagon_Cpanel($in['cpanel_user'], $in['cpanel_pass']);
                if (($problem = $cp->check()) !== null) {
                    throw new RuntimeException($problem);
                }
                $done('Signed in to cPanel');
            }

            // Where it lives
            $scheme = is_ssl() || wp_parse_url(home_url(), PHP_URL_SCHEME) === 'https' ? 'https' : 'http';
            $ip = '';
            if ($in['address'] === 'folder') {
                $dir = untrailingslashit(ABSPATH) . '/' . $in['folder'];
                $url = trailingslashit(site_url('/' . $in['folder']));
                if (get_page_by_path($in['folder'])) {
                    throw new RuntimeException("This site already has a page at /{$in['folder']}. Choose another folder name.");
                }
                self::checkFolder($dir);
                $host = '';
            } else {
                $domains = $cp->domains();
                $root = self::siteHost();
                if (!in_array($root, $domains['all'], true)) {
                    throw new RuntimeException("This site's domain ($root) isn't one of this cPanel account's domains, so a subdomain of it can't be made here.");
                }
                $host = $in['subdomain'] . '.' . $root;
                $home = $domains['home'] !== '' ? $domains['home'] : self::homeDir();
                $rel = (is_dir("$home/public_html") ? 'public_html/' : '') . $host;
                $dir = "$home/$rel";
                $url = "$scheme://$host/";
                $ip = $domains['ip'];
                // a subdomain of a subdomain (radio.site.example.com): Cloudflare's free certificate doesn't cover it
                $deep = (bool) array_filter($domains['all'], fn($d) => str_ends_with($root, ".$d"));
                $exists = in_array($host, $domains['all'], true);
                if ($exists && !is_dir($dir)) {
                    throw new RuntimeException("$host already exists. Choose another name.");
                }
                try {
                    self::checkFolder($dir);
                } catch (RuntimeException $ex) {
                    throw new RuntimeException($exists ? "$host already exists and its folder is in use. Choose another name." : $ex->getMessage());
                }
                if ($exists) {
                    // made by an earlier try that stopped part way: its folder is empty, so use it
                    $done("Uses the subdomain $host made earlier");
                } else {
                    $cp->addSubdomain($in['subdomain'], $root, $rel);
                    $done("Made the subdomain $host");
                }
            }

            $fresh = !file_exists($dir) || !array_diff((array) scandir($dir), ['.', '..']);
            self::unpack($dir, false);
            $made = $fresh ? $dir : null; // removed again if setup stops after this
            $done('Put the station files in place', $dir);

            // The database
            if ($in['database'] === 'wordpress') {
                $db = self::wordpressDb();
                $done('Uses this site’s database', "tables starting with {$db['prefix']}");
            } elseif ($in['database'] === 'new') {
                $made = $cp->createDatabase();
                $db = ['host' => 'localhost', 'port' => 3306, 'name' => $made['name'], 'user' => $made['user'], 'pass' => $made['pass'], 'prefix' => 'sw_'];
                $done("Made the database {$made['name']}", "with its own user {$made['user']}");
            } else {
                [$h, $p] = self::hostPort($in['db_host']);
                $db = ['host' => $h, 'port' => $p, 'name' => trim($in['db_name']), 'user' => trim($in['db_user']), 'pass' => $in['db_pass'], 'prefix' => 'sw_'];
            }

            // The app's own installer: tables, the station, the first admin, config.local.php
            self::loadApp($dir);
            $installer = new \Stationwagon\Installer($dir);
            $result = $installer->install([
                'db_host' => $db['host'], 'db_port' => (string) $db['port'], 'db_name' => $db['name'], 'db_user' => $db['user'],
                'db_pass' => $db['pass'], 'db_prefix' => $db['prefix'], 'station_name' => trim($in['station_name']),
                'timezone' => $in['timezone'], 'admin_name' => trim($in['admin_name']), 'admin_email' => trim($in['admin_email']),
                'admin_password' => $in['admin_password'], 'cron' => $in['jobs'] !== 'visits', 'managed_by' => 'wordpress',
            ]);
            if (!$result['config_written']) {
                throw new RuntimeException("The station's settings file couldn't be saved in $dir.");
            }
            $done('Made the station and your sign-in', trim($in['admin_email']));

            // The background jobs
            $command = self::cronCommand($dir);
            if ($in['jobs'] === 'cpanel') {
                $cp->addCron($command);
                $done('Added a cron job every 5 minutes');
            } else {
                $done($in['jobs'] === 'manual' ? 'Background jobs run during visits until you add the cron job' : 'Background jobs run during visits');
            }

            $station = [
                'dir' => $dir, 'url' => $url, 'host' => $host, 'address' => $in['address'], 'ip' => $ip, 'deep' => $deep ?? false,
                'version' => self::bundledVersion(), 'jobs' => $in['jobs'], 'cron' => $command,
                'database' => $in['database'], 'installed_at' => time(),
            ];
            $station['reachable'] = self::reachable($url) === null;
            update_option(self::OPTION, $station, false);
            $done($station['reachable'] ? 'The station answers at ' . $url : 'The station is set up; its address needs one DNS record (below)');
            return ['steps' => $steps, 'station' => $station];
        } catch (Throwable $ex) {
            if ($made !== null && !is_file("$made/config.local.php")) {
                // the files this setup unpacked, so a retry starts clean; a subdomain keeps its
                // (empty) folder, so a retry can use the subdomain cPanel already made
                if ($in['address'] === 'subdomain') {
                    foreach (array_diff((array) scandir($made), ['.', '..']) as $entry) {
                        self::remove("$made/$entry");
                    }
                } else {
                    self::remove($made);
                }
                $steps[] = ['Removed the station files again', ''];
            }
            throw new Stationwagon_Setup_Failed($ex->getMessage(), $steps);
        }
    }

    /** The folder must not exist yet, be empty, or hold a Stationwagon copy that isn't installed (a retry). */
    private static function checkFolder(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }
        if (!is_dir($dir)) {
            throw new RuntimeException("$dir is a file. Choose another name.");
        }
        $entries = array_diff((array) scandir($dir), ['.', '..']);
        if (!$entries) {
            return;
        }
        if (self::isStationDir($dir) && !is_file("$dir/config.local.php")) {
            return;
        }
        throw new RuntimeException("The folder $dir already has other files in it. Choose another name.");
    }

    public static function isStationDir(string $dir): bool
    {
        return is_file("$dir/VERSION") && is_file("$dir/src/Installer.php") && is_file("$dir/index.php");
    }

    /** The hosting account's home folder (/home/user on cPanel), when cPanel didn't say. */
    private static function homeDir(): string
    {
        return preg_match('#^(/home\d*/[^/]+)/#', ABSPATH, $m) ? $m[1] : dirname(untrailingslashit(ABSPATH));
    }

    /** This WordPress site's database, with a table prefix nothing else uses yet. */
    public static function wordpressDb(): array
    {
        global $wpdb;
        [$host, $port] = self::hostPort(DB_HOST);
        $prefix = 'sw_';
        for ($i = 2; $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%')) !== null; $i++) {
            $prefix = "sw{$i}_";
        }
        return ['host' => $host, 'port' => $port, 'name' => DB_NAME, 'user' => DB_USER, 'pass' => DB_PASSWORD, 'prefix' => $prefix];
    }

    /** "localhost", "localhost:3307" or "localhost:/path/mysql.sock" (then the default socket). */
    public static function hostPort(string $host): array
    {
        $host = trim($host);
        if (preg_match('/^(.+):(\d+)$/', $host, $m)) {
            return [$m[1], (int) $m[2]];
        }
        if (strpos($host, ':') !== false && substr_count($host, ':') === 1) {
            return [strstr($host, ':', true) ?: 'localhost', 3306];
        }
        return [$host, 3306];
    }

    /** The cron line: /usr/local/bin/php follows the account's PHP version on cPanel. */
    public static function cronCommand(string $dir): string
    {
        $php = is_executable('/usr/local/bin/php') ? '/usr/local/bin/php' : 'php';
        return "$php $dir/cli/plan.php > /dev/null 2>&1";
    }

    private static function loadApp(string $dir): void
    {
        if (!class_exists('\Stationwagon\Installer', false)) {
            require_once $dir . '/src/bootstrap.php';
        }
    }

    // ---- Files -----------------------------------------------------------------------------------

    /**
     * Unpack app.zip into $dir. On an update, first remove the old code: everything at the top
     * except storage/ (songs, recordings) and config.local.php (the settings).
     */
    public static function unpack(string $dir, bool $update): void
    {
        if ($update) {
            if (!self::isStationDir($dir)) {
                throw new RuntimeException("$dir doesn't look like a Stationwagon station, so it wasn't touched.");
            }
            foreach (array_diff((array) scandir($dir), ['.', '..', 'storage', 'config.local.php']) as $entry) {
                self::remove("$dir/$entry");
            }
        }
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new RuntimeException("Can't make the folder $dir.");
        }
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open(self::zipPath()) !== true || !$zip->extractTo($dir)) {
                throw new RuntimeException("Can't unpack the station files into $dir.");
            }
            $zip->close();
        } else {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            $zip = new PclZip(self::zipPath());
            if ($zip->extract(PCLZIP_OPT_PATH, $dir) === 0) {
                throw new RuntimeException("Can't unpack the station files into $dir.");
            }
        }
        @chmod("$dir/storage", 0775);
        foreach (['media', 'live', 'cache'] as $sub) {
            if (!is_dir("$dir/storage/$sub")) {
                @mkdir("$dir/storage/$sub", 0775, true);
            }
        }
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
                self::remove("$path/$entry");
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    // ---- After setup -----------------------------------------------------------------------------

    /** Null when the station's page answers, else why not. */
    public static function reachable(string $url): ?string
    {
        $r = wp_remote_get($url, ['timeout' => 15, 'sslverify' => false, 'redirection' => 2]);
        if (is_wp_error($r)) {
            return $r->get_error_message();
        }
        $code = (int) wp_remote_retrieve_response_code($r);
        return $code === 200 ? null : "It answered $code.";
    }

    /**
     * Bring the station's code up to this plugin's version (run on admin pages after the plugin is
     * updated). Settings and storage stay; the station upgrades its database on its next page.
     * Returns the new version, or null when nothing was needed.
     */
    public static function update(): ?string
    {
        $s = self::station();
        $v = self::bundledVersion();
        if (!$s || $v === '' || ($s['version'] ?? '') === $v || !is_file(self::zipPath())) {
            return null;
        }
        self::unpack($s['dir'], true);
        $s['version'] = $v;
        update_option(self::OPTION, $s, false);
        self::reachable($s['url']); // its first page brings the database up to date
        return $v;
    }
}

/** A setup that stopped part way, with the steps it had done. */
final class Stationwagon_Setup_Failed extends RuntimeException
{
    /** @var list<array{0:string,1:string}> */
    public $steps;

    public function __construct(string $message, array $steps)
    {
        parent::__construct($message);
        $this->steps = $steps;
    }
}
