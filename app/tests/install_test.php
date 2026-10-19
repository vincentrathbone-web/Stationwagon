<?php
// Command-line test of the installer against a real MySQL/MariaDB server. Creates a throwaway
// database, installs into it, checks the result, then drops it.
//   php app/tests/install_test.php
// Server from SW_TEST_DB_HOST / SW_TEST_DB_USER / SW_TEST_DB_PASS (default 127.0.0.1, root, no
// password: Laragon's MySQL). The user needs CREATE DATABASE rights, unless SW_TEST_DB_NAME names an
// existing empty database to use instead (cPanel users can't create databases); then only the
// test's own tables are dropped afterwards.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Db, Installer, Schema};

$failures = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . ($detail !== '' ? "  ($detail)" : '') . "\n";
    if (!$ok) { $failures++; }
}

$server = ['host' => getenv('SW_TEST_DB_HOST') ?: '127.0.0.1', 'user' => getenv('SW_TEST_DB_USER') ?: 'root', 'pass' => getenv('SW_TEST_DB_PASS') ?: ''];
$given = getenv('SW_TEST_DB_NAME') ?: '';
$dbName = $given !== '' ? $given : 'sw_install_test_' . getmypid();
$admin = new PDO("mysql:host={$server['host']};charset=utf8mb4", $server['user'], $server['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($given === '') {
    $admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}
echo 'Server: ' . $admin->query('SELECT VERSION()')->fetchColumn() . "
";
$root = sys_get_temp_dir() . '/sw-install-test-' . getmypid();
mkdir("$root/storage", 0775, true);

$form = [
    'db_host' => $server['host'], 'db_port' => '3306', 'db_name' => $dbName, 'db_user' => $server['user'],
    'db_pass' => $server['pass'], 'db_prefix' => 'sw_', 'station_name' => 'Radio WordWave / Radio WoordGolf',
    'timezone' => 'Africa/Johannesburg', 'admin_name' => 'Test Admin', 'admin_email' => 'Admin@Example.test',
    'admin_password' => 'correct horse battery',
];

try {
    $inst = new Installer($root);
    echo "Checks\n";
    check('server checks listed', count($inst->requirements()) >= 4);
    check('required checks pass here', $inst->requirementsMet());
    check('MariaDB 10.11 accepted', Installer::versionOk('10.11.19-MariaDB'));
    check('MariaDB 10.4 refused', !Installer::versionOk('10.4.32-MariaDB'));
    check('MySQL 8.4 accepted', Installer::versionOk('8.4.3'));
    check('MySQL 5.7 refused', !Installer::versionOk('5.7.44'));

    echo "Form\n";
    $bad = Installer::validate(['db_prefix' => 'SW-', 'admin_email' => 'nope', 'admin_password' => 'short', 'timezone' => 'Mars/Base'] + $form);
    check('bad prefix, email, password and timezone caught', isset($bad['db_prefix'], $bad['admin_email'], $bad['admin_password'], $bad['timezone']), implode(', ', array_keys($bad)));
    check('good form passes', Installer::validate($form) === []);
    check('station slug', Installer::slug($form['station_name']) === 'radio-wordwave-radio-woordgolf');

    echo "Database test\n";
    $missing = $inst->testDatabase(Installer::dbFrom(['db_name' => $dbName . '_nope'] + $form));
    // MySQL says "doesn't exist" to root; a cPanel user gets "no access" for any database not theirs
check('missing database explained', $missing !== null && (str_contains($missing, 'doesn’t exist') || str_contains($missing, 'no access')), (string) $missing);
    $wrong = $inst->testDatabase(Installer::dbFrom(['db_pass' => 'definitely-wrong', 'db_user' => 'sw_no_such_user'] + $form));
    check('wrong login explained', $wrong !== null && str_contains($wrong, 'wrong'), (string) $wrong);
    check('empty database accepted', $inst->testDatabase(Installer::dbFrom($form)) === null);

    echo "Setup key\n";
    check('no key file means no key needed', !$inst->setupKeyRequired() && $inst->setupKeyMatches(''));
    file_put_contents("$root/install.key", "k3y-for-test\n");
    check('key file means key needed', $inst->setupKeyRequired());
    check('wrong key refused', !$inst->setupKeyMatches('nope'));
    check('right key accepted (spaces ignored)', $inst->setupKeyMatches(' k3y-for-test '));
    $refused = null;
    try { $inst->install(['setup_key' => 'nope'] + $form); } catch (RuntimeException $ex) { $refused = $ex->getMessage(); }
    check('install with a wrong key refused', $refused !== null && str_contains($refused, 'setup key'));
    $form['setup_key'] = 'k3y-for-test';

    echo "Install\n";
    $result = $inst->install($form);
    check('key file deleted after install', !is_file("$root/install.key"));
    check('settings file written', $result['config_written'] && is_file("$root/config.local.php"));
    $cfg = require "$root/config.local.php";
    check('settings file has database, key and install time', ($cfg['db']['name'] ?? '') === $dbName && strlen($cfg['app_key'] ?? '') === 64 && !empty($cfg['installed_at']));
    $db = Db::connect($cfg['db']);
    $tables = array_column($db->run('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0);
    check('all tables created', count($tables) === count(Schema::TABLES), count($tables) . ' tables');
    check('schema version recorded', $db->run('SELECT value FROM ' . $db->table('settings') . " WHERE name = 'schema_version'")->fetchColumn() === (string) Schema::VERSION);
    $station = $db->run('SELECT * FROM ' . $db->table('stations'))->fetch();
    check('station saved', $station['slug'] === 'radio-wordwave-radio-woordgolf' && $station['timezone'] === 'Africa/Johannesburg');
    check('station has the POC defaults', (json_decode($station['settings'], true)['crossfade_seconds'] ?? null) == 6);
    $user = $db->run('SELECT * FROM ' . $db->table('users'))->fetch();
    check('admin saved with a hashed password', $user['role'] === 'admin' && $user['email'] === 'admin@example.test' && password_verify($form['admin_password'], $user['password_hash']) && !str_contains($user['password_hash'], $form['admin_password']));
    check('admin linked to the station', (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('station_users'))->fetchColumn() === 1);
    check('storage folders made', is_dir("$root/storage/media") && is_dir("$root/storage/live"));

    echo "Locked after install\n";
    check('installer reports installed', $inst->isInstalled());
    $again = null;
    try { $inst->install($form); } catch (RuntimeException $ex) { $again = $ex->getMessage(); }
    check('second install refused', $again !== null && str_contains($again, 'already installed'));
    unlink("$root/config.local.php");
    $clash = $inst->testDatabase(Installer::dbFrom($form));
    check('same prefix in a used database refused', $clash !== null && str_contains($clash, 'already has tables'));
    check('another prefix in the same database accepted', $inst->testDatabase(Installer::dbFrom(['db_prefix' => 'sw2_'] + $form)) === null);
} finally {
    if ($given === '') {
        $admin->exec("DROP DATABASE IF EXISTS `$dbName`");
    } else {
        $admin->exec("USE `$dbName`");
        $admin->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (Schema::TABLES as $t) {
            $admin->exec("DROP TABLE IF EXISTS `sw_$t`");
        }
        $admin->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($root);
}

echo $failures ? "\n$failures check(s) failed\n" : "\nAll checks passed\n";
exit($failures ? 1 : 0);
