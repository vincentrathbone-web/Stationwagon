<?php
declare(strict_types=1);

function sw_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config.php';
        if (PHP_SAPI === 'cli' && getenv('SW_DATA_DIR')) {
            $config['data_dir'] = getenv('SW_DATA_DIR'); // tests use a throwaway data folder
        }
        foreach (['', '/live', '/locks'] as $sub) {
            $dir = $config['data_dir'] . $sub;
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
    }
    return $config;
}

function sw_now(): float
{
    return microtime(true);
}

function sw_read_json(string $path, $default = null)
{
    if (!is_file($path)) {
        return $default;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return $data === null ? $default : $data;
}

/** Atomic write: readers never see a half-written file. */
function sw_write_json(string $path, $data): void
{
    $tmp = $path . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    if (!@rename($tmp, $path)) {
        // Windows cannot rename over an existing file that another process has open
        copy($tmp, $path);
        unlink($tmp);
    }
}

/** Run $fn while holding an exclusive lock named $name. */
function sw_with_lock(string $name, callable $fn)
{
    $handle = fopen(sw_config()['data_dir'] . "/locks/$name.lock", 'c');
    flock($handle, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function sw_json($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

function sw_require_key(): void
{
    $key = $_SERVER['HTTP_X_KEY'] ?? ($_POST['key'] ?? ($_GET['key'] ?? ''));
    if (!hash_equals(sw_config()['control_key'], (string) $key)) {
        sw_json(['error' => 'Wrong control key. Use the key from prototypes/config.php.'], 403);
        exit;
    }
}
