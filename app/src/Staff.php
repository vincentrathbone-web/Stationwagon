<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * The people who work on a station. Managers add and look after DJs and other managers; only
 * an admin can make or change an admin. Nobody can remove themselves or the last admin.
 * Passwords are typed by whoever sets them and never shown again.
 */
final class Staff
{
    public const ROLE_NAMES = ['dj' => 'DJ', 'manager' => 'Manager', 'admin' => 'Admin'];

    public function __construct(private readonly Db $db, private readonly array $station)
    {
    }

    public function all(): array
    {
        $us = $this->db->table('users');
        $su = $this->db->table('station_users');
        $ls = $this->db->table('live_shows');
        return $this->db->run(
            "SELECT u.id, u.email, u.name, u.role, u.created_at, u.last_login_at,
                    (SELECT MAX(l.start_at) FROM $ls l WHERE l.dj_user_id = u.id) AS last_live
             FROM $us u JOIN $su su ON su.user_id = u.id AND su.station_id = ?
             ORDER BY FIELD(u.role, 'admin', 'manager', 'dj'), u.name",
            [$this->station['id']]
        )->fetchAll();
    }

    public function get(int $id): ?array
    {
        $us = $this->db->table('users');
        $su = $this->db->table('station_users');
        $row = $this->db->run("SELECT u.id, u.email, u.name, u.role FROM $us u JOIN $su su ON su.user_id = u.id AND su.station_id = ? WHERE u.id = ?", [$this->station['id'], $id])->fetch();
        return $row ?: null;
    }

    /** Roles $actor may hand out. */
    public static function grantable(array $actor): array
    {
        return $actor['role'] === 'admin' ? ['dj', 'manager', 'admin'] : (Auth::allows($actor, 'manager') ? ['dj', 'manager'] : []);
    }

    /** May $actor change or remove $target? */
    public static function canManage(array $actor, array $target): bool
    {
        return (int) $actor['id'] !== (int) $target['id'] && in_array($target['role'], self::grantable($actor), true);
    }

    /** @return array{0:?int,1:array<string,string>} [new id, field => problem] */
    public function add(array $actor, string $name, string $email, string $role, string $password): array
    {
        $e = [];
        $email = strtolower(trim($email));
        if (trim($name) === '') {
            $e['name'] = 'Their name is needed.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $e['email'] = 'That email address doesn’t look right.';
        } elseif ((int) $this->db->run('SELECT COUNT(*) FROM ' . $this->db->table('users') . ' WHERE email = ?', [$email])->fetchColumn()) {
            $e['email'] = 'Someone already signs in with that email.';
        }
        if (!in_array($role, self::grantable($actor), true)) {
            $e['role'] = 'You can’t give that role.';
        }
        if (($p = Auth::passwordProblem($password, $email)) !== null) {
            $e['password'] = $p;
        }
        if ($e) {
            return [null, $e];
        }
        $id = $this->db->insert('users', ['email' => $email, 'name' => mb_substr(trim($name), 0, 190), 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $this->db->insert('station_users', ['station_id' => $this->station['id'], 'user_id' => $id]);
        return [$id, []];
    }

    public function setRole(array $actor, int $id, string $role): ?string
    {
        $target = $this->get($id);
        if ($target === null || !self::canManage($actor, $target)) {
            return 'You can’t change that person’s role.';
        }
        if (!in_array($role, self::grantable($actor), true)) {
            return 'You can’t give that role.';
        }
        if ($target['role'] === 'admin' && $role !== 'admin' && $this->admins() <= 1) {
            return 'The station needs at least one admin.';
        }
        $this->db->run('UPDATE ' . $this->db->table('users') . ' SET role = ? WHERE id = ?', [$role, $id]);
        return null;
    }

    /** A new password for someone who forgot theirs; their other sessions are signed out. */
    public function resetPassword(array $actor, int $id, string $password, Auth $auth): ?string
    {
        $target = $this->get($id);
        if ($target === null || !self::canManage($actor, $target)) {
            return 'You can’t set that person’s password. (Change your own on Your account.)';
        }
        if (($p = Auth::passwordProblem($password, $target['email'])) !== null) {
            return $p;
        }
        $auth->setPassword($id, $password);
        return null;
    }

    public function remove(array $actor, int $id): ?string
    {
        $target = $this->get($id);
        if ($target === null || !self::canManage($actor, $target)) {
            return 'You can’t remove that person.';
        }
        if ($target['role'] === 'admin' && $this->admins() <= 1) {
            return 'The station needs at least one admin.';
        }
        // Their past shows and notes stay (dj_user_id / user_id become empty)
        $this->db->run('DELETE FROM ' . $this->db->table('users') . ' WHERE id = ?', [$id]);
        return null;
    }

    private function admins(): int
    {
        $us = $this->db->table('users');
        $su = $this->db->table('station_users');
        return (int) $this->db->run("SELECT COUNT(*) FROM $us u JOIN $su su ON su.user_id = u.id AND su.station_id = ? WHERE u.role = 'admin'", [$this->station['id']])->fetchColumn();
    }
}
