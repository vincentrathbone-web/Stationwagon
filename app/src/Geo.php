<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * Country and city for an IP address, from DB-IP's free "IP to City Lite" database (CC BY 4.0,
 * https://db-ip.com), kept on this server in storage/geo/ and looked up here: no address leaves
 * the server. The file is in MaxMind's MMDB format, read with a small reader of our own (a binary
 * search tree over the address bits, then a data section); see
 * https://maxmind.github.io/MaxMind-DB/. DB-IP publishes a new file each month; update() fetches it
 * (cli/plan.php calls it from cron once the month has moved on).
 */
final class Geo
{
    public const FILE = 'geo/dbip-city-lite.mmdb';
    public const URL = 'https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz';
    public const CREDIT_URL = 'https://db-ip.com';
    private const MARKER = "\xAB\xCD\xEFMaxMind.com";

    /** @var resource */
    private $fh;
    private int $nodes;
    private int $recordSize;
    private int $nodeBytes;
    private int $dataStart;
    private int $ipv4Start = 0;
    private int $ipVersion;
    /** @var array<string,mixed> */
    public readonly array $metadata;

    private static ?self $shared = null;
    private static bool $sharedTried = false;

    /** Opens a database file; throws \RuntimeException if it isn't one. */
    public function __construct(string $path)
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("Can't open $path.");
        }
        $this->fh = $fh;
        $size = (int) fstat($fh)['size'];
        $tail = min($size, 128 * 1024);
        fseek($fh, $size - $tail);
        $buf = (string) fread($fh, $tail);
        $at = strrpos($buf, self::MARKER);
        if ($at === false) {
            throw new \RuntimeException('Not an MMDB file.');
        }
        $meta = $this->decodeBuffer($buf, $at + strlen(self::MARKER));
        if (!is_array($meta) || !isset($meta['node_count'], $meta['record_size'], $meta['ip_version'])
            || !in_array($meta['record_size'], [24, 28, 32], true)) {
            throw new \RuntimeException('The MMDB metadata is incomplete.');
        }
        $this->metadata = $meta;
        $this->nodes = (int) $meta['node_count'];
        $this->recordSize = (int) $meta['record_size'];
        $this->nodeBytes = $this->recordSize * 2 / 8;
        $this->dataStart = $this->nodes * $this->nodeBytes + 16;
        $this->ipVersion = (int) $meta['ip_version'];
        if ($this->dataStart > $size) {
            throw new \RuntimeException('The MMDB file is cut short.');
        }
        if ($this->ipVersion === 6) {
            // IPv4 addresses live under ::/96: follow 96 zero bits once
            $node = 0;
            for ($i = 0; $i < 96 && $node < $this->nodes; $i++) {
                $node = $this->record($node, 0);
            }
            $this->ipv4Start = $node;
        }
    }

    /** The station's copy (storage/geo/), opened once per request; null if there isn't one yet. */
    public static function shared(): ?self
    {
        if (!self::$sharedTried) {
            self::$sharedTried = true;
            try {
                self::$shared = new self(self::path());
            } catch (\RuntimeException) {
                self::$shared = null;
            }
        }
        return self::$shared;
    }

    public static function path(): string
    {
        return SW_ROOT . '/storage/' . self::FILE;
    }

    /** The month of the copy here ("2026-10"), or null. */
    public static function version(): ?string
    {
        $v = @file_get_contents(self::path() . '.version');
        return $v !== false && preg_match('/^\d{4}-\d\d$/', trim($v)) ? trim($v) : null;
    }

    /**
     * The country and city for an address, or null when the database doesn't know it (a private
     * or local address, or one it has no place for).
     * @return array{country_code:string, country:string, city:string}|null
     */
    public function lookup(string $ip): ?array
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        $bits = strlen($bin) * 8;
        if ($bits === 128 && $this->ipVersion === 4) {
            return null;
        }
        $node = $bits === 32 && $this->ipVersion === 6 ? $this->ipv4Start : 0;
        for ($i = 0; $i < $bits && $node < $this->nodes; $i++) {
            $node = $this->record($node, (ord($bin[$i >> 3]) >> (7 - ($i & 7))) & 1);
        }
        if ($node <= $this->nodes) {
            return null; // == node_count means "no data"; < means the address ran out (can't happen)
        }
        $data = $this->decodeAt($this->dataStart + $node - $this->nodes - 16);
        if (!is_array($data) || !isset($data['country']['iso_code'])) {
            return null;
        }
        return [
            'country_code' => (string) $data['country']['iso_code'],
            'country' => (string) ($data['country']['names']['en'] ?? $data['country']['iso_code']),
            'city' => (string) ($data['city']['names']['en'] ?? ''),
        ];
    }

    /**
     * Fetch this month's file from DB-IP (last month's early in a month, before the new one is out),
     * unpack it, check it opens and knows a well-known address, then swap it in. Returns the month
     * now here. Throws \RuntimeException when nothing could be fetched; the old copy stays.
     */
    public static function update(?float $now = null, ?string $url = null): string
    {
        $now ??= microtime(true);
        $dir = dirname(self::path());
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Can't make $dir.");
        }
        $months = [gmdate('Y-m', (int) $now), gmdate('Y-m', strtotime(gmdate('Y-m-01', (int) $now) . ' -1 month'))];
        $tmp = self::path() . '.download';
        $last = 'nothing tried';
        foreach ($months as $month) {
            $src = $url ?? sprintf(self::URL, $month);
            $in = @fopen('compress.zlib://' . $src, 'rb', false, stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'Stationwagon']]));
            if ($in === false) {
                $last = "couldn't fetch $src";
                continue;
            }
            $out = fopen($tmp, 'wb');
            $copied = stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            try {
                if ($copied === false || $copied < 1024 * 1024) {
                    throw new \RuntimeException('the file was too small');
                }
                $check = (new self($tmp))->lookup('8.8.8.8');
                if ($check === null || $check['country_code'] === '') {
                    throw new \RuntimeException('it has no place for 8.8.8.8');
                }
            } catch (\RuntimeException $ex) {
                @unlink($tmp);
                $last = "$src: " . $ex->getMessage();
                continue;
            }
            if (!@rename($tmp, self::path())) {
                @unlink($tmp);
                throw new \RuntimeException("Can't put the new file in place.");
            }
            file_put_contents(self::path() . '.version', $month);
            self::$sharedTried = false;
            return $month;
        }
        throw new \RuntimeException('No location database: ' . $last . '.');
    }

    /**
     * Whether update() should run: the copy here is from before this month, and the last try was
     * over a day ago (a failed download isn't retried every five minutes).
     */
    public static function due(float $now): bool
    {
        if (self::version() === gmdate('Y-m', (int) $now)) {
            return false;
        }
        $tried = @filemtime(self::path() . '.tried');
        return $tried === false || $now - $tried > 86400;
    }

    /** Note that update() was tried just now (see due()). */
    public static function tried(): void
    {
        $dir = dirname(self::path());
        if (is_dir($dir) || @mkdir($dir, 0775, true)) {
            @touch(self::path() . '.tried');
        }
    }

    private function record(int $node, int $bit): int
    {
        fseek($this->fh, $node * $this->nodeBytes);
        $b = (string) fread($this->fh, $this->nodeBytes);
        if (strlen($b) !== $this->nodeBytes) {
            return $this->nodes; // past the end: treat as "no data"
        }
        switch ($this->recordSize) {
            case 24:
                return $this->uint(substr($b, $bit * 3, 3));
            case 28:
                return $bit === 0
                    ? ((ord($b[3]) & 0xF0) << 20) | $this->uint(substr($b, 0, 3))
                    : ((ord($b[3]) & 0x0F) << 24) | $this->uint(substr($b, 4, 3));
            default:
                return $this->uint(substr($b, $bit * 4, 4));
        }
    }

    /** Decode the value at a file offset (in the data section). */
    private function decodeAt(int $offset): mixed
    {
        fseek($this->fh, $offset);
        // one record (a city with its names in a few languages) is a few kB at most
        $buf = (string) fread($this->fh, 16384);
        $pos = 0;
        return $this->decode($buf, $pos, $offset);
    }

    private function decodeBuffer(string $buf, int $pos): mixed
    {
        return $this->decode($buf, $pos, null);
    }

    /**
     * One value from $buf at $pos (moved past it). $base is $buf's offset in the file, for
     * following pointers (null in the metadata, which has none).
     */
    private function decode(string $buf, int &$pos, ?int $base, int $depth = 0): mixed
    {
        if ($depth > 32 || $pos >= strlen($buf)) {
            throw new \RuntimeException('Bad MMDB data.');
        }
        $ctrl = ord($buf[$pos++]);
        $type = $ctrl >> 5;
        if ($type === 1) { // a pointer into the data section
            $ss = ($ctrl >> 3) & 3;
            $v = $ctrl & 7;
            $p = match ($ss) {
                0 => ($v << 8) | ord($buf[$pos]),
                1 => (($v << 16) | $this->uint(substr($buf, $pos, 2))) + 2048,
                2 => (($v << 24) | $this->uint(substr($buf, $pos, 3))) + 526336,
                default => $this->uint(substr($buf, $pos, 4)),
            };
            $pos += $ss + 1;
            if ($base === null) {
                throw new \RuntimeException('Bad MMDB data.');
            }
            $target = $this->dataStart + $p;
            if ($target >= $base && $target + 4096 < $base + strlen($buf)) {
                $at = $target - $base; // often still in the buffer we have
                return $this->decode($buf, $at, $base, $depth + 1);
            }
            fseek($this->fh, $target);
            $more = (string) fread($this->fh, 16384);
            $at = 0;
            return $this->decode($more, $at, $target, $depth + 1);
        }
        if ($type === 0) { // extended type
            $type = 7 + ord($buf[$pos++]);
        }
        $size = $ctrl & 0x1F;
        if ($size >= 29) {
            $n = $size - 28;
            $extra = $this->uint(substr($buf, $pos, $n));
            $pos += $n;
            $size = [1 => 29, 2 => 285, 3 => 65821][$n] + $extra;
        }
        switch ($type) {
            case 2: // UTF-8 string
            case 4: // bytes
                $s = substr($buf, $pos, $size);
                $pos += $size;
                return $s;
            case 3: // double
                $v = unpack('E', substr($buf, $pos, 8))[1];
                $pos += 8;
                return $v;
            case 15: // float
                $v = unpack('G', substr($buf, $pos, 4))[1];
                $pos += 4;
                return $v;
            case 5: case 6: case 9: case 10: // unsigned ints (big ones as hex is enough here)
                $raw = substr($buf, $pos, $size);
                $pos += $size;
                return $size <= 7 ? $this->uint($raw) : bin2hex($raw);
            case 8: // int32
                $v = $this->uint(substr($buf, $pos, $size));
                $pos += $size;
                return $size === 4 && $v >= 0x80000000 ? $v - 0x100000000 : $v;
            case 7: // map
                $out = [];
                for ($i = 0; $i < $size; $i++) {
                    $k = $this->decode($buf, $pos, $base, $depth + 1);
                    $out[(string) $k] = $this->decode($buf, $pos, $base, $depth + 1);
                }
                return $out;
            case 11: // array
                $out = [];
                for ($i = 0; $i < $size; $i++) {
                    $out[] = $this->decode($buf, $pos, $base, $depth + 1);
                }
                return $out;
            case 14: // boolean (the size is the value)
                return $size !== 0;
            default: // 12 data cache, 13 end marker: not used in values
                throw new \RuntimeException('Bad MMDB data.');
        }
    }

    private function uint(string $b): int
    {
        $v = 0;
        for ($i = 0, $n = strlen($b); $i < $n; $i++) {
            $v = ($v << 8) | ord($b[$i]);
        }
        return $v;
    }
}
