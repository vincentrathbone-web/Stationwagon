<?php
declare(strict_types=1);

/**
 * Reads MPEG audio (Layer III) frame headers in pure PHP and groups whole frames into pieces of
 * about $pieceSeconds. Each piece is [byteOffset, byteLength, durationSeconds], ready for
 * #EXT-X-BYTERANGE. No ffmpeg needed.
 */
function sw_mp3_scan(string $path, float $pieceSeconds): array
{
    static $bitratesV1 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0];
    static $bitratesV2 = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0];
    static $rates = [3 => [44100, 48000, 32000], 2 => [22050, 24000, 16000], 0 => [11025, 12000, 8000]];

    $data = (string) file_get_contents($path);
    $len = strlen($data);
    $pos = 0;

    // Skip an ID3v2 tag (size is "syncsafe": 7 bits per byte)
    if ($len > 10 && substr($data, 0, 3) === 'ID3') {
        $size = ((ord($data[6]) & 0x7f) << 21) | ((ord($data[7]) & 0x7f) << 14)
              | ((ord($data[8]) & 0x7f) << 7) | (ord($data[9]) & 0x7f);
        $pos = 10 + $size + ((ord($data[5]) & 0x10) ? 10 : 0);
    }

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
        $kbps = ($version === 3 ? $bitratesV1 : $bitratesV2)[$bitrateIndex];
        $rate = $rates[$version][$rateIndex];
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
        'duration'    => round($time, 3),
        'frames'      => $frames,
        'sample_rate' => $sampleRate,
        'bitrate'     => $bitrate,
        'channels'    => $channels,
        'pieces'      => $pieces,
    ];
}
