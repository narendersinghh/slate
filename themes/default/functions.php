<?php
/**
 * Theme functions — runs on every request after modules are loaded.
 * Use it for theme-specific hooks. Keep it light for speed.
 */
declare(strict_types=1);

use Slate\Hooks;

// Example: add the accent colour as a CSS variable.
Hooks::add('head', static function (): void {
    $accent = (string)\Slate\Options::get('theme_accent', '#4f46e5');
    if (preg_match('/^#[0-9a-f]{3,8}$/i', $accent)) {
        echo '<style>:root{--accent:' . $accent . '}</style>';
    }
});
