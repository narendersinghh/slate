<?php
declare(strict_types=1);

namespace Slate;

/**
 * Site-wide templates managed in the admin (Templates): HTML + simple tags, no PHP.
 *
 * Kinds & targets:
 *   single  : <post type>   e.g. single/leaders, single/page, single/post
 *   archive : <post type>   listing at /books/, blog listing (post)
 *   term    : <taxonomy>    e.g. term/category, term/nationality
 *   special : 404 | search
 *
 * Tags (all output is HTML-escaped unless written with triple braces {{{ }}}):
 *   {{title}} {{{content}}} {{excerpt}} {{url}} {{date}} {{date:Y-m-d}} {{modified}} {{author}} {{image}} {{image:thumb}} {{image_url}} {{id}} {{slug}} {{type}}
 *   {{field:name}}  {{{field:name}}}  {{field:name|image}}  {{field:name|date:F j, Y}}  {{field:name|url}}  {{field:name|nl2br}}
 *   {{terms:taxonomy}} (linked list)   {{terms:taxonomy|plain}}
 *   {{site.name}} {{site.tagline}} {{site.url}} {{year}}  {{menu:primary}}  {{{breadcrumbs}}}  {{{pagination}}}
 *   {{archive.title}} {{{archive.description}}} {{archive.count}}
 *   {{edit:title}} {{edit:content}} {{edit:field:name}}   (editable on the live site for team members)
 *   {{#if field:name}} … {{else}} … {{/if}}   {{#unless …}} … {{/unless}}
 *   {{#each field:repeater}} {{item:sub}} {{{item:sub}}} {{index}} {{/each}}
 *   {{#each terms:taxonomy}} {{item.name}} {{item.url}} {{item.count}} {{/each}}
 *   {{#each related:field}} … post tags … {{/each}}      (relationship field posts)
 *   {{#posts}} … post tags … {{else}} No items {{/posts}}  (items of the current archive / term / search)
 *   {{#query type=leaders limit=6 orderby=title order=asc term=role:keynote-speaker}} … {{/query}}
 */
final class Templates
{
    public const KINDS = ['single' => 'Single item', 'archive' => 'Archive / listing', 'term' => 'Taxonomy archive', 'special' => 'Special page'];
    public const SPECIAL = ['404' => 'Not found (404)', 'search' => 'Search results'];
    private const MAX_DEPTH = 8;

    private static int $depth = 0;

    public static function all(): array
    {
        $t = Options::get('templates');
        return is_array($t) ? $t : [];
    }

