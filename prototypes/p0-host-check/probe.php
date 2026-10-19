<?php
// Echoes the clock, plus the Apache time variables when reached through the rewrite rule.
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode([
    'php_now'    => microtime(true),
    'php_sec'    => gmdate('s'),
    'apache_sec' => $_GET['apache_sec'] ?? '',
    'apache_min' => $_GET['apache_min'] ?? '',
]);
