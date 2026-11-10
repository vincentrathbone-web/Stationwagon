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
        } elseif (str_starts_with($route, 'index.php/')) {
            // index.php/install: setup.php hands over this way, as the web server may not have
            // noticed the new .htaccess yet
            $route = substr($route, strlen('index.php/'));
        }

        if (!Config::isInstalled() && $route !== 'install') {
            self::redirect('install');
        }

        try {
            if ($route !== 'install' && Config::isInstalled()) {
                Schema::ensure(Db::fromConfig(), SW_ROOT); // brings an older database up to date
                Jobs::afterVisit(); // the background jobs, when due, once this page is out
            }
            if (self::shellRoute($route) && self::wantsShell()) {
                self::shell($route);
                return;
            }
            if (preg_match('#^studio/api/([a-z-]+)$#', $route, $m) === 1) {
                (new StudioPage())->api($m[1]);
                return;
            }
            if (preg_match('#^podcast/api/([a-z-]+)$#', $route, $m) === 1) {
                (new PodcastPage())->api($m[1]);
                return;
            }
            if (preg_match('#^manage/podcast/(\d+)$#', $route, $m) === 1) {
                (new ManagePage())->episode((int) $m[1]);
                return;
            }
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
                'manage/people' => (new ManagePage())->people(),
                'manage/roles' => (new ManagePage())->roles(),
                'manage/djs' => App::redirect('manage/people'),
                'manage/sound' => (new ManagePage())->sound(),
                'manage/songprep' => (new ManagePage())->songprep(),
                'manage/songprep/original' => (new ManagePage())->songprepOriginal(),
                'manage/listeners' => (new ManagePage())->listeners(),
                'manage/podcast' => (new ManagePage())->podcast(),
                'podcast' => PodcastPage::page(),
                'podcast/feed' => PodcastPage::feed(),
                'studio' => (new StudioPage())->page(),
                'embed' => self::embed(),
                'now' => self::now(),
                'bar' => self::embed(true),
                'api/me' => self::meJson(),
                'privacy' => self::privacy(),
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

    /**
     * The address of a file in assets/, tagged with a hash of its content (and of any files it
     * loads itself), so browsers and Cloudflare fetch it again whenever it changes: the host tells
     * browsers to keep scripts for a week.
     */
    public static function asset(string $path, array $alsoHash = []): string
    {
        static $seen = [];
        $key = $path . '|' . implode('|', $alsoHash);
        if (!isset($seen[$key])) {
            $sums = '';
            foreach ([$path, ...$alsoHash] as $p) {
                $sums .= @sha1_file(SW_ROOT . '/' . $p) ?: Config::version();
            }
            $seen[$key] = substr(sha1($sums), 0, 10);
        }
        return self::url($path) . '?v=' . $seen[$key];
    }

    /** The Stationwagon logo as inline SVG: its colours come from the page's tokens (app.css, `.lg-mark` and `.lg-word`),
     *  so it follows the look. Made by tools/gen_brand.py. */
    public static function logo(): string
    {
        static $svg = null;
        return $svg ??= (string) @file_get_contents(SW_ROOT . '/views/logo.svg');
    }

    /** Just the wagon, for tight places (the embed's footer). Same colours as the logo. */
    public static function mark(): string
    {
        static $svg = null;
        return $svg ??= (string) @file_get_contents(SW_ROOT . '/views/mark.svg');
    }

    /** The tab icon and the home-screen icon, for a page's <head>. */
    public static function icons(): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
        return '<link rel="icon" type="image/svg+xml" href="' . $e(self::asset('assets/brand/favicon.svg')) . '">' . "\n"
            . '<link rel="icon" type="image/png" sizes="32x32" href="' . $e(self::asset('assets/brand/favicon-32.png')) . '">' . "\n"
            . '<link rel="apple-touch-icon" href="' . $e(self::asset('assets/brand/apple-touch-icon.png')) . '">' . "\n";
    }

    public static function redirect(string $route): never
    {
        header('Location: ' . self::url($route), true, 303);
        exit;
    }

    /**
     * Who is signed in, for the header of the website the station lives in (same site only: no
     * CORS). Its script asks only while the sw_staff hint cookie is there; when nobody is signed
     * in the hint is removed, so it stops asking.
     */
    private static function meJson(): never
    {
        header('Content-Type: application/json');
        header('Cache-Control: private, no-store');
        header('Vary: Cookie');
        $me = self::me();
        $out = ['signedIn' => false, 'signin' => self::url('signin')];
        if ($me === null) {
            Session::staffHint(false);
        } else {
            $out = ['signedIn' => true, 'name' => $me['name'], 'role' => $me['role_name'] ?? $me['role'],
                'studio' => self::url('studio'), 'manage' => self::url('manage'), 'account' => self::url('account'),
                'signout' => self::url('signout'), 'csrf' => Session::csrf()];
        }
        echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
        if (!isset($_COOKIE[Session::STAFF_HINT])) {
            Session::staffHint(true); // signed in before the hint existed, or by "Keep me signed in"
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
        return $db->run('SELECT id, name, slug, timezone, settings FROM ' . $db->table('stations') . ' ORDER BY id LIMIT 1')->fetch() ?: null;
    }

    /** The station's page: the listener player, what's on and today's schedule. No session. */
    private static function home(): void
    {
        $station = self::station();
        $vars = ['title' => $station['name'] ?? 'Stationwagon', 'station' => $station, 'onAir' => self::onAir(), 'today' => [], 'delay' => 20];
        try {
            $planner = Planner::forFirstStation();
            $vars['delay'] = (int) $planner->setting('listener_delay_seconds');
            $vars['today'] = Schedule::today((new Schedule(Db::fromConfig(), $planner->station()))->all(), microtime(true), $planner->station()['timezone']);
        } catch (\Throwable $ex) {
            error_log('Stationwagon player page: ' . $ex);
        }
        View::render('player', $vars + ['wide' => true]);
    }

    /**
     * The small player other websites put in an iframe: its own page, no site header, no session.
     * Any site may frame it; `?look=light` suits a light website.
     *
     * $bar: the same player as a long, flat bar (`bar`, round 61) under the manager screens, the studio and
     * the account page (the browser's frame page, views/shell.php, or the apps' own bar area), with a mute
     * button; only this station's own pages may frame it, and its listening isn't counted (it's the staff).
     */
    private static function embed(bool $bar = false): void
    {
        $station = self::station();
        $light = !$bar && ($_GET['look'] ?? '') === 'light';
        $delay = 20; // the listener delay, which the embed's player holds as the station's page does (round 60)
        try {
            $delay = (int) Planner::forFirstStation()->setting('listener_delay_seconds');
        } catch (\Throwable $ex) {
            error_log('Stationwagon embed: ' . $ex);
        }
        header_remove('X-Frame-Options');
        header('Content-Security-Policy: frame-ancestors ' . ($bar ? "'self'" : '*'));
        header('Content-Type: text/html; charset=utf-8');
        require SW_ROOT . '/views/embed.php';
    }

    /** A screen that gets the bar under it (round 61): the manager screens, the studio and the account page. */
    public static function shellRoute(string $route): bool
    {
        return in_array($route, ['studio', 'account', 'manage'], true)
            || (str_starts_with($route, 'manage/') && !in_array($route, ['manage/skip', 'manage/songprep/original'], true));
    }

    /**
     * Whether this request gets the frame page (views/shell.php) instead of the screen: a browser opening the
     * screen as a page of its own (`Sec-Fetch-Dest: document`; the frame's own request says `iframe`), signed in.
     * Not the apps (they have their own bar area; an older app is caught by shell.js, which sets `sw_bare`),
     * nor `?bare=1` or the `sw_bare` cookie (the screens on their own, as the tests open them), nor a browser
     * too old to say where a page goes (it gets the screen without the bar).
     */
    private static function wantsShell(): bool
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') !== 'document'
            || isset($_GET['bare']) || !empty($_COOKIE['sw_bare'])
            || str_contains($ua, 'StationwagonStudio/') || str_contains($ua, 'Stationwagon Studio/')) {
            return false;
        }
        try {
            Session::start(); // before asking: Auth keeps its first answer for the rest of the request
            return Auth::fromConfig()->user() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** The frame page: the screen asked for, in a frame, with the bar under it. */
    private static function shell(string $route): void
    {
        $names = ['studio' => 'Studio', 'account' => 'Your account', 'manage' => 'Manage'];
        $title = $names[$route] ?? ucfirst(str_replace('-', ' ', basename($route)));
        $screen = (string) ($_SERVER['REQUEST_URI'] ?? self::url($route));
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        require SW_ROOT . '/views/shell.php';
    }

    /**
     * The small Now playing card other websites frame (round 61): no sound, a link to where people listen.
     * `?link=` an http(s) address (else the station page), `?target=new` a new tab, `?look=light`.
     */
    private static function now(): void
    {
        $station = self::station();
        $light = ($_GET['look'] ?? '') === 'light';
        $link = (string) ($_GET['link'] ?? '');
        if (!preg_match('#^https?://[^\s"<>]+$#i', $link) || strlen($link) > 500) {
            $link = self::url('');
        }
        $newTab = ($_GET['target'] ?? '') === 'new';
        header_remove('X-Frame-Options');
        header('Content-Security-Policy: frame-ancestors *');
        header('Content-Type: text/html; charset=utf-8');
        require SW_ROOT . '/views/now.php';
    }

    /** What the station keeps about listeners, and why (linked under the player and in the embed). */
    private static function privacy(): void
    {
        $station = self::station();
        View::render('privacy', ['title' => 'Privacy', 'station' => $station, 'email' => $station ? StationSettings::privacyEmail($station) : '']);
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
