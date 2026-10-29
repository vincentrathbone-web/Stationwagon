<?php
// Live HLS playlist for listeners. A real file (not a front-controller route) so .htaccess can give
// it its own cache header: Cloudflare keeps it 2 s (one PHP run per location every 2 s, however
// many listeners), browsers always ask again. The Cloudflare Cache Rule matches */api/stream.php*.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Config, Jobs, Planner, Stream};

header('Content-Type: application/vnd.apple.mpegurl');
header('Access-Control-Allow-Origin: *');
if (!Config::isInstalled()) {
    http_response_code(503);
    echo "#EXTM3U\n# Not set up yet.\n";
    exit;
}
Jobs::afterVisit(); // the background jobs, when due, once this answer is out
try {
    $out = Stream::playlist(Planner::forFirstStation(), microtime(true));
} catch (Throwable $ex) {
    error_log('Stationwagon stream: ' . $ex);
    $out = ['status' => 503, 'body' => "#EXTM3U\n# The station is busy. Try again.\n"];
}
http_response_code($out['status']);
echo $out['body'];
