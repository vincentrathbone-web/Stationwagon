<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * The station's roles and what each may do (Permissions). Admin, Manager and DJ are built in:
 * Admin always has everything and can't be changed; Manager and DJ can be renamed and ticked but
 * not deleted. The station adds its own (a guest presenter, a senior DJ…). People get exactly what
 * their role allows (users.role holds the role's key). Only admins change roles.
 */
final class Roles
{
    public const BUILT_IN = ['admin' => 'Admin', 'manager' => 'Manager', 'dj' => 'DJ'];

    public function __construct(private readonly Db $db, private readonly array $station)
    {
    }

    /** The built-in roles, made the first time they are needed (a new station, or one from before roles). */
    public static function ensureDefaults(Db $db, int $stationId): void
    {
        foreach (self::BUILT_IN as $key => $name) {
            $db->run('INSERT IGNORE INTO ' . $db->table('roles') . ' (station_id, `key`, name, permissions, built_in, created_at) VALUES (?, ?, ?, ?, 1, ?)',
                [$stationId, $key, $name, json_encode(Permissions::defaults($key)), gmdate('Y-m-d H:i:s')]);
        }
    }

    /** @return list<array{key:string,name:string,permissions:list<string>,built_in:bool,people:int}> admin, manager, the station's own, DJ */
    public function all(): array
    {
        self::ensureDefaults($this->db, (int) $this->station['id']);
        $rows = $this->db->run(
            'SELECT r.`key`, r.name, r.permissions, r.built_in, (SELECT COUNT(*) FROM ' . $this->db->table('users') . ' u JOIN ' . $this->db->table('station_users') . ' su ON su.user_id = u.id AND su.station_id = r.station_id WHERE u.role = r.`key`) AS people
             FROM ' . $this->db->table('roles') . " r WHERE r.station_id = ? ORDER BY FIELD(r.`key`, 'dj', 'manager', 'admin') DESC, r.name",
            [$this->station['id']]
        )->fetchAll();
        $out = array_map(fn($r) => ['key' => $r['key'], 'name' => $r['name'], 'built_in' => (bool) $r['built_in'], 'people' => (int) $r['people'],
            'permissions' => $r['key'] === 'admin' ? array_keys(Permissions::ALL) : Permissions::clean(json_decode((string) $r['permissions'], true) ?: [])], $rows);
        // DJ last: the everyday role, after the station's own
        usort($out, fn($a, $b) => ($a['key'] === 'dj') <=> ($b['key'] === 'dj'));
        return $out;
    }

    public function get(string $key): ?array
    {
        foreach ($this->all() as $r) {
            if ($r['key'] === $key) {
                return $r;
            }
        }
        return null;
    }

    /** @return array<string,string> key => name */
    public function names(): array
    {
        return array_column($this->all(), 'name', 'key');
    }

    /** @return array{0:?string,1:?string} [new key, problem] */
    public function add(array $actor, string $name, array $permissions): array
    {
        if (($actor['role'] ?? '') !== 'admin') {
            return [null, 'Only an admin can add roles.'];
        }
        if (($p = $this->nameProblem($name, null)) !== null) {
            return [null, $p];
        }
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'role';
        $base = substr($base, 0, 30);
        $key = $base;
        for ($i = 2; isset(self::BUILT_IN[$key]) || $this->exists($key); $i++) {
            $key = "$base-$i";
        }
        $this->db->insert('roles', ['station_id' => $this->station['id'], 'key' => $key, 'name' => trim($name),
            'permissions' => json_encode(Permissions::clean($permissions)), 'built_in' => 0, 'created_at' => gmdate('Y-m-d H:i:s')]);
        return [$key, null];
    }

    /** A new name and ticks; applies to everyone with the role at once. */
    public function update(array $actor, string $key, string $name, array $permissions): ?string
    {
        if (($actor['role'] ?? '') !== 'admin') {
            return 'Only an admin can change roles.';
        }
        if ($key === 'admin') {
            return 'The Admin role always has everything.';
        }
        if (!$this->exists($key)) {
            return 'That role doesn’t exist.';
        }
        if (($p = $this->nameProblem($name, $key)) !== null) {
            return $p;
        }
        $this->db->run('UPDATE ' . $this->db->table('roles') . ' SET name = ?, permissions = ? WHERE station_id = ? AND `key` = ?',
            [trim($name), json_encode(Permissions::clean($permissions)), $this->station['id'], $key]);
        return null;
    }

    public function delete(array $actor, string $key): ?string
    {
        if (($actor['role'] ?? '') !== 'admin') {
            return 'Only an admin can delete roles.';
        }
        $role = $this->get($key);
        if ($role === null) {
            return 'That role doesn’t exist.';
        }
        if ($role['built_in']) {
            return 'Admin, Manager and DJ can’t be deleted.';
        }
        if ($role['people'] > 0) {
            return 'Give its ' . $role['people'] . ($role['people'] === 1 ? ' person' : ' people') . ' another role first.';
        }
        $this->db->run('DELETE FROM ' . $this->db->table('roles') . ' WHERE station_id = ? AND `key` = ?', [$this->station['id'], $key]);
        return null;
    }

    private function exists(string $key): bool
    {
        return (bool) $this->db->run('SELECT COUNT(*) FROM ' . $this->db->table('roles') . ' WHERE station_id = ? AND `key` = ?', [$this->station['id'], $key])->fetchColumn();
    }

    private function nameProblem(string $name, ?string $self): ?string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) {
            return 'Give the role a name (up to 60 characters).';
        }
        foreach ($this->all() as $r) {
            if ($r['key'] !== $self && mb_strtolower($r['name']) === mb_strtolower($name)) {
                return 'There is already a role called “' . $r['name'] . '”.';
            }
        }
        return null;
    }

    /**
     * Schema 19: a new tick for the built-in role $key at every station (a custom role keeps what its admin chose).
     */
    public static function addTick(Db $db, string $key, string $tick): void
    {
        $t = $db->table('roles');
        foreach ($db->run("SELECT id, permissions FROM $t WHERE `key` = ? AND built_in = 1", [$key])->fetchAll() as $r) {
            $have = json_decode((string) $r['permissions'], true) ?: [];
            if (!in_array($tick, $have, true)) {
                $have[] = $tick;
                $db->run("UPDATE $t SET permissions = ? WHERE id = ?", [json_encode(Permissions::clean($have)), $r['id']]);
            }
        }
    }

    /**
     * Schema 13: everyone who had their own ticks (schema 12) moves to a role with exactly those
     * ticks: a role that already has them, or a new "Custom role" made for them.
     */
    public static function migrateTicks(Db $db): void
    {
        $stations = $db->run('SELECT id FROM ' . $db->table('stations'))->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($stations as $sid) {
            self::ensureDefaults($db, (int) $sid);
        }
        $cols = $db->run('SHOW COLUMNS FROM ' . $db->table('users') . " LIKE 'permissions'")->fetchAll();
        if (!$cols || !$stations) {
            return;
        }
        $sid = (int) $stations[0];
        $users = $db->run('SELECT id, role, permissions FROM ' . $db->table('users') . " WHERE permissions IS NOT NULL AND role <> 'admin'")->fetchAll();
        $n = 0;
        foreach ($users as $u) {
            $old = json_decode((string) $u['permissions'], true) ?: [];
            if (in_array('onair', $old, true)) {
                array_push($old, 'anytime', 'desk');
            }
            $want = json_encode(Permissions::clean($old));
            $key = null;
            foreach ($db->run('SELECT `key`, permissions FROM ' . $db->table('roles') . " WHERE station_id = ? AND `key` <> 'admin'", [$sid])->fetchAll() as $r) {
                if (json_encode(Permissions::clean(json_decode((string) $r['permissions'], true) ?: [])) === $want) {
                    $key = $r['key'];
                    break;
                }
            }
            if ($key === null) {
                do {
                    $n++;
                    $key = "custom-$n";
                } while ((int) $db->run('SELECT COUNT(*) FROM ' . $db->table('roles') . ' WHERE station_id = ? AND `key` = ?', [$sid, $key])->fetchColumn());
                $db->insert('roles', ['station_id' => $sid, 'key' => $key, 'name' => "Custom role $n", 'permissions' => $want, 'built_in' => 0, 'created_at' => gmdate('Y-m-d H:i:s')]);
            }
            $db->run('UPDATE ' . $db->table('users') . ' SET role = ? WHERE id = ?', [$key, $u['id']]);
        }
    }
}
