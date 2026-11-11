<?php
declare(strict_types=1);

namespace Stationwagon;

use RuntimeException;

/**
 * The station manager screens: overview (with Skip), music library (upload, edit, delete),
 * playlists, the weekly schedule, DJs and Sound & safety. Thin: rules live in Library, Playlists,
 * Schedule, Staff and StationSettings. Every change that affects what plays re-plans the part of
 * the timeline not yet on air.
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
            'listening' => ($listeners = new Listeners($this->db, $this->station))->now($now),
            'todayPeak' => $listeners->daily($now, 1)[0]['peak'],
            'jobs' => Jobs::health($now),
        ]);
    }

    public function skip(): void
    {
        $me = $this->postAs('desk', 'manage');
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
            $me = $this->postAs('library', 'manage/library', $wantsJson);
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'upload') {
                $results = $this->upload($library);
                if ($wantsJson) {
                    $this->json(['results' => $results]);
                }
                foreach ($results as $r) {
                    Session::flash($r['ok'] ? 'ok' : 'bad', $r['ok'] ? ($r['duplicate'] ? "Already in the library: {$r['title']}" : "Added: {$r['title']}") : "{$r['name']}: {$r['error']}");
                }
            } elseif ($action === 'original') {
                // after an upload: the file the song was prepared from, kept for song prep (round 40)
                $file = $_FILES['original'] ?? null;
                $problem = is_array($file) && (int) $file['error'] === UPLOAD_ERR_OK && (is_uploaded_file((string) $file['tmp_name']) || PHP_SAPI === 'cli')
                    ? $library->keepOriginal((int) ($_POST['id'] ?? 0), (string) $file['tmp_name'])
                    : 'The original didn’t arrive.';
                if ($wantsJson) {
                    $this->json(['ok' => $problem === null, 'error' => $problem]);
                }
                Session::flash($problem ? 'bad' : 'ok', $problem ?? 'Original kept.');
            } elseif ($action === 'prep') {
                // song prep's two switches and the loudness target, saved as soon as they change
                $target = isset($_POST['target']) && is_numeric($_POST['target']) ? (float) $_POST['target'] : null;
                $this->station = (new StationSettings($this->db, $this->station))->savePrep(!empty($_POST['master']), !empty($_POST['level']), $target);
                if ($wantsJson) {
                    $this->json(['ok' => true, 'master' => !empty($_POST['master']), 'level' => !empty($_POST['level']),
                        'target' => StationSettings::prep($this->station)[2], 'relevel' => $this->relevelList($library)]);
                }
                Session::flash('ok', 'Song prep choices saved.');
            } elseif ($action === 'rate') {
                // stars from the library row; one song (id) or several (ids[], the bulk bar). "" or 0 clears.
                $raw = trim((string) ($_POST['rating'] ?? ''));
                $stars = $raw === '' || $raw === '0' ? null : (ctype_digit($raw) ? (int) $raw : -1);
                $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_slice($_POST['ids'], 0, 2000) : [$_POST['id'] ?? 0];
                $problem = null;
                $rated = [];
                foreach ($ids as $one) {
                    $problem = $library->setRating((int) $one, $stars);
                    if ($problem !== null) {
                        break;
                    }
                    $rated[] = (int) $one;
                }
                if ($wantsJson) {
                    $this->json($problem === null ? ['ok' => true, 'rating' => $stars, 'ids' => $rated] : ['ok' => false, 'error' => $problem]);
                }
                Session::flash($problem ? 'bad' : 'ok', $problem ?? 'Rating saved.');
            } elseif (in_array($action, ['update', 'art', 'art_remove', 'rights'], true)) {
                // saved without leaving the page (library.js asks for JSON and updates the row); the forms still work without it
                $id = (int) ($_POST['id'] ?? 0);
                if ($action === 'update') {
                    $problem = $library->update($id, (string) ($_POST['title'] ?? ''), (string) ($_POST['artist'] ?? ''), isset($_POST['album']) ? (string) $_POST['album'] : null);
                } elseif ($action === 'art') {
                    $file = $_FILES['art'] ?? null;
                    $ok = is_array($file) && (int) $file['error'] === UPLOAD_ERR_OK && (is_uploaded_file((string) $file['tmp_name']) || PHP_SAPI === 'cli');
                    $problem = $ok ? $library->setArt($id, (string) file_get_contents((string) $file['tmp_name'])) : 'The picture didn’t arrive. Try a smaller one (up to 3 MB).';
                } elseif ($action === 'art_remove') {
                    $problem = $library->setArt($id, null);
                } else {
                    $problem = $library->setRights($id, $_POST);
                }
                $track = $problem === null ? $library->get($id) : null;
                if ($wantsJson) {
                    $this->json($track !== null ? ['ok' => true, 'track' => Library::row($track), 'line' => Rights::line(Rights::decode($track['rights']))]
                        : ['ok' => false, 'error' => $problem ?? 'That song isn’t in this library.']);
                }
                Session::flash($problem ? 'bad' : 'ok', $problem ?? ($action === 'update' ? 'Saved. Listeners see the new title from the next time it plays.' : 'Saved.'));
            } elseif ($action === 'delete') {
                $problem = $library->delete((int) ($_POST['id'] ?? 0), $this->planner, microtime(true));
                if ($wantsJson) {
                    $this->json(['ok' => $problem === null, 'error' => $problem, 'summary' => count($library->tracks()) . ' songs · ' . megabytes($library->bytes())]);
                }
                Session::flash($problem ? 'bad' : 'ok', $problem ?? 'Song removed from the library.');
            }
            App::redirect('manage/library' . (isset($_POST['q']) && $_POST['q'] !== '' ? '?q=' . rawurlencode((string) $_POST['q']) : ''));
        }
        $me = App::requireRole('library');
        $q = trim((string) ($_GET['q'] ?? ''));
        $this->render('library', 'Music library', $me, [
            'tracks' => $library->tracks($q),
            'q' => $q,
            'total' => count($library->tracks()),
            'bytes' => $library->bytes(),
            'confirm' => (int) ($_GET['confirm'] ?? 0),
            'edit' => (int) ($_GET['edit'] ?? 0),
            'maxUpload' => self::maxUploadBytes(),
            'prepQuality' => StationSettings::prepQuality($this->station),
            'prep' => StationSettings::prep($this->station),
            'preset' => (new PrepPresets($this->db, $this->station))->forImport(),
            'relevel' => $this->relevelList($library),
        ]);
    }

    /**
     * The songs levelled to another loudness than the station's target now (round 41), each with
     * what its re-level needs: whether the station has its original, and the mastering to make it
     * with again (master: false, or the settings, {} being the built-in chain). A song made before
     * its settings were kept uses its preset by name, or is only levelled when that preset is gone.
     * Also the songs levelled to the target by song prep before round 51 (old: true), whose encoder left
     * them 0.26 to 0.45 dB under it; re-levelling makes them again with LAME 3.100.
     * @return list<array{id:int,title:string,target:float,original:bool,master:mixed,preset:?string,old:bool}>
     */
    private function relevelList(Library $library): array
    {
        [, $level, $target] = StationSettings::prep($this->station);
        if (!$level) {
            return [];
        }
        $byName = [];
        foreach ((new PrepPresets($this->db, $this->station))->all() as $p) {
            $byName[mb_strtolower($p['name'])] = $p['settings'];
        }
        $out = [];
        foreach ($library->tracks() as $t) {
            if ($t['level_target'] === null) {
                continue;
            }
            $old = abs((float) $t['level_target'] - $target) < 0.05;
            if ($old && !$library->madeWithOldEncoder($t)) {
                continue;
            }
            $master = false;
            $preset = (int) $t['mastered'] ? ($t['preset'] ?? PrepPresets::BUILT_IN) : null;
            if ($preset !== null) {
                $kept = $t['preset_settings'] !== null ? json_decode((string) $t['preset_settings'], true) : null;
                $master = is_array($kept) ? (object) $kept
                    : (mb_strtolower($preset) === mb_strtolower(PrepPresets::BUILT_IN) ? (object) [] : (isset($byName[mb_strtolower($preset)]) ? (object) $byName[mb_strtolower($preset)] : false));
            }
            $out[] = ['id' => (int) $t['id'], 'title' => $t['title'], 'target' => (float) $t['level_target'],
                'original' => $library->original($t) !== null, 'master' => $master, 'preset' => $master === false ? null : $preset, 'old' => $old];
        }
        return $out;
    }

    /** Song prep's normalization values sent with a prepared song (field[] for uploads, $i its index). */
    private static function levelPosted(?int $i = null): array
    {
        $get = fn(string $k) => $i === null ? ($_POST[$k] ?? null) : (((array) ($_POST[$k] ?? []))[$i] ?? null);
        $settings = json_decode((string) ($get('prep_settings') ?? ''), true);
        return ['source_lufs' => $get('prep_source_lufs'), 'gain_db' => $get('prep_gain'), 'target' => $get('prep_target'),
            'settings' => is_array($settings) ? $settings : null];
    }

    // ---- Song prep (round 40): mastering presets, tried on any song of the library ------------

    public function songprep(): void
    {
        $presets = new PrepPresets($this->db, $this->station);
        $library = new Library($this->db, $this->station, SW_ROOT);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
                $this->uploadTooBig(true);
            }
            $this->postAs('library', 'manage/songprep', true);
            $action = (string) ($_POST['action'] ?? '');
            $now = microtime(true);
            if ($action === 'save') {
                [$id, $problem] = $presets->save((int) ($_POST['id'] ?? 0), (string) ($_POST['name'] ?? ''), json_decode((string) ($_POST['settings'] ?? ''), true), $now);
                if ($problem === null && !empty($_POST['default'])) {
                    $presets->setDefault((int) $id);
                }
                $this->json($problem === null ? ['ok' => true, 'id' => $id] : ['ok' => false, 'error' => $problem]);
            }
            if ($action === 'delete') {
                $problem = $presets->delete((int) ($_POST['id'] ?? 0));
                $this->json(['ok' => $problem === null, 'error' => $problem]);
            }
            if ($action === 'default') {
                $station = $presets->setDefault((int) ($_POST['id'] ?? 0));
                $this->json(['ok' => true, 'id' => PrepPresets::defaultId($station)]);
            }
            if ($action === 'apply') {
                // a song prepared again in the browser, from its original, with the preset on screen
                $file = $_FILES['file'] ?? null;
                if (!is_array($file) || (int) $file['error'] !== UPLOAD_ERR_OK || (!is_uploaded_file((string) $file['tmp_name']) && PHP_SAPI !== 'cli')) {
                    $this->json(['ok' => false, 'error' => 'The prepared song didn’t arrive. Try again.']);
                }
                $lufs = $_POST['prep_lufs'] ?? null;
                $outro = json_decode((string) ($_POST['prep_outro'] ?? ''), true);
                $problem = $library->replace((int) ($_POST['id'] ?? 0), (string) $file['tmp_name'], is_numeric($lufs) ? (float) $lufs : null,
                    is_array($outro) ? $outro : null, ($_POST['prep_mastered'] ?? '') === '1', (string) ($_POST['prep_preset'] ?? ''), $this->planner, $now, self::levelPosted());
                $this->json(['ok' => $problem === null, 'error' => $problem]);
            }
            $this->json(['ok' => false, 'error' => 'Unknown action.']);
        }
        $me = App::requireRole('library');
        $tracks = array_map(fn($t) => $t + ['original' => $library->original($t) !== null], $library->tracks());
        $this->render('songprep', 'Song prep', $me, [
            'tracks' => $tracks,
            'presets' => $presets->all(),
            'defaultId' => PrepPresets::defaultId($this->station),
            'prepQuality' => StationSettings::prepQuality($this->station),
            'prep' => StationSettings::prep($this->station),
            'song' => (int) ($_GET['song'] ?? 0),
        ], 'songprep');
    }

    /** A song's original, for the Song prep screen (managers only; never cached by others). */
    public function songprepOriginal(): void
    {
        App::requireRole('library');
        $library = new Library($this->db, $this->station, SW_ROOT);
        $track = $library->get((int) ($_GET['id'] ?? 0));
        $path = $track ? $library->original($track) : null;
        if ($path === null) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'No original for that song.';
            exit;
        }
        header('Content-Type: audio/mpeg');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
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
                $out[] = ['name' => $name, 'ok' => false, 'error' => preg_match('/\.(wav|flac|m4a|mp4|aac|ogg|oga|opus|wma)$/i', $name)
                    ? 'The station plays MP3s: other formats are converted to MP3 in your browser, which needs JavaScript on this page.'
                    : 'Only songs in MP3 (or WAV, FLAC, M4A, AAC, Ogg, Opus or WMA, converted in your browser).'];
                continue;
            }
            $tmp = (string) ((array) $files['tmp_name'])[$i];
            try {
                if (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli') {
                    throw new RuntimeException('That isn’t an uploaded file.');
                }
                // From prep.js: the original's hash (duplicates) and the levelled loudness
                $source = ((array) ($_POST['source_sha1'] ?? []))[$i] ?? null;
                $lufs = ((array) ($_POST['prep_lufs'] ?? []))[$i] ?? null;
                $outro = json_decode((string) (((array) ($_POST['prep_outro'] ?? []))[$i] ?? ''), true);
                $mastered = (string) (((array) ($_POST['prep_mastered'] ?? []))[$i] ?? '') === '1';
                $preset = ((array) ($_POST['prep_preset'] ?? []))[$i] ?? null;
                // the upload window's details (round 56), checked before the song is kept
                $details = self::detailsPosted($i);
                if (is_string($details)) {
                    throw new RuntimeException($details);
                }
                $r = $library->import($tmp, null, null, $name, is_string($source) ? strtolower($source) : null,
                    is_numeric($lufs) ? (float) $lufs : null, is_array($outro) ? $outro : null, $mastered, is_string($preset) ? $preset : null, self::levelPosted($i));
                $added = $added || !$r['duplicate'];
                $note = null;
                if ($details !== null && !$r['duplicate']) {
                    $note = $this->applyDetails($library, (int) $r['id'], $details, $i);
                    $r['title'] = (string) ($library->get((int) $r['id'])['title'] ?? $r['title']);
                }
                // original: whether the station has the file the song was made from (the page sends it
                // when not, a song uploaded again included, so older songs get theirs for Song prep)
                $track = $library->get((int) $r['id']);
                $out[] = ['name' => $name, 'ok' => true, 'duplicate' => $r['duplicate'], 'title' => $r['title'], 'id' => $r['id'],
                    'original' => $track !== null && $library->original($track) !== null] + ($note !== null ? ['note' => $note] : []);
            } catch (RuntimeException $ex) {
                $out[] = ['name' => $name, 'ok' => false, 'error' => $ex->getMessage()];
            }
        }
        if ($added) {
            $this->planner->replan(microtime(true));
        }
        return $out;
    }

    /**
     * The upload window's details for upload $i (round 56): `details[]` holds JSON with the title,
     * artist, album, the copyright details (`rights`, see Rights) and what to do with the album art
     * (`art`: "keep" the file's own, "remove" it, or "new", sent as the file `art_<i>`). Null when
     * none were sent (the form without JavaScript, older pages); a string says what's wrong.
     * @return array{title:string,artist:string,album:string,rights:array,art:string}|string|null
     */
    private static function detailsPosted(int $i): array|string|null
    {
        $raw = ((array) ($_POST['details'] ?? []))[$i] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            return 'The song’s details didn’t arrive whole. Try again.';
        }
        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '') {
            return 'A song needs a title.';
        }
        $rights = is_array($d['rights'] ?? null) ? $d['rights'] : [];
        if (($problem = Rights::problem($rights)) !== null) {
            return $problem;
        }
        $art = in_array($d['art'] ?? 'keep', ['keep', 'remove', 'new'], true) ? (string) ($d['art'] ?? 'keep') : 'keep';
        return ['title' => $title, 'artist' => (string) ($d['artist'] ?? ''), 'album' => (string) ($d['album'] ?? ''), 'rights' => $rights, 'art' => $art];
    }

    /**
     * Put the upload window's details on a song just added (its tags gave the first values; these win).
     * Returns what couldn't be kept (an unusable picture), or null.
     */
    private function applyDetails(Library $library, int $id, array $d, int $i): ?string
    {
        $library->update($id, $d['title'], $d['artist'], $d['album']);
        $library->setRights($id, $d['rights']);
        if ($d['art'] === 'remove') {
            return $library->setArt($id, null);
        }
        if ($d['art'] === 'new') {
            $file = $_FILES['art_' . $i] ?? null;
            $ok = is_array($file) && (int) $file['error'] === UPLOAD_ERR_OK && (is_uploaded_file((string) $file['tmp_name']) || PHP_SAPI === 'cli');
            return $ok ? $library->setArt($id, (string) file_get_contents((string) $file['tmp_name'])) : 'The album art didn’t arrive. Try a smaller picture (up to 3 MB).';
        }
        return null;
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
            $this->postAs('playlists', 'manage/playlists');
            [$id, $problem] = $lists->create((string) ($_POST['name'] ?? ''), (string) ($_POST['mode'] ?? 'shuffle'));
            if ($problem) {
                Session::flash('bad', $problem);
                App::redirect('manage/playlists');
            }
            Session::flash('ok', 'Playlist made. Add songs to it, then put it on the schedule.');
            App::redirect("manage/playlists/$id");
        }
        $me = App::requireRole('playlists');
        $this->render('playlists', 'Playlists', $me, ['lists' => $lists->all()]);
    }

    public function playlist(int $id): void
    {
        $lists = new Playlists($this->db, $this->station);
        $list = $lists->get($id);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->postAs('playlists', "manage/playlists/$id");
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
        $me = App::requireRole('playlists');
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
            $this->postAs('schedule', 'manage/schedule');
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
        $me = App::requireRole('schedule');
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

    // ---- People --------------------------------------------------------------------------

    /** Everyone who can sign in: add, change details and role, block, set a password, remove. */
    public function people(): void
    {
        $staff = new Staff($this->db, $this->station);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $me = $this->postAs('people', 'manage/people');
            $id = (int) ($_POST['id'] ?? 0);
            $action = (string) ($_POST['action'] ?? '');
            $back = 'manage/people';
            if ($action === 'add') {
                [$newId, $errors] = $staff->add($me, (string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['role'] ?? 'dj'), (string) ($_POST['password'] ?? ''));
                if ($errors) {
                    $_SESSION['people_form'] = ['in' => array_diff_key($_POST, ['password' => 1, 'csrf' => 1]), 'errors' => $errors];
                    Session::flash('bad', 'Not added: ' . implode(' ', $errors));
                } else {
                    Session::flash('ok', 'Added. Give them the email and password you chose; they can change the password on Your account.');
                }
            } else {
                $back = 'manage/people?person=' . $id;
                $problem = match ($action) {
                    'details' => implode(' ', $staff->edit($me, $id, (string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''))) ?: null,
                    'role' => $staff->setRole($me, $id, (string) ($_POST['role'] ?? '')),
                    'password' => $staff->resetPassword($me, $id, (string) ($_POST['password'] ?? ''), Auth::fromConfig()),
                    'block' => $staff->block($me, $id, $this->planner),
                    'unblock' => $staff->unblock($me, $id),
                    'remove' => $staff->remove($me, $id),
                    default => 'Unknown action.',
                };
                if ($action === 'remove' && $problem === null) {
                    $back = 'manage/people';
                }
                Session::flash($problem ? 'bad' : 'ok', $problem ?? match ($action) {
                    'details' => 'Name and email saved.',
                    'role' => 'Role changed. They may now do what that role allows.',
                    'password' => 'New password set. Their other sessions are signed out.',
                    'block' => 'Blocked. They are signed out everywhere, and if they were live the AutoDJ has taken over.',
                    'unblock' => 'Unblocked. They can sign in again.',
                    default => 'Removed. They can no longer sign in.',
                });
            }
            App::redirect($back);
        }
        $me = App::requireRole('people');
        $form = $_SESSION['people_form'] ?? ['in' => [], 'errors' => []];
        unset($_SESSION['people_form']);
        $this->render('people', 'People', $me, [
            'people' => $staff->all(),
            'staff' => $staff,
            'roles' => (new Roles($this->db, $this->station))->all(),
            'grantable' => $staff->grantable($me),
            'form' => $form,
            'open' => (int) ($_GET['person'] ?? 0),
        ]);
    }

    /** The station's roles and what each may do; admins only. */
    public function roles(): void
    {
        $roles = new Roles($this->db, $this->station);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $me = $this->postAs('admin', 'manage/roles');
            $key = (string) ($_POST['key'] ?? '');
            $ticks = array_map('strval', (array) ($_POST['perm'] ?? []));
            $action = (string) ($_POST['action'] ?? '');
            $back = 'manage/roles';
            if ($action === 'add') {
                [$newKey, $problem] = $roles->add($me, (string) ($_POST['name'] ?? ''), $ticks);
                $back .= $newKey ? '?role=' . rawurlencode($newKey) : '';
            } else {
                $problem = match ($action) {
                    'save' => $roles->update($me, $key, (string) ($_POST['name'] ?? ''), $ticks),
                    'delete' => $roles->delete($me, $key),
                    default => 'Unknown action.',
                };
                $back .= $action === 'save' ? '?role=' . rawurlencode($key) : '';
            }
            Session::flash($problem ? 'bad' : 'ok', $problem ?? ['add' => 'Role added.', 'save' => 'Saved. Everyone with this role may now do what it allows.', 'delete' => 'Role deleted.'][$action]);
            App::redirect($back);
        }
        $me = App::requireRole('admin');
        $this->render('roles', 'Roles', $me, ['roles' => $roles->all(), 'open' => (string) ($_GET['role'] ?? '')]);
    }

    // ---- Sound & safety ------------------------------------------------------------------

    /** Listeners: how many now, and charts of the last 48 hours and 30 days. */
    public function listeners(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->postAs('listeners', 'manage/listeners');
            [, $problem] = (new StationSettings($this->db, $this->station))->savePrivacyEmail((string) ($_POST['privacy_email'] ?? ''));
            Session::flash($problem ? 'bad' : 'ok', $problem ? "Not saved: $problem" : 'Saved. The Privacy page shows it now.');
            App::redirect('manage/listeners');
        }
        $me = App::requireRole('listeners');
        $now = microtime(true);
        $listeners = new Listeners($this->db, $this->station);
        $this->render('listeners', 'Listeners', $me, [
            'now' => $now,
            'listening' => $listeners->now($now),
            'hourly' => $listeners->hourly($now, 48),
            'daily' => $listeners->daily($now, 30),
            'monthly' => $listeners->monthly($now, 12),
            'breakdown' => $listeners->breakdown($now, 30),
            'geoVersion' => Geo::version(),
            'privacyEmail' => StationSettings::privacyEmail($this->station),
        ]);
    }

    // ---- Podcast --------------------------------------------------------------------------

    /** Episodes (all for managers, their own for DJs) and, for managers, the podcast's details. */
    public function podcast(): void
    {
        $podcast = new Podcast($this->db, $this->station, SW_ROOT);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->postAs('podcast', 'manage/podcast');
            $file = $_FILES['cover'] ?? null;
            $tmp = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name']) ? $file['tmp_name'] : null;
            if (is_array($file) && !in_array($file['error'] ?? UPLOAD_ERR_NO_FILE, [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE], true)) {
                Session::flash('bad', 'Not saved: the cover didn’t upload. Try a smaller image.');
                App::redirect('manage/podcast');
            }
            [, $problem] = $podcast->saveDetails($_POST, $tmp);
            Session::flash($problem ? 'bad' : 'ok', $problem ? "Not saved: $problem" : 'Saved. Podcast apps see the changes the next time they check the feed.');
            App::redirect('manage/podcast');
        }
        $me = App::requireRole('dj');
        $podcast->sync(microtime(true));
        $this->render('podcast', 'Podcast', $me, [
            'episodes' => $podcast->list($me),
            'details' => $podcast->details(),
            'isManager' => Auth::allows($me, 'podcast'),
        ]);
    }

    /** The episode editor: two tracks of clips (or one, as aired), Mixdown, Publish. */
    public function episode(int $id): void
    {
        $me = App::requireRole('dj');
        $podcast = new Podcast($this->db, $this->station, SW_ROOT);
        $ep = $podcast->episodeById($id);
        if ($ep === null || $ep['status'] === 'skipped' || !$podcast->mayEdit($me, $ep)) {
            http_response_code(404);
            View::render('error', ['title' => 'Not found', 'message' => 'There’s no such episode, or it isn’t yours to edit.']);
            return;
        }
        $this->render('episode', $ep['title'], $me, ['episode' => $ep, 'kbps' => 128], 'podcast');
    }

    public function sound(): void
    {
        $settings = new StationSettings($this->db, $this->station);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->postAs('sound', 'manage/sound');
            [$station, $errors] = $settings->save($_POST);
            if ($errors) {
                $_SESSION['sound_form'] = ['in' => array_diff_key($_POST, ['csrf' => 1]), 'errors' => $errors];
                Session::flash('bad', 'Not saved: ' . implode(' ', $errors));
                App::redirect('manage/sound');
            }
            // Plan what isn't on air yet again, with the new values
            (new Planner($this->db, $station, SW_ROOT))->replan(microtime(true));
            Session::flash('ok', 'Saved. New settings apply from the next song.');
            App::redirect('manage/sound');
        }
        $me = App::requireRole('sound');
        $form = $_SESSION['sound_form'] ?? ['in' => [], 'errors' => []];
        unset($_SESSION['sound_form']);
        $this->render('sound', 'Sound & safety', $me, [
            'values' => array_replace($settings->values(), array_intersect_key($form['in'], StationSettings::FIELDS)),
            'errors' => $form['errors'],
            'minLiveTimeout' => $settings->minLiveTimeout(),
            'segment' => (float) $this->planner->setting('segment_seconds'),
            'delay' => (int) $this->planner->setting('listener_delay_seconds'),
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
            'fluid' => true, // the manager uses the whole window (round 62); the player and the studio keep their width
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
