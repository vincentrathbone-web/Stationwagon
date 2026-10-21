<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * What kind of device, browser and app a listener uses, read from the browser's User-Agent text.
 * Coarse on purpose: the Listeners screen groups by these, so a handful of names each is enough.
 * Our own test runs add TEST_MARK to theirs, so their listening stays out of the stats.
 */
final class Agent
{
    public const TEST_MARK = 'StationwagonTest';

    /** @return array{device:string, browser:string, app:string, test:bool} */
    public static function parse(string $ua): array
    {
        return ['device' => self::device($ua), 'browser' => self::browser($ua), 'app' => self::app($ua),
            'test' => str_contains($ua, self::TEST_MARK)];
    }

    public static function device(string $ua): string
    {
        return match (true) {
            $ua === '' => 'Unknown',
            (bool) preg_match('/SmartTV|SMART-TV|HbbTV|Tizen.*TV|Web0S|webOS.*TV|AppleTV|CrKey|Roku|BRAVIA|AFT[A-Z]|GoogleTV|Android TV/i', $ua) => 'TV',
            (bool) preg_match('/iPad|Tablet|PlayBook|Silk\//i', $ua),
            str_contains($ua, 'Android') && !str_contains($ua, 'Mobile') => 'Tablet',
            (bool) preg_match('/Mobi|iPhone|iPod|Windows Phone|BlackBerry|KaiOS/i', $ua) => 'Phone',
            (bool) preg_match('/bot\b|crawl|spider|curl\/|Wget|python|okhttp|Go-http|Java\//i', $ua) => 'Other',
            (bool) preg_match('/Windows NT|Macintosh|Mac OS X|X11|CrOS|Linux/', $ua) => 'Computer',
            default => 'Other',
        };
    }

    public static function browser(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/Edg(e|A|iOS)?\//', $ua) => 'Edge',
            (bool) preg_match('/OPR\/|Opera|OPT\//', $ua) => 'Opera',
            str_contains($ua, 'SamsungBrowser/') => 'Samsung Internet',
            (bool) preg_match('/Firefox\/|FxiOS\//', $ua) => 'Firefox',
            (bool) preg_match('/Chrome\/|CriOS\/|Chromium\//', $ua) => 'Chrome',
            str_contains($ua, 'Safari/') && str_contains($ua, 'Version/') => 'Safari',
            str_contains($ua, 'AppleWebKit') && (bool) preg_match('/iPhone|iPad|iPod/', $ua) => 'Safari', // an iOS app's web view
            default => 'Other',
        };
    }

    /** The app the page is open in, when it isn't a plain browser (a link opened inside WhatsApp…). */
    public static function app(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/FBAN|FBAV|FB_IAB|FBIOS/', $ua) => 'Facebook',
            str_contains($ua, 'Instagram') => 'Instagram',
            str_contains($ua, 'WhatsApp') => 'WhatsApp',
            (bool) preg_match('/musical_ly|BytedanceWebview|TikTok/i', $ua) => 'TikTok',
            str_contains($ua, 'Telegram') => 'Telegram',
            str_contains($ua, 'LinkedInApp') => 'LinkedIn',
            str_contains($ua, 'Snapchat') => 'Snapchat',
            (bool) preg_match('/Twitter|TwitterAndroid/', $ua) => 'X (Twitter)',
            str_contains($ua, 'GSA/') => 'Google app',
            (bool) preg_match('/; wv\)/', $ua) => 'Another app',
            (bool) preg_match('/iPhone|iPad|iPod/', $ua) && str_contains($ua, 'AppleWebKit') && !str_contains($ua, 'Safari/') => 'Another app',
            default => 'Web browser',
        };
    }
}
