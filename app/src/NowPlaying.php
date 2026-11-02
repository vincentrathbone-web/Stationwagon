<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * What listeners hear right now, for `api/nowplaying.php` (other websites, apps, the embed): the
 * station's own player is the listener delay behind "now", so everything here is told at that
 * moment. Titles and times only; no file addresses.
 */
final class NowPlaying
{
    public const RECENT = 5;
    public const NEXT = 3;

    public function __construct(private readonly Planner $planner)
    {
    }

    /**
     * @return array{station: array, heard_at: float, server_now: float, delay: int, listeners: int,
     *               on_air: ?array, next: list<array>, recent: list<array>}
     */
    public function at(float $now): array
    {
        $delay = (int) $this->planner->setting('listener_delay_seconds');
        $heard = $now - $delay;
        $this->planner->timeline($now); // plans ahead first if it needs it
        $entries = $this->planner->entries($heard - 1800); // timeline() itself starts 10 minutes back
        $current = null;
        $next = $recent = [];
        foreach ($entries as $e) {
            $openLive = $e['type'] === 'live' && $e['open'];
            if ($current === null && $e['start'] <= $heard && ($heard < $e['end'] || $openLive)) {
                $current = $e;
            } elseif ($current === null && $e['end'] <= $heard) {
                if ($e['type'] === 'song') {
                    $recent[] = $e;
                }
            } elseif ($current !== null && $e['type'] === 'song' && count($next) < self::NEXT) {
                $next[] = $e;
            }
        }
        $station = $this->planner->station();
        return [
            'station' => ['name' => $station['name'], 'timezone' => $station['timezone']],
            'heard_at' => round($heard, 1),
            'server_now' => round($now, 3), // the embed sets its clock from it
            'delay' => $delay,
            'listeners' => (new Listeners($this->planner->db(), $station))->now($now),
            'on_air' => $current === null ? null : $this->describe($current, $heard),
            'next' => array_map(fn($e) => self::song($e) + ['starts' => round($e['start'], 1)], $next),
            'recent' => array_map(fn($e) => self::song($e) + ['started' => round($e['start'], 1)],
                array_reverse(array_slice($recent, -self::RECENT))),
        ];
    }

    private function describe(array $e, float $heard): array
    {
        $out = ['type' => $e['type'], 'started' => round($e['start'], 1), 'elapsed' => round(max(0.0, $heard - $e['start']), 1)];
        if ($e['type'] === 'song') {
            // the song ends where the next one starts (its tail plays under the next song)
            return $out + self::song($e) + ['ends' => round($e['end'], 1), 'duration' => round($e['play'], 1)];
        }
        $db = $this->planner->db();
        $dj = $db->run('SELECT u.name FROM ' . $db->table('live_shows') . ' ls JOIN ' . $db->table('users') . ' u ON u.id = ls.dj_user_id WHERE ls.id = ?',
            [$e['live_show_id']])->fetchColumn();
        return $out + ['title' => $e['title'], 'dj' => $dj === false ? null : (string) $dj, 'live' => true];
    }

    private static function song(array $e): array
    {
        return ['title' => (string) $e['title'], 'artist' => $e['artist'] === null || $e['artist'] === '' ? null : (string) $e['artist']];
    }
}
