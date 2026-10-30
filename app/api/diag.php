<?php
// A Diagnostics report from the player's panel ("Send report"): {"report": text}. Kept in
// storage/diag/ for troubleshooting (see DiagReports); answers {"id": …}. 429 after 20 an hour
// from one address.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Config, DiagReports, Request};

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
    echo json_encode(['error' => 'Send a report with POST.']);
    exit;
}
$in = json_decode((string) file_get_contents('php://input', false, null, 0, DiagReports::MAX_BYTES + 1024), true);
try {
    $id = DiagReports::forApp()->save(is_array($in) ? (string) ($in['report'] ?? '') : '', Request::clientAddress(), microtime(true));
    echo json_encode(['id' => $id]);
} catch (OverflowException $ex) {
    http_response_code(429);
    header('Retry-After: 3600');
    echo json_encode(['error' => $ex->getMessage()]);
} catch (InvalidArgumentException $ex) {
    http_response_code(400);
    echo json_encode(['error' => $ex->getMessage()]);
} catch (Throwable $ex) {
    error_log('Stationwagon diag: ' . $ex);
    http_response_code(503);
    echo json_encode(['error' => 'Couldn’t save the report. Try Copy instead.']);
}
