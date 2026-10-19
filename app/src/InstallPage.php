<?php
declare(strict_types=1);

namespace Stationwagon;

use RuntimeException;

/** The installer's web page: one form, a "Test connection" button and an "Install" button. */
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
                $dbProblem = $this->installer->testDatabase(Installer::dbFrom($in));
                $notice = $dbProblem === null ? 'Connected. The database is ready for Stationwagon.' : null;
            } elseif ($action === 'install') {
                $errors = Installer::validate($in);
                if (!$errors && $this->installer->requirementsMet()) {
                    try {
                        $result = $this->installer->install($in);
                        Config::reset();
                        View::render('install-done', ['title' => 'Installed', 'result' => $result, 'station' => $in['station_name']]);
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

    private static function defaults(): array
    {
        return [
            'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
            'db_prefix' => 'sw_', 'station_name' => '', 'timezone' => 'Africa/Johannesburg',
            'admin_name' => '', 'admin_email' => '', 'admin_password' => '', 'setup_key' => '',
        ];
    }
}
