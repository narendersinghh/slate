<?php
/**
 * Admin routes & controllers.
 */
declare(strict_types=1);

use Slate\Ai;
use Slate\Auth;
use Slate\ContentModel;
use Slate\Crypto;
use Slate\Csrf;
use Slate\DB;
use Slate\Hooks;
use Slate\Media;
use Slate\Menus;
use Slate\Modules;
use Slate\Options;
use Slate\PageCache;
use Slate\Posts;
use Slate\Roles;
use Slate\Router;
use Slate\Session;
use Slate\Settings;
use Slate\Terms;
use Slate\Totp;
use Slate\Updater;

require __DIR__ . '/api.php';

function admin_dispatch(string $path): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, private');
    header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
    Session::start();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST' && !in_array(trim($path, '/'), ['login'], true)) {
        Csrf::check();
    }

    $r = new Router();

    // ---------- Auth ----------
    $r->get('/login', static function () {
        if (Auth::user()) {
            redirect(admin_url());
        }
        admin_view('login', ['step' => isset($_SESSION['2fa_pending']) ? '2fa' : 'login'], false);
    });
    $r->post('/login', static function () {
        Csrf::check();
        $redirect = (string)input('redirect', '');
        $target = $redirect !== '' && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//') ? $redirect : admin_url();
        if (isset($_SESSION['2fa_pending']) && input('code') !== null) {
            $p = $_SESSION['2fa_pending'];
            if (time() - $p['t'] > 300) {
                unset($_SESSION['2fa_pending']);
                admin_view('login', ['step' => 'login', 'error' => 'Verification timed out. Sign in again.'], false);
                return;
            }
            $res = Auth::attempt($p['login'], Crypto::decrypt($p['pw']), (string)input('code'));
            if ($res['ok']) {
                unset($_SESSION['2fa_pending']);
                redirect($target);
            }
            admin_view('login', ['step' => '2fa', 'error' => $res['error'] ?? 'Invalid code.'], false);
            return;
        }
        $login = trim((string)input('login', ''));
        $res = Auth::attempt($login, (string)input('password', ''));
        if ($res['ok']) {
            redirect($target);
        }
        if (!empty($res['needs_2fa']) && empty($res['error'])) {
            $_SESSION['2fa_pending'] = ['login' => $login, 'pw' => Crypto::encrypt((string)input('password', '')), 't' => time()];
            admin_view('login', ['step' => '2fa'], false);
            return;
        }
        admin_view('login', ['step' => 'login', 'error' => $res['error'] ?? 'Login failed.', 'login' => $login], false);
    });
    $r->get('/logout', static function () {
        if (Csrf::valid($_GET['_csrf'] ?? null)) {
            log_activity('logout');
            Auth::logout();
        }
        redirect(admin_url('login'));
    });

    // ---------- Dashboard ----------
    $r->get('/', static function () {
        Auth::require('access_admin');
        $stats = [];
        foreach (ContentModel::types() as $slug => $t) {
            if (!empty($t['show_in_menu'])) {
                $stats[$slug] = ['type' => $t, 'count' => (int)DB::val("SELECT COUNT(*) FROM {{posts}} WHERE type = :t AND status = 'publish'", ['t' => $slug])];
            }
        }
        $recent = Posts::query(['type' => array_keys(ContentModel::types()), 'status' => 'any', 'limit' => 6, 'orderby' => 'updated_at', 'count' => false])['items'];
        $drafts = Posts::query(['type' => array_keys(ContentModel::types()), 'status' => ['draft', 'pending'], 'limit' => 5, 'orderby' => 'updated_at', 'count' => false])['items'];
        $media = ['count' => (int)DB::val('SELECT COUNT(*) FROM {{media}}'), 'size' => (int)DB::val('SELECT COALESCE(SUM(size),0) FROM {{media}}'),
            'saved' => (int)DB::val('SELECT COALESCE(SUM(original_size - size),0) FROM {{media}} WHERE original_size > size'),
            'noalt' => (int)DB::val("SELECT COUNT(*) FROM {{media}} WHERE mime LIKE 'image/%' AND alt = ''")];
        $seoIssues = [];
        if (Options::get('seo_noindex_site')) {
            $seoIssues[] = ['danger', 'The whole website is set to noindex — search engines will not index it.', admin_url('settings/seo/indexing')];
        }
        $noDesc = (int)DB::val("SELECT COUNT(*) FROM {{posts}} WHERE status = 'publish' AND (seo IS NULL OR seo NOT LIKE '%\"description\":\"_%')");
        if ($noDesc) {
            $seoIssues[] = ['warn', "$noDesc published items have no custom meta description.", admin_url('content/page')];
        }
        if ($media['noalt']) {
            $seoIssues[] = ['warn', "{$media['noalt']} images are missing alt text.", admin_url('media?filter=noalt')];
        }
        if (!Options::get('gsc_verification')) {
            $seoIssues[] = ['info', 'Google Search Console is not verified yet.', admin_url('settings/integrations/google')];
        }
        $update = Auth::can('manage_updates') && Options::get('update_auto_check', true) ? (Options::get('update_info') ?: null) : null;
        $activity = DB::all('SELECT a.*, u.display_name FROM {{activity_log}} a LEFT JOIN {{users}} u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 8');
        admin_view('dashboard', compact('stats', 'recent', 'drafts', 'media', 'seoIssues', 'update', 'activity') + ['title' => 'Dashboard']);
    });

    // ---------- Content ----------
    $r->get('/content/{type}', static function (string $type) {
        Auth::require('edit_posts');
        $def = ContentModel::type($type) ?? admin_404();
        $status = (string)($_GET['status'] ?? 'all');
        $args = ['type' => $type, 'status' => $status === 'all' ? 'any' : $status, 'page' => (int)($_GET['p'] ?? 1), 'limit' => 25, 'with_terms' => true,
            'search' => (string)($_GET['q'] ?? ''), 'orderby' => (string)($_GET['orderby'] ?? ($def['hierarchical'] ? 'menu_order' : 'published_at')),
            'order' => (string)($_GET['order'] ?? ($def['hierarchical'] ? 'ASC' : 'DESC'))];
        if (!Auth::can('edit_others_posts')) {
            $args['author'] = Auth::id();
        }
        if (!empty($_GET['term'])) {
            $args['term'] = [(int)$_GET['term']];
        }
        $result = Posts::query($args);
        $authors = array_column(DB::all('SELECT id, display_name FROM {{users}}'), 'display_name', 'id');
        admin_view('content-list', ['type' => $def, 'result' => $result, 'counts' => Posts::counts($type), 'status' => $status, 'authors' => $authors,
            'title' => $def['plural']]);
    });
    $r->get('/content/{type}/new', static fn(string $type) => admin_edit_post($type, null));
    $r->get('/content/{type}/{id:\d+}', static fn(string $type, string $id) => admin_edit_post($type, (int)$id));
    $r->post('/content/{type}/save', static fn(string $type) => admin_save_post($type));
    $r->post('/content/{type}/bulk', static function (string $type) {
        Auth::require('edit_posts');
        $ids = array_map('intval', (array)input('ids', []));
        $action = (string)input('action');
        $n = 0;
        foreach ($ids as $id) {
            $p = Posts::find($id);
            if (!$p || $p['type'] !== $type || !admin_can_edit($p)) {
                continue;
            }
            match ($action) {
                'trash' => Posts::delete($id),
                'delete' => $p['status'] === 'trash' && Auth::can('delete_posts') ? Posts::delete($id, true) : null,
                'restore' => DB::update('posts', ['status' => 'draft'], ['id' => $id]),
                'publish' => Auth::can('publish_posts') ? Posts::save(['status' => 'publish'], $id) : null,
                'draft' => Posts::save(['status' => 'draft'], $id),
                default => null,
            };
            $n++;
        }
        PageCache::clear();
        Session::flash('success', "$n item(s) updated.");
        back();
    });
    $r->post('/content/{type}/{id:\d+}/duplicate', static function (string $type, string $id) {
        Auth::require('edit_posts');
        $p = Posts::find((int)$id) ?? admin_404();
        $terms = [];
        foreach (Terms::forPosts([$p['id']])[$p['id']] ?? [] as $tax => $list) {
            $terms[$tax] = array_column($list, 'id');
        }
        $new = Posts::save(['type' => $p['type'], 'title' => $p['title'] . ' (copy)', 'slug' => $p['slug'] . '-copy', 'content' => $p['content'],
            'excerpt' => $p['excerpt'], 'status' => 'draft', 'parent_id' => $p['parent_id'], 'featured_media_id' => $p['featured_media_id'],
            'template' => $p['template'], 'fields' => $p['fields'], 'seo' => $p['seo'], 'terms' => $terms, 'published_at' => null]);
        Session::flash('success', 'Duplicated as draft.');
        redirect(admin_url("content/$type/$new"));
    });

    // ---------- Terms ----------
    $r->get('/terms/{tax}', static function (string $tax) {
        Auth::require('manage_terms');
        $def = ContentModel::taxonomy($tax) ?? admin_404();
        admin_view('terms', ['tax' => $def, 'terms' => $def['hierarchical'] ? Terms::tree($tax) : Terms::all($tax), 'term' => null,
            'fields' => ContentModel::fieldsFor('term', $tax), 'title' => $def['plural']]);
    });
    $r->get('/terms/{tax}/{id:\d+}', static function (string $tax, string $id) {
        Auth::require('manage_terms');
        $def = ContentModel::taxonomy($tax) ?? admin_404();
        $term = Terms::find((int)$id) ?? admin_404();
        admin_view('terms', ['tax' => $def, 'terms' => $def['hierarchical'] ? Terms::tree($tax) : Terms::all($tax), 'term' => $term,
            'fields' => ContentModel::fieldsFor('term', $tax), 'title' => 'Edit ' . $def['singular']]);
    });
    $r->post('/terms/{tax}/save', static function (string $tax) {
        Auth::require('manage_terms');
        ContentModel::taxonomy($tax) ?? admin_404();
        $name = trim((string)input('name', ''));
        if ($name === '') {
            Session::flash('error', 'Name is required.');
            back();
        }
        $id = (int)input('id', 0) ?: null;
        $fields = ContentModel::sanitizeFields(ContentModel::fieldsFor('term', $tax), (array)input('fields', []), Auth::can('unfiltered_html'));
        $seo = admin_clean_seo((array)input('seo', []));
        $tid = Terms::save($tax, ['name' => $name, 'slug' => (string)input('slug', ''), 'description' => (string)input('description', ''),
            'parent_id' => (int)input('parent_id', 0), 'fields' => $fields, 'seo' => $seo], $id);
        Session::flash('success', $id ? 'Updated.' : 'Added.');
        redirect(admin_url("terms/$tax" . ($id ? "/$tid" : '')));
    });
    $r->post('/terms/{tax}/{id:\d+}/delete', static function (string $tax, string $id) {
        Auth::require('manage_terms');
        Terms::delete((int)$id);
        Session::flash('success', 'Deleted.');
        redirect(admin_url("terms/$tax"));
    });

    // ---------- Media ----------
    $r->get('/media', static function () {
        Auth::require('upload_media');
        admin_view('media', ['title' => 'Media library']);
    });

    // ---------- Users ----------
    $r->get('/users', static function () {
        Auth::require('manage_users');
        $users = DB::all('SELECT u.*, (SELECT COUNT(*) FROM {{posts}} p WHERE p.author_id = u.id) posts FROM {{users}} u ORDER BY u.id');
        admin_view('users', ['users' => $users, 'title' => 'Users']);
    });
    $r->get('/users/new', static function () {
        Auth::require('manage_users');
        admin_view('user-edit', ['user' => null, 'title' => 'Add user']);
    });
    $r->get('/users/{id:\d+}', static function (string $id) {
        Auth::require('manage_users');
        $user = DB::row('SELECT * FROM {{users}} WHERE id = :id', ['id' => (int)$id]) ?? admin_404();
        admin_view('user-edit', ['user' => $user, 'title' => 'Edit user']);
    });
    $r->post('/users/save', static fn() => admin_save_user(false));
    $r->post('/users/{id:\d+}/delete', static function (string $id) {
        Auth::require('manage_users');
        $id = (int)$id;
        if ($id === Auth::id()) {
            Session::flash('error', 'You cannot delete your own account.');
            back();
        }
        $admins = (int)DB::val("SELECT COUNT(*) FROM {{users}} WHERE role = 'administrator' AND status = 'active' AND id <> :id", ['id' => $id]);
        if (!$admins) {
            Session::flash('error', 'At least one administrator must remain.');
            back();
        }
        DB::query('UPDATE {{posts}} SET author_id = :me WHERE author_id = :id', ['me' => Auth::id(), 'id' => $id]);
        DB::delete('users', ['id' => $id]);
        log_activity('user_deleted', (string)$id);
        Session::flash('success', 'User deleted. Their content was reassigned to you.');
        redirect(admin_url('users'));
    });
    $r->get('/profile', static function () {
        Auth::require('access_admin');
        $user = DB::row('SELECT * FROM {{users}} WHERE id = :id', ['id' => Auth::id()]);
        if (empty($user['totp_secret']) && empty($_SESSION['totp_setup'])) {
            $_SESSION['totp_setup'] = Totp::secret();
        }
        admin_view('user-edit', ['user' => $user, 'profile' => true, 'title' => 'Your profile']);
    });
    $r->post('/profile', static fn() => admin_save_user(true));
    $r->post('/profile/2fa', static function () {
        Auth::require('access_admin');
        if (input('disable')) {
            $user = DB::row('SELECT password FROM {{users}} WHERE id = :id', ['id' => Auth::id()]);
            if (!password_verify((string)input('password', ''), $user['password'])) {
                Session::flash('error', 'Password is incorrect.');
                back();
            }
            DB::update('users', ['totp_secret' => null], ['id' => Auth::id()]);
            log_activity('2fa_disabled');
            Session::flash('success', 'Two-factor authentication disabled.');
        } else {
            $secret = (string)($_SESSION['totp_setup'] ?? '');
            if (!$secret || !Totp::verify($secret, (string)input('code', ''))) {
                Session::flash('error', 'That code is not valid. Check the time on your phone and try again.');
                back();
            }
            DB::update('users', ['totp_secret' => Crypto::encrypt($secret)], ['id' => Auth::id()]);
            unset($_SESSION['totp_setup']);
            log_activity('2fa_enabled');
            Session::flash('success', 'Two-factor authentication is now active.');
        }
        // Re-login so the session fingerprint matches the new secret.
        Auth::loginUser(DB::row('SELECT * FROM {{users}} WHERE id = :id', ['id' => Auth::id()]));
        redirect(admin_url('profile'));
    });

    // ---------- Content model ----------
    $r->get('/model', static fn() => redirect(admin_url('model/types')));
    $r->get('/model/types', static function () {
        Auth::require('manage_content_model');
        $counts = array_column(DB::all('SELECT type, COUNT(*) c FROM {{posts}} GROUP BY type'), 'c', 'type');
        admin_view('model-types', ['types' => ContentModel::types(), 'counts' => $counts, 'title' => 'Post types']);
    });
    $r->get('/model/types/new', static function () {
        Auth::require('manage_content_model');
        admin_view('model-type-edit', ['def' => null, 'title' => 'New post type']);
    });
    $r->get('/model/types/{slug}', static function (string $slug) {
        Auth::require('manage_content_model');
        admin_view('model-type-edit', ['def' => ContentModel::type($slug) ?? admin_404(), 'title' => 'Edit post type']);
    });
    $r->post('/model/types/save', static function () {
        Auth::require('manage_content_model');
        $original = (string)input('original', '') ?: null;
        $builtin = $original && (ContentModel::builtinTypes()[$original] ?? false);
        $slug = $builtin ? $original : strtolower(trim((string)input('slug', '')));
        if (!$builtin && ($err = ContentModel::validSlug($slug, 'type', $original))) {
            Session::flash('error', $err);
            back();
        }
        $rewrite = trim(strtolower((string)input('rewrite', $slug)), '/');
        $rewrite = $rewrite === '' ? '' : implode('/', array_map('slugify', explode('/', $rewrite)));
        if ($slug !== 'page' && $rewrite === '') {
            $rewrite = $slug;
        }
        $def = [
            'slug' => $slug,
            'singular' => trim(strip_tags((string)input('singular', ''))) ?: ucfirst($slug),
            'plural' => trim(strip_tags((string)input('plural', ''))) ?: ucfirst($slug) . 's',
            'icon' => in_array(input('icon'), \Slate\Icons::names(), true) ? input('icon') : 'file',
            'public' => (bool)input('public'),
            'has_archive' => (bool)input('has_archive'),
            'hierarchical' => (bool)input('hierarchical'),
            'show_in_menu' => (bool)input('show_in_menu', true),
            'rewrite' => $rewrite,
            'menu_position' => (int)input('menu_position', 30),
            'supports' => array_values(array_intersect((array)input('supports', []), array_keys(ContentModel::SUPPORTS))),
            'description' => trim(strip_tags((string)input('description', ''))),
        ];
        ContentModel::saveType($def, $original);
        PageCache::clear();
        log_activity('post_type_saved', $slug);
        Session::flash('success', "Post type “{$def['plural']}” saved.");
        redirect(admin_url('model/types'));
    });
    $r->post('/model/types/{slug}/delete', static function (string $slug) {
        Auth::require('manage_content_model');
        if (isset(ContentModel::builtinTypes()[$slug])) {
            Session::flash('error', 'Built-in post types cannot be deleted.');
            back();
        }
        if (input('delete_content')) {
            foreach (DB::col('SELECT id FROM {{posts}} WHERE type = :t', ['t' => $slug]) as $pid) {
                Posts::delete((int)$pid, true);
            }
        }
        ContentModel::deleteType($slug);
        log_activity('post_type_deleted', $slug);
        Session::flash('success', 'Post type deleted.');
        redirect(admin_url('model/types'));
    });
    $r->get('/model/taxonomies', static function () {
        Auth::require('manage_content_model');
        admin_view('model-taxonomies', ['taxonomies' => ContentModel::taxonomies(), 'title' => 'Taxonomies']);
    });
    $r->get('/model/taxonomies/new', static function () {
        Auth::require('manage_content_model');
        admin_view('model-tax-edit', ['def' => null, 'title' => 'New taxonomy']);
    });
    $r->get('/model/taxonomies/{slug}', static function (string $slug) {
        Auth::require('manage_content_model');
        admin_view('model-tax-edit', ['def' => ContentModel::taxonomy($slug) ?? admin_404(), 'title' => 'Edit taxonomy']);
    });
    $r->post('/model/taxonomies/save', static function () {
        Auth::require('manage_content_model');
        $original = (string)input('original', '') ?: null;
        $slug = strtolower(trim((string)input('slug', '')));
        if ($original && isset(ContentModel::builtinTaxonomies()[$original])) {
            $slug = $original;
        } elseif ($err = ContentModel::validSlug($slug, 'tax', $original)) {
            Session::flash('error', $err);
            back();
        }
        $def = [
            'slug' => $slug,
            'singular' => trim(strip_tags((string)input('singular', ''))) ?: ucfirst($slug),
            'plural' => trim(strip_tags((string)input('plural', ''))) ?: ucfirst($slug) . 's',
            'post_types' => array_values(array_intersect((array)input('post_types', []), array_keys(ContentModel::types()))),
            'hierarchical' => (bool)input('hierarchical'),
            'public' => (bool)input('public'),
            'rewrite' => slugify((string)input('rewrite', '') ?: $slug),
        ];
        ContentModel::saveTaxonomy($def, $original);
        PageCache::clear();
        Session::flash('success', "Taxonomy “{$def['plural']}” saved.");
        redirect(admin_url('model/taxonomies'));
    });
    $r->post('/model/taxonomies/{slug}/delete', static function (string $slug) {
        Auth::require('manage_content_model');
        if (isset(ContentModel::builtinTaxonomies()[$slug])) {
            Session::flash('error', 'Built-in taxonomies cannot be deleted.');
            back();
        }
        foreach (DB::col('SELECT id FROM {{terms}} WHERE taxonomy = :t', ['t' => $slug]) as $tid) {
            Terms::delete((int)$tid);
        }
        ContentModel::deleteTaxonomy($slug);
        Session::flash('success', 'Taxonomy deleted.');
        redirect(admin_url('model/taxonomies'));
    });
    $r->get('/model/fields', static function () {
        Auth::require('manage_content_model');
        admin_view('model-fields', ['groups' => ContentModel::fieldGroups(), 'title' => 'Custom fields']);
    });
    $r->get('/model/fields/new', static function () {
        Auth::require('manage_content_model');
        admin_view('model-field-edit', ['group' => null, 'title' => 'New field group']);
    });
    $r->get('/model/fields/{id}', static function (string $id) {
        Auth::require('manage_content_model');
        admin_view('model-field-edit', ['group' => ContentModel::fieldGroups()[$id] ?? admin_404(), 'title' => 'Edit field group']);
    });
    $r->post('/model/fields/save', static function () {
        Auth::require('manage_content_model');
        $data = json_decode((string)input('group_json', ''), true);
        if (!is_array($data)) {
            Session::flash('error', 'Invalid field group data.');
            back();
        }
        $id = preg_replace('/[^a-z0-9]/', '', (string)($data['id'] ?? '')) ?: 'g' . bin2hex(random_bytes(4));
        $cleanFields = static function (array $fields, int $depth) use (&$cleanFields): array {
            $out = [];
            $seen = [];
            foreach ($fields as $f) {
                $name = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($f['name'] ?? '')));
                if ($name === '' || isset($seen[$name]) || !isset(ContentModel::FIELD_TYPES[$f['type'] ?? ''])) {
                    continue;
                }
                $seen[$name] = true;
                $field = [
                    'name' => $name,
                    'label' => trim(strip_tags((string)($f['label'] ?? $name))),
                    'type' => $f['type'],
                    'required' => !empty($f['required']),
                    'help' => trim(strip_tags((string)($f['help'] ?? ''))),
                    'placeholder' => trim(strip_tags((string)($f['placeholder'] ?? ''))),
                    'default' => is_scalar($f['default'] ?? '') ? (string)($f['default'] ?? '') : '',
                    'width' => in_array($f['width'] ?? '100', ['25', '33', '50', '66', '75', '100'], true) ? $f['width'] : '100',
                    'options' => array_values(array_filter(array_map('trim', is_array($f['options'] ?? null) ? $f['options'] : explode("\n", (string)($f['options'] ?? ''))))),
                    'post_type' => preg_replace('/[^a-z0-9_-]/', '', (string)($f['post_type'] ?? '')),
                ];
                if ($f['type'] === 'repeater' && $depth < 1) {
                    $field['sub_fields'] = $cleanFields((array)($f['sub_fields'] ?? []), $depth + 1);
                }
                $out[] = $field;
            }
            return $out;
        };
        $group = [
            'id' => $id,
            'title' => trim(strip_tags((string)($data['title'] ?? 'Fields'))) ?: 'Fields',
            'position' => in_array($data['position'] ?? 'main', ['main', 'side'], true) ? $data['position'] : 'main',
            'location' => [
                'post_types' => array_values(array_intersect((array)($data['location']['post_types'] ?? []), array_keys(ContentModel::types()))),
                'taxonomies' => array_values(array_intersect((array)($data['location']['taxonomies'] ?? []), array_keys(ContentModel::taxonomies()))),
            ],
            'fields' => $cleanFields((array)($data['fields'] ?? []), 0),
        ];
        ContentModel::saveFieldGroup($group);
        Session::flash('success', 'Field group saved.');
        redirect(admin_url('model/fields/' . $id));
    });
    $r->post('/model/fields/{id}/delete', static function (string $id) {
        Auth::require('manage_content_model');
        ContentModel::deleteFieldGroup($id);
        Session::flash('success', 'Field group deleted. Existing values stay stored on the content.');
        redirect(admin_url('model/fields'));
    });

    // ---------- Modules ----------
    $r->get('/modules', static function () {
        Auth::require('manage_modules');
        admin_view('modules', ['modules' => Modules::all(), 'title' => 'Modules']);
    });
    $r->post('/modules/{slug}/toggle', static function (string $slug) {
        Auth::require('manage_modules');
        if (Modules::isActive($slug)) {
            Modules::deactivate($slug);
            Session::flash('success', 'Module deactivated.');
        } elseif ($err = Modules::activate($slug)) {
            Session::flash('error', $err);
        } else {
            Session::flash('success', 'Module activated.');
        }
        log_activity('module_toggled', $slug);
        redirect(admin_url('modules'));
    });
    $r->any('/m/{slug}', static function (string $slug) {
        $page = Modules::adminPages()[$slug] ?? admin_404();
        Auth::require($page['cap']);
        ob_start();
        ($page['render'])();
        admin_view('module-page', ['html' => ob_get_clean(), 'title' => $page['label']]);
    });

    // ---------- Settings ----------
    $r->get('/settings', static function () {
        Auth::require('access_admin');
        foreach (Settings::tabs() as $tab => $t) {
            if (admin_can_settings($tab)) {
                redirect(admin_url("settings/$tab/" . array_key_first($t['sections'])));
            }
        }
        admin_404();
    });
    $r->get('/settings/{tab}', static function (string $tab) {
        $t = Settings::tabs()[$tab] ?? admin_404();
        redirect(admin_url("settings/$tab/" . array_key_first($t['sections'])));
    });
    $r->get('/settings/{tab}/{section}', static function (string $tab, string $section) {
        admin_require_settings($tab);
        $def = Settings::section($tab, $section) ?? admin_404();
        admin_view('settings', ['tab' => $tab, 'section' => $section, 'def' => $def, 'title' => 'Settings']);
    });
    $r->post('/settings/{tab}/{section}', static function (string $tab, string $section) {
        admin_require_settings($tab);
        $def = Settings::section($tab, $section) ?? admin_404();
        $oldSlug = admin_slug();
        $errors = [];
        if (!empty($def['fields'])) {
            $errors = Settings::save($tab, $section, $_POST['s'] ?? []);
        }
        match ($def['render'] ?? '') {
            'seo-types' => admin_save_seo_types(),
            'redirects' => admin_save_redirects(),
            'menus' => admin_save_menus(),
            default => null,
        };
        log_activity('settings_saved', "$tab/$section");
        if ($errors) {
            Session::flash('error', implode(' ', $errors));
        } else {
            Session::flash('success', 'Settings saved.');
        }
        $newSlug = (string)Options::get('security_admin_slug', 'admin');
        if ($newSlug !== $oldSlug) {
            Session::flash('warn', 'Your admin URL is now ' . url($newSlug) . ' — bookmark it.');
        }
        redirect(admin_url("settings/$tab/$section"));
    });
    $r->post('/system/cache/clear', static function () {
        Auth::require('manage_settings');
        $n = PageCache::clear();
        is_ajax() ? json_response(['ok' => true, 'message' => "Cleared $n cached pages."]) : null;
        Session::flash('success', "Cleared $n cached pages.");
        back();
    });
    $r->post('/system/updates/check', static function () {
        Auth::require('manage_updates');
        $info = Updater::check(true);
        Session::flash($info['ok'] ? 'success' : 'error', $info['ok']
            ? (!empty($info['available']) ? "Slate {$info['latest']} is available." : 'You are running the latest version.')
            : $info['error']);
        redirect(admin_url('settings/system/updates'));
    });
    $r->post('/system/updates/install', static function () {
        Auth::require('manage_updates');
        $res = Updater::install();
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok'] ? "Updated to Slate {$res['version']}. Backup saved as {$res['backup']}." : $res['error']);
        redirect(admin_url('settings/system/updates'));
    });

    // ---------- JSON API ----------
    admin_api_routes($r);

    Hooks::action('admin_routes', $r);

    if (!$r->dispatch($method, $path)) {
        admin_404();
    }
}

