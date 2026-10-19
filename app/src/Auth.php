<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * Sign-in for station staff. Roles rank dj < manager < admin; a page asks for the lowest role
 * it needs. Failed attempts are counted per email and per address in storage/cache/throttle, so
 * guessing passwords gets locked out for a while.
 */
final class Auth
{
    public const ROLES = ['dj' => 1, 'manager' => 2, 'admin' => 3];
    public const MAX_FAILS_PER_EMAIL = 5;
    public const MAX_FAILS_PER_ADDRESS = 20;
    public const LOCK_SECONDS = 900;

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
    public function attempt(string $email, string $password, string $address): array
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
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            $this->setPassword((int) $row['id'], $password);
        }
        $this->db->run('UPDATE ' . $this->db->table('users') . ' SET last_login_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $row['id']]);
        Session::start();
        Session::regenerate();
        $_SESSION['user_id'] = (int) $row['id'];
        $_SESSION['pw'] = substr($row['password_hash'], -12); // a password change signs out other sessions
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
        if ($id > 0) {
            $row = $this->db->run('SELECT id, email, name, role, password_hash, last_login_at FROM ' . $this->db->table('users') . ' WHERE id = ?', [$id])->fetch() ?: null;
            if ($row !== null && hash_equals(substr($row['password_hash'], -12), (string) ($_SESSION['pw'] ?? ''))) {
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
        unset($_SESSION['user_id'], $_SESSION['pw']);
        Session::regenerate();
        $this->loaded = false;
    }

    public static function allows(?array $user, string $role): bool
    {
        return $user !== null && (self::ROLES[$user['role']] ?? 0) >= (self::ROLES[$role] ?? PHP_INT_MAX);
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
