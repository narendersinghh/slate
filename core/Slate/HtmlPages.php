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
 * ]
 * and post content holds the page's text (search, excerpts, feeds). Visitors get the page as its own document with
 * Slate's SEO tags; loops bound to content ({{#query}}) are filled in by the template engine. Team members can edit
 * the page in place (core/Slate/PageEditor.php).
 */
final class HtmlPages
{
    /** True while a page that keeps its own design is being rendered (theme/design-system styles stay out). */
    public static bool $rendering = false;

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
        if (!$sanitized && !Auth::can('unfiltered_html') && PHP_SAPI !== 'cli') {
            $data['head'] = self::stripScripts((string)$data['head']);
            $data['body'] = self::stripScripts((string)$data['body']);
        }
        $change = ['fields' => ['_html' => $data], 'content' => self::text((string)$data['body'])] + $extra;
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
        $body = Templates::render((string)($d['body'] ?? ''), $ctx);
        self::$rendering = true;
        ob_start();
        echo '<!doctype html><html ' . ((string)($d['html_attrs'] ?? '') !== '' ? $d['html_attrs'] : html_attrs()) . '><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">';
        slate_head();
        echo "\n" . (string)($d['head'] ?? '') . "\n";
        if (class_exists(PageEditor::class) && PageEditor::enabledFor($post)) {
            echo PageEditor::assets($post);
        }
        echo '</head><body ' . (string)($d['body_attrs'] ?? '') . '>';
        slate_body_open();
        echo $body;
        slate_footer();
        echo '</body></html>';
        self::$rendering = false;
        return (string)ob_get_clean();
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
