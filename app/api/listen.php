<?php
// A ping from an open player or embed, once a minute while it plays: {"sid": 16 hex, "source":
// "player"|"embed", "stop": true when Listen is stopped}. Answers how many are listening now.
// The id is random per Listen (no cookie); the address and browser details it came from are kept
// for 30 days for the stats (see Listeners and the Privacy page). 429 when one address starts too
// many Listens at once.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Config, Listeners, Planner, Request};

header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!Config::isInstalled()) {
    http_response_code(503);
    echo json_encode(['error' => 'Not set up yet.']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Send a ping with POST.']);
    exit;
}
// JSON from fetch() or navigator.sendBeacon() (which sends it as text/plain)
$in = json_decode((string) file_get_contents('php://input', false, null, 0, 1024), true);
try {
    $planner = Planner::forRequest();
    $count = (new Listeners($planner->db(), $planner->station()))->ping(
        is_array($in) ? (string) ($in['sid'] ?? '') : '', is_array($in) ? (string) ($in['source'] ?? '') : '',
        microtime(true), is_array($in) && !empty($in['stop']), Request::clientAddress(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    echo json_encode(['listening' => $count]);
} catch (OverflowException $ex) {
    http_response_code(429);
    header('Retry-After: 600');
    echo json_encode(['error' => $ex->getMessage()]);
} catch (InvalidArgumentException $ex) {
    http_response_code(400);
    echo json_encode(['error' => $ex->getMessage()]);
} catch (Throwable $ex) {
    error_log('Stationwagon listen: ' . $ex);
    http_response_code(503);
    echo json_encode(['error' => 'The station is busy. Try again.']);
}
