<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/library.php';

/*
 * The timeline is the whole "radio server". Nothing plays on the server: the timeline says
 * which song (or live block) covers which stretch of stream time. Every listener derives what to
 * play from it, so they all hear the same thing at the same moment.
 *
 * Entry (song):  n, type=song, file, title, start, play, full, fade_out, seq, pieces[[off,len,dur]]
 * Entry (live):  n, type=live, id, start, seq, pieces[[name,dur]], open, last_piece_at, dj
 *
 *   start     stream time (unix seconds) the entry begins
 *   play      seconds of the song that belong to the stream (pieces end on this boundary)
 *   full      the song's whole length; the two-deck player lets the tail ring out under the fade
 *   seq       HLS media sequence number of the entry's first piece (continuous across entries)
 *   n         entry counter, used as the HLS discontinuity sequence
 */

function sw_timeline_path(): string
{
    return sw_config()['data_dir'] . '/timeline.json';
}

function sw_entry_end(array $e): float
{
    if ($e['type'] === 'live') {
        return $e['start'] + array_sum(array_column($e['pieces'], 1));
    }
    return $e['start'] + $e['play'];
}

function sw_entry_piece_count(array $e): int
{
    return count($e['pieces']);
}

/** Load the timeline, planning ahead (under a lock) only when it actually needs it. */
function sw_timeline(float $now): array
{
    $tl = sw_read_json(sw_timeline_path(), ['entries' => [], 'recent' => []]);
    if (!sw_timeline_needs_plan($tl, $now)) {
        return $tl;
    }
    return sw_timeline_mutate($now, function (array &$tl) {
        // sw_timeline_mutate plans after every change; nothing else to do
    });
}

function sw_timeline_needs_plan(array $tl, float $now): bool
{
    $config = sw_config();
    if (!$tl['entries']) {
        return true;
    }
    $last = end($tl['entries']);
    if ($last['type'] === 'live' && $last['open']) {
        return sw_live_timed_out($last, $now);
    }
    return sw_entry_end($last) < $now + $config['plan_ahead_seconds'] / 2;
}

function sw_live_timed_out(array $live, float $now): bool
{
    return $now - $live['last_piece_at'] > sw_config()['live_timeout_seconds'];
}

/** Lock, reload, apply $change, re-plan, save. Returns the new timeline. */
function sw_timeline_mutate(float $now, callable $change): array
{
    return sw_with_lock('timeline', function () use ($now, $change) {
        $tl = sw_read_json(sw_timeline_path(), ['entries' => [], 'recent' => []]);
        $result = $change($tl);
        sw_timeline_plan($tl, $now);
        sw_write_json(sw_timeline_path(), $tl);
        $tl['result'] = $result;
        return $tl;
    });
}

function sw_timeline_plan(array &$tl, float $now): void
{
    $config = sw_config();

    // Drop entries that finished more than 10 minutes ago (keep the last one for numbering)
    while (count($tl['entries']) > 1 && sw_entry_end($tl['entries'][0]) < $now - 600) {
        array_shift($tl['entries']);
    }

    $lastIndex = count($tl['entries']) - 1;
    if ($lastIndex >= 0) {
        $last = &$tl['entries'][$lastIndex];
        if ($last['type'] === 'live' && $last['open']) {
            if (!sw_live_timed_out($last, $now)) {
                return; // a live show is running: AutoDJ waits
            }
            $last['open'] = false;
            $last['closed_reason'] = 'timeout';
        }
        unset($last);
    }

    $library = sw_library();
    if (!$library) {
        return;
    }
    while (!$tl['entries'] || sw_entry_end(end($tl['entries'])) < $now + $config['plan_ahead_seconds']) {
        sw_timeline_append_song($tl, $library, $now);
    }
}

