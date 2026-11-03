<?php
// Copy to config.php if you prefer manual setup instead of the web installer.
const SLATE_CONFIG = [
    'db' => [
        'driver' => 'mysql',          // 'mysql' or 'sqlite'
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'slate',
        'user' => 'slate',
        'pass' => 'change-me',
        'prefix' => 'sl_',
        // 'path' => __DIR__ . '/content/database.sqlite', // sqlite only
    ],
    'secret' => 'replace-with-64-random-hex-characters',   // php -r "echo bin2hex(random_bytes(32));"
    'url' => 'https://example.com',                          // no trailing slash
    'debug' => false,
];
