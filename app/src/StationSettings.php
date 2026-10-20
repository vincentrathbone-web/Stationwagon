<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * The "Sound & safety" settings a manager may change, with their limits. Values are kept in the
 * station's `settings` JSON over Installer::defaultStationSettings(); keys not listed here (piece
 * length, plan ahead, skin…) are left as they are.
 *
 * A field is a slider (min, max, step, unit) or a choice (choices: value => label). They show in
 * the order listed, grouped by `group`.
 */
final class StationSettings
{
    private const QUALITY = [96 => '96 kbps', 128 => '128 kbps', 160 => '160 kbps', 192 => '192 kbps', 256 => '256 kbps'];

    public const FIELDS = [
        'crossfade_seconds' => ['group' => 'Transitions', 'label' => 'Crossfade between songs', 'help' => 'Both songs overlap for this long.', 'min' => 0, 'max' => 12, 'step' => 1, 'unit' => 's'],
        'fade_shape' => ['group' => 'Transitions', 'label' => 'Fade shape', 'help' => 'How the two songs blend in a crossfade or a skip.',
            'choices' => ['equal_power' => 'Equal power: smooth, no dip in the middle', 'linear' => 'Linear: a straight line, a little dip', 's_curve' => 'S-curve: holds, then blends quickly']],
        'skip_fade_seconds' => ['group' => 'Transitions', 'label' => 'Skip fade', 'help' => 'Used when someone presses Skip.', 'min' => 0, 'max' => 6, 'step' => 0.5, 'unit' => 's'],
        'live_fade_seconds' => ['group' => 'Transitions', 'label' => 'Live cut-in fade', 'help' => 'Music fades out this fast when a DJ takes over.', 'min' => 0, 'max' => 8, 'step' => 0.5, 'unit' => 's'],
        'duck_db' => ['group' => 'Live shows', 'label' => 'Music under the voice', 'help' => 'How far music drops while the presenter speaks. Every mic preset in the studio starts from this.', 'min' => -24, 'max' => 0, 'step' => 1, 'unit' => 'dB'],
        'live_timeout_seconds' => ['group' => 'Live shows', 'label' => 'Back to AutoDJ if a DJ drops', 'help' => 'No live audio for this long switches back to music.', 'min' => 8, 'max' => 30, 'step' => 1, 'unit' => 's'],
        'rejoin_minutes' => ['group' => 'Live shows', 'label' => 'DJ rejoin window', 'help' => 'A presenter who drops or reloads the studio can carry on the same show for this long, with its notes and running order. 0 turns it off.', 'min' => 0, 'max' => 30, 'step' => 1, 'unit' => 'min'],
        'silence_db' => ['group' => 'Songs', 'label' => 'Move on when a song goes quiet', 'help' => 'The next song starts once the music stays below this level to the end, so long quiet endings don’t become dead air. Works for songs prepared on upload from now on.',
            'choices' => [0 => 'Off: play every song to its end', -50 => 'Below −50 dB: only near-silence', -40 => 'Below −40 dB: fade-outs and silence', -30 => 'Below −30 dB: quiet endings too']],
        'recent_tracks' => ['group' => 'Songs', 'label' => 'Songs not to repeat', 'help' => 'Shuffle never picks one of the last this many songs.', 'min' => 0, 'max' => 20, 'step' => 1, 'unit' => 'songs'],
        'live_kbps' => ['group' => 'Sound quality', 'label' => 'Live shows', 'help' => 'The studio sends live shows at this quality.', 'choices' => self::QUALITY],
        'prep_kbps' => ['group' => 'Sound quality', 'label' => 'Prepared songs', 'help' => 'Songs are saved at this quality when they’re prepared on upload. Songs already in the library keep theirs.', 'choices' => self::QUALITY],
    ];

    public function __construct(private readonly Db $db, private readonly array $station)
    {
    }

    /** @return array<string,float|int|string> the current value of every field */
    public function values(): array
    {
        $all = Planner::settings($this->station);
        $out = [];
        foreach (self::FIELDS as $key => $f) {
            $out[$key] = isset($f['choices']) ? self::choice($all[$key], $f['choices']) : self::number($all[$key], $f['step']);
        }
        return $out;
    }

    /** The lowest timeout that can work: one live piece must arrive in time, plus 2 s of slack. */
    public function minLiveTimeout(): int
    {
        return max(self::FIELDS['live_timeout_seconds']['min'], (int) ceil((float) Planner::settings($this->station)['segment_seconds']) + 2);
    }

    /** Megabytes an hour at $kbps, as the Sound quality choices show it. */
    public static function mbPerHour(int $kbps): int
    {
        return (int) round($kbps * 3600 / 8 / 1000);
    }

    /**
     * Check and store the fields in $in (all of them must be there).
     * @return array{0: ?array, 1: array<string,string>} [the updated station row, errors by field]
     */
    public function save(array $in): array
    {
        $errors = [];
        $clean = [];
        foreach (self::FIELDS as $key => $f) {
            $raw = trim((string) ($in[$key] ?? ''));
            if (isset($f['choices'])) {
                $match = array_values(array_filter(array_keys($f['choices']), fn($k) => (string) $k === $raw));
                if (!$match) {
                    $errors[$key] = "{$f['label']}: choose one of the options.";
                    continue;
                }
                $clean[$key] = $match[0];
                continue;
            }
            $min = $key === 'live_timeout_seconds' ? $this->minLiveTimeout() : $f['min'];
            if (!is_numeric($raw)) {
                $errors[$key] = "{$f['label']}: enter a number.";
                continue;
            }
            $v = (float) $raw;
            $steps = ($v - $min) / $f['step'];
            if ($v < $min || $v > $f['max'] || abs($steps - round($steps)) > 1e-6) {
                $errors[$key] = sprintf('%s: choose %s to %s %s%s.', $f['label'], self::show($min), self::show($f['max']), $f['unit'],
                    $f['step'] == 1 ? '' : ' in steps of ' . self::show($f['step']));
                continue;
            }
            $clean[$key] = self::number($v, $f['step']);
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

    /** A stored choice as its option key (ints stay ints), or the first option if it isn't one. */
    private static function choice(mixed $v, array $choices): int|string
    {
        foreach (array_keys($choices) as $k) {
            if ((string) $k === (string) $v) {
                return $k;
            }
        }
        return array_key_first($choices);
    }

    public static function show(float|int $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
    }
}
