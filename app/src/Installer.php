<?php
declare(strict_types=1);

namespace Stationwagon;

use PDOException;
use RuntimeException;

/**
 * First-run setup, like WordPress: check the server, connect to the MySQL database the user made
 * in cPanel, create the tables, the station and the first admin, then write config.local.php.
 * Once that file says installed, the installer refuses to run again.
 */
final class Installer
{
    public function __construct(private readonly string $root)
    {
    }

    public function configPath(): string
    {
        return $this->root . '/config.local.php';
    }

    /**
     * On a public server the deploy step leaves install.key next to index.php; whoever installs
     * must type it, so a stranger can't run a fresh installer first. Local copies have no key.
     */
    public function setupKeyPath(): string
    {
        return $this->root . '/install.key';
    }

    public function setupKeyRequired(): bool
    {
        return is_file($this->setupKeyPath());
    }

    public function setupKeyMatches(string $given): bool
    {
        return !$this->setupKeyRequired() || hash_equals(trim((string) file_get_contents($this->setupKeyPath())), trim($given));
    }

    public function isInstalled(): bool
    {
        if (!is_file($this->configPath())) {
            return false;
        }
        $c = (array) require $this->configPath();
        return !empty($c['installed_at']);
    }

    /** @return list<array{label:string,ok:bool,found:string,required:bool,why:string}> */
    public function requirements(): array
    {
        $writable = fn(string $p) => is_dir($p) ? is_writable($p) : is_writable(dirname($p));
        $mb = fn(string $v) => (int) $v * (['g' => 1024, 'm' => 1, 'k' => 1 / 1024][strtolower(substr($v, -1))] ?? 1 / 1048576);
        $post = (string) ini_get('post_max_size');
        $upload = (string) ini_get('upload_max_filesize');
        return [
            ['label' => 'PHP version', 'ok' => PHP_VERSION_ID >= 80100, 'found' => PHP_VERSION, 'required' => true,
                'why' => 'Stationwagon needs PHP 8.1 or newer. In cPanel: MultiPHP Manager.'],
            ['label' => 'MySQL support (PDO)', 'ok' => extension_loaded('pdo_mysql'), 'found' => extension_loaded('pdo_mysql') ? 'Yes' : 'No', 'required' => true,
                'why' => 'Turn on the pdo_mysql extension (cPanel: Select PHP Version → Extensions).'],
            ['label' => 'Settings file can be written', 'ok' => $writable($this->configPath()), 'found' => $writable($this->configPath()) ? 'Yes' : 'No', 'required' => false,
                'why' => 'If not, the installer shows the file for you to upload yourself.'],
            ['label' => 'Storage folder writable', 'ok' => $writable($this->root . '/storage'), 'found' => $writable($this->root . '/storage') ? 'Yes' : 'No', 'required' => true,
                'why' => 'Songs and live pieces are saved in storage/. Set it to 755 or 775.'],
            ['label' => 'Upload size', 'ok' => min($mb($post), $mb($upload)) >= 32, 'found' => "post $post, file $upload", 'required' => false,
                'why' => 'Song uploads need at least 32 MB (64 MB is better). Live pieces need 2 MB.'],
        ];
    }