function sw_timeline_append_song(array &$tl, array $library, float $now): void
{
    $config = sw_config();
    $recent = $tl['recent'] ?? [];
    $avoid = array_slice($recent, -min($config['recent_tracks'], max(0, count($library) - 1)));
    $choices = array_values(array_filter($library, fn($t) => !in_array($t['id'], $avoid, true)));
    $track = $choices[random_int(0, count($choices) - 1)];

    // The stream moves on at the last piece boundary that still leaves crossfade_seconds of
    // the song, so the two-deck player always has a full-length fade (it keeps the tail playing
    // under the next song). HLS listeners jump straight on and miss the last 6–12 s instead.
    $target = max(0.0, $track['duration'] - $config['crossfade_seconds']);
    $pieces = [];
    $play = 0.0;
    foreach ($track['pieces'] as $piece) {
        if ($pieces && $play + $piece[2] > $target + 1e-6) {
            break;
        }
        $pieces[] = $piece;
        $play += $piece[2];
    }

    $prev = $tl['entries'] ? end($tl['entries']) : null;
    $tl['entries'][] = [
        'n'        => $prev ? $prev['n'] + 1 : 0,
        'type'     => 'song',
        'id'       => $track['id'],
        'file'     => $track['file'],
        'title'    => $track['title'],
        'start'    => $prev ? sw_entry_end($prev) : floor($now * 1000) / 1000,
        'play'     => round($play, 4),
        'full'     => $track['duration'],
        'fade_out' => $config['crossfade_seconds'],
        'seq'      => $prev ? $prev['seq'] + sw_entry_piece_count($prev) : 0,
        'pieces'   => $pieces,
    ];
    $recent[] = $track['id'];
    $tl['recent'] = array_slice($recent, -20);
}

/**
 * Cut the song playing at $now on the first piece boundary at least cut_lead_seconds ahead and
 * drop everything planned after it. Returns the cut time (stream time), or null if a live
 * block is running.
 */
function sw_timeline_cut(array &$tl, float $now, float $fade): ?float
{
    $lead = sw_config()['cut_lead_seconds'];
    foreach ($tl['entries'] as $i => &$e) {
        if (sw_entry_end($e) <= $now) {
            continue;
        }
        if ($e['type'] === 'live') {
            return null;
        }
        $t = $e['start'];
        $keep = [];
        foreach ($e['pieces'] as $piece) {
            if ($t >= $now + $lead && $keep) {
                break;
            }
            $keep[] = $piece;
            $t += $piece[2];
        }
        $e['pieces'] = $keep;
        $e['play'] = round($t - $e['start'], 4);
        $e['fade_out'] = $fade;
        $e['cut'] = true;
        array_splice($tl['entries'], $i + 1);
        // "recent" still lists the songs that were planned after the cut; the next pick must
        // avoid what actually played, or the cut song can come straight back
        $songs = array_filter($tl['entries'], fn($x) => $x['type'] === 'song');
        $tl['recent'] = array_slice(array_column($songs, 'id'), -20);
        return $t;
    }
    return null;
}

/**
 * Skip the song. $heard is the stream time the person pressing Skip is hearing (an HLS listener
 * is about 18 s behind the live edge). If that song already ends before the cut could land, the
 * skip does nothing rather than cutting the next song, which they haven't heard yet.
 */
function sw_timeline_skip(float $now, ?float $heard = null): array
{
    return sw_timeline_mutate($now, function (array &$tl) use ($now, $heard) {
        if ($heard !== null && $heard < $now) {
            foreach ($tl['entries'] as $e) {
                if ($e['start'] <= $heard && $heard < sw_entry_end($e)) {
                    if (sw_entry_end($e) <= $now + sw_config()['cut_lead_seconds']) {
                        return ['cut_at' => null, 'reason' => 'That song is already ending; the next one has started at the live edge.'];
                    }
                    break;
                }
            }
        }
        return ['cut_at' => sw_timeline_cut($tl, $now, sw_config()['skip_fade_seconds'])];
    });
}

