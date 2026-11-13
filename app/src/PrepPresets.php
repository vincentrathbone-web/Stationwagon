<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * Song prep's mastering presets (the Song prep screen, round 40): named settings for the chain in
 * assets/master-core.js, kept as JSON. The chain keeps every value in range itself (SwMaster.preset);
 * here a preset is only checked for its shape: known keys, numbers, true/false and short lists.
 * The station's default for imports is its `prep_preset` setting: a preset's id, or 0 for the
 * built-in Audition chain (master-core.js's P; WordWave's tuning since round 86), which can't be changed or removed.
 */
final class PrepPresets
{
    public const MAX = 20;
    public const MAX_BYTES = 8000;
    public const BUILT_IN = 'Audition chain';
    /** What a preset may hold (master-core.js), and the simple view's four controls. */
    private const KEYS = ['cross', 'threshold', 'ratio', 'knee', 'gain', 'attackMs', 'releaseMs', 'mbThresh', 'mbMargin',
        'shelfDb', 'exciteDb', 'sideDb', 'maxGain', 'ceiling', 'eqOn', 'compOn', 'finishOn', 'eq', 'simple'];

    public function __construct(private readonly Db $db, private readonly array $station)
    {
    }

    /** @return list<array{id:int,name:string,settings:array,updated_at:string}> by name */
    public function all(): array
    {
        $rows = $this->db->run('SELECT id, name, settings, updated_at FROM ' . $this->db->table('prep_presets') . ' WHERE station_id = ? ORDER BY name',
            [$this->station['id']])->fetchAll();
        return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'settings' => json_decode($r['settings'], true) ?: [], 'updated_at' => $r['updated_at']], $rows);
    }

    public function get(int $id): ?array
    {
        foreach ($this->all() as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Save a preset: a new one ($id 0) or a change to one. @return array{0:?int,1:?string} [id, problem]
     */
    public function save(int $id, string $name, mixed $settings, float $now): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 60) {
            return [null, 'Give the preset a name of up to 60 characters.'];
        }
        if (mb_strtolower($name) === mb_strtolower(self::BUILT_IN)) {
            return [null, 'That name is the built-in preset’s. Pick another.'];
        }
        $problem = self::check($settings);
        if ($problem !== null) {
            return [null, $problem];
        }
        $json = json_encode($settings, JSON_UNESCAPED_SLASHES);
        $t = $this->db->table('prep_presets');
        $same = $this->db->run("SELECT id FROM $t WHERE station_id = ? AND name = ?", [$this->station['id'], $name])->fetchColumn();
        if ($same !== false && (int) $same !== $id) {
            return [null, 'There is already a preset called “' . $name . '”.'];
        }
        $at = gmdate('Y-m-d H:i:s', (int) $now);
        if ($id > 0) {
            if ($this->get($id) === null) {
                return [null, 'That preset isn’t there any more.'];
            }
            $this->db->run("UPDATE $t SET name = ?, settings = ?, updated_at = ? WHERE id = ? AND station_id = ?", [$name, $json, $at, $id, $this->station['id']]);
            return [$id, null];
        }
        if (count($this->all()) >= self::MAX) {
            return [null, 'That’s ' . self::MAX . ' presets already. Remove one first.'];
        }
        $new = $this->db->insert('prep_presets', ['station_id' => $this->station['id'], 'name' => $name, 'settings' => $json, 'created_at' => $at, 'updated_at' => $at]);
        return [$new, null];
    }

    /** Remove a preset; the import default goes back to the built-in one if it was this. */
    public function delete(int $id): ?string
    {
        if ($this->get($id) === null) {
            return 'That preset isn’t there any more.';
        }
        $this->db->run('DELETE FROM ' . $this->db->table('prep_presets') . ' WHERE id = ? AND station_id = ?', [$id, $this->station['id']]);
        if (self::defaultId($this->station) === $id) {
            $this->setDefault(0);
        }
        return null;
    }

    /** The import default: a preset's id, or 0 for the built-in Audition chain. */
    public static function defaultId(array $station): int
    {
        $own = json_decode((string) ($station['settings'] ?? ''), true);
        return is_array($own) && isset($own['prep_preset']) ? max(0, (int) $own['prep_preset']) : 0;
    }

    /** @return array the station row with the new default */
    public function setDefault(int $id): array
    {
        $id = $id > 0 && $this->get($id) !== null ? $id : 0;
        $own = json_decode((string) ($this->station['settings'] ?? ''), true);
        $own = is_array($own) ? $own : [];
        $own['prep_preset'] = $id;
        $json = json_encode($own, JSON_UNESCAPED_SLASHES);
        $this->db->run('UPDATE ' . $this->db->table('stations') . ' SET settings = ? WHERE id = ?', [$json, $this->station['id']]);
        return ['settings' => $json] + $this->station;
    }

    /** What song prep uses on import: [name, settings] ({} is the built-in chain). */
    public function forImport(): array
    {
        $p = ($id = self::defaultId($this->station)) > 0 ? $this->get($id) : null;
        return $p ? [$p['name'], $p['settings']] : [self::BUILT_IN, (object) []];
    }

    /** null when $settings is a preset's shape, or what's wrong. */
    public static function check(mixed $settings): ?string
    {
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            return 'A preset is a set of named settings.';
        }
        if (strlen((string) json_encode($settings)) > self::MAX_BYTES) {
            return 'That preset is too big.';
        }
        foreach ($settings as $k => $v) {
            if (!in_array($k, self::KEYS, true)) {
                return "“{$k}” isn’t a setting song prep knows.";
            }
            if (!self::plain($v, 0)) {
                return "“{$k}” has a value song prep can’t use.";
            }
        }
        return null;
    }

    /** Numbers, true/false, and lists or sets of them, at most three levels deep and 10 wide. */
    private static function plain(mixed $v, int $depth): bool
    {
        if (is_int($v) || is_float($v) || is_bool($v)) {
            return !is_float($v) || is_finite($v);
        }
        if (is_array($v) && $depth < 3 && count($v) <= 10) {
            foreach ($v as $k => $x) {
                if ((is_string($k) && !preg_match('/^[a-zA-Z]{1,12}$/', $k)) || !self::plain($x, $depth + 1)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }
}
