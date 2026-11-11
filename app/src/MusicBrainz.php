<?php
declare(strict_types=1);

namespace Stationwagon;

use RuntimeException;

/**
 * "Look up details" for a song (round 65): suggestions from MusicBrainz, the free, open music database
 * (musicbrainz.org; its core data is CC0), and its Cover Art Archive. A manager asks for it, for one song, in the
 * song details window; the answer is only ever shown as suggestions that the person ticks and keeps.
 * Nothing here runs on its own, and listeners' browsers never contact anyone: only this server does, and it sends
 * only what was typed in the window (a title, an artist, an ISRC, a length).
 *
 * The public service allows about one request a second from an address and wants a User-Agent that says who asks
 * and how to reach them: requests are spaced out (a lock file in storage/cache/mb) and every answer is kept for 30
 * days in that folder, so looking the same song up again, or a busy shared server, costs nothing. A lookup is
 * about four requests: the search, the recording, the work it is a performance of (the writers, the publishers
 * and the ISWC) and the release (the album, the year, the label, the track number, whether it has a cover).
 */
final class MusicBrainz
{
    public const BASE = 'https://musicbrainz.org/ws/2/';
    public const COVERS = 'https://coverartarchive.org/release/';
    public const MIN_GAP_SECONDS = 1.1;
    public const KEEP_DAYS = 30;

    /** @var (callable(string, string): array{code:int, body:string})|null */
    private $transport;

    /** @param ?callable(string, string): array{code:int, body:string} $transport (url, user agent) => answer; the tests give their own */
    public function __construct(private readonly string $root, private readonly string $userAgent, ?callable $transport = null)
    {
        $this->transport = $transport;
    }

    /** MusicBrainz asks who is calling: the program, its version and how to reach the station (its address, or its privacy email). */
    public static function userAgent(array $station, string $version, string $address): string
    {
        $contact = StationSettings::privacyEmail($station);
        return 'Stationwagon/' . preg_replace('/[^0-9A-Za-z.\-]/', '', $version) . ' ( ' . ($contact !== '' ? $contact : $address) . ' )';
    }

    // ---- Searching -------------------------------------------------------------------------------------

