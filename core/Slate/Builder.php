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

    /**
     * Save a design written as plain HTML + CSS (AI agents over MCP, imports). It opens in the builder as editable parts.
     * Returns null or an error message.
     */
    public static function saveHtml(int $postId, string $html, string $css, string $layout = 'theme'): ?string
    {
        if (!Auth::can('unfiltered_html')) {
            $html = Sanitizer::html($html);
        }
        $css = self::cleanCss($css);
        return self::save($postId, ['html' => $html, 'css' => $css, 'layout' => $layout, 'project' => self::projectFromHtml($html, $css)]);
    }

    /** A minimal GrapesJS project around HTML + CSS: the editor parses both into components and style rules on load. */
    public static function projectFromHtml(string $html, string $css): array
    {
        return ['assets' => [], 'styles' => [], 'symbols' => [], 'dataSources' => [],
            'pages' => [['frames' => [['component' => ['type' => 'wrapper', 'components' => $html], 'styles' => $css]]]]];
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

    /**
     * Ask the AI for builder-ready HTML. $mode: 'page' (a whole page), 'section' (one new section) or 'edit'
     * (rewrite $current following the instruction). Returns ['ok' => bool, 'html' => string, 'error' => ?string].
     */
    public static function ai(string $mode, string $instruction, string $current = '', array $context = []): array
    {
        if (!Ai::enabled()) {
            return ['ok' => false, 'error' => 'AI is not set up yet. Add a provider and key in Settings → AI assistant.'];
        }
        $instruction = trim(mb_substr($instruction, 0, 4000));
        if ($instruction === '') {
            return ['ok' => false, 'error' => 'Describe what you want.'];
        }
        $ds = DesignSystem::get();
        $colors = implode(', ', array_map(static fn($c) => ($c['name'] ?? $c['id']) . ' = var(--color-' . $c['id'] . ')', $ds['colors']));
        $forms = array_column(DB::all("SELECT slug, title FROM {{posts}} WHERE type = 'form' AND status = 'publish' LIMIT 20"), 'title', 'slug');
        $formList = $forms ? implode(', ', array_map(static fn($k, $v) => "{{form:$k}} ($v)", array_keys($forms), $forms)) : 'none yet';
        $system = <<<TXT
You design web pages for a website built with Slate CMS. You write the HTML that goes into its visual page builder, where people keep editing it.

Output rules:
- Reply with one HTML fragment only: no Markdown, no code fences, no <html>, <head> or <body>, no explanations.
- Build the page from sections: <section class="section"><div class="container">…</div></section>.
- Use the site's design system instead of fixed colours and fonts: colours {$colors}; fonts var(--font-heading) and var(--font-body);
  radius var(--radius); spacing var(--section-space). Ready classes: container, section, btn (primary button), btn-outline,
  eyebrow (small label above a heading), text-<colour id>, bg-<colour id>. Headings h1-h3 are already styled.
- Layout with inline style attributes (display:flex/grid, gap, padding, max-width, text-align). Make every layout work on
  phones: wrap rows (flex-wrap:wrap with flex:1 1 260px children) or use grid with repeat(auto-fit,minmax(240px,1fr)).
- Images: <img src="" alt="what the picture shows" data-ai-image="short description of the ideal photo" style="width:100%;height:auto;border-radius:var(--radius)">.
  Never invent image URLs; the editor swaps in real pictures from the media library.
- Real, specific copy (never lorem ipsum), short sentences, in the language of the request. Never use em dashes.
- No <script>, no <style>, no event attributes, no iframes, no external fonts or CSS.
- Dynamic content Slate fills in for visitors (use only when it fits): {{title}} (page title), {{excerpt}}, forms: {$formList},
  latest posts: {{#query type="post" limit="3"}}<article><h3><a href="{{url}}">{{title}}</a></h3><p>{{excerpt}}</p></article>{{/query}}.
TXT;
        $site = Options::get('site_title') . ' (' . Options::get('tagline') . ')';
        $page = trim((string)($context['title'] ?? ''));
        $prompt = match ($mode) {
            'edit' => "Rewrite this part of the page \"$page\" on the website $site.\nInstruction: $instruction\n\nCurrent HTML:\n$current\n\nReturn only the replacement HTML for this part.",
            'section' => "Design ONE new section for the page \"$page\" on the website $site.\nWhat it should contain: $instruction" . ($current !== '' ? "\n\nThe page so far, for context (do not repeat it):\n" . mb_substr($current, 0, 6000) : ''),
            default => "Design a complete page \"$page\" for the website $site.\nBrief: $instruction\nUse 4 to 8 sections that tell a clear story, ending with a call to action.",
        };
        $r = Ai::complete($prompt, $system, ['max_tokens' => 14000, 'min_tokens' => 8000, 'effort' => $mode === 'edit' ? 'low' : 'medium', 'timeout' => 240]);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error']];
        }
        return ['ok' => true, 'html' => self::cleanAiHtml((string)$r['text'])];
    }

    /** AI output: strip fences, scripts, event handlers, javascript: URLs and document wrappers; keep inline layout styles. */
    public static function cleanAiHtml(string $html): string
    {
        $html = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($html)));
        $html = preg_replace(['#<script\b.*?</script>#is', '#<(iframe|object|embed|link|meta|base|style)\b[^>]*>(.*?</\1>)?#is',
            '#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '#(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i'], ['', '', '', '$1="#"'], $html);
        if (preg_match('#<body[^>]*>(.*)</body>#is', (string)$html, $m)) {
            $html = $m[1];
        }
        return trim((string)preg_replace('#</?(html|head|body)\b[^>]*>#i', '', (string)$html));
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
