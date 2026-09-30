<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Assembly\\';
    if (str_starts_with($class, $prefix)) {
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = __DIR__ . '/' . $relative . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

if (!function_exists('h')) {
    /** Escape a value for HTML output. */
    function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
