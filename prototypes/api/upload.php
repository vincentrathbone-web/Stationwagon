<?php
// Receives one live MP3 piece from the studio.
// POST body = MP3 bytes; query: live=<id>&seq=<n>&dur=<seconds>; header X-Key.
declare(strict_types=1);
require_once __DIR__ . '/../shared/timeline.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sw_json(['error' => 'Use POST'], 405);
    exit;
}
sw_require_key();

$liveId = (string) ($_GET['live'] ?? '');
$seq = (int) ($_GET['seq'] ?? -1);
$duration = (float) ($_GET['dur'] ?? 0);
$bytes = (string) file_get_contents('php://input');

if (!preg_match('/^live-\d{8}-\d{6}$/', $liveId) || $seq < 0 || $duration <= 0 || $duration > 30 || $bytes === '') {
    sw_json(['error' => 'Send live, seq and dur in the query and the MP3 bytes as the body.'], 400);
    exit;
}
if ((ord($bytes[0]) !== 0xFF) || ((ord($bytes[1] ?? "\0") & 0xE0) !== 0xE0)) {
    sw_json(['error' => 'The piece must start with an MP3 frame.'], 400);
    exit;
}

$tl = sw_timeline_add_piece(sw_now(), $liveId, $seq, $duration, $bytes);
$result = $tl['result'];
sw_json($result, $result['status'] ?? 200);
