<?php
/**
 * Admin JSON API (used by the dashboard UI and frontend editing).
 */
declare(strict_types=1);

use Slate\Ai;
use Slate\Auth;
use Slate\ContentModel;
use Slate\DB;
use Slate\Media;
use Slate\Posts;
use Slate\Router;
use Slate\Sanitizer;
use Slate\Settings;

function admin_api_routes(Router $r): void
{
    $r->get('/api/media', static function () {
        Auth::require('upload_media');
        json_response(['ok' => true] + Media::list((int)($_GET['page'] ?? 1), (string)($_GET['q'] ?? ''), (string)($_GET['filter'] ?? ''),
                min(100, max(10, (int)($_GET['per'] ?? 40)))));
    });

    $r->post('/api/media', static function () {
        Auth::require('upload_media');
        $files = $_FILES['file'] ?? null;
        if (!$files) {
            json_response(['ok' => false, 'error' => 'No file received. The file may exceed the server limit (' . ini_get('post_max_size') . ').'], 400);
        }
        // Normalise single and multiple uploads.
        $list = [];
        if (is_array($files['name'])) {
            foreach ($files['name'] as $i => $n) {
                $list[] = ['name' => $n, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
            }
        } else {
            $list[] = $files;
        }
        $out = [];
        $errors = [];
        foreach ($list as $f) {
            $res = Media::upload($f);
            if ($res['ok']) {
                $out[] = Media::toJson($res['media']);
                log_activity('media_uploaded', $res['media']['path']);
            } else {
                $errors[] = $f['name'] . ': ' . $res['error'];
            }
        }
        json_response(['ok' => (bool)$out, 'items' => $out, 'errors' => $errors, 'error' => $errors ? implode("\n", $errors) : null], $out ? 200 : 422);
    });

    $r->post('/api/media/{id:\d+}', static function (string $id) {
        Auth::require('upload_media');
        $m = Media::find((int)$id) ?? admin_404();
        if ((int)$m['uploaded_by'] !== Auth::id()) {
            Auth::require('manage_media');
        }
        $err = Media::update((int)$id, json_input() ?: $_POST);
        $err ? json_response(['ok' => false, 'error' => $err], 422) : json_response(['ok' => true, 'item' => Media::toJson(Media::find((int)$id))]);
    });

    $r->post('/api/media/{id:\d+}/delete', static function (string $id) {
        Auth::require('upload_media');
        $m = Media::find((int)$id) ?? admin_404();
        if ((int)$m['uploaded_by'] !== Auth::id()) {
            Auth::require('manage_media');
        }
        Media::delete((int)$id);
        log_activity('media_deleted', $m['path']);
        json_response(['ok' => true]);
    });

    $r->post('/api/ai', static function () {
        Auth::require('use_ai');
        if (!Ai::enabled()) {
            json_response(['ok' => false, 'error' => 'AI is not configured. Go to Settings → AI.'], 400);
        }
        $in = json_input();
        $res = Ai::action((string)($in['action'] ?? ''), [
            'content' => (string)($in['content'] ?? ''),
            'title' => (string)($in['title'] ?? ''),
            'input' => mb_substr((string)($in['input'] ?? ''), 0, 4000),
        ]);
        json_response($res, $res['ok'] ? 200 : 422);
    });

    $r->post('/api/ai/test', static function () {
        Auth::require('manage_settings');
        $res = Ai::complete('Reply with exactly: Slate connection OK', null, ['max_tokens' => 30]);
        json_response($res, $res['ok'] ? 200 : 422);
    });

    $r->post('/api/inline-save', static function () {
        Auth::require('frontend_edit');
        $in = json_input();
        $post = Posts::find((int)($in['id'] ?? 0)) ?? admin_404();
        if (!admin_can_edit($post)) {
            Auth::require('edit_others_posts');
        }
        $unfiltered = Auth::can('unfiltered_html');
        $data = [];
        if (isset($in['title'])) {
            $data['title'] = trim(strip_tags((string)$in['title']));
        }
        if (isset($in['content'])) {
            $data['content'] = $unfiltered ? (string)$in['content'] : Sanitizer::html((string)$in['content']);
        }
        if (isset($in['excerpt'])) {
            $data['excerpt'] = trim(strip_tags((string)$in['excerpt']));
        }
        if (!empty($in['fields']) && is_array($in['fields'])) {
            $defs = ContentModel::fieldsFor('post', $post['type']);
            $data['fields'] = [];
            foreach ($in['fields'] as $name => $value) {
                if (isset($defs[$name])) {
                    if ($defs[$name]['type'] === 'textarea' && is_string($value)) {
                        $value = preg_replace('#<br\s*/?>#i', "\n", $value);
                    }
                    $data['fields'][$name] = ContentModel::sanitizeValue($defs[$name], $value, $unfiltered);
                }
            }
        }
        // Keep the slug stable when only the title changes inline.
        $data['slug'] = $post['slug'];
        Posts::save($data, $post['id']);
        log_activity('content_inline_edit', $post['type'] . ':' . $post['title']);
        json_response(['ok' => true, 'message' => 'Saved']);
    });

    $r->get('/api/posts', static function () {
        Auth::require('edit_posts');
        $type = (string)($_GET['type'] ?? '');
        $types = $type !== '' && ContentModel::type($type) ? [$type] : array_keys(ContentModel::types());
        $res = Posts::query(['type' => $types, 'status' => 'any', 'search' => (string)($_GET['q'] ?? ''), 'limit' => 20, 'count' => false,
            'ids' => !empty($_GET['ids']) ? explode(',', (string)$_GET['ids']) : [], 'orderby' => 'title', 'order' => 'ASC']);
        json_response(['ok' => true, 'items' => array_map(static fn($p) => ['id' => $p['id'], 'title' => $p['title'] ?: '(no title)', 'type' => $p['type'],
            'status' => $p['status'], 'url' => Posts::permalink($p)], $res['items'])]);
    });

    $r->get('/api/terms', static function () {
        Auth::require('edit_posts');
        $tax = (string)($_GET['taxonomy'] ?? '');
        if (!ContentModel::taxonomy($tax)) {
            admin_404();
        }
        $q = mb_strtolower((string)($_GET['q'] ?? ''));
        $items = array_values(array_filter(\Slate\Terms::all($tax), static fn($t) => $q === '' || str_contains(mb_strtolower($t['name']), $q)));
        json_response(['ok' => true, 'items' => array_map(static fn($t) => ['id' => $t['id'], 'name' => $t['name'], 'url' => \Slate\Terms::link($t)],
            array_slice($items, 0, 30))]);
    });

    $r->post('/api/slug', static function () {
        Auth::require('edit_posts');
        $in = json_input();
        json_response(['ok' => true, 'slug' => Posts::uniqueSlug((string)($in['type'] ?? 'post'), (string)($in['text'] ?? ''), (int)($in['id'] ?? 0))]);
    });

    // Command palette search (Ctrl/⌘ + K).
    $r->get('/api/search', static function () {
        Auth::require('access_admin');
        $q = trim((string)($_GET['q'] ?? ''));
        $out = [];
        if ($q !== '' && Auth::can('edit_posts')) {
            $res = Posts::query(['type' => array_keys(ContentModel::types()), 'status' => 'any', 'search' => $q, 'limit' => 8, 'count' => false, 'orderby' => 'updated_at']);
            foreach ($res['items'] as $p) {
                $out[] = ['group' => 'Content', 'label' => $p['title'] ?: '(no title)', 'hint' => (ContentModel::type($p['type'])['singular'] ?? $p['type']) . ' · ' . $p['status'],
                    'url' => admin_url("content/{$p['type']}/{$p['id']}"), 'icon' => ContentModel::type($p['type'])['icon'] ?? 'file'];
            }
        }
        $ql = mb_strtolower($q);
        foreach (Settings::tabs() as $tk => $tab) {
            if (!admin_can_settings($tk)) {
                continue;
            }
            foreach ($tab['sections'] as $sk => $sec) {
                $hay = mb_strtolower($tab['label'] . ' ' . $sec['label'] . ' ' . implode(' ', array_column($sec['fields'] ?? [], 'label')));
                if ($ql === '' || str_contains($hay, $ql)) {
                    $out[] = ['group' => 'Settings', 'label' => $tab['label'] . ' → ' . $sec['label'], 'hint' => '', 'url' => admin_url("settings/$tk/$sk"), 'icon' => $tab['icon']];
                }
            }
        }
        json_response(['ok' => true, 'items' => array_slice($out, 0, 30)]);
    });
}
