<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * Pure-PHP MP3 reading: frame headers (to cut ~6 s pieces on frame boundaries for HLS byte
 * ranges, no ffmpeg) and the common ID3v2 text tags. Ported from the proof of concept.
 */
final class Mp3
{
    private const BITRATES_V1 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0];
    private const BITRATES_V2 = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0];
    private const RATES = [3 => [44100, 48000, 32000], 2 => [22050, 24000, 16000], 0 => [11025, 12000, 8000]];

    /**
     * @return array{duration:float,frames:int,sample_rate:int,bitrate:int,channels:int,
     *               pieces:list<array{0:int,1:int,2:float}>} pieces are [byte offset, byte length, seconds]
     */
    public static function scan(string $path, float $pieceSeconds): array
    {
        $data = (string) file_get_contents($path);
        $len = strlen($data);
        $pos = self::id3Size($data);

        $pieces = [];
        $time = 0.0;
        $pieceStart = null;
        $pieceTime = 0.0;
        $frames = 0;
        $sampleRate = 0;
        $bitrate = 0;
        $channels = 2;

        while ($pos + 4 <= $len) {
            $b1 = ord($data[$pos]);
            $b2 = ord($data[$pos + 1]);
            if ($b1 !== 0xFF || ($b2 & 0xE0) !== 0xE0) {
                $pos++;
                continue;
            }
            $version = ($b2 >> 3) & 3;   // 3 = MPEG1, 2 = MPEG2, 0 = MPEG2.5, 1 = reserved
            $layer = ($b2 >> 1) & 3;     // 1 = Layer III
            $b3 = ord($data[$pos + 2]);
            $bitrateIndex = ($b3 >> 4) & 0xF;
            $rateIndex = ($b3 >> 2) & 3;
            if ($version === 1 || $layer !== 1 || $bitrateIndex === 0 || $bitrateIndex === 15 || $rateIndex === 3) {
                $pos++;
                continue;
            }
            $kbps = ($version === 3 ? self::BITRATES_V1 : self::BITRATES_V2)[$bitrateIndex];
            $rate = self::RATES[$version][$rateIndex];
            $padding = ($b3 >> 1) & 1;
            $samples = $version === 3 ? 1152 : 576;
            $frameLen = intdiv(($version === 3 ? 144 : 72) * $kbps * 1000, $rate) + $padding;

            // Guard against a false sync word: the next frame must start where this one ends
            $next = $pos + $frameLen;
            if ($frameLen < 24 || ($next + 2 <= $len && (ord($data[$next]) !== 0xFF || (ord($data[$next + 1]) & 0xE0) !== 0xE0))) {
                if ($next < $len - 128) { // the last frame may be followed by an ID3v1 tag
                    $pos++;
                    continue;
                }
            }

            if ($frames === 0) {
                $sampleRate = $rate;
                $bitrate = $kbps;
                $channels = ((ord($data[$pos + 3]) >> 6) & 3) === 3 ? 1 : 2;
            }
            if ($pieceStart === null) {
                $pieceStart = $pos;
                $pieceTime = 0.0;
            }
            $frameTime = $samples / $rate;
            $pieceTime += $frameTime;
            $time += $frameTime;
            $frames++;
            $pos = min($next, $len);

            if ($pieceTime >= $pieceSeconds) {
                $pieces[] = [$pieceStart, $pos - $pieceStart, round($pieceTime, 4)];
                $pieceStart = null;
            }
        }
        if ($pieceStart !== null && $pieceTime > 0.05) {
            $pieces[] = [$pieceStart, $pos - $pieceStart, round($pieceTime, 4)];
        }

        return [
            'duration' => round($time, 3),
            'frames' => $frames,
            'sample_rate' => $sampleRate,
            'bitrate' => $bitrate,
            'channels' => $channels,
            'pieces' => $pieces,
        ];
    }

    /**
     * Title, artist and album from an ID3v2.3/2.4 tag, when there is one; also its copyright text
     * (`copyright`), publisher, composers (`writers`), year, and the attached picture (`art`: mime
     * and bytes of the front cover, or the first picture). The picture may be up to 3 MB.
     */
    public static function tags(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 3 * 1024 * 1024 + 256 * 1024);
        $out = [];
        if (strlen($head) < 10 || substr($head, 0, 3) !== 'ID3' || !in_array(ord($head[3]), [3, 4], true)) {
            return $out;
        }
        $v4 = ord($head[3]) === 4;
        $end = min(strlen($head), self::id3Size($head));
        $pos = 10;
        $names = ['TIT2' => 'title', 'TPE1' => 'artist', 'TALB' => 'album', 'TCOP' => 'copyright', 'TPUB' => 'publisher',
                  'TCOM' => 'writers', 'TYER' => 'year', 'TDRC' => 'year'];
        $pictures = [];
        while ($pos + 10 <= $end) {
            $id = substr($head, $pos, 4);
            if (!preg_match('/^[A-Z0-9]{4}$/', $id)) {
                break; // padding
            }
            $raw = substr($head, $pos + 4, 4);
            $size = $v4 ? self::syncsafe($raw) : (int) unpack('N', $raw)[1];
            $body = substr($head, $pos + 10, $size);
            if (isset($names[$id]) && $body !== '') {
                $text = self::text($body);
                if ($text !== '') {
                    $out[$names[$id]] = $text;
                }
            }
            if ($id === 'APIC' && $size > 20 && $size <= 3 * 1024 * 1024 && ($pic = self::picture($body)) !== null) {
                $pictures[$pic['type']] = $pic;
            }
            $pos += 10 + $size;
        }
        if ($pictures) {
            $pic = $pictures[3] ?? reset($pictures); // 3: front cover
            $out['art'] = ['mime' => $pic['mime'], 'data' => $pic['data']];
        }
        if (isset($out['year']) && preg_match('/\d{4}/', $out['year'], $m)) {
            $out['year'] = $m[0];
        }
        return $out;
    }

    /** An APIC frame's body: text encoding, mime type, picture type, description, then the image. */
    private static function picture(string $body): ?array
    {
        $enc = ord($body[0]);
        $end = strpos($body, "\0", 1);
        if ($end === false) {
            return null;
        }
        $type = ord($body[$end + 1] ?? "\0");
        $p = $end + 2;
        if ($enc === 1 || $enc === 2) { // a UTF-16 description ends in two zero bytes, on an even offset
            while ($p + 1 < strlen($body) && ($body[$p] !== "\0" || $body[$p + 1] !== "\0")) {
                $p += 2;
            }
            $p += 2;
        } else {
            $z = strpos($body, "\0", $p);
            $p = $z === false ? strlen($body) : $z + 1;
        }
        $data = substr($body, $p);
        // go by the bytes, not the mime type that tags often get wrong
        if (str_starts_with($data, "\xFF\xD8\xFF")) {
            $mime = 'image/jpeg';
        } elseif (str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
            $mime = 'image/png';
        } else {
            return null;
        }
        return ['mime' => $mime, 'type' => $type, 'data' => $data];
    }

    /** Where the audio starts: after an ID3v2 tag, if any. */
    private static function id3Size(string $data): int
    {
        if (strlen($data) > 10 && substr($data, 0, 3) === 'ID3') {
            return 10 + self::syncsafe(substr($data, 6, 4)) + ((ord($data[5]) & 0x10) ? 10 : 0);
        }
        return 0;
    }

    private static function syncsafe(string $four): int
    {
        return ((ord($four[0]) & 0x7f) << 21) | ((ord($four[1]) & 0x7f) << 14) | ((ord($four[2]) & 0x7f) << 7) | (ord($four[3]) & 0x7f);
    }

    private static function text(string $body): string
    {
        $encoding = ord($body[0]);
        $s = substr($body, 1);
        $s = match ($encoding) {
            1 => mb_convert_encoding($s, 'UTF-8', 'UTF-16'),   // with byte-order mark
            2 => mb_convert_encoding($s, 'UTF-8', 'UTF-16BE'),
            3 => $s,
            default => mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1'),
        };
        return trim(str_replace("\0", ' ', $s));
    }
}
