<?php
// What listeners hear now, what's next and what played, for other websites, apps and the embed.
// Told at the listener delay, as the station's own player hears it. Anyone may read it.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Config, NowPlaying, Planner};

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
if (!Config::isInstalled()) {
    http_response_code(503);
    echo json_encode(['error' => 'Not set up yet.']);
    exit;
}
try {
    echo json_encode((new NowPlaying(Planner::forFirstStation()))->at(microtime(true)),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
} catch (Throwable $ex) {
    error_log('Stationwagon now playing: ' . $ex);
    http_response_code(503);
    echo json_encode(['error' => 'The station is busy. Try again.']);
}
