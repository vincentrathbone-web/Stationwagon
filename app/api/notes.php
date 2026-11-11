<?php
// Presenter notes for listeners: the live show's notes (or the last show's, up to a week old),
// newest first. The player fetches this when the timeline's notes_rev changes. Read only: the
// studio posts notes through its own endpoints.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Config, Notes, Planner};

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
if (!Config::isInstalled()) {
    http_response_code(503);
    echo json_encode(['error' => 'Not set up yet.']);
    exit;
}
try {
    $planner = Planner::forRequest();
    echo json_encode((new Notes($planner->db(), $planner->station()))->forListeners(microtime(true)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $ex) {
    error_log('Stationwagon notes: ' . $ex);
    http_response_code(503);
    echo json_encode(['error' => 'The station is busy. Try again.']);
}
