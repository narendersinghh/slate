<?php
declare(strict_types=1);

namespace Slate;

/** Allow-list HTML sanitizer for users without the unfiltered_html capability. */
final class Sanitizer
{
    private const TAGS = [
        'p' => [], 'br' => [], 'hr' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'sub' => [], 'sup' => [], 'mark' => [], 'small' => [], 'code' => [], 'pre' => [], 'blockquote' => ['cite'],
        'h1' => ['id'], 'h2' => ['id'], 'h3' => ['id'], 'h4' => ['id'], 'h5' => ['id'], 'h6' => ['id'],
        'ul' => [], 'ol' => ['start'], 'li' => [], 'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading', 'data-media-id'],
        'figure' => [], 'figcaption' => [], 'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan', 'scope'],
        'td' => ['colspan', 'rowspan'], 'span' => [], 'div' => [], 'iframe' => ['src', 'width', 'height', 'allow', 'allowfullscreen', 'title'],
    ];
    private const GLOBAL_ATTRS = ['class'];
    private const IFRAME_HOSTS = ['www.youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com', 'www.google.com', 'open.spotify.com'];

    public static function html(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $root = $doc->getElementById('__root');
        if (!$root) {
            return '';
        }
        self::clean($root);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    private static function clean(\DOMNode $node): void
    {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'object', 'embed', 'form', 'input', 'button', 'svg', 'math', 'link', 'meta', 'base'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                if (!isset(self::TAGS[$tag])) {
                    // Unwrap unknown tags, keep their text.
                    self::clean($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                $allowed = array_merge(self::TAGS[$tag], self::GLOBAL_ATTRS);
                for ($a = $child->attributes->length - 1; $a >= 0; $a--) {
                    $attr = $child->attributes->item($a);
                    $name = strtolower($attr->nodeName);
                    if (!in_array($name, $allowed, true)) {
                        $child->removeAttribute($attr->nodeName);
                        continue;
                    }
                    if (in_array($name, ['href', 'src', 'cite'], true)) {
                        $clean = ContentModel::cleanUrl($attr->nodeValue);
                        if ($clean === '') {
                            $child->removeAttribute($attr->nodeName);
                        }
                    }
                }
                if ($tag === 'iframe') {
                    $host = parse_url($child->getAttribute('src'), PHP_URL_HOST);
                    if (!in_array($host, self::IFRAME_HOSTS, true)) {
                        $node->removeChild($child);
                        continue;
                    }
                }
                if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
                self::clean($child);
            } elseif ($child instanceof \DOMComment) {
                $node->removeChild($child);
            }
        }
    }
}
