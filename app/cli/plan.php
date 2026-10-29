<?php
// The background jobs from cron (see src/Jobs.php): plan the timeline ahead, give ended live shows
// their podcast episodes, delete published episodes' raw tracks after 30 days, and once a month
// fetch the new location database for the listener stats. Optional: the same jobs also run during
// visits. Every 5 minutes is plenty; /usr/local/bin/php follows the account's PHP version on cPanel:
//   */5 * * * * /usr/local/bin/php /path/to/app/cli/plan.php > /dev/null 2>&1
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, 'Stationwagon needs PHP 8.1 or newer; this is ' . PHP_VERSION . ".\n");
    exit(1);
}

require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Config, Jobs};

if (!Config::isInstalled()) {
    exit(0);
}
foreach (Jobs::run('cron') as $line) {
    echo $line, "\n";
}
