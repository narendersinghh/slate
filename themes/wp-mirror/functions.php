<?php
/**
 * WordPress mirror theme helpers. Data comes from the WordPress Importer (option "wpm_site").
 */
declare(strict_types=1);

use Slate\Options;

function wpm_site(): array
{
    return Options::get('wpm_site') ?: ['head' => [], 'foot' => [], 'header' => '', 'footer' => '', 'popups' => [], 'body_class' => '', 'lang' => 'en', 'wrap_open' => '', 'wrap_close' => ''];
}

/** Print captured <link>/<style>/<script> entries. */
function wpm_assets(array $items): void
{
    foreach ($items as $a) {
        switch ($a['t'] ?? '') {
            case 'css':
                echo '<link rel="stylesheet"' . (!empty($a['id']) ? ' id="' . e($a['id']) . '"' : '') . ' href="' . e($a['href']) . '" media="' . e($a['media'] ?? 'all') . '">', "\n";
                break;
            case 'style':
                echo '<style' . (!empty($a['id']) ? ' id="' . e($a['id']) . '"' : '') . '>' . str_ireplace('</style', '<\/style', (string)$a['css']) . '</style>', "\n";
                break;
            case 'js':
                echo '<script' . (!empty($a['id']) ? ' id="' . e($a['id']) . '"' : '') . ' src="' . e($a['src']) . '"' . (!empty($a['defer']) ? ' defer' : '') . (!empty($a['async']) ? ' async' : '') . (!empty($a['module']) ? ' type="module"' : '') . '></script>', "\n";
                break;
            case 'inline':
                echo '<script' . (!empty($a['id']) ? ' id="' . e($a['id']) . '"' : '') . '>' . str_ireplace('</script', '<\/script', (string)$a['js']) . '</script>', "\n";
                break;
        }
    }
}

/** Page-specific CSS captured from the original page (e.g. Elementor post-123.css). */
function wpm_page_assets(): void
{
    $p = post();
    if (!$p) {
        return;
    }
    foreach ((array)($p['fields']['_wpm_css'] ?? []) as $href) {
        echo '<link rel="stylesheet" href="' . e((string)$href) . '">', "\n";
    }
    if (!empty($p['fields']['_wpm_style'])) {
        echo '<style>' . str_ireplace('</style', '<\/style', (string)$p['fields']['_wpm_style']) . '</style>', "\n";
    }
}

function wpm_body_class(): string
{
    $p = post();
    $classes = wpm_site()['body_class'] . ' ' . ($p['fields']['_wpm_body_class'] ?? '') . ' slate-mirror kind-' . page_data('kind', 'page');
    return trim(preg_replace('/\s+/', ' ', $classes));
}

/** True when the content is a full Elementor layout (renders without theme chrome around it). */
function wpm_is_elementor(?array $p = null): bool
{
    $p ??= post();
    return $p && str_contains((string)$p['content'], 'data-elementor-type');
}
