<?php
declare(strict_types=1);

// Classes live in src/ as Stationwagon\Name → src/Name.php
spl_autoload_register(function (string $class): void {
    $prefix = 'Stationwagon\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

const SW_ROOT = __DIR__ . '/..';

// View.php also holds the small helper functions (h, clock, duration, megabytes)
require_once __DIR__ . '/View.php';
