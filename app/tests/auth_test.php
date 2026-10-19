<?php
// Command-line test of sign-in (Auth) against a real MySQL/MariaDB server: roles, wrong
// passwords, lock-out, password changes signing out other sessions, sign-out, old hashes, and
// reading the visitor's address through Cloudflare. Installs into a throwaway database.
//   php app/tests/auth_test.php      (same SW_TEST_DB_* settings as install_test.php)
declare(strict_types=1);
require __DIR__ . '/lib.php';
use Stationwagon\{Auth, AuthPage, Db, Request};

$t = test_database('auth');
$root = temp_root('auth');
try {
    $cfg = test_install($t, $root);
    $db = Db::connect($cfg['db']);
    $users = $db->table('users');
    $newAuth = fn() => new Auth($db, "$root/storage/cache/throttle");
    $_SESSION = [];

    echo "Roles\n";
    $dj = ['role' => 'dj']; $mgr = ['role' => 'manager']; $adm = ['role' => 'admin'];
    check('dj may open dj pages', Auth::allows($dj, 'dj'));
    check('dj may not open manager pages', !Auth::allows($dj, 'manager'));
    check('manager may open manager pages, not admin ones', Auth::allows($mgr, 'manager') && !Auth::allows($mgr, 'admin'));
    check('admin may open everything', Auth::allows($adm, 'dj') && Auth::allows($adm, 'manager') && Auth::allows($adm, 'admin'));
    check('nobody signed in may open nothing', !Auth::allows(null, 'dj'));
    check('unknown role asked for is refused', !Auth::allows($adm, 'owner'));

    echo "Passwords\n";
    check('short password refused', Auth::passwordProblem('short') !== null);
    check('email as password refused', Auth::passwordProblem('admin@example.test', 'Admin@Example.test') !== null);
    check('good password accepted', Auth::passwordProblem('correct horse battery', 'admin@example.test') === null);

    echo "Sign in\n";
    $auth = $newAuth();
    check('nobody signed in at first', $auth->user() === null);
    $bad = $auth->attempt('admin@example.test', 'wrong password!', '203.0.113.5');
    check('wrong password refused', !$bad['ok'] && str_contains($bad['error'], 'don’t match'), $bad['error'] ?? '');
    $unknown = $auth->attempt('nobody@example.test', 'correct horse battery', '203.0.113.5');
    check('unknown email gives the same answer', !$unknown['ok'] && $unknown['error'] === $bad['error']);
    $ok = $auth->attempt('  ADMIN@example.test ', 'correct horse battery', '203.0.113.5');
    check('right password signs in (email case and spaces ignored)', $ok['ok']);
    $me = $auth->user();
    check('signed-in user is the admin', ($me['email'] ?? '') === 'admin@example.test' && ($me['role'] ?? '') === 'admin');
    check('password hash is not handed to pages', !array_key_exists('password_hash', (array) $me));
    check('last sign-in time saved', $db->run("SELECT last_login_at FROM $users WHERE id = ?", [$me['id']])->fetchColumn() !== null);
    check('success clears the email’s failed tries', $auth->fails('e:admin@example.test') === 0);

    echo "Lock-out\n";
    $db->insert('users', ['email' => 'dj@example.test', 'name' => 'Test DJ', 'password_hash' => password_hash('dj password 123', PASSWORD_DEFAULT), 'role' => 'dj', 'created_at' => gmdate('Y-m-d H:i:s')]);
    $_SESSION = [];
    $auth = $newAuth();
    for ($i = 0; $i < Auth::MAX_FAILS_PER_EMAIL; $i++) {
        $auth->attempt('dj@example.test', "guess number $i", '198.51.100.' . $i);
    }
    $locked = $auth->attempt('dj@example.test', 'dj password 123', '198.51.100.99');
    check('after 5 wrong tries even the right password waits', !$locked['ok'] && str_contains($locked['error'], 'Too many'), $locked['error'] ?? '');
    check('other people can still sign in', $auth->attempt('admin@example.test', 'correct horse battery', '198.51.100.99')['ok']);
    $_SESSION = [];
    $auth = $newAuth();
    for ($i = 0; $i < Auth::MAX_FAILS_PER_ADDRESS; $i++) {
        $auth->attempt("guess$i@example.test", 'whatever pass', '192.0.2.77');
    }
    $fromBusy = $auth->attempt('admin@example.test', 'correct horse battery', '192.0.2.77');
    check('one address guessing many emails gets locked out', !$fromBusy['ok'] && str_contains($fromBusy['error'], 'Too many'));
    $lockFile = glob("$root/storage/cache/throttle/*.json");
    check('tries are kept in storage/cache/throttle', count($lockFile) > 0);
    foreach ($lockFile as $f) { unlink($f); }
    check('lock-out ends when the tries expire', $auth->attempt('dj@example.test', 'dj password 123', '192.0.2.77')['ok']);

    echo "Password change\n";
    $_SESSION = [];
    $auth = $newAuth();
    $auth->attempt('dj@example.test', 'dj password 123', '192.0.2.1');
    $djId = (int) $auth->user()['id'];
    $otherDevice = $_SESSION; // a second browser signed in as the same DJ
    check('wrong current password refused', $auth->changePassword($djId, 'not my password', 'brand new password') !== null);
    check('too short new password refused', $auth->changePassword($djId, 'dj password 123', 'short') !== null);
    check('good change accepted', $auth->changePassword($djId, 'dj password 123', 'brand new password') === null);
    check('this browser stays signed in', ($auth->user()['id'] ?? 0) === $djId);
    $_SESSION = $otherDevice;
    check('other browsers are signed out', $newAuth()->user() === null);
    $_SESSION = [];
    check('old password no longer works', !$newAuth()->attempt('dj@example.test', 'dj password 123', '192.0.2.2')['ok']);
    $_SESSION = [];
    check('new password works', $newAuth()->attempt('dj@example.test', 'brand new password', '192.0.2.2')['ok']);

    echo "Sign out\n";
    $auth = $newAuth();
    $auth->signOut();
    check('signed out', $auth->user() === null && !isset($_SESSION['user_id']));
    $_SESSION = ['user_id' => 999999, 'pw' => 'x'];
    check('a session for a deleted user is ignored', $newAuth()->user() === null);

    echo "Old password hashes\n";
    $db->run("UPDATE $users SET password_hash = ? WHERE email = 'dj@example.test'", [password_hash('brand new password', PASSWORD_BCRYPT, ['cost' => 4])]);
    $_SESSION = [];
    $newAuth()->attempt('dj@example.test', 'brand new password', '192.0.2.3');
    $hash = (string) $db->run("SELECT password_hash FROM $users WHERE email = 'dj@example.test'")->fetchColumn();
    check('a weak old hash is upgraded at sign-in', !password_needs_rehash($hash, PASSWORD_DEFAULT) && password_verify('brand new password', $hash));

    echo "Where to go after sign-in\n";
    check('own page kept', AuthPage::safeNext('account') === 'account');
    check('another site refused', AuthPage::safeNext('//evil.example/x') === 'manage' && AuthPage::safeNext('https://evil.example') === 'manage');
    check('sign-in page itself refused', AuthPage::safeNext('signin') === 'manage');

    echo "Visitor address\n";
    $_SERVER['REMOTE_ADDR'] = '172.70.1.2'; $_SERVER['HTTP_CF_CONNECTING_IP'] = '41.13.5.6';
    check('through Cloudflare: the visitor’s own address', Request::clientAddress() === '41.13.5.6');
    $_SERVER['REMOTE_ADDR'] = '2606:4700:10::6816:1'; $_SERVER['HTTP_CF_CONNECTING_IP'] = '2c0f:f4c0::1';
    check('through Cloudflare over IPv6 too', Request::clientAddress() === '2c0f:f4c0::1');
    $_SERVER['REMOTE_ADDR'] = '41.13.5.6'; $_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4';
    check('a made-up header from elsewhere is ignored', Request::clientAddress() === '41.13.5.6');
    check('range edges', Request::inRanges('162.159.255.255', ['162.158.0.0/15']) && !Request::inRanges('162.160.0.0', ['162.158.0.0/15']));
} finally {
    drop_test_database($t);
    remove_tree($root);
}
finish();
