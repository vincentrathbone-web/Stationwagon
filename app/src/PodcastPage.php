<?php
declare(strict_types=1);

namespace Stationwagon;

use Throwable;

/**
 * Podcast endpoints (/podcast/api/<action>, signed-in staff; rules in Podcast), the public podcast
 * page (/podcast) and its RSS feed (/podcast/feed). The manager screens are in ManagePage.
 *
 *   POST rec-chunk?show=&track=&n=&samples=   one chunk of the studio's recording (MP3 body)
 *   POST rec-done   {show, counts: {music, mic}, analysis}
 *   GET  episode?id=                          the editor's view of an episode
 *   GET  chunk?id=&take=&track=&n=            a chunk's MP3 (a device chunk or a piece as aired)
 *   GET  audio?id=                            the episode's mixdown (with Range, for the player)
 *   POST analysis   {id, take, analysis}      an as-aired take's peaks and clips
 *   POST edits      {id, deleted}
 *   POST mix-chunk?id=&n=                     one part of a mixdown (MP3 body)
 *   POST mix-done   {id, parts, seconds, deleted}
 *   POST publish    {id, title, description, publish}
 *   POST hide       {id, hidden}
 * POSTs carry the session's form key in an X-CSRF header.
 */
final class PodcastPage
{
    public function api(string $action): void
    {
        try {
            Session::start();
            $me = Auth::fromConfig()->user();
            if ($me === null || !Auth::allows($me, 'dj')) {
                $this->out(['error' => 'You’re signed out. Sign in again.'], 401);
            }
            $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
            if ($post && !Session::checkCsrf($_SERVER['HTTP_X_CSRF'] ?? null)) {
                $this->out(['error' => 'The page has expired. Reload it.'], 400);
            }
            session_write_close();
            $podcast = Podcast::forFirstStation();
            $raw = in_array($action, ['rec-chunk', 'mix-chunk'], true);
            $in = $post && !$raw ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : [];
            if ($action === 'rec-chunk' && $post) {
                $this->result($podcast->addChunk($me, (string) ($_GET['show'] ?? ''), (string) ($_GET['track'] ?? ''), (int) ($_GET['n'] ?? -1), (int) ($_GET['samples'] ?? 0), (string) file_get_contents('php://input')));
            }
            if ($action === 'rec-done' && $post) {
                $this->result($podcast->finishDevice($me, (string) ($in['show'] ?? ''), is_array($in['counts'] ?? null) ? $in['counts'] : [], $in['analysis'] ?? null));
            }
            // everything else is about one episode this person may edit
            $ep = $podcast->episodeById((int) ($_GET['id'] ?? $in['id'] ?? 0));
            if ($ep === null || $ep['status'] === 'skipped') {
                $this->out(['error' => 'No such episode.'], 404);
            }
            if (!$podcast->mayEdit($me, $ep)) {
                $this->out(['error' => 'Only the show’s presenter or a manager can edit this episode.'], 403);
            }
            $r = match (true) {
                !$post && $action === 'episode' => $podcast->view($ep),
                !$post && $action === 'chunk' => $this->file($podcast->chunkPath($ep, (int) ($_GET['take'] ?? 0), (string) ($_GET['track'] ?? ''), (int) ($_GET['n'] ?? -1))),
                !$post && $action === 'audio' => $this->file($podcast->audioPath($ep)),
                $post && $action === 'analysis' => $podcast->saveAnalysis($ep, (int) ($in['take'] ?? 0), $in['analysis'] ?? null),
                $post && $action === 'edits' => $podcast->saveEdits($ep, $in['deleted'] ?? null),
                $post && $action === 'mix-chunk' => $podcast->addMixPart($ep, (int) ($_GET['n'] ?? -1), (string) file_get_contents('php://input')),
                $post && $action === 'mix-done' => $podcast->finishMix($ep, (int) ($in['parts'] ?? 0), (float) ($in['seconds'] ?? 0), $in['deleted'] ?? []),
                $post && $action === 'publish' => $podcast->publish($ep, (string) ($in['title'] ?? ''), (string) ($in['description'] ?? ''), !empty($in['publish'])),
                $post && $action === 'hide' => $podcast->setHidden($ep, !empty($in['hidden'])),
                default => ['error' => 'No such podcast action.', 'status' => 404],
            };
            $this->result($r);
        } catch (Throwable $ex) {
            error_log('Stationwagon podcast: ' . $ex);
            $this->out(['error' => 'The server hit an error. Try again.'], 503);
        }
    }

    /** The public podcast page: the published episodes with a player each, and the feed's address. */
    public static function page(): void
    {
        $podcast = Podcast::forFirstStation();
        View::render('podcast', ['title' => 'Podcast', 'details' => $podcast->details(), 'episodes' => $podcast->published(), 'podcast' => $podcast, 'station' => $podcast->station()]);
    }

    /** The RSS feed for podcast apps. */
    public static function feed(): void
    {
        $base = self::base();
        header('Content-Type: application/rss+xml; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        echo Podcast::forFirstStation()->feed($base);
    }

    /** The app's absolute address, as the visitor reached it (behind Cloudflare too). */
    public static function base(): string
    {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || str_contains((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"https"');
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . App::url('');
    }

    /** Send a file, honouring a single Range (the audio player seeks with them). */
    private function file(?string $path): never
    {
        if ($path === null) {
            $this->out(['error' => 'That file isn’t here (any more).'], 404);
        }
        $size = filesize($path);
        $from = 0;
        $to = $size - 1;
        if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m) === 1 && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                $from = max(0, $size - (int) $m[2]);
            } else {
                $from = (int) $m[1];
                $to = $m[2] !== '' ? min($to, (int) $m[2]) : $to;
            }
            if ($from > $to || $from >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */$size");
                exit;
            }
            http_response_code(206);
            header("Content-Range: bytes $from-$to/$size");
        }
        header('Content-Type: audio/mpeg');
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store');
        header('Content-Length: ' . ($to - $from + 1));
        $fh = fopen($path, 'rb');
        fseek($fh, $from);
        $left = $to - $from + 1;
        while ($left > 0 && !feof($fh)) {
            $buf = (string) fread($fh, min(65536, $left));
            echo $buf;
            $left -= strlen($buf);
        }
        fclose($fh);
        exit;
    }

    private function result(array $r): never
    {
        $status = (int) ($r['status'] ?? (isset($r['error']) ? 400 : 200));
        unset($r['status']);
        $this->out($r, $status);
    }

    private function out(array $data, int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