function admin_404(): never
{
    http_response_code(404);
    if (is_ajax()) {
        json_response(['ok' => false, 'error' => 'Not found'], 404);
    }
    if (!Auth::user()) {
        redirect(admin_url('login'));
    }
    admin_view('error', ['title' => 'Not found', 'message' => 'That page does not exist.']);
    exit;
}

function admin_can_edit(array $post): bool
{
    return Auth::can('edit_others_posts') || ((int)$post['author_id'] === Auth::id() && Auth::can('edit_posts'));
}

/** Settings tabs are grouped by capability. */
function admin_settings_cap(string $tab): string
{
    return match ($tab) {
        'appearance' => 'manage_menus',
        'system' => 'manage_updates',
        default => 'manage_settings',
    };
}

function admin_can_settings(string $tab): bool
{
    return Auth::can(admin_settings_cap($tab)) || Auth::can('manage_settings');
}

function admin_require_settings(string $tab): void
{
    Auth::require('access_admin');
    if (!admin_can_settings($tab)) {
        Auth::require(admin_settings_cap($tab));
    }
}

function admin_edit_post(string $type, ?int $id): void
{
    Auth::require('edit_posts');
    $def = ContentModel::type($type) ?? admin_404();
    $post = $id ? (Posts::find($id) ?? admin_404()) : null;
    if ($post && ($post['type'] !== $type || !admin_can_edit($post))) {
        Auth::require('edit_others_posts');
    }
    $postTerms = $post ? (Terms::forPosts([$post['id']])[$post['id']] ?? []) : [];
    $parents = $def['hierarchical'] ? DB::all('SELECT id, title, parent_id FROM {{posts}} WHERE type = :t AND status <> \'trash\' AND id <> :id ORDER BY title',
        ['t' => $type, 'id' => (int)$id]) : [];
    $groups = ContentModel::groupsFor('post', $type);
    $mediaIds = [$post['featured_media_id'] ?? 0, $post['seo']['og_image'] ?? 0];
    foreach ($groups as $g) {
        foreach ($g['fields'] as $f) {
            if (in_array($f['type'], ['image', 'file'], true)) {
                $mediaIds[] = (int)($post['fields'][$f['name']] ?? 0);
            } elseif ($f['type'] === 'gallery') {
                $mediaIds = array_merge($mediaIds, (array)($post['fields'][$f['name']] ?? []));
            }
        }
    }
    Media::prime($mediaIds);
    $authors = Auth::can('edit_others_posts') ? DB::all("SELECT id, display_name FROM {{users}} WHERE role <> 'subscriber' ORDER BY display_name") : [];
    admin_view('content-edit', ['type' => $def, 'post' => $post, 'postTerms' => $postTerms, 'parents' => $parents, 'groups' => $groups, 'authors' => $authors,
        'templates' => \Slate\Theme::meta()['templates'] ?? [], 'title' => ($post ? 'Edit ' : 'New ') . $def['singular'], 'bodyClass' => 'is-editor']);
}

