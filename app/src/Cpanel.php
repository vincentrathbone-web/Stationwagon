<?php
declare(strict_types=1);

namespace Stationwagon;

use RuntimeException;

/**
 * Talks to this hosting account's cPanel through its own API, to make a database, a subdomain or
 * a cron job during setup (the station's installer and setup.php; the WordPress plugin has its own
 * copy built on WordPress's HTTP functions). It signs in with the cPanel username and password the
 * person typed the way cPanel's login page does (a session), else with HTTP Basic sign-in (some
 * hosts switch that off for the API) or a cPanel API token in the password field. The login is
 * used for that one request and never saved.
 *
 * cPanel listens on port 2083 of the same server, so this asks https://127.0.0.1:2083; its
 * certificate is for the server's name, so it isn't checked on that local hop. SW_CPANEL_URL (an
 * environment variable or constant) points it elsewhere for tests.
 */
final class Cpanel
{
    private string $base;
    private ?string $auth = null;
    /** @var array{prefix:string,cookie:string}|null */
    private ?array $session = null;
    private string $refusal = '';

    public function __construct(private readonly string $user, private readonly string $secret, ?string $base = null)
    {
        $this->base = rtrim($base ?? (defined('SW_CPANEL_URL') ? (string) constant('SW_CPANEL_URL') : (getenv('SW_CPANEL_URL') ?: 'https://127.0.0.1:2083')), '/');
    }

