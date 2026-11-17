<?php
declare(strict_types=1);

namespace Slate;

/**
 * Pages that keep their own design: imported HTML/CSS/JS pages and pages written by AI (Goraiya, MCP).
 *
 * An item stores fields['_html'] = [
 *   'head'  => their stylesheets, styles, scripts and meta (title/charset/viewport/description are left to Slate's SEO),
 *   'body'  => the body HTML; editable elements carry data-se="<id>", repeating groups data-se-loop / data-se-item,
 *   'html_attrs', 'body_attrs', 'source' => 'import'|'ai', 'next' => next free element id, 'v' => 1,
 *   'design' => 'keep' (default: the page's own CSS, design-system CSS stays out) | 'site' (the page's own CSS is left
 *               out at render time and the site's design system styles it, see designHead()/designBody()) | 'partial'
 *               (the page's own CSS stays; the design system overrides only the groups in 'site_parts', see PARTS),
 *   'site_parts' => for 'partial': list of PARTS keys,
 *   'hints'  => hints read from the page's CSS: ['grid' => [class => columns], 'flex' => [class => 1], 'bg' => [class => 1]],
 * ]
 * and post content holds the page's text (search, excerpts, feeds). Visitors get the page as its own document with
 * Slate's SEO tags; loops bound to content ({{#query}}) are filled in by the template engine. Team members can edit
 * the page in place (core/Slate/PageEditor.php).
 */
final class HtmlPages
{
    /** True while a page that keeps its own design is being rendered (theme/design-system styles stay out). */
    public static bool $rendering = false;
    /** True once this response is a page with its own design: output filters (e.g. a theme's rebranding) must leave it alone. */
    public static bool $served = false;