    /**
     * Recordings that may be this song, best first. With an ISRC (when it looks like one) the answer is the recordings
     * that carry it; otherwise a search by title and artist. $seconds (the song's length) tells a likely match from a
     * doubtful one: the same recording is within a few seconds.
     * @return list<array<string,mixed>>
     */
    public function search(string $title, string $artist = '', string $isrc = '', ?float $seconds = null): array
    {
        $title = trim($title);
        $artist = trim($artist);
        $isrc = self::cleanIsrc($isrc);
        if ($title === '' && $isrc === null) {
            throw new RuntimeException('Give the song a title (or an ISRC) first.');
        }
        $rows = [];
        $byIsrc = false;
        if ($isrc !== null) {
            $j = $this->get(self::BASE . 'isrc/' . $isrc . '?inc=artist-credits+releases&fmt=json');
            foreach ($j['recordings'] ?? [] as $r) {
                $rows[] = $r + ['score' => 100];
            }
            $byIsrc = $rows !== [];
        }
        if (!$rows && $title !== '') {
            $query = 'recording:"' . self::lucene($title) . '"' . ($artist !== '' ? ' AND artist:"' . self::lucene($artist) . '"' : '');
            $j = $this->get(self::BASE . 'recording?query=' . rawurlencode($query) . '&limit=8&fmt=json');
            $rows = $j['recordings'] ?? [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::candidate($r, $seconds, $byIsrc);
        }
        usort($out, fn($a, $b) => [$b['rank'], $b['score']] <=> [$a['rank'], $a['score']]);
        return array_slice($out, 0, 6);
    }

    /** One search hit as the window shows it. */
    private static function candidate(array $r, ?float $seconds, bool $byIsrc): array
    {
        $length = isset($r['length']) ? round((float) $r['length'] / 1000, 1) : null;
        $diff = $seconds !== null && $length !== null ? round(abs($length - $seconds), 1) : null;
        $score = (int) ($r['score'] ?? 0);
        $releases = [];
        foreach ($r['releases'] ?? [] as $rel) {
            $releases[] = ['mbid' => (string) $rel['id'], 'title' => (string) ($rel['title'] ?? ''), 'date' => (string) ($rel['date'] ?? ''), 'status' => (string) ($rel['status'] ?? ''), 'country' => (string) ($rel['country'] ?? '')];
        }
        $close = $diff !== null && $diff <= 3.0;
        return [
            'mbid' => (string) $r['id'],
            'title' => (string) ($r['title'] ?? ''),
            'artist' => self::credit($r['artist-credit'] ?? []),
            'score' => $score,
            'seconds' => $length,
            'diff' => $diff,
            // likely: a strong match whose length agrees (or an ISRC, which names one recording); otherwise check it
            'confidence' => ($byIsrc || $score >= 95) && ($diff === null ? $byIsrc : $close) ? 'likely' : 'check',
            'year' => substr((string) ($r['first-release-date'] ?? ''), 0, 4),
            'releases' => $releases,
            'rank' => ($close ? 1000 : 0) + $score,
        ];
    }

    // ---- The details of one recording -------------------------------------------------------------------

    /**
     * What MusicBrainz knows about a recording, as suggestions: the album (the release: $release, or the best one), the
     * year, the track number, the label, the ISRC and, from the work the recording is a performance of, the
     * writers, lyricists, publishers and ISWC. A work that MusicBrainz doesn't link to the recording is only offered
     * as a guess (work.linked false), when its title matches exactly.
     * @return array<string,mixed>
     */
    public function details(string $recording, ?string $release = null, string $title = '', string $artist = ''): array
    {
        $rec = $this->get(self::BASE . 'recording/' . self::mbid($recording) . '?inc=isrcs+work-rels+releases+artist-credits&fmt=json');
        $recTitle = (string) ($rec['title'] ?? $title);
        $recArtist = self::credit($rec['artist-credit'] ?? []);
        $options = self::releaseOptions($rec['releases'] ?? [], $recArtist);
        $chosen = $release !== null && $release !== '' ? self::mbid($release) : ($options[0]['mbid'] ?? null);

        $out = [
            'recording' => ['mbid' => (string) $rec['id'], 'title' => $recTitle, 'artist' => $recArtist, 'url' => 'https://musicbrainz.org/recording/' . $rec['id']],
            'isrcs' => array_values(array_map('strval', $rec['isrcs'] ?? [])),
            'isrc' => (string) (($rec['isrcs'][0] ?? '')),
            'releases' => $options, 'release' => null, 'album' => '', 'year' => '', 'track' => '', 'label' => '', 'catalog' => '', 'cover' => false,
            'work' => null, 'iswc' => '', 'writers' => [], 'lyricists' => [], 'publishers' => [],
        ];

        if ($chosen !== null) {
            $rel = $this->get(self::BASE . 'release/' . $chosen . '?inc=labels+recordings&fmt=json');
            $date = (string) ($rel['date'] ?? '');
            $out['release'] = ['mbid' => (string) $rel['id'], 'title' => (string) ($rel['title'] ?? ''), 'date' => $date, 'country' => (string) ($rel['country'] ?? ''), 'url' => 'https://musicbrainz.org/release/' . $rel['id']];
            $out['album'] = (string) ($rel['title'] ?? '');
            $out['year'] = preg_match('/^\d{4}/', $date, $y) ? $y[0] : '';
            $out['cover'] = !empty($rel['cover-art-archive']['front']);
            foreach ($rel['label-info'] ?? [] as $li) {
                if (isset($li['label']['name'])) {
                    $out['label'] = (string) $li['label']['name'];
                    $out['catalog'] = (string) ($li['catalog-number'] ?? '');
                    break;
                }
            }
            foreach ($rel['media'] ?? [] as $medium) {
                foreach ($medium['tracks'] ?? [] as $t) {
                    if (($t['recording']['id'] ?? '') === $rec['id']) {
                        $out['track'] = (string) ($t['number'] ?? $t['position'] ?? '') . (isset($medium['track-count']) && count($rel['media']) === 1 ? '/' . $medium['track-count'] : '');
                        break 2;
                    }
                }
            }
        }

        $workId = null;
        $linked = true;
        foreach ($rec['relations'] ?? [] as $rl) {
            if (($rl['target-type'] ?? '') === 'work' && isset($rl['work']['id'])) {
                $workId = (string) $rl['work']['id'];
                break;
            }
        }
        if ($workId === null && $recTitle !== '') {
            // not linked: the work of the same name, if there is exactly one that agrees on its title, as a guess to check
            $j = $this->get(self::BASE . 'work?query=' . rawurlencode('work:"' . self::lucene($recTitle) . '"') . '&limit=5&fmt=json');
            $same = array_values(array_filter($j['works'] ?? [], fn($w) => (int) ($w['score'] ?? 0) >= 95 && mb_strtolower((string) ($w['title'] ?? '')) === mb_strtolower($recTitle) && !empty($w['iswcs'])));
            if (count($same) >= 1) {
                $workId = (string) $same[0]['id'];
                $linked = false;
            }
        }
        if ($workId !== null) {
            $w = $this->get(self::BASE . 'work/' . $workId . '?inc=artist-rels+label-rels&fmt=json');
            $out['work'] = ['mbid' => $workId, 'title' => (string) ($w['title'] ?? ''), 'linked' => $linked, 'url' => 'https://musicbrainz.org/work/' . $workId];
            $out['iswc'] = (string) (($w['iswcs'][0] ?? ''));
            foreach ($w['relations'] ?? [] as $rl) {
                $type = (string) ($rl['type'] ?? '');
                $name = (string) ($rl['artist']['name'] ?? $rl['label']['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                if (in_array($type, ['writer', 'composer'], true)) {
                    $out['writers'][] = $name;
                } elseif (in_array($type, ['lyricist', 'librettist'], true)) {
                    $out['lyricists'][] = $name;
                } elseif ($type === 'publishing') {
                    $out['publishers'][] = $name;
                }
            }
            foreach (['writers', 'lyricists', 'publishers'] as $k) {
                $out[$k] = array_values(array_unique($out[$k]));
            }
        }
        return $out;
    }

    /**
     * The releases a recording is on, best first: official ones, by the same artist (not a "Various Artists" compilation),
     * with a date, the earliest first.
     * @return list<array{mbid:string,title:string,date:string,country:string,status:string}>
     */
    private static function releaseOptions(array $releases, string $artist): array
    {
        $rows = [];
        foreach ($releases as $r) {
            $by = self::credit($r['artist-credit'] ?? []);
            $rows[] = ['mbid' => (string) $r['id'], 'title' => (string) ($r['title'] ?? ''), 'date' => (string) ($r['date'] ?? ''), 'country' => (string) ($r['country'] ?? ''), 'status' => (string) ($r['status'] ?? ''),
                'rank' => (($r['status'] ?? '') === 'Official' ? 0 : 4) + ($by !== '' && mb_strtolower($by) !== mb_strtolower($artist) ? 2 : 0) + (preg_match('/various/i', $by) ? 2 : 0) + (($r['date'] ?? '') === '' ? 1 : 0)];
        }
        usort($rows, fn($a, $b) => [$a['rank'], $a['date'] === '' ? '9' : $a['date']] <=> [$b['rank'], $b['date'] === '' ? '9' : $b['date']]);
        return array_map(fn($r) => array_diff_key($r, ['rank' => 1]), array_slice($rows, 0, 8));
    }

    // ---- Cover art -------------------------------------------------------------------------------------

    /**
     * The release's front cover (500 px) from the Cover Art Archive, as picture bytes, or null when it has none.
     * Not kept here: the person chooses it, and it is saved with the song.
     */
    public function cover(string $release): ?string
    {
        $url = self::COVERS . self::mbid($release) . '/front-500';
        $this->throttle();
        $a = $this->fetch($url, true);
        if ($a['code'] !== 200 || $a['body'] === '' || strlen($a['body']) > 3 * 1024 * 1024) {
            return null;
        }
        $info = @getimagesizefromstring($a['body']);
        return $info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) ? $a['body'] : null;
    }

    // ---- Helpers ---------------------------------------------------------------------------------------

    /** A MusicBrainz id (a UUID), or an exception. */
    public static function mbid(string $id): string
    {
        $id = strtolower(trim($id));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            throw new RuntimeException('That isn’t a MusicBrainz id.');
        }
        return $id;
    }

