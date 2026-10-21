<?php
declare(strict_types=1);

namespace Stationwagon;

use Throwable;

/**
 * The live studio page (/studio) and its JSON endpoints (/studio/api/<action>), for signed-in
 * staff. Rules live in Studio; the audio work in assets/studio.js.
 *
 *   GET  state                  the person's slot, the open show, running order, notes, settings
 *   GET  songs?q=               library songs for Deck B and the running order
 *   POST golive                 start the show (409 with the reason when not allowed)
 *   POST piece?live=&seq=&dur=  one MP3 piece as the request body (409 gap, 410 closed)
 *   POST end    {live}
 *   POST note   {live, kind, fields}       POST note-remove {live, id}
 *   POST order  {scope, items}
 * POSTs carry the session's form key in an X-CSRF header.
 */
final class StudioPage
{
    public function page(): void
    {
        $me = App::requireRole('dj');
        $planner = Planner::forFirstStation();
        View::render('studio', [
            'title' => 'Studio',
            'me' => $me,
            'station' => $planner->station(),
            'csrf' => Session::csrf(),
            'delay' => (int) $planner->setting('listener_delay_seconds'),
            'wide' => true,
        ]);
    }

    public function api(string $action): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        try {
            Session::start();
            $me = Auth::fromConfig()->user();
            if ($me === null) {
                $this->out(['error' => 'You’re signed out. Sign in again to use the studio.'], 401);
            }
            $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
            if ($post && !Session::checkCsrf($_SERVER['HTTP_X_CSRF'] ?? null)) {
                $this->out(['error' => 'The studio page has expired. Reload it.'], 400);
            }
            session_write_close(); // pieces, notes and state requests must never queue behind each other

            $planner = Planner::forFirstStation();
            $studio = new Studio($planner);
            $now = microtime(true);
            $in = $post && $action !== 'piece' ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : [];
            $r = match (true) {
                !$post && $action === 'state' => $this->state($studio, $planner, $me, $now),
                !$post && $action === 'songs' => ['songs' => $studio->songs((string) ($_GET['q'] ?? ''))],
                $post && $action === 'golive' => $studio->goLive($me, $now),
                $post && $action === 'rejoin' => $studio->rejoin($me, $now),
                $post && $action === 'piece' => $studio->addPiece($me, (string) ($_GET['live'] ?? ''), (int) ($_GET['seq'] ?? -1), (float) ($_GET['dur'] ?? 0), (string) file_get_contents('php://input'), $now),
                $post && $action === 'end' => $studio->endLive($me, (string) ($in['live'] ?? ''), $now),
                $post && $action === 'note' => $studio->postNote($me, (string) ($in['live'] ?? ''), (string) ($in['kind'] ?? ''), is_array($in['fields'] ?? null) ? $in['fields'] : [], $now),
                $post && $action === 'note-remove' => $studio->removeNote($me, (string) ($in['live'] ?? ''), (int) ($in['id'] ?? 0)),
                $post && $action === 'order' => $studio->saveOrder($me, (string) ($in['scope'] ?? ''), $in['items'] ?? null),
                default => ['error' => 'No such studio action.', 'status' => 404],
            };
            $status = (int) ($r['status'] ?? (isset($r['error']) ? 400 : 200));
            unset($r['status']);
            $this->out($r, $status);
        } catch (Throwable $ex) {
            error_log('Stationwagon studio: ' . $ex);
            $this->out(['error' => 'The server hit an error. Try again.'], 503);
        }
    }

    private function state(Studio $studio, Planner $planner, array $me, float $now): array
    {
        $slot = $studio->slot($me, $now);
        $open = $studio->openShow($now);
        $show = null;
        if ($open) {
            $show = ['public_id' => $open['public_id'], 'title' => $open['title'], 'dj' => $open['dj_name'], 'start' => (float) $open['start_at'],
                     'mine' => $studio->canControl($me, $open), 'own' => (int) $open['dj_user_id'] === (int) $me['id']];
            if ($show['mine']) {
                $show['notes'] = $studio->notes($open['public_id']);
            }
        }
        $s = fn(string $k) => $planner->setting($k);
        return [
            'server_now' => microtime(true),
            'me' => ['id' => $me['id'], 'name' => $me['name'], 'role' => $me['role']],
            'slot' => $slot,
            'show' => $show,
            'order' => ['scope' => $slot['scope'], 'items' => $studio->order($slot['scope']), 'editable' => $studio->mayEditOrder($me, $slot['scope'])],
            'settings' => [
                'segment_seconds' => $s('segment_seconds'), 'crossfade_seconds' => $s('crossfade_seconds'), 'skip_fade_seconds' => $s('skip_fade_seconds'),
                'live_fade_seconds' => $s('live_fade_seconds'), 'live_timeout_seconds' => $s('live_timeout_seconds'), 'listener_delay_seconds' => $s('listener_delay_seconds'),
                'fade_shape' => $s('fade_shape'), 'duck_db' => $s('duck_db'), 'live_kbps' => $s('live_kbps'), 'silence_db' => $s('silence_db'), 'rejoin_minutes' => $s('rejoin_minutes'),
            ],
            'station' => ['name' => $planner->station()['name'], 'timezone' => $planner->station()['timezone']],
            'listeners' => (new Listeners($planner->db(), $planner->station()))->now($now),
        ];
    }

    private function out(array $data, int $status): never
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}
