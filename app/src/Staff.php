<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * The people who work on a station. Each gets exactly what their role allows (Roles). Someone
 * with the "people" permission adds and looks after people whose role can do no more than they
 * can, and hands out only such roles; only an admin makes or changes an admin. Nobody can change,
 * block or remove themselves, and the station always keeps one admin who isn't blocked. Blocking
 * signs them out at once (Auth checks on every request), ends their "Keep me signed in" devices
 * and ends any live show they are on. Passwords are typed by whoever sets them and never shown again.
 *
 * Used by the station's People screen and by the WordPress plugin's People page, which acts as an
 * admin of the station (a WordPress administrator already controls the station's files).
 */
final class Staff
{
    private readonly Roles $roles;

    public function __construct(private readonly Db $db, private readonly array $station)
    {
        $this->roles = new Roles($db, $station);
    }

    public function all(): array
    {
        $us = $this->db->table('users');
        $su = $this->db->table('station_users');
        $ls = $this->db->table('live_shows');
        return $this->db->run(
            "SELECT u.id, u.email, u.name, u.role, u.created_at, u.last_login_at, u.blocked_at, " . Auth::roleColumns($this->db) . ",
                    (SELECT MAX(l.start_at) FROM $ls l WHERE l.dj_user_id = u.id) AS last_live
             FROM $us u JOIN $su su ON su.user_id = u.id AND su.station_id = ?
             ORDER BY u.blocked_at IS NOT NULL, FIELD(u.role, 'dj', 'manager', 'admin') DESC, u.name",
            [$this->station['id']]
        )->fetchAll();
    }

    public function get(int $id): ?array
    {
        $us = $this->db->table('users');
        $su = $this->db->table('station_users');
        $row = $this->db->run("SELECT u.id, u.email, u.name, u.role, u.blocked_at, " . Auth::roleColumns($this->db) . " FROM $us u JOIN $su su ON su.user_id = u.id AND su.station_id = ? WHERE u.id = ?", [$this->station['id'], $id])->fetch();
        return $row ?: null;
    }

    /** Keys of the roles $actor may hand out: any for an admin; with "people", those that can do no more than they can. */
    public function grantable(array $actor): array
    {
        if (!Auth::allows($actor, 'people')) {
            return [];
        }
        $mine = Permissions::of($actor);
        $out = [];
        foreach ($this->roles->all() as $r) {
            if (($actor['role'] ?? '') === 'admin' || ($r['key'] !== 'admin' && !array_diff($r['permissions'], $mine))) {
                $out[] = $r['key'];
            }
        }
        return $out;
    }

    /** May $actor change, block or remove $target? Not themselves; only someone whose role they could give. */
    public function canManage(array $actor, array $target): bool
    {
        return (int) $actor['id'] !== (int) $target['id'] && in_array($target['role'], $this->grantable($actor), true);
    }

    /** @return array{0:?int,1:array<string,string>} [new id, field => problem] */
    public function add(array $actor, string $name, string $email, string $role, string $password): array
    {
        $e = $this->checkDetails($name, $email, null);
        if (!in_array($role, $this->grantable($actor), true)) {
            $e['role'] = 'You can’t give that role.';
        }
        if (($p = Auth::passwordProblem($password, strtolower(trim($email)))) !== null) {
            $e['password'] = $p;
        }
        if ($e) {
            return [null, $e];
        }
        $id = $this->db->insert('users', ['email' => strtolower(trim($email)), 'name' => mb_substr(trim($name), 0, 190), 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $this->db->insert('station_users', ['station_id' => $this->station['id'], 'user_id' => $id]);
        $this->db->insert('user_streams', ['user_id' => $id, 'station_id' => $this->station['id']]); // the main stream, as before: more are given on the People screen
        return [$id, []];
    }

    /** A new name and sign-in email. @return array<string,string> field => problem */
    public function edit(array $actor, int $id, string $name, string $email): array
    {
        $target = $this->get($id);
        if ($target === null || !$this->canManage($actor, $target)) {
            return ['name' => 'You can’t change that person.'];
        }
        $e = $this->checkDetails($name, $email, $id);
        if (!$e) {
            $this->db->run('UPDATE ' . $this->db->table('users') . ' SET name = ?, email = ? WHERE id = ?', [mb_substr(trim($name), 0, 190), strtolower(trim($email)), $id]);
        }
        return $e;
    }

    /** The streams this person may go live on (ids): a DJ's own list; admins and managers have every stream. @return list<int> */
    public function streamsOf(array $person): array
    {
        return Streams::allowedIds($this->db, $person);
    }

    /** Give a person exactly these streams to go live on (round 73). Returns what's wrong, or null. */
    public function setStreams(array $actor, int $id, array $ids): ?string
    {
        $target = $this->get($id);
        if ($target === null || !$this->canManage($actor, $target)) {
            return 'You can’t change that person.';
        }
        if (Streams::usesAll($target)) {
            return 'Admins and managers may use every stream.';
        }
        if (!array_filter(array_map('intval', $ids), fn(int $i) => Streams::byId($this->db, $i) !== null)) {
            return 'Choose at least one stream. To stop someone going live, block them or change their role.';
        }
        Streams::setFor($this->db, $id, $ids);
        return null;
    }

    /** A new role: from now on they may do what it allows. */
    public function setRole(array $actor, int $id, string $role): ?string
    {
        $target = $this->get($id);
        if ($target === null || !$this->canManage($actor, $target)) {
            return 'You can’t change that person’s role.';
        }
        if (!in_array($role, $this->grantable($actor), true)) {
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
        if ($target === null || !$this->canManage($actor, $target)) {
            return 'You can’t set that person’s password. (Change your own on Your account.)';
        }
        if (($p = Auth::passwordProblem($password, $target['email'])) !== null) {
            return $p;
        }
        $auth->setPassword($id, $password);
        return null;
    }

    /**
     * Block: they can't sign in, are signed out on their next request, lose their "Keep me signed in"
     * devices, and a live show they are on ends now (the AutoDJ takes over). @return ?string a problem
     */
    public function block(array $actor, int $id, ?Planner $planner = null, ?float $now = null): ?string
    {
        $target = $this->get($id);
        if ($target === null || !$this->canManage($actor, $target)) {
            return 'You can’t block that person.';
        }
        if ($target['role'] === 'admin' && $this->admins() <= 1) {
            return 'The station needs at least one admin who can sign in.';
        }
        $this->db->run('UPDATE ' . $this->db->table('users') . ' SET blocked_at = COALESCE(blocked_at, ?) WHERE id = ?', [gmdate('Y-m-d H:i:s'), $id]);
        $this->db->run('DELETE FROM ' . $this->db->table('remember_tokens') . ' WHERE user_id = ?', [$id]);
        if ($planner !== null) {
            $open = $this->db->run('SELECT public_id FROM ' . $this->db->table('live_shows') . ' WHERE station_id = ? AND dj_user_id = ? AND open = 1', [$this->station['id'], $id])->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($open as $publicId) {
                $planner->endLive($now ?? microtime(true), (string) $publicId, 'DJ blocked');
            }
        }
        return null;
    }

    public function unblock(array $actor, int $id): ?string
    {
        $target = $this->get($id);
        if ($target === null || !$this->canManage($actor, $target)) {
            return 'You can’t unblock that person.';
        }
        $this->db->run('UPDATE ' . $this->db->table('users') . ' SET blocked_at = NULL WHERE id = ?', [$id]);
        return null;
    }

    public function remove(array $actor, int $id): ?string
    {
        $target = $this->get($id);
        if ($target === null || !$this->canManage($actor, $target)) {
            return 'You can’t remove that person.';
        }
        if ($target['role'] === 'admin' && $this->admins() <= 1) {
            return 'The station needs at least one admin.';
        }
        // Their past shows and notes stay (dj_user_id / user_id become empty)
        $this->db->run('DELETE FROM ' . $this->db->table('users') . ' WHERE id = ?', [$id]);
        return null;
    }

    /** @return array<string,string> */
    private function checkDetails(string $name, string $email, ?int $self): array
    {
        $e = [];
        $email = strtolower(trim($email));
        if (trim($name) === '') {
            $e['name'] = 'Their name is needed.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $e['email'] = 'That email address doesn’t look right.';
        } elseif ((int) $this->db->run('SELECT COUNT(*) FROM ' . $this->db->table('users') . ' WHERE email = ? AND id <> ?', [$email, $self ?? 0])->fetchColumn()) {
            $e['email'] = 'Someone already signs in with that email.';
        }
        return $e;
    }

    /** Admins who can sign in. */
    private function admins(): int
    {
        $us = $this->db->table('users');
        $su = $this->db->table('station_users');
        return (int) $this->db->run("SELECT COUNT(*) FROM $us u JOIN $su su ON su.user_id = u.id AND su.station_id = ? WHERE u.role = 'admin' AND u.blocked_at IS NULL", [$this->station['id']])->fetchColumn();
    }
}
