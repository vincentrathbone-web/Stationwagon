<?php
// A song file's peaks for the listeners' output strip, at peaks/<song file>.bin (.htaccess sends it here; round 87,
// src/Peaks.php). Kept in storage/peaks, which isn't served itself. A song file never changes under its name, nor do
// its peaks, so Cloudflare and browsers keep them for good: PHP sends each one once. No database, no session.
declare(strict_types=1);

$f = $_GET['f'] ?? '';
$path = is_string($f) && preg_match('/^[A-Za-z0-9._-]+$/', $f) && !str_contains($f, '..') ? __DIR__ . '/../storage/peaks/' . $f . '.peaks' : '';
$size = $path !== '' && is_file($path) ? filesize($path) : false;
if ($size === false || $size < 17) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "There are no such peaks.\n";
    exit;
}
header('Content-Type: application/octet-stream');
header('Content-Length: ' . $size);
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=31536000, immutable');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}
readfile($path);