    /** Elements whose text people edit in place. */
    public const TEXT_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'a', 'button', 'blockquote', 'figcaption', 'td', 'th', 'label', 'dt', 'dd', 'span', 'small', 'strong', 'em', 'cite', 'summary'];
    /** Class fragments of well-known sliders/carousels: their item lists become editable loops. */
    private const SLIDER_HINTS = ['swiper-wrapper', 'slick-track', 'slick-list', 'carousel-inner', 'splide__list', 'owl-stage', 'glide__slides', 'flickity-slider', 'slides', 'slider'];

    public static function data(array $post): ?array
    {
        $d = $post['fields']['_html'] ?? null;
        return is_array($d) && isset($d['body']) ? $d : null;
    }

    public static function active(array $post): bool
    {
        return self::data($post) !== null;
    }

    // ------------------------------------------------------------------ parsing

    /**
     * Turn a full HTML document into page data. $rewrite maps every URL found in src/href/srcset/url() (relative ones
     * are resolved by the caller, e.g. to the imported asset folder or to another imported page).
     */
    public static function fromDocument(string $html, ?callable $rewrite = null, string $source = 'import'): array
    {
        $doc = self::load($html);
        $rewrite ??= static fn(string $u): string => $u;
        $xp = new \DOMXPath($doc);

        $title = '';
        $description = '';
        $head = '';
        foreach ($xp->query('/html/head/*') ?: [] as $n) {
            if (!$n instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($n->tagName);
            if ($tag === 'title') {
                $title = trim($n->textContent);
                continue;
            }
            if ($tag === 'meta') {
                $name = strtolower($n->getAttribute('name'));
                if ($n->hasAttribute('charset') || in_array($name, ['viewport', 'description', 'robots', 'generator'], true) || $n->hasAttribute('http-equiv')) {
                    if ($name === 'description') {
                        $description = trim($n->getAttribute('content'));
                    }
                    continue;
                }
            }
            if ($tag === 'base') {
                continue;
            }
            self::rewriteNode($n, $rewrite);
            $head .= $doc->saveHTML($n) . "\n";
        }
        $body = $xp->query('/html/body')->item(0);
        $bodyAttrs = $body instanceof \DOMElement ? self::attrString($body) : '';
        $htmlEl = $doc->documentElement;
        $htmlAttrs = $htmlEl instanceof \DOMElement ? self::attrString($htmlEl, ['lang', 'dir', 'class', 'data-theme']) : '';
        if ($body) {
            self::rewriteNode($body, $rewrite);
        }
        $data = ['head' => trim($head), 'body' => $body ? self::inner($doc, $body) : '', 'html_attrs' => $htmlAttrs, 'body_attrs' => $bodyAttrs,
            'source' => $source, 'next' => 1, 'v' => 1];
        $data = self::annotate($data);
        return ['title' => $title, 'description' => $description, 'data' => $data];
    }

    /** Give every editable element a stable data-se id and mark repeating groups (sliders, card lists) as loops. */
    public static function annotate(array $data): array
    {
        [$doc, $root] = self::fragment((string)$data['body']);
        $next = max(1, (int)($data['next'] ?? 1));
        $xp = new \DOMXPath($doc);
        // Loops first, so their items get ids too.
        foreach ($xp->query('.//*', $root) ?: [] as $el) {
            if (!$el instanceof \DOMElement || $el->hasAttribute('data-se-loop') || self::insideLoop($el)) {
                continue;
            }
            if (self::isLoop($el)) {
                $el->setAttribute('data-se-loop', 'l' . $next++);
                foreach ($el->childNodes as $c) {
                    if ($c instanceof \DOMElement) {
                        $c->setAttribute('data-se-item', '1');
                    }
                }
            }
        }
        foreach ($xp->query('.//*', $root) ?: [] as $el) {
            if (!$el instanceof \DOMElement || $el->hasAttribute('data-se')) {
                continue;
            }
            $tag = strtolower($el->tagName);
            $editable = in_array($tag, ['img', 'svg', 'video', 'picture', 'iframe'], true)
                || ($tag === 'i' && preg_match('/\b(fa|fas|far|fab|bi|icon|material-icons)\b/', $el->getAttribute('class')))
                || (in_array($tag, self::TEXT_TAGS, true) && self::hasOwnText($el))
                || ($tag === 'div' && self::hasOwnText($el) && !self::hasBlockChild($el));
            if ($editable && !self::insideSvg($el)) {
                $el->setAttribute('data-se', 'e' . $next++);
            }
        }
        $data['body'] = self::inner($doc, $root);
        $data['next'] = $next;
        return $data;
    }

    private static function isLoop(\DOMElement $el): bool
    {
        $kids = [];
        foreach ($el->childNodes as $c) {
            if ($c instanceof \DOMElement && !in_array(strtolower($c->tagName), ['script', 'style', 'template'], true)) {
                $kids[] = $c;
            }
        }
        $class = ' ' . strtolower($el->getAttribute('class')) . ' ';
        foreach (self::SLIDER_HINTS as $h) {
            if (str_contains($class, ' ' . $h . ' ') && count($kids) >= 1) {
                return true;
            }
        }
        if (count($kids) < 3 || in_array(strtolower($el->tagName), ['ul', 'ol'], true) && !self::richItems($kids)) {
            return false; // plain bullet lists stay text
        }
        $sig = static fn(\DOMElement $e): string => strtolower($e->tagName) . '|' . implode(' ', array_slice(array_filter(explode(' ', preg_replace('/\b(active|is-active|current|first|last|show|selected)\b/', '', $e->getAttribute('class')))), 0, 2));
        $first = $sig($kids[0]);
        foreach ($kids as $k) {
            if ($sig($k) !== $first) {
                return false;
            }
        }
        // Items must be "cards": more than one element inside (heading + text, image + caption…).
        return self::richItems($kids);
    }

    private static function richItems(array $kids): bool
    {
        foreach ($kids as $k) {
            $n = 0;
            foreach ($k->getElementsByTagName('*') as $_) {
                $n++;
            }
            if ($n < 2) {
                return false;
            }
        }
        return true;
    }

    private static function hasOwnText(\DOMElement $el): bool
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof \DOMText && trim($c->textContent) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function hasBlockChild(\DOMElement $el): bool
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof \DOMElement && in_array(strtolower($c->tagName), ['div', 'section', 'p', 'ul', 'ol', 'table', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                return true;
            }
        }
        return false;
    }

    private static function insideLoop(\DOMElement $el): bool
    {
        for ($p = $el->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
            if ($p->hasAttribute('data-se-loop')) {
                return true;
            }
        }
        return false;
    }

    private static function insideSvg(\DOMElement $el): bool
    {
        for ($p = $el->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
            if (strtolower($p->tagName) === 'svg') {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------ saving

    /** Save page data on an item (revision first). Users without "unfiltered_html" cannot store scripts. */
    /** $sanitized: the caller only changed the stored page through checked edits (PageEditor), so its existing scripts stay. */
    public static function save(int $postId, array $data, array $extra = [], bool $sanitized = false): void
    {
        $post = Posts::find($postId);
        if (!$post) {
            return;
        }
        // A rewrite of the whole page (Goraiya, MCP) keeps the chosen design and where the page was imported from.
        $old = self::data($post) ?? [];
        foreach (['design', 'site_parts', 'import'] as $k) {
            if (!array_key_exists($k, $data) && isset($old[$k])) {
                $data[$k] = $old[$k];
            }
        }
        if (($mode = self::design($data)) !== 'keep' && (!isset($data['hints']) || (isset($old['head']) && ($data['head'] ?? null) !== $old['head']))) {
            unset($data['hints']);
            $data = self::withDesign($data, $mode, self::parts($data));
        }
        if (!$sanitized && !Auth::can('unfiltered_html') && PHP_SAPI !== 'cli') {
            $data['head'] = self::stripScripts((string)$data['head']);
            $data['body'] = self::stripScripts((string)$data['body']);
        }
        $change = ['fields' => array_merge((array)($extra['fields'] ?? []), ['_html' => $data]), 'content' => self::text((string)$data['body'])] + $extra;
        Revisions::snapshot($post, $change);
        Posts::save($change, $postId);
        PageCache::purgeContent(Posts::find($postId));
    }

    /** Readable text of the body for search, excerpts and feeds. */
    public static function text(string $body): string
    {
        $body = (string)preg_replace(['#<(script|style|noscript|template|svg)\b.*?</\1>#is', '/\{\{.*?\}\}/s'], ' ', $body);
        $body = (string)preg_replace('#<(br|/p|/h[1-6]|/li|/div|/section)[^>]*>#i', "\n", $body);
        return trim((string)preg_replace(["/[ \t]+/", "/\n\s*\n+/"], [' ', "\n\n"], html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    public static function stripScripts(string $html): string
    {
        $html = (string)preg_replace('#<script\b.*?</script>#is', '', $html);
        $html = (string)preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        return (string)preg_replace('#(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1="#"', $html);
    }

    // ------------------------------------------------------------------ rendering

    /** The page as its own document, with Slate's SEO head, admin bar and (for team members) the page editor. */
    public static function render(array $post, array $ctx): string
    {
        $d = (array)self::data($post);
        $body = self::designBody($d, Templates::render((string)($d['body'] ?? ''), $ctx));
        self::$rendering = true;
        self::$served = true;
        ob_start();
        echo '<!doctype html><html ' . ((string)($d['html_attrs'] ?? '') !== '' ? $d['html_attrs'] : html_attrs()) . '><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">';
        slate_head();
        echo self::designHead($d);
        if (class_exists(PageEditor::class) && PageEditor::enabledFor($post)) {
            echo PageEditor::assets($post);
        }
        echo '</head><body ' . self::bodyAttrs($d) . '>';
        slate_body_open();
        echo $body;
        slate_footer();
        echo '</body></html>';
        self::$rendering = false;
        return (string)ob_get_clean();
    }

    // ------------------------------------------------------------------ design: the page's own CSS or the site's styles

    public const DESIGNS = ['keep' => "Keep the original design", 'partial' => "Use my site's styles for...", 'site' => "Use my site's styles completely"];
    /** Groups of the design system a 'partial' page takes over; everything else keeps the page's own CSS. */
    public const PARTS = [
        'typography' => ['Typography', 'Fonts, heading sizes (h1 to h6), body text, line height and letter spacing from your type scale'],
        'colors' => ['Colours', 'Text, heading and link colours from your palette (sections with their own background keep their colours)'],
        'backgrounds' => ['Section backgrounds', 'The page and its sections use your background colour (background photos stay)'],
        'buttons' => ['Buttons', 'Buttons and button-like links get your global btn / btn-outline look'],
        'links' => ['Links', 'Text links in your primary colour'],
        'images' => ['Images', "Your site's corner radius on images"],
        'spacing' => ['Spacing and containers', 'Your container width and section spacing'],
    ];

    /** Inline style properties kept in the 'site' design (layout only; colours, fonts, borders, backgrounds go). */
    private const LAYOUT_PROPS = '/^(display|flex|flex-[a-z-]+|grid|grid-[a-z-]+|gap|row-gap|column-gap|align-[a-z-]+|justify-[a-z-]+|place-[a-z-]+|order|text-align|width|max-width|min-width|aspect-ratio|object-fit|object-position|background-image|background-size|background-position|background-repeat)$/'; // background images are content (hero photos); colours and fonts follow the site
    /** Icon font stylesheets stay in the 'site' design, so icons keep showing. */
    private const ICON_CSS = '/font-?awesome|bootstrap-icons|material-?icons|material-symbols|remixicon|boxicons|ionicons|line-?awesome|icofont|themify-icons|phosphor/i';

    public static function design(array $d): string
    {
        $m = (string)($d['design'] ?? 'keep');
        return $m === 'site' ? 'site' : ($m === 'partial' && self::parts($d) ? 'partial' : 'keep');
    }

    /** The chosen PARTS of a 'partial' page (cleaned, in PARTS order). */
    public static function parts(array $d): array
    {
        return self::cleanParts((array)($d['site_parts'] ?? []));
    }

    public static function cleanParts(array $parts): array
    {
        return array_values(array_intersect(array_keys(self::PARTS), array_map('strval', $parts)));
    }

    /** Page data with the design switched; layout hints are read from the page's CSS the first time 'site' is chosen. */
    public static function withDesign(array $d, string $mode, array $parts = []): array
    {
        $parts = self::cleanParts($parts);
        $d['design'] = $mode === 'site' ? 'site' : ($mode === 'partial' && $parts ? 'partial' : 'keep');
        if ($d['design'] === 'partial') {
            $d['site_parts'] = $parts;
        } else {
            unset($d['site_parts']);
        }
        if ($d['design'] !== 'keep' && (!isset($d['hints']) || ($d['design'] === 'partial' && !isset($d['hints']['bg'])))) {
            $d['hints'] = self::layoutHints((string)($d['head'] ?? ''), (string)($d['body'] ?? ''));
        }
        return $d;
    }

    /** What follows Slate's own head tags. 'keep' prints the page's head exactly as before. */
    public static function designHead(array $d): string
    {
        if (self::design($d) === 'partial') {
            // The page's own CSS first, then the design-system variables and the overrides for the chosen groups.
            return "\n" . (string)($d['head'] ?? '') . "\n" . self::partsHead(self::parts($d)) . "\n";
        }
        if (self::design($d) !== 'site') {
            return "\n" . (string)($d['head'] ?? '') . "\n";
        }
        return "\n" . DesignSystem::head() . "\n" . '<style id="slate-site-base">' . self::siteBaseCss() . "</style>\n" . self::siteHead((string)($d['head'] ?? '')) . "\n";
    }

    public static function designBody(array $d, string $body): string
    {
        return match (self::design($d)) {
            'site' => self::siteBody($body, (array)($d['hints'] ?? [])),
            'partial' => self::partsBody($body, self::parts($d), (array)($d['hints'] ?? [])),
            default => $body,
        };
    }

    public static function bodyAttrs(array $d): string
    {
        $attrs = (string)($d['body_attrs'] ?? '');
        $mode = self::design($d);
        if ($mode === 'keep') {
            return $attrs;
        }
        $class = $mode === 'site' ? 'slate-site-styles' : 'slate-parts';
        if (preg_match('/(^|\s)class="([^"]*)"/', $attrs)) {
            return (string)preg_replace('/(^|\s)class="([^"]*)"/', '$1class="$2 ' . $class . '"', $attrs, 1);
        }
        return trim($attrs . ' class="' . $class . '"');
    }

    // ------------------------------------------------------------------ design: 'partial' (chosen groups only)

    /** Design-system variables (and fonts when typography is chosen) plus the overrides, printed after the page's CSS. */
    public static function partsHead(array $parts): string
    {
        $ds = DesignSystem::get();
        $out = '';
        if (in_array('typography', $parts, true) && ($url = DesignSystem::fontsUrl($ds))) {
            $out .= '<link rel="stylesheet" href="' . e($url) . '">';
        }
        // Only the :root variables: the design system's element rules and classes would restyle everything else.
        $vars = preg_match('/^:root\{[^}]*\}/', DesignSystem::css($ds), $m) ? $m[0] : '';
        return $out . '<style id="slate-site-parts">' . $vars . self::partsCss($parts, $ds) . '</style>';
    }

    /**
     * Overrides for the chosen groups, scoped to body.slate-parts with !important on just the properties of each group,
     * so they win over the page's own stylesheets. Elements inside a section with its own background (sl-own-bg) keep
     * their text and link colours unless "Section backgrounds" is chosen too.
     */
    public static function partsCss(array $parts, ?array $ds = null): string
    {
        $ds ??= DesignSystem::get();
        $b = 'html body.slate-parts';
        $own = in_array('backgrounds', $parts, true) ? '' : ':not(.sl-own-bg,.sl-own-bg *)';
        $noIcon = ':not(i,[class*="icon"],[class*="fa-"],.fa,[class^="bi-"],[class*=" bi-"],.material-icons,.material-symbols-outlined)';
        // Slate's own UI on the page (admin bar, page editor) keeps its look.
        $ui = ':not(#slate-bar,#slate-bar *,#slate-picker *,#slate-fmt *,.slate-toast *,.se-ui,.se-ui *,.se-tb *,.se-pop *)';
        $noIcon .= $ui;
        $own .= $ui;
        $t = $ds['type'];
        $css = '';
        foreach ($parts as $part) {
            switch ($part) {
                case 'typography':
                    $body = $t['body'];
                    $css .= "$b, $b :is(p,li,dd,dt,td,th,blockquote,figcaption,label,small,cite,a,span,div,input,textarea,select,button)$noIcon{font-family:var(--font-body)!important}"
                        . "$b :is(p,li,dd,dt,td,th,blockquote,figcaption,label)$ui{font-size:var(--fs-body)!important;line-height:{$body['line']}!important;letter-spacing:{$body['tracking']}em!important}"
                        . "$b :is(small,figcaption,cite)$ui{font-size:var(--fs-small)!important}";
                    foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $h) {
                        $css .= "$b $h$ui{font:var(--type-$h)!important;letter-spacing:{$t[$h]['tracking']}em!important}";
                    }
                    $css .= "$b :is(h1,h2,h3,h4,h5,h6) *$noIcon{font-family:inherit!important}";
                    break;
                case 'colors':
                    $css .= "$b{color:var(--color-text)!important}"
                        . "$b :is(p,li,dd,dt,td,th,blockquote,figcaption,label,small,cite,span,strong,em,div)$own:not(.btn *,.btn-outline *,button *){color:var(--color-text)!important}"
                        . "$b :is(h1,h2,h3,h4,h5,h6)$own{color:var(--color-secondary,var(--color-text))!important}"
                        . "$b a:not(.btn,.btn-outline)$own{color:var(--color-primary)!important}";
                    break;
                case 'backgrounds':
                    $css .= "$b{background-color:var(--color-background,#fff)!important}"
                        . "$b .sl-own-bg{background-color:var(--color-background,#fff)!important}";
                    break;
                case 'links':
                    $css .= "$b :is(p,li,dd,td,blockquote,figcaption,span,div) a:not(.btn,.btn-outline)$own{color:var(--color-primary)!important;text-decoration:underline!important;text-underline-offset:.2em}"
                        . "$b :is(p,li,dd,td,blockquote,figcaption,span,div) a:not(.btn,.btn-outline):hover{opacity:.8}";
                    break;
                case 'buttons':
                    $cls = [];
                    foreach ($ds['classes'] as $c) {
                        $cls[$c['name']] = $c;
                    }
                    foreach (['btn' => "display:inline-flex;align-items:center;gap:.5em;padding:.9em 1.6em;border-radius:var(--radius-button);background:var(--color-primary);color:#fff;font:var(--type-button);text-decoration:none;border:0",
                        'btn-outline' => "display:inline-flex;align-items:center;padding:.85em 1.5em;border-radius:var(--radius-button);border:1.5px solid currentColor;color:var(--color-primary);background:transparent;text-decoration:none"] as $name => $fallback) {
                        $css .= "$b .$name{" . self::important((string)($cls[$name]['css'] ?? $fallback)) . 'cursor:pointer;box-shadow:none!important}';
                        if (($cls[$name]['hover'] ?? '') !== '') {
                            $css .= "$b .$name:hover{" . self::important((string)$cls[$name]['hover']) . '}';
                        } else {
                            $css .= "$b .$name:hover{filter:brightness(1.08)}";
                        }
                    }
                    break;
                case 'images':
                    $css .= "$b img$ui:not([class*=\"logo\"],[src*=\"logo\"],[width=\"16\"],[width=\"24\"],[width=\"32\"]){border-radius:var(--radius)!important}";
                    break;
                case 'spacing':
                    $css .= "$b .sl-container{width:100%!important;max-width:var(--container)!important;margin-inline:auto!important}"
                        . "$b .sl-section{padding-block:var(--section-space)!important}";
                    break;
            }
        }
        return $css;
    }

    /** "a:b;c:d" -> "a:b!important;c:d!important;" (declarations only, from the design system's own classes). */
    private static function important(string $decls): string
    {
        $out = '';
        foreach (explode(';', str_replace("\n", '', $decls)) as $decl) {
            $decl = trim(str_replace(['!important', '{', '}', '<'], '', $decl));
            if ($decl !== '' && str_contains($decl, ':')) {
                $out .= $decl . '!important;';
            }
        }
        return $out;
    }

    /**
     * The body for a 'partial' page: nothing is removed. Classes are only added where a chosen group needs them:
     * "btn"/"btn-outline" on buttons, "sl-container"/"sl-section" for spacing, "sl-own-bg" on sections that have their
     * own background (from the page's CSS or an inline style) so colours there stay readable.
     */
    public static function partsBody(string $html, array $parts, array $hints = []): string
    {
        $buttons = in_array('buttons', $parts, true);
        $spacing = in_array('spacing', $parts, true);
        $colours = (bool)array_intersect(['colors', 'links', 'backgrounds'], $parts);
        if (!$buttons && !$spacing && !$colours) {
            return $html;
        }
        [$doc, $root] = self::fragment($html);
        $bg = (array)($hints['bg'] ?? []);
        foreach (iterator_to_array($root->getElementsByTagName('*')) as $el) {
            /** @var \DOMElement $el */
            $tag = strtolower($el->tagName);
            if ($tag === 'svg' || self::insideSvg($el)) {
                continue;
            }
            $classes = preg_split('/\s+/', trim($el->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);
            $cls = ' ' . strtolower(implode(' ', $classes)) . ' ';
            $add = [];
            if ($buttons && ($tag === 'button' || ($tag === 'input' && in_array(strtolower($el->getAttribute('type')), ['submit', 'button'], true))
                || ($tag === 'a' && (preg_match('/[\s_-](btn|button|cta)[\s_-]/', $cls) || strtolower($el->getAttribute('role')) === 'button')))) {
                $add[] = preg_match('/outline|secondary|ghost|border|light|link/', $cls) && $tag === 'a' ? 'btn-outline' : 'btn';
            }
            if ($spacing && preg_match('/[\s_-](wrapper|wrap|inner|container|container-fluid|site-width|page-width|content-width)\s/', $cls)) {
                $add[] = 'sl-container';
            }
            if ($spacing && $tag === 'section') {
                $add[] = 'sl-section';
            }
            if ($colours && (array_intersect_key($bg, array_flip($classes))
                || preg_match('/(^|;)\s*background(-color|-image)?\s*:\s*(?!none|transparent|inherit|initial|unset)/i', $el->getAttribute('style')))) {
                $add[] = 'sl-own-bg';
            }
            $add = array_diff(array_unique($add), $classes);
            if ($add) {
                $el->setAttribute('class', trim(implode(' ', array_merge($classes, $add))));
            }
        }
        return self::inner($doc, $root);
    }

    /** The page's head without its stylesheets (icon fonts stay); scripts, icons and meta stay. */
    public static function siteHead(string $head): string
    {
        $head = (string)preg_replace('#<style\b[^>]*>.*?</style>\s*#is', '', $head);
        return (string)preg_replace_callback('#<link\b[^>]*>\s*#i', static function ($m) {
            $tag = $m[0];
            $rel = preg_match('#\brel=["\']([^"\']*)#i', $tag, $r) ? strtolower($r[1]) : '';
            $isCss = str_contains($rel, 'stylesheet') || (str_contains($rel, 'preload') && preg_match('#\bas=["\']?style#i', $tag));
            return $isCss && !preg_match(self::ICON_CSS, $tag) ? '' : $tag;
        }, $head);
    }

    /**
     * The body for the 'site' design: the page's own <style>/stylesheets go, inline styles keep layout properties only,
     * button-like links and buttons get the global "btn" class (outline/secondary ones "btn-outline"), wrappers get
     * "container", and grids/rows found in the original CSS get "sl-grid"/"sl-row" so the structure still reads well.
     * Editable ids (data-se) and repeating groups are untouched.
     */
    public static function siteBody(string $html, array $hints = []): string
    {
        [$doc, $root] = self::fragment($html);
        $grid = (array)($hints['grid'] ?? []);
        $flex = (array)($hints['flex'] ?? []);
        $remove = [];
        foreach (iterator_to_array($root->getElementsByTagName('*')) as $el) {
            /** @var \DOMElement $el */
            $tag = strtolower($el->tagName);
            if ($tag === 'style' || ($tag === 'link' && str_contains(strtolower($el->getAttribute('rel')), 'stylesheet') && !preg_match(self::ICON_CSS, $el->getAttribute('href')))) {
                $remove[] = $el;
                continue;
            }
            if (self::insideSvg($el) || $tag === 'svg') {
                continue;
            }
            if ($el->hasAttribute('style')) {
                $keep = [];
                foreach (explode(';', $el->getAttribute('style')) as $decl) {
                    [$prop, $val] = array_pad(explode(':', $decl, 2), 2, '');
                    $prop = strtolower(trim($prop));
                    if ($prop !== '' && trim($val) !== '' && preg_match(self::LAYOUT_PROPS, $prop)) {
                        $keep[] = $prop . ':' . trim($val);
                    } elseif ($prop === 'background' && preg_match('/url\(\s*([\'"]?)[^\'")]+\1\s*\)/i', $val, $bg)) {
                        // Shorthand with an image: keep the image (content), drop the colour.
                        $keep[] = 'background-image:' . $bg[0];
                        $keep[] = 'background-size:cover';
                        $keep[] = 'background-position:center';
                    }
                }
                $keep ? $el->setAttribute('style', implode(';', $keep)) : $el->removeAttribute('style');
            }
            $classes = preg_split('/\s+/', trim($el->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);
            $add = [];
            $cls = ' ' . strtolower(implode(' ', $classes)) . ' ';
            if ($tag === 'button' || ($tag === 'input' && in_array(strtolower($el->getAttribute('type')), ['submit', 'button'], true))
                || ($tag === 'a' && (preg_match('/[\s_-](btn|button|cta)[\s_-]/', $cls) || strtolower($el->getAttribute('role')) === 'button'))) {
                $add[] = preg_match('/outline|secondary|ghost|border|light|link/', $cls) && $tag === 'a' ? 'btn-outline' : 'btn';
            }
            if (preg_match('/[\s_-](wrapper|wrap|inner|container|container-fluid|site-width|page-width|content-width)\s/', $cls)) {
                $add[] = 'container';
            }
            foreach ($classes as $c) {
                if (isset($grid[$c])) {
                    $add[] = 'sl-grid';
                    $n = (int)$grid[$c];
                    if ($n >= 2 && $n <= 6) {
                        $add[] = 'sl-cols-' . $n;
                    }
                    break;
                }
                if (isset($flex[$c])) {
                    $add[] = 'sl-row';
                    break;
                }
            }
            if ($el->hasAttribute('data-se-loop') && !array_intersect($add, ['sl-grid', 'sl-row']) && !preg_match('/swiper|slick|carousel|splide|owl|glide|flickity|slide/', $cls)) {
                $add[] = 'sl-grid';
            }
            $add = array_diff(array_unique($add), $classes);
            if ($add) {
                $el->setAttribute('class', trim(implode(' ', array_merge($classes, $add))));
            }
        }
        foreach ($remove as $el) {
            $el->parentNode?->removeChild($el);
        }
        return self::inner($doc, $root);
    }

    /**
     * Layout hints from the page's CSS (<style> blocks and stylesheets stored with an import): classes laid out as
     * grids (with their column count) or as rows (flex, not column direction). Used by the 'site' design.
     */
    public static function layoutHints(string $head, string $body): array
    {
        $css = '';
        if (preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $head . $body, $m)) {
            $css .= implode("\n", $m[1]);
        }
        $uploads = url('content/uploads/');
        if (preg_match_all('#<link\b[^>]*\bhref=["\']([^"\']+\.css)(?:\?[^"\']*)?["\'][^>]*>#i', $head . $body, $m)) {
            foreach ($m[1] as $href) {
                $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (str_starts_with($href, $uploads)) {
                    $rel = rawurldecode(substr($href, strlen($uploads)));
                    $file = SLATE_UPLOADS . '/' . $rel;
                    if (!str_contains($rel, '..') && is_file($file) && filesize($file) < 3 * 1024 * 1024) {
                        $css .= "\n" . (string)file_get_contents($file);
                    }
                }
            }
        }
        $css = (string)preg_replace('#/\*.*?\*/#s', '', $css);
        $out = ['grid' => [], 'flex' => [], 'bg' => []];
        if (preg_match_all('/([^{}@;]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER)) {
            foreach ($rules as [, $selectors, $decls]) {
                $decls = strtolower($decls);
                // Sections that paint their own background (colour, gradient or photo).
                if (preg_match('/(^|;)\s*background(-color|-image)?\s*:\s*(?!none|transparent|inherit|initial|unset|#fff\b|#ffffff\b|white\b)/', $decls)) {
                    foreach (explode(',', $selectors) as $sel) {
                        if (preg_match('/^\.([a-zA-Z_][\w-]*)$/', trim($sel), $cm) && count($out['bg']) < 200) {
                            $out['bg'][$cm[1]] = 1;
                        }
                    }
                }
                $kind = preg_match('/(^|;)\s*display\s*:\s*(inline-)?grid/', $decls) ? 'grid'
                    : (preg_match('/(^|;)\s*display\s*:\s*(inline-)?flex/', $decls) && !preg_match('/flex-direction\s*:\s*column/', $decls) ? 'flex' : '');
                if ($kind === '') {
                    continue;
                }
                $cols = 0;
                if ($kind === 'grid' && preg_match('/grid-template-columns\s*:\s*([^;]+)/', $decls, $gm)) {
                    $cols = preg_match('/repeat\(\s*(\d+)/', $gm[1], $rm) ? (int)$rm[1] : count(preg_split('/\s+(?![^(]*\))/', trim($gm[1])));
                }
                foreach (explode(',', $selectors) as $sel) {
                    if (preg_match('/\.([a-zA-Z_][\w-]*)\s*$/', trim($sel), $cm) && count($out['grid']) + count($out['flex']) < 300) {
                        $out[$kind][$cm[1]] = $kind === 'grid' ? max($cols, (int)($out['grid'][$cm[1]] ?? 0)) : 1;
                    }
                }
            }
        }
        foreach (array_keys($out['grid']) as $c) {
            unset($out['flex'][$c]);
        }
        return $out;
    }

    /**
     * Base layout for the 'site' design. Every rule is wrapped in :where() (no specificity), so the design system's
     * element styles and global classes (btn, container, ...) always win; this only fills the gaps the removed CSS left.
     */
    public static function siteBaseCss(): string
    {
        $b = ':where(.slate-site-styles)';
        return "$b{margin:0;padding-inline:max(24px,calc((100% - var(--container,1200px)) / 2));font:var(--type-body);color:var(--color-text);background:var(--color-background,#fff)}"
            . "$b :where(*,*::before,*::after){box-sizing:border-box}"
            . implode('', array_map(static fn($h) => "$b :where($h){font:var(--type-$h);margin:0 0 .5em}", ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']))
            . "$b :where(p,ul,ol,dl,figure,table,form){margin:0 0 1em}"
            . "$b :where(ul,ol){padding-left:1.4em}"
            . "$b :where(small){font:var(--type-small)}"
            . "$b :where(a){color:var(--color-primary)}"
            . "$b :where(img,video){max-width:100%;height:auto;vertical-align:middle}"
            . "$b :where(img){border-radius:var(--radius)}"
            . "$b :where(blockquote){margin:1.5em 0;padding:.4em 0 .4em 1.25em;border-left:3px solid var(--color-primary);font-family:var(--font-heading);font-size:1.25em;line-height:1.5}"
            . "$b :where(blockquote cite,figcaption){display:block;margin-top:.5em;font:var(--type-small);color:var(--color-muted)}"
            . "$b :where(table){width:100%;border-collapse:collapse}$b :where(th,td){padding:.6em .8em;border-bottom:1px solid rgba(0,0,0,.1);text-align:left}"
            . "$b :where(hr){border:0;border-top:1px solid rgba(0,0,0,.12);margin:2em 0}"
            . "$b :where(input,select,textarea){font:inherit;padding:.65em .85em;border:1px solid rgba(0,0,0,.2);border-radius:var(--radius-button);max-width:100%}"
            . "$b :where(.btn,.btn-outline){display:inline-flex;align-items:center;gap:.5em;padding:.9em 1.6em;border-radius:var(--radius-button);font:var(--type-button);text-decoration:none;cursor:pointer}"
            . "$b :where(.btn){background:var(--color-primary);color:#fff;border:0}$b :where(.btn-outline){border:1.5px solid currentColor;color:var(--color-primary);background:none}"
            . "$b > :where(section,main>section,main>div){padding-block:calc(var(--section-space,80px) / 2)}"
            . "$b > :where(header,footer){padding-block:20px}"
            . "$b > :where(header){display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px}"
            . "$b :where(nav){display:flex;flex-wrap:wrap;align-items:center;gap:.4em 1.4em}$b :where(nav ul){display:flex;flex-wrap:wrap;gap:.4em 1.4em;list-style:none;margin:0;padding:0}"
            . "$b :where(nav a){color:inherit;text-decoration:none}"
            . "$b :where(.container){width:100%;max-width:var(--container,1200px);margin-inline:auto}"
            . "$b :where(.sl-row){display:flex;flex-wrap:wrap;align-items:center;gap:16px}"
            . "$b :where(ul.sl-grid,ol.sl-grid,ul.sl-row,ol.sl-row){list-style:none;padding:0}"
            . "$b :where(.sl-grid){display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:24px;margin-block:1em}"
            . "@media (min-width:760px){" . implode('', array_map(static fn($n) => "$b :where(.sl-cols-$n){grid-template-columns:repeat($n,minmax(0,1fr))}", [2, 3, 4, 5, 6])) . '}'
            . "$b :where(.sl-grid>:has(>*+*)){padding:20px;border:1px solid rgba(0,0,0,.08);border-radius:var(--radius);background:var(--color-background,#fff)}"
            . "$b :where(.sl-grid>*>:last-child){margin-bottom:0}";
    }

    // ------------------------------------------------------------------ DOM helpers

    public static function load(string $html): \DOMDocument
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        foreach ($doc->childNodes as $c) {
            if ($c instanceof \DOMProcessingInstruction) {
                $doc->removeChild($c);
            }
        }
        return $doc;
    }

    /** Parse a body fragment: [document, wrapper element]. */
    public static function fragment(string $html): array
    {
        $doc = self::load('<!doctype html><html><head><meta charset="utf-8"></head><body><div id="__slate_root">' . $html . '</div></body></html>');
        return [$doc, $doc->getElementById('__slate_root')];
    }

    public static function inner(\DOMDocument $doc, \DOMNode $el): string
    {
        $out = '';
        foreach ($el->childNodes as $c) {
            $out .= $doc->saveHTML($c);
        }
        // libxml percent-encodes "{" and "}" in href/src/action: give template tags ({{url}}, {{image_url}}) back.
        return str_contains($out, '%7B%7B') ? (string)preg_replace_callback('/%7B%7B([^"\'<>\s]*?)%7D%7D/',
            static fn($m) => '{{' . rawurldecode($m[1]) . '}}', $out) : $out;
    }

    private static function attrString(\DOMElement $el, ?array $only = null): string
    {
        $parts = [];
        foreach ($el->attributes as $a) {
            if ($only !== null && !in_array(strtolower($a->name), $only, true)) {
                continue;
            }
            if (str_starts_with(strtolower($a->name), 'on')) {
                continue;
            }
            $parts[] = $a->name . '="' . htmlspecialchars($a->value, ENT_QUOTES) . '"';
        }
        return implode(' ', $parts);
    }

    /** Rewrite URLs in src, href, srcset, poster, data-src(set), inline style url() and <style> blocks. */
    private static function rewriteNode(\DOMNode $root, callable $rewrite): void
    {
        $css = static fn(string $s): string => (string)preg_replace_callback('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
            static fn($m) => 'url(' . $m[1] . $rewrite(trim($m[2])) . $m[1] . ')', $s);
        $els = $root instanceof \DOMElement ? array_merge([$root], iterator_to_array($root->getElementsByTagName('*'))) : [];
        foreach ($els as $el) {
            foreach (['src', 'href', 'poster', 'data-src', 'data-bg', 'data-background', 'action'] as $at) {
                if ($el->hasAttribute($at)) {
                    $el->setAttribute($at, $rewrite($el->getAttribute($at)));
                }
            }
            foreach (['srcset', 'data-srcset'] as $at) {
                if ($el->hasAttribute($at)) {
                    $el->setAttribute($at, implode(', ', array_map(static function ($part) use ($rewrite) {
                        $bits = preg_split('/\s+/', trim($part), 2);
                        return $rewrite($bits[0]) . (isset($bits[1]) ? ' ' . $bits[1] : '');
                    }, explode(',', $el->getAttribute($at)))));
                }
            }
            if ($el->hasAttribute('style')) {
                $el->setAttribute('style', $css($el->getAttribute('style')));
            }
            if (strtolower($el->tagName) === 'style') {
                $el->textContent = $css($el->textContent);
            }
        }
    }
}
