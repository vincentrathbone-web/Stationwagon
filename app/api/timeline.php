<?php
// The timeline around "now" plus the server clock, for the two-deck player and the studio.
// Song urls are relative to the app root.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Config, Planner};

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
if (!Config::isInstalled()) {
    http_response_code(503);
    echo json_encode(['error' => 'Not set up yet.']);
    exit;
}
try {
    $planner = Planner::forFirstStation();
    $now = microtime(true);
    $entries = $planner->window($now, $now - 30, $now + 900);
    echo json_encode([
        'server_now' => microtime(true),
        'station' => ['name' => $planner->station()['name'], 'timezone' => $planner->station()['timezone']],
        'settings' => [
            'crossfade_seconds' => $planner->setting('crossfade_seconds'),
            'skip_fade_seconds' => $planner->setting('skip_fade_seconds'),
            'live_fade_seconds' => $planner->setting('live_fade_seconds'),
            'segment_seconds' => $planner->setting('segment_seconds'),
            'listener_delay_seconds' => $planner->setting('listener_delay_seconds'),
        ],
        'entries' => $entries,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
} catch (Throwable $ex) {
    error_log('Stationwagon timeline: ' . $ex);
    http_response_code(503);
    echo json_encode(['error' => 'The station is busy. Try again.']);
}