    /** An ISRC without its dashes and spaces in capitals (CC-XXX-YY-NNNNN), or null when it isn't one. */
    public static function cleanIsrc(string $isrc): ?string
    {
        $isrc = strtoupper((string) preg_replace('/[\s\-]/', '', $isrc));
        return preg_match('/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/', $isrc) === 1 ? $isrc : null;
    }

    /** A phrase for a search query, with the characters the query language treats as its own made plain. */
    public static function lucene(string $s): string
    {
        return (string) preg_replace('/([+\-&|!(){}\[\]^"~*?:\\\\\/])/', '\\\\$1', $s);
    }

    /** "Cory Asbury" from an artist-credit list. */
    private static function credit(array $credit): string
    {
        $out = '';
        foreach ($credit as $c) {
            $out .= (string) ($c['name'] ?? $c['artist']['name'] ?? '') . (string) ($c['joinphrase'] ?? '');
        }
        return trim($out);
    }

    // ---- Talking to MusicBrainz, with a cache and the pause between requests ------------------------------------

    /** The answer for $url as data: from the cache when it has it (30 days), else asked for, after the pause. */
    private function get(string $url): array
    {
        $file = $this->cacheFile($url);
        if (is_file($file) && filemtime($file) > time() - self::KEEP_DAYS * 86400) {
            $kept = json_decode((string) file_get_contents($file), true);
            if (is_array($kept) && isset($kept['code'], $kept['body'])) {
                return self::decode((int) $kept['code'], (string) $kept['body']);
            }
        }
        $this->throttle();
        $a = $this->fetch($url);
        if (in_array($a['code'], [200, 404], true)) {
            $this->keep($url, $a['code'], $a['body']);
        }
        return self::decode($a['code'], $a['body']);
    }

