<?php
declare(strict_types=1);

namespace Stationwagon;

use RuntimeException;

/**
 * The station manager screens: overview (with Skip), music library (upload, edit, delete),
 * playlists, the weekly schedule and DJs. Thin: rules live in Library, Playlists, Schedule and
 * Staff. Every change that affects what plays re-plans the part of the timeline not yet on air.
 */
final class ManagePage
{
    private Db $db;
    private Planner $planner;
    private array $station;

    public function __construct()
    {
        $this->planner = Planner::forFirstStation();
        $this->db = Db::fromConfig();
        $this->station = $this->planner->station();
    }

    // ---- Overview and Skip ---------------------------------------------------------------

    public function overview(): void
    {
        $me = App::requireRole('dj');
        $now = microtime(true);
        $timeline = $this->planner->timeline($now);
        $library = new Library($this->db, $this->station, SW_ROOT);
        $this->render('overview', 'Overview', $me, [
            'onAir' => $this->planner->nowPlaying($now),
            'now' => $now,
            'plannedUntil' => $timeline ? end($timeline)['end'] : null,
            'songs' => count($library->tracks()),
            'bytes' => $library->bytes(),
            'playlists' => count((new Playlists($this->db, $this->station))->all()),
            'blocks' => count((new Schedule($this->db, $this->station))->all()),
            'block' => $this->planner->blockAt($now),
        ]);
    }

    public function skip(): void
    {
        $me = $this->postAs('manager', 'manage');
        $now = microtime(true);
        $r = $this->planner->skip($now)['result'];
        if ($r['cut_at'] === null) {
            Session::flash('bad', $r['reason'] ?? 'Nothing to skip: a live show is on air, or the library is empty.');
        } else {
            Session::flash('ok', sprintf('Skipped. The next song starts at %s; listeners hear it about %d seconds later.',
                clock($r['cut_at'], $this->station['timezone'], 'H:i:s'), (int) $this->planner->setting('listener_delay_seconds')));
        }
        App::redirect('manage');
    }

    // ---- Music library -------------------------------------------------------------------