/** Start a live block at the next piece boundary. The studio starts sending audio at that time. */
function sw_timeline_go_live(float $now, string $dj): array
{
    return sw_timeline_mutate($now, function (array &$tl) use ($now, $dj) {
        $config = sw_config();
        $last = end($tl['entries']);
        if ($last && $last['type'] === 'live' && $last['open']) {
            return ['error' => 'A live show is already on air', 'live' => $last];
        }
        $cutAt = sw_timeline_cut($tl, $now, $config['crossfade_seconds']);
        if ($cutAt === null) {
            return ['error' => 'Nothing to cut. Add MP3s to prototypes/media first.'];
        }
        $prev = end($tl['entries']);
        $live = [
            'n'             => $prev['n'] + 1,
            'type'          => 'live',
            'id'            => 'live-' . gmdate('Ymd-His', (int) $cutAt),
            'dj'            => $dj,
            'start'         => $cutAt,
            'seq'           => $prev['seq'] + sw_entry_piece_count($prev),
            'pieces'        => [],
            'open'          => true,
            // the first piece can only arrive one piece length after the start
            'last_piece_at' => $cutAt + $config['segment_seconds'] + 2,
        ];
        $tl['entries'][] = $live;
        @mkdir($config['data_dir'] . '/live/' . $live['id'], 0775, true);
        return ['live' => $live, 'handover' => $prev];
    });
}

function sw_timeline_add_piece(float $now, string $liveId, int $seq, float $duration, string $bytes): array
{
    return sw_timeline_mutate($now, function (array &$tl) use ($now, $liveId, $seq, $duration, $bytes) {
        $i = count($tl['entries']) - 1;
        if ($i < 0) {
            return ['error' => 'This live show is not on air', 'status' => 410];
        }
        $live = &$tl['entries'][$i];
        if ($live['type'] !== 'live' || $live['id'] !== $liveId) {
            return ['error' => 'This live show is not on air', 'status' => 410];
        }
        if (!$live['open']) {
            return ['error' => 'The server ended this show: ' . ($live['closed_reason'] ?? 'closed'), 'status' => 410];
        }
        $expected = count($live['pieces']);
        if ($seq < $expected) {
            return ['ok' => true, 'duplicate' => true, 'expected' => $expected];
        }
        if ($seq > $expected) {
            return ['error' => 'Missing earlier piece', 'expected' => $expected, 'status' => 409];
        }
        $name = sprintf('%05d.mp3', $seq);
        file_put_contents(sw_config()['data_dir'] . "/live/$liveId/$name", $bytes);
        $live['pieces'][] = [$name, round($duration, 4)];
        $live['last_piece_at'] = $now;
        return ['ok' => true, 'expected' => $expected + 1, 'stream_end' => sw_entry_end($live)];
    });
}

function sw_timeline_end_live(float $now, string $liveId): array
{
    return sw_timeline_mutate($now, function (array &$tl) use ($liveId) {
        foreach ($tl['entries'] as &$e) {
            if ($e['type'] === 'live' && $e['id'] === $liveId && $e['open']) {
                $e['open'] = false;
                $e['closed_reason'] = 'ended by DJ';
                return ['ended' => true, 'stream_end' => sw_entry_end($e)];
            }
        }
        return ['ended' => false];
    });
}

function sw_timeline_reset(float $now): array
{
    return sw_timeline_mutate($now, function (array &$tl) {
        $tl = ['entries' => [], 'recent' => []];
    });
}

/** Entries overlapping [$from, $to], with the extra fields players need. */
function sw_timeline_window(array $tl, float $from, float $to): array
{
    $out = [];
    foreach ($tl['entries'] as $e) {
        if (sw_entry_end($e) < $from || $e['start'] > $to) {
            if (!($e['type'] === 'live' && $e['open'] && $e['start'] <= $to)) {
                continue;
            }
        }
        $item = $e;
        $item['end'] = sw_entry_end($e);
        unset($item['pieces']);
        $item['piece_count'] = count($e['pieces']);
        $out[] = $item;
    }
    return $out;
}
