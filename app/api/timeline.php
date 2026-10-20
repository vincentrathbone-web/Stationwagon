<?php
// The timeline around "now" plus the server clock, for the two-deck player and the studio.
// Song urls are relative to the app root.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Config, Notes, Planner};

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
if (!Config::isInstalled()) {
    http_response_code(503);
    echo json_encode(['error' => 'Not set up yet.']);
    exit;
}
try {
    $planner = Planner::forFirstStation();
    $now = microtime(true);
    $entries = $planner->window($now, $now - 30, $now + 900);
    // The player reads its songs about 20 s ahead of what it plays, to draw the coming output: give
    // the songs around that time their pieces (byte offset, byte length, seconds; the HLS cut points)
    $near = [];
    foreach ($entries as $e) {
        if ($e['type'] === 'song' && $e['end'] > $now - 60 && $e['start'] < $now + 30) {
            $near[(int) $e['track_id']] = null;
        }
    }
    if ($near) {
        $ids = array_keys($near);
        $rows = $planner->db()->run('SELECT id, pieces FROM ' . $planner->db()->table('tracks') . ' WHERE id IN (' . implode(',', $ids) . ')')->fetchAll();
        foreach ($rows as $r) {
            $near[(int) $r['id']] = json_decode((string) $r['pieces'], true) ?: null;
        }
        foreach ($entries as &$e) {
            if ($e['type'] === 'song' && isset($near[(int) $e['track_id']]) && $e['end'] > $now - 60 && $e['start'] < $now + 30) {
                $e['pieces'] = $near[(int) $e['track_id']];
            }
        }
        unset($e);
    }
    echo json_encode([
        'server_now' => microtime(true),
        'station' => ['name' => $planner->station()['name'], 'timezone' => $planner->station()['timezone']],
        'settings' => [
            'crossfade_seconds' => $planner->setting('crossfade_seconds'),
            'fade_shape' => $planner->setting('fade_shape'),
            'skip_fade_seconds' => $planner->setting('skip_fade_seconds'),
            'live_fade_seconds' => $planner->setting('live_fade_seconds'),
            'segment_seconds' => $planner->setting('segment_seconds'),
            'listener_delay_seconds' => $planner->setting('listener_delay_seconds'),
        ],
        'entries' => $entries,
        // changes when the presenter notes do; the player then fetches api/notes.php
        'notes_rev' => (new Notes($planner->db(), $planner->station()))->rev($now),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
} catch (Throwable $ex) {
    error_log('Stationwagon timeline: ' . $ex);
    http_response_code(503);
    echo json_encode(['error' => 'The station is busy. Try again.']);
}
