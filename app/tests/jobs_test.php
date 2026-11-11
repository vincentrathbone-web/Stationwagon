<?php
// The background jobs (src/Jobs.php) on an installed copy: run by cron and by visits, one at a
// time, their state, the Overview's health line, and a real visit starting them.
//   php app/tests/jobs_test.php [base url, e.g. http://localhost:8080/stationwagon-app]
// Uses the copy's own config.local.php and storage/cache/jobs.json (the jobs are the station's
// normal work, so running them early does no harm). Puts the state file back afterwards.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Config, Jobs};

$failures = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . ($detail !== '' ? "  ($detail)" : '') . "\n";
    if (!$ok) { $failures++; }
}
/** Pretend config.local.php says $values too (the real file is left alone). */
function configWith(array $values): void
{
    Config::reset();
    $all = Config::all();
    $p = new ReflectionProperty(Config::class, 'values');
    $p->setValue(null, $values + $all);
}

if (!Config::isInstalled()) {
    fwrite(STDERR, "Install this copy first.\n");
    exit(1);
}
$base = $argv[1] ?? '';
$statePath = Jobs::statePath();
$saved = @file_get_contents($statePath);
$now = microtime(true);

try {
    echo "Health\n";
    @unlink($statePath);
    configWith(['cron' => false]);
    $h = Jobs::health($now);
    check('no state, no cron: fine, run during visits', $h['ok'] && str_contains($h['what'], 'During visits') && $h['found'] === 'not yet', $h['what']);
    configWith(['cron' => true]);
    $h = Jobs::health($now);
    check('cron promised but never seen: a warning', !$h['ok'] && str_contains($h['what'], "hasn't run yet"), $h['what']);

    echo "A visit's run\n";
    configWith(['cron' => false]);
    $lines = Jobs::run('visit', $now);
    check('it plans each station', count($lines) >= 1 && str_contains($lines[0], 'planned until'), $lines[0] ?? '');
    $s = Jobs::state();
    check('state: when and by a visit', abs(($s['last_run'] ?? 0) - $now) < 1 && ($s['last_by'] ?? '') === 'visit' && !isset($s['last_cron']));
    $h = Jobs::health($now + 60);
    check('health: during visits, ran 1 minute ago', $h['ok'] && $h['found'] === 'ran 1 min ago', $h['found']);
    check('and "just now" within a minute', Jobs::health($now + 5)['found'] === 'ran just now');

    echo "Every stream (round 73)\n";
    $db = Stationwagon\Db::fromConfig();
    [$sid, $why] = Stationwagon\Streams::create($db, '[test] Jobs stream', 'test-jobs-stream');
    try {
        $tl = $db->table('timeline');
        $lines = Jobs::run('visit', $now + 0.5);
        $mainName = Stationwagon\Streams::main($db)['name'];
        $streamLine = array_values(array_filter($lines, fn($l) => str_starts_with($l, '[test] Jobs stream: ')));
        $songs = $db->run("SELECT COUNT(*) FROM $tl WHERE station_id = ? AND track_id IS NOT NULL", [$sid])->fetchColumn();
        check('a further stream is planned ahead too, from the main station’s library', $sid !== null && $streamLine && str_contains($streamLine[0], 'planned until') && (int) $songs > 0, $why ?? ($streamLine[0] ?? implode(' | ', $lines)));
        check('  the podcast runs once, for the station', count(array_filter($lines, fn($l) => str_contains($l, 'podcast') && !str_starts_with($l, $mainName))) === 0);
    } finally {
        if ($sid !== null) {
            Stationwagon\Streams::delete($db, $sid);
        }
    }
    @unlink($statePath);
    Jobs::run('visit', $now);

    echo "One at a time\n";
    $lock = fopen(SW_ROOT . '/storage/cache/jobs.lock', 'c');
    flock($lock, LOCK_EX);
    check('a second run while one is busy does nothing', Jobs::run('visit', $now + 1) === [] && abs((Jobs::state()['last_run'] ?? 0) - $now) < 1);
    flock($lock, LOCK_UN);
    fclose($lock);

    echo "Cron\n";
    $lines = Jobs::run('cron', $now + 2);
    $s = Jobs::state();
    check('a cron run records itself', abs(($s['last_cron'] ?? 0) - ($now + 2)) < 1 && $s['last_by'] === 'cron', json_encode($s));
    configWith(['cron' => true]);
    $h = Jobs::health($now + 120);
    check('health: by a cron job', $h['ok'] && str_contains($h['what'], 'By a cron job'), $h['what']);
    $h = Jobs::health($now + 2 + Jobs::CRON_STALE + 60);
    check('a cron job gone quiet for 15 minutes: a warning', !$h['ok'] && str_contains($h['what'], 'The cron job stopped'), $h['what']);
    configWith(['cron' => false]);
    $h = Jobs::health($now + 2 + Jobs::CRON_STALE + 60);
    check('no cron promised: still fine after cron stops', $h['ok'], $h['what']);

    echo "The location database\n";
    check('cron may fetch it', Jobs::mayFetchGeo('cron'));
    check('a visit may only when the answer goes first', Jobs::mayFetchGeo('visit') === Jobs::canFinishEarly(), Jobs::canFinishEarly() ? 'this server can' : 'this server cannot');

    echo "cli/plan.php\n";
    $php = PHP_BINARY;
    $out = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg(SW_ROOT . '/cli/plan.php') . ' 2>&1');
    check('prints what it planned', str_contains((string) $out, 'planned until'), trim((string) $out));
    check('and counts as cron', (Jobs::state()['last_by'] ?? '') === 'cron');

    if ($base !== '') {
        echo "A real visit\n";
        file_put_contents($statePath, json_encode(['last_run' => $now - 3600, 'last_by' => 'cron', 'last_cron' => $now - 3600]));
        $t = microtime(true);
        $body = @file_get_contents(rtrim($base, '/') . '/api/stream.php');
        $took = microtime(true) - $t;
        check('the listener playlist still answers', str_starts_with((string) $body, '#EXTM3U'));
        for ($i = 0; $i < 20 && (Jobs::state()['last_by'] ?? '') !== 'visit'; $i++) {
            usleep(250000);
        }
        $s = Jobs::state();
        check('the overdue jobs ran after it', ($s['last_by'] ?? '') === 'visit' && $s['last_run'] > $now - 60, json_encode($s));
        check('the cron time is kept', abs(($s['last_cron'] ?? 0) - ($now - 3600)) < 1);
        $t = microtime(true);
        @file_get_contents(rtrim($base, '/') . '/api/stream.php');
        $last = Jobs::state()['last_run'];
        usleep(500000);
        check('the next visit within 5 minutes leaves them', abs(Jobs::state()['last_run'] - $last) < 0.001);
        printf("  (playlist with the jobs %.0f ms)\n", $took * 1000);
    }
} finally {
    Config::reset();
    if ($saved !== false) {
        file_put_contents($statePath, $saved);
    }
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