function admin_clean_seo(array $in): array
{
    $schema = trim((string)($in['schema_custom'] ?? ''));
    if ($schema !== '' && json_decode($schema) === null) {
        $schema = '';
    }
    return [
        'title' => trim(strip_tags((string)($in['title'] ?? ''))),
        'description' => trim(strip_tags((string)($in['description'] ?? ''))),
        'focus_keyword' => trim(strip_tags((string)($in['focus_keyword'] ?? ''))),
        'canonical' => ContentModel::cleanUrl((string)($in['canonical'] ?? '')),
        'robots_index' => in_array($in['robots_index'] ?? 'default', ['default', 'index', 'noindex'], true) ? ($in['robots_index'] ?? 'default') : 'default',
        'nofollow' => !empty($in['nofollow']),
        'og_title' => trim(strip_tags((string)($in['og_title'] ?? ''))),
        'og_description' => trim(strip_tags((string)($in['og_description'] ?? ''))),
        'og_image' => (int)($in['og_image'] ?? 0) ?: null,
        'schema_type' => array_key_exists($in['schema_type'] ?? '', \Slate\Seo::SCHEMA_TYPES) ? $in['schema_type'] : '',
        'schema_custom' => Auth::can('unfiltered_html') ? $schema : '',
    ];
}

function admin_save_post(string $type): void
{
    Auth::require('edit_posts');
    $def = ContentModel::type($type) ?? admin_404();
    $id = (int)input('id', 0) ?: null;
    $existing = $id ? Posts::find($id) : null;
    if ($existing && !admin_can_edit($existing)) {
        Auth::require('edit_others_posts');
    }
    $unfiltered = Auth::can('unfiltered_html');
    $status = (string)input('status', 'draft');
    if (in_array($status, ['publish', 'scheduled', 'private'], true) && !Auth::can('publish_posts')) {
        $status = 'pending';
    }
    $content = (string)input('content', '');
    $data = [
        'type' => $type,
        'title' => (string)input('title', ''),
        'slug' => (string)input('slug', ''),
        'content' => $unfiltered ? $content : \Slate\Sanitizer::html($content),
        'excerpt' => trim(strip_tags((string)input('excerpt', ''))),
        'status' => $status,
        'parent_id' => (int)input('parent_id', 0) ?: null,
        'menu_order' => (int)input('menu_order', 0),
        'featured_media_id' => (int)input('featured_media_id', 0) ?: null,
        'template' => preg_replace('/[^a-z0-9_-]/', '', (string)input('template', '')) ?: null,
        'fields' => ContentModel::sanitizeFields(ContentModel::fieldsFor('post', $type), (array)input('fields', []), $unfiltered),
        'seo' => admin_clean_seo((array)input('seo', [])),
        'terms' => [],
    ];
    if ($date = trim((string)input('published_at', ''))) {
        try {
            $tz = new DateTimeZone((string)Options::get('timezone', 'UTC'));
            $data['published_at'] = (new DateTime($date, $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable) {
        }
    }
    if (Auth::can('edit_others_posts') && ($a = (int)input('author_id', 0))) {
        $data['author_id'] = $a;
    }
    foreach (ContentModel::taxonomiesFor($type) as $tax => $t) {
        $vals = input('terms', [])[$tax] ?? [];
        if (is_string($vals)) {
            $vals = array_filter(array_map('trim', explode(',', $vals)));
        }
        $data['terms'][$tax] = (array)$vals;
    }
    // Required custom fields.
    $missing = [];
    if ($status === 'publish') {
        foreach (ContentModel::fieldsFor('post', $type) as $name => $f) {
            if (!empty($f['required']) && in_array($data['fields'][$name] ?? null, [null, '', []], true)) {
                $missing[] = $f['label'];
            }
        }
    }
    if ($missing) {
        $msg = 'Required: ' . implode(', ', $missing);
        is_ajax() ? json_response(['ok' => false, 'error' => $msg], 422) : Session::flash('error', $msg);
        back();
    }
    $newId = Posts::save($data, $id);
    $post = Posts::find($newId);
    log_activity($id ? 'content_updated' : 'content_created', $type . ':' . $post['title']);
    if (is_ajax()) {
        json_response(['ok' => true, 'id' => $newId, 'slug' => $post['slug'], 'status' => $post['status'],
            'permalink' => Posts::permalink($post, true), 'edit_url' => admin_url("content/$type/$newId"),
            'message' => $def['singular'] . ' ' . ($post['status'] === 'publish' ? 'published' : 'saved') . '.']);
    }
    Session::flash('success', $def['singular'] . ' saved.');
    redirect(admin_url("content/$type/$newId"));
}

function admin_save_user(bool $profile): void
{
    Auth::require($profile ? 'access_admin' : 'manage_users');
    $id = $profile ? Auth::id() : ((int)input('id', 0) ?: null);
    $existing = $id ? DB::row('SELECT * FROM {{users}} WHERE id = :id', ['id' => $id]) : null;
    $username = trim((string)input('username', $existing['username'] ?? ''));
    $email = strtolower(trim((string)input('email', '')));
    $password = (string)input('password', '');
    $errors = [];
    if (!$existing && !preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $username)) {
        $errors[] = 'Username must be 3–60 letters, numbers, dot, dash or underscore.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email.';
    }
    if (DB::val('SELECT id FROM {{users}} WHERE (email = :e OR username = :u) AND id <> :id', ['e' => $email, 'u' => $username, 'id' => (int)$id])) {
        $errors[] = 'That username or email is already used.';
    }
    if ($password !== '' || !$existing) {
        if ($p = Auth::passwordProblems($password)) {
            $errors[] = $p;
        }
    }
    if ($profile && $password !== '' && !password_verify((string)input('current_password', ''), $existing['password'])) {
        $errors[] = 'Your current password is incorrect.';
    }
    if ($errors) {
        Session::flash('error', implode(' ', $errors));
        back();
    }
    $row = [
        'email' => $email,
        'display_name' => trim(strip_tags((string)input('display_name', ''))) ?: $username,
        'meta' => json_encode(['bio' => trim(strip_tags((string)input('bio', ''))), 'website' => ContentModel::cleanUrl((string)input('website', ''))]),
    ];
    if (!$profile) {
        $role = (string)input('role', 'subscriber');
        $row['role'] = isset(Roles::all()[$role]) ? $role : 'subscriber';
        $row['status'] = input('status') === 'suspended' ? 'suspended' : 'active';
        if ($id === Auth::id()) {
            unset($row['role'], $row['status']); // Cannot demote / suspend yourself.
        }
    }
    if ($password !== '') {
        $row['password'] = Auth::hash($password);
    }
    if ($existing) {
        DB::update('users', $row, ['id' => $id]);
    } else {
        $row += ['username' => $username, 'created_at' => now()];
        $id = DB::insert('users', $row);
    }
    log_activity($existing ? 'user_updated' : 'user_created', $username);
    if ($profile && $password !== '') {
        Auth::loginUser(DB::row('SELECT * FROM {{users}} WHERE id = :id', ['id' => $id]));
    }
    Session::flash('success', $profile ? 'Profile updated.' : 'User saved.');
    redirect($profile ? admin_url('profile') : admin_url('users/' . $id));
}

function admin_save_seo_types(): void
{
    $types = [];
    foreach ((array)input('seo_types', []) as $slug => $d) {
        if (ContentModel::type((string)$slug)) {
            $types[$slug] = [
                'title_template' => trim(strip_tags((string)($d['title_template'] ?? ''))),
                'robots' => ($d['robots'] ?? 'index') === 'noindex' ? 'noindex' : 'index',
                'schema' => array_key_exists($d['schema'] ?? '', \Slate\Seo::SCHEMA_TYPES) ? $d['schema'] : 'WebPage',
                'sitemap' => !empty($d['sitemap']),
            ];
        }
    }
    Options::set('seo_types', $types);
    $taxes = [];
    foreach ((array)input('seo_taxonomies', []) as $slug => $d) {
        if (ContentModel::taxonomy((string)$slug)) {
            $taxes[$slug] = [
                'title_template' => trim(strip_tags((string)($d['title_template'] ?? ''))),
                'robots' => ($d['robots'] ?? 'index') === 'noindex' ? 'noindex' : 'index',
                'sitemap' => !empty($d['sitemap']),
            ];
        }
    }
    Options::set('seo_taxonomies', $taxes);
    PageCache::clear();
}

function admin_save_redirects(): void
{
    $rows = [];
    $in = (array)input('redirects', []);
    foreach ($in['from'] ?? [] as $i => $from) {
        $from = trim((string)$from);
        $to = trim((string)($in['to'][$i] ?? ''));
        $code = (int)($in['code'][$i] ?? 301);
        if ($from === '' || ($to === '' && $code !== 410)) {
            continue;
        }
        $rows[] = ['from' => $from, 'to' => $to, 'code' => in_array($code, [301, 302, 307, 308, 410], true) ? $code : 301,
            'regex' => !empty($in['regex'][$i])];
    }
    Options::set('redirects', $rows);
    PageCache::clear();
}

function admin_save_menus(): void
{
    $data = json_decode((string)input('menus_json', '[]'), true);
    if (is_array($data)) {
        Menus::save($data);
    }
}
