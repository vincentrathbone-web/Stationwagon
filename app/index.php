<?php
// Stationwagon: every request starts here (see .htaccess).
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    echo 'Stationwagon needs PHP 8.1 or newer. This server runs PHP ' . PHP_VERSION . '.';
    exit;
}

require __DIR__ . '/src/bootstrap.php';

Stationwagon\App::run();
