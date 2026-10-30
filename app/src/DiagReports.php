<?php
declare(strict_types=1);

namespace Stationwagon;

use InvalidArgumentException;
use OverflowException;

/**
 * Diagnostics reports a listener sends from the player's Diagnostics panel ("Send report"), kept as
 * text files in storage/diag/ (not served) for whoever troubleshoots the station to read. A report
 * holds the panel's numbers, the player's recent events and the browser's own description of itself;
 * no internet address is kept (it is only hashed, for a limit of 20 reports an hour per address), and
 * only the newest 200 reports stay.
 */
final class DiagReports
{
    public const MAX_BYTES = 65536;
    public const PER_HOUR = 20;
    public const KEEP = 200;

    public function __construct(private readonly string $dir)
    {
    }

    public static function forApp(): self
    {
        return new self(SW_ROOT . '/storage/diag');
    }

    /** Save a report; returns its id (the file name without .txt). */
    public function save(string $report, string $address, float $now): string
    {
        $report = trim(str_replace("\r\n", "\n", $report));
        if ($report === '' || strlen($report) > self::MAX_BYTES || !mb_check_encoding($report, 'UTF-8')) {
            throw new InvalidArgumentException('Send a report of up to 64 KB of text.');
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Can’t make storage/diag.');
        }
        $this->limit($address, $now);
        $id = gmdate('Ymd-His', (int) $now) . '-' . bin2hex(random_bytes(3));
        file_put_contents("{$this->dir}/$id.txt", "Received: " . gmdate('Y-m-d H:i:s', (int) $now) . " UTC\n\n$report\n", LOCK_EX);
        $this->prune();
        return $id;
    }

    /** @return list<string> report ids, newest first */
    public function ids(): array
    {
        $ids = array_map(fn($f) => basename($f, '.txt'), glob("{$this->dir}/*.txt") ?: []);
        rsort($ids);
        return $ids;
    }

    private function limit(string $address, float $now): void
    {
        $file = "{$this->dir}/.limit-" . substr(hash('sha256', 'sw-diag|' . $address), 0, 16) . '.json';
        $fh = fopen($file, 'c+');
        flock($fh, LOCK_EX);
        $times = array_values(array_filter((array) json_decode((string) stream_get_contents($fh), true), fn($t) => is_numeric($t) && $t > $now - 3600));
        if (count($times) >= self::PER_HOUR) {
            flock($fh, LOCK_UN);
            fclose($fh);
            throw new OverflowException('That’s plenty of reports for an hour. Thank you!');
        }
        $times[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($times));
        flock($fh, LOCK_UN);
        fclose($fh);
        // old limit files go with the hour they counted
        foreach (glob("{$this->dir}/.limit-*.json") ?: [] as $f) {
            if ($f !== $file && @filemtime($f) < $now - 3600) {
                @unlink($f);
            }
        }
    }

    private function prune(): void
    {
        foreach (array_slice($this->ids(), self::KEEP) as $old) {
            @unlink("{$this->dir}/$old.txt");
        }
    }
}
