<?php

/**
 * Zero-dependency autoloader.
 * Maps namespace "App\Foo\Bar" -> app/Foo/Bar.php
 * so the product runs on plain XAMPP with no `composer install` step.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
