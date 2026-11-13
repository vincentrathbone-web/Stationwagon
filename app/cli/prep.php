<?php
// Song prep on the server (round 85, src/PrepQueue.php): prepare every upload waiting in the queue with Node
// (cli/prep-node.mjs), one at a time, until none is left. Started in the background when an upload has arrived and by
// the background jobs when something waits; a cron job every minute is the safety net where the station has one:
//   * * * * * /usr/bin/php8.4 /path/to/app/cli/prep.php > /dev/null 2>&1
// A second copy started while one works exits at once (the queue's own lock).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, 'Stationwagon needs PHP 8.1 or newer; this is ' . PHP_VERSION . ".\n");
    exit(1);
}

require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Config, Db, PrepQueue, Schema};

if (!Config::isInstalled()) {
    exit(0);
}
@set_time_limit(0);
$db = Db::fromConfig();
if (Schema::installed($db) < 22) {
    exit(0); // the upgrade comes first (cli/upgrade.php, or the next page visit)
}
PrepQueue::work($db, SW_ROOT, null, static function (string $line): void {
    echo gmdate('H:i:s'), ' ', $line, "\n";
});
