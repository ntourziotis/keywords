<?php
declare(strict_types=1);

require_once __DIR__ . '/self_cron/bootstrap.php';
\SelfCron\Bootstrap::tick();

// NO-REWRITE front controller.
// Use: /app.php?r=/login
$route = (string)($_GET['r'] ?? '');

if ($route === '') {
    header('Location: /app.php?r=/login', true, 302);
    exit;
}

if ($route[0] !== '/') {
    $route = '/' . ltrim($route, '/');
}

$_SERVER['REQUEST_URI'] = $route;

require __DIR__ . '/public/index.php';
