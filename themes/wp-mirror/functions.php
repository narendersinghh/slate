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
                $type = (string)($a['type'] ?? '');
                echo '<script' . (!empty($a['id']) ? ' id="' . e($a['id']) . '"' : '') . ($type !== '' && $type !== 'text/javascript' ? ' type="' . e($type) . '"' : '') . '>'
                    . str_ireplace('</script', '<\/script', (string)$a['js']) . '</script>', "\n";
                break;
        }
    }
}

/** Page-specific CSS captured from the original page (e.g. Elementor post-123.css). */
/** Page-level asset bundle: the current post's captured fields, or an archive snapshot's sidecar. */
function wpm_page_fields(): array
{
    if (isset($GLOBALS['wpm_page_fields'])) {
        return (array)$GLOBALS['wpm_page_fields'];
    }
    return (array)(post()['fields'] ?? []);
}

function wpm_page_assets(): void
{
    $f = wpm_page_fields();
    if (!$f) {
        return;
    }
    $p = ['fields' => $f];
    foreach ((array)($p['fields']['_wpm_css'] ?? []) as $href) {
        echo '<link rel="stylesheet" href="' . e((string)$href) . '">', "\n";
    }
    if (!empty($p['fields']['_wpm_style'])) {
        echo '<style>' . str_ireplace('</style', '<\/style', (string)$p['fields']['_wpm_style']) . '</style>', "\n";
    }
}

function wpm_body_class(): string
{
    $classes = wpm_site()['body_class'] . ' ' . (wpm_page_fields()['_wpm_body_class'] ?? '') . ' slate-mirror kind-' . page_data('kind', 'page');
    return trim(preg_replace('/\s+/', ' ', $classes));
}

/** True when the content is a full Elementor layout (renders without theme chrome around it). */
function wpm_is_elementor(?array $p = null): bool
{
    $p ??= post();
    return $p && str_contains((string)$p['content'], 'data-elementor-type');
}

/** Stored snapshot of the original rendered page (Elementor Pro templates, loops, ACF output). */
function wpm_snapshot(string $bucket, string $key): ?string
{
    $f = SLATE_CONTENT . '/wpm/' . preg_replace('/[^a-z0-9_-]/i', '', $bucket) . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $key) . '.html';
    return is_file($f) ? (string)file_get_contents($f) : null;
}

/** Page-specific scripts captured from the original page (loaded after the global ones). */
function wpm_page_scripts(): void
{
    $p = ['fields' => wpm_page_fields()];
    $global = array_column(wpm_site()['foot'] ?? [], 'src');
    foreach ((array)($p['fields']['_wpm_js'] ?? []) as $js) {
        if (!empty($js['src']) && !in_array($js['src'], $global, true)) {
            echo '<script src="' . e((string)$js['src']) . '"' . (!empty($js['id']) ? ' id="' . e((string)$js['id']) . '"' : '') . '></script>', "\n";
        }
    }
}

/** Use an archive snapshot (and its CSS/JS sidecar) for the current listing, if one exists. */
function wpm_archive_snapshot(string $key): ?string
{
    $snap = wpm_snapshot('archive', $key);
    if ($snap !== null) {
        $meta = wpm_snapshot('archive', $key . '.assets');
        $GLOBALS['wpm_page_fields'] = $meta ? (json_decode($meta, true) ?: []) : [];
    }
    return $snap;
}

/** Snapshot of a single item, unless it was edited in Slate after the import. */
function wpm_single_snapshot(array $p): ?string
{
    $file = SLATE_CONTENT . '/wpm/single/' . (int)$p['id'] . '.html';
    if (!is_file($file) || strtotime($p['updated_at'] . ' UTC') > filemtime($file) + 5) {
        return null;
    }
    return (string)file_get_contents($file);
}
