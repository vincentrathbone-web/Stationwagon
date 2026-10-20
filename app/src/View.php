<?php
declare(strict_types=1);

namespace Stationwagon;

/** Renders views/<name>.php inside views/layout.php. Views escape with h(). */
final class View
{
    public static function render(string $name, array $vars = []): void
    {
        $vars['me'] ??= App::me();
        $vars['flash'] = Session::takeFlash();
        if ($vars['me'] !== null) {
            $vars['csrf'] ??= Session::csrf(); // the header's sign-out button
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        require SW_ROOT . "/views/$name.php";
        $content = ob_get_clean();
        header('Content-Type: text/html; charset=utf-8');
        require SW_ROOT . '/views/layout.php';
    }
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** A stream time on the station's clock. */
function clock(float $t, string $timezone, string $format = 'H:i'): string
{
    return (new \DateTimeImmutable('@' . (int) floor($t)))->setTimezone(new \DateTimeZone($timezone))->format($format);
}

/** 3:07, or 1:02:09 for an hour or more. */
function duration(float $seconds): string
{
    $s = (int) round($seconds);
    return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
}

function megabytes(int|float $bytes): string
{
    return $bytes >= 1073741824 ? sprintf('%.1f GB', $bytes / 1073741824) : sprintf('%.1f MB', $bytes / 1048576);
}
