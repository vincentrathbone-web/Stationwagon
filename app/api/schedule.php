<?php
// The weekly schedule, for apps and other websites: the station's name and time zone, the server's clock
// and the blocks (title, AutoDJ or live, the presenter of a live one, ISO weekdays, start and end times in
// the station's time zone). Anyone may read it.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Config, Planner, Schedule};

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
if (!Config::isInstalled()) {
    http_response_code(503);
    echo json_encode(['error' => 'Not set up yet.']);
    exit;
}
try {
    $planner = Planner::forFirstStation();
    $station = $planner->station();
    echo json_encode([
        'station' => ['name' => $station['name'], 'timezone' => $station['timezone']],
        'now' => (int) floor(microtime(true)),
        'blocks' => (new Schedule($planner->db(), $station))->publicBlocks(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $ex) {
    error_log('Stationwagon schedule: ' . $ex);
    http_response_code(503);
    echo json_encode(['error' => 'The station is busy. Try again.']);
}
