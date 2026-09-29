<?php

/*
| Front controller for the 48HRS Dental Lab API.
|
| The Laravel application lives OUTSIDE the web root (its code, .env and the
| uploaded case files are never reachable by URL). The .htaccess next to this
| file sends every /api request here; this file only boots Laravel from there —
| exactly what Laravel's own public/index.php does.
|
| The app folder defaults to ../../48hrs_lab_app relative to this file, i.e.
|   ~/domains/<domain>/public_html/48hrs_lab/laravel.php
|   ~/domains/<domain>/48hrs_lab_app/            ← Laravel (backend/)
| Set LAB_APP_DIR (SetEnv in .htaccess) to use another location.
*/

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$app = getenv('LAB_APP_DIR') ?: ($_SERVER['LAB_APP_DIR'] ?? ($_SERVER['REDIRECT_LAB_APP_DIR'] ?? dirname(__DIR__, 2).'/48hrs_lab_app'));

if (! is_file($app.'/bootstrap/app.php')) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"message":"The service is temporarily unavailable."}';
    exit;
}

// Maintenance mode (php artisan down).
if (file_exists($maintenance = $app.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $app.'/vendor/autoload.php';

(require_once $app.'/bootstrap/app.php')->handleRequest(Request::capture());