    private static function decode(int $code, string $body): array
    {
        if ($code === 404) {
            return [];
        }
        if ($code === 503 || $code === 429) {
            throw new RuntimeException('MusicBrainz is busy (it allows about one request a second). Try again in a minute.');
        }
        $j = json_decode($body, true);
        if ($code !== 200 || !is_array($j)) {
            throw new RuntimeException($code === 0 ? 'MusicBrainz didn’t answer. Check that the server can reach the internet, then try again.' : 'MusicBrainz gave an answer this server couldn’t read (' . $code . ').');
        }
        return $j;
    }

    /** @return array{code:int,body:string} */
    private function fetch(string $url, bool $follow = false): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url, $this->userAgent);
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_MAXREDIRS => 3,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => $this->userAgent, CURLOPT_MAXFILESIZE => 4 * 1024 * 1024,
                CURLOPT_HTTPHEADER => ['Accept: ' . ($follow ? 'image/*' : 'application/json')]]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ['code' => is_string($body) ? $code : 0, 'body' => is_string($body) ? $body : ''];
        }
        $ctx = stream_context_create(['http' => ['timeout' => 12, 'user_agent' => $this->userAgent, 'ignore_errors' => true, 'follow_location' => $follow ? 1 : 0, 'header' => 'Accept: ' . ($follow ? 'image/*' : 'application/json')]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) {
                $code = (int) $m[1];
            }
        }
        return ['code' => is_string($body) ? $code : 0, 'body' => is_string($body) ? $body : ''];
    }

    /** Wait until at least MIN_GAP_SECONDS after the last request any visit made (a lock file keeps visits in turn). */
    private function throttle(): void
    {
        $dir = $this->dir();
        $fh = $dir !== null ? @fopen("$dir/last", 'c+') : false;
        if ($fh === false) {
            return;
        }
        flock($fh, LOCK_EX);
        $last = (float) trim((string) stream_get_contents($fh));
        $wait = $last + self::MIN_GAP_SECONDS - microtime(true);
        if ($wait > 0 && $wait < 10) {
            usleep((int) ($wait * 1_000_000));
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, sprintf('%.3f', microtime(true)));
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    private function dir(): ?string
    {
        $dir = $this->root . '/storage/cache/mb';
        return is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir) ? $dir : null;
    }

    private function cacheFile(string $url): string
    {
        return ($this->dir() ?? sys_get_temp_dir()) . '/' . sha1($url) . '.json';
    }

    private function keep(string $url, int $code, string $body): void
    {
        @file_put_contents($this->cacheFile($url), json_encode(['url' => $url, 'code' => $code, 'body' => $body]), LOCK_EX);
        if (random_int(1, 40) === 1) { // now and then, the answers nobody asked for in a while go
            foreach (glob(($this->dir() ?? '') . '/*.json') ?: [] as $old) {
                if (filemtime($old) < time() - 2 * self::KEEP_DAYS * 86400) {
                    @unlink($old);
                }
            }
        }
    }

    /** Put an answer in the cache as if it had been asked for (the tests do, so they never reach the internet). */
    public function seed(string $url, string $body, int $code = 200): void
    {
        $this->keep($url, $code, $body);
    }
}
