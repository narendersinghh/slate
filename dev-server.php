<?php
/**
 * Router for PHP's built-in web server (local development only):
 *   php -S localhost:8000 dev-server.php
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('#^/(config\.php|core/.*\.php|modules/.*\.php|themes/.*\.php|content/.*\.(php|sqlite|log|zip|html)|admin/(views|install\.php|routes\.php|api\.php))#i', $path)) {
    http_response_code(403);
    exit('Forbidden');
}
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    return false; // serve static asset
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
