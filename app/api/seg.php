<?php
// One piece of a song for the HLS playlist, at an address of its own: seg/<offset>-<length>/<file>.mp3
// (.htaccess sends it here). The playlist used to point into the whole song with EXT-X-BYTERANGE,
// which Android's player (Media3 1.8, used by the listener apps and many others) stops reading after
// three frames: it takes the piece's length for the end of the file (round 76). A piece of its own
// starts at byte 0, so every player reads it whole. Pieces never change, so Cloudflare and players
// keep them for good: PHP sends each one once. No database, no session.
declare(strict_types=1);

$o = $_GET['o'] ?? '';
$l = $_GET['l'] ?? '';
$f = $_GET['f'] ?? '';
$path = is_string($f) && preg_match('/^[A-Za-z0-9._-]+\.mp3$/', $f) ? __DIR__ . '/../storage/media/' . $f : '';
$size = $path !== '' && is_file($path) ? filesize($path) : false;
if (!is_string($o) || !is_string($l) || !ctype_digit($o) || !ctype_digit($l) || $size === false
    || (int) $l < 1 || (int) $l > 4 * 1024 * 1024 || (int) $o + (int) $l > $size) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "There is no such piece.\n";
    exit;
}
$length = (int) $l;
header('Content-Type: audio/mpeg');
header('Content-Length: ' . $length);
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=31536000, immutable');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}
$in = fopen($path, 'rb');
fseek($in, (int) $o);
$out = fopen('php://output', 'wb');
stream_copy_to_stream($in, $out, $length);
fclose($in);
