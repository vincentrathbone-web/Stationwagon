<?php
declare(strict_types=1);

namespace Stationwagon;

/** The listener's HLS playlist: pieces of the original MP3s (each at its own address, api/seg.php), or the uploaded live pieces. */
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
            $out[] = $prefix . self::address($s);
        }
        return ['status' => 200, 'body' => implode("\n", $out) . "\n"];
    }

    /**
     * A piece's address from the app root. A piece of a song gets one of its own, seg/<offset>-<length>/<file>
     * (api/seg.php), not a byte range into the song: Android's player (Media3 1.8, in the listener apps and
     * many others) reads only three frames of a byte range that starts further in than it is long (round 76).
     * @param array{uri:string,range:?string} $s
     */
    public static function address(array $s): string
    {
        if ($s['range'] === null) {
            return $s['uri'];
        }
        [$length, $offset] = explode('@', $s['range']);
        return 'seg/' . $offset . '-' . $length . '/' . basename($s['uri']);
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