    public static function find(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /** The enabled template for a kind/target (or a preview requested by an editor). */
    public static function resolve(string $kind, string $target): ?array
    {
        $preview = (string)($_GET['slate_template'] ?? '');
        if ($preview !== '' && Auth::can('manage_content_model') && ($t = self::find($preview)) && $t['kind'] === $kind) {
            return $t;
        }
        foreach (self::all() as $t) {
            if (!empty($t['enabled']) && $t['kind'] === $kind && $t['target'] === $target) {
                return $t;
            }
        }
        return null;
    }

    public static function save(array $in): string
    {
        $all = self::all();
        $id = preg_replace('/[^a-z0-9]/', '', (string)($in['id'] ?? '')) ?: 't' . bin2hex(random_bytes(4));
        $kind = array_key_exists($in['kind'] ?? '', self::KINDS) ? $in['kind'] : 'single';
        $target = preg_replace('/[^a-z0-9_-]/', '', (string)($in['target'] ?? ''));
        if (!empty($in['enabled'])) {
            foreach ($all as $k => $t) {
                if ($k !== $id && $t['kind'] === $kind && $t['target'] === $target) {
                    $all[$k]['enabled'] = false; // one active template per kind/target
                }
            }
        }
        $all[$id] = [
            'id' => $id,
            'name' => mb_substr(trim(strip_tags((string)($in['name'] ?? ''))) ?: self::label($kind, $target), 0, 120),
            'kind' => $kind,
            'target' => $target,
            'html' => str_replace("\r\n", "\n", (string)($in['html'] ?? '')),
            'css' => str_ireplace('</style', '', str_replace("\r\n", "\n", (string)($in['css'] ?? ''))),
            'wrap' => !empty($in['wrap']),
            'enabled' => !empty($in['enabled']),
            'updated_at' => now(),
        ];
        Options::set('templates', $all);
        PageCache::clear();
        return $id;
    }

    public static function delete(string $id): void
    {
        $all = self::all();
        unset($all[$id]);
        Options::set('templates', $all);
        PageCache::clear();
    }

    public static function label(string $kind, string $target): string
    {
        return match ($kind) {
            'single' => (ContentModel::type($target)['singular'] ?? ucfirst($target)) . ' — single',
            'archive' => (ContentModel::type($target)['plural'] ?? ucfirst($target)) . ' — archive',
            'term' => (ContentModel::taxonomy($target)['singular'] ?? ucfirst($target)) . ' — archive',
            default => self::SPECIAL[$target] ?? ucfirst($target),
        };
    }

    // ------------------------------------------------------------------ rendering

    /** Render a full page with the template (inside the theme's header/footer when "wrap" is on). */
    public static function renderPage(array $tpl, array $ctx): string
    {
        $body = '';
        if ($tpl['css'] !== '') {
            $body .= '<style id="slate-template-' . e($tpl['id']) . '">' . $tpl['css'] . '</style>';
        }
        $body .= '<div class="slate-template slate-template-' . e($tpl['kind']) . '-' . e($tpl['target']) . '">' . self::render($tpl['html'], $ctx) . '</div>';
        if (empty($tpl['wrap'])) {
            ob_start();
            echo '<!doctype html><html lang="' . e((string)Options::get('language', 'en')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
            slate_head();
            echo '</head><body>';
            slate_body_open();
            echo $body;
            slate_footer();
            echo '</body></html>';
            return (string)ob_get_clean();
        }
        ob_start();
        Theme::partial('header');
        echo '<main id="content" class="site-main">' . $body . '</main>';
        Theme::partial('footer');
        return (string)ob_get_clean();
    }

    /** Render a template string with a context: ['post'=>?, 'result'=>?, 'term'=>?, 'archive'=>[]]. */
    public static function render(string $tpl, array $ctx): string
    {
        if (++self::$depth > self::MAX_DEPTH) {
            self::$depth--;
            return '';
        }
        try {
            return self::renderNodes(self::parse($tpl), $ctx);
        } finally {
            self::$depth--;
        }
    }

    /** Parse into a tree: text | var | raw | block(name, args, children, else). */
    private static function parse(string $src): array
    {
        preg_match_all('/\{\{\{\s*(.+?)\s*\}\}\}|\{\{\s*([#\/]?)([^{}]*?)\s*\}\}/s', $src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $tokens = [];
        $pos = 0;
        foreach ($m as $tok) {
            $offset = $tok[0][1];
            if ($offset > $pos) {
                $tokens[] = ['text', substr($src, $pos, $offset - $pos)];
            }
            $pos = $offset + strlen($tok[0][0]);
            if (isset($tok[1]) && $tok[1][1] !== -1 && $tok[1][0] !== '') {
                $tokens[] = ['raw', trim($tok[1][0])];
                continue;
            }
            $sigil = $tok[2][0] ?? '';
            $expr = trim($tok[3][0] ?? '');
            if ($sigil === '#') {
                [$name, $args] = array_pad(preg_split('/\s+/', $expr, 2), 2, '');
                $tokens[] = ['open', $name, trim((string)$args)];
            } elseif ($sigil === '/') {
                $tokens[] = ['close'];
            } elseif ($expr === 'else') {
                $tokens[] = ['else'];
            } else {
                $tokens[] = ['var', $expr];
            }
        }
        if ($pos < strlen($src)) {
            $tokens[] = ['text', substr($src, $pos)];
        }
        $i = 0;
        return self::build($tokens, $i, false)['children'];
    }

    /** @return array{children: array, else: ?array} */
    private static function build(array $tokens, int &$i, bool $inBlock): array
    {
        $children = [];
        $else = null;
        $n = count($tokens);
        while ($i < $n) {
            $t = $tokens[$i++];
            switch ($t[0]) {
                case 'text':
                case 'var':
                case 'raw':
                    $node = $t[0] === 'text' ? ['t' => 'text', 'v' => $t[1]] : ['t' => $t[0], 'v' => $t[1]];
                    if ($else !== null) {
                        $else[] = $node;
                    } else {
                        $children[] = $node;
                    }
                    break;
                case 'open':
                    $inner = self::build($tokens, $i, true);
                    $node = ['t' => 'block', 'name' => $t[1], 'args' => $t[2], 'children' => $inner['children'], 'else' => $inner['else']];
                    if ($else !== null) {
                        $else[] = $node;
                    } else {
                        $children[] = $node;
                    }
                    break;
                case 'else':
                    if ($inBlock && $else === null) {
                        $else = [];
                    }
                    break;
                case 'close':
                    if ($inBlock) {
                        return ['children' => $children, 'else' => $else];
                    }
                    break;
            }
        }
        return ['children' => $children, 'else' => $else];
    }

    private static function renderNodes(array $nodes, array $ctx): string
    {
        $out = '';
        foreach ($nodes as $n) {
            $out .= match ($n['t']) {
                'text' => $n['v'],
                'var' => self::value($n['v'], $ctx, false),
                'raw' => self::value($n['v'], $ctx, true),
                'block' => self::block($n, $ctx),
                default => '',
            };
        }
        return $out;
    }

    private static function block(array $n, array $ctx): string
    {
        switch ($n['name']) {
            case 'if':
            case 'unless':
                $truthy = self::truthy(self::lookup($n['args'], $ctx));
                if ($n['name'] === 'unless') {
                    $truthy = !$truthy;
                }
                return self::renderNodes($truthy ? $n['children'] : ($n['else'] ?? []), $ctx);

            case 'each':
                $arg = $n['args'];
                $out = '';
                if (str_starts_with($arg, 'related:')) {
                    $ids = array_filter(array_map('intval', (array)(($ctx['post']['fields'] ?? [])[substr($arg, 8)] ?? [])));
                    Posts::prime($ids);
                    $posts = array_values(array_filter(array_map(static fn($id) => Posts::find($id), $ids), static fn($p) => $p && $p['status'] === 'publish'));
                    foreach ($posts as $i => $p) {
                        $out .= self::renderNodes($n['children'], ['post' => $p, 'index' => $i + 1, 'parent' => $ctx] + $ctx);
                    }
                    return $out !== '' ? $out : self::renderNodes($n['else'] ?? [], $ctx);
                }
                if (str_starts_with($arg, 'terms:')) {
                    $terms = self::postTerms($ctx['post'] ?? null, substr($arg, 6));
                    foreach ($terms as $i => $t) {
                        $out .= self::renderNodes($n['children'], ['item' => ['name' => $t['name'], 'url' => Terms::link($t), 'slug' => $t['slug'],
                            'count' => $t['count'], 'description' => $t['description']], 'index' => $i + 1] + $ctx);
                    }
                    return $out !== '' ? $out : self::renderNodes($n['else'] ?? [], $ctx);
                }
                $list = self::lookup($arg, $ctx);
                if (is_array($list) && $list && array_is_list($list)) {
                    foreach ($list as $i => $item) {
                        $out .= self::renderNodes($n['children'], ['item' => $item, 'index' => $i + 1] + $ctx);
                    }
                    return $out;
                }
                return self::renderNodes($n['else'] ?? [], $ctx);

            case 'posts':
                $items = $ctx['result']['items'] ?? [];
                $out = '';
                foreach ($items as $i => $p) {
                    $out .= self::renderNodes($n['children'], ['post' => $p, 'index' => $i + 1] + $ctx);
                }
                return $items ? $out : self::renderNodes($n['else'] ?? [], $ctx);

            case 'query':
                $args = self::args($n['args']);
                $q = ['type' => array_values(array_filter(explode(',', (string)($args['type'] ?? 'post')), static fn($t) => ContentModel::type($t) !== null)) ?: ['post'],
                    'limit' => max(1, min(50, (int)($args['limit'] ?? 6))), 'orderby' => (string)($args['orderby'] ?? 'published_at'),
                    'order' => (string)($args['order'] ?? 'desc'), 'with_terms' => true, 'count' => false];
                if (!empty($args['term']) && str_contains((string)$args['term'], ':')) {
                    [$tax, $slug] = explode(':', (string)$args['term'], 2);
                    $q['taxonomy'] = $tax;
                    $q['term'] = [$slug];
                }
                if (!empty($args['exclude_current']) && !empty($ctx['post']['id'])) {
                    $q['exclude'] = [(int)$ctx['post']['id']];
                }
                $items = Posts::query($q)['items'];
                $out = '';
                foreach ($items as $i => $p) {
                    $out .= self::renderNodes($n['children'], ['post' => $p, 'index' => $i + 1] + $ctx);
                }
                return $items ? $out : self::renderNodes($n['else'] ?? [], $ctx);
        }
        return '';
    }

    private static function args(string $s): array
    {
        preg_match_all('/([a-z_]+)=("[^"]*"|\S+)/i', $s, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $x) {
            $out[strtolower($x[1])] = trim($x[2], '"');
        }
        return $out;
    }

    private static function truthy(mixed $v): bool
    {
        return !($v === null || $v === false || $v === '' || $v === [] || $v === 0 || $v === '0');
    }

    private static function postTerms(?array $post, string $tax): array
    {
        if (!$post) {
            return [];
        }
        if (!isset($post['terms'])) {
            $post['terms'] = Terms::forPosts([(int)$post['id']])[(int)$post['id']] ?? [];
        }
        return $post['terms'][$tax] ?? [];
    }

    /** Raw value lookup (for #if / #each). */
    private static function lookup(string $expr, array $ctx): mixed
    {
        $expr = trim(explode('|', $expr)[0]);
        $post = $ctx['post'] ?? null;
        if (str_starts_with($expr, 'field:')) {
            return $post['fields'][substr($expr, 6)] ?? null;
        }
        if (str_starts_with($expr, 'item:')) {
            return is_array($ctx['item'] ?? null) ? ($ctx['item'][substr($expr, 5)] ?? null) : null;
        }
        if (str_starts_with($expr, 'item.')) {
            return is_array($ctx['item'] ?? null) ? ($ctx['item'][substr($expr, 5)] ?? null) : null;
        }
        if (str_starts_with($expr, 'terms:')) {
            return self::postTerms($post, substr($expr, 6));
        }
        if (str_starts_with($expr, 'related:')) {
            return array_filter((array)($post['fields'][substr($expr, 8)] ?? []));
        }
        if (str_starts_with($expr, 'archive.')) {
            return $ctx['archive'][substr($expr, 8)] ?? null;
        }
        return match ($expr) {
            'title' => $post['title'] ?? null, 'content' => $post['content'] ?? null, 'excerpt' => $post ? Posts::excerpt($post) : null,
            'image' => $post['featured_media_id'] ?? null, 'posts' => $ctx['result']['items'] ?? [], 'item' => $ctx['item'] ?? null,
            default => null,
        };
    }

    /** Output value for {{expr}} (escaped) or {{{expr}}} (raw). */
    private static function value(string $expr, array $ctx, bool $raw): string
    {
        $parts = array_map('trim', explode('|', $expr));
        $key = array_shift($parts);
        $filter = $parts[0] ?? '';
        $post = $ctx['post'] ?? null;
        $esc = static fn($v) => $raw ? (string)$v : e($v);

        // Simple keys with optional ":arg"
        [$name, $arg] = array_pad(explode(':', $key, 2), 2, null);
        switch ($name) {
            case 'title':
                return $esc($post['title'] ?? '');
            case 'content':
                return $post ? ($raw ? the_content($post) : e(strip_tags(the_content($post)))) : '';
            case 'excerpt':
                return $post ? $esc(Posts::excerpt($post, (int)($arg ?: 160))) : '';
            case 'url':
                return $post ? e(Posts::permalink($post)) : '';
            case 'id':
                return (string)(int)($post['id'] ?? 0);
            case 'slug':
                return e($post['slug'] ?? '');
            case 'type':
                return e($post['type'] ?? '');
            case 'date':
                return $post ? e(local_date($post['published_at'] ?: $post['created_at'], $arg ?: null)) : '';
            case 'modified':
                return $post ? e(local_date($post['updated_at'], $arg ?: null)) : '';
            case 'author':
                $a = !empty($post['author_id']) ? DB::row('SELECT display_name FROM {{users}} WHERE id = :id', ['id' => (int)$post['author_id']]) : null;
                return e($a['display_name'] ?? '');
            case 'image':
                return $post && $post['featured_media_id'] ? image((int)$post['featured_media_id'], ['size' => $arg ?: 'full']) : '';
            case 'image_url':
                return $post && $post['featured_media_id'] ? e(media_url((int)$post['featured_media_id'], $arg ?: 'full')) : '';
            case 'index':
                return (string)(int)($ctx['index'] ?? 0);
            case 'year':
                return date('Y');
            case 'menu':
                return menu((string)$arg, 'menu');
            case 'breadcrumbs':
                return breadcrumbs();
            case 'pagination':
                return paginate($ctx['result'] ?? null);
            case 'edit':
                return $post ? editable((string)$arg, str_starts_with((string)$arg, 'field:') ? 'div' : ($arg === 'title' ? 'h1' : 'div'), $post) : '';
            case 'terms':
                $terms = self::postTerms($post, (string)$arg);
                if ($filter === 'plain') {
                    return e(implode(', ', array_column($terms, 'name')));
                }
                return implode(', ', array_map(static fn($t) => '<a href="' . e(Terms::link($t)) . '">' . e($t['name']) . '</a>', $terms));
            case 'field':
            case 'item':
                $v = $name === 'field' ? ($post['fields'][(string)$arg] ?? null) : (is_array($ctx['item'] ?? null) ? ($ctx['item'][(string)$arg] ?? null) : null);
                return self::format($v, $filter, $raw);
        }
        if (str_starts_with($key, 'item.')) {
            return self::format(is_array($ctx['item'] ?? null) ? ($ctx['item'][substr($key, 5)] ?? null) : null, $filter, $raw);
        }
        if (str_starts_with($key, 'site.')) {
            return e(match (substr($key, 5)) {
                'name' => site_name(), 'tagline' => site_tagline(), 'url' => url(), default => '',
            });
        }
        if (str_starts_with($key, 'archive.')) {
            $v = $ctx['archive'][substr($key, 8)] ?? '';
            return $raw ? (string)$v : e($v);
        }
        return '';
    }

    private static function format(mixed $v, string $filter, bool $raw): string
    {
        [$f, $farg] = array_pad(explode(':', $filter, 2), 2, null);
        if ($f === 'image') {
            return is_numeric($v) ? image((int)$v, ['size' => $farg ?: 'full']) : '';
        }
        if ($f === 'image_url') {
            return is_numeric($v) ? e(media_url((int)$v, $farg ?: 'full')) : '';
        }
        if ($f === 'date') {
            $ts = is_string($v) && $v !== '' ? strtotime($v) : false;
            return $ts ? e(date($farg ?: (string)Options::get('date_format', 'M j, Y'), $ts)) : '';
        }
        if ($f === 'url') {
            return e(ContentModel::cleanUrl((string)$v));
        }
        if ($f === 'nl2br') {
            return nl2br(e(is_scalar($v) ? $v : ''));
        }
        if ($f === 'embed' && is_string($v)) {
            if (preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/)([\w-]{11})#', $v, $m)) {
                return '<iframe class="slate-embed" src="https://www.youtube-nocookie.com/embed/' . $m[1] . '" title="Video" loading="lazy" allowfullscreen></iframe>';
            }
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'Yes' : '';
        }
        if (is_array($v)) {
            $v = implode(', ', array_filter($v, 'is_scalar'));
        }
        return $raw ? (string)$v : e((string)$v);
    }

    // ------------------------------------------------------------------ starters

    /** A working starter template that shows every field of the target. */
    public static function starter(string $kind, string $target): string
    {
        if ($kind === 'single') {
            $type = ContentModel::type($target);
            $lines = ["<article class=\"container section\">", "  {{{breadcrumbs}}}", "  <header>", "    {{edit:title}}"];
            if ($target !== 'page') {
                $lines[] = "    <p class=\"muted\">{{date}}</p>";
            }
            $lines[] = "  </header>";
            $lines[] = "  {{#if image}}<figure>{{image}}</figure>{{/if}}";
            if (in_array('editor', $type['supports'] ?? ['editor'], true)) {
                $lines[] = "  <div class=\"entry-content\">{{edit:content}}</div>";
            }
            foreach (ContentModel::fieldsFor('post', $target) as $f) {
                $lines = array_merge($lines, self::fieldSnippet($f));
            }
            foreach (ContentModel::taxonomiesFor($target) as $tx => $t) {
                $lines[] = "  {{#if terms:$tx}}<p><strong>" . e($t['plural']) . ":</strong> {{{terms:$tx}}}</p>{{/if}}";
            }
            $lines[] = "</article>";
            return implode("\n", $lines) . "\n";
        }
        if ($kind === 'archive' || $kind === 'term' || ($kind === 'special' && $target === 'search')) {
            $head = $kind === 'term' ? "  <h1>{{archive.title}}</h1>\n  {{#if archive.description}}<div class=\"lead\">{{{archive.description}}}</div>{{/if}}" : "  <h1>{{archive.title}}</h1>";
            return "<section class=\"container section\">\n  {{{breadcrumbs}}}\n$head\n  <div class=\"grid\">\n  {{#posts}}\n    <article class=\"card\">\n      <a href=\"{{url}}\">{{image:thumb}}</a>\n      <h2><a href=\"{{url}}\">{{title}}</a></h2>\n      <p>{{excerpt}}</p>\n    </article>\n  {{else}}\n    <p>Nothing found.</p>\n  {{/posts}}\n  </div>\n  {{{pagination}}}\n</section>\n";
        }
        return "<section class=\"container section\" style=\"text-align:center\">\n  <h1>Page not found</h1>\n  <p>The page you are looking for doesn’t exist.</p>\n  <p><a class=\"btn\" href=\"{{site.url}}\">Back to homepage</a></p>\n</section>\n";
    }

    private static function fieldSnippet(array $f): array
    {
        $n = $f['name'];
        $label = e($f['label']);
        return match ($f['type']) {
            'image' => ["  {{#if field:$n}}<figure class=\"field-$n\">{{field:$n|image}}</figure>{{/if}}"],
            'richtext' => ["  {{#if field:$n}}<section class=\"field-$n\"><h2>$label</h2>{{{field:$n}}}</section>{{/if}}"],
            'textarea' => ["  {{#if field:$n}}<p class=\"field-$n\">{{field:$n|nl2br}}</p>{{/if}}"],
            'date' => ["  {{#if field:$n}}<p class=\"field-$n\"><strong>$label:</strong> {{field:$n|date}}</p>{{/if}}"],
            'url' => ["  {{#if field:$n}}<p class=\"field-$n\"><a href=\"{{field:$n|url}}\" rel=\"noopener\">$label</a></p>{{/if}}"],
            'toggle' => [],
            'gallery' => ["  {{#if field:$n}}<div class=\"gallery field-$n\">{{#each field:$n}}{{item:0}}{{/each}}</div>{{/if}}"],
            'relation' => ["  {{#if field:$n}}<section class=\"field-$n\"><h2>$label</h2><ul>{{#each related:$n}}<li><a href=\"{{url}}\">{{title}}</a></li>{{/each}}</ul></section>{{/if}}"],
            'repeater' => array_merge(
                ["  {{#if field:$n}}<section class=\"field-$n\"><h2>$label</h2>", "    {{#each field:$n}}<div class=\"item\">"],
                array_map(static fn($sf) => '      ' . match ($sf['type']) {
                    'richtext' => '{{{item:' . $sf['name'] . '}}}',
                    'image' => '{{item:' . $sf['name'] . '|image}}',
                    'url' => '<a href="{{item:' . $sf['name'] . '|url}}">{{item:' . $sf['name'] . '}}</a>',
                    default => '<p>{{item:' . $sf['name'] . '}}</p>',
                }, $f['sub_fields'] ?? []),
                ["    </div>{{/each}}", "  </section>{{/if}}"]
            ),
            default => ["  {{#if field:$n}}<p class=\"field-$n\"><strong>$label:</strong> {{field:$n}}</p>{{/if}}"],
        };
    }
}
