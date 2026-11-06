<?php
declare(strict_types=1);

namespace Stationwagon;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The verse of the day (api/verse.php), for the station's apps and websites. After the way the
 * LinkFlow dashboard does it: a curated list of references, one a day by the day of the year in the
 * station's own time zone, with the text fetched on the server from a public-domain Bible
 * (bible-api.com, the World English Bible) and kept for the day, so no app calls a third party and
 * the station never hands out text it has no right to. English comes with its text; Afrikaans
 * (and any other language the station's list names) comes as the reference in that language's
 * book names with a link to read the passage in a licensed Afrikaans Bible on Bible Gateway: no
 * free API serves Afrikaans text and the usual Afrikaans Bibles are copyrighted.
 * When the text can't be fetched the answer has no text, only the reference and the link.
 */
final class Verse
{
    public const TRANSLATION = 'web';
    public const TRANSLATION_NAME = 'World English Bible';
    public const VERSIONS = ['en' => 'WEB', 'af' => 'AFR53'];
    public const RETRY_SECONDS = 300;

    /** Well-loved passages, one a day in turn (a year is a few rounds of it). */
    public const REFERENCES = [
        'Joshua 1:9', 'Psalm 23:1', 'Psalm 27:1', 'Psalm 34:18', 'Psalm 37:4',
        'Psalm 46:1', 'Psalm 55:22', 'Psalm 56:3', 'Psalm 62:1', 'Psalm 90:17',
        'Psalm 118:24', 'Psalm 119:105', 'Psalm 121:1-2', 'Psalm 138:8', 'Psalm 143:8',
        'Proverbs 3:5-6', 'Proverbs 16:3', 'Proverbs 16:9', 'Proverbs 18:10', 'Proverbs 31:25',
        'Isaiah 40:31', 'Isaiah 41:10', 'Isaiah 43:2', 'Isaiah 26:3', 'Isaiah 54:10',
        'Jeremiah 29:11', 'Lamentations 3:22-23', 'Zephaniah 3:17',
        'Matthew 6:33', 'Matthew 6:34', 'Matthew 11:28', 'Matthew 19:26',
        'Mark 11:24', 'Luke 1:37', 'John 3:16', 'John 14:27', 'John 16:33',
        'Romans 8:28', 'Romans 8:31', 'Romans 12:2', 'Romans 15:13',
        '1 Corinthians 13:4-7', '2 Corinthians 4:16-18', '2 Corinthians 5:17', '2 Corinthians 12:9',
        'Galatians 6:9', 'Ephesians 2:10', 'Ephesians 3:20', 'Philippians 4:6-7', 'Philippians 4:13',
        'Philippians 4:19', 'Colossians 3:23', '1 Thessalonians 5:16-18',
        '2 Timothy 1:7', 'Hebrews 11:1', 'Hebrews 12:1', 'James 1:2-3', 'James 1:17',
        '1 Peter 5:7', '1 John 4:18',
    ];

    /** Book names in Afrikaans (the 1933/1953 Bible's), for the books in REFERENCES and the rest. */
    public const BOOKS_AF = [
        'Genesis' => 'Genesis', 'Exodus' => 'Eksodus', 'Leviticus' => 'Levitikus', 'Numbers' => 'Numeri', 'Deuteronomy' => 'Deuteronomium',
        'Joshua' => 'Josua', 'Judges' => 'Rigters', 'Ruth' => 'Rut', '1 Samuel' => '1 Samuel', '2 Samuel' => '2 Samuel',
        '1 Kings' => '1 Konings', '2 Kings' => '2 Konings', '1 Chronicles' => '1 Kronieke', '2 Chronicles' => '2 Kronieke',
        'Ezra' => 'Esra', 'Nehemiah' => 'Nehemia', 'Esther' => 'Ester', 'Job' => 'Job', 'Psalm' => 'Psalm', 'Proverbs' => 'Spreuke',
        'Ecclesiastes' => 'Prediker', 'Song of Solomon' => 'Hooglied', 'Isaiah' => 'Jesaja', 'Jeremiah' => 'Jeremia',
        'Lamentations' => 'Klaagliedere', 'Ezekiel' => 'Esegiël', 'Daniel' => 'Daniël', 'Hosea' => 'Hosea', 'Joel' => 'Joël',
        'Amos' => 'Amos', 'Obadiah' => 'Obadja', 'Jonah' => 'Jona', 'Micah' => 'Miga', 'Nahum' => 'Nahum', 'Habakkuk' => 'Habakuk',
        'Zephaniah' => 'Sefanja', 'Haggai' => 'Haggai', 'Zechariah' => 'Sagaria', 'Malachi' => 'Maleagi',
        'Matthew' => 'Matteus', 'Mark' => 'Markus', 'Luke' => 'Lukas', 'John' => 'Johannes', 'Acts' => 'Handelinge', 'Romans' => 'Romeine',
        '1 Corinthians' => '1 Korintiërs', '2 Corinthians' => '2 Korintiërs', 'Galatians' => 'Galasiërs', 'Ephesians' => 'Efesiërs',
        'Philippians' => 'Filippense', 'Colossians' => 'Kolossense', '1 Thessalonians' => '1 Tessalonisense', '2 Thessalonians' => '2 Tessalonisense',
        '1 Timothy' => '1 Timoteus', '2 Timothy' => '2 Timoteus', 'Titus' => 'Titus', 'Philemon' => 'Filemon', 'Hebrews' => 'Hebreërs',
        'James' => 'Jakobus', '1 Peter' => '1 Petrus', '2 Peter' => '2 Petrus', '1 John' => '1 Johannes', '2 John' => '2 Johannes',
        '3 John' => '3 Johannes', 'Jude' => 'Judas', 'Revelation' => 'Openbaring',
    ];

    /** @var callable(string): ?string fetches a web address's body, or null */
    private $fetch;

    public function __construct(private readonly array $station, private readonly string $root, ?callable $fetch = null)
    {
        $this->fetch = $fetch ?? [self::class, 'httpGet'];
    }

    public static function forFirstStation(): self
    {
        return new self(Planner::forFirstStation()->station(), SW_ROOT);
    }

    /** The reference for a date (Y-m-d): the list in turn by the day of the year. */
    public static function referenceFor(string $date): string
    {
        $day = (int) (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))->format('z');
        return self::REFERENCES[$day % count(self::REFERENCES)];
    }

    /** A reference in a language's book names ("Psalm 23:1" is the same in Afrikaans, "John 3:16" is "Johannes 3:16"). */
    public static function localRef(string $ref, string $lang): string
    {
        if ($lang !== 'af' || preg_match('/^(\d? ?[A-Za-z ]+?) (\d.*)$/', $ref, $m) !== 1) {
            return $ref;
        }
        return (self::BOOKS_AF[$m[1]] ?? $m[1]) . ' ' . $m[2];
    }

    /** Where to read a passage in a Bible Gateway version. */
    public static function readUrl(string $ref, string $version): string
    {
        return 'https://www.biblegateway.com/passage/?search=' . rawurlencode($ref) . '&version=' . rawurlencode($version);
    }

    /**
     * Today's verse in `$lang` ("en", "af"; anything else answers like English).
     * @return array{date: string, lang: string, reference: string, text: ?string, version: ?string, translation: ?string, read: string, source: string}
     */
    public function today(string $lang = 'en', ?float $now = null): array
    {
        $tz = new DateTimeZone($this->station['timezone'] ?: 'UTC');
        $date = (new DateTimeImmutable('@' . (int) floor($now ?? microtime(true))))->setTimezone($tz)->format('Y-m-d');
        $lang = $lang === 'af' ? 'af' : 'en';
        $ref = self::referenceFor($date);
        $out = ['date' => $date, 'lang' => $lang, 'reference' => self::localRef($ref, $lang), 'text' => null, 'version' => null, 'translation' => null,
                'read' => self::readUrl($ref, self::VERSIONS[$lang]), 'source' => 'Bible Gateway'];
        if ($lang === 'en') {
            $text = $this->text($ref, $date, $now ?? microtime(true));
            if ($text !== null) {
                $out['text'] = $text;
                $out['version'] = self::VERSIONS['en'];
                $out['translation'] = self::TRANSLATION_NAME;
                $out['source'] = 'bible-api.com';
            }
        }
        return $out;
    }

    /** The passage's text in the public-domain translation: kept for the day in storage/cache; null when it can't be had. */
    private function text(string $ref, string $date, float $now): ?string
    {
        $dir = $this->root . '/storage/cache';
        $file = "$dir/verse-$date.json";
        $cached = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (is_array($cached) && ($cached['ref'] ?? null) === $ref && is_string($cached['text'] ?? null)) {
            return $cached['text'];
        }
        $tried = "$dir/verse-tried";
        if (is_file($tried) && $now - (float) @file_get_contents($tried) < self::RETRY_SECONDS) {
            return null; // it failed a moment ago: not on every request
        }
        $body = ($this->fetch)('https://bible-api.com/' . rawurlencode($ref) . '?translation=' . self::TRANSLATION);
        $json = is_string($body) ? json_decode($body, true) : null;
        $text = is_array($json) && is_string($json['text'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00a0}", ' ', $json['text']))) : '';
        if ($text === '' || mb_strlen($text) > 2000) {
            @file_put_contents($tried, (string) $now);
            return null;
        }
        if (is_dir($dir)) {
            foreach (glob("$dir/verse-*.json") ?: [] as $old) {
                @unlink($old);
            }
            @file_put_contents($file, json_encode(['ref' => $ref, 'text' => $text], JSON_UNESCAPED_UNICODE));
        }
        @unlink($tried);
        return $text;
    }

    /** A web address's body (curl, or PHP's own), or null. */
    public static function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Stationwagon', CURLOPT_MAXFILESIZE => 200000]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return is_string($body) && $code === 200 ? $body : null;
        }
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 6, 'user_agent' => 'Stationwagon']]));
        return is_string($body) ? $body : null;
    }
}
