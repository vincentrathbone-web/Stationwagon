<?php
declare(strict_types=1);

namespace Stationwagon;

use RuntimeException;
use Throwable;

/**
 * The background jobs: plan each station ahead, give ended live shows their podcast episodes,
 * delete published episodes' raw tracks after 30 days, and fetch the new location database once a
 * month. A cron job (cli/plan.php) runs them every 5 minutes where the station has one; with or
 * without it they also run after ordinary visits (listeners, pages), at most once every 5 minutes,
 * after the answer has gone out where the server allows that. So a station needs no cron job.
 * The state (when they last ran, and by what) is kept in storage/cache/jobs.json.
 */
final class Jobs
{
    public const EVERY = 300;       // seconds between runs
    public const CRON_STALE = 900;  // a cron job not seen for this long has stopped

    public static function statePath(): string
    {
        return SW_ROOT . '/storage/cache/jobs.json';
    }

    /** @return array{last_run?:float,last_by?:string,last_cron?:float} */
    public static function state(): array
    {
        $s = json_decode((string) @file_get_contents(self::statePath()), true);
        return is_array($s) ? $s : [];
    }

    private static function save(array $values): void
    {
        $dir = dirname(self::statePath());
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = self::statePath() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode($values + self::state())) !== false) {
            @rename($tmp, self::statePath());
        }
    }

    /** Whether the server can send the answer before the jobs run (LiteSpeed, PHP-FPM). */
    public static function canFinishEarly(): bool
    {
        return function_exists('litespeed_finish_request') || function_exists('fastcgi_finish_request');
    }

    /** The location database is a long download: from cron, or from a visit that has already answered. */
    public static function mayFetchGeo(string $by): bool
    {
        return $by === 'cron' || self::canFinishEarly();
    }

    /**
     * Run every job now. $by is 'cron' or 'visit'. A visit that can't send its answer first skips
     * the location database (a 120 MB download), which then waits for a cron run or a server that can.
     * @return list<string> what was done
     */
    public static function run(string $by, ?float $now = null): array
    {
        $now ??= microtime(true);
        $lock = @fopen(SW_ROOT . '/storage/cache/jobs.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return []; // another run is busy
        }
        try {
            self::save(['last_run' => $now, 'last_by' => $by] + ($by === 'cron' ? ['last_cron' => $now] : []));
            $out = [];
            $db = Db::fromConfig();
            foreach ($db->run('SELECT * FROM ' . $db->table('stations') . ' ORDER BY id')->fetchAll() as $station) {
                $planner = new Planner($db, $station, SW_ROOT);
                $entries = $planner->timeline($now);
                $last = $entries ? end($entries) : null;
                $out[] = sprintf('%s: %d entries, planned until %s', $station['name'], count($entries),
                    $last ? gmdate('Y-m-d H:i:s', (int) $last['end']) . ' UTC' : 'nothing (empty library)');
                $podcast = new Podcast($db, $planner->station(), SW_ROOT);
                $added = $podcast->sync($now);
                $cleaned = $podcast->cleanup($now);
                if ($added || $cleaned) {
                    $out[] = sprintf('%s: %d show(s) added to podcast episodes, raw tracks of %d episode(s) deleted',
                        $station['name'], $added, $cleaned);
                }
            }
            if (Geo::due($now) && self::mayFetchGeo($by)) {
                Geo::tried();
                try {
                    $out[] = 'Location database ' . Geo::update($now) . ' fetched';
                } catch (RuntimeException $ex) {
                    $out[] = $ex->getMessage();
                }
            }
            return $out;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Called by pages and the listener API: when the jobs are due, run them once this request's
     * answer is done. Costs one small file read when they aren't due.
     */
    public static function afterVisit(): void
    {
        if (!Config::isInstalled()) {
            return;
        }
        $last = self::state()['last_run'] ?? 0;
        if (microtime(true) - $last < self::EVERY) {
            return;
        }
        register_shutdown_function(static function (): void {
            ignore_user_abort(true);
            @set_time_limit(600);
            if (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            } elseif (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                self::run('visit');
            } catch (Throwable $ex) {
                error_log('Stationwagon jobs: ' . $ex);
            }
        });
    }

    /**
     * For the Overview: how the jobs are kept running.
     * @return array{ok:bool,what:string,found:string}
     */
    public static function health(float $now): array
    {
        $s = self::state();
        $ago = static function (float $t) use ($now): string {
            $s = max(0, $now - $t);
            return match (true) {
                $s < 60 => 'just now',
                $s < 3600 => floor($s / 60) . ' min ago',
                $s < 172800 => floor($s / 3600) . ' h ago',
                default => floor($s / 86400) . ' days ago',
            };
        };
        $cronSeen = isset($s['last_cron']) && $now - $s['last_cron'] < self::CRON_STALE;
        if ($cronSeen) {
            return ['ok' => true, 'what' => 'By a cron job every 5 minutes, and during visits', 'found' => 'ran ' . $ago((float) ($s['last_run'] ?? $s['last_cron']))];        }
        if (Config::get('cron')) {
            return ['ok' => false, 'what' => isset($s['last_cron'])
                ? 'The cron job stopped ' . $ago((float) $s['last_cron']) . '. Jobs still run during visits'
                : 'The cron job hasn\'t run yet. Jobs still run during visits',
                'found' => isset($s['last_run']) ? 'ran ' . $ago((float) $s['last_run']) : 'not yet'];
        }
        return ['ok' => true, 'what' => 'During visits, at most every 5 minutes (no cron job needed)',
            'found' => isset($s['last_run']) ? 'ran ' . $ago((float) $s['last_run']) : 'not yet'];
    }
}
