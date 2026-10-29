<?php
/**
 * Talks to this hosting account's cPanel through its own API (the same one cPanel's screens use),
 * to make a database, a subdomain or a cron job during setup. It signs in with the cPanel username
 * and password the person typed into the wizard the way cPanel's own login page does (a session),
 * else with HTTP Basic sign-in or a cPanel API token in the password field; they're used for that
 * one request and never saved. (Some hosts switch HTTP Basic sign-in off for the API.)
 *
 * cPanel listens on port 2083 of the same server, so the plugin asks https://127.0.0.1:2083. Its
 * certificate is for the server's own name, not 127.0.0.1, so it isn't checked on this local hop.
 * Tests point it elsewhere with the STATIONWAGON_CPANEL_URL constant.
 */

defined('ABSPATH') || exit;

final class Stationwagon_Cpanel
{
    /** @var string */
    private $base;
    /** @var string */
    private $user;
    /** @var string */
    private $secret;
    /** @var string|null 'session', 'basic' or 'token', once a call has worked */
    private $auth = null;
    /** @var array{prefix:string,cookie:string}|null the login page's session (cPanel's security token path and cookie) */
    private $session = null;
    /** @var string what cPanel said when it refused the login */
    private $refusal = '';

    public function __construct(string $user, string $secret, ?string $base = null)
    {
        $this->user = trim($user);
        $this->secret = $secret;
        $this->base = rtrim($base ?? (defined('STATIONWAGON_CPANEL_URL') ? STATIONWAGON_CPANEL_URL : 'https://127.0.0.1:2083'), '/');
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

    /**
     * Call a UAPI function (cPanel's API 3). Returns its data, or throws with cPanel's own message.
     * @return mixed
     */
    public function uapi(string $module, string $function, array $args = [])
    {
        $r = $this->request("/execute/$module/$function", $args);
        if (empty($r['status'])) {
            throw new RuntimeException(self::errors($r) ?: "cPanel couldn't do $module::$function.");
        }
        return $r['data'] ?? null;
    }

    /** Call an API 2 function (cron jobs are only there). */
    public function api2(string $module, string $function, array $args = []): array
    {
        $r = $this->request('/json-api/cpanel', $args + [
            'cpanel_jsonapi_user' => $this->user,
            'cpanel_jsonapi_apiversion' => '2',
            'cpanel_jsonapi_module' => $module,
            'cpanel_jsonapi_func' => $function,
        ]);
        $result = $r['cpanelresult'] ?? [];
        if (!empty($result['error']) || (isset($result['event']['result']) && !$result['event']['result'])) {
            throw new RuntimeException((string) ($result['error'] ?? "cPanel couldn't do $module::$function."));
        }
        $data = $result['data'] ?? [];
        if (isset($data[0]['status']) && !$data[0]['status']) {
            throw new RuntimeException((string) ($data[0]['statusmsg'] ?? "cPanel couldn't do $module::$function."));
        }
        return is_array($data) ? $data : [];
    }

    /** Check the login works. Returns null when it does, or a plain explanation. */
    public function check(): ?string
    {
        try {
            $this->uapi('Mysql', 'get_restrictions');
            return null;
        } catch (RuntimeException $ex) {
            return $ex->getMessage();
        }
    }

    // ---- What setup needs ------------------------------------------------------------------------

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
            } catch (RuntimeException $ignored) {
            }
            $this->uapi('Mysql', 'delete_database', ['name' => $name]);
            throw $ex;
        }
        return ['name' => $name, 'user' => $user, 'pass' => $pass];
    }

    /** The domains on this account: ['main' => 'example.com', 'all' => [...], 'home' => '/home/user', 'ip' => '1.2.3.4']. */
    public function domains(): array
    {
        $list = (array) $this->uapi('DomainInfo', 'list_domains');
        $data = (array) $this->uapi('DomainInfo', 'domains_data', ['format' => 'hash']);
        $main = (string) ($list['main_domain'] ?? '');
        $all = array_merge([$main], (array) ($list['addon_domains'] ?? []), (array) ($list['sub_domains'] ?? []), (array) ($list['parked_domains'] ?? []));
        $first = (array) ($data['main_domain'] ?? []);
        return [
            'main' => $main,
            'all' => array_values(array_filter(array_unique($all))),
            'ip' => (string) ($first['ip'] ?? ''),
            'home' => (string) ($first['homedir'] ?? ''),
        ];
    }

    /** Make sub.rootdomain with its folder at $dir (relative to the home folder). */
    public function addSubdomain(string $sub, string $rootDomain, string $dir): void
    {
        $this->uapi('SubDomain', 'addsubdomain', ['domain' => $sub, 'rootdomain' => $rootDomain, 'dir' => $dir, 'disallowdot' => 1]);
    }

    /** The account's cron lines (API 2 Cron::listcron). */
    public function cronLines(): array
    {
        return $this->api2('Cron', 'listcron');
    }

    /** Add "every 5 minutes: $command", unless the same command is there already. */
    public function addCron(string $command): bool
    {
        foreach ($this->cronLines() as $line) {
            if (trim((string) ($line['command'] ?? '')) === trim($command)) {
                return false;
            }
        }
        $this->api2('Cron', 'add_line', ['minute' => '*/5', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*', 'command' => $command]);
        return true;
    }

    // ---- Plumbing ------------------------------------------------------------------------------

    /**
     * Sign in the way cPanel's own login page does (works wherever that page does, also on hosts
     * that switch off HTTP Basic sign-in for the API), keeping its session for the calls after.
     */
    private function login(): bool
    {
        $response = wp_remote_post($this->base . '/login/?login_only=1', [
            'body' => ['user' => $this->user, 'pass' => $this->secret],
            'timeout' => 60,
            'sslverify' => false,
            'redirection' => 0,
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException("Can't reach cPanel on this server (" . $response->get_error_message() . '). Is this a cPanel hosting account?');
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $cookie = '';
        foreach (wp_remote_retrieve_cookies($response) as $c) {
            if ($c->name === 'cpsession') {
                $cookie = 'cpsession=' . $c->value;
            }
        }
        if (is_array($body) && !empty($body['status']) && !empty($body['security_token']) && $cookie !== '') {
            $this->session = ['prefix' => '/' . trim((string) $body['security_token'], '/'), 'cookie' => $cookie];
            return true;
        }
        $msg = is_array($body) ? (string) ($body['message'] ?? '') : '';
        if ($msg !== '' && $msg !== 'invalid_login') {
            $this->refusal = $msg; // e.g. a brute-force lock, or a password that must be changed first
        }
        return false;
    }

    private function request(string $path, array $args): array
    {
        $tries = $this->auth !== null ? [$this->auth] : ['session', 'basic', 'token'];
        $last = null;
        foreach ($tries as $auth) {
            $url = $this->base . $path;
            if ($auth === 'session') {
                if ($this->session === null && !$this->login()) {
                    continue;
                }
                $url = $this->base . $this->session['prefix'] . $path;
                $headers = ['Cookie' => $this->session['cookie']];
            } else {
                $headers = ['Authorization' => $auth === 'basic'
                    ? 'Basic ' . base64_encode($this->user . ':' . $this->secret)
                    : 'cpanel ' . $this->user . ':' . $this->secret];
            }
            $response = wp_remote_post($url, [
                'headers' => $headers,
                'body' => $args,
                'timeout' => 60,
                'sslverify' => false, // the local hop to 127.0.0.1; see the note at the top
                'redirection' => 0,
            ]);
            if (is_wp_error($response)) {
                throw new RuntimeException("Can't reach cPanel on this server (" . $response->get_error_message() . '). Is this a cPanel hosting account?');
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            if ($code === 200 && is_array($body)) {
                $this->auth = $auth;
                return $body;
            }
            $last = $code;
        }
        if ($last === null || in_array($last, [401, 403], true) || $last === 301 || $last === 302) {
            throw new RuntimeException('cPanel refused that username and password' . ($this->refusal !== '' ? ': ' . $this->refusal : '.')
                . ' Use the same login as cPanel’s own sign-in page (the cPanel username, not an email address).');
        }
        throw new RuntimeException("cPanel answered with an error ($last).");
    }

    private static function errors(array $r): string
    {
        return implode(' ', array_map('strval', (array) ($r['errors'] ?? [])));
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
        throw new RuntimeException('No free name left for ' . $wanted . '.');
    }

    /** A strong random password that cPanel's strength rules accept. */
    public static function password(): string
    {
        $sets = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '-_.+'];
        $chars = [];
        foreach ($sets as $set) {
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
