<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * Sign-in for station staff. Roles rank dj < manager < admin; a page asks for the lowest role
 * it needs. Failed attempts are counted per email and per address in storage/cache/throttle, so
 * guessing passwords gets locked out for a while. "Keep me signed in" leaves a token cookie that
 * signs the device in again for 30 days, until the password changes or the DJ signs out there.
 */
final class Auth
{
    public const ROLES = ['dj' => 1, 'manager' => 2, 'admin' => 3];
    public const MAX_FAILS_PER_EMAIL = 5;
    public const MAX_FAILS_PER_ADDRESS = 20;
    public const LOCK_SECONDS = 900;
    public const REMEMBER_COOKIE = 'sw_remember';
    public const REMEMBER_DAYS = 30;

    private static ?self $instance = null;
    private ?array $user = null;
    private bool $loaded = false;

    public function __construct(private readonly Db $db, private readonly string $throttleDir)
    {
    }

    public static function fromConfig(): self
    {
        return self::$instance ??= new self(Db::fromConfig(), SW_ROOT . '/storage/cache/throttle');
    }

    /** @return array{ok:bool,error?:string} */
    public function attempt(string $email, string $password, string $address, bool $remember = false): array
    {
        $email = strtolower(trim($email));
        $keys = ['e:' . $email => self::MAX_FAILS_PER_EMAIL, 'a:' . $address => self::MAX_FAILS_PER_ADDRESS];
        foreach ($keys as $key => $max) {
            if ($this->fails($key) >= $max) {
                return ['ok' => false, 'error' => 'Too many tries. Wait 15 minutes, then try again.'];
            }
        }
        $row = $this->db->run('SELECT * FROM ' . $this->db->table('users') . ' WHERE email = ?', [$email])->fetch() ?: null;
        // Check a password even for an unknown email, so the answer takes the same time
        $hash = $row['password_hash'] ?? '$2y$10$ESYEKo1T/.9/frBIyCtnFua70vIni7cFwBpM6xZAlhmRZqSDD8uwK'; // hash of a random throwaway
        if (!password_verify($password, $hash) || $row === null) {
            foreach (array_keys($keys) as $key) {
                $this->fail($key);
            }
            return ['ok' => false, 'error' => 'That email and password don’t match.'];
        }
        $this->clear('e:' . $email);
        if (!empty($row['blocked_at'])) {
            return ['ok' => false, 'error' => 'This account is blocked. Ask the station’s admin.'];
        }
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            $row['password_hash'] = $this->setPassword((int) $row['id'], $password);
        }
        $this->db->run('UPDATE ' . $this->db->table('users') . ' SET last_login_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $row['id']]);
        Session::start();
        Session::regenerate();
        $_SESSION['user_id'] = (int) $row['id'];
        $_SESSION['pw'] = substr($row['password_hash'], -12); // a password change signs out other sessions
        if ($remember) {
            $this->remember((int) $row['id'], $row['password_hash']);
        }
        $this->loaded = false;
        return ['ok' => true];
    }

    /** The signed-in user, or null. */
    public function user(): ?array
    {
        if ($this->loaded) {
            return $this->user;
        }
        $this->loaded = true;
        $this->user = null;
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if ($id <= 0) {
            $id = $this->fromRemember();
        }
        if ($id > 0) {
            $row = $this->db->run('SELECT id, email, name, role, password_hash, last_login_at, blocked_at, permissions FROM ' . $this->db->table('users') . ' WHERE id = ?', [$id])->fetch() ?: null;
            // a blocked person is signed out on their next request
            if ($row !== null && empty($row['blocked_at']) && hash_equals(substr($row['password_hash'], -12), (string) ($_SESSION['pw'] ?? ''))) {
                unset($row['password_hash']);
                $row['id'] = (int) $row['id']; // MariaDB hands ids back as strings
                $this->user = $row;
            } else {
                unset($_SESSION['user_id'], $_SESSION['pw']);
            }
        }
        return $this->user;
    }

    public function signOut(): void
    {
        $token = $this->rememberCookie();
        if ($token !== null) {
            $this->db->run('DELETE FROM ' . $this->db->table('remember_tokens') . ' WHERE selector = ?', [$token[0]]);
        }
        if (isset($_COOKIE[self::REMEMBER_COOKIE])) {
            $this->forget();
        }
        unset($_SESSION['user_id'], $_SESSION['pw']);
        Session::regenerate();
        $this->loaded = false;
    }