    /** The account's own name, as a default for the username field. */
    public static function guessUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (!empty($pw['name'])) {
                return (string) $pw['name'];
            }
        }
        return (string) get_current_user();
    }

    /** A UAPI call (cPanel's API 3): its data, or an exception with cPanel's own message. */
    public function uapi(string $module, string $function, array $args = []): mixed
    {
        $r = $this->request("/execute/$module/$function", $args);
        if (empty($r['status'])) {
            throw new RuntimeException(implode(' ', array_map('strval', (array) ($r['errors'] ?? []))) ?: "cPanel couldn't do $module::$function.");
        }
        return $r['data'] ?? null;
    }

    /** An API 2 call (cron jobs are only there). */
    public function api2(string $module, string $function, array $args = []): array
    {
        $r = $this->request('/json-api/cpanel', $args + [
            'cpanel_jsonapi_user' => $this->user, 'cpanel_jsonapi_apiversion' => '2',
            'cpanel_jsonapi_module' => $module, 'cpanel_jsonapi_func' => $function,
        ]);
        $result = $r['cpanelresult'] ?? [];
        if (!empty($result['error']) || (isset($result['event']['result']) && !$result['event']['result'])) {
            throw new RuntimeException((string) ($result['error'] ?? "cPanel couldn't do $module::$function."));
        }
        $data = (array) ($result['data'] ?? []);
        if (isset($data[0]['status']) && !$data[0]['status']) {
            throw new RuntimeException((string) ($data[0]['statusmsg'] ?? "cPanel couldn't do $module::$function."));
        }
        return $data;
    }

    /** Null when the login works, else a plain explanation. */
    public function check(): ?string
    {
        try {
            $this->uapi('Mysql', 'get_restrictions');
            return null;
        } catch (RuntimeException $ex) {
            return $ex->getMessage();
        }
    }

    /**
     * A new database with its own user and a random password, with all privileges on it.
     * @return array{name:string,user:string,pass:string}
     */
    public function createDatabase(string $base = 'stationwagon'): array
    {
        $limits = (array) $this->uapi('Mysql', 'get_restrictions');
        $prefix = (string) ($limits['prefix'] ?? ($this->user . '_'));
        $dbs = array_column((array) $this->uapi('Mysql', 'list_databases'), 'database');
        $users = array_column((array) $this->uapi('Mysql', 'list_users'), 'user');
        $name = self::free($prefix . $base, $dbs, (int) ($limits['max_database_name_length'] ?? 64));
        $user = self::free($prefix . 'swapp', $users, (int) ($limits['max_username_length'] ?? 16));
        $pass = self::password();
        $this->uapi('Mysql', 'create_database', ['name' => $name]);
        try {
            $this->uapi('Mysql', 'create_user', ['name' => $user, 'password' => $pass]);
            $this->uapi('Mysql', 'set_privileges_on_database', ['user' => $user, 'database' => $name, 'privileges' => 'ALL PRIVILEGES']);
        } catch (RuntimeException $ex) {
            try {
                $this->uapi('Mysql', 'delete_user', ['name' => $user]);
            } catch (RuntimeException) {
            }
            $this->uapi('Mysql', 'delete_database', ['name' => $name]);
            throw $ex;
        }
        return ['name' => $name, 'user' => $user, 'pass' => $pass];
    }

    /** @return array{main:string,all:list<string>,ip:string,home:string} */
    public function domains(): array
    {
        $list = (array) $this->uapi('DomainInfo', 'list_domains');
        $data = (array) $this->uapi('DomainInfo', 'domains_data', ['format' => 'hash']);
        $main = (string) ($list['main_domain'] ?? '');
        $all = array_merge([$main], (array) ($list['addon_domains'] ?? []), (array) ($list['sub_domains'] ?? []), (array) ($list['parked_domains'] ?? []));
        $first = (array) ($data['main_domain'] ?? []);
        return ['main' => $main, 'all' => array_values(array_filter(array_unique($all))), 'ip' => (string) ($first['ip'] ?? ''), 'home' => (string) ($first['homedir'] ?? '')];
    }

    /** Make sub.rootdomain with its folder at $dir (relative to the home folder). */
    public function addSubdomain(string $sub, string $rootDomain, string $dir): void
    {
        $this->uapi('SubDomain', 'addsubdomain', ['domain' => $sub, 'rootdomain' => $rootDomain, 'dir' => $dir, 'disallowdot' => 1]);
    }

    /** Add "every 5 minutes: $command", unless the same command is there already. */
    public function addCron(string $command): bool
    {
        foreach ($this->api2('Cron', 'listcron') as $line) {
            if (trim((string) ($line['command'] ?? '')) === trim($command)) {
                return false;
            }
        }
        $this->api2('Cron', 'add_line', ['minute' => '*/5', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*', 'command' => $command]);
        return true;
    }

    /** The cron line for a station in $dir: /usr/local/bin/php follows the account's PHP version. */
    public static function cronCommand(string $dir): string
    {
        $php = is_executable('/usr/local/bin/php') ? '/usr/local/bin/php' : 'php';
        return "$php $dir/cli/plan.php > /dev/null 2>&1";
    }

    // ---- Plumbing ------------------------------------------------------------------------------

    /** @return array{code:int,body:string,cookies:array<string,string>} */
    private function post(string $url, array $fields, array $headers = []): array
    {
        $cookies = [];
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, // the local hop; see the note at the top
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$cookies): int {
                if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $line, $m)) {
                    $cookies[trim($m[1])] = trim($m[2]);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($c);
        if ($body === false) {
            $err = curl_error($c);
            curl_close($c);
            throw new RuntimeException("Can't reach cPanel on this server ($err). Is this a cPanel hosting account?");
        }
        $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        return ['code' => $code, 'body' => (string) $body, 'cookies' => $cookies];
    }

    /** Sign in the way cPanel's own login page does, keeping its session for the calls after. */
    private function login(): bool
    {
        $r = $this->post($this->base . '/login/?login_only=1', ['user' => $this->user, 'pass' => $this->secret]);
        $body = json_decode($r['body'], true);
        if (is_array($body) && !empty($body['status']) && !empty($body['security_token']) && isset($r['cookies']['cpsession'])) {
            $this->session = ['prefix' => '/' . trim((string) $body['security_token'], '/'), 'cookie' => 'cpsession=' . $r['cookies']['cpsession']];
            return true;
        }
        $msg = is_array($body) ? (string) ($body['message'] ?? '') : '';
        if ($msg !== '' && $msg !== 'invalid_login') {
            $this->refusal = $msg;
        }
        return false;
    }

    private function request(string $path, array $args): array
    {
        $last = null;
        foreach ($this->auth !== null ? [$this->auth] : ['session', 'basic', 'token'] as $auth) {
            $url = $this->base . $path;
            if ($auth === 'session') {
                if ($this->session === null && !$this->login()) {
                    continue;
                }
                $url = $this->base . $this->session['prefix'] . $path;
                $headers = ['Cookie: ' . $this->session['cookie']];
            } else {
                $headers = ['Authorization: ' . ($auth === 'basic' ? 'Basic ' . base64_encode("{$this->user}:{$this->secret}") : "cpanel {$this->user}:{$this->secret}")];
            }
            $r = $this->post($url, $args, $headers);
            $body = json_decode($r['body'], true);
            if ($r['code'] === 200 && is_array($body)) {
                $this->auth = $auth;
                return $body;
            }
            $last = $r['code'];
        }
        if ($last === null || in_array($last, [301, 302, 401, 403], true)) {
            throw new RuntimeException('cPanel refused that username and password' . ($this->refusal !== '' ? ': ' . $this->refusal : '.')
                . ' Use the same login as cPanel’s own sign-in page (the cPanel username, not an email address).');
        }
        throw new RuntimeException("cPanel answered with an error ($last).");
    }

    /** $wanted, or $wanted2, $wanted3… whichever isn't taken, cut to $max characters. */
    private static function free(string $wanted, array $taken, int $max): string
    {
        $wanted = substr($wanted, 0, $max);
        for ($i = 1; $i < 100; $i++) {
            $name = $i === 1 ? $wanted : substr($wanted, 0, $max - strlen((string) $i)) . $i;
            if (!in_array($name, $taken, true)) {
                return $name;
            }
        }
        throw new RuntimeException("No free name left for $wanted.");
    }

    /** A strong random password that cPanel's strength rules accept. */
    public static function password(): string
    {
        $chars = [];
        foreach (['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '-_.+'] as $set) {
            for ($i = 0; $i < 6; $i++) {
                $chars[] = $set[random_int(0, strlen($set) - 1)];
            }
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }
}