    public function requirementsMet(): bool
    {
        foreach ($this->requirements() as $r) {
            if ($r['required'] && !$r['ok']) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,string> field => problem, empty when the form is fine */
    public static function validate(array $in): array
    {
        $e = [];
        foreach (['db_host' => 'Database server', 'db_name' => 'Database name', 'db_user' => 'Database user',
                     'station_name' => 'Station name', 'admin_name' => 'Your name', 'admin_email' => 'Email'] as $k => $label) {
            if (trim((string) ($in[$k] ?? '')) === '') {
                $e[$k] = "$label is needed.";
            }
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,15}$/', (string) ($in['db_prefix'] ?? ''))) {
            $e['db_prefix'] = 'Use lowercase letters, digits and _ only, starting with a letter (for example sw_).';
        }
        if (!isset($e['admin_email']) && !filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $e['admin_email'] = 'That email address doesn’t look right.';
        }
        if (strlen((string) ($in['admin_password'] ?? '')) < 10) {
            $e['admin_password'] = 'Use at least 10 characters.';
        }
        if (!in_array($in['timezone'] ?? '', \DateTimeZone::listIdentifiers(), true)) {
            $e['timezone'] = 'Pick a timezone from the list.';
        }
        return $e;
    }

    public static function dbFrom(array $in): array
    {
        return [
            'host'   => trim((string) $in['db_host']),
            'port'   => (int) ($in['db_port'] ?? 3306) ?: 3306,
            'name'   => trim((string) $in['db_name']),
            'user'   => trim((string) $in['db_user']),
            'pass'   => (string) ($in['db_pass'] ?? ''),
            'prefix' => (string) $in['db_prefix'],
        ];
    }

    /** Returns null when the database works, or a plain explanation of what's wrong. */
    public function testDatabase(array $db): ?string
    {
        try {
            $conn = Db::connect($db);
            $version = (string) $conn->pdo->query('SELECT VERSION()')->fetchColumn();
            if (!self::versionOk($version)) {
                return "This database server is $version. Stationwagon needs MySQL 8.0+ or MariaDB 10.5+.";
            }
            $existing = (int) $conn->run(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?',
                [str_replace('_', '\\_', $db['prefix']) . '%']
            )->fetchColumn();
            if ($existing) {
                return "This database already has tables starting with “{$db['prefix']}”. Choose another table prefix, or an empty database.";
            }
            return null;
        } catch (PDOException $ex) {
            return self::explain($ex, $db);
        }
    }

    public static function versionOk(string $version): bool
    {
        if (preg_match('/^(\d+)\.(\d+)/', $version, $m) !== 1) {
            return false;
        }
        $isMaria = stripos($version, 'mariadb') !== false;
        return $isMaria ? version_compare("$m[1].$m[2]", '10.5', '>=') : version_compare("$m[1].$m[2]", '8.0', '>=');
    }

    private static function explain(PDOException $ex, array $db): string
    {
        $code = (int) ($ex->errorInfo[1] ?? $ex->getCode());
        return match ($code) {
            1045 => 'The database user name or password is wrong.',
            1044 => "The user “{$db['user']}” has no access to “{$db['name']}”. In cPanel → MySQL Databases, add the user to the database with All Privileges.",
            1049 => "The database “{$db['name']}” doesn’t exist yet. Create it in cPanel → MySQL Databases, then add the user to it.",
            2002, 2005 => "Can’t reach a database server at “{$db['host']}”. On cPanel this is usually localhost.",
            default => 'The database said: ' . $ex->getMessage(),
        };
    }

    /**
     * Create everything. Returns the settings file's text and whether it was written; when it
     * couldn't be written, the page shows it so the user can upload it.
     *
     * @return array{config_written:bool,config:string}
     */
    public function install(array $in): array
    {
        if ($this->isInstalled()) {
            throw new RuntimeException('Stationwagon is already installed here.');
        }
        if (!$this->setupKeyMatches((string) ($in['setup_key'] ?? ''))) {
            throw new RuntimeException('The setup key is wrong. It is in install.key on the server.');
        }
        $errors = self::validate($in);
        if ($errors) {
            throw new RuntimeException(implode(' ', $errors));
        }
        $db = self::dbFrom($in);
        if (($problem = $this->testDatabase($db)) !== null) {
            throw new RuntimeException($problem);
        }

        $conn = Db::connect($db);
        $created = [];
        try {
            // CREATE TABLE commits on its own in MySQL, so undo by hand if one fails
            foreach (Schema::create($db['prefix']) as $name => $sql) {
                $conn->pdo->exec($sql);
                $created[] = $name;
            }
        } catch (PDOException $ex) {
            $conn->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (array_reverse($created) as $name) {
                $conn->pdo->exec('DROP TABLE IF EXISTS ' . $conn->table($name));
            }
            $conn->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            throw new RuntimeException('Creating the tables failed: ' . $ex->getMessage());
        }

        $now = gmdate('Y-m-d H:i:s');
        $conn->pdo->beginTransaction();
        foreach (['schema_version' => (string) Schema::VERSION, 'installed_version' => Config::version()] as $k => $v) {
            $conn->insert('settings', ['name' => $k, 'value' => $v, 'updated_at' => $now]);
        }
        $stationId = $conn->insert('stations', [
            'slug' => self::slug((string) $in['station_name']),
            'name' => trim((string) $in['station_name']),
            'timezone' => $in['timezone'],
            'settings' => json_encode(self::defaultStationSettings()),
            'created_at' => $now,
        ]);
        $userId = $conn->insert('users', [
            'email' => strtolower(trim((string) $in['admin_email'])),
            'name' => trim((string) $in['admin_name']),
            'password_hash' => password_hash((string) $in['admin_password'], PASSWORD_DEFAULT),
            'role' => 'admin',
            'created_at' => $now,
        ]);
        $conn->insert('station_users', ['station_id' => $stationId, 'user_id' => $userId]);
        Roles::ensureDefaults($conn, $stationId);
        $conn->pdo->commit();

        foreach (['storage/media', 'storage/live', 'storage/cache'] as $dir) {
            if (!is_dir($this->root . "/$dir")) {
                mkdir($this->root . "/$dir", 0775, true);
            }
        }

        // 'cron': a cron job was set up, so the Overview warns if it stops; 'managed_by': set up by
        // the WordPress plugin, which also brings the code up to date
        $config = $this->configText($db, array_filter([
            'cron' => !empty($in['cron']),
            'managed_by' => (string) ($in['managed_by'] ?? ''),
        ]));
        $written = $this->writeConfig($config);
        if ($written && $this->setupKeyRequired()) {
            @unlink($this->setupKeyPath()); // one use only
        }
        return ['config_written' => $written, 'config' => $config];
    }

    public static function defaultStationSettings(): array
    {
        // The values the POC proved (see docs/POC-PLAN.md)
        return [
            'segment_seconds' => 6.0, 'crossfade_seconds' => 6.0, 'skip_fade_seconds' => 2.0,
            'plan_ahead_seconds' => 10800, 'recent_tracks' => 5, 'cut_lead_seconds' => 1.0,
            'live_timeout_seconds' => 8, 'listener_delay_seconds' => 20,
            'live_fade_seconds' => 6.0, // the go-live fade; until 2026-10-20 it was the crossfade
            // v0.4 Sound & safety (round 25)
            'fade_shape' => 'equal_power', 'duck_db' => -12, 'rejoin_minutes' => 10, 'silence_db' => -40,
            'live_kbps' => 192, 'prep_kbps' => 192,
            // song prep on upload (round 34): level to -16 LUFS; mastering like the station's Audition
            // chain is off unless chosen (round 39: it squeezed real music flat and pushed the treble)
            'prep_master' => 0, 'prep_level' => 1,
            // the loudness songs are levelled to (round 41: set with a slider in the Music library)
            'prep_target' => -16.0,
        ];
    }

    public static function slug(string $name): string
    {
        $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? '', '-'));
        return substr($s !== '' ? $s : 'station', 0, 64);
    }

    private function configText(array $db, array $extra = []): string
    {
        $values = [
            'db' => $db,
            'app_key' => bin2hex(random_bytes(32)),
            'installed_at' => gmdate('c'),
        ] + $extra;
        return "<?php\n// Written by the Stationwagon installer. Keep this file private: it holds the\n"
            . "// database password. It is never committed and the web server refuses to serve it.\n"
            . 'return ' . var_export($values, true) . ";\n";
    }

    private function writeConfig(string $text): bool
    {
        $tmp = $this->configPath() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $text) === false) {
            return false;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $this->configPath())) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
