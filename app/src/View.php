<?php
declare(strict_types=1);

namespace Stationwagon;

/** Renders views/<name>.php inside views/layout.php. Views escape with h(). */
final class View
{
    public static function render(string $name, array $vars = []): void
    {
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
