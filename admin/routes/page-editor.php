<?php
/** On-page editor: designed pages, stored content, titles and fields, taxonomy terms (core/Slate/PageEditor.php, admin/assets/page-editor.js). */
use Slate\Auth;
use Slate\HtmlPages;
use Slate\PageEditor;
use Slate\Posts;
use Slate\Router;

return static function (Router $r): void {
    $load = static function (string $id): array {
        Auth::require('edit_posts');
        $post = Posts::find((int)$id);
        if (!$post || $post['status'] === 'trash') {
            json_response(['ok' => false, 'error' => 'This page cannot be edited on the page.'], 404);
        }
        if (!PageEditor::canEdit($post) || !admin_can_edit($post)) {
            json_response(['ok' => false, 'error' => 'You are not allowed to edit this page.'], 403);
        }
        return $post;
    };

    // Save a batch of operations: {ops: [...], base: "<updated_at the editor started from>"}.
    $r->post('/page-editor/{id:\d+}/save', static function (string $id) use ($load) {
        $post = $load($id);
        $in = json_input();
        $base = (string)($in['base'] ?? '');
        if ($base !== '' && empty($in['force']) && $base !== (string)$post['updated_at']) {
            json_response(['ok' => false, 'conflict' => true, 'error' => 'Someone else changed this page since you opened it. Reload to get the latest version.'], 409);
        }
        $ops = $in['ops'] ?? null;
        if (!is_array($ops)) {
            json_response(['ok' => false, 'error' => 'Nothing to save.'], 422);
        }
        if ($err = PageEditor::apply($post, $ops)) {
            json_response(['ok' => false, 'error' => $err], 422);
        }
        $now = Posts::find((int)$id);
        log_activity('page_edited', (string)$now['title']);
        $body = HtmlPages::active($now) ? (string)(HtmlPages::data($now)['body'] ?? '') : (string)(PageEditor::annotateContent((string)$now['content'])['body'] ?? '');
        json_response(['ok' => true, 'updated' => (string)$now['updated_at'], 'loops' => PageEditor::loops($body),
            'revisions' => \Slate\Revisions::count((int)$id)]);
    });

    // Term pages: name and term fields. {ops: [{op: 'field', bind: 'term:4:field:lead', value}]}
    $r->post('/page-editor/term/{id:\d+}/save', static function (string $id) {
        Auth::require('edit_posts');
        Auth::require('manage_terms');
        if (!Auth::can('frontend_edit')) {
            json_response(['ok' => false, 'error' => 'You are not allowed to edit on the page.'], 403);
        }
        $term = \Slate\Terms::find((int)$id);
        if (!$term) {
            json_response(['ok' => false, 'error' => 'Term not found.'], 404);
        }
        $ops = json_input()['ops'] ?? null;
        if (!is_array($ops)) {
            json_response(['ok' => false, 'error' => 'Nothing to save.'], 422);
        }
        if ($err = PageEditor::applyTerm($term, $ops)) {
            json_response(['ok' => false, 'error' => $err], 422);
        }
        log_activity('term_edited', (string)$term['name']);
        json_response(['ok' => true, 'updated' => '']);
    });

    // AI for pages and terms that are not tied to one designed page.
    $r->post('/page-editor/ai', static function () {
        Auth::require('edit_posts');
        Auth::require('use_ai');
        if (!\Slate\Ai::enabled()) {
            json_response(['ok' => false, 'error' => 'AI is not set up. Go to Settings, AI.'], 400);
        }
        @set_time_limit(180);
        $in = json_input();
        $res = PageEditor::ai((string)($in['action'] ?? ''), (string)($in['text'] ?? ''), (string)($in['lang'] ?? ''), (string)($in['context'] ?? ''));
        json_response($res, $res['ok'] ? 200 : 422);
    });

    // Terms of a taxonomy for the "Make dynamic" filter (slugs are what {{#query term=tax:slug}} needs).
    $r->get('/page-editor/terms', static function () {
        Auth::require('edit_posts');
        $tax = (string)($_GET['taxonomy'] ?? '');
        if (!\Slate\ContentModel::taxonomy($tax)) {
            json_response(['ok' => false, 'error' => 'Unknown taxonomy.'], 404);
        }
        json_response(['ok' => true, 'items' => array_map(static fn($t) => ['slug' => $t['slug'], 'name' => $t['name'], 'count' => (int)$t['count']],
            array_slice(\Slate\Terms::all($tax), 0, 200))]);
    });

    // AI: rewrite a text element or suggest alt text. {action, text, lang?, context?}
    $r->post('/page-editor/{id:\d+}/ai', static function (string $id) use ($load) {
        $load($id);
        Auth::require('use_ai');
        if (!\Slate\Ai::enabled()) {
            json_response(['ok' => false, 'error' => 'AI is not set up. Go to Settings, AI.'], 400);
        }
        @set_time_limit(180);
        $in = json_input();
        $res = PageEditor::ai((string)($in['action'] ?? ''), (string)($in['text'] ?? ''), (string)($in['lang'] ?? ''), (string)($in['context'] ?? ''));
        if ($res['ok']) {
            log_activity('page_editor_ai', (string)($in['action'] ?? ''));
        }
        json_response($res, $res['ok'] ? 200 : 422);
    });
};
