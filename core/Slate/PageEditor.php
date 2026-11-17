<?php
declare(strict_types=1);

namespace Slate;

/**
 * On-page editor for HtmlPages (imported / AI-written pages): team members click elements on the live page and edit
 * them in place. The browser sends a batch of small operations; apply() checks every one of them against the stored
 * page (fields['_html']['body']) and saves the result through HtmlPages::save (one revision per save).
 *
 * Operations (ids are data-se="eN" for elements, data-se-loop="lN" for repeating groups):
 *   text {id, html}                 inner HTML of a text element (inline tags only)
 *   tag {id, tag}                   h1-h6, p, div, span
 *   style {id, style:{prop: v|null}} whitelisted inline style properties
 *   attr {id, name, value|null}     whitelisted attributes
 *   image {id, src?, media?, alt?, width?, height?, aspect?, fit?, position?, size?}
 *   icon {id, class? | svg?}
 *   remove {id}
 *   loop {id, action: duplicate|remove|move, index, to?}
 *   loop_dynamic {id, source:{kind, type|taxonomy, limit, orderby, order, term?}, map:{key: value}}
 *   loop_static {id}
 */
final class PageEditor
{
    public const TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'span'];
    /** Inline tags allowed inside edited text, with their attributes ("class" is kept on all of them). */
    public const INLINE = ['strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'a' => ['href', 'target', 'rel'], 'br' => [],
        'span' => ['style'], 'sup' => [], 'sub' => [], 'code' => [], 'mark' => [], 'small' => []];
    public const STYLE_PROPS = ['color', 'background-color', 'font-size', 'font-weight', 'font-style', 'text-decoration', 'text-transform',
        'text-align', 'line-height', 'letter-spacing', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border-radius', 'width', 'max-width', 'height',
        'aspect-ratio', 'object-fit', 'object-position', 'display'];
    public const ATTRS = ['href', 'target', 'rel', 'title', 'alt', 'src', 'srcset', 'sizes', 'width', 'height', 'loading', 'aria-label'];
    private const MEDIA_TAGS = ['img', 'picture', 'video', 'iframe', 'svg'];
    private const POST_ORDERBY = ['published_at', 'created_at', 'updated_at', 'title', 'menu_order'];
    private const TERM_ORDERBY = ['name', 'count', 'id', 'slug'];
    private const SIZES = ['thumb' => null, 'medium' => '640px', 'large' => '1024px', 'full' => null];
    private const MAX_OPS = 500;

    // ------------------------------------------------------------------ access

    public static function canEdit(array $post): bool
    {
        if (!Auth::id() || !Auth::can('edit_posts') || !Auth::can('frontend_edit')) {
            return false;
        }
        return Auth::can('edit_others_posts') || (int)($post['author_id'] ?? 0) === Auth::id();
    }

    public static function enabledFor(array $post): bool
    {
        return HtmlPages::active($post) && !empty($post['id']) && (bool)Options::get('admin_bar_frontend', true) && self::canEdit($post);
    }

    public static function aiAvailable(): bool
    {
        return Ai::enabled() && Auth::can('use_ai');
    }

    // ------------------------------------------------------------------ assets / config

    /** Inline config + stylesheet + script for the editor (printed in <head> by HtmlPages::render). */
    public static function assets(array $post): string
    {
        $cfg = [
            'postId' => (int)$post['id'],
            'title' => (string)$post['title'],
            'updated' => (string)($post['updated_at'] ?? ''),
            'save' => admin_url('page-editor/' . (int)$post['id'] . '/save'),
            'ai' => self::aiAvailable() ? admin_url('page-editor/' . (int)$post['id'] . '/ai') : null,
            'csrf' => Csrf::token(),
            'media' => Auth::can('upload_media') ? admin_url('api/media') : null,
            'terms' => admin_url('page-editor/terms'),
            'dashboard' => admin_url('content/' . $post['type'] . '/' . (int)$post['id']),
            'css' => asset_url('admin/assets/page-editor.css'),
            'sources' => self::sources(),
            'loops' => self::loops((string)(HtmlPages::data($post)['body'] ?? '')),
        ];
        $json = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        return "\n" . '<script type="application/json" id="se-config">' . $json . '</script>' . "\n"
            . '<link rel="stylesheet" href="' . e(asset_url('admin/assets/page-editor.css')) . '">' . "\n"
            . '<script src="' . e(asset_url('admin/assets/page-editor.js')) . '" defer></script>' . "\n";
    }

    /** Content a repeating group can be bound to: public post types and taxonomies with their fields. */
    public static function sources(): array
    {
        $field = static fn(array $f): array => ['name' => (string)$f['name'], 'label' => (string)($f['label'] ?? $f['name']), 'type' => (string)($f['type'] ?? 'text')];
        $usable = static fn(array $f): bool => !in_array($f['type'] ?? 'text', ['repeater', 'gallery', 'relation', 'tab', 'code', 'form', 'file'], true);
        $types = [];
        foreach (ContentModel::types() as $slug => $t) {
            if (empty($t['public'])) {
                continue;
            }
            $types[] = ['slug' => $slug, 'label' => (string)($t['plural'] ?? $slug),
                'fields' => array_values(array_map($field, array_filter(ContentModel::fieldsFor('post', $slug), $usable))),
                'taxonomies' => array_values(array_map(static fn($tx, $d) => ['slug' => $tx, 'label' => (string)($d['plural'] ?? $tx)],
                    array_keys(ContentModel::taxonomiesFor($slug)), ContentModel::taxonomiesFor($slug)))];
        }
        $taxes = [];
        foreach (ContentModel::taxonomies() as $slug => $t) {
            if (isset($t['public']) && !$t['public']) {
                continue;
            }
            $taxes[] = ['slug' => $slug, 'label' => (string)($t['plural'] ?? $slug),
                'fields' => array_values(array_map($field, array_filter(ContentModel::fieldsFor('term', $slug), $usable)))];
        }
        return ['types' => $types, 'taxonomies' => $taxes];
    }

    /** Repeating groups of a page body: items (for the panel) and the fields of the first item (for "Make dynamic"). */
    public static function loops(string $body): array
    {
        [$doc, $root] = HtmlPages::fragment($body);
        $xp = new \DOMXPath($doc);
        $out = [];
        foreach ($xp->query('.//*[@data-se-loop][not(ancestor::template)]', $root) ?: [] as $loop) {
            /** @var \DOMElement $loop */
            $src = $loop->getAttribute('data-se-source');
            $dyn = $src !== '' ? json_decode($src, true) : null;
            $items = [];
            $static = self::items($loop);
            if (is_array($dyn)) {
                $tpl = self::staticTemplate($loop);
                $static = $tpl ? self::items($tpl) : [];
            }
            foreach ($static as $it) {
                $ids = [];
                foreach ($xp->query('.//*[@data-se]', $it) ?: [] as $n) {
                    $ids[] = $n->getAttribute('data-se');
                    if (count($ids) >= 40) {
                        break;
                    }
                }
                $img = $xp->query('.//img', $it)->item(0);
                $items[] = ['label' => self::label($it), 'thumb' => $img instanceof \DOMElement ? $img->getAttribute('src') : '', 'ids' => $ids];
            }
            $out[] = ['id' => $loop->getAttribute('data-se-loop'), 'dynamic' => is_array($dyn) ? $dyn : null, 'items' => $items,
                'fields' => $static ? self::fields($static[0], $xp) : []];
        }
        return $out;
    }

    private static function label(\DOMElement $el): string
    {
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'p', 'a', 'span'] as $t) {
            foreach ($el->getElementsByTagName($t) as $n) {
                $s = trim(preg_replace('/\s+/', ' ', $n->textContent));
                if ($s !== '') {
                    return mb_substr($s, 0, 60);
                }
            }
        }
        $s = trim(preg_replace('/\s+/', ' ', $el->textContent));
        return $s !== '' ? mb_substr($s, 0, 60) : '';
    }

    /** Elements of an item that can be bound to content: data-se elements and links ("link:N"). */
    private static function fields(\DOMElement $item, \DOMXPath $xp): array
    {
        $out = [];
        foreach ($xp->query('.//*[@data-se]', $item) ?: [] as $n) {
            /** @var \DOMElement $n */
            $tag = strtolower($n->tagName);
            $kind = in_array($tag, ['img', 'picture'], true) ? 'image' : (self::isIcon($n) ? 'icon' : (in_array($tag, self::MEDIA_TAGS, true) ? 'media' : 'text'));
            if ($kind === 'icon' || $kind === 'media') {
                continue;
            }
            $out[] = ['key' => $n->getAttribute('data-se'), 'tag' => $tag, 'kind' => $kind,
                'text' => $kind === 'image' ? ($n->getAttribute('alt') ?: basename(parse_url($n->getAttribute('src') ?: '', PHP_URL_PATH) ?: 'image')) : mb_substr(trim(preg_replace('/\s+/', ' ', $n->textContent)), 0, 50)];
        }
        $i = 0;
        foreach ($item->getElementsByTagName('a') as $a) {
            $out[] = ['key' => 'link:' . $i++, 'tag' => 'a', 'kind' => 'link', 'text' => mb_substr(trim(preg_replace('/\s+/', ' ', $a->textContent)) ?: $a->getAttribute('href'), 0, 50),
                'href' => $a->getAttribute('href')];
        }
        if ($item->tagName === 'a') {
            array_unshift($out, ['key' => 'link:item', 'tag' => 'a', 'kind' => 'link', 'text' => 'Whole item', 'href' => $item->getAttribute('href')]);
        }
        return $out;
    }

    // ------------------------------------------------------------------ applying operations

    /** Apply a batch of operations and save. Returns an error message, or null on success. */
    public static function apply(array $post, array $ops): ?string
    {
        $data = HtmlPages::data($post);
        if (!$data) {
            return 'This page cannot be edited on the page.';
        }
        if (!$ops || !array_is_list($ops)) {
            return 'Nothing to save.';
        }
        if (count($ops) > self::MAX_OPS) {
            return 'Too many changes at once. Save in smaller steps.';
        }
        [$doc, $root] = HtmlPages::fragment((string)$data['body']);
        $state = ['next' => max(1, (int)($data['next'] ?? 1))];
        foreach ($ops as $i => $op) {
            if (!is_array($op)) {
                return 'Change #' . ($i + 1) . ' is not valid.';
            }
            try {
                $err = self::op($doc, $root, $op, $state);
            } catch (\Throwable $e) {
                $err = 'could not be applied';
            }
            if ($err !== null) {
                return 'Change #' . ($i + 1) . ' (' . preg_replace('/[^a-z_]/', '', (string)($op['op'] ?? '?')) . '): ' . $err;
            }
        }
        $data['body'] = HtmlPages::inner($doc, $root);
        $data['next'] = $state['next'];
        HtmlPages::save((int)$post['id'], $data, [], true);
        return null;
    }

    private static function op(\DOMDocument $doc, \DOMElement $root, array $op, array &$state): ?string
    {
        $type = (string)($op['op'] ?? '');
        $id = (string)($op['id'] ?? '');
        if (str_starts_with($type, 'loop')) {
            $el = preg_match('/^l\d{1,7}$/', $id) ? self::find($doc, $root, 'data-se-loop', $id) : null;
            if (!$el) {
                return 'repeating group not found';
            }
            return match ($type) {
                'loop' => self::opLoop($doc, $el, $op, $state),
                'loop_dynamic' => self::opDynamic($doc, $el, $op),
                'loop_static' => self::opStatic($el),
                default => 'unknown change',
            };
        }
        if ($type === 'style' && preg_match('/^l\d{1,7}$/', $id)) {
            $el = self::find($doc, $root, 'data-se-loop', $id);
        } else {
            $el = preg_match('/^e\d{1,7}$/', $id) ? self::find($doc, $root, 'data-se', $id) : null;
        }
        if (!$el) {
            return 'element not found';
        }
        if ($type !== 'style' || !$el->hasAttribute('data-se-loop')) {
            for ($p = $el->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
                if ($p->hasAttribute('data-se-source')) {
                    return 'the element is part of a dynamic list (make it static first)';
                }
            }
        }
        return match ($type) {
            'text' => self::opText($doc, $el, $op),
            'tag' => self::opTag($doc, $el, $op),
            'style' => self::opStyle($el, $op),
            'attr' => self::opAttr($el, $op),
            'image' => self::opImage($doc, $el, $op),
            'icon' => self::opIcon($doc, $el, $op),
            'remove' => self::opRemove($el),
            default => 'unknown change',
        };
    }

    private static function find(\DOMDocument $doc, \DOMElement $root, string $attr, string $id): ?\DOMElement
    {
        $n = (new \DOMXPath($doc))->query('.//*[@' . $attr . '="' . $id . '"][not(ancestor::template)]', $root);
        $el = $n ? $n->item(0) : null;
        return $el instanceof \DOMElement ? $el : null;
    }

    private static function opText(\DOMDocument $doc, \DOMElement $el, array $op): ?string
    {
        $tag = strtolower($el->tagName);
        if (in_array($tag, self::MEDIA_TAGS, true) || self::isIcon($el) || in_array($tag, ['ul', 'ol', 'table', 'select', 'template'], true)) {
            return 'this element has no editable text';
        }
        if (!is_string($op['html'] ?? null) || strlen($op['html']) > 50000) {
            return 'text missing or too long';
        }
        // Elements with their own ids inside the text (an icon in a button, a nested link) are kept as stored.
        $keep = [];
        foreach ((new \DOMXPath($doc))->query('.//*[@data-se]', $el) ?: [] as $n) {
            $keep[$n->getAttribute('data-se')] = $n;
        }
        $frag = $doc->createDocumentFragment();
        [$src, $srcRoot] = HtmlPages::fragment($op['html']);
        self::cleanInline($doc, $srcRoot, $frag, $keep);
        while ($el->firstChild) {
            $el->removeChild($el->firstChild);
        }
        if ($frag->hasChildNodes()) {
            $el->appendChild($frag);
        }
        return null;
    }

    /** Copy $src's children into $dst keeping only inline formatting. */
    private static function cleanInline(\DOMDocument $doc, \DOMNode $src, \DOMNode $dst, array &$keep): void
    {
        $first = true;
        foreach (iterator_to_array($src->childNodes) as $c) {
            if ($c instanceof \DOMText) {
                $dst->appendChild($doc->createTextNode($c->data));
                $first = false;
                continue;
            }
            if (!$c instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($c->tagName);
            $sid = $c->getAttribute('data-se');
            if ($sid !== '' && isset($keep[$sid])) {
                $dst->appendChild($keep[$sid]->cloneNode(true));
                unset($keep[$sid]);
                $first = false;
                continue;
            }
            if (in_array($tag, ['script', 'style', 'template', 'iframe', 'object', 'embed', 'noscript', 'svg', 'math', 'textarea', 'select', 'button', 'input', 'form', 'img', 'video', 'audio', 'link', 'meta'], true)) {
                continue;
            }
            if (isset(self::INLINE[$tag])) {
                $n = $doc->createElement($tag);
                foreach (self::INLINE[$tag] as $an) {
                    if (!$c->hasAttribute($an)) {
                        continue;
                    }
                    $v = trim($c->getAttribute($an));
                    $ok = match ($an) {
                        'href' => self::safeUrl($v, false) ? $v : null,
                        'target' => in_array($v, ['_blank', '_self'], true) ? $v : null,
                        'rel' => preg_match('/^[a-z ]{1,60}$/', $v) ? $v : null,
                        'style' => self::cleanStyleString($v),
                        default => null,
                    };
                    if ($ok !== null && $ok !== '') {
                        $n->setAttribute($an, $ok);
                    }
                }
                $cls = trim(preg_replace('/\s+/', ' ', $c->getAttribute('class')));
                if ($cls !== '' && preg_match('/^[A-Za-z0-9_\- :\/\[\]\.]{1,300}$/', $cls)) {
                    $n->setAttribute('class', $cls);
                }
                if ($tag === 'a' && $n->getAttribute('target') === '_blank' && !$n->hasAttribute('rel')) {
                    $n->setAttribute('rel', 'noopener');
                }
                self::cleanInline($doc, $c, $n, $keep);
                $dst->appendChild($n);
                $first = false;
                continue;
            }
            // Blocks the browser adds while typing (div, p) become line breaks; anything else is unwrapped.
            if (in_array($tag, ['div', 'p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote'], true) && !$first) {
                $dst->appendChild($doc->createElement('br'));
            }
            self::cleanInline($doc, $c, $dst, $keep);
            $first = false;
        }
    }

    private static function opTag(\DOMDocument $doc, \DOMElement $el, array $op): ?string
    {
        $to = strtolower((string)($op['tag'] ?? ''));
        if (!in_array($to, self::TAGS, true) || !in_array(strtolower($el->tagName), self::TAGS, true)) {
            return 'this tag cannot be used here';
        }
        if ($to === strtolower($el->tagName)) {
            return null;
        }
        $n = $doc->createElement($to);
        foreach (iterator_to_array($el->attributes) as $a) {
            $n->setAttribute($a->name, $a->value);
        }
        while ($el->firstChild) {
            $n->appendChild($el->firstChild);
        }
        $el->parentNode->replaceChild($n, $el);
        return null;
    }

    private static function opStyle(\DOMElement $el, array $op): ?string
    {
        $style = $op['style'] ?? null;
        if (!is_array($style) || !$style || count($style) > 40) {
            return 'no style given';
        }
        $decl = self::parseStyle($el->getAttribute('style'));
        foreach ($style as $prop => $v) {
            $prop = strtolower(trim((string)$prop));
            if (!in_array($prop, self::STYLE_PROPS, true)) {
                return 'style property "' . mb_substr($prop, 0, 40) . '" is not allowed';
            }
            if ($v === null || $v === '') {
                unset($decl[$prop]);
                continue;
            }
            if (!is_scalar($v) || !self::safeStyleValue((string)$v)) {
                return 'value for "' . $prop . '" is not allowed';
            }
            $decl[$prop] = trim((string)$v);
        }
        self::writeStyle($el, $decl);
        return null;
    }

    private static function opAttr(\DOMElement $el, array $op): ?string
    {
        $name = strtolower((string)($op['name'] ?? ''));
        if (!in_array($name, self::ATTRS, true)) {
            return 'attribute "' . mb_substr($name, 0, 40) . '" is not allowed';
        }
        $v = $op['value'] ?? null;
        if ($v === null) {
            $el->removeAttribute($name);
            return null;
        }
        if (!is_scalar($v)) {
            return 'invalid value';
        }
        $v = trim((string)$v);
        $tag = strtolower($el->tagName);
        $err = match ($name) {
            'srcset', 'sizes' => 'can only be removed',
            'href' => self::safeUrl($v, false) ? null : 'this link is not allowed',
            'src' => self::safeUrl($v, $tag === 'img') ? null : 'this address is not allowed',
            'target' => in_array($v, ['_blank', '_self', '_parent', '_top'], true) ? null : 'invalid target',
            'rel' => preg_match('/^[a-z ]{0,60}$/', $v) ? null : 'invalid rel',
            'width', 'height' => preg_match('/^\d{1,5}$/', $v) ? null : 'must be a whole number',
            'loading' => in_array($v, ['lazy', 'eager'], true) ? null : 'must be lazy or eager',
            default => mb_strlen($v) <= 1000 ? null : 'too long',
        };
        if ($err !== null) {
            return $err;
        }
        $el->setAttribute($name, $v);
        if ($name === 'src' && $tag === 'img') {
            self::dropSources($el);
        }
        return null;
    }

    private static function opImage(\DOMDocument $doc, \DOMElement $el, array $op): ?string
    {
        $tag = strtolower($el->tagName);
        $img = $tag === 'img' ? $el : ($tag === 'picture' ? $el->getElementsByTagName('img')->item(0) : null);
        if (!$img instanceof \DOMElement) {
            return 'not an image';
        }
        $media = null;
        if (isset($op['media']) && $op['media'] !== null) {
            $media = Media::find((int)$op['media']);
            if (!$media || !Media::isImage($media)) {
                return 'image not found in the media library';
            }
        } elseif ($img->getAttribute('data-media-id') !== '' && isset($op['size'])) {
            $media = Media::find((int)$img->getAttribute('data-media-id'));
        }
        $size = isset($op['size']) ? (string)$op['size'] : null;
        if ($size !== null && !array_key_exists($size, self::SIZES)) {
            return 'unknown size';
        }
        $decl = self::parseStyle($img->getAttribute('style'));
        if ($media) {
            $img->setAttribute('src', Media::url($media, $size === 'thumb' ? 'thumb' : 'full'));
            $img->setAttribute('data-media-id', (string)(int)$media['id']);
            if (isset($op['media'])) {
                if ($media['width'] && $media['height'] && !array_key_exists('width', $op)) {
                    $img->setAttribute('width', (string)(int)$media['width']);
                    $img->setAttribute('height', (string)(int)$media['height']);
                }
                if (!array_key_exists('alt', $op) && (string)$media['alt'] !== '') {
                    $img->setAttribute('alt', (string)$media['alt']);
                }
            }
            self::dropSources($img);
        } elseif (array_key_exists('src', $op)) {
            $src = trim((string)$op['src']);
            if (!self::safeUrl($src, true)) {
                return 'this image address is not allowed';
            }
            $img->setAttribute('src', $src);
            $img->removeAttribute('data-media-id');
            self::dropSources($img);
        }
        if ($size !== null) {
            if (!$media) {
                return 'sizes are only available for images from the media library';
            }
            if (self::SIZES[$size] !== null) {
                $decl['max-width'] = self::SIZES[$size];
                $decl['width'] = '100%';
            } else {
                unset($decl['max-width']);
                if (($decl['width'] ?? '') === '100%') {
                    unset($decl['width']);
                }
            }
        }
        if (array_key_exists('alt', $op)) {
            $alt = trim((string)$op['alt']);
            if (mb_strlen($alt) > 500) {
                return 'alt text too long';
            }
            $img->setAttribute('alt', $alt);
        }
        foreach (['width', 'height'] as $d) {
            if (!array_key_exists($d, $op)) {
                continue;
            }
            if ($op[$d] === null || $op[$d] === '') {
                $img->removeAttribute($d);
            } elseif (preg_match('/^\d{1,5}$/', (string)$op[$d])) {
                $img->setAttribute($d, (string)(int)$op[$d]);
            } else {
                return $d . ' must be a whole number';
            }
        }
        if (array_key_exists('aspect', $op)) {
            $a = trim((string)$op['aspect']);
            if ($a === '' || $a === 'original' || $op['aspect'] === null) {
                unset($decl['aspect-ratio']);
                if (($decl['height'] ?? '') === 'auto') {
                    unset($decl['height']);
                }
            } elseif (preg_match('#^(\d{1,4}(?:\.\d{1,3})?)\s*[/:]\s*(\d{1,4}(?:\.\d{1,3})?)$#', $a, $m) && (float)$m[1] > 0 && (float)$m[2] > 0) {
                $decl['aspect-ratio'] = $m[1] . ' / ' . $m[2];
                $decl['height'] = 'auto';
                $decl['object-fit'] ??= 'cover';
            } else {
                return 'invalid aspect ratio';
            }
        }
        if (array_key_exists('fit', $op)) {
            if ($op['fit'] === null || $op['fit'] === '') {
                unset($decl['object-fit']);
            } elseif (in_array($op['fit'], ['cover', 'contain', 'fill', 'none', 'scale-down'], true)) {
                $decl['object-fit'] = $op['fit'];
            } else {
                return 'invalid fit';
            }
        }
        if (array_key_exists('position', $op)) {
            $p = trim((string)$op['position']);
            if ($p === '' || $op['position'] === null) {
                unset($decl['object-position']);
            } elseif (preg_match('/^((left|center|right|top|bottom|\d{1,3}%)\s*){1,2}$/', $p)) {
                $decl['object-position'] = $p;
            } else {
                return 'invalid focus point';
            }
        }
        self::writeStyle($img, $decl);
        return null;
    }

    private static function opIcon(\DOMDocument $doc, \DOMElement $el, array $op): ?string
    {
        $tag = strtolower($el->tagName);
        if (!self::isIcon($el)) {
            return 'not an icon';
        }
        if (isset($op['svg'])) {
            $svg = self::cleanSvg($doc, (string)$op['svg']);
            if (!$svg) {
                return 'this SVG cannot be used';
            }
            $svg->setAttribute('data-se', $el->getAttribute('data-se'));
            if ($tag === 'svg') {
                foreach (['class', 'aria-hidden', 'role'] as $keep) {
                    if ($el->hasAttribute($keep) && !$svg->hasAttribute($keep)) {
                        $svg->setAttribute($keep, $el->getAttribute($keep));
                    }
                }
            } else {
                if (!$svg->hasAttribute('width') && !$svg->hasAttribute('height')) {
                    $svg->setAttribute('width', '1em');
                    $svg->setAttribute('height', '1em');
                }
                $svg->setAttribute('aria-hidden', 'true');
            }
            $el->parentNode->replaceChild($svg, $el);
            return null;
        }
        $cls = trim(preg_replace('/\s+/', ' ', (string)($op['class'] ?? '')));
        if ($tag === 'svg') {
            return 'paste an SVG to replace this icon';
        }
        if ($cls === '' || !preg_match('/^[A-Za-z0-9_\- ]{1,200}$/', $cls) || !preg_match('/(^|\s)(fa[a-z]*|fa-[a-z0-9-]+|bi|bi-[a-z0-9-]+|icon[a-z0-9_-]*|material-icons[a-z-]*|ti|ti-[a-z0-9-]+|ri-[a-z0-9-]+|las|lab|la-[a-z0-9-]+)(\s|$)/', $cls)) {
            return 'use icon classes such as "fa-solid fa-star" or "bi bi-star"';
        }
        $el->setAttribute('class', $cls);
        return null;
    }

    private static function opRemove(\DOMElement $el): ?string
    {
        $el->parentNode->removeChild($el);
        return null;
    }

    // ------------------------------------------------------------------ loops

    /** @return \DOMElement[] */
    private static function items(\DOMElement $loop): array
    {
        $out = [];
        foreach ($loop->childNodes as $c) {
            if ($c instanceof \DOMElement && !in_array(strtolower($c->tagName), ['script', 'style', 'template'], true)) {
                $out[] = $c;
            }
        }
        return $out;
    }

    private static function staticTemplate(\DOMElement $loop): ?\DOMElement
    {
        foreach ($loop->childNodes as $c) {
            if ($c instanceof \DOMElement && strtolower($c->tagName) === 'template' && $c->hasAttribute('data-se-static')) {
                return $c;
            }
        }
        return null;
    }

    private static function opLoop(\DOMDocument $doc, \DOMElement $loop, array $op, array &$state): ?string
    {
        if ($loop->hasAttribute('data-se-source')) {
            return 'this list shows live content; make it static first';
        }
        $items = self::items($loop);
        $n = count($items);
        $i = $op['index'] ?? null;
        if (!is_int($i) || $i < 0 || $i >= $n) {
            return 'item not found';
        }
        $item = $items[$i];
        switch ((string)($op['action'] ?? '')) {
            case 'duplicate':
                if ($n >= 100) {
                    return 'a list can have at most 100 items';
                }
                $copy = $item->cloneNode(true);
                self::renumber($copy, $state);
                $ws = $item->previousSibling instanceof \DOMText && trim($item->previousSibling->data) === '' ? $item->previousSibling->data : null;
                $ref = $item->nextSibling;
                if ($ws !== null) {
                    $loop->insertBefore($doc->createTextNode($ws), $ref);
                }
                $loop->insertBefore($copy, $ref);
                return null;
            case 'remove':
                if ($n <= 1) {
                    return 'a list needs at least one item';
                }
                $prev = $item->previousSibling;
                $loop->removeChild($item);
                if ($prev instanceof \DOMText && trim($prev->data) === '') {
                    $loop->removeChild($prev);
                }
                return null;
            case 'move':
                $to = $op['to'] ?? null;
                if (!is_int($to) || $to < 0 || $to >= $n) {
                    return 'invalid position';
                }
                if ($to === $i) {
                    return null;
                }
                $loop->removeChild($item);
                $rest = self::items($loop);
                if ($to >= count($rest)) {
                    $last = end($rest);
                    $loop->insertBefore($item, $last->nextSibling);
                } else {
                    $loop->insertBefore($item, $rest[$to]);
                }
                return null;
        }
        return 'unknown action';
    }

    /** Fresh ids for a duplicated item (and anything inside it). */
    private static function renumber(\DOMElement $el, array &$state): void
    {
        $all = array_merge([$el], iterator_to_array($el->getElementsByTagName('*')));
        foreach ($all as $n) {
            if ($n->hasAttribute('data-se')) {
                $n->setAttribute('data-se', 'e' . $state['next']++);
            }
            if ($n->hasAttribute('data-se-loop')) {
                $n->setAttribute('data-se-loop', 'l' . $state['next']++);
            }
        }
    }

    private static function opDynamic(\DOMDocument $doc, \DOMElement $loop, array $op): ?string
    {
        if ($loop->hasAttribute('data-se-source')) {
            return 'this list already shows live content';
        }
        $s = $op['source'] ?? null;
        if (!is_array($s)) {
            return 'choose what to show';
        }
        $kind = (string)($s['kind'] ?? '');
        $order = strtolower((string)($s['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $limit = (int)($s['limit'] ?? 6);
        if ($kind === 'posts') {
            $type = (string)($s['type'] ?? '');
            $def = ContentModel::type($type);
            if (!$def || empty($def['public'])) {
                return 'unknown content type';
            }
            $orderby = in_array($s['orderby'] ?? 'published_at', self::POST_ORDERBY, true) ? (string)($s['orderby'] ?? 'published_at') : null;
            if ($orderby === null) {
                return 'invalid order';
            }
            if ($limit < 1 || $limit > 50) {
                return 'number of items must be between 1 and 50';
            }
            $term = trim((string)($s['term'] ?? ''));
            if ($term !== '') {
                if (!preg_match('/^([a-z0-9_-]+):([a-z0-9_-]+)$/', $term, $tm) || !ContentModel::taxonomy($tm[1]) || !Terms::findBySlug($tm[1], $tm[2])) {
                    return 'unknown term';
                }
            }
            $source = ['kind' => 'posts', 'type' => $type, 'limit' => $limit, 'orderby' => $orderby, 'order' => $order] + ($term !== '' ? ['term' => $term] : []);
            $open = '{{#query type=' . $type . ' limit=' . $limit . ' orderby=' . $orderby . ' order=' . $order . ($term !== '' ? ' term=' . $term : '') . '}}';
            $close = '{{/query}}';
            $fields = ContentModel::fieldsFor('post', $type);
        } elseif ($kind === 'terms') {
            $tax = (string)($s['taxonomy'] ?? '');
            if (!ContentModel::taxonomy($tax)) {
                return 'unknown taxonomy';
            }
            $orderby = in_array($s['orderby'] ?? 'name', self::TERM_ORDERBY, true) ? (string)($s['orderby'] ?? 'name') : null;
            if ($orderby === null) {
                return 'invalid order';
            }
            if ($limit < 1 || $limit > 100) {
                return 'number of items must be between 1 and 100';
            }
            $source = ['kind' => 'terms', 'taxonomy' => $tax, 'limit' => $limit, 'orderby' => $orderby, 'order' => $order];
            $open = '{{#terms taxonomy=' . $tax . ' limit=' . $limit . ' orderby=' . $orderby . ' order=' . $order . '}}';
            $close = '{{/terms}}';
            $fields = ContentModel::fieldsFor('term', $tax);
        } else {
            return 'choose posts or terms';
        }
        $items = self::items($loop);
        if (!$items) {
            return 'the list is empty';
        }
        $map = $op['map'] ?? [];
        if (!is_array($map) || count($map) > 50) {
            return 'invalid field mapping';
        }
        $pattern = $items[0]->cloneNode(true);
        $links = array_merge(strtolower($pattern->tagName) === 'a' ? [$pattern] : [], iterator_to_array($pattern->getElementsByTagName('a')));
        $plainLinks = iterator_to_array($pattern->getElementsByTagName('a'));
        $urlTag = $kind === 'posts' ? '{{url}}' : '{{item.url}}';
        $titleTag = $kind === 'posts' ? '{{title}}' : '{{item.name}}';
        $bindLink = static function (\DOMElement $a) use ($links, $urlTag): void {
            $orig = $a->getAttribute('href');
            foreach ($links as $l) {
                if ($l === $a || ($orig !== '' && $orig !== '#' && $l->getAttribute('href') === $orig)) {
                    $l->setAttribute('href', $urlTag);
                }
            }
        };
        $clean = [];
        foreach ($map as $key => $value) {
            $key = (string)$key;
            $value = (string)$value;
            if ($value === '' || $value === 'none') {
                continue;
            }
            if (preg_match('/^link:(\d{1,3}|item)$/', $key, $lm)) {
                $a = $lm[1] === 'item' ? (strtolower($pattern->tagName) === 'a' ? $pattern : null) : ($plainLinks[(int)$lm[1]] ?? null);
                if (!$a || $value !== ($kind === 'posts' ? 'url' : 'term:url')) {
                    return 'links can only show the item address';
                }
                $bindLink($a);
                $clean[$key] = $value;
                continue;
            }
            if (!preg_match('/^e\d{1,7}$/', $key)) {
                return 'invalid field "' . mb_substr($key, 0, 20) . '"';
            }
            $el = null;
            foreach (array_merge([$pattern], iterator_to_array($pattern->getElementsByTagName('*'))) as $cand) {
                if ($cand->getAttribute('data-se') === $key) {
                    $el = $cand;
                    break;
                }
            }
            if (!$el instanceof \DOMElement) {
                return 'element ' . $key . ' is not in the first item';
            }
            $tag = strtolower($el->tagName);
            $tagFor = self::bindTag($kind, $value, $fields);
            if ($tagFor === null) {
                return 'unknown content "' . mb_substr($value, 0, 40) . '"';
            }
            if ($tag === 'img' || $tag === 'picture') {
                $img = $tag === 'img' ? $el : $el->getElementsByTagName('img')->item(0);
                if (!$img instanceof \DOMElement || $tagFor['kind'] !== 'image') {
                    return 'images can only show an image';
                }
                // Items without an image keep the original picture instead of showing a broken one.
                $orig = $img->getAttribute('src');
                $cond = trim($tagFor['tag'], '{}');
                $cond = explode('|', $cond === 'image_url' ? 'image' : $cond)[0];
                $img->setAttribute('src', $orig !== '' && self::safeUrl($orig, true) && !str_contains($orig, '{{')
                    ? '{{#if ' . $cond . '}}' . $tagFor['tag'] . '{{else}}' . $orig . '{{/if}}' : $tagFor['tag']);
                $img->setAttribute('alt', $titleTag);
                foreach (['srcset', 'sizes', 'data-media-id', 'data-src', 'data-srcset'] as $at) {
                    $img->removeAttribute($at);
                }
                self::dropSources($img);
            } elseif ($tagFor['kind'] === 'url') {
                $a = $tag === 'a' ? $el : null;
                for ($p = $el->parentNode; !$a && $p instanceof \DOMElement; $p = $p->parentNode) {
                    if (strtolower($p->tagName) === 'a') {
                        $a = $p;
                    }
                    if ($p === $pattern) {
                        break;
                    }
                }
                $a ??= $el->getElementsByTagName('a')->item(0);
                if (!$a instanceof \DOMElement) {
                    return 'element ' . $key . ' is not a link';
                }
                $bindLink($a);
            } elseif ($tagFor['kind'] === 'image') {
                return 'only images can show an image';
            } else {
                while ($el->firstChild) {
                    $el->removeChild($el->firstChild);
                }
                $el->appendChild($doc->createTextNode($tagFor['tag']));
                // A linked title links to the item.
                if (in_array($value, ['title', 'term:name'], true)) {
                    $a = $tag === 'a' ? $el : null;
                    for ($p = $el->parentNode; !$a && $p instanceof \DOMElement && $p !== $pattern->parentNode; $p = $p->parentNode) {
                        if (strtolower($p->tagName) === 'a') {
                            $a = $p;
                        }
                    }
                    if ($a) {
                        $bindLink($a);
                    }
                }
            }
            $clean[$key] = $value;
        }
        // Keep everything that was there, exactly, for "Make static again".
        $tpl = $doc->createElement('template');
        $tpl->setAttribute('data-se-static', '1');
        while ($loop->firstChild) {
            $tpl->appendChild($loop->firstChild);
        }
        $loop->appendChild($doc->createTextNode($open));
        $loop->appendChild($pattern);
        $loop->appendChild($doc->createTextNode($close));
        $loop->appendChild($tpl);
        $loop->setAttribute('data-se-source', (string)json_encode($source + ['map' => (object)$clean], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return null;
    }

    /** The template tag for a mapping value: ['kind' => text|image|url, 'tag' => '{{…}}'] or null. */
    private static function bindTag(string $kind, string $value, array $fields): ?array
    {
        if ($kind === 'posts') {
            $simple = ['title' => '{{title}}', 'excerpt' => '{{excerpt}}', 'date' => '{{date}}', 'author' => '{{author}}'];
            if (isset($simple[$value])) {
                return ['kind' => 'text', 'tag' => $simple[$value]];
            }
            if ($value === 'image') {
                return ['kind' => 'image', 'tag' => '{{image_url}}'];
            }
            if ($value === 'url') {
                return ['kind' => 'url', 'tag' => '{{url}}'];
            }
            if (preg_match('/^field:([A-Za-z0-9_]{1,60})$/', $value, $m) && isset($fields[$m[1]])) {
                return ($fields[$m[1]]['type'] ?? '') === 'image'
                    ? ['kind' => 'image', 'tag' => '{{field:' . $m[1] . '|image_url}}']
                    : ['kind' => 'text', 'tag' => '{{field:' . $m[1] . (($fields[$m[1]]['type'] ?? '') === 'date' ? '|date' : '') . '}}'];
            }
            return null;
        }
        $simple = ['term:name' => '{{item.name}}', 'term:count' => '{{item.count}}', 'term:description' => '{{item.description|strip}}'];
        if (isset($simple[$value])) {
            return ['kind' => 'text', 'tag' => $simple[$value]];
        }
        if ($value === 'term:url') {
            return ['kind' => 'url', 'tag' => '{{item.url}}'];
        }
        if (preg_match('/^term:field:([A-Za-z0-9_]{1,60})$/', $value, $m) && isset($fields[$m[1]])) {
            return ($fields[$m[1]]['type'] ?? '') === 'image'
                ? ['kind' => 'image', 'tag' => '{{item.fields.' . $m[1] . '|image_url}}']
                : ['kind' => 'text', 'tag' => '{{item.fields.' . $m[1] . '}}'];
        }
        return null;
    }

    private static function opStatic(\DOMElement $loop): ?string
    {
        if (!$loop->hasAttribute('data-se-source')) {
            return 'this list is already static';
        }
        $tpl = self::staticTemplate($loop);
        if (!$tpl) {
            return 'the original items were not found';
        }
        $loop->removeChild($tpl);
        while ($loop->firstChild) {
            $loop->removeChild($loop->firstChild);
        }
        while ($tpl->firstChild) {
            $loop->appendChild($tpl->firstChild);
        }
        $loop->removeAttribute('data-se-source');
        return null;
    }

    // ------------------------------------------------------------------ AI

    /** Rewrite text / suggest alt text. Returns ['ok' => bool, 'html'|'error' => string]. */
    public static function ai(string $action, string $text, string $lang = '', string $context = ''): array
    {
        $text = mb_substr(trim($text), 0, 6000);
        $prompts = [
            'shorter' => 'Make this text shorter (about half the length), keeping its meaning.',
            'longer' => 'Make this text a little longer and more descriptive (at most twice the length), keeping its meaning and tone.',
            'grammar' => 'Fix spelling, grammar and punctuation. Change nothing else.',
            'formal' => 'Rewrite this text in a more formal, professional tone.',
            'casual' => 'Rewrite this text in a friendlier, more casual tone.',
            'simpler' => 'Rewrite this text in simpler words that anyone can understand.',
        ];
        if ($action === 'alt') {
            $prompt = "Write alt text for an image on a web page. Image file: \"" . mb_substr($context, 0, 300) . "\". Text near the image: \"" . mb_substr($text, 0, 1500)
                . "\".\nDescribe what the image most likely shows in one short, plain sentence (at most 125 characters). Do not start with \"Image of\". Return only the alt text, no quotes.";
        } elseif ($action === 'translate') {
            $lang = trim(preg_replace('/[^\p{L} \-()]/u', '', $lang));
            if ($lang === '' || mb_strlen($lang) > 40) {
                return ['ok' => false, 'error' => 'Choose a language.'];
            }
            $prompt = "Translate this web page text into $lang. Keep any inline HTML tags (<strong>, <em>, <a href=\"…\">, <br>) exactly where they belong.\n"
                . "Return only the translated text, with no quotes, notes or explanations.\n\nText:\n" . $text;
        } elseif (isset($prompts[$action])) {
            if ($text === '') {
                return ['ok' => false, 'error' => 'There is no text to rewrite.'];
            }
            $prompt = $prompts[$action] . " This is a single element of a web page (a heading, paragraph or button), so keep it about the same kind of text.\n"
                . "Keep any inline HTML tags (<strong>, <em>, <a href=\"…\">, <br>) where they belong; do not add new tags, headings, lists or markdown.\n"
                . "Return only the new text, with no quotes, notes or explanations.\n\nText:\n" . $text;
        } else {
            return ['ok' => false, 'error' => 'Unknown action.'];
        }
        $res = Ai::complete($prompt, null, ['max_tokens' => 1500]);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? 'The AI request failed.')];
        }
        $out = trim((string)$res['text']);
        $out = (string)preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $out);
        $out = trim($out, " \n\r\t\"“”");
        $out = (string)preg_replace('/\s*\x{2014}\s*/u', ', ', $out);
        $out = str_replace("\u{2013}", '-', $out);
        if ($action === 'alt') {
            return ['ok' => true, 'html' => mb_substr(trim(strip_tags($out)), 0, 200)];
        }
        return ['ok' => true, 'html' => self::sanitizeInline($out)];
    }

    /** Inline-only HTML (as stored by the "text" operation). */
    public static function sanitizeInline(string $html): string
    {
        [$doc, $root] = HtmlPages::fragment('');
        [, $src] = HtmlPages::fragment(nl2br($html, false));
        $keep = [];
        self::cleanInline($doc, $src, $root, $keep);
        return HtmlPages::inner($doc, $root);
    }

    // ------------------------------------------------------------------ helpers

    public static function isIcon(\DOMElement $el): bool
    {
        $tag = strtolower($el->tagName);
        return $tag === 'svg' || (in_array($tag, ['i', 'span'], true) && (bool)preg_match('/(^|\s)(fa|fas|far|fab|fal|fad|fa-[a-z0-9-]+|bi|bi-[a-z0-9-]+|icon|material-icons|ti|ri-[a-z0-9-]+)(\s|$)/', $el->getAttribute('class'))
            && trim($el->textContent) === '');
    }

    /** http(s), relative, #anchor, mailto:, tel:; data:image (not SVG) only for images. */
    public static function safeUrl(string $url, bool $image): bool
    {
        $u = trim($url);
        if ($u === '' || strlen($u) > 4000 || preg_match('/[\x00-\x1f<>"]/', $u)) {
            return false;
        }
        $clean = strtolower((string)preg_replace('/[\s\x00-\x20]+/', '', $u));
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $clean, $m)) {
            if ($m[1] === 'data') {
                return $image && (bool)preg_match('#^data:image/(png|jpe?g|gif|webp|avif);base64,[a-z0-9+/=]+$#i', $u);
            }
            return in_array($m[1], $image ? ['http', 'https'] : ['http', 'https', 'mailto', 'tel'], true);
        }
        return true; // relative, //host, #anchor, ?query
    }

    public static function safeStyleValue(string $v): bool
    {
        $v = trim($v);
        return $v !== '' && strlen($v) <= 120 && !preg_match('/url\s*\(|expression|javascript|behavior|@import|[;{}<>\\\\]|\/\*/i', $v);
    }

    /** @return array<string,string> */
    public static function parseStyle(string $style): array
    {
        $out = [];
        foreach (preg_split('/;(?![^(]*\))/', $style) ?: [] as $d) {
            $p = strpos($d, ':');
            if ($p === false) {
                continue;
            }
            $k = strtolower(trim(substr($d, 0, $p)));
            $v = trim(substr($d, $p + 1));
            if ($k !== '' && $v !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private static function writeStyle(\DOMElement $el, array $decl): void
    {
        if (!$decl) {
            $el->removeAttribute('style');
            return;
        }
        $el->setAttribute('style', implode('; ', array_map(static fn($k, $v) => $k . ': ' . $v, array_keys($decl), $decl)));
    }

    private static function cleanStyleString(string $style): string
    {
        $keep = [];
        foreach (self::parseStyle($style) as $k => $v) {
            if (in_array($k, self::STYLE_PROPS, true) && self::safeStyleValue($v)) {
                $keep[] = $k . ': ' . $v;
            }
        }
        return implode('; ', $keep);
    }

    /** When an image changes, responsive variants of the old one must go: srcset/sizes and <picture> sources. */
    private static function dropSources(\DOMElement $img): void
    {
        $img->removeAttribute('srcset');
        $img->removeAttribute('sizes');
        $img->removeAttribute('data-srcset');
        $img->removeAttribute('data-src');
        $p = $img->parentNode;
        if ($p instanceof \DOMElement && strtolower($p->tagName) === 'picture') {
            foreach (iterator_to_array($p->getElementsByTagName('source')) as $s) {
                $s->parentNode->removeChild($s);
            }
        }
    }

    /** Parse pasted SVG into $doc, keeping only drawing markup. */
    private static function cleanSvg(\DOMDocument $doc, string $svg): ?\DOMElement
    {
        $svg = trim($svg);
        if ($svg === '' || strlen($svg) > 100000 || !preg_match('/^<svg[\s>]/i', $svg)) {
            return null;
        }
        [$src, $root] = HtmlPages::fragment($svg);
        $el = null;
        foreach ($root->childNodes as $c) {
            if ($c instanceof \DOMElement) {
                if ($el || strtolower($c->tagName) !== 'svg') {
                    return null;
                }
                $el = $c;
            }
        }
        if (!$el) {
            return null;
        }
        $bad = ['script', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video', 'handler', 'listener', 'set', 'animate'];
        foreach (array_merge([$el], iterator_to_array($el->getElementsByTagName('*'))) as $n) {
            if (in_array(strtolower($n->tagName), $bad, true)) {
                $n->parentNode?->removeChild($n);
                continue;
            }
            foreach (iterator_to_array($n->attributes) as $a) {
                $an = strtolower($a->name);
                $av = strtolower((string)preg_replace('/[\s\x00-\x20]+/', '', $a->value));
                if (str_starts_with($an, 'on') || (in_array($an, ['href', 'xlink:href', 'src'], true) && !str_starts_with($av, '#'))
                    || str_contains($av, 'javascript:') || ($an === 'style' && preg_match('/url\s*\(|expression|javascript/i', $a->value))) {
                    $n->removeAttribute($a->name);
                }
            }
        }
        return $doc->importNode($el, true);
    }
}
