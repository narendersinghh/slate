<?php
declare(strict_types=1);

namespace Slate;

/**
 * Where each element of an HTML string starts and ends, in source order, so the page editor can change one element of
 * stored content (post content imported from WordPress, Elementor…) and leave every other byte exactly as it was.
 *
 * elements() returns, for every start tag in source order:
 *   name, start (offset of "<"), insert (offset where attributes can be added), open_end (offset after ">"),
 *   close (offset of the end tag's "<", null when the element has no explicit end tag), end (offset after the end tag).
 * The order matches the elements libxml builds from the same string (checked by matches()); when it does not, the
 * content is not edited on the page.
 */
final class ContentMap
{
    private const VOID = ['area', 'base', 'basefont', 'bgsound', 'br', 'col', 'embed', 'frame', 'hr', 'img', 'input', 'keygen', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
    private const RAW = ['script', 'style'];

    /** @return array<int, array{name:string,start:int,insert:int,open_end:int,close:?int,end:?int}> */
    public static function elements(string $html): array
    {
        $len = strlen($html);
        $pos = 0;
        $out = [];
        $stack = [];
        while ($pos < $len) {
            $lt = strpos($html, '<', $pos);
            if ($lt === false) {
                break;
            }
            if (substr_compare($html, '<!--', $lt, 4) === 0) {
                $e = strpos($html, '-->', $lt + 4);
                $pos = $e === false ? $len : $e + 3;
                continue;
            }
            if (substr_compare($html, '<![CDATA[', $lt, 9) === 0) {
                $e = strpos($html, ']]>', $lt + 9);
                $pos = $e === false ? $len : $e + 3;
                continue;
            }
            $next = $html[$lt + 1] ?? '';
            if ($next === '!' || $next === '?') {
                $e = strpos($html, '>', $lt);
                $pos = $e === false ? $len : $e + 1;
                continue;
            }
            if ($next === '/') {
                if (preg_match('/\G<\/([A-Za-z][A-Za-z0-9:_-]*)[^>]*>/', $html, $m, 0, $lt)) {
                    $name = strtolower($m[1]);
                    for ($i = count($stack) - 1; $i >= 0; $i--) {
                        if ($out[$stack[$i]]['name'] === $name) {
                            $out[$stack[$i]]['close'] = $lt;
                            $out[$stack[$i]]['end'] = $lt + strlen($m[0]);
                            array_splice($stack, $i);
                            break;
                        }
                    }
                    $pos = $lt + strlen($m[0]);
                } else {
                    $pos = $lt + 2;
                }
                continue;
            }
            if (!ctype_alpha($next)) {
                $pos = $lt + 1;
                continue;
            }
            // Start tag: name, then attributes (quoted values may contain ">").
            preg_match('/\G<([A-Za-z][A-Za-z0-9:_-]*)/', $html, $m, 0, $lt);
            $name = strtolower($m[1]);
            $i = $lt + strlen($m[0]);
            $quote = '';
            $gt = null;
            for (; $i < $len; $i++) {
                $c = $html[$i];
                if ($quote !== '') {
                    if ($c === $quote) {
                        $quote = '';
                    }
                } elseif ($c === '"' || $c === "'") {
                    // Quotes only open a value right after "=" (optionally with spaces).
                    $j = $i - 1;
                    while ($j > $lt && ($html[$j] === ' ' || $html[$j] === "\t" || $html[$j] === "\n" || $html[$j] === "\r")) {
                        $j--;
                    }
                    if ($html[$j] === '=') {
                        $quote = $c;
                    }
                } elseif ($c === '>') {
                    $gt = $i;
                    break;
                }
            }
            if ($gt === null) {
                break;
            }
            $selfClose = $html[$gt - 1] === '/';
            $insert = $selfClose ? $gt - 1 : $gt;
            $el = ['name' => $name, 'start' => $lt, 'insert' => $insert, 'open_end' => $gt + 1, 'close' => null, 'end' => null];
            $idx = count($out);
            $out[] = $el;
            $pos = $gt + 1;
            if (in_array($name, self::VOID, true) || $selfClose) {
                $out[$idx]['close'] = $gt + 1;
                $out[$idx]['end'] = $gt + 1;
                continue;
            }
            if (in_array($name, self::RAW, true)) {
                if (preg_match('/<\/' . $name . '\s*>/i', $html, $rm, PREG_OFFSET_CAPTURE, $pos)) {
                    $out[$idx]['close'] = $rm[0][1];
                    $out[$idx]['end'] = $rm[0][1] + strlen($rm[0][0]);
                    $pos = $out[$idx]['end'];
                } else {
                    $pos = $len;
                }
                continue;
            }
            $stack[] = $idx;
        }
        return $out;
    }

    /** Elements of a parsed fragment in document order. @return \DOMElement[] */
    public static function domElements(\DOMElement $root): array
    {
        return iterator_to_array($root->getElementsByTagName('*'), false);
    }

    /** Do the source elements and the parsed elements line up one to one? */
    public static function matches(array $src, array $dom): bool
    {
        if (count($src) !== count($dom)) {
            return false;
        }
        foreach ($dom as $i => $el) {
            if (strtolower($el->tagName) !== $src[$i]['name']) {
                return false;
            }
        }
        return true;
    }

    /** Insert attribute strings (' data-se="e3"') into the start tags of the given elements. $add: index => string */
    public static function insertAttrs(string $html, array $src, array $add): string
    {
        krsort($add);
        foreach ($add as $i => $attrs) {
            $at = $src[$i]['insert'];
            $html = substr($html, 0, $at) . $attrs . substr($html, $at);
        }
        return $html;
    }
}
