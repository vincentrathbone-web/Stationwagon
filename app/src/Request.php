<?php
declare(strict_types=1);

namespace Stationwagon;

/** Facts about the current request. */
final class Request
{
    // Cloudflare's published edge ranges (https://www.cloudflare.com/ips/). Only when the request
    // really comes from one of these do we believe its CF-Connecting-IP header.
    private const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** The visitor's address, looking through Cloudflare. Used to slow down password guessing. */
    public static function clientAddress(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $forwarded = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP) && self::inRanges($remote, self::CLOUDFLARE)) {
            return $forwarded;
        }
        return $remote;
    }

    /**
     * The audio a studio, recorder or mixdown sent: the raw bytes, or, for a small upload, the bytes as
     * base64 in JSON ({"b64": "…"}). Some hosts' firewalls refuse a small binary body outright (406 before
     * PHP; wordwave.co.za's host under about 10 KB), and a show's last piece can be that small.
     */
    public static function audio(): string
    {
        $body = (string) file_get_contents('php://input');
        if (str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
            $in = json_decode($body, true);
            $bytes = is_array($in) && is_string($in['b64'] ?? null) ? base64_decode($in['b64'], true) : false;
            return $bytes === false ? '' : $bytes;
        }
        return $body;
    }

    public static function inRanges(string $ip, array $ranges): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($ranges as $range) {
            [$net, $bits] = explode('/', $range);
            $netBin = @inet_pton($net);
            if ($netBin === false || strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0 || ((ord($bin[$bytes]) ^ ord($netBin[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }
        return false;
    }
}
