<?php
// The verse of the day, for apps and other websites: ?lang=en (with the text of a public-domain Bible)
// or ?lang=af (the reference in Afrikaans with a link to read it). One verse a day in the station's own
// time zone; the text is fetched on the server and kept for the day. Anyone may read it.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Config, Verse};

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
if (!Config::isInstalled()) {
    http_response_code(503);
    echo json_encode(['error' => 'Not set up yet.']);
    exit;
}
try {
    echo json_encode(Verse::forFirstStation()->today((string) ($_GET['lang'] ?? 'en')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $ex) {
    error_log('Stationwagon verse: ' . $ex);
    http_response_code(503);
    echo json_encode(['error' => 'The station is busy. Try again.']);
}
