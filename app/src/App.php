<?php
declare(strict_types=1);

namespace Stationwagon;

use Throwable;

/** Front controller: works out the route from the address and runs the matching page. */
final class App
{
    public static function run(): void
    {
        $base = self::base();
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $route = trim(substr($path, strlen($base)), '/');
        if ($route === 'index.php') {
            $route = '';
        }

        if (!Config::isInstalled() && $route !== 'install') {
            self::redirect('install');
        }

        try {
            match ($route) {
                '' => self::home(),
                'install' => (new InstallPage(new Installer(SW_ROOT)))->handle(),
                default => self::notFound(),
            };
        } catch (Throwable $ex) {
            http_response_code(500);
            error_log('Stationwagon: ' . $ex);
            View::render('error', ['title' => 'Something went wrong', 'message' => 'The server hit an error. The details are in the PHP error log.']);
        }
    }

    /** The folder the app is installed in, as seen in the address ('' at the domain root). */
    public static function base(): string
    {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        return rtrim($dir, '/');
    }

    public static function url(string $route = ''): string
    {
        return self::base() . '/' . ltrim($route, '/');
    }

    public static function redirect(string $route): never
    {
        header('Location: ' . self::url($route), true, 303);
        exit;
    }

    private static function home(): void
    {
        $db = Db::fromConfig();
        $station = $db->run('SELECT name, timezone FROM ' . $db->table('stations') . ' ORDER BY id LIMIT 1')->fetch();
        View::render('home', ['title' => $station['name'] ?? 'Stationwagon', 'station' => $station]);
    }

    private static function notFound(): void
    {
        http_response_code(404);
        View::render('error', ['title' => 'Page not found', 'message' => 'There’s no page at this address.']);
    }
}
