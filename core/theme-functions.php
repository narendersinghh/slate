<?php
/**
 * Template API for themes. Short, readable helpers:
 *   <?= e(post()['title']) ?>   <?= field('price') ?>   <?php foreach (query(['type'=>'project']) as $p): ?>
 */
declare(strict_types=1);

use Slate\Auth;
use Slate\ContentModel;
use Slate\Csrf;
use Slate\Frontend;
use Slate\Media;
use Slate\Menus;
use Slate\Options;
use Slate\Posts;
use Slate\Seo;
use Slate\Terms;
use Slate\Theme;

function site_name(): string
{
    return (string)Options::get('site_title');
}

function site_tagline(): string
{
    return (string)Options::get('tagline');
}

function home_url(string $path = ''): string
{
    return url($path);
}

function theme_url(string $file = ''): string
{
    return Theme::url($file);
}

function partial(string $name, array $vars = []): void
{
    Theme::partial($name, $vars);
}

/** Current post on single views. */
function post(): ?array
{
    return Frontend::$data['post'] ?? null;
}

function page_data(string $key, mixed $default = null): mixed
{
    return Frontend::$data[$key] ?? $default;
}

function can_frontend_edit(): bool
{
    return Auth::can('frontend_edit') && Auth::can('edit_posts');
}

/** Everything that belongs in <head>: SEO meta, schema, analytics, custom code. */
function slate_head(): void
{
    echo Seo::head(), "\n";
    if (can_frontend_edit() && Options::get('admin_bar_frontend', true)) {
        echo '<link rel="stylesheet" href="' . e(asset_url('admin/assets/frontend-edit.css')) . '">' . "\n";
    }
    \Slate\Hooks::action('head');
}

function slate_body_open(): void
{
    echo Seo::bodyOpen(), "\n";
    if (Auth::can('access_admin') && Options::get('admin_bar_frontend', true)) {
        $p = post();
        $edit = $p && Auth::can('edit_posts') ? admin_url('content/' . $p['type'] . '/' . $p['id']) : '';
        echo '<div id="slate-bar" role="toolbar" aria-label="Slate admin bar">'
            . '<a class="sb-logo" href="' . e(admin_url()) . '">' . icon('layers', 16) . ' Slate</a>'
            . ($edit ? '<a href="' . e($edit) . '">' . icon('edit', 15) . ' Edit in dashboard</a>' : '')
            . ($p && can_frontend_edit() && ContentModel::supports($p['type'], 'frontend_edit')
                ? '<button type="button" id="sb-edit" data-id="' . (int)$p['id'] . '">' . icon('sparkles', 15) . ' Edit on page</button>'
                . '<button type="button" id="sb-save" hidden>' . icon('check', 15) . ' Save changes</button>'
                . '<button type="button" id="sb-cancel" hidden>Cancel</button>' : '')
            . '<span class="sb-spacer"></span>'
            . '<a href="' . e(admin_url('content/post/new')) . '">' . icon('plus', 15) . ' New</a>'
            . '<a href="' . e(admin_url('logout')) . '?_csrf=' . e(Csrf::token()) . '">' . icon('logout', 15) . '</a>'
            . '</div>';
    }
    \Slate\Hooks::action('body_open');
}

function slate_footer(): void
{
    echo Seo::footer(), "\n";
    if (can_frontend_edit() && Options::get('admin_bar_frontend', true)) {
        echo '<script>window.SLATE={api:' . json_encode(admin_url('api')) . ',csrf:' . json_encode(Csrf::token())
            . ',media:' . json_encode(admin_url('api/media')) . '};</script>';
        echo '<script src="' . e(asset_url('admin/assets/frontend-edit.js')) . '" defer></script>';
    }
    \Slate\Hooks::action('footer');
}

/** Run a content query and return the items. */
function query(array $args = []): array
{
    $args += ['with_terms' => true, 'count' => false];
    return Posts::query($args)['items'];
}

/** Custom field value of the current (or given) post. */
function field(string $name, ?array $post = null, mixed $default = null): mixed
{
    $post ??= post();
    return $post['fields'][$name] ?? $default;
}

function permalink(?array $post = null): string
{
    $post ??= post();
    return $post ? Posts::permalink($post) : '';
}

function excerpt(?array $post = null, int $length = 160): string
{
    $post ??= post();
    return $post ? Posts::excerpt($post, $length) : '';
}

function post_date(?array $post = null, ?string $format = null): string
{
    $post ??= post();
    return $post ? local_date($post['published_at'] ?: $post['created_at'], $format) : '';
}

/** Terms of a post: terms($post, 'category') */
function terms(?array $post, string $taxonomy): array
{
    $post ??= post();
    if (!$post) {
        return [];
    }
    if (!isset($post['terms'])) {
        $post['terms'] = Terms::forPosts([$post['id']])[$post['id']] ?? [];
    }
    return $post['terms'][$taxonomy] ?? [];
}

function term_link(array $term): string
{
    return Terms::link($term);
}

function menu(string $location, string $class = 'menu'): string
{
    return Menus::render($location, $class);
}

function media_url(?int $id, string $size = 'full'): string
{
    return $id ? Media::url($id, $size) : '';
}

