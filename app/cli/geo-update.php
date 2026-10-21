<?php
// Fetch this month's location database from DB-IP (IP to City Lite, CC BY 4.0) into
// storage/geo/, for the Listeners screen's countries and cities. cli/plan.php runs this from cron
// once a month; run it by hand after an install or to update now:
//   php app/cli/geo-update.php           (only if this month's isn't here yet)
//   php app/cli/geo-update.php --force
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\Geo;

$now = microtime(true);
if (!in_array('--force', $argv, true) && Geo::version() === gmdate('Y-m', (int) $now)) {
    echo 'The location database is up to date (' . Geo::version() . ").\n";
    exit(0);
}
Geo::tried();
try {
    $month = Geo::update($now);
    printf("Location database %s in place (%.0f MB).\n", $month, filesize(Geo::path()) / 1048576);
} catch (RuntimeException $ex) {
    fwrite(STDERR, $ex->getMessage() . "\n");
    exit(1);
}
