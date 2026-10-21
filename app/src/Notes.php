<?php
declare(strict_types=1);

namespace Stationwagon;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Presenter notes: verses, references, video links and short notes a presenter posts during a
 * live show, shown to listeners beside the player ("From the presenter"). Notes belong to a live
 * show; between shows listeners see the last show's notes (up to a week old). The studio posts
 * them (its endpoints come with the live studio); the player reads `forListeners()` through
 * api/notes.php whenever `rev()` (sent with every timeline) changes.
 *
 * Verse text is pasted by the presenter (the app ships no Bible text); each verse keeps its
 * version (KJV unless the presenter says otherwise) and links to that passage on Bible Gateway.
 */
final class Notes
{
    public const KINDS = ['verse', 'refs', 'video', 'note'];
    public const PER_SHOW = 50;
    public const KEEP_DAYS = 7;
    public const DEFAULT_VERSION = 'KJV';

    public function __construct(private readonly Db $db, private readonly array $station)
    {
    }

    /**
     * Check and tidy a note as the studio sends it. Fields by kind:
     *   verse: ref ("Psalm 23:1"), text (pasted), version ("KJV"), passage (what "Read …" opens; the ref by default)
     *   refs:  text (a heading, may be empty), refs (a list, or one per line)
     *   video: text (a title), url (http or https)
     *   note:  text
     * @return array{body?: array, error?: string}
     */
    public static function clean(string $kind, array $in): array
    {
        $line = fn(string $k, int $max): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', self::plain($in[$k] ?? ''))), 0, $max);
        $para = fn(string $k, int $max): string => mb_substr(trim((string) preg_replace("/[ \t]+/u", ' ', preg_replace("/\R{3,}/u", "\n\n", self::plain($in[$k] ?? '')))), 0, $max);
        switch ($kind) {
            case 'verse':
                $ref = $line('ref', 80);
                $text = $para('text', 1500);
                $version = strtoupper($line('version', 12)) ?: self::DEFAULT_VERSION;
                if ($ref === '') {
                    return ['error' => 'Say which verse it is (for example Psalm 23:1).'];
                }
                if ($text === '') {
                    return ['error' => 'Paste the verse text.'];
                }
                if (preg_match('/^[A-Z0-9-]{2,12}$/', $version) !== 1) {
                    return ['error' => 'The Bible version is a short code such as KJV or AFR53.'];
                }
                return ['body' => ['ref' => $ref, 'version' => $version, 'text' => $text, 'passage' => $line('passage', 80) ?: $ref]];
            case 'refs':
                $list = $in['refs'] ?? [];
                $list = is_array($list) ? $list : preg_split('/\R/u', (string) $list);
                $refs = array_values(array_filter(array_map(fn($r) => mb_substr(trim((string) preg_replace('/\s+/u', ' ', self::plain($r))), 0, 60), $list), fn($r) => $r !== ''));
                if (!$refs) {
                    return ['error' => 'Add at least one reference.'];
                }
                if (count($refs) > 12) {
                    return ['error' => 'Twelve references at most in one note.'];
                }
                return ['body' => ['text' => $line('text', 200), 'refs' => $refs, 'version' => strtoupper($line('version', 12)) ?: self::DEFAULT_VERSION]];
            case 'video':
                $text = $line('text', 200);
                $url = trim(self::plain($in['url'] ?? ''));
                if ($text === '') {
                    return ['error' => 'Give the video a title.'];
                }
                if (strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
                    return ['error' => 'The video address must start with http:// or https://.'];
                }
                return ['body' => ['text' => $text, 'url' => $url]];
            case 'note':
                $text = $para('text', 1000);
                return $text === '' ? ['error' => 'Write the note first.'] : ['body' => ['text' => $text]];
        }
        return ['error' => 'Unknown kind of note.'];
    }

