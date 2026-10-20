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
            if (preg_match('#^manage/playlists/(\d+)$#', $route, $m) === 1) {
                (new ManagePage())->playlist((int) $m[1]);
                return;
            }
            match ($route) {
                '' => self::home(),
                'install' => (new InstallPage(new Installer(SW_ROOT)))->handle(),
                'signin' => (new AuthPage(Auth::fromConfig()))->signIn(),
                'signout' => (new AuthPage(Auth::fromConfig()))->signOut(),
                'account' => (new AuthPage(Auth::fromConfig()))->account(),
                'manage' => (new ManagePage())->overview(),
                'manage/skip' => (new ManagePage())->skip(),
                'manage/library' => (new ManagePage())->library(),
                'manage/playlists' => (new ManagePage())->playlists(),
                'manage/schedule' => (new ManagePage())->schedule(),
                'manage/djs' => (new ManagePage())->djs(),
                'manage/sound' => (new ManagePage())->sound(),
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

    /** The signed-in user for the page header, without starting a session for listeners. */
    public static function me(): ?array
    {
        if (!Config::isInstalled()) {
            return null;
        }
        Session::resume();
        return Session::active() ? Auth::fromConfig()->user() : null;
    }

    /** Send anyone without the role to sign in (or a 403 if signed in with a lower role). */
    public static function requireRole(string $role): array
    {
        Session::start();
        $me = Auth::fromConfig()->user();
        if ($me === null) {
            $route = trim(substr((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), strlen(self::base())), '/');
            header('Location: ' . self::url('signin') . '?next=' . rawurlencode(AuthPage::safeNext($route)), true, 303);
            exit;
        }
        if (!Auth::allows($me, $role)) {
            http_response_code(403);
            View::render('error', ['title' => 'Not allowed', 'message' => 'Your account can’t open this page. Ask the station’s admin.']);
            exit;
        }
        return $me;
    }

    private static function station(): ?array
    {
        $db = Db::fromConfig();
        return $db->run('SELECT id, name, slug, timezone FROM ' . $db->table('stations') . ' ORDER BY id LIMIT 1')->fetch() ?: null;
    }

    private static function home(): void
    {
        $station = self::station();
        View::render('home', ['title' => $station['name'] ?? 'Stationwagon', 'station' => $station, 'onAir' => self::onAir()]);
    }

    /** What's on air now and next, plus the library size; null if the planner can't answer. */
    private static function onAir(): ?array
    {
        try {
            $planner = Planner::forFirstStation();
            $db = Db::fromConfig();
            $songs = (int) $db->run('SELECT COUNT(*) FROM ' . $db->table('tracks') . " WHERE station_id = ? AND status = 'ready'", [$planner->station()['id']])->fetchColumn();
            return $planner->nowPlaying(microtime(true)) + ['songs' => $songs, 'tz' => $planner->station()['timezone']];
        } catch (\Throwable $ex) {
            error_log('Stationwagon on air: ' . $ex);
            return null;
        }
    }

    public static function notFound(): void
    {
        http_response_code(404);
        View::render('error', ['title' => 'Page not found', 'message' => 'There’s no page at this address.']);
    }
}
