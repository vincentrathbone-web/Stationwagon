<?php
// Station controls for the prototypes: skip, go live, end live, reset, library rescan.
// POST action=... with the control key in the X-Key header (or a "key" field).
declare(strict_types=1);
require_once __DIR__ . '/../shared/timeline.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sw_json(['error' => 'Use POST'], 405);
    exit;
}
sw_require_key();

$now = sw_now();
$action = $_POST['action'] ?? '';
switch ($action) {
    case 'skip':
        $heard = isset($_POST['heard']) && is_numeric($_POST['heard']) ? (float) $_POST['heard'] : null;
        $tl = sw_timeline_skip($now, $heard);
        sw_json(['ok' => $tl['result']['cut_at'] !== null] + $tl['result'] + ['server_now' => $now]);
        break;
    case 'golive':
        $tl = sw_timeline_go_live($now, trim((string) ($_POST['dj'] ?? 'Studio')) ?: 'Studio');
        $result = $tl['result'];
        if (isset($result['handover']) && $result['handover']['type'] === 'song') {
            $result['handover']['url'] = 'media/' . rawurlencode($result['handover']['file']);
            unset($result['handover']['pieces']);
        }
        sw_json($result + ['server_now' => $now], isset($result['error']) ? 409 : 200);
        break;
    case 'endlive':
        $tl = sw_timeline_end_live($now, (string) ($_POST['live'] ?? ''));
        sw_json($tl['result'] + ['server_now' => $now]);
        break;
    case 'reset':
        sw_timeline_reset($now);
        sw_json(['ok' => true, 'server_now' => $now]);
        break;
    case 'rescan':
        sw_json(['ok' => true, 'tracks' => count(sw_library())]);
        break;
    default:
        sw_json(['error' => 'Unknown action. Use skip, golive, endlive, reset or rescan.'], 400);
}
