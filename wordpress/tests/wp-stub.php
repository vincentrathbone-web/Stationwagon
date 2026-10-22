<?php
// Just enough of WordPress to run the Stationwagon Updates plugin's routes under PHP's own web server,
// for desktop/tests/update_test.mjs:
//   SW_WP_STORE=<options.json> SW_TEST_GITHUB=<stand-in GitHub> php -S 127.0.0.1:<port> wordpress/tests/wp-stub.php
// Options and transients live in that JSON file; wp_remote_get is curl; GET /wp-json/<route> runs the route.
declare(strict_types=1);

const ABSPATH = __DIR__ . '/';
const MINUTE_IN_SECONDS = 60;

$GLOBALS['sw_actions'] = [];
$GLOBALS['sw_routes'] = [];
function add_action(string $hook, $fn): void { $GLOBALS['sw_actions'][$hook][] = $fn; }
function register_rest_route(string $ns, string $route, array $args): void { $GLOBALS['sw_routes']["/$ns$route"] = $args; }
function apply_filters(string $name, $value) { return $name === 'stationwagon_updates_github_api' && getenv('SW_TEST_GITHUB') ? getenv('SW_TEST_GITHUB') : $value; }

function sw_store(?array $set = null): array {
    $f = getenv('SW_WP_STORE');
    if ($set !== null) { file_put_contents($f, json_encode($set)); return $set; }
    return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
}
function get_option(string $k, $d = false) { return sw_store()['options'][$k] ?? $d; }
function update_option(string $k, $v, $autoload = null): bool { $s = sw_store(); $s['options'][$k] = $v; sw_store($s); return true; }
function get_transient(string $k) { $t = sw_store()['transients'][$k] ?? null; return $t && $t[1] > time() ? $t[0] : false; }
function set_transient(string $k, $v, int $ttl): bool { $s = sw_store(); $s['transients'][$k] = [$v, time() + $ttl]; sw_store($s); return true; }
function delete_transient(string $k): bool { $s = sw_store(); unset($s['transients'][$k]); sw_store($s); return true; }

class WP_Error { public $code; public $message; public $data; function __construct($c, $m, $d = []) { $this->code = $c; $this->message = $m; $this->data = $d; } function get_error_message() { return $this->message; } }
function is_wp_error($x): bool { return $x instanceof WP_Error; }
class WP_REST_Response { public $data; public $status; public $headers = []; function __construct($d = null, $s = 200) { $this->data = $d; $this->status = $s; } function header($k, $v) { $this->headers[$k] = $v; } }
class SW_Request { private $q; function __construct(array $q) { $this->q = $q; } function get_param($k) { return $this->q[$k] ?? null; } }

function rest_url(string $path = ''): string { return 'http://' . $_SERVER['HTTP_HOST'] . '/wp-json/' . ltrim($path, '/'); }
function add_query_arg(string $k, string $v, string $url): string { return $url . (strpos($url, '?') === false ? '?' : '&') . $k . '=' . $v; }
function nocache_headers(): void { header('Cache-Control: no-cache, must-revalidate, max-age=0'); }

/** wp_remote_get: curl, following redirects only when asked ('redirection' > 0, as WordPress does). */
function wp_remote_get(string $url, array $args = []) {
    $s = sw_store(); $s['github_calls'] = ($s['github_calls'] ?? 0) + 1; sw_store($s);
    $ch = curl_init($url);
    $hdrs = [];
    foreach ($args['headers'] ?? [] as $k => $v) $hdrs[] = "$k: $v";
    $got = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hdrs, CURLOPT_TIMEOUT => $args['timeout'] ?? 5,
        CURLOPT_FOLLOWLOCATION => ($args['redirection'] ?? 5) > 0, CURLOPT_MAXREDIRS => (int) ($args['redirection'] ?? 5),
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$got) { $p = explode(':', $line, 2); if (count($p) === 2) $got[strtolower(trim($p[0]))] = trim($p[1]); return strlen($line); }]);
    $body = curl_exec($ch);
    if ($body === false) { $e = curl_error($ch); curl_close($ch); return new WP_Error('http_request_failed', $e); }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['response' => ['code' => $code], 'headers' => $got, 'body' => $body];
}
function wp_remote_retrieve_response_code($r) { return $r['response']['code'] ?? ''; }
function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }
function wp_remote_retrieve_header($r, $k) { return $r['headers'][strtolower($k)] ?? ''; }

require __DIR__ . '/../stationwagon-updates/stationwagon-updates.php';
foreach ($GLOBALS['sw_actions']['rest_api_init'] ?? [] as $fn) $fn();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
if (strpos($path, '/wp-json') !== 0 || !isset($GLOBALS['sw_routes'][substr($path, 8)])) {
    http_response_code(404);
    echo json_encode(['code' => 'rest_no_route']);
    return;
}
$out = ($GLOBALS['sw_routes'][substr($path, 8)]['callback'])(new SW_Request($q));
header('Content-Type: application/json; charset=UTF-8');
if ($out instanceof WP_Error) {
    http_response_code($out->data['status'] ?? 500);
    echo json_encode(['code' => $out->code, 'message' => $out->message]);
} else {
    http_response_code($out->status);
    foreach ($out->headers as $k => $v) header("$k: $v");
    if ($out->data !== null) echo json_encode($out->data);
}
