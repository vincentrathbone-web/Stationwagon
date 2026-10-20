<?php
// Bring the installed database up to the code's schema version (run after a deploy; pages also
// do it on their own the first time they're opened).   php cli/upgrade.php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Config, Db, Schema};

if (!Config::isInstalled()) {
    fwrite(STDERR, "Not installed yet." . PHP_EOL);
    exit(1);
}
$db = Db::fromConfig();
$from = Schema::installed($db);
$done = Schema::upgrade($db);
@unlink(SW_ROOT . '/storage/cache/schema-version');
echo $done ? "Schema $from -> " . end($done) . PHP_EOL : "Schema $from: up to date" . PHP_EOL;