/** Responsive, lazy <img> with alt text and dimensions (prevents layout shift). */
function image(?int $id, array $attrs = []): string
{
    $m = $id ? Media::find($id) : null;
    if (!$m) {
        return '';
    }
    $size = $attrs['size'] ?? 'full';
    unset($attrs['size']);
    $a = [
        'src' => Media::url($m, $size),
        'alt' => $attrs['alt'] ?? $m['alt'],
        'width' => $m['width'] ?: null,
        'height' => $m['height'] ?: null,
        'loading' => Options::get('media_lazy', true) ? 'lazy' : null,
        'decoding' => 'async',
    ];
    if ($m['thumb_path'] && $size === 'full' && $m['width']) {
        $a['srcset'] = Media::url($m, 'thumb') . ' ' . Options::get('media_thumb_size', 400) . 'w, ' . Media::url($m) . ' ' . $m['width'] . 'w';
        $a['sizes'] = $attrs['sizes'] ?? '(max-width: 800px) 100vw, 800px';
    }
    $a = array_merge($a, $attrs);
    $html = '<img';
    foreach ($a as $k => $v) {
        if ($v !== null && $v !== false) {
            $html .= ' ' . e($k) . '="' . e($v) . '"';
        }
    }
    return $html . '>';
}

function featured_image(?array $post = null, array $attrs = []): string
{
    $post ??= post();
    return $post && $post['featured_media_id'] ? image((int)$post['featured_media_id'], $attrs) : '';
}

/**
 * Output an editable region. When a team member turns on "Edit on page" it becomes editable in place.
 *   editable('title', 'h1')   editable('content')   editable('field:subtitle', 'p')   editable('field:hero', 'img')
 */
function editable(string $what, string $tag = 'div', ?array $post = null, array $attrs = []): string
{
    $post ??= post();
    if (!$post) {
        return '';
    }
    $tag = preg_replace('/[^a-z0-9]/', '', strtolower($tag)) ?: 'div';
    $fieldDef = null;
    if ($what === 'title') {
        $value = e($post['title']);
        $kind = 'text';
    } elseif ($what === 'content') {
        $value = (string)$post['content'];
        $kind = 'html';
    } elseif ($what === 'excerpt') {
        $value = e((string)$post['excerpt']);
        $kind = 'text';
    } elseif (str_starts_with($what, 'field:')) {
        $name = substr($what, 6);
        $fieldDef = ContentModel::fieldsFor('post', $post['type'])[$name] ?? ['type' => 'text'];
        $raw = $post['fields'][$name] ?? '';
        $kind = match ($fieldDef['type']) {
            'richtext' => 'html', 'image' => 'image', 'textarea' => 'textarea', default => 'text',
        };
        if ($kind === 'image') {
            $img = image((int)$raw, $attrs);
            if (!can_frontend_edit()) {
                return $img;
            }
            return '<span class="slate-editable-img" data-slate-edit="' . e($what) . '" data-slate-kind="image" data-post="' . (int)$post['id'] . '">' . ($img ?: '<span class="slate-img-placeholder">Add image</span>') . '</span>';
        }
        $value = $kind === 'html' ? (string)$raw : ($kind === 'textarea' ? nl2br(e(is_scalar($raw) ? $raw : '')) : e(is_scalar($raw) ? $raw : ''));
    } else {
        return '';
    }
    $attrHtml = '';
    foreach ($attrs as $k => $v) {
        $attrHtml .= ' ' . e($k) . '="' . e($v) . '"';
    }
    if (can_frontend_edit() && ContentModel::supports($post['type'], 'frontend_edit')) {
        $attrHtml .= ' data-slate-edit="' . e($what) . '" data-slate-kind="' . $kind . '" data-post="' . (int)$post['id'] . '"';
    }
    return "<$tag$attrHtml>$value</$tag>";
}

/** Numbered pagination for a query result (from page_data('result')). */
function paginate(?array $result = null): string
{
    $result ??= page_data('result');
    if (!$result || ($result['pages'] ?? 1) <= 1) {
        return '';
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = preg_replace('#/page/\d+/?$#', '', rtrim($path, '/'));
    $qs = isset($_GET['s']) ? '?s=' . rawurlencode((string)$_GET['s']) : '';
    $link = static fn(int $n) => ($n === 1 ? ($path ?: '/') . ($path ? '/' : '') : $path . '/page/' . $n . '/') . $qs;
    $out = '<nav class="pagination" aria-label="Pagination">';
    $cur = $result['page'];
    if ($cur > 1) {
        $out .= '<a rel="prev" href="' . e($link($cur - 1)) . '">← Previous</a>';
    }
    for ($i = max(1, $cur - 2); $i <= min($result['pages'], $cur + 2); $i++) {
        $out .= $i === $cur ? '<span aria-current="page">' . $i . '</span>' : '<a href="' . e($link($i)) . '">' . $i . '</a>';
    }
    if ($cur < $result['pages']) {
        $out .= '<a rel="next" href="' . e($link($cur + 1)) . '">Next →</a>';
    }
    return $out . '</nav>';
}

function breadcrumbs(string $sep = '/'): string
{
    $crumbs = Seo::breadcrumbs();
    if (count($crumbs) < 2) {
        return '';
    }
    $out = '<nav class="breadcrumbs" aria-label="Breadcrumb"><ol>';
    $last = count($crumbs) - 1;
    foreach ($crumbs as $i => $c) {
        $out .= '<li>' . ($i === $last ? '<span aria-current="page">' . e($c['label']) . '</span>' : '<a href="' . e($c['url']) . '">' . e($c['label']) . '</a>')
            . ($i < $last ? ' <span class="sep" aria-hidden="true">' . e($sep) . '</span> ' : '') . '</li>';
    }
    return $out . '</ol></nav>';
}

/** Render post content (hook-filterable for modules: shortcodes, forms, etc.). */
function the_content(?array $post = null): string
{
    $post ??= post();
    if (!$post) {
        return '';
    }
    $html = \Slate\Hooks::filter('the_content', (string)$post['content'], $post);
    if (Options::get('media_lazy', true)) {
        $html = preg_replace('/<img(?![^>]*\bloading=)/i', '<img loading="lazy" decoding="async"', $html);
    }
    return $html;
}
