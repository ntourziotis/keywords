<?php

declare(strict_types=1);

use App\Core\Config;
use App\Auth\AuthService;

// Simple PSR-4 autoloader for App\ -> keywords-adweb-php-mvp/src
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/src/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Config::load(__DIR__);
AuthService::startSession();
