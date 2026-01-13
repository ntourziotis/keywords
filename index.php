<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\ChannelsController;
use App\Controllers\VideosController;
use App\Controllers\StopwordsController;
use App\Controllers\TaxonomyController;
use App\Controllers\ErrorsController;
use App\Controllers\ExportController;
use App\Controllers\SyncController;

$router = new Router();

// Home
$router->get('/', fn() => DashboardController::index());

// Sync now
$router->post('/sync/run', fn() => SyncController::run());

// Auth
$router->get('/login', fn() => AuthController::showLogin());
$router->post('/login', fn() => AuthController::login());
$router->post('/logout', fn() => AuthController::logout());

// Channels
$router->get('/channels', fn() => ChannelsController::index());
$router->get('/channels/new', fn() => ChannelsController::createForm());
$router->post('/channels', fn() => ChannelsController::store());
$router->get('/channels/edit', fn() => ChannelsController::editForm());
$router->post('/channels/update', fn() => ChannelsController::update());
$router->post('/channels/delete', fn() => ChannelsController::delete());

// Videos
$router->get('/videos', fn() => VideosController::index());
$router->get('/videos/edit', fn() => VideosController::editForm());
$router->post('/videos/update', fn() => VideosController::update());
$router->post('/videos/delete', fn() => VideosController::delete());
$router->post('/videos/bulk', fn() => VideosController::bulk());

// Stopwords
$router->get('/stopwords', fn() => StopwordsController::index());
$router->post('/stopwords/add', fn() => StopwordsController::add());
$router->post('/stopwords/delete', fn() => StopwordsController::delete());

// Taxonomy
$router->get('/taxonomy', fn() => TaxonomyController::index());
$router->post('/taxonomy/create', fn() => TaxonomyController::create());
$router->post('/taxonomy/update', fn() => TaxonomyController::update());
$router->post('/taxonomy/delete', fn() => TaxonomyController::delete());

// Errors
$router->get('/errors', fn() => ErrorsController::index());
$router->post('/errors/resolve', fn() => ErrorsController::resolve());

// Export (MRSS enriched)
$router->get('/export/mrss', fn() => ExportController::mrss());

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
