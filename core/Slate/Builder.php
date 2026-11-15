<?php
declare(strict_types=1);

namespace Slate;

/**
 * Visual page builder (GrapesJS in the dashboard, plain HTML + CSS for visitors).
 *
 * A built item keeps its design in fields['_builder'] = [mode, layout, html, css, project, v]; post content holds the
 * same HTML so search, excerpts, feeds and the REST API keep working. Visitors get only HTML and CSS no builder
 * JavaScript and the result is page-cached like any other page. The HTML may contain template tags ({{title}},
 * {{field:x}}, {{#query}}…), rendered by the Templates engine.
 *
 * Optional at three levels: site-wide (Settings → Content & media → Visual builder), per content type, and per item
 * (classic editor or visual builder).
 */
final class Builder
{
    public const MAX_PROJECT = 4 * 1024 * 1024;

    public static function enabled(): bool
    {
        return (bool)Options::get('builder_enabled', true);
    }

    /** May items of this content type be designed visually? */
    public static function forType(string $type): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $def = ContentModel::type($type);
        if (!$def || !in_array('editor', (array)($def['supports'] ?? []), true) || empty($def['public'])) {
            return false;
        }
        $types = Options::get('builder_types');
        return !is_array($types) || $types === [] || in_array($type, $types, true);
    }

    /** Builder data of an item, or null. */
    public static function data(array $post): ?array
    {
        $b = $post['fields']['_builder'] ?? null;
        return is_array($b) && isset($b['html']) ? $b : null;
    }

    /** Is this item shown with its visual design? */
    public static function active(array $post): bool
    {
        $b = self::data($post);
        return $b !== null && ($b['mode'] ?? 'builder') === 'builder' && self::forType((string)$post['type']);
    }

    /**
     * Save a design from the builder. $in: html, css, project (array), layout ('theme'|'blank').
     * Returns null or an error message.
     */
    public static function save(int $postId, array $in): ?string
    {
        $post = Posts::find($postId);
        if (!$post) {
            return 'Item not found.';
        }
        $project = $in['project'] ?? null;
        $projectJson = json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_array($project) || $projectJson === false || strlen($projectJson) > self::MAX_PROJECT) {
            return 'The design is too large or invalid.';
        }
        $html = (string)($in['html'] ?? '');
        $css = (string)($in['css'] ?? '');
        if (!Auth::can('unfiltered_html')) {
            $html = Sanitizer::html($html);
        }
        $css = self::cleanCss($css);
        $layout = ($in['layout'] ?? '') === 'blank' ? 'blank' : 'theme';
        $prev = self::data($post);
        // The content as it was before the first visual design: "Use classic editor" brings it back exactly.
        $classic = $prev ? (string)($prev['classic'] ?? '') : (string)$post['content'];
        $data = ['mode' => 'builder', 'layout' => $layout, 'html' => $html, 'css' => $css, 'project' => $project, 'classic' => $classic, 'v' => 1];
        $change = ['fields' => ['_builder' => $data], 'content' => $html];
        Revisions::snapshot($post, $change);
        Posts::save($change, $postId);
        return null;
    }

    /** Switch an item between the classic editor and its visual design (the design is kept either way). */
    public static function setMode(int $postId, string $mode): void
    {
        $post = Posts::find($postId);
        $b = $post ? self::data($post) : null;
        if (!$b || ($b['mode'] ?? 'builder') === ($mode === 'classic' ? 'classic' : 'builder')) {
            return;
        }
        if ($mode === 'classic') {
            // Back to the content exactly as it was before the visual design (the design itself is kept).
            $b['mode'] = 'classic';
            $change = ['fields' => ['_builder' => $b], 'content' => (string)($b['classic'] ?? $post['content'])];
        } else {
            // Keep whatever was edited in the classic editor meanwhile, so switching back again restores it.
            $b['mode'] = 'builder';
            $b['classic'] = (string)$post['content'];
            $change = ['fields' => ['_builder' => $b], 'content' => (string)$b['html']];
        }
        Revisions::snapshot($post, $change);
        Posts::save($change, $postId);
    }

    /** Template logic that a visual editor could disturb (loops, conditions, tags inside attributes). */
    public static function hasTemplateLogic(string $html): bool
    {
        return (bool)preg_match('/\{\{\s*[#\/^]|=\s*["\'][^"\']*\{\{/', $html);
    }

    /** CSS from the builder: no closing tags, no script URLs, no remote @import. */
    public static function cleanCss(string $css): string
    {
        $css = str_ireplace(['</style', '<style', '<script', '</script'], '', $css);
        $css = preg_replace('/expression\s*\(|javascript\s*:|behavior\s*:|-moz-binding/i', '', $css);
        $css = preg_replace('/@import[^;]+;?/i', '', $css);
        // GrapesJS writes a body/wrapper reset that would fight the theme: drop it.
        $css = preg_replace('/^\s*\*\s*\{\s*box-sizing:\s*border-box;\s*\}\s*body\s*\{\s*margin:\s*0;\s*\}\s*/', '', (string)$css);
        return trim((string)$css);
    }

    /** Full page for a visitor (inside the theme's header/footer, or a blank canvas). */
    public static function renderPage(array $post, array $ctx): string
    {
        $b = (array)self::data($post);
        $tpl = ['id' => 'pb-' . (int)$post['id'], 'kind' => 'single', 'target' => (string)$post['type'], 'css' => (string)($b['css'] ?? ''),
            'html' => (string)($b['html'] ?? ''), 'wrap' => ($b['layout'] ?? 'theme') !== 'blank'];
        $body = '';
        if ($tpl['css'] !== '') {
            $body .= '<style id="pb-css-' . (int)$post['id'] . '">' . $tpl['css'] . '</style>';
        }
        $body .= '<div class="pb-page">' . Templates::render($tpl['html'], $ctx) . '</div>';
        if (!$tpl['wrap']) {
            ob_start();
            echo '<!doctype html><html ' . html_attrs() . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
            slate_head();
            echo '</head><body class="pb-blank">';
            slate_body_open();
            echo $body;
            slate_footer();
            echo '</body></html>';
            return (string)ob_get_clean();
        }
        ob_start();
        Theme::wrap($body);
        return (string)ob_get_clean();
    }

    /**
     * The site's real header and footer (for a read-only preview around the design in the builder).
     * Returns ['header' => html, 'footer' => html, 'main' => '<main …>' opening tag or ''], scripts and the admin bar removed.
     */
    public static function chrome(array $post): array
    {
        if (Theme::active() === '') {
            return ['header' => '', 'footer' => '', 'main' => ''];
        }
        $marker = '<!--slate-builder-split-->';
        Frontend::$data['post'] = $post;
        Seo::$ctx = ['kind' => 'single', 'post' => $post];
        try {
            ob_start();
            Theme::wrap($marker);
            $doc = (string)ob_get_clean();
        } catch (\Throwable $e) {
            @ob_end_clean();
            return ['header' => '', 'footer' => '', 'main' => ''];
        }
        if (!str_contains($doc, $marker)) {
            return ['header' => '', 'footer' => '', 'main' => ''];
        }
        [$before, $after] = explode($marker, $doc, 2);
        $before = preg_match('/<body[^>]*>(.*)$/is', $before, $m) ? $m[1] : $before;
        $after = preg_replace('#</body>.*$#is', '', $after);
        $clean = static fn(string $h): string => trim((string)preg_replace(['#<script\b.*?</script>#is', '#<div id="slate-bar".*?</div>#is', '#<(link|meta)\b[^>]*>#i'], '', $h));
        $main = '';
        if (preg_match('/<main\b[^>]*>\s*$/i', $before, $mm)) {
            $main = $mm[0];
            $before = substr($before, 0, -strlen($mm[0]));
        }
        $after = preg_replace('#^\s*</main>#i', '', (string)$after);
        return ['header' => $clean($before), 'footer' => $clean((string)$after), 'main' => trim($main)];
    }

    /** Stylesheets the canvas loads so the design looks like the real site. */
    public static function canvasStyles(): array
    {
        $out = [];
        if ($fonts = DesignSystem::fontsUrl()) {
            $out[] = $fonts;
        }
        $theme = Theme::active();
        if ($theme !== '' && is_file(SLATE_ROOT . "/themes/$theme/assets/style.css")) {
            $out[] = asset_url("themes/$theme/assets/style.css");
        }
        return $out;
    }

    /** Reusable blocks saved by editors (Builder → Save as block). */
    public static function savedBlocks(): array
    {
        Options::lazy('builder_blocks');
        return array_values(array_filter((array)Options::get('builder_blocks', []), 'is_array'));
    }

    public static function saveBlock(string $label, string $html, string $css): ?string
    {
        $label = trim(mb_substr($label, 0, 60));
        if ($label === '' || $html === '') {
            return 'Give the block a name.';
        }
        if (!Auth::can('unfiltered_html')) {
            $html = Sanitizer::html($html);
        }
        $blocks = self::savedBlocks();
        $blocks[] = ['id' => bin2hex(random_bytes(5)), 'label' => $label, 'html' => $html, 'css' => self::cleanCss($css), 'created' => now()];
        Options::set('builder_blocks', array_slice($blocks, -200), false);
        return null;
    }

    public static function deleteBlock(string $id): void
    {
        Options::set('builder_blocks', array_values(array_filter(self::savedBlocks(), static fn($b) => ($b['id'] ?? '') !== $id)), false);
    }
}