    public function library(): void
    {
        $library = new Library($this->db, $this->station, SW_ROOT);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
            if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
                $this->uploadTooBig($wantsJson); // PHP drops the whole request past post_max_size
            }
            $me = $this->postAs('manager', 'manage/library', $wantsJson);
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'upload') {
                $results = $this->upload($library);
                if ($wantsJson) {
                    $this->json(['results' => $results]);
                }
                foreach ($results as $r) {
                    Session::flash($r['ok'] ? 'ok' : 'bad', $r['ok'] ? ($r['duplicate'] ? "Already in the library: {$r['title']}" : "Added: {$r['title']}") : "{$r['name']}: {$r['error']}");
                }
            } elseif ($action === 'update') {
                $problem = $library->update((int) ($_POST['id'] ?? 0), (string) ($_POST['title'] ?? ''), (string) ($_POST['artist'] ?? ''));
                Session::flash($problem ? 'bad' : 'ok', $problem ?? 'Saved. Listeners see the new title from the next time it plays.');
            } elseif ($action === 'delete') {
                $problem = $library->delete((int) ($_POST['id'] ?? 0), $this->planner, microtime(true));
                Session::flash($problem ? 'bad' : 'ok', $problem ?? 'Song removed from the library.');
            }
            App::redirect('manage/library' . (isset($_POST['q']) && $_POST['q'] !== '' ? '?q=' . rawurlencode((string) $_POST['q']) : ''));
        }
        $me = App::requireRole('manager');
        $q = trim((string) ($_GET['q'] ?? ''));
        $this->render('library', 'Music library', $me, [
            'tracks' => $library->tracks($q),
            'q' => $q,
            'total' => count($library->tracks()),
            'bytes' => $library->bytes(),
            'confirm' => (int) ($_GET['confirm'] ?? 0),
            'edit' => (int) ($_GET['edit'] ?? 0),
            'maxUpload' => self::maxUploadBytes(),
        ]);
    }

    /** @return list<array{name:string,ok:bool,duplicate?:bool,title?:string,error?:string}> */
    private function upload(Library $library): array
    {
        $files = $_FILES['files'] ?? null;
        if (!is_array($files) || !isset($files['name'])) {
            return [['name' => 'Upload', 'ok' => false, 'error' => 'Choose one or more MP3 files.']];
        }
        $names = (array) $files['name'];
        $out = [];
        $added = false;
        foreach ($names as $i => $name) {
            $name = (string) $name;
            $error = (int) ((array) $files['error'])[$i];
            if ($error !== UPLOAD_ERR_OK) {
                $out[] = ['name' => $name, 'ok' => false, 'error' => match ($error) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Bigger than this server accepts (' . megabytes(self::maxUploadBytes()) . ').',
                    UPLOAD_ERR_NO_FILE => 'No file was chosen.',
                    UPLOAD_ERR_PARTIAL => 'The upload was cut off. Try again.',
                    default => 'The server couldn’t take the upload (error ' . $error . ').',
                }];
                continue;
            }
            if (!preg_match('/\.mp3$/i', $name)) {
                $out[] = ['name' => $name, 'ok' => false, 'error' => 'Only MP3 files for now.'];
                continue;
            }
            $tmp = (string) ((array) $files['tmp_name'])[$i];
            try {
                if (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli') {
                    throw new RuntimeException('That isn’t an uploaded file.');
                }
                $r = $library->import($tmp, null, null, $name);
                $added = $added || !$r['duplicate'];
                $out[] = ['name' => $name, 'ok' => true, 'duplicate' => $r['duplicate'], 'title' => $r['title'], 'id' => $r['id']];
            } catch (RuntimeException $ex) {
                $out[] = ['name' => $name, 'ok' => false, 'error' => $ex->getMessage()];
            }
        }
        if ($added) {
            $this->planner->replan(microtime(true));
        }
        return $out;
    }

    private function uploadTooBig(bool $json): never
    {
        $msg = 'That upload was bigger than the server accepts (' . megabytes(self::maxUploadBytes()) . '). Send fewer or smaller files at a time.';
        if ($json) {
            http_response_code(413);
            $this->json(['error' => $msg]);
        }
        Session::flash('bad', $msg);
        App::redirect('manage/library');
    }

    public static function maxUploadBytes(): int
    {
        $bytes = function (string $v): int {
            $n = (float) $v;
            return (int) match (strtolower(substr(trim($v), -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
        };
        return min($bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size')));
    }

    // ---- Playlists -----------------------------------------------------------------------

    public function playlists(): void
    {
        $lists = new Playlists($this->db, $this->station);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->postAs('manager', 'manage/playlists');
            [$id, $problem] = $lists->create((string) ($_POST['name'] ?? ''), (string) ($_POST['mode'] ?? 'shuffle'));
            if ($problem) {
                Session::flash('bad', $problem);
                App::redirect('manage/playlists');
            }
            Session::flash('ok', 'Playlist made. Add songs to it, then put it on the schedule.');
            App::redirect("manage/playlists/$id");
        }
        $me = App::requireRole('manager');
        $this->render('playlists', 'Playlists', $me, ['lists' => $lists->all()]);
    }

    public function playlist(int $id): void
    {
        $lists = new Playlists($this->db, $this->station);
        $list = $lists->get($id);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->postAs('manager', "manage/playlists/$id");
            $action = (string) ($_POST['action'] ?? '');
            $track = (int) ($_POST['track'] ?? 0);
            $problem = match ($action) {
                'save' => $lists->update($id, (string) ($_POST['name'] ?? ''), (string) ($_POST['mode'] ?? ''), !empty($_POST['active'])),
                'add' => $lists->add($id, (array) ($_POST['tracks'] ?? [])),
                'remove' => $lists->remove($id, $track),
                'up' => $lists->move($id, $track, -1),
                'down' => $lists->move($id, $track, 1),
                'delete' => $lists->delete($id),
                default => 'Unknown action.',
            };
            if ($problem === null) {
                $this->planner->replan(microtime(true));
            }
            Session::flash($problem ? 'bad' : 'ok', $problem ?? match ($action) {
                'delete' => 'Playlist deleted, with its schedule blocks.',
                'add' => 'Songs added.',
                'remove' => 'Song taken out of the playlist (it stays in the library).',
                default => 'Saved. It applies from the next song.',
            });
            App::redirect($action === 'delete' && $problem === null ? 'manage/playlists' : "manage/playlists/$id");
        }
        $me = App::requireRole('manager');
        if ($list === null) {
            App::notFound();
            return;
        }
        $in = $lists->tracks($id);
        $inIds = array_map('intval', array_column($in, 'id'));
        $all = (new Library($this->db, $this->station, SW_ROOT))->tracks();
        $this->render('playlist', $list['name'], $me, [
            'list' => $list,
            'songs' => $in,
            'others' => array_values(array_filter($all, fn($t) => !in_array((int) $t['id'], $inIds, true))),
            'confirm' => !empty($_GET['confirm']),
        ], 'playlists');
    }

    // ---- Schedule ------------------------------------------------------------------------

    public function schedule(): void
    {
        $schedule = new Schedule($this->db, $this->station);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->postAs('manager', 'manage/schedule');
            if (($_POST['action'] ?? '') === 'delete') {
                $problem = $schedule->delete((int) ($_POST['id'] ?? 0));
                Session::flash($problem ? 'bad' : 'ok', $problem ?? 'Block removed. It applies from the next song.');
            } else {
                [$id, $errors] = $schedule->add($_POST);
                if ($errors) {
                    $_SESSION['schedule_form'] = ['in' => $_POST, 'errors' => $errors];
                    Session::flash('bad', 'The block wasn’t added: ' . implode(' ', $errors));
                    App::redirect('manage/schedule');
                }
                Session::flash('ok', 'Block added. It applies from the next song.');
            }
            $this->planner->replan(microtime(true));
            App::redirect('manage/schedule');
        }
        $me = App::requireRole('manager');
        $form = $_SESSION['schedule_form'] ?? ['in' => [], 'errors' => []];
        unset($_SESSION['schedule_form']);
        $now = microtime(true);
        $this->render('schedule', 'Weekly schedule', $me, [
            'blocks' => $schedule->all(),
            'playlists' => (new Playlists($this->db, $this->station))->all(),
            'djs' => (new Staff($this->db, $this->station))->all(),
            'form' => $form,
            'today' => (int) clock($now, $this->station['timezone'], 'N') - 1,
        ]);
    }

    // ---- DJs -----------------------------------------------------------------------------

    public function djs(): void
    {
        $staff = new Staff($this->db, $this->station);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $me = $this->postAs('manager', 'manage/djs');
            $id = (int) ($_POST['id'] ?? 0);
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'add') {
                [$newId, $errors] = $staff->add($me, (string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['role'] ?? 'dj'), (string) ($_POST['password'] ?? ''));
                if ($errors) {
                    $_SESSION['djs_form'] = ['in' => array_diff_key($_POST, ['password' => 1, 'csrf' => 1]), 'errors' => $errors];
                    Session::flash('bad', 'Not added: ' . implode(' ', $errors));
                } else {
                    Session::flash('ok', 'Added. Give them the email and password you chose; they can change the password on Your account.');
                }
            } else {
                $problem = match ($action) {
                    'role' => $staff->setRole($me, $id, (string) ($_POST['role'] ?? '')),
                    'password' => $staff->resetPassword($me, $id, (string) ($_POST['password'] ?? ''), Auth::fromConfig()),
                    'remove' => $staff->remove($me, $id),
                    default => 'Unknown action.',
                };
                Session::flash($problem ? 'bad' : 'ok', $problem ?? match ($action) {
                    'role' => 'Role changed.',
                    'password' => 'New password set. Their other sessions are signed out.',
                    default => 'Removed. They can no longer sign in.',
                });
            }
            App::redirect('manage/djs');
        }
        $me = App::requireRole('manager');
        $form = $_SESSION['djs_form'] ?? ['in' => [], 'errors' => []];
        unset($_SESSION['djs_form']);
        $this->render('djs', 'DJs', $me, [
            'people' => $staff->all(),
            'grantable' => Staff::grantable($me),
            'form' => $form,
            'open' => (int) ($_GET['person'] ?? 0),
        ]);
    }

    // ---- Shared --------------------------------------------------------------------------

    /** A POST from someone with $role and the right form key, or back to $back with a message. */
    private function postAs(string $role, string $back, bool $json = false): array
    {
        $me = App::requireRole($role);
        if (!Session::checkCsrf($_POST['csrf'] ?? null)) {
            if ($json) {
                http_response_code(400);
                $this->json(['error' => 'This form had expired. Reload the page and try again.']);
            }
            Session::flash('bad', 'This form had expired. Please try again.');
            App::redirect($back);
        }
        return $me;
    }

    private function render(string $view, string $title, array $me, array $vars, ?string $section = null): void
    {
        View::render('manage/frame', $vars + [
            'title' => $title,
            'me' => $me,
            'inner' => $view,
            'section' => $section ?? $view,
            'station' => $this->station,
            'nowPlaying' => $view === 'overview' ? null : $this->planner->nowPlaying(microtime(true))['current'],
            'csrf' => Session::csrf(),
            'wide' => true,
        ]);
    }

    private function json(array $data): never
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
