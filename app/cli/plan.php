<?php
// Plan the timeline ahead from cron, so it is ready even when nobody is listening (listeners'
// requests plan lazily anyway; this is optional). Every 5 minutes is plenty. Once a month it
// also fetches the new location database for the listener stats (Geo; at most one try a day):
//   */5 * * * * php /path/to/app/cli/plan.php > /dev/null 2>&1
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Config, Db, Geo, Planner};

if (!Config::isInstalled()) {
    exit(0);
}
$db = Db::fromConfig();
$now = microtime(true);
foreach ($db->run('SELECT * FROM ' . $db->table('stations') . ' ORDER BY id')->fetchAll() as $station) {
    $planner = new Planner($db, $station, SW_ROOT);
    $entries = $planner->timeline($now);
    $last = $entries ? end($entries) : null;
    printf("%s: %d entries, planned until %s\n", $station['name'], count($entries),
        $last ? gmdate('Y-m-d H:i:s', (int) $last['end']) . ' UTC' : 'nothing (empty library)');
}
if (Geo::due($now)) {
    Geo::tried();
    try {
        echo 'Location database ' . Geo::update($now) . " fetched\n";
    } catch (RuntimeException $ex) {
        echo $ex->getMessage(), "\n";
    }
}
