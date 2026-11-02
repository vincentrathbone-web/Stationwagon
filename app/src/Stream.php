<?php
declare(strict_types=1);

namespace Stationwagon;

/** The listener's HLS playlist: byte ranges into the original MP3s, or the uploaded live pieces. */
final class Stream
{
    /**
     * @param string $prefix how the page's address reaches the app root (api/stream.php: "../")
     * @return array{status:int,body:string}
     */
    public static function playlist(Planner $planner, float $now, string $prefix = '../'): array
    {
        $segments = $planner->segments($now);
        if (!$segments) {
            return ['status' => 503, 'body' => "#EXTM3U\n# Nothing on air yet: add songs to the library.\n"];
        }
        $target = (int) ceil((float) $planner->setting('segment_seconds')) + 1;
        $first = $segments[0];
        $out = ['#EXTM3U', '#EXT-X-VERSION:4', "#EXT-X-TARGETDURATION:$target",
                '#EXT-X-MEDIA-SEQUENCE:' . $first['msn'], '#EXT-X-DISCONTINUITY-SEQUENCE:' . $first['dsn']];
        $dsn = $first['dsn'];
        foreach ($segments as $s) {
            if ($s['dsn'] !== $dsn) {
                $out[] = '#EXT-X-DISCONTINUITY';
                $dsn = $s['dsn'];
            }
            $out[] = '#EXT-X-PROGRAM-DATE-TIME:' . self::date($s['start']);
            $out[] = sprintf('#EXTINF:%.3f,%s', $s['dur'], str_replace([',', "\n", "\r"], ' ', $s['title']));
            if ($s['range'] !== null) {
                $out[] = '#EXT-X-BYTERANGE:' . $s['range'];
            }
            $out[] = $prefix . $s['uri'];
        }
        return ['status' => 200, 'body' => implode("\n", $out) . "\n"];
    }

    /**
     * A PROGRAM-DATE-TIME to the millisecond. Whole milliseconds first, so a start just short of a
     * second (….9996) becomes the next second's .000, not this second's (a second early).
     */
    public static function date(float $t): string
    {
        $ms = (int) round($t * 1000);
        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000);
    }
}
