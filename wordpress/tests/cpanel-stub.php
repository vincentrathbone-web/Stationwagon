<?php
// A stand-in for cPanel's API (port 2083), for wordpress/tests/setup_test.php on this PC. It
// answers the calls the Stationwagon plugin makes and does the database ones for real on the local
// MySQL (as root), so a station can be installed into what it "made":
//   SW_STUB_STATE=<state.json> SW_STUB_USER=<user> SW_STUB_SECRET=<password> SW_STUB_HOME=<home dir> \
//     php -S 127.0.0.1:<port> wordpress/tests/cpanel-stub.php
// It signs in like cPanel's login page (/login/?login_only=1, then a /cpsess… path and a cookie),
// refuses HTTP Basic sign-in as the test server's cPanel does, and takes a token too
// (Authorization: cpanel user:secret). The state file records subdomains, cron lines and every call.
declare(strict_types=1);

$stateFile = getenv('SW_STUB_STATE');
$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];
$state += ['subdomains' => [], 'cron' => [], 'calls' => [], 'databases' => [], 'users' => []];
$user = (string) getenv('SW_STUB_USER');
$secret = (string) getenv('SW_STUB_SECRET');
$prefix = $user . '_';
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
header('Content-Type: application/json');
// The login page's way (like the real host, HTTP Basic sign-in is refused): a session token in
// the path and a cpsession cookie
$session = 'cpsess' . substr(hash('sha256', $secret), 0, 10);
if ($path === '/login/') {
    $good = ($_POST['user'] ?? '') === $user && hash_equals($secret, (string) ($_POST['pass'] ?? ''));
    $state['calls'][] = '/login/ ' . ($good ? 'ok' : 'refused');
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
    if (!$good) {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'invalid_login']);
        exit;
    }
    setcookie('cpsession', "$user:$session", ['path' => '/', 'httponly' => true]);
    echo json_encode(['status' => 1, 'security_token' => "/$session", 'redirect' => "/$session/"]);
    exit;
}
$ok = hash_equals("cpanel $user:$secret", $auth); // an API token
if (str_starts_with($path, "/$session/")) {
    $ok = ($_COOKIE['cpsession'] ?? '') === "$user:$session";
    $path = substr($path, strlen("/$session"));
}
if (!$ok) {
    http_response_code(401);
    echo json_encode(['errors' => ['Access denied']]);
    exit;
}
$args = $_POST + $_GET;
$state['calls'][] = $path . (isset($args['cpanel_jsonapi_func']) ? ' ' . $args['cpanel_jsonapi_module'] . '::' . $args['cpanel_jsonapi_func'] : '');
$pdo = new PDO('mysql:host=127.0.0.1', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$uapi = fn($data) => ['status' => 1, 'data' => $data, 'errors' => null];
$fail = fn(string $msg) => ['status' => 0, 'data' => null, 'errors' => [$msg]];
$q = fn(string $s) => '`' . str_replace('`', '', $s) . '`';
$main = 'stub-site.test';

try {
    switch ($path) {
        case '/execute/Mysql/get_restrictions':
            $out = $uapi(['prefix' => $prefix, 'max_database_name_length' => 64, 'max_username_length' => 32]);
            break;
        case '/execute/Mysql/list_databases':
            $out = $uapi(array_map(fn($d) => ['database' => $d], $state['databases']));
            break;
        case '/execute/Mysql/list_users':
            $out = $uapi(array_map(fn($u) => ['user' => $u], $state['users']));
            break;
        case '/execute/Mysql/create_database':
            if (!str_starts_with($args['name'], $prefix)) { $out = $fail('The name must start with ' . $prefix); break; }
            $pdo->exec('CREATE DATABASE ' . $q($args['name']) . ' CHARACTER SET utf8mb4');
            $state['databases'][] = $args['name'];
            $out = $uapi(null);
            break;
        case '/execute/Mysql/create_user':
            if (strlen($args['password']) < 12) { $out = $fail('The password is too weak.'); break; }
            $pdo->prepare("CREATE USER ?@'%' IDENTIFIED BY ?")->execute([$args['name'], $args['password']]);
            $state['users'][] = $args['name'];
            $out = $uapi(null);
            break;
        case '/execute/Mysql/set_privileges_on_database':
            $pdo->exec('GRANT ALL PRIVILEGES ON ' . $q($args['database']) . ".* TO " . $pdo->quote($args['user']) . "@'%'");
            $out = $uapi(null);
            break;
        case '/execute/Mysql/delete_user':
            $pdo->exec('DROP USER IF EXISTS ' . $pdo->quote($args['name']) . "@'%'");
            $state['users'] = array_values(array_diff($state['users'], [$args['name']]));
            $out = $uapi(null);
            break;
        case '/execute/Mysql/delete_database':
            $pdo->exec('DROP DATABASE IF EXISTS ' . $q($args['name']));
            $state['databases'] = array_values(array_diff($state['databases'], [$args['name']]));
            $out = $uapi(null);
            break;
        case '/execute/DomainInfo/list_domains':
            $out = $uapi(['main_domain' => $main, 'sub_domains' => array_keys($state['subdomains']), 'addon_domains' => [getenv('SW_STUB_SITE') ?: 'localhost'], 'parked_domains' => []]);
            break;
        case '/execute/DomainInfo/domains_data':
            $out = $uapi(['main_domain' => ['domain' => $main, 'ip' => '192.0.2.10', 'homedir' => getenv('SW_STUB_HOME')], 'sub_domains' => [], 'addon_domains' => [], 'parked_domains' => []]);
            break;
        case '/execute/SubDomain/addsubdomain':
            $host = $args['domain'] . '.' . $args['rootdomain'];
            $state['subdomains'][$host] = $args['dir'];
            $out = $uapi(null);
            break;
        case '/json-api/cpanel':
            $fn = $args['cpanel_jsonapi_module'] . '::' . $args['cpanel_jsonapi_func'];
            if ($fn === 'Cron::listcron') {
                $data = array_map(fn($c, $i) => ['command' => $c, 'linekey' => "k$i", 'minute' => '*/5'], $state['cron'], array_keys($state['cron']));
            } elseif ($fn === 'Cron::add_line') {
                $state['cron'][] = $args['command'];
                $data = [['status' => 1, 'statusmsg' => 'crontab installed', 'linekey' => 'k' . (count($state['cron']) - 1)]];
            } else {
                $data = [];
            }
            $out = ['cpanelresult' => ['data' => $data, 'event' => ['result' => 1]]];
            break;
        default:
            http_response_code(404);
            $out = ['errors' => ["No $path"]];
    }
} catch (Throwable $ex) {
    $out = $fail($ex->getMessage());
}
file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
echo json_encode($out);
