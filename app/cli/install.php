<?php
// Install from the command line (over SSH) instead of the web page. Reads the same fields as the
// web form as JSON on stdin, so passwords never show up in the process list or shell history:
//   php cli/install.php < answers.json
// Fields: db_host, db_port, db_name, db_user, db_pass, db_prefix, station_name, timezone,
// admin_name, admin_email, admin_password. Anyone who can run this already has the files, so the
// setup key is read from install.key here rather than asked for.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\Installer;

$inst = new Installer(SW_ROOT);
if ($inst->isInstalled()) {
    fwrite(STDERR, "Already installed: config.local.php says so.\n");
    exit(1);
}
$in = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($in)) {
    fwrite(STDERR, "Send the answers as a JSON object on stdin.\n");
    exit(1);
}
$in += ['db_host' => 'localhost', 'db_port' => '3306', 'db_prefix' => 'sw_', 'timezone' => 'Africa/Johannesburg'];
if ($inst->setupKeyRequired()) {
    $in['setup_key'] = (string) file_get_contents($inst->setupKeyPath());
}
foreach ($inst->requirements() as $r) {
    printf("  %-4s %-30s %s\n", $r['ok'] ? 'ok' : ($r['required'] ? 'FAIL' : 'warn'), $r['label'], $r['found']);
}
if (!$inst->requirementsMet()) {
    fwrite(STDERR, "Fix the failed server checks first.\n");
    exit(1);
}
try {
    $result = $inst->install($in);
} catch (RuntimeException $ex) {
    fwrite(STDERR, 'Not installed: ' . $ex->getMessage() . "\n");
    exit(1);
}
echo $result['config_written']
    ? "Installed: {$in['station_name']}. Settings saved, setup key removed.\n"
    : "Tables and admin made, but config.local.php couldn't be written. Save this as config.local.php:\n\n{$result['config']}\n";
