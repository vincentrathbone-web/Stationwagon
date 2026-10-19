<?php
declare(strict_types=1);

namespace Stationwagon;

/** Sign in, sign out and the account page (change password). */
final class AuthPage
{
    public function __construct(private readonly Auth $auth)
    {
    }

    public function signIn(): void
    {
        Session::start();
        $next = self::safeNext((string) ($_POST['next'] ?? $_GET['next'] ?? 'manage'));
        if ($this->auth->user() !== null) {
            App::redirect($next);
        }
        $email = trim((string) ($_POST['email'] ?? ''));
        $error = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Session::checkCsrf($_POST['csrf'] ?? null)) {
                http_response_code(400);
                $error = 'This form had expired. Please try again.';
            } else {
                $result = $this->auth->attempt($email, (string) ($_POST['password'] ?? ''), Request::clientAddress());
                if ($result['ok']) {
                    App::redirect($next);
                }
                http_response_code(401);
                $error = $result['error'];
            }
        }
        View::render('signin', ['title' => 'Sign in', 'email' => $email, 'error' => $error, 'next' => $next, 'csrf' => Session::csrf()]);
    }

    public function signOut(): void
    {
        Session::start();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Session::checkCsrf($_POST['csrf'] ?? null)) {
            $this->auth->signOut();
        }
        App::redirect('signin');
    }

    public function account(): void
    {
        $me = App::requireRole('dj');
        $error = null;
        $notice = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Session::checkCsrf($_POST['csrf'] ?? null)) {
                http_response_code(400);
                $error = 'This form had expired. Please try again.';
            } elseif ((string) ($_POST['new_password'] ?? '') !== (string) ($_POST['confirm_password'] ?? '')) {
                $error = 'The two new passwords aren’t the same.';
            } else {
                $error = $this->auth->changePassword((int) $me['id'], (string) ($_POST['current_password'] ?? ''), (string) ($_POST['new_password'] ?? ''));
                $notice = $error === null ? 'Password changed. Other devices are signed out.' : null;
            }
            if ($error !== null && http_response_code() === 200) {
                http_response_code(422);
            }
        }
        View::render('account', ['title' => 'Your account', 'me' => $me, 'error' => $error, 'notice' => $notice, 'csrf' => Session::csrf()]);
    }

    /** Only our own pages, never another site (//evil.example) */
    public static function safeNext(string $next): string
    {
        $next = trim($next, '/');
        return preg_match('#^[a-z0-9][a-z0-9/_-]{0,80}$#', $next) === 1 && !in_array($next, ['signin', 'signout'], true) ? $next : 'manage';
    }
}