    /** Post a note to the open live show `$publicId`. @return array{note?: array, error?: string} */
    public function post(string $publicId, ?int $userId, string $kind, array $in, ?float $now = null): array
    {
        $show = $this->show($publicId);
        if (!$show || !$show['open']) {
            return ['error' => 'Notes can only be posted while the show is on air.'];
        }
        $count = (int) $this->db->run('SELECT COUNT(*) FROM ' . $this->db->table('presenter_notes') . ' WHERE live_show_id = ? AND replaced_by IS NULL', [$show['id']])->fetchColumn();
        if ($count >= self::PER_SHOW) {
            return ['error' => 'This show already has ' . self::PER_SHOW . ' notes. Remove one first.'];
        }
        $clean = self::clean($kind, $in);
        if (isset($clean['error'])) {
            return $clean;
        }
        $id = $this->db->insert('presenter_notes', [
            'station_id' => $this->station['id'], 'live_show_id' => $show['id'], 'user_id' => $userId, 'kind' => $kind,
            'body' => json_encode($clean['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'posted_at' => gmdate('Y-m-d H:i:s', (int) floor($now ?? microtime(true))),
        ]);
        return ['note' => $this->item($this->row($id))];
    }

    /**
     * Change a note of the open show `$publicId`. It is posted again as a new note at `$now` (listeners
     * get it as new, with a toast, when they reach this moment); the old version stays in `replaced_by`
     * until then, so listeners never see the note vanish early. Same kind as before.
     * @return array{note?: array, replaces?: int, error?: string}
     */
    public function edit(string $publicId, ?int $userId, int $id, array $in, ?float $now = null): array
    {
        $now ??= microtime(true);
        $show = $this->show($publicId);
        if (!$show || !$show['open']) {
            return ['error' => 'Notes can only be changed while the show is on air.'];
        }
        $t = $this->db->table('presenter_notes');
        $old = $this->db->run("SELECT * FROM $t WHERE id = ? AND live_show_id = ? AND replaced_by IS NULL", [$id, $show['id']])->fetch();
        if (!$old) {
            return ['error' => 'That note is already gone.', 'status' => 404];
        }
        $clean = self::clean($old['kind'], $in);
        if (isset($clean['error'])) {
            return $clean;
        }
        $this->tidy($now);
        $new = $this->db->insert('presenter_notes', [
            'station_id' => $this->station['id'], 'live_show_id' => $show['id'], 'user_id' => $userId, 'kind' => $old['kind'],
            'body' => json_encode($clean['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'posted_at' => gmdate('Y-m-d H:i:s', (int) floor($now)),
        ]);
        $this->db->run("UPDATE $t SET replaced_by = ? WHERE id = ?", [$new, $id]);
        return ['note' => $this->item($this->row($new)), 'replaces' => $id];
    }

    /** Remove a note from show `$publicId` (a mistake, a wrong verse), with any older versions of it. Open or closed shows alike. */
    public function remove(string $publicId, int $id): bool
    {
        $show = $this->show($publicId);
        $t = $this->db->table('presenter_notes');
        if ($show === null || $this->db->run("DELETE FROM $t WHERE id = ? AND live_show_id = ? AND replaced_by IS NULL", [$id, $show['id']])->rowCount() !== 1) {
            return false;
        }
        for ($ids = [$id]; $ids; ) { // the versions it replaced would otherwise show again
            $ids = array_map('intval', $this->db->run("SELECT id FROM $t WHERE replaced_by IN (" . implode(',', $ids) . ')')->fetchAll(\PDO::FETCH_COLUMN));
            if ($ids) {
                $this->db->run("DELETE FROM $t WHERE id IN (" . implode(',', $ids) . ')');
            }
        }
        return true;
    }

    /** Old versions of edited notes are dropped once listeners are well past the edit (2 minutes). */
    private function tidy(float $now): void
    {
        $t = $this->db->table('presenter_notes');
        $this->db->run("DELETE o FROM $t o JOIN $t n ON n.id = o.replaced_by WHERE o.station_id = ? AND n.posted_at < ?", [$this->station['id'], gmdate('Y-m-d H:i:s', (int) floor($now) - 120)]);
    }

    /** The notes of show `$publicId`, oldest first (for the studio). */
    public function forShow(string $publicId): array
    {
        $show = $this->show($publicId);
        if (!$show) {
            return [];
        }
        $rows = $this->db->run('SELECT * FROM ' . $this->db->table('presenter_notes') . ' WHERE live_show_id = ? AND replaced_by IS NULL ORDER BY posted_at, id', [$show['id']])->fetchAll();
        return array_map(fn($r) => $this->item($r), $rows);
    }

    /**
     * Changes whenever what listeners should see changes: a note added or removed, a show opening
     * or closing, or a new day (the label moves from "This morning" to "Yesterday"). One small query.
     */
    public function rev(?float $now = null): string
    {
        $r = $this->db->run('SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS top, (SELECT COALESCE(MAX(id), 0) FROM ' . $this->db->table('live_shows') .
            ' WHERE station_id = ? AND `open` = 1) AS open_show FROM ' . $this->db->table('presenter_notes') . ' WHERE station_id = ?', [$this->station['id'], $this->station['id']])->fetch();
        return implode('.', [$r['open_show'], $r['n'], $r['top'], $this->local($now ?? microtime(true))->format('Ymd')]);
    }

    /**
     * What listeners see: the open show (once it has a note) or else the newest show with notes
     * from the last week, its notes newest first. Times are in the station's time zone; `posted`
     * (unix seconds) lets the player hold a note back until listeners, 20 s behind, reach it, and
     * `replaced_by` lets it keep an edited note's old version until they reach the edit.
     * @return array{rev: string, show: ?array, items: array}
     */
    public function forListeners(?float $now = null): array
    {
        $now ??= microtime(true);
        $t = $this->db->table('live_shows');
        $show = $this->db->run("SELECT ls.*, u.name AS dj_name
            FROM $t ls LEFT JOIN {$this->db->table('users')} u ON u.id = ls.dj_user_id
            WHERE ls.station_id = ? AND ls.start_at >= ? AND EXISTS (SELECT 1 FROM {$this->db->table('presenter_notes')} pn WHERE pn.live_show_id = ls.id)
            ORDER BY ls.start_at DESC LIMIT 1", [$this->station['id'], $now - self::KEEP_DAYS * 86400])->fetch();
        $open = $this->db->run("SELECT id FROM $t WHERE station_id = ? AND `open` = 1 LIMIT 1", [$this->station['id']])->fetchColumn();
        if (!$show || ($open && (int) $open !== (int) $show['id'])) {
            // nothing yet, or a new show is on air and hasn't posted: don't show the last show's notes under it
            return ['rev' => $this->rev($now), 'show' => null, 'items' => []];
        }
        $rows = $this->db->run('SELECT * FROM ' . $this->db->table('presenter_notes') . ' WHERE live_show_id = ? ORDER BY posted_at DESC, id DESC', [$show['id']])->fetchAll();
        $posted = array_column($rows, 'posted_at', 'id');
        $start = $this->local((float) $show['start_at']);
        $end = $this->local((float) $show['last_piece_at']);
        $live = (bool) $show['open'];
        return [
            'rev' => $this->rev($now),
            'show' => [
                'title' => $show['title'], 'dj' => $show['dj_name'], 'live' => $live, 'label' => $live ? 'Live' : $this->when($start, $now),
                'by' => implode(' · ', array_filter([$show['dj_name'], $show['title'] . ', ' . $start->format('H:i') . ($live ? '' : '–' . $end->format('H:i')), $live ? 'posting live' : null])),
            ],
            // an edited note's old version only while listeners may not have reached the edit yet
            'items' => array_values(array_map(fn($r) => $this->item($r), array_filter($rows, fn($r) => $r['replaced_by'] === null
                || (($posted[(int) $r['replaced_by']] ?? 0) >= gmdate('Y-m-d H:i:s', (int) floor($now) - 60))))),
        ];
    }

    /** A Bible Gateway link to a passage in a version. */
    public static function passageUrl(string $passage, string $version): string
    {
        return 'https://www.biblegateway.com/passage/?search=' . rawurlencode(str_replace(['–', '—'], '-', $passage)) . '&version=' . rawurlencode($version);
    }

    // ---- Inside ------------------------------------------------------------------------------

    private function item(array $r): array
    {
        $b = json_decode((string) $r['body'], true) ?: [];
        $posted = (new DateTimeImmutable($r['posted_at'], new DateTimeZone('UTC')))->getTimestamp();
        $item = ['id' => (int) $r['id'], 'kind' => $r['kind'], 'posted' => $posted, 'at' => $this->local((float) $posted)->format('H:i'), 'text' => $b['text'] ?? '',
                 'replaced_by' => isset($r['replaced_by']) ? (int) $r['replaced_by'] : null];
        if ($r['kind'] === 'verse') {
            $item += ['ref' => $b['ref'] ?? '', 'version' => $b['version'] ?? self::DEFAULT_VERSION, 'passage' => $b['passage'] ?? ($b['ref'] ?? ''),
                      'read' => self::passageUrl($b['passage'] ?? ($b['ref'] ?? ''), $b['version'] ?? self::DEFAULT_VERSION)];
        } elseif ($r['kind'] === 'refs') {
            $item['refs'] = array_map(fn($ref) => ['label' => $ref, 'url' => self::passageUrl($ref, $b['version'] ?? self::DEFAULT_VERSION)], $b['refs'] ?? []);
            $item['version'] = $b['version'] ?? self::DEFAULT_VERSION;
        } elseif ($r['kind'] === 'video') {
            $item['url'] = $b['url'] ?? '';
        }
        return $item;
    }

    private function row(int $id): array
    {
        return $this->db->run('SELECT * FROM ' . $this->db->table('presenter_notes') . ' WHERE id = ?', [$id])->fetch();
    }

    private function show(string $publicId): ?array
    {
        $row = $this->db->run('SELECT * FROM ' . $this->db->table('live_shows') . ' WHERE station_id = ? AND public_id = ?', [$this->station['id'], $publicId])->fetch();
        return $row ?: null;
    }

    /** "This morning" / "This afternoon" / "This evening", "Yesterday", or "Tue 14 Oct". */
    private function when(DateTimeImmutable $start, float $now): string
    {
        $today = $this->local($now)->format('Y-m-d');
        if ($start->format('Y-m-d') === $today) {
            $hour = (int) $start->format('G');
            return $hour < 12 ? 'This morning' : ($hour < 17 ? 'This afternoon' : 'This evening');
        }
        if ($start->format('Y-m-d') === $this->local($now - 86400)->format('Y-m-d')) {
            return 'Yesterday';
        }
        return $start->format('D j M');
    }

    private function local(float $t): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . (int) floor($t)))->setTimezone(new DateTimeZone($this->station['timezone'] ?: 'UTC'));
    }

    /** Text without control characters (keeps line breaks and tabs). */
    private static function plain(mixed $v): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', is_scalar($v) ? (string) $v : '');
    }
}