    /** A new "Keep me signed in" token for this device (expired ones are cleared on the way). */
    private function remember(int $userId, string $hash): void
    {
        $t = $this->db->table('remember_tokens');
        $this->db->run("DELETE FROM $t WHERE expires_at < ?", [gmdate('Y-m-d H:i:s')]);
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + self::REMEMBER_DAYS * 86400;
        $this->db->run(
            "INSERT INTO $t (user_id, selector, validator_hash, pw, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)",
            [$userId, $selector, hash('sha256', $validator), substr($hash, -12), gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', $expires)]
        );
        Session::cookie(self::REMEMBER_COOKIE, $selector . ':' . $validator, $expires);
        $_COOKIE[self::REMEMBER_COOKIE] = $selector . ':' . $validator;
    }

    /** @return ?array{0:string,1:string} the device's token cookie: [selector, validator] */
    private function rememberCookie(): ?array
    {
        $c = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        return is_string($c) && preg_match('/^([0-9a-f]{24}):([0-9a-f]{64})$/', $c, $m) === 1 ? [$m[1], $m[2]] : null;
    }

    /**
     * Signs the device in again from its token: the user's id, or 0. A token that doesn't match (a
     * wrong validator, expired, or made before the password changed) is removed with its cookie.
     */
    private function fromRemember(): int
    {
        if (!isset($_COOKIE[self::REMEMBER_COOKIE])) {
            return 0;
        }
        $token = $this->rememberCookie();
        $t = $this->db->table('remember_tokens');
        $row = $token === null ? null : ($this->db->run(
            "SELECT r.id, r.user_id, r.validator_hash, r.pw, r.expires_at, u.password_hash FROM $t r JOIN " . $this->db->table('users') . ' u ON u.id = r.user_id WHERE r.selector = ?',
            [$token[0]]
        )->fetch() ?: null);
        $ok = $row !== null
            && $row['expires_at'] >= gmdate('Y-m-d H:i:s')
            && hash_equals($row['validator_hash'], hash('sha256', $token[1]))
            && hash_equals(substr($row['password_hash'], -12), $row['pw']);
        if (!$ok) {
            if ($row !== null) {
                $this->db->run("DELETE FROM $t WHERE id = ?", [$row['id']]);
            }
            $this->forget();
            return 0;
        }
        Session::start();
        Session::regenerate();
        $_SESSION['user_id'] = (int) $row['user_id'];
        $_SESSION['pw'] = $row['pw'];
        return (int) $row['user_id'];
    }

    private function forget(): void
    {
        Session::cookie(self::REMEMBER_COOKIE, '', 1);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    /** Has $user the role $need (dj < manager < admin) or, for a Permissions key, that permission? */
    public static function allows(?array $user, string $need): bool
    {
        if ($user === null || !empty($user['blocked_at'])) {
            return false;
        }
        if (isset(self::ROLES[$need])) {
            return (self::ROLES[$user['role']] ?? 0) >= self::ROLES[$need];
        }
        return isset(Permissions::ALL[$need]) && Permissions::has($user, $need);
    }

    /** Returns null when changed, or what's wrong. Keeps this session signed in. */
    public function changePassword(int $userId, string $current, string $new): ?string
    {
        $row = $this->db->run('SELECT email, password_hash FROM ' . $this->db->table('users') . ' WHERE id = ?', [$userId])->fetch();
        if (!$row || !password_verify($current, $row['password_hash'])) {
            return 'Your current password isn’t right.';
        }
        if (($problem = self::passwordProblem($new, $row['email'])) !== null) {
            return $problem;
        }
        $hash = $this->setPassword($userId, $new);
        if (($_SESSION['user_id'] ?? 0) === $userId) {
            Session::regenerate();
            $_SESSION['pw'] = substr($hash, -12);
        }
        $this->loaded = false;
        return null;
    }

    public function setPassword(int $userId, string $new): string
    {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $this->db->run('UPDATE ' . $this->db->table('users') . ' SET password_hash = ? WHERE id = ?', [$hash, $userId]);
        return $hash;
    }

    public static function passwordProblem(string $password, string $email = ''): ?string
    {
        if (strlen($password) < 10) {
            return 'Use at least 10 characters.';
        }
        if ($email !== '' && strcasecmp(trim($password), trim($email)) === 0) {
            return 'Don’t use your email address as your password.';
        }
        return null;
    }

    // Failed attempts: one small file per key holding the times of recent failures

    private function file(string $key): string
    {
        return $this->throttleDir . '/' . hash('sha256', $key) . '.json';
    }

    private function recent(string $file): array
    {
        $times = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        return array_values(array_filter($times, fn($t) => is_int($t) && $t > time() - self::LOCK_SECONDS));
    }

    public function fails(string $key): int
    {
        return count($this->recent($this->file($key)));
    }

    private function fail(string $key): void
    {
        if (!is_dir($this->throttleDir)) {
            @mkdir($this->throttleDir, 0700, true);
        }
        $file = $this->file($key);
        $times = $this->recent($file);
        $times[] = time();
        @file_put_contents($file, json_encode($times), LOCK_EX);
    }

    private function clear(string $key): void
    {
        @unlink($this->file($key));
    }
}
