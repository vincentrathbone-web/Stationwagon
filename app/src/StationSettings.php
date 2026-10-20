<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * The "Sound & safety" settings a manager may change, with their limits. Values are kept in the
 * station's `settings` JSON over Installer::defaultStationSettings(); keys not listed here (piece
 * length, plan ahead, skin…) are left as they are.
 */
final class StationSettings
{
    /** key => [group, label, help, min, max, step, unit] */
    public const FIELDS = [
        'crossfade_seconds' => ['Transitions', 'Crossfade between songs', 'Both songs overlap for this long.', 0, 12, 1, 's'],
        'skip_fade_seconds' => ['Transitions', 'Skip fade', 'Used when someone presses Skip.', 0, 6, 0.5, 's'],
        'live_fade_seconds' => ['Transitions', 'Live cut-in fade', 'Music fades out this fast when a DJ takes over.', 0, 8, 0.5, 's'],
        'live_timeout_seconds' => ['Safety nets', 'Back to AutoDJ if a DJ drops', 'No live audio for this long switches back to music.', 8, 30, 1, 's'],
        'recent_tracks' => ['Safety nets', 'Songs not to repeat', 'Shuffle never picks one of the last this many songs.', 0, 20, 1, 'songs'],
    ];

    public function __construct(private readonly Db $db, private readonly array $station)
    {
    }

    /** @return array<string,float|int> the current value of every field */
    public function values(): array
    {
        $all = Planner::settings($this->station);
        $out = [];
        foreach (self::FIELDS as $key => $f) {
            $out[$key] = self::number($all[$key], $f[5]);
        }
        return $out;
    }

    /** The lowest timeout that can work: one live piece must arrive in time, plus 2 s of slack. */
    public function minLiveTimeout(): int
    {
        return max(self::FIELDS['live_timeout_seconds'][3], (int) ceil((float) Planner::settings($this->station)['segment_seconds']) + 2);
    }

    /**
     * Check and store the fields in $in (all of them must be there).
     * @return array{0: ?array, 1: array<string,string>} [the updated station row, errors by field]
     */
    public function save(array $in): array
    {
        $errors = [];
        $clean = [];
        foreach (self::FIELDS as $key => [, $label, , $min, $max, $step, $unit]) {
            if ($key === 'live_timeout_seconds') {
                $min = $this->minLiveTimeout();
            }
            $raw = trim((string) ($in[$key] ?? ''));
            if (!is_numeric($raw)) {
                $errors[$key] = "$label: enter a number.";
                continue;
            }
            $v = (float) $raw;
            $steps = ($v - $min) / $step;
            if ($v < $min || $v > $max || abs($steps - round($steps)) > 1e-6) {
                $errors[$key] = sprintf('%s: choose %s to %s %s%s.', $label, self::show($min), self::show($max), $unit,
                    $step == 1 ? '' : ' in steps of ' . self::show($step));
                continue;
            }
            $clean[$key] = self::number($v, $step);
        }
        if ($errors) {
            return [null, $errors];
        }
        $own = json_decode((string) ($this->station['settings'] ?? ''), true);
        $own = array_replace(is_array($own) ? $own : [], $clean);
        $json = json_encode($own, JSON_UNESCAPED_SLASHES);
        $this->db->run('UPDATE ' . $this->db->table('stations') . ' SET settings = ? WHERE id = ?', [$json, $this->station['id']]);
        return [['settings' => $json] + $this->station, []];
    }

    /** Whole-step fields are ints, half steps floats (JSON keeps them that way). */
    private static function number(float|int|string $v, float|int $step): float|int
    {
        return $step == 1 ? (int) round((float) $v) : round((float) $v, 2);
    }

    public static function show(float|int $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
    }
}
