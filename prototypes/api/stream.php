<?php
// Live HLS playlist: the last few pieces that have begun, as byte ranges inside the original
// MP3s (AutoDJ) or as uploaded files (live shows). Short request, no long-running process.
declare(strict_types=1);
require_once __DIR__ . '/../shared/timeline.php';

$config = sw_config();
$now = sw_now();
$tl = sw_timeline($now);

$segments = [];
$horizon = $now - $config['window_segments'] * $config['segment_seconds'] * 3;
foreach ($tl['entries'] as $e) {
    if (sw_entry_end($e) < $horizon && !($e['type'] === 'live' && $e['open'])) {
        continue;
    }
    if ($e['start'] > $now) {
        break;
    }
    $t = $e['start'];
    foreach ($e['pieces'] as $i => $piece) {
        if ($t > $now) {
            break;
        }
        if ($e['type'] === 'song') {
            [$offset, $length, $duration] = $piece;
            $uri = $config['media_url'] . '/' . rawurlencode($e['file']);
            $range = "$length@$offset";
        } else {
            [$name, $duration] = $piece;
            $uri = $config['data_url'] . '/live/' . rawurlencode($e['id']) . '/' . $name;
            $range = null;
        }
        $segments[] = ['msn' => $e['seq'] + $i, 'dsn' => $e['n'], 'start' => $t, 'dur' => $duration,
                       'uri' => $uri, 'range' => $range, 'title' => $e['type'] === 'song' ? $e['title'] : 'LIVE · ' . $e['dj']];
        $t += $duration;
    }
}
$segments = array_slice($segments, -$config['window_segments']);

header('Content-Type: application/vnd.apple.mpegurl');
header('Cache-Control: public, max-age=1');
header('Access-Control-Allow-Origin: *');

if (!$segments) {
    http_response_code(503);
    echo "#EXTM3U\n# No audio yet. Add MP3 files to prototypes/media.\n";
    exit;
}

$first = $segments[0];
$out = ["#EXTM3U", "#EXT-X-VERSION:4", "#EXT-X-TARGETDURATION:" . $config['target_duration'],
        "#EXT-X-MEDIA-SEQUENCE:" . $first['msn'], "#EXT-X-DISCONTINUITY-SEQUENCE:" . $first['dsn']];
$prevDsn = $first['dsn'];
foreach ($segments as $s) {
    if ($s['dsn'] !== $prevDsn) {
        $out[] = '#EXT-X-DISCONTINUITY';
        $prevDsn = $s['dsn'];
    }
    $ms = sprintf('%03d', (int) round(fmod($s['start'], 1) * 1000) % 1000);
    $out[] = '#EXT-X-PROGRAM-DATE-TIME:' . gmdate('Y-m-d\TH:i:s', (int) floor($s['start'])) . ".{$ms}Z";
    $out[] = sprintf('#EXTINF:%.3f,%s', $s['dur'], str_replace([',', "\n"], ' ', $s['title']));
    if ($s['range']) {
        $out[] = '#EXT-X-BYTERANGE:' . $s['range'];
    }
    $out[] = $s['uri'];
}
echo implode("\n", $out), "\n";
