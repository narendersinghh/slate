<?php
/**
 * Example Slate module.
 * Activate it under Modules. It shows how to use hooks and register an admin page.
 */
declare(strict_types=1);

use Slate\Hooks;
use Slate\Modules;

// Prepend an estimated reading time to blog posts.
Hooks::add('the_content', static function (string $html, array $post): string {
    if ($post['type'] !== 'post') {
        return $html;
    }
    $words = str_word_count(strip_tags($html));
    $minutes = max(1, (int)round($words / 220));
    return '<p class="reading-time"><small>⏱ ' . $minutes . ' min read</small></p>' . $html;
});

Modules::adminPage('Hello World', 'hello-world', static function (): void {
    echo '<div class="card card-pad"><p>👋 This page is rendered by <code>modules/hello-world/module.php</code>.</p>';
    echo '<p class="muted">Modules can add admin pages, hook into content, SEO output, saving, uploads and more.</p></div>';
}, 'manage_modules', 'puzzle');
