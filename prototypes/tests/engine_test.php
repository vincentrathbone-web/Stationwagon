<?php
// Command-line test of the timeline engine: scan, plan, HLS output, skip, go live, pieces,
// timeout and end show. Uses a throwaway data folder, never the real one.
//   php prototypes/tests/engine_test.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/sw-engine-test-' . getmypid();
putenv("SW_DATA_DIR=$tmp");
require_once __DIR__ . '/../shared/timeline.php';

$failures = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? "  ok   " : "  FAIL ") . $what . ($detail !== '' ? "  ($detail)" : '') . "\n";
    if (!$ok) { $failures++; }
}
function playlist(float $now): string
{
    // run stream.php's logic at a chosen time by faking the clock is not possible, so we
    // rebuild the segment list the same way stream.php does
    $config = sw_config();
    $tl = sw_timeline($now);
    $segs = [];
    foreach ($tl['entries'] as $e) {
        $t = $e['start'];
        foreach ($e['pieces'] as $i => $p) {
            if ($t > $now) break 2;
            $segs[] = ['msn' => $e['seq'] + $i, 'dsn' => $e['n'], 'start' => $t, 'dur' => $e['type'] === 'song' ? $p[2] : $p[1]];
            $t += $e['type'] === 'song' ? $p[2] : $p[1];
        }
    }
    return json_encode(array_slice($segs, -$config['window_segments']));
}

echo "Library\n";
$lib = sw_library();
check('found test MP3s', count($lib) >= 3, count($lib) . ' tracks');
foreach ($lib as $t) {
    $sum = array_sum(array_column($t['pieces'], 2));
    check("{$t['file']} scanned", $t['frames'] > 0 && abs($sum - $t['duration']) < 0.01,
        sprintf('%.2f s, %d pieces, %d kbps, %d Hz', $t['duration'], count($t['pieces']), $t['bitrate'], $t['sample_rate']));
    $pieceOk = true;
    foreach (array_slice($t['pieces'], 0, -1) as $p) { $pieceOk = $pieceOk && $p[2] >= 6.0 && $p[2] < 6.1; }
    check('  every piece but the last is 6.0–6.1 s', $pieceOk);
    $fh = fopen(sw_config()['media_dir'] . '/' . $t['file'], 'rb');
    $starts = true;
    foreach ($t['pieces'] as $p) { fseek($fh, $p[0]); $h = fread($fh, 2); $starts = $starts && ord($h[0]) === 0xFF && (ord($h[1]) & 0xE0) === 0xE0; }
    fclose($fh);
    check('  every piece starts on an MP3 frame', $starts);
}

echo "Planning\n";
$t0 = 1800000000.0; // a fixed "now" keeps the test repeatable
$tl = sw_timeline($t0);
$entries = $tl['entries'];
check('planned ahead', sw_entry_end(end($entries)) >= $t0 + sw_config()['plan_ahead_seconds'], count($entries) . ' entries');
$contiguous = true; $seqOk = true;
for ($i = 1; $i < count($entries); $i++) {
    $contiguous = $contiguous && abs($entries[$i]['start'] - sw_entry_end($entries[$i - 1])) < 1e-6;
    $seqOk = $seqOk && $entries[$i]['seq'] === $entries[$i - 1]['seq'] + count($entries[$i - 1]['pieces']);
}
check('entries are back to back', $contiguous);
check('HLS numbering is continuous', $seqOk);
$repeat = false;
for ($i = 1; $i < count($entries); $i++) { $repeat = $repeat || $entries[$i]['id'] === $entries[$i - 1]['id']; }
check('no song plays twice in a row', !$repeat);
$first = $entries[0];
check('stream moves on inside the crossfade window', $first['full'] - $first['play'] <= sw_config()['crossfade_seconds'] + 6.1,
    sprintf('plays %.2f of %.2f s', $first['play'], $first['full']));

echo "HLS window\n";
$a = json_decode(playlist($t0 + 100), true);
$b = json_decode(playlist($t0 + 106.5), true);
check('window has 5 pieces', count($a) === 5);
check('window slides forward', $b[0]['msn'] > $a[0]['msn']);
check('sequence numbers are consecutive', array_column($a, 'msn') === range($a[0]['msn'], $a[0]['msn'] + 4));

