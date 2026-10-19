<?php
// Timeline around "now" plus the server clock, for the two-deck player and the studio.
declare(strict_types=1);
require_once __DIR__ . '/../shared/timeline.php';

$config = sw_config();
$now = sw_now();
$tl = sw_timeline($now);

$entries = sw_timeline_window($tl, $now - 30, $now + 900);
foreach ($entries as &$e) {
    if ($e['type'] === 'song') {
        $e['url'] = 'media/' . rawurlencode($e['file']); // relative to prototypes/
    }
}
unset($e);

sw_json([
    'server_now' => sw_now(),
    'settings'   => [
        'crossfade_seconds' => $config['crossfade_seconds'],
        'skip_fade_seconds' => $config['skip_fade_seconds'],
        'segment_seconds'   => $config['segment_seconds'],
    ],
    'entries'    => array_values($entries),
]);
