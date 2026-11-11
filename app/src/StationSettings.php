<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * The "Sound & safety" settings a manager may change, with their limits. Values are kept in the
 * station's `settings` JSON over Installer::defaultStationSettings(); keys not listed here (piece
 * length, plan ahead…) are left as they are.
 *
 * A field is a slider (min, max, step, unit) or a choice (choices: value => label). They show in
 * the order listed, grouped by `group`.
 */
final class StationSettings
{
    /** The station's loudness target for song prep's normalization (LUFS): its range and default. */
    public const TARGET_MIN = -23.0;
    public const TARGET_MAX = -8.0;
    public const TARGET_DEFAULT = -16.0;
    private const QUALITY = [96 => '96 kbps', 128 => '128 kbps', 160 => '160 kbps', 192 => '192 kbps', 256 => '256 kbps'];
    // prepared songs (round 51): up to 320 kbps, MP3's most
    private const PREP_QUALITY = [96 => '96 kbps', 128 => '128 kbps', 160 => '160 kbps', 192 => '192 kbps', 224 => '224 kbps', 256 => '256 kbps', 320 => '320 kbps'];

    public const FIELDS = [
        'crossfade_seconds' => ['group' => 'Transitions', 'label' => 'Crossfade between songs', 'help' => 'Both songs overlap for this long.', 'min' => 0, 'max' => 12, 'step' => 1, 'unit' => 's'],
        'ads_crossfade_seconds' => ['group' => 'Transitions', 'label' => 'Crossfade for ads', 'help' => 'Ads are short, so a shorter blend works better: this long between a song and an ad, between two ads, and from an ad back to a song.', 'min' => 0, 'max' => 12, 'step' => 0.5, 'unit' => 's'],
        'jingles_crossfade_seconds' => ['group' => 'Transitions', 'label' => 'Crossfade for jingles', 'help' => 'Jingles are tiny, so they blend over a very short crossfade: this long between a song and a jingle, and from a jingle back to a song.', 'min' => 0, 'max' => 6, 'step' => 0.5, 'unit' => 's'],
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
        'ratings_influence' => ['group' => 'Songs', 'label' => 'Let ratings influence how often songs play', 'help' => 'Coming soon: stars are saved in the Music library now, but they do not change the playing order yet. Later, with this on, songs with fewer stars play less often and songs with more stars play more often (a song with no stars counts as 3).',
            'choices' => [0 => 'Off: every song is equally likely', 1 => 'On: more stars play more often']],
        // round 64: how songs and ads are made when they are uploaded (they were switches and a slider on the Music library and the Ads screen)
        'prep_master' => ['group' => 'Loudness', 'label' => 'Pre-processing (mastering)', 'help' => 'EQ and compression with the import preset, which you set on the Song prep screen. It is applied to every song and ad uploaded from now on.',
            'choices' => [1 => 'On: master with the import preset', 0 => 'Off: leave the sound as it is']],
        'prep_level' => ['group' => 'Loudness', 'label' => 'Loudness normalization', 'help' => 'Every song and ad is levelled to the target below when it is uploaded, so they all play at the same volume.',
            'choices' => [1 => 'On: level every upload to the target', 0 => 'Off: keep each upload’s own loudness']],
        'prep_target' => ['group' => 'Loudness', 'label' => 'Loudness target', 'help' => 'The loudness every song and ad is levelled to (LUFS). Louder squeezes more; quieter keeps more dynamics. The usual levels are marked. Songs already in the library keep theirs until you re-level them in the Music library.', 'min' => -23, 'max' => -8, 'step' => 0.5, 'unit' => 'LUFS'],
        'live_kbps' => ['group' => 'Sound quality', 'label' => 'Live shows', 'help' => 'The studio sends live shows at this quality.', 'choices' => self::QUALITY],
        'prep_kbps' => ['group' => 'Sound quality', 'label' => 'Prepared songs', 'help' => 'Songs are saved at this quality when they’re prepared on upload. Songs already in the library keep theirs.', 'choices' => self::PREP_QUALITY],
        // round 51: how songs are prepared. They apply to songs prepared from now on (uploads, Song prep's Apply, re-levelling).
        'prep_rate' => ['group' => 'Sound quality', 'label' => 'Sample rate of prepared songs', 'help' => 'One rate for every song plays smoothly in every browser; a song at another rate is converted once, with no audible loss, and the uploader is told. Keeping each song’s own rate means no conversion for 44.1 and 48 kHz songs (others go to the nearer of the two: 88.2 and 22.05 kHz to 44.1, 96 and 32 kHz to 48), but in Firefox the website player and the stream-only page go quiet for about 5 s where the rate changes.',
            'choices' => [44100 => '44.1 kHz for every song', 48000 => '48 kHz for every song', 0 => 'The song’s own rate (44.1 or 48 kHz)']],
        'prep_precision' => ['group' => 'Sound quality', 'label' => 'Processing precision', 'help' => 'The size of the samples between the steps of song prep (resampling, mastering, levelling). The finished song is always 16-bit.',
            'choices' => [32 => '32-bit float: no rounding until the end', 24 => '24-bit: rounded after each step', 16 => '16-bit: rounded after each step']],
        'prep_dither' => ['group' => 'Sound quality', 'label' => 'Dither', 'help' => 'Whenever samples are rounded (always at the end, to 16-bit), a tiny noise (TPDF) turns the rounding error into a steady hiss far below hearing instead of distortion on quiet passages.',
            'choices' => [1 => 'On: TPDF dither when rounding', 0 => 'Off: plain rounding']],
        'prep_quality' => ['group' => 'Sound quality', 'label' => 'Encoding quality', 'help' => 'How hard LAME 3.100 works on each song: 0 is the fastest, 9 the best and slowest (LAME’s -q 9 to 0). The size of the song stays the same.', 'min' => 0, 'max' => 9, 'step' => 1, 'unit' => ''],
    ];

