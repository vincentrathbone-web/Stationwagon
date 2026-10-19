<?php
// Add MP3 files to the station's library from the command line (over SSH):
//   php cli/import.php song1.mp3 "folder/*.mp3" ...
// Each file is stored once under its content hash; a file that's already there is skipped.
// Then the planned-but-not-started part of the timeline is planned again to include them.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Config, Library, Planner};

if (!Config::isInstalled() || count($argv) < 2) {
    fwrite(STDERR, "Usage: php cli/import.php <mp3 files or patterns> (on an installed copy)\n");
    exit(1);
}
$planner = Planner::forFirstStation();
$library = new Library(\Stationwagon\Db::fromConfig(), $planner->station(), SW_ROOT);
$added = 0;
$failed = 0;
foreach (array_slice($argv, 1) as $arg) {
    foreach (glob($arg) ?: [$arg] as $path) {
        try {
            $r = $library->import($path);
            echo ($r['duplicate'] ? '  already there  ' : '  added          ') . $r['title'] . "\n";
            $added += $r['duplicate'] ? 0 : 1;
        } catch (RuntimeException $ex) {
            echo '  skipped        ' . $ex->getMessage() . "\n";
            $failed++;
        }
    }
}
if ($added) {
    $planner->replan(microtime(true));
}
echo "$added added, $failed skipped. The library has " . count($library->tracks()) . " songs.\n";
exit($failed ? 1 : 0);
