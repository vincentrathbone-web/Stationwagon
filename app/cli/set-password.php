<?php
// Set a user's password from the command line (over SSH), for when nobody can sign in.
// The new password comes on stdin so it stays out of the process list and shell history:
//   php cli/set-password.php someone@example.com < new-password.txt
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Auth, Config, Db};

$email = strtolower(trim((string) ($argv[1] ?? '')));
if ($email === '' || !Config::isInstalled()) {
    fwrite(STDERR, "Usage: php cli/set-password.php <email> < password-file (on an installed copy)\n");
    exit(1);
}
$password = rtrim((string) stream_get_contents(STDIN), "\r\n");
if (($problem = Auth::passwordProblem($password, $email)) !== null) {
    fwrite(STDERR, "Not changed: $problem\n");
    exit(1);
}
$db = Db::fromConfig();
$id = (int) $db->run('SELECT id FROM ' . $db->table('users') . ' WHERE email = ?', [$email])->fetchColumn();
if ($id === 0) {
    fwrite(STDERR, "No user with the email $email.\n");
    exit(1);
}
(new Auth($db, SW_ROOT . '/storage/cache/throttle'))->setPassword($id, $password);
// Clear that email's failed tries so they can sign in straight away
@unlink(SW_ROOT . '/storage/cache/throttle/' . hash('sha256', 'e:' . $email) . '.json');
echo "Password set for $email. Their other sessions are signed out.\n";
