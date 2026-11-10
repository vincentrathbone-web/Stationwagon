<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * A song's copyright details (round 45): who owns it, the year, the licence, the publishing house,
 * the songwriters, and free notes (links to YouTube, the artist's page…). Stored as JSON in
 * `tracks.rights`; a song with any of it gets the © badge in the library and the line under its
 * title in the players. The notes are rich text of one kind only: text, line breaks and links, so
 * everything pasted or posted is cleaned here, on the server, whatever the browser sent.
 *
 * Round 56 (goal 10, the upload window) adds what radio returns (SAMRO, PRS) and distributors ask for,
 * in three groups: the recording (track number, record label, ISRC, the ℗ owner), the composition
 * (lyricists, ISWC; the songwriters, publisher and © owner were there) and the licence (its type in
 * `licence`, a number or reference such as a CCLI song number, a link, the date it runs to, and its
 * terms as plain text). All of it is public, like the rest (the user's choice).
 */
final class Rights
{
    public const FIELDS = ['owner', 'year', 'licence', 'publisher', 'writers',
        'track', 'label', 'isrc', 'pline', 'lyricists', 'iswc', 'licence_ref', 'licence_url', 'licence_until'];
    private const LIMITS = ['owner' => 200, 'year' => 20, 'licence' => 200, 'publisher' => 200, 'writers' => 500,
        'track' => 10, 'label' => 200, 'isrc' => 20, 'pline' => 200, 'lyricists' => 500, 'iswc' => 20,
        'licence_ref' => 100, 'licence_url' => 500, 'licence_until' => 10];
    private const NOTES_TEXT = 4000;
    /** The licence terms: plain text with its line breaks, up to this many characters. */
    public const TERMS_TEXT = 20000;

    /**
     * What's wrong with posted details, or null: a link that isn't http(s), a date that isn't one,
     * an ISRC or ISWC that isn't one (spaces and dashes are allowed in both).
     */
    public static function problem(array $in): ?string
    {
        $v = fn(string $k) => trim((string) ($in[$k] ?? ''));
        if ($v('licence_url') !== '' && self::safeHref($v('licence_url')) === null || str_starts_with(strtolower($v('licence_url')), 'mailto:')) {
            return 'The licence link must be a web address starting with http:// or https://.';
        }
        if ($v('licence_until') !== '' && self::date($v('licence_until')) === null) {
            return 'The licence’s end date must be a date, like 2027-12-31.';
        }
        if ($v('isrc') !== '' && !preg_match('/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/', self::code($v('isrc')))) {
            return 'An ISRC has 12 characters, like ZA-ABC-24-00001.';
        }
        if ($v('iswc') !== '' && !preg_match('/^T\d{10}$/', self::code($v('iswc')))) {
            return 'An ISWC is a T and 10 digits, like T-034.524.680-1.';
        }
        return null;
    }

    /** A code as typed (ZA-ABC-24-00001, T-034.524.680-1) without its dashes, dots and spaces, in capitals. */
    private static function code(string $s): string
    {
        return strtoupper((string) preg_replace('/[\s.\-]+/', '', $s));
    }

    /** A date as YYYY-MM-DD, or null. */
    private static function date(string $s): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        return $d !== false && $d->format('Y-m-d') === $s ? $s : null;
    }

    /** The details from a posted form (or decoded JSON): trimmed, limited, the notes cleaned. Null when nothing is set. */
    public static function normalize(array $in): ?array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $v = trim((string) preg_replace('/\s+/u', ' ', (string) ($in[$f] ?? '')));
            $out[$f] = mb_substr($v, 0, self::LIMITS[$f]);
        }
        // the codes kept in their usual written form; a link, date or code that isn't one is dropped
        // (forms check first with problem(), so this only meets old or hand-made data)
        $isrc = self::code($out['isrc']);
        $out['isrc'] = preg_match('/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/', $isrc) ? substr($isrc, 0, 2) . '-' . substr($isrc, 2, 3) . '-' . substr($isrc, 5, 2) . '-' . substr($isrc, 7) : '';
        $iswc = self::code($out['iswc']);
        $out['iswc'] = preg_match('/^T\d{10}$/', $iswc) ? 'T-' . substr($iswc, 1, 3) . '.' . substr($iswc, 4, 3) . '.' . substr($iswc, 7, 3) . '-' . substr($iswc, 10) : '';
        $out['licence_url'] = self::safeHref($out['licence_url']) !== null && !str_starts_with(strtolower($out['licence_url']), 'mailto:') ? $out['licence_url'] : '';
        $out['licence_until'] = self::date($out['licence_until']) ?? '';
        $out['terms'] = self::plain((string) ($in['terms'] ?? ''), self::TERMS_TEXT);
        $out['notes'] = self::cleanHtml((string) ($in['notes'] ?? ''));
        return array_filter($out, fn($v) => $v !== '') ? $out : null;
    }

    /** Plain text with its line breaks (at most two blank lines), control characters dropped, limited. */
    private static function plain(string $s, int $limit): string
    {
        $s = str_replace(["
", ""], "
", $s);
        $s = (string) preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/u', '', $s);
        $s = (string) preg_replace('/[ 	]+
/', "
", $s);
        $s = trim((string) preg_replace('/
{3,}/', "

", $s));
        return mb_substr($s, 0, $limit);
    }

    /** Decode what the column holds. */
    public static function decode(?string $json): ?array
    {
        $a = $json === null || $json === '' ? null : json_decode($json, true);
        return is_array($a) ? self::normalize($a) : null;
    }

    /** The column's value for these details. */
    public static function encode(?array $rights): ?string
    {
        return $rights === null ? null : json_encode($rights, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** One line for under a song's title: "© 2024 Owner · Written by A, B · Licence". */
    public static function line(?array $r): string
    {
        if ($r === null) {
            return '';
        }
        $holder = $r['owner'] !== '' ? $r['owner'] : $r['publisher'];
        $parts = [];
        if ($holder !== '' || $r['year'] !== '') {
            $parts[] = implode(' ', array_filter(['©', $r['year'], $holder], fn($v) => $v !== ''));
        }
        if ($r['writers'] !== '') {
            $parts[] = 'Written by ' . $r['writers'];
        }
        if ($r['licence'] !== '') {
            $parts[] = $r['licence'];
        }
        if (!$parts && $r['publisher'] !== '') {
            $parts[] = 'Published by ' . $r['publisher'];
        }
        $line = $parts ? implode(' · ', $parts) : '© See details';
        return mb_strlen($line) > 200 ? mb_substr($line, 0, 199) . '…' : $line;
    }

    /** What listeners get with a song: the line and the details; null when it has no copyright details. */
    public static function forListeners(?string $json): ?array
    {
        $r = self::decode($json);
        return $r === null ? null : ['line' => self::line($r)] + $r;
    }

    /**
     * Rich text reduced to text, line breaks and links (http, https and mailto only). Pasted
     * fonts, sizes, colours, scripts, styles and every other tag are dropped, their text kept.
     */
    public static function cleanHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $body = $doc->getElementsByTagName('body')->item(0);
        $out = $body ? self::walk($body) : '';
        $out = preg_replace('/(?:<br>\s*){3,}/', '<br><br>', $out) ?? $out;
        $out = preg_replace('/^(?:\s|<br>)+|(?:\s|<br>)+$/', '', $out) ?? $out;
        // the limit counts text, not markup
        if (mb_strlen(self::toText($out)) > self::NOTES_TEXT) {
            $out = htmlspecialchars(mb_substr(self::toText($out), 0, self::NOTES_TEXT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $out = str_replace("\n", '<br>', $out);
        }
        return $out;
    }

    /** The cleaned notes as plain text, line breaks kept. */
    public static function toText(string $clean): string
    {
        $t = preg_replace('#<br\s*/?>#i', "\n", $clean) ?? $clean;
        return trim(html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private const BLOCKS = ['p', 'div', 'li', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'tr', 'blockquote', 'pre', 'table', 'section', 'article'];
    private const DROP = ['script', 'style', 'head', 'title', 'meta', 'link', 'object', 'iframe', 'template', 'noscript'];

    private static function walk(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $c) {
            if ($c instanceof \DOMText) {
                // as in HTML, a line break inside text is only whitespace; real ones are <br> (the editor makes them)
                $t = preg_replace('/[ \t\r\n\x{00A0}]+/u', ' ', $c->nodeValue ?? '') ?? '';
                $out .= htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } elseif ($c instanceof \DOMElement) {
                $name = strtolower($c->nodeName);
                if (in_array($name, self::DROP, true)) {
                    continue;
                }
                if ($name === 'br') {
                    $out .= '<br>';
                } elseif ($name === 'a') {
                    $inner = self::walk($c);
                    $href = trim($c->getAttribute('href'));
                    $out .= self::safeHref($href) !== null
                        ? '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . $inner . '</a>'
                        : $inner;
                } elseif (in_array($name, self::BLOCKS, true)) {
                    $inner = self::walk($c);
                    $out .= ($out !== '' && !str_ends_with($out, '<br>') ? '<br>' : '') . $inner . ($inner !== '' ? '<br>' : '');
                } else {
                    $out .= self::walk($c);
                }
            }
        }
        return $out;
    }

    /** The link's address when it's one we keep: http, https or mailto, no control characters. */
    public static function safeHref(string $href): ?string
    {
        if ($href === '' || preg_match('/[\x00-\x1f\x7f\s<>"\']/u', $href) || strlen($href) > 500) {
            return null;
        }
        return preg_match('#^(https?://[^/]|mailto:[^@]+@)#i', $href) === 1 ? $href : null;
    }
}