    public function __construct(private readonly Db $db, private readonly array $station)
    {
    }

    /** @return array<string,float|int|string> the current value of every field */
    public function values(): array
    {
        return self::valuesOf($this->station);
    }

    /** @return array<string,float|int|string> */
    private static function valuesOf(array $station): array
    {
        $all = Planner::settings($station);
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

    /**
     * How songs are prepared (round 51), for prep.js's data attributes: kbps, rate (0 = the song's own),
     * precision (bits), dither (bool) and LAME's -q (0 best .. 9 fastest; the setting runs the other way).
     * @return array{kbps:int,rate:int,precision:int,dither:bool,q:int}
     */
    public static function prepQuality(array $station): array
    {
        $v = self::valuesOf($station);
        return ['kbps' => (int) $v['prep_kbps'], 'rate' => (int) $v['prep_rate'], 'precision' => (int) $v['prep_precision'],
            'dither' => (int) $v['prep_dither'] === 1, 'q' => 9 - (int) $v['prep_quality']];
    }

    /** Song prep's two switches on the Music library page: [master, level]. */
    public static function prep(array $station): array
    {
        $all = Planner::settings($station);
        return [(bool) $all['prep_master'], (bool) $all['prep_level'], self::target($all['prep_target'])];
    }

    /** A loudness target kept in range, in half-dB steps. */
    public static function target(mixed $v): float
    {
        $v = is_numeric($v) && is_finite((float) $v) ? (float) $v : self::TARGET_DEFAULT;
        return round(max(self::TARGET_MIN, min(self::TARGET_MAX, $v)) * 2) / 2;
    }

    /**
     * Store song prep's two switches and the station's loudness target (LUFS; null keeps it).
     * @return array the updated station row
     */
    public function savePrep(bool $master, bool $level, ?float $target = null): array
    {
        $own = json_decode((string) ($this->station['settings'] ?? ''), true);
        $own = is_array($own) ? $own : [];
        $own['prep_master'] = $master ? 1 : 0;
        $own['prep_level'] = $level ? 1 : 0;
        if ($target !== null) {
            $own['prep_target'] = self::target($target);
        }
        $json = json_encode($own, JSON_UNESCAPED_SLASHES);
        $this->db->run('UPDATE ' . $this->db->table('stations') . ' SET settings = ? WHERE id = ?', [$json, $this->station['id']]);
        return ['settings' => $json] + $this->station;
    }

    /** The email address listeners can write to about their data (the Privacy page), or ''. */
    public static function privacyEmail(array $station): string
    {
        $own = json_decode((string) ($station['settings'] ?? ''), true);
        return is_array($own) && is_string($own['privacy_email'] ?? null) ? $own['privacy_email'] : '';
    }

    /**
     * Store the privacy contact ('' clears it). @return array{0: ?array, 1: ?string} [the updated
     * station row, or null with what's wrong]
     */
    public function savePrivacyEmail(string $email): array
    {
        $email = trim($email);
        if ($email !== '' && (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            return [null, 'That doesn’t look like an email address.'];
        }
        $own = json_decode((string) ($this->station['settings'] ?? ''), true);
        $own = is_array($own) ? $own : [];
        $own['privacy_email'] = $email;
        $json = json_encode($own, JSON_UNESCAPED_SLASHES);
        $this->db->run('UPDATE ' . $this->db->table('stations') . ' SET settings = ? WHERE id = ?', [$json, $this->station['id']]);
        return [['settings' => $json] + $this->station, null];
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
