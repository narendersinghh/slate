<?php
/** SEO tools: instant indexing (Google, IndexNow) and the "Schema by type" screen. */
use Slate\Auth;
use Slate\ContentModel;
use Slate\Indexing;
use Slate\Posts;
use Slate\Router;
use Slate\Session;
use Slate\Settings;
use Slate\StructuredData;

if (!function_exists('admin_seo_save')) {
    /** Saves the custom parts of SEO › Instant indexing and SEO › Schema by type (called from admin_settings_save). */
    function admin_seo_save(string $render): void
    {
        if ($render === 'seo-indexing') {
            if ($err = Indexing::saveAdmin((array)input('seo_idx', []))) {
                Session::flash('error', $err);
            }
            return;
        }
        if ($render === 'seo-schema') {
            $in = (array)input('sd', []);
            StructuredData::saveAdmin($in);
            $type = (string)($in['type'] ?? '');
            if ($type !== '' && ContentModel::type($type)) {
                log_activity('settings_saved', 'seo/schema-types/' . $type);
                Session::flash('success', 'Schema for ' . (ContentModel::type($type)['plural'] ?? $type) . ' saved.');
                redirect(Settings::url('seo', 'schema-types') . '?type=' . rawurlencode($type));
            }
        }
    }
}

return static function (Router $r): void {
    $back = static fn() => redirect(Settings::url('seo', 'instant'));
    $settings = static function (): void {
        admin_require_settings('seo');
    };

    $r->post('/seo/tools/google-test', static function () use ($back, $settings) {
        $settings();
        [$ok, $msg] = Indexing::testGoogle();
        Session::flash($ok ? 'success' : 'error', $msg);
        $back();
    });
    $r->post('/seo/tools/google-remove', static function () use ($back, $settings) {
        $settings();
        Indexing::removeGoogleKey();
        Session::flash('success', 'The Google service account key was removed.');
        $back();
    });
    $r->post('/seo/tools/indexnow-key', static function () use ($back, $settings) {
        $settings();
        Indexing::regenerateIndexNowKey();
        log_activity('seo_indexnow_key_changed');
        Session::flash('success', 'A new IndexNow key was created.');
        $back();
    });
    $r->post('/seo/tools/sitemap', static function () use ($back, $settings) {
        $settings();
        [$ok, $msg] = Indexing::submitSitemap();
        Session::flash($ok ? 'success' : 'error', $msg);
        $back();
    });
    $r->post('/seo/tools/queue', static function () use ($back, $settings) {
        $settings();
        $what = (string)input('do');
        if ($what === 'clear') {
            Session::flash('success', number_format(Indexing::clearPending()) . ' waiting submissions removed.');
        } elseif ($what === 'retry') {
            $n = Indexing::retryFailed();
            Indexing::process(null, 50);
            Session::flash('success', number_format($n) . ' failed submissions sent again.');
        } else {
            Indexing::process(null, 50);
            Session::flash('success', 'The queue was processed.');
        }
        $back();
    });
    // Submit a list of addresses, or every published item of a type.
    $r->post('/seo/tools/submit', static function () use ($back, $settings) {
        $settings();
        $type = input('seo_submit_mode') === 'type' ? (string)input('seo_submit_type', '') : '';
        if (input('seo_submit_mode') === 'type' && $type === '') {
            Session::flash('error', 'Choose a content type first.');
            $back();
        }
        $urls = [];
        if ($type !== '') {
            $def = ContentModel::type($type);
            if (!$def || empty($def['public'])) {
                Session::flash('error', 'Choose a public content type.');
                $back();
            }
            $res = Posts::query(['type' => $type, 'status' => 'publish', 'limit' => 500, 'count' => false, 'with_terms' => false]);
            foreach ($res['items'] as $p) {
                if (Indexing::blocked($p) === null) {
                    $urls[] = Posts::permalink($p, true);
                }
            }
        } else {
            $urls = array_filter(array_map('trim', preg_split('/\R/', (string)input('seo_submit_urls', '')) ?: []));
        }
        $bad = array_filter($urls, static fn($u) => !Indexing::ownUrl($u));
        $action = input('seo_submit_action') === 'URL_DELETED' ? 'URL_DELETED' : 'URL_UPDATED';
        [$ok, $msg] = Indexing::request(array_slice(array_values(array_diff($urls, $bad)), 0, 1000), null, $action);
        if ($bad) {
            $msg .= ' ' . count($bad) . ' address(es) skipped: not on this website.';
        }
        Session::flash($ok ? 'success' : 'error', trim($msg));
        $back();
    });
    // "Request indexing" in the editor's SEO panel (JSON).
    $r->post('/seo/tools/request', static function () {
        Auth::require('publish_posts');
        $post = Posts::find((int)input('id', 0));
        if (!$post || !admin_can_edit($post)) {
            json_response(['ok' => false, 'message' => 'Item not found.'], 404);
        }
        [$ok, $msg] = Indexing::request([Posts::permalink($post, true)], $post);
        log_activity('seo_index_requested', '#' . $post['id']);
        json_response(['ok' => $ok, 'message' => $msg]);
    });
};