echo "Skip\n";
$now = $t0 + 30;
$before = sw_timeline($now)['entries'];
$r = sw_timeline_skip($now);
$cut = $r['result']['cut_at'];
check('skip returns a cut time 1–7 s ahead', $cut !== null && $cut >= $now + 1 && $cut <= $now + 7.1, sprintf('+%.2f s', $cut - $now));
$after = $r['entries'];
$cutEntry = null;
foreach ($after as $e) { if (!empty($e['cut'])) { $cutEntry = $e; } }
check('current song is cut with the skip fade', $cutEntry && abs(sw_entry_end($cutEntry) - $cut) < 1e-6 && $cutEntry['fade_out'] === sw_config()['skip_fade_seconds']);
$next = null;
foreach ($after as $e) { if (abs($e['start'] - $cut) < 1e-6) { $next = $e; } }
check('next song starts exactly at the cut', $next !== null);
check('numbering still continuous after skip', $next && $next['seq'] === $cutEntry['seq'] + count($cutEntry['pieces']));

echo "Live\n";
$now = $t0 + 60;
$r = sw_timeline_go_live($now, 'Test DJ');
$live = $r['result']['live'] ?? null;
check('go live opens a live block', $live && $live['open'] && $live['start'] >= $now + 1, $live ? sprintf('starts +%.2f s', $live['start'] - $now) : json_encode($r['result']));
check('no AutoDJ songs planned during the show', end($r['entries'])['type'] === 'live');
$mp3 = file_get_contents(sw_config()['media_dir'] . '/' . $lib[0]['file'], false, null, $lib[0]['pieces'][1][0], $lib[0]['pieces'][1][1]);
$p0 = sw_timeline_add_piece($live['start'] + 7, $live['id'], 0, 6.0, $mp3)['result'];
check('piece 0 accepted', !empty($p0['ok']) && empty($p0['duplicate']));
$dup = sw_timeline_add_piece($live['start'] + 8, $live['id'], 0, 6.0, $mp3)['result'];
check('duplicate piece ignored', !empty($dup['duplicate']));
$gap = sw_timeline_add_piece($live['start'] + 9, $live['id'], 2, 6.0, $mp3)['result'];
check('out-of-order piece rejected with the expected number', ($gap['status'] ?? 0) === 409 && $gap['expected'] === 1);
$p1 = sw_timeline_add_piece($live['start'] + 13, $live['id'], 1, 6.0, $mp3)['result'];
check('piece 1 accepted', !empty($p1['ok']));
check('piece saved to disk', is_file(sw_config()['data_dir'] . "/live/{$live['id']}/00001.mp3"));
$pl = json_decode(playlist($live['start'] + 13), true);
check('playlist includes the live pieces', end($pl)['msn'] === $live['seq'] + 1 && end($pl)['dsn'] === $live['n']);

$end = sw_timeline_end_live($live['start'] + 14, $live['id']);
check('end show closes the block', !empty($end['result']['ended']) && abs($end['result']['stream_end'] - ($live['start'] + 12)) < 1e-6);
$resume = null;
foreach ($end['entries'] as $e) { if ($e['type'] === 'song' && abs($e['start'] - ($live['start'] + 12)) < 1e-6) { $resume = $e; } }
check('AutoDJ resumes right after the live block', $resume !== null && $resume['seq'] === $live['seq'] + 2);

echo "Live timeout\n";
$now = $t0 + 400;
$r2 = sw_timeline_go_live($now, 'Dropped DJ');
$live2 = $r2['result']['live'];
sw_timeline_add_piece($live2['start'] + 7, $live2['id'], 0, 6.0, $mp3);
$tl = sw_timeline($live2['start'] + 7 + sw_config()['live_timeout_seconds'] + 1);
$closed = null;
foreach ($tl['entries'] as $e) { if (($e['id'] ?? '') === $live2['id']) { $closed = $e; } }
check('silent live block closes itself', $closed && !$closed['open'] && ($closed['closed_reason'] ?? '') === 'timeout');
check('AutoDJ planned again after the timeout', end($tl['entries'])['type'] === 'song');
$late = sw_timeline_add_piece($live2['start'] + 30, $live2['id'], 1, 6.0, $mp3)['result'];
check('late piece after timeout is refused', ($late['status'] ?? 0) === 410);

// clean up
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
rmdir($tmp);

echo $failures ? "\n$failures check(s) failed\n" : "\nAll checks passed\n";
exit($failures ? 1 : 0);
