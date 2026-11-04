<?php
/**
 * Slate CMS bootstrap.
 * Loaded by index.php for every request (frontend, admin, API).
 */
declare(strict_types=1);

const SLATE_VERSION = '0.3.1';
const SLATE_DB_VERSION = 1;
const SLATE_MIN_PHP = '8.2.0';

define('SLATE_ROOT', dirname(__DIR__));
define('SLATE_CORE', SLATE_ROOT . '/core');
define('SLATE_ADMIN', SLATE_ROOT . '/admin');
define('SLATE_CONTENT', SLATE_ROOT . '/content');
define('SLATE_UPLOADS', SLATE_CONTENT . '/uploads');
define('SLATE_CACHE', SLATE_CONTENT . '/cache');
define('SLATE_THEMES', SLATE_ROOT . '/themes');
define('SLATE_MODULES', SLATE_ROOT . '/modules');

if (version_compare(PHP_VERSION, SLATE_MIN_PHP, '<')) {
    http_response_code(500);
    exit('Slate requires PHP ' . SLATE_MIN_PHP . ' or newer. You are running ' . PHP_VERSION . '.');
}

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Slate\\')) {
        $file = SLATE_CORE . '/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require SLATE_CORE . '/helpers.php';
require SLATE_CORE . '/theme-functions.php';

$configFile = SLATE_ROOT . '/config.php';
if (is_file($configFile)) {
    require $configFile;
}

define('SLATE_DEBUG', defined('SLATE_CONFIG') && !empty(SLATE_CONFIG['debug']));
error_reporting(E_ALL);
ini_set('display_errors', SLATE_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', SLATE_CONTENT . '/slate-error.log');
