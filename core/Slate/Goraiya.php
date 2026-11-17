<?php
declare(strict_types=1);

namespace Slate;

/**
 * Goraiya (the sparrow): Slate's built-in AI. A chat drawer in the dashboard that builds the site with you: it writes
 * pages (complete designed documents, editable by clicking on the live page), designs templates for content types,
 * changes designed pages, and connects lists to live content. Everything it makes is a draft until a person publishes.
 *
 * Works with any provider set up in Settings → AI assistant (Claude, OpenAI, Gemini, local models…).
 */
final class Goraiya
{
    private const MAX_HISTORY = 12;

    /** One chat turn. $history: [['role' => 'user'|'assistant', 'text' => …]]. Returns ['ok', 'reply', 'results' => [...]]. */
    public static function chat(string $message, array $history = [], array $context = []): array
    {
        if (!Ai::enabled()) {
            return ['ok' => false, 'error' => 'Connect an AI provider first.', 'needs_setup' => true];
        }
        $message = trim(mb_substr($message, 0, 6000));
        if ($message === '') {
            return ['ok' => false, 'error' => 'Write what you would like Goraiya to do.'];
        }
        $transcript = '';
        foreach (array_slice($history, -self::MAX_HISTORY) as $h) {
            $who = ($h['role'] ?? '') === 'assistant' ? 'Goraiya' : 'User';
            $transcript .= "$who: " . mb_substr((string)($h['text'] ?? ''), 0, 1500) . "\n";
        }
        $prompt = ($transcript !== '' ? "Conversation so far:\n$transcript\n" : '') . self::contextText($context) . "User: $message";
        $r = Ai::complete($prompt, self::system(), ['max_tokens' => 16000, 'min_tokens' => 12000, 'effort' => 'medium', 'timeout' => 300]);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error']];
        }
        $json = self::parse((string)$r['text']);
        if ($json === null) {
            return ['ok' => true, 'reply' => Ai::noEmDash(trim((string)$r['text'])), 'results' => []];
        }
        $results = [];
        foreach (array_slice((array)($json['actions'] ?? []), 0, 6) as $action) {
            if (is_array($action)) {
                $results[] = self::run($action);
            }
        }
        log_activity('goraiya', mb_substr($message, 0, 140));
        return ['ok' => true, 'reply' => Ai::noEmDash(trim((string)($json['reply'] ?? ''))), 'results' => $results];
    }

    // ------------------------------------------------------------------ actions

    private static function run(array $a): array
    {
        try {
            return match ((string)($a['type'] ?? '')) {
                'create_page' => self::createPage($a),
                'update_page' => self::updatePage($a),
                'create_template' => self::createTemplate($a),
                'edit_page' => self::editPage($a),
                default => ['ok' => false, 'error' => 'Unknown action.'],
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private static function createPage(array $a): array
    {
        Auth::require('edit_posts');
        $type = ContentModel::type((string)($a['post_type'] ?? 'page')) ? (string)($a['post_type'] ?? 'page') : 'page';
        $parsed = self::document((string)($a['html'] ?? ''));
        $title = trim((string)($a['title'] ?? '')) ?: $parsed['title'] ?: 'New page';
        $id = Posts::save(['type' => $type, 'title' => $title, 'slug' => slugify((string)($a['slug'] ?? $title)), 'status' => 'draft', 'content' => '', 'author_id' => Auth::id()]);
        HtmlPages::save($id, $parsed['data'], $parsed['description'] !== '' ? ['seo' => ['description' => $parsed['description']]] : []);
        if (!empty($a['homepage']) && $type === 'page' && Auth::can('manage_settings')) {
            Options::set('front_page', 'page');
            Options::set('front_page_id', $id);
        }
        $p = Posts::find($id);
        return ['ok' => true, 'kind' => 'page', 'title' => $p['title'], 'status' => 'draft', 'preview' => Posts::permalink($p) . '?preview=' . $id,
            'edit' => admin_url("content/$type/$id"), 'message' => 'Draft page created.' . (!empty($a['homepage']) ? ' Set as the homepage.' : '')];
    }

    private static function updatePage(array $a): array
    {
        $p = Posts::find((int)($a['id'] ?? 0));
        if (!$p || !self::canEdit($p)) {
            return ['ok' => false, 'error' => 'That page was not found or you may not edit it.'];
        }
        $parsed = self::document((string)($a['html'] ?? ''));
        HtmlPages::save((int)$p['id'], $parsed['data']);
        $p = Posts::find((int)$p['id']);
        return ['ok' => true, 'kind' => 'page', 'title' => $p['title'], 'status' => $p['status'], 'preview' => Posts::permalink($p) . ($p['status'] === 'publish' ? '' : '?preview=' . $p['id']),
            'edit' => admin_url("content/{$p['type']}/{$p['id']}"), 'message' => 'Page updated (the previous version is kept in Revisions).'];
    }

    /** Operations on a designed page (the same ones as on-page editing, e.g. loop_dynamic to connect a list to posts). */
    private static function editPage(array $a): array
    {
        $p = Posts::find((int)($a['id'] ?? 0));
        if (!$p || !self::canEdit($p) || !HtmlPages::active($p) || !class_exists(PageEditor::class)) {
            return ['ok' => false, 'error' => 'That page cannot be edited this way.'];
        }
        if ($err = PageEditor::apply($p, array_values(array_filter((array)($a['ops'] ?? []), 'is_array')))) {
            return ['ok' => false, 'error' => $err];
        }
        $p = Posts::find((int)$p['id']);
        return ['ok' => true, 'kind' => 'page', 'title' => $p['title'], 'status' => $p['status'], 'preview' => Posts::permalink($p) . ($p['status'] === 'publish' ? '' : '?preview=' . $p['id']),
            'edit' => admin_url("content/{$p['type']}/{$p['id']}"), 'message' => 'Changes applied (the previous version is kept in Revisions).'];
    }

    private static function createTemplate(array $a): array
    {
        Auth::require('manage_content_model');
        $kind = array_key_exists((string)($a['kind'] ?? ''), Templates::KINDS) ? (string)$a['kind'] : 'single';
        $target = preg_replace('/[^a-z0-9_-]/', '', (string)($a['target'] ?? 'post'));
        $id = Templates::save(['name' => (string)($a['name'] ?? Templates::label($kind, $target)), 'kind' => $kind, 'target' => $target,
            'html' => HtmlPages::stripScripts((string)($a['html'] ?? '')), 'css' => (string)($a['css'] ?? ''), 'wrap' => ($a['layout'] ?? 'theme') !== 'blank', 'enabled' => false]);
        $t = Templates::find($id);
        return ['ok' => true, 'kind' => 'template', 'title' => $t['name'], 'status' => 'draft', 'preview' => admin_template_preview_url($t) ?: '',
            'edit' => admin_url('templates/' . $id), 'message' => 'Template saved as inactive: preview it, then switch it on.'];
    }

    private static function canEdit(array $p): bool
    {
        return Auth::can('edit_others_posts') || ((int)$p['author_id'] === Auth::id() && Auth::can('edit_posts'));
    }

    /** A complete document from the AI (or a body fragment, wrapped). */
    private static function document(string $html): array
    {
        $html = trim((string)preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($html)));
        if ($html === '') {
            throw new \RuntimeException('The AI returned an empty page.');
        }
        if (!preg_match('#<body\b#i', $html)) {
            $html = '<!doctype html><html><head></head><body>' . $html . '</body></html>';
        }
        $parsed = HtmlPages::fromDocument($html, null, 'ai');
        $parsed['data']['head'] = HtmlPages::stripScripts((string)$parsed['data']['head']);
        if (!Auth::can('unfiltered_html')) {
            $parsed['data']['body'] = HtmlPages::stripScripts((string)$parsed['data']['body']);
        }
        return $parsed;
    }

    // ------------------------------------------------------------------ prompt

    private static function system(): string
    {
        $ds = DesignSystem::get();
        $vars = implode(', ', array_map(static fn($c) => 'var(--color-' . $c['id'] . ') ' . ($c['name'] ?? '') . ' ' . $c['value'], $ds['colors']));
        $fonts = ($ds['fonts']['heading'] ?? '') . ' (headings), ' . ($ds['fonts']['body'] ?? '') . ' (text)';
        $types = [];
        foreach (ContentModel::types() as $slug => $t) {
            if (empty($t['public'])) {
                continue;
            }
            $fields = implode(', ', array_map(static fn($f) => ($f['name'] ?? '') . ' (' . ($f['type'] ?? 'text') . ')', ContentModel::fieldsFor('post', $slug)));
            $types[] = "- $slug \"" . ($t['plural'] ?? $slug) . '"' . ($fields !== '' ? ": fields $fields" : '') . '; taxonomies: ' . implode(', ', array_keys(ContentModel::taxonomiesFor($slug)));
        }
        $pages = array_map(static fn($p) => '#' . $p['id'] . ' ' . $p['title'] . ' (' . $p['status'] . (HtmlPages::active($p) ? ', designed page' : '') . ')',
            Posts::query(['type' => 'page', 'status' => 'any', 'limit' => 40, 'count' => false, 'orderby' => 'updated_at'])['items']);
        $site = Options::get('site_title') . ' (' . Options::get('tagline') . '), ' . site_url();
        $types = implode("\n", $types);
        $pages = implode('; ', $pages) ?: 'none yet';
        return <<<TXT
You are Goraiya (the sparrow), the AI built into Slate CMS. You build and improve the website $site together with its owner.
Be warm and brief. Never use em dashes. Ask one short question when a request is too vague to build well; otherwise build it.

Reply with ONE JSON object and nothing else:
{"reply": "short message to the user", "actions": [ ... ]}
Actions you can take (use [] when only talking):
- {"type":"create_page","title":"…","slug":"…","post_type":"page","homepage":false,"html":"<!doctype html>…"}
- {"type":"update_page","id":123,"html":"<!doctype html>…"}   (replaces a designed page; a revision is kept)
- {"type":"edit_page","id":123,"ops":[ … ]}   (small changes to a designed page, see ops below)
- {"type":"create_template","kind":"single|archive|term|special","target":"<post type, taxonomy, 404 or search>","name":"…","html":"…","css":"…","layout":"theme|blank"}
Everything you create is a draft; never claim it is published.

Pages ("html"): a complete, polished, responsive HTML document with all CSS in a <style> in the <head> (Google Fonts <link> allowed),
no JavaScript unless the user asks. Match the site's design system: colours $vars; fonts $fonts. Use real, specific copy in the
user's language (never lorem ipsum), semantic sections, clear calls to action, accessible contrast, alt text. Images: use
https://images.unsplash.com/photo-… only if you are sure the URL exists, otherwise a tasteful CSS gradient or an inline SVG.
Repeating content (cards, testimonials, team, slides) as a parent element whose children share one structure, so people can edit
them as lists. Live content inside a page: {{#query type=post limit=3}}<article><a href="{{url}}">{{title}}</a><p>{{excerpt}}</p></article>{{/query}}.

Templates ("html" + "css") render every item of a content type through Slate's template engine: {{title}} {{{content}}}
{{excerpt}} {{url}} {{date}} {{author}} {{image}} {{image_url}} {{field:name}} {{{field:name}}} {{field:name|image}}
{{terms:taxonomy}} {{#if field:name}}…{{else}}…{{/if}} {{#each field:repeater}}{{item:sub}}{{/each}}
{{#query type=x limit=6 orderby=title order=asc term=tax:slug}}…{{/query}}; archives: {{archive.title}} {{#posts}}…{{else}}…{{/posts}} {{{pagination}}}.
"css" holds class-based CSS for the template. layout "theme" keeps the site header and footer.

edit_page ops (use the data-se ids from the page outline you are given): {"op":"text","id":"e3","html":"New <strong>text</strong>"},
{"op":"style","id":"e3","style":{"color":"#123456"}}, {"op":"image","id":"e5","src":"https://…","alt":"…"},
{"op":"loop","id":"l2","action":"duplicate|remove|move","index":0,"to":2},
{"op":"loop_dynamic","id":"l2","source":{"kind":"posts","type":"post","limit":6,"orderby":"published_at","order":"desc"},"map":{"e7":"title","e8":"excerpt","e9":"image","e10":"url"}}.

Site content types:
$types
Pages: $pages
TXT;
    }

    /** What the user is looking at (e.g. the page being edited), with an outline of a designed page. */
    private static function contextText(array $ctx): string
    {
        $id = (int)($ctx['post_id'] ?? 0);
        if (!$id || !($p = Posts::find($id)) || !self::canEdit($p)) {
            return '';
        }
        $out = "The user is looking at {$p['type']} #{$p['id']} \"{$p['title']}\" ({$p['status']}).\n";
        if (HtmlPages::active($p)) {
            $out .= "It is a designed page. Outline (data-se ids):\n" . self::outline((string)HtmlPages::data($p)['body']) . "\n";
        }
        return $out;
    }

    /** Compact outline of a designed page body: editable ids with tag and text, loops with item counts. */
    public static function outline(string $body, int $max = 120): string
    {
        [$doc, $root] = HtmlPages::fragment($body);
        $lines = [];
        $xp = new \DOMXPath($doc);
        foreach ($xp->query('.//*[@data-se or @data-se-loop]', $root) ?: [] as $el) {
            if (count($lines) >= $max) {
                $lines[] = '…';
                break;
            }
            if ($el->hasAttribute('data-se-loop')) {
                $items = 0;
                foreach ($el->childNodes as $c) {
                    $items += $c instanceof \DOMElement ? 1 : 0;
                }
                $lines[] = $el->getAttribute('data-se-loop') . ": repeating group of $items <" . strtolower($el->firstElementChild?->tagName ?? 'div') . '> items';
                continue;
            }
            $tag = strtolower($el->tagName);
            $text = $tag === 'img' ? 'image ' . $el->getAttribute('src') : mb_substr(trim(preg_replace('/\s+/', ' ', $el->textContent)), 0, 70);
            $lines[] = $el->getAttribute('data-se') . " <$tag> $text";
        }
        return implode("\n", $lines);
    }

    /** First JSON object in the model's answer. */
    private static function parse(string $text): ?array
    {
        $text = trim((string)preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)));
        $j = json_decode($text, true);
        if (is_array($j)) {
            return $j;
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end > $start) {
            $j = json_decode(substr($text, $start, $end - $start + 1), true);
            return is_array($j) ? $j : null;
        }
        return null;
    }

    /** Suggestions for the empty drawer, based on what the site has. */
    public static function suggestions(): array
    {
        $pages = (int)DB::val("SELECT COUNT(*) FROM {{posts}} WHERE type = 'page' AND status <> 'trash'");
        $s = [];
        if ($pages < 3) {
            $s[] = 'Create a homepage for my business: here is what we do…';
            $s[] = 'Create About and Contact pages';
        } else {
            $s[] = 'Create a landing page for a new offer';
            $s[] = 'Improve the homepage copy and make it more convincing';
        }
        $s[] = 'Design the template for blog posts';
        $s[] = 'Make the list on this page show my latest posts';
        return $s;
    }

    /** Save provider settings from the drawer's quick setup. */
    public static function connect(string $provider, string $key, string $model = ''): array
    {
        Auth::require('manage_settings');
        if (!isset(Ai::PRESETS[$provider])) {
            return ['ok' => false, 'error' => 'Choose a provider.'];
        }
        if ($key === '' && $provider !== 'ollama') {
            return ['ok' => false, 'error' => 'Paste the API key from your provider.'];
        }
        Options::set('ai_provider', $provider);
        if ($key !== '') {
            Options::set('ai_api_key', Crypto::encrypt($key));
        }
        Options::set('ai_model', $model);
        Options::set('ai_enabled', true);
        $test = Ai::complete('Reply with the single word: ready', 'You are a connection test.', ['max_tokens' => 2000, 'effort' => 'low', 'timeout' => 60]);
        if (!$test['ok']) {
            Options::set('ai_enabled', false);
            return ['ok' => false, 'error' => 'The provider did not answer: ' . $test['error']];
        }
        log_activity('ai_connected', $provider);
        return ['ok' => true];
    }
}
