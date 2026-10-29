<?php
declare(strict_types=1);

namespace Stationwagon;

use RuntimeException;

/**
 * The installer's web page: the database (made through cPanel, or one the person made), how the
 * background jobs run (during visits, a cron job added through cPanel, or one added by hand), the
 * station and the first admin. setup.php hands over to it with the setup key in the link (?key=).
 */
final class InstallPage
{
    public function __construct(private readonly Installer $installer)
    {
    }

    public function handle(): void
    {
        if ($this->installer->isInstalled()) {
            View::render('installed', ['title' => 'Already installed']);
            return;
        }

        $in = array_merge(self::defaults(), array_map(fn($v) => is_string($v) ? $v : '', $_POST));
        $errors = [];
        $notice = null;
        $dbProblem = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = $_POST['action'] ?? '';
            if (!$this->installer->setupKeyMatches($in['setup_key'])) {
                $errors['setup_key'] = 'That setup key is wrong. Look in install.key on the server.';
            } elseif ($action === 'test') {
                $in['database'] = 'manual';
                $dbProblem = $this->installer->testDatabase(Installer::dbFrom($in));
                $notice = $dbProblem === null ? 'Connected. The database is ready for Stationwagon.' : null;
            } elseif ($action === 'install') {
                $errors = self::validateChoices($in) + Installer::validate($in['database'] === 'new' ? self::placeholderDb() + $in : $in);
                if (!$errors && $this->installer->requirementsMet()) {
                    try {
                        [$result, $steps] = $this->install($in);
                        Config::reset();
                        View::render('install-done', ['title' => 'Installed', 'result' => $result, 'station' => $in['station_name'],
                            'jobs' => $in['jobs'], 'cron' => Cpanel::cronCommand(self::root()), 'steps' => $steps]);
                        return;
                    } catch (RuntimeException $ex) {
                        $dbProblem = $ex->getMessage();
                    }
                }
            }
        }

        View::render('install', [
            'title' => 'Install Stationwagon',
            'in' => $in,
            'errors' => $errors,
            'notice' => $notice,
            'dbProblem' => $dbProblem,
            'checks' => $this->installer->requirements(),
            'ready' => $this->installer->requirementsMet(),
            'needsKey' => $this->installer->setupKeyRequired(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    /** @return array{0:array,1:list<string>} the installer's result and the steps done */
    private function install(array $in): array
    {
        $steps = [];
        $cp = null;
        if (self::needsCpanel($in)) {
            $cp = new Cpanel($in['cpanel_user'], $in['cpanel_pass']);
            if (($problem = $cp->check()) !== null) {
                throw new RuntimeException($problem);
            }
            $steps[] = 'Signed in to cPanel';
        }
        $made = null;
        if ($in['database'] === 'new') {
            $made = $cp->createDatabase();
            $in = ['db_host' => 'localhost', 'db_port' => '3306', 'db_name' => $made['name'], 'db_user' => $made['user'],
                'db_pass' => $made['pass'], 'db_prefix' => 'sw_'] + $in;
            $steps[] = "Made the database {$made['name']} with its own user {$made['user']}";
        }
        try {
            $result = $this->installer->install($in + ['cron' => $in['jobs'] !== 'visits']);
        } catch (RuntimeException $ex) {
            if ($made !== null) {
                // take the database back, so a retry starts clean
                try {
                    $cp->uapi('Mysql', 'delete_user', ['name' => $made['user']]);
                    $cp->uapi('Mysql', 'delete_database', ['name' => $made['name']]);
                } catch (RuntimeException) {
                }
            }
            throw $ex;
        }
        $steps[] = 'Made the station and your sign-in';
        if ($in['jobs'] === 'cpanel') {
            $cp->addCron(Cpanel::cronCommand(self::root()));
            $steps[] = 'Added a cron job every 5 minutes';
        } else {
            $steps[] = 'Background jobs run during visits';
        }
        return [$result, $steps];
    }

    private static function root(): string
    {
        return (string) (realpath(SW_ROOT) ?: SW_ROOT);
    }

    public static function needsCpanel(array $in): bool
    {
        return $in['database'] === 'new' || $in['jobs'] === 'cpanel';
    }

    /** @return array<string,string> */
    public static function validateChoices(array $in): array
    {
        $e = [];
        if (!in_array($in['database'], ['new', 'manual'], true)) {
            $e['database'] = 'Choose a database.';
        }
        if (!in_array($in['jobs'], ['visits', 'cpanel', 'manual'], true)) {
            $e['jobs'] = 'Choose how the background jobs run.';
        }
        if (self::needsCpanel($in) && (trim($in['cpanel_user']) === '' || $in['cpanel_pass'] === '')) {
            $e['cpanel_pass'] = 'Your choices above need your cPanel username and password.';
        }
        return $e;
    }

    /** Stand-ins so the other fields can be checked before cPanel makes the real database. */
    private static function placeholderDb(): array
    {
        return ['db_host' => 'localhost', 'db_name' => 'made_by_cpanel', 'db_user' => 'made_by_cpanel', 'db_prefix' => 'sw_'];
    }

    private static function defaults(): array
    {
        return [
            'database' => 'new', 'jobs' => 'visits', 'cpanel_user' => Cpanel::guessUser(), 'cpanel_pass' => '',
            'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
            'db_prefix' => 'sw_', 'station_name' => '', 'timezone' => 'Africa/Johannesburg',
            'admin_name' => '', 'admin_email' => '', 'admin_password' => '',
            'setup_key' => is_string($_GET['key'] ?? null) ? $_GET['key'] : '',
        ];
    }
}
