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
use Slate\ModuleBuilder;
use Slate\ModuleInstaller;
use Slate\ModuleScanner;
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

    // Module files changed since last time (git pull, FTP, update)? Run their new migrations.
    if ($method === 'GET' && Auth::user() && Auth::can('manage_modules')) {
        Modules::maintain();
    }

    // "Require two-factor auth for administrators": until it is set up, only the profile page is reachable.
    if (Auth::user() && Auth::user()['role'] === 'administrator' && Options::get('security_require_2fa')
        && !in_array(trim($path, '/'), ['profile', 'profile/2fa', 'profile/passkeys', 'profile/passkeys/options', 'logout'], true)
        && !DB::val('SELECT totp_secret FROM {{users}} WHERE id = :id', ['id' => Auth::id()])
        && !\Slate\Passkeys::count(Auth::id())) {
        Session::flash('error', 'Two-factor authentication is required for administrators. Set up an authenticator app or a passkey to continue.');
        redirect(admin_url('profile') . '#2fa');
    }

    $r = new Router();

    // ---------- Auth ----------
    // One-time sign-in when the dashboard moves to another domain (cookies cannot follow).
    $r->get('/handoff/{token:[a-f0-9]+}', static function (string $token) {
        $res = \Slate\AdminAccess::redeemHandoff($token);
        if (!$res) {
            redirect(admin_url('login'));
        }
        Auth::loginUser($res[0]);
        Session::flash('success', 'You are signed in at ' . \Slate\AdminAccess::currentHost() . '.');
        redirect(admin_url($res[1]));
    });
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
    // ---------- Passkeys ----------
    $r->get('/passkey/options', static fn() => json_response(\Slate\Passkeys::loginOptions()));
    $r->post('/passkey/login', static function () {
        $res = \Slate\Passkeys::login(json_input());
        json_response($res + ['redirect' => admin_url()], $res['ok'] ? 200 : 401);
    });
    $r->get('/profile/passkeys/options', static function () {
        Auth::require('access_admin');
        $u = DB::row('SELECT * FROM {{users}} WHERE id = :id', ['id' => Auth::id()]);
        json_response(\Slate\Passkeys::registrationOptions($u));
    });
    $r->post('/profile/passkeys', static function () {
        Auth::require('access_admin');
        $in = json_input();
        $u = DB::row('SELECT * FROM {{users}} WHERE id = :id', ['id' => Auth::id()]);
        $err = \Slate\Passkeys::register($u, $in, (string)($in['name'] ?? ''));
        if (!$err) {
            Session::flash('success', 'Passkey added. You can now sign in with it.');
        }
        json_response($err ? ['ok' => false, 'error' => $err] : ['ok' => true], $err ? 422 : 200);
    });
    $r->post('/profile/passkeys/{id:\d+}/remove', static function (string $id) {
        Auth::require('access_admin');
        \Slate\Passkeys::remove((int)$id, Auth::id());
        Session::flash('success', 'Passkey removed.');
        redirect(admin_url('profile') . '#passkeys');
    });
    $r->get('/forgot', static fn() => admin_view('password', ['mode' => 'forgot'], false));
    $r->post('/forgot', static function () {
        Auth::requestReset((string)input('login', ''));
        admin_view('password', ['mode' => 'sent'], false);
    });
    $r->get('/reset', static function () {
        $uid = (int)($_GET['u'] ?? 0);
        $token = (string)($_GET['t'] ?? '');
        header('Referrer-Policy: no-referrer');
        admin_view('password', Auth::resetUser($uid, $token) ? ['mode' => 'reset', 'uid' => $uid, 'token' => $token] : ['mode' => 'invalid'], false);
    });
    $r->post('/reset', static function () {
        $uid = (int)input('u', 0);
        $token = (string)input('t', '');
        $pw = (string)input('password', '');
        $err = $pw !== (string)input('password2', '') ? 'The two passwords do not match.' : Auth::completeReset($uid, $token, $pw);
        if ($err === null) {
            redirect(admin_url('login') . '?reset=1');
        }
        admin_view('password', Auth::resetUser($uid, $token) ? ['mode' => 'reset', 'uid' => $uid, 'token' => $token, 'error' => $err] : ['mode' => 'invalid'], false);
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
            $seoIssues[] = ['danger', 'The whole website is set to noindex, search engines will not index it.', Settings::url('seo', 'indexing')];
        }
        $noDesc = (int)DB::val("SELECT COUNT(*) FROM {{posts}} WHERE status = 'publish' AND (seo IS NULL OR seo NOT LIKE '%\"description\":\"_%')");
        if ($noDesc) {
            $seoIssues[] = ['warn', "$noDesc published items have no custom meta description.", admin_url('content/page')];
        }
        if ($media['noalt']) {
            $seoIssues[] = ['warn', "{$media['noalt']} images are missing alt text.", admin_url('media?filter=noalt')];
        }
        if (!Options::get('gsc_verification')) {
            $seoIssues[] = ['info', 'Google Search Console is not verified yet.', admin_url('settings/integrations/verification')];
        }
        $update = Auth::can('manage_updates') && Options::get('update_auto_check', true) ? (Options::get('update_info') ?: null) : null;
        $activity = DB::all('SELECT a.*, u.display_name FROM {{activity_log}} a LEFT JOIN {{users}} u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 8');
        admin_view('dashboard', compact('stats', 'recent', 'drafts', 'media', 'seoIssues', 'update', 'activity') + ['title' => 'Dashboard']);
    });

    // ---------- Content ----------
    $r->get('/content/{type}', static function (string $type) {
        Auth::require('edit_posts');
        $def = ContentModel::type($type) ?? admin_404();
        $table = admin_content_table($def);
        $args = admin_content_list_args($def, $table->state());
        if ($table->exporting()) {
            $table->exportCsv((static function () use ($args) {
                for ($page = 1; $page <= 200; $page++) {
                    $res = Posts::query(['page' => $page, 'limit' => 500, 'count' => false] + $args);
                    yield from $res['items'];
                    if (count($res['items']) < 500) {
                        return;
                    }
                }
            })(), $type . '-' . gmdate('Y-m-d') . '.csv');
        }
        $result = Posts::query($args);
        admin_view('content-list', ['type' => $def, 'table' => $table, 'result' => $result, 'title' => $def['plural']]);
    });
    $r->get('/content/{type}/new', static fn(string $type) => admin_edit_post($type, null));
    $r->get('/content/{type}/{id:\d+}', static fn(string $type, string $id) => admin_edit_post($type, (int)$id));
    $r->post('/content/{type}/save', static fn(string $type) => admin_save_post($type));
    $r->post('/content/{type}/bulk', static function (string $type) {
        Auth::require('edit_posts');
        $def = ContentModel::type($type) ?? admin_404();
        if (input('all_matching')) {
            // Every row that matches the current search/filters, not just this page (up to 5,000).
            parse_str((string)input('query'), $get);
            $saved = $_GET;
            $_GET = $get;
            $args = admin_content_list_args($def, admin_content_table($def)->state());
            $_GET = $saved;
            $ids = [];
            for ($page = 1; $page <= 10 && count($ids) < 5000; $page++) {
                $res = Posts::query(['page' => $page, 'limit' => 500, 'count' => false, 'with_terms' => false] + $args);
                $ids = array_merge($ids, array_column($res['items'], 'id'));
                if (count($res['items']) < 500) {
                    break;
                }
            }
        } else {
            $ids = array_map('intval', (array)input('ids', []));
        }
        $action = (string)input('action');
        $value = (string)(input('bulk_value')[$action] ?? '');
        if (in_array($action, ['term_add', 'term_remove', 'author', 'seo_index'], true) && $value === '') {
            Session::flash('error', 'Choose a value from the list first.');
            back();
        }
        $n = 0;
        $indexUrls = [];
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
                'author' => Auth::can('edit_others_posts') ? DB::update('posts', ['author_id' => (int)$value, 'updated_at' => now()], ['id' => $id]) : null,
                'term_add', 'term_remove' => admin_bulk_term($id, $action, (int)$value),
                'seo_index' => in_array($value, ['index', 'noindex'], true) ? Posts::save(['seo' => ['robots_index' => $value === 'noindex' ? 'noindex' : 'default']], $id) : null,
                'seo_request' => Auth::can('publish_posts') && \Slate\Indexing::blocked($p) === null ? $indexUrls[] = Posts::permalink($p) : null,
                default => null,
            };
            $n++;
        }
        if ($action === 'seo_request') {
            // One request for all selected items that may be indexed (published, public, not noindex).
            [$ok, $msg] = $indexUrls ? \Slate\Indexing::request($indexUrls) : [false, 'None of the selected items can be indexed (drafts, hidden from search engines, or not public).'];
            Session::flash($ok ? 'success' : 'error', count($indexUrls) . ' of ' . $n . ' sent. ' . $msg);
            back();
        }
        PageCache::clear();
        Session::flash('success', number_format($n) . ' item' . ($n === 1 ? '' : 's') . ' updated.');
        back();
    });
    // Quick edit from the list (title, slug, status, publish date).
    $r->post('/content/{type}/{id:\d+}/quick', static function (string $type, string $id) {
        Auth::require('edit_posts');
        $p = Posts::find((int)$id) ?? admin_404();
        if ($p['type'] !== $type || !admin_can_edit($p)) {
            admin_404();
        }
        $status = (string)input('status', $p['status']);
        if ($status === 'publish' && $p['status'] !== 'publish' && !Auth::can('publish_posts')) {
            $status = 'pending';
        }
        $data = ['title' => trim((string)input('title', $p['title'])), 'slug' => trim((string)input('slug', $p['slug'])), 'status' => $status];
        $date = trim((string)input('published_at', ''));
        if ($date !== '' && ($ts = strtotime($date)) !== false) {
            $data['published_at'] = gmdate('Y-m-d H:i:s', $ts - (new \DateTimeZone((string)Options::get('timezone', 'UTC')))->getOffset(new \DateTime('@' . $ts)));
        }
        Posts::save($data, (int)$id);
        Session::flash('success', 'Saved “' . $data['title'] . '”.');
        back();
    });
    // Column chooser / density / page size, remembered per user.
    $r->post('/ui/table', static function () {
        Auth::require('access_admin');
        $in = json_input() ?: $_POST;
        \Slate\ListTable::storePrefs((string)($in['id'] ?? ''), array_intersect_key((array)$in, array_flip(['cols', 'density', 'per', 'reset'])));
        json_response(['ok' => true]);
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
    $r->get('/terms/{tax}', static fn(string $tax) => admin_terms_screen($tax, null));
    $r->get('/terms/{tax}/{id:\d+}', static fn(string $tax, string $id) => admin_terms_screen($tax, (int)$id));
    $r->post('/terms/{tax}/bulk', static function (string $tax) {
        Auth::require('manage_terms');
        ContentModel::taxonomy($tax) ?? admin_404();
        $n = 0;
        if ((string)input('action') === 'delete') {
            foreach (array_map('intval', (array)input('ids', [])) as $id) {
                $t = Terms::find($id);
                if ($t && $t['taxonomy'] === $tax) {
                    Terms::delete($id);
                    $n++;
                }
            }
            PageCache::clear();
        }
        Session::flash('success', "$n deleted. Content using them was kept.");
        back();
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
        $users = DB::all("SELECT u.*, (SELECT COUNT(*) FROM {{posts}} p WHERE p.author_id = u.id AND p.status <> 'trash') posts,
            (SELECT COUNT(*) FROM {{passkeys}} k WHERE k.user_id = u.id) passkeys FROM {{users}} u ORDER BY u.id");
        $table = admin_users_table($users);
        $s = $table->state();
        $rows = array_values(array_filter($users, static function (array $u) use ($s): bool {
            if ($s['view'] === 'suspended' ? $u['status'] === 'active' : ($s['view'] !== 'all' && $s['view'] !== '' && $u['role'] !== $s['view'])) {
                return false;
            }
            if (($s['filters']['security'] ?? '') === 'none' && ($u['totp_secret'] || $u['passkeys'])) {
                return false;
            }
            if (($s['filters']['security'] ?? '') === 'protected' && !$u['totp_secret'] && !$u['passkeys']) {
                return false;
            }
            $q = mb_strtolower($s['q']);
            return $q === '' || str_contains(mb_strtolower($u['username'] . ' ' . $u['display_name'] . ' ' . $u['email']), $q);
        }));
        $key = ['name' => 'display_name', 'role' => 'role', 'posts' => 'posts', 'last_login' => 'last_login', 'created' => 'created_at', 'id' => 'id'][$s['sort']] ?? 'id';
        usort($rows, static fn($a, $b) => ($s['dir'] === 'asc' ? 1 : -1) * (is_numeric($a[$key]) && is_numeric($b[$key]) ? $a[$key] <=> $b[$key] : strnatcasecmp((string)$a[$key], (string)$b[$key])));
        if ($table->exporting()) {
            $table->exportCsv($rows, 'users-' . gmdate('Y-m-d') . '.csv');
        }
        admin_view('users', ['table' => $table, 'rows' => array_slice($rows, $s['offset'], $s['per']), 'total' => count($rows), 'title' => 'Users']);
    });
    $r->post('/users/bulk', static function () {
        Auth::require('manage_users');
        $ids = array_map('intval', (array)input('ids', []));
        $action = (string)input('action');
        $value = (string)(input('bulk_value')[$action] ?? '');
        $n = 0;
        foreach ($ids as $id) {
            if ($id === Auth::id()) {
                continue; // never change your own role or lock yourself out from a bulk action
            }
            $done = match ($action) {
                'role' => isset(Roles::all()[$value]) ? DB::update('users', ['role' => $value], ['id' => $id]) : 0,
                'suspend' => DB::update('users', ['status' => 'suspended'], ['id' => $id]),
                'activate' => DB::update('users', ['status' => 'active'], ['id' => $id]),
                default => 0,
            };
            $n += $done ? 1 : 0;
        }
        log_activity('users_bulk', "$action ($n)");
        Session::flash('success', $n . ' user' . ($n === 1 ? '' : 's') . ' updated.' . (in_array(Auth::id(), $ids, true) ? ' Your own account was left unchanged.' : ''));
        back();
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
    $r->post('/profile/api-keys', static function () {
        Auth::require('access_admin');
        $token = \Slate\Api::createKey(Auth::id(), (string)input('name', ''), (string)input('scope', 'read'));
        Session::putValue('new_api_key', $token);
        redirect(admin_url('profile') . '#api-keys');
    });
    $r->post('/profile/api-keys/{id:\d+}/revoke', static function (string $id) {
        Auth::require('access_admin');
        \Slate\Api::revokeKey((int)$id, Auth::id());
        Session::flash('success', 'API key revoked.');
        redirect(admin_url('profile') . '#api-keys');
    });

    // ---------- Email, backups, scheduled tasks ----------
    $r->post('/system/email/test', static function () {
        admin_require_settings('general');
        $to = trim((string)input('test_to', ''));
        $ok = \Slate\Mailer::send($to, 'Test email from ' . Options::get('site_title', 'Slate'),
            '<p>This is a test email from <strong>' . e((string)Options::get('site_title', 'Slate')) . '</strong>.</p><p>If you can read this, email delivery works.</p>');
        Session::flash($ok ? 'success' : 'error', $ok ? "Test email sent to $to. Check the inbox (and spam folder)." : 'Sending failed: ' . \Slate\Mailer::lastError());
        redirect(admin_url('settings/email/delivery'));
    });
    $r->post('/system/backups/create', static function () {
        Auth::require('manage_updates');
        $res = \Slate\Backup::create(!empty($_GET['files']));
        if ($res['ok']) {
            \Slate\Backup::prune();
        }
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Backup created: ' . implode(', ', array_map('basename', $res['files'])) : 'Backup failed: ' . $res['error']);
        redirect(admin_url('settings/system/backups'));
    });
    $r->get('/system/backups/download/{name}', static function (string $name) {
        Auth::require('manage_updates');
        $file = \Slate\Backup::path($name) ?? admin_404();
        log_activity('backup_downloaded', $name);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . (str_ends_with($name, '.zip') ? 'application/zip' : 'application/gzip'));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    });
    $r->post('/system/backups/delete', static function () {
        Auth::require('manage_updates');
        $name = (string)input('backup', '');
        $ok = \Slate\Backup::delete($name);
        if ($ok) {
            log_activity('backup_deleted', $name);
        }
        Session::flash($ok ? 'success' : 'error', $ok ? 'Backup deleted.' : 'Backup not found.');
        redirect(admin_url('settings/system/backups'));
    });
    $r->post('/system/integrity/check', static function () {
        Auth::require('manage_updates');
        $r = \Slate\Integrity::check(false);
        $n = count(array_filter($r['issues'], static fn($i) => $i['level'] !== 'info'));
        Session::flash($n ? 'error' : 'success', $n ? "$n file(s) need a look." : 'No unexpected file changes.');
        redirect(admin_url('settings/security/check'));
    });
    $r->post('/system/integrity/trust', static function () {
        Auth::require('manage_updates');
        $n = \Slate\Integrity::trust();
        Session::flash('success', "Trusted the current $n files. Future changes will be reported.");
        redirect(admin_url('settings/security/check'));
    });
    $r->post('/system/webhooks/add', static function () {
        admin_require_settings('integrations');
        $url = trim((string)input('wh_url', ''));
        $events = array_values(array_intersect((array)input('wh_events', []), array_merge(['*'], array_keys(\Slate\Webhooks::events()))));
        if ($err = \Slate\Webhooks::urlProblem($url)) {
            Session::flash('error', $err);
        } elseif (!$events) {
            Session::flash('error', 'Choose at least one event.');
        } else {
            [, $secret] = \Slate\Webhooks::add($url, $events);
            Session::putValue('new_webhook_secret', $secret);
            Session::flash('success', 'Webhook added. Copy its signing secret now.');
        }
        redirect(admin_url('settings/integrations/webhooks'));
    });
    $r->post('/system/webhooks/{id:[a-f0-9]+}/{action:remove|toggle|ping}', static function (string $id, string $action) {
        admin_require_settings('integrations');
        if ($action === 'remove') {
            \Slate\Webhooks::remove($id);
            Session::flash('success', 'Webhook removed.');
        } elseif ($action === 'toggle') {
            \Slate\Webhooks::toggle($id);
        } else {
            [$ok, $msg] = \Slate\Webhooks::ping($id);
            Session::flash($ok ? 'success' : 'error', 'Test: ' . $msg);
        }
        redirect(admin_url('settings/integrations/webhooks'));
    });
    $r->post('/system/webhooks/deliveries/{id:\d+}/retry', static function (string $id) {
        admin_require_settings('integrations');
        \Slate\Webhooks::retry((int)$id);
        Session::flash('success', 'Delivery retried.');
        redirect(admin_url('settings/integrations/webhooks'));
    });
    $r->post('/system/cron/run', static function () {
        admin_require_settings('system');
        $res = \Slate\Cron::run(false, 'admin');
        $failed = array_filter($res, static fn($v) => $v !== 'ok' && $v !== 'skipped');
        $ran = count(array_filter($res, static fn($v) => $v === 'ok'));
        Session::flash($failed ? 'error' : 'success', $failed ? 'Some tasks failed: ' . implode('; ', array_map(static fn($k, $v) => "$k: $v", array_keys($failed), $failed))
            : ($ran ? "$ran task(s) ran." : 'Nothing was due.'));
        redirect(admin_url('settings/system/cron'));
    });

    // ---------- Revisions ----------
    $r->get('/content/{type}/{id:\d+}/revisions', static function (string $type, string $id) {
        Auth::require('edit_posts');
        $def = ContentModel::type($type) ?? admin_404();
        $post = Posts::find((int)$id);
        if (!$post || $post['type'] !== $type) {
            admin_404();
        }
        if (!admin_can_edit($post)) {
            Auth::require('edit_others_posts');
        }
        $list = \Slate\Revisions::list((int)$id);
        $revId = (int)($_GET['rev'] ?? ($list[0]['id'] ?? 0));
        $rev = $revId ? \Slate\Revisions::find($revId) : null;
        if ($rev && (int)$rev['post_id'] !== (int)$id) {
            $rev = null;
        }
        admin_view('revisions', ['type' => $def, 'post' => $post, 'list' => $list, 'rev' => $rev, 'title' => 'Revisions: ' . ($post['title'] ?: '(no title)')]);
    });
    $r->post('/content/{type}/{id:\d+}/revisions/{rev:\d+}/restore', static function (string $type, string $id, string $rev) {
        Auth::require('edit_posts');
        $post = Posts::find((int)$id);
        if (!$post || $post['type'] !== $type) {
            admin_404();
        }
        if (!admin_can_edit($post)) {
            Auth::require('edit_others_posts');
        }
        $r = \Slate\Revisions::find((int)$rev);
        if (!$r || (int)$r['post_id'] !== (int)$id || !\Slate\Revisions::restore((int)$rev)) {
            Session::flash('error', 'Could not restore that revision.');
        } else {
            log_activity('revision_restored', "$type:{$post['title']}");
            Session::flash('success', 'Revision restored. The version you replaced was kept as a revision too.');
        }
        redirect(admin_url("content/$type/$id"));
    });
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
        $rewrite = $rewrite === '' ? '' : implode('/', array_map(static fn($seg) => preg_match('/^%[a-z0-9_-]+%$/', $seg) ? $seg : slugify($seg), explode('/', $rewrite)));
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
            'rewrite' => trim(implode('/', array_map('slugify', array_filter(explode('/', (string)input('rewrite', '')), 'strlen'))), '/'),
            'nested' => (bool)input('nested', true),
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
                if (($f['type'] ?? '') === 'tab' && $name === '') {
                    $name = 'tab_' . (slugify((string)($f['label'] ?? '')) ?: count($out));
                    $name = str_replace('-', '_', $name);
                }
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
                    'post_type' => implode(',', array_filter(array_map(static fn($t) => preg_replace('/[^a-z0-9_-]/', '', (string)$t),
                        is_array($f['post_type'] ?? null) ? $f['post_type'] : explode(',', (string)($f['post_type'] ?? ''))))),
                ];
                if ($f['type'] === 'taxonomy') {
                    $field['taxonomy'] = ContentModel::taxonomy((string)($f['taxonomy'] ?? '')) ? (string)$f['taxonomy'] : '';
                }
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

    // ---------- Templates ----------
    $r->get('/templates', static function () {
        Auth::require('manage_content_model');
        admin_view('templates', ['templates' => \Slate\Templates::all(), 'title' => 'Templates']);
    });
    $r->get('/templates/new', static function () {
        Auth::require('manage_content_model');
        $kind = (string)($_GET['kind'] ?? 'single');
        $target = preg_replace('/[^a-z0-9_-]/', '', (string)($_GET['target'] ?? 'post'));
        $tpl = ['id' => '', 'name' => \Slate\Templates::label($kind, $target), 'kind' => $kind, 'target' => $target,
            'html' => \Slate\Templates::starter($kind, $target), 'css' => '', 'wrap' => true, 'enabled' => false];
        admin_view('template-edit', ['tpl' => $tpl, 'title' => 'New template', 'bodyClass' => 'is-editor']);
    });
    $r->get('/templates/{id}', static function (string $id) {
        Auth::require('manage_content_model');
        admin_view('template-edit', ['tpl' => \Slate\Templates::find($id) ?? admin_404(), 'title' => 'Edit template', 'bodyClass' => 'is-editor']);
    });
    $r->post('/templates/save', static function () {
        Auth::require('manage_content_model');
        $id = \Slate\Templates::save([
            'id' => (string)input('id', ''), 'name' => (string)input('name', ''), 'kind' => (string)input('kind', 'single'), 'target' => (string)input('target', ''),
            'html' => (string)input('html', ''), 'css' => (string)input('css', ''), 'wrap' => (bool)input('wrap'), 'enabled' => (bool)input('enabled'),
        ]);
        log_activity('template_saved', $id);
        if (is_ajax()) {
            json_response(['ok' => true, 'id' => $id, 'edit_url' => admin_url('templates/' . $id), 'preview' => admin_template_preview_url(\Slate\Templates::find($id)), 'message' => 'Template saved.']);
        }
        Session::flash('success', 'Template saved.');
        redirect(admin_url('templates/' . $id));
    });
    // Live preview of unsaved changes: keep the draft in this user's session, return the address to show.
    $r->post('/templates/preview', static function () {
        Auth::require('manage_content_model');
        $in = ['kind' => (string)input('kind', 'single'), 'target' => (string)input('target', ''), 'html' => (string)input('html', ''),
            'css' => (string)input('css', ''), 'wrap' => (bool)input('wrap')];
        \Slate\Templates::putDraft($in);
        $url = admin_template_preview_url(['id' => 'draft'] + $in, (int)input('item', 0));
        json_response($url !== '' ? ['ok' => true, 'url' => $url . '&_=' . time()] : ['ok' => false, 'error' => 'Nothing to preview yet: create at least one item of this type first.']);
    });
    // Items to preview a template with (search by title).
    $r->get('/template-items', static function () {
        Auth::require('manage_content_model');
        $kind = (string)($_GET['kind'] ?? 'single');
        $target = explode('__', preg_replace('/[^a-z0-9_-]/', '', (string)($_GET['target'] ?? '')), 2)[0];
        $q = trim((string)($_GET['q'] ?? ''));
        $out = [];
        if ($kind === 'single') {
            $res = \Slate\Posts::query(['type' => $target, 'status' => ['publish', 'private', 'draft'], 'search' => $q, 'limit' => 20, 'count' => false, 'orderby' => 'updated_at', 'order' => 'DESC']);
            foreach ($res['items'] as $p) {
                $out[] = ['id' => (int)$p['id'], 'label' => $p['title'] ?: '(no title)'];
            }
        } elseif ($kind === 'term') {
            foreach (array_slice(array_filter(\Slate\Terms::all($target), static fn($t) => $q === '' || stripos($t['name'], $q) !== false), 0, 20) as $t) {
                $out[] = ['id' => (int)$t['id'], 'label' => $t['name']];
            }
        }
        json_response(['ok' => true, 'items' => $out]);
    });
    $r->post('/templates/{id}/delete', static function (string $id) {
        Auth::require('manage_content_model');
        \Slate\Templates::delete($id);
        Session::flash('success', 'Template deleted.');
        redirect(admin_url('templates'));
    });

    // ---------- Modules ----------
    $moduleView = static function (string $tab, array $vars = []): void {
        Auth::require('manage_modules');
        admin_view('modules', $vars + ['tab' => $tab, 'title' => 'Modules', 'modules' => Modules::all(true),
            'errors' => Modules::errors(), 'locked' => ModuleInstaller::lockReason()]);
    };
    $moduleDone = static function (array $res, string $success, ?string $to = null): never {
        if ($res['ok']) {
            Session::flash('success', $success);
        } else {
            Session::flash('error', $res['error']);
        }
        redirect($to ?? admin_url('modules'));
    };
    $installed = static function (array $res) use ($moduleDone): never {
        if (!$res['ok']) {
            $moduleDone($res, '', admin_url('modules/add'));
        }
        $msg = !empty($res['updated'])
            ? 'Module updated to ' . ($res['version'] ?? 'the new version') . '. The previous version was backed up.'
            : 'Module installed. Review what it can do, then activate it.';
        $moduleDone($res, $msg, admin_url('modules/' . $res['slug'] . '/check'));
    };
    $slugOr404 = static function (string $slug): string {
        return Modules::validSlug($slug) && Modules::get($slug) ? $slug : admin_404();
    };

    $r->get('/modules', static function () use ($moduleView) {
        Auth::require('manage_modules');
        $moduleView('installed', ['updates' => ModuleInstaller::updates()]);
    });
    $r->get('/modules/available', static function () use ($moduleView) {
        Auth::require('manage_modules');
        $moduleView('available', ['available' => ModuleInstaller::available(isset($_GET['refresh'])),
            'registryErrors' => ModuleInstaller::registryErrors(), 'updates' => ModuleInstaller::updates(isset($_GET['refresh']))]);
    });
    $r->get('/modules/add', static fn() => $moduleView('add', ['aiEnabled' => \Slate\Ai::enabled()]));
    $r->get('/modules/sources', static fn() => $moduleView('sources', ['registries' => ModuleInstaller::registries(),
        'hasToken' => (string)Options::get('modules_github_token') !== '']));

    $r->post('/modules/sources', static function () {
        Auth::require('manage_modules');
        $urls = array_filter(array_map('trim', preg_split('/\s+/', (string)input('registries', ''))), static fn($u) => preg_match('#^https?://\S+$#i', $u));
        Options::set('module_registries', implode("\n", array_unique($urls)), false);
        if (input('token_clear')) {
            Options::set('modules_github_token', '', false);
        } elseif (trim((string)input('token', '')) !== '') {
            Options::set('modules_github_token', \Slate\Crypto::encrypt(trim((string)input('token'))), false);
        }
        Options::delete('module_updates');
        Session::flash('success', 'Module sources saved.');
        redirect(admin_url('modules/sources'));
    });
    $r->post('/modules/{slug}/toggle', static function (string $slug) use ($slugOr404) {
        Auth::require('manage_modules');
        $slugOr404($slug);
        if (Modules::isActive($slug)) {
            $err = Modules::deactivate($slug);
            Session::flash($err ? 'error' : 'success', $err ?: 'Module deactivated.');
        } elseif ($err = Modules::activate($slug)) {
            Session::flash('error', $err);
        } else {
            Session::flash('success', 'Module activated.');
        }
        log_activity('module_toggled', $slug);
        redirect((string)input('back', '') === 'check' ? admin_url("modules/$slug/check") : admin_url('modules'));
    });
    $r->post('/modules/upload', static function () use ($installed) {
        Auth::require('manage_modules');
        $f = $_FILES['module'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
            Session::flash('error', 'Choose a module .zip file to upload (the server may also limit upload size).');
            redirect(admin_url('modules/add'));
        }
        $installed(ModuleInstaller::fromZip((string)$f['tmp_name'], null, (bool)input('replace'), null, '', null, basename((string)$f['name'])));
    });
    $r->post('/modules/install-url', static function () use ($installed) {
        Auth::require('manage_modules');
        $installed(ModuleInstaller::fromUrl((string)input('url', ''), trim((string)input('sha256', '')) ?: null, (bool)input('replace')));
    });
    $r->post('/modules/install/{slug}', static function (string $slug) use ($installed) {
        Auth::require('manage_modules');
        foreach (ModuleInstaller::available() as $e) {
            if ($e['slug'] === $slug && $e['download'] !== '') {
                $installed(ModuleInstaller::fromUrl($e['download'], $e['sha256'], false, $slug));
            }
        }
        Session::flash('error', 'That module is not listed by your registries.');
        redirect(admin_url('modules/available'));
    });
    $r->post('/modules/{slug}/update', static function (string $slug) use ($installed, $slugOr404) {
        Auth::require('manage_modules');
        $installed(ModuleInstaller::update($slugOr404($slug)));
    });
    $r->post('/modules/{slug}/delete', static function (string $slug) use ($moduleDone, $slugOr404) {
        Auth::require('manage_modules');
        $res = ModuleInstaller::delete($slugOr404($slug), (bool)input('purge'));
        $moduleDone($res, 'Module deleted. A backup was kept in content/backups/modules/.');
    });
    $r->post('/modules/{slug}/restore', static function (string $slug) use ($moduleDone, $slugOr404) {
        Auth::require('manage_modules');
        $slugOr404($slug);
        $file = basename((string)input('file', ''));
        $ok = false;
        foreach (ModuleInstaller::backups($slug) as $b) {
            if ($b['file'] === $file && ModuleInstaller::allowed()) {
                $ok = ModuleInstaller::restore($slug, SLATE_CONTENT . '/backups/modules/' . $file);
            }
        }
        if ($ok) {
            log_activity('module_restored', "$slug $file");
        }
        $moduleDone(['ok' => $ok, 'error' => 'Could not restore that backup.'], 'Previous version restored.', admin_url("modules/$slug/check"));
    });
    $r->get('/modules/{slug}/download', static function (string $slug) use ($slugOr404) {
        Auth::require('manage_modules');
        $file = ModuleInstaller::export($slugOr404($slug));
        if (!$file) {
            Session::flash('error', 'Could not package the module (the PHP zip extension is required).');
            redirect(admin_url("modules/$slug/check"));
        }
        log_activity('module_exported', $slug);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        \Slate\Updater::rrmdir(dirname($file));
        exit;
    });
    $r->post('/modules/{slug}/dismiss', static function (string $slug) {
        Auth::require('manage_modules');
        Modules::clearError($slug);
        redirect(admin_url('modules'));
    });
    $r->get('/modules/{slug}/check', static function (string $slug) use ($moduleView, $slugOr404) {
        Auth::require('manage_modules');
        $slugOr404($slug);
        $moduleView('check', ['module' => Modules::get($slug), 'scan' => ModuleScanner::scan(SLATE_MODULES . "/$slug"),
            'backups' => ModuleInstaller::backups($slug), 'dependents' => Modules::dependents($slug)]);
    });
    $r->post('/modules/create', static function () use ($installed) {
        Auth::require('manage_modules');
        $name = trim((string)input('name', ''));
        $slug = slugify((string)input('slug', '') ?: $name);
        if ($name === '' || !Modules::validSlug($slug)) {
            Session::flash('error', 'Give the module a name.');
            redirect(admin_url('modules/add'));
        }
        $installed(ModuleInstaller::fromFiles($slug, ModuleBuilder::scaffold($slug, $name, trim((string)input('description', '')), (bool)input('admin_page'))));
    });
    $r->post('/modules/ai', static function () {
        Auth::require('manage_modules');
        @set_time_limit(300);
        $res = ModuleBuilder::generate((string)input('request', ''));
        if (!$res['ok']) {
            Session::flash('error', $res['error']);
            redirect(admin_url('modules/add'));
        }
        $id = ModuleBuilder::saveDraft($res + ['request' => (string)input('request', '')]);
        redirect(admin_url("modules/draft/$id"));
    });
    $r->get('/modules/draft/{id}', static function (string $id) use ($moduleView) {
        Auth::require('manage_modules');
        $d = ModuleBuilder::draft($id) ?? admin_404();
        $moduleView('draft', ['draftId' => $id, 'draft' => $d, 'scan' => ModuleBuilder::scanFiles($d['files'])]);
    });
    $r->post('/modules/draft/{id}/install', static function (string $id) use ($installed) {
        Auth::require('manage_modules');
        $d = ModuleBuilder::draft($id) ?? admin_404();
        $res = ModuleInstaller::fromFiles($d['slug'], $d['files']);
        if ($res['ok']) {
            ModuleBuilder::dropDraft($id);
        }
        $installed($res);
    });
    $r->post('/modules/draft/{id}/discard', static function (string $id) {
        Auth::require('manage_modules');
        ModuleBuilder::dropDraft($id);
        Session::flash('success', 'Draft discarded.');
        redirect(admin_url('modules/add'));
    });
    $r->any('/m/{slug}', static function (string $slug) {
        $page = Modules::adminPages()[$slug] ?? admin_404();
        Auth::require($page['cap']);
        ob_start();
        ($page['render'])();
        $group = Modules::adminGroups()[$page['module'] ?? ''] ?? null;
        // Hidden detail pages highlight their module's first tab.
        $current = $page['section'] === 'hidden' && $group && $group['pages'] ? admin_url('m/' . $group['pages'][0]['slug']) : admin_url('m/' . $slug);
        admin_view('module-page', ['html' => ob_get_clean(), 'title' => $page['label'], 'module' => $group['name'] ?? '',
            'moduleSlug' => (string)($page['module'] ?? ''), 'icon' => $group['icon'] ?? $page['icon'], 'currentUrl' => $current]);
    });

    // ---------- Settings ----------
    $r->get('/settings', static function () {
        Auth::require('access_admin');
        if (!Auth::can('manage_settings')) {
            foreach (Settings::tabs() as $tab => $t) {
                if (admin_can_settings($tab)) {
                    redirect(Settings::url($tab));
                }
            }
            admin_404();
        }
        admin_view('settings', ['tab' => '', 'section' => '', 'def' => [], 'title' => 'Settings']);
    });
    // Quick switches (dashboard / settings overview). Only these keys; each maps "switch on" to the safe, normal state.
    $r->post('/settings/quick', static function () {
        Auth::require('manage_settings');
        $key = (string)input('key');
        $on = filter_var(input('on'), FILTER_VALIDATE_BOOLEAN);
        $map = ['seo_noindex_site' => !$on, 'maintenance_mode' => !$on, 'cache_pages' => $on];
        if (!array_key_exists($key, $map)) {
            json_response(['ok' => false, 'error' => 'Unknown setting.'], 400);
        }
        Options::set($key, $map[$key]);
        if ($key === 'cache_pages') {
            PageCache::writeFlag();
        }
        PageCache::clear();
        log_activity('settings_saved', "quick:$key=" . ($map[$key] ? '1' : '0'));
        json_response(['ok' => true, 'message' => match ($key) {
            'seo_noindex_site' => $on ? 'Search engines may index the site again.' : 'The whole site is now hidden from search engines.',
            'maintenance_mode' => $on ? 'The site is online for everyone.' : 'Maintenance mode is on, visitors see a notice.',
            default => $on ? 'Page cache is on.' : 'Page cache is off.',
        }]);
    });
    // Old addresses: renamed sections (MOVED) and groups that now have their own page (SEO, Appearance).
    $moved = static function (string $tab, ?string $section = null): ?string {
        $key = $section === null ? $tab : "$tab/$section";
        if (isset(Settings::MOVED[$key])) {
            [$tab, $section] = array_pad(explode('/', Settings::MOVED[$key], 2), 2, null);
            return isset(Settings::STANDALONE[$tab]) ? Settings::url($tab, $section) : admin_url('settings/' . Settings::MOVED[$key]);
        }
        return isset(Settings::STANDALONE[$tab]) ? Settings::url($tab, $section) : null;
    };
    $r->get('/settings/{tab}', static function (string $tab) use ($moved) {
        if ($to = $moved($tab)) {
            redirect($to, 301);
        }
        Settings::tabs()[$tab] ?? admin_404();
        redirect(Settings::url($tab));
    });
    $r->get('/settings/{tab}/{section}', static function (string $tab, string $section) use ($moved) {
        if ($to = $moved($tab, $section)) {
            redirect($to, 301);
        }
        admin_require_settings($tab);
        $def = Settings::section($tab, $section) ?? admin_404();
        admin_view('settings', ['tab' => $tab, 'section' => $section, 'def' => $def, 'title' => 'Settings']);
    });
    $r->post('/settings/{tab}/{section}', static fn(string $tab, string $section) => admin_settings_save($tab, $section));

    // SEO and Appearance: same settings, their own place in the sidebar.
    foreach (Settings::STANDALONE as $tab => $base) {
        $r->get('/' . $base, static function () use ($tab) {
            admin_require_settings($tab);
            redirect(Settings::url($tab));
        });
        $r->get('/' . $base . '/{section}', static function (string $section) use ($tab) {
            admin_require_settings($tab);
            $def = Settings::section($tab, $section) ?? admin_404();
            admin_view('settings', ['tab' => $tab, 'section' => $section, 'def' => $def, 'title' => Settings::tabs()[$tab]['label']]);
        });
        $r->post('/' . $base . '/{section}', static fn(string $section) => admin_settings_save($tab, $section));
    }
    // ---------- Dashboard access: private domain + IP allow-list ----------
    $r->post('/system/admin-host/check', static function () {
        Auth::require('manage_settings');
        [$ok, $msg] = \Slate\AdminAccess::probe((string)input('host'));
        json_response(['ok' => $ok, 'message' => $msg]);
    });
    $r->post('/system/admin-host/enable', static function () {
        Auth::require('manage_settings');
        $host = strtolower(trim(preg_replace('#^https?://|/.*$#i', '', (string)input('host'))));
        [$ok, $msg] = \Slate\AdminAccess::probe($host);
        if (!$ok) {
            Session::flash('error', $msg);
            redirect(admin_url('settings/security/access'));
        }
        if ($err = \Slate\AdminAccess::setConfig('admin_host', $host)) {
            Session::flash('error', $err);
            redirect(admin_url('settings/security/access'));
        }
        log_activity('admin_host_changed', $host);
        redirect(\Slate\AdminAccess::handoffUrl($host, Auth::id(), 'settings/security/access'));
    });
    $r->post('/system/admin-host/disable', static function () {
        Auth::require('manage_settings');
        if ($err = \Slate\AdminAccess::setConfig('admin_host', null)) {
            Session::flash('error', $err);
            redirect(admin_url('settings/security/access'));
        }
        log_activity('admin_host_changed', '(none)');
        $main = (string)parse_url(site_url(), PHP_URL_HOST);
        redirect(\Slate\AdminAccess::handoffUrl($main, Auth::id(), 'settings/security/access'));
    });
    $r->get('/system/admin-host/bridge', static function () {
        Auth::require('manage_settings');
        $host = strtolower(trim(preg_replace('#^https?://|/.*$#i', '', (string)($_GET['host'] ?? ''))));
        if (!preg_match('/^[a-z0-9.-]{4,253}$/', $host) || !class_exists('ZipArchive')) {
            admin_404();
        }
        $tmp = tempnam(sys_get_temp_dir(), 'slb');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach (\Slate\AdminAccess::bridgeFiles($host) as $name => $body) {
            $zip->addFromString($name, $body);
        }
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="slate-bridge-' . preg_replace('/[^a-z0-9.-]/', '', $host) . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    });
    $r->post('/system/admin-ips', static function () {
        Auth::require('manage_settings');
        $list = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)input('ips')) ?: [])));
        foreach ($list as $rule) {
            [$ip] = explode('/', $rule, 2);
            if (@inet_pton($ip) === false) {
                Session::flash('error', "\"$rule\" is not an IP address or range.");
                redirect(admin_url('settings/security/access'));
            }
        }
        $me = Auth::ip();
        if ($list && !array_filter($list, static fn($r) => \Slate\AdminAccess::match($me, $r))) {
            Session::flash('error', "Your own address ($me) is not in the list, so saving it would lock you out. Add it first.");
            redirect(admin_url('settings/security/access'));
        }
        if ($err = \Slate\AdminAccess::setConfig('admin_ips', $list ?: null)) {
            Session::flash('error', $err);
        } else {
            log_activity('admin_ips_changed', implode(' ', $list) ?: '(any)');
            Session::flash('success', $list ? 'Only the listed addresses can reach the dashboard now.' : 'The dashboard can be reached from any address.');
        }
        redirect(admin_url('settings/security/access'));
    });

    $r->post('/system/cache/clear', static function () {
        Auth::require('manage_settings');
        PageCache::clear();
        is_ajax() ? json_response(['ok' => true, 'message' => 'Cache refreshed: every page will be rebuilt on its next visit.']) : null;
        Session::flash('success', 'Cache refreshed: every page will be rebuilt on its next visit (important pages are re-warmed in the background).');
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

    // Feature route files (admin/routes/*.php each return fn(Router $r)).
    foreach (glob(__DIR__ . '/routes/*.php') ?: [] as $routeFile) {
        (require $routeFile)($r);
    }

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

/** The list table for a content type: columns for taxonomies, custom fields, author, SEO and dates. */
function admin_content_table(array $def): \Slate\ListTable
{
    $slug = $def['slug'];
    $base = admin_url("content/$slug");
    $taxes = ContentModel::taxonomiesFor($slug);
    $supports = (array)($def['supports'] ?? []);
    $authors = array_column(DB::all('SELECT id, display_name FROM {{users}} ORDER BY display_name'), 'display_name', 'id');
    $statuses = Posts::STATUSES;
    $cols = [];
    $cols['title'] = ['label' => 'Title', 'primary' => true, 'sort' => 'title', 'render' => static function (array $p) use ($base, $supports, $statuses): string {
        $thumb = '';
        if (in_array('thumbnail', $supports, true)) {
            $m = $p['featured_media_id'] ? Media::find((int)$p['featured_media_id']) : null;
            $thumb = '<span class="row-thumb">' . ($m ? '<img src="' . e(Media::url($m, 'thumb')) . '" alt="" loading="lazy">' : icon('image', 14)) . '</span>';
        }
        $html = '<div class="row-main">' . $thumb . '<div><a class="row-title" href="' . e($base . '/' . $p['id']) . '">'
            . ($p['parent_id'] ? '<span class="muted">- </span>' : '') . e($p['title'] ?: '(no title)') . '</a>';
        if ($p['status'] !== 'publish') {
            $html .= ' <span class="status status-' . e($p['status']) . '">' . e($statuses[$p['status']] ?? $p['status']) . '</span>';
        }
        if (\Slate\Seo::isFrontPage($p)) {
            $html .= ' <span class="pill">Homepage</span>';
        }
        return $html . '</div></div>';
    }, 'export' => static fn(array $p) => (string)$p['title']];
    $cols['status'] = ['label' => 'Status', 'default' => false, 'sort' => 'status', 'render' => static fn(array $p) => '<span class="status status-' . e($p['status']) . '">' . e($statuses[$p['status']] ?? $p['status']) . '</span>'];
    $i = 0;
    foreach ($taxes as $tx => $tdef) {
        $cols["tax_$tx"] = ['label' => $tdef['plural'], 'default' => $i++ < 3, 'render' => static function (array $p) use ($tx): string {
            $names = array_column($p['terms'][$tx] ?? [], 'name');
            return $names ? e(implode(', ', $names)) : '<span class="muted">-</span>';
        }];
    }
    foreach (ContentModel::fieldsFor('post', $slug) as $name => $f) {
        if (in_array($f['type'] ?? '', ['repeater', 'group', 'gallery', 'wysiwyg', 'code', 'flexible', 'message', 'tab'], true)) {
            continue;
        }
        $cols["field_$name"] = ['label' => $f['label'] ?? $name, 'default' => false, 'sort' => "field:$name", 'render' => static function (array $p) use ($name, $f): string {
            $v = $p['fields'][$name] ?? null;
            if ($v === null || $v === '' || $v === []) {
                return '<span class="muted">-</span>';
            }
            if (($f['type'] ?? '') === 'image' && is_numeric($v) && ($m = Media::find((int)$v))) {
                return '<img class="cell-img" src="' . e(Media::url($m, 'thumb')) . '" alt="" loading="lazy">';
            }
            if (is_bool($v) || ($f['type'] ?? '') === 'toggle') {
                return $v ? 'Yes' : 'No';
            }
            if (is_array($v)) {
                $v = implode(', ', array_map(static fn($x) => is_scalar($x) ? (string)$x : json_encode($x), $v));
            }
            return e(str_limit((string)$v, 80));
        }, 'export' => static fn(array $p) => is_array($p['fields'][$name] ?? null) ? json_encode($p['fields'][$name], JSON_UNESCAPED_UNICODE) : (string)($p['fields'][$name] ?? '')];
    }
    if (in_array('author', $supports, true)) {
        $cols['author'] = ['label' => 'Author', 'sort' => 'author_id', 'render' => static fn(array $p) => e($authors[$p['author_id']] ?? '-'), 'export' => static fn(array $p) => (string)($authors[$p['author_id']] ?? '')];
    }
    $cols['seo'] = ['label' => 'SEO', 'render' => static function (array $p): string {
        $score = (int)!empty($p['seo']['title']) + (int)!empty($p['seo']['description']) + (int)!empty($p['featured_media_id']);
        $noindex = ($p['seo']['robots_index'] ?? '') === 'noindex';
        $dots = '';
        for ($i = 0; $i < 3; $i++) {
            $dots .= '<i class="' . ($i < $score ? 'on' : '') . '"></i>';
        }
        return '<span class="seo-dots" title="' . $score . '/3: meta title, description, image">' . $dots . '</span>' . ($noindex ? ' <span class="pill pill-warn" title="Hidden from search engines">noindex</span>' : '');
    }, 'export' => static fn(array $p) => (($p['seo']['robots_index'] ?? '') === 'noindex' ? 'noindex ' : '') . ((int)!empty($p['seo']['title']) + (int)!empty($p['seo']['description']) + (int)!empty($p['featured_media_id'])) . '/3'];
    $cols['date'] = ['label' => 'Date', 'sort' => 'published_at', 'render' => static fn(array $p) => '<span class="cell-date"><small class="muted">' . ($p['status'] === 'publish' ? 'Published' : 'Modified') . '</small>' . e(local_date($p['status'] === 'publish' ? $p['published_at'] : $p['updated_at'])) . '</span>',
        'export' => static fn(array $p) => (string)($p['published_at'] ?? '')];
    $cols['updated'] = ['label' => 'Last modified', 'default' => false, 'sort' => 'updated_at', 'render' => static fn(array $p) => e(local_date($p['updated_at'])) . ' <small class="muted">' . e(time_ago($p['updated_at'])) . '</small>', 'export' => static fn(array $p) => (string)$p['updated_at']];
    $cols['created'] = ['label' => 'Created', 'default' => false, 'sort' => 'created_at', 'render' => static fn(array $p) => e(local_date($p['created_at'])), 'export' => static fn(array $p) => (string)$p['created_at']];
    $cols['slug'] = ['label' => 'Slug', 'default' => false, 'sort' => 'slug', 'render' => static fn(array $p) => '<code class="cell-code">' . e($p['slug']) . '</code>', 'export' => static fn(array $p) => (string)$p['slug']];
    $cols['url'] = ['label' => 'URL', 'default' => false, 'render' => static fn(array $p) => '<a href="' . e(Posts::permalink($p)) . '" target="_blank" rel="noopener" class="cell-link">' . e(Posts::permalink($p)) . '</a>', 'export' => static fn(array $p) => Posts::permalink($p, true)];
    $cols['id'] = ['label' => 'ID', 'default' => false, 'sort' => 'id', 'align' => 'right', 'render' => static fn(array $p) => (string)(int)$p['id']];

    // Status views
    $counts = Posts::counts($slug);
    $views = ['all' => ['All', array_sum(array_diff_key($counts, ['trash' => 0]))]];
    foreach ($statuses as $k => $label) {
        if ($counts[$k] ?? 0) {
            $views[$k] = [$label, $counts[$k]];
        }
    }
    // Filters
    $filters = [];
    foreach ($taxes as $tx => $tdef) {
        $n = (int)DB::val('SELECT COUNT(*) FROM {{terms}} WHERE taxonomy = :t', ['t' => $tx]);
        if (!$n) {
            continue;
        }
        if ($n <= 150) {
            $filters["tax_$tx"] = ['label' => $tdef['plural'], 'options' => array_column(DB::all('SELECT id, name FROM {{terms}} WHERE taxonomy = :t ORDER BY name', ['t' => $tx]), 'name', 'id')];
        } else {
            $filters["tax_$tx"] = ['label' => $tdef['singular'] ?? $tdef['plural'], 'type' => 'text', 'list' => DB::col('SELECT name FROM {{terms}} WHERE taxonomy = :t ORDER BY count DESC, name LIMIT 1000', ['t' => $tx])];
        }
    }
    if (in_array('author', $supports, true) && count($authors) > 1 && Auth::can('edit_others_posts')) {
        $filters['author'] = ['label' => 'Authors', 'options' => $authors];
    }
    $months = DB::col("SELECT DISTINCT SUBSTR(COALESCE(published_at, created_at), 1, 7) m FROM {{posts}} WHERE type = :t AND status <> 'trash' ORDER BY m DESC LIMIT 48", ['t' => $slug]);
    if (count($months) > 1) {
        $opts = [];
        foreach ($months as $m) {
            $opts[$m] = date('F Y', (int)strtotime($m . '-01'));
        }
        $filters['month'] = ['label' => 'Dates', 'all' => 'All dates', 'options' => $opts];
    }
    $filters['indexing'] = ['label' => 'Search engines', 'all' => 'Any visibility', 'options' => ['index' => 'Visible to search engines', 'noindex' => 'Hidden (noindex)']];
    // Bulk actions
    $view = (string)($_GET['view'] ?? '');
    if ($view === 'trash') {
        $bulk = ['restore' => 'Restore'];
        if (Auth::can('delete_posts')) {
            $bulk['delete'] = ['label' => 'Delete permanently', 'danger' => true, 'confirm' => 'Permanently delete the selected items? This cannot be undone.'];
        }
    } else {
        $bulk = [];
        if (Auth::can('publish_posts')) {
            $bulk['publish'] = 'Publish';
        }
        $bulk['draft'] = 'Move to draft';
        $termOpts = [];
        foreach ($taxes as $tx => $tdef) {
            if (isset($filters["tax_$tx"]['options'])) {
                foreach ($filters["tax_$tx"]['options'] as $tid => $name) {
                    $termOpts[$tid] = $tdef['plural'] . ': ' . $name;
                }
            }
        }
        if ($termOpts) {
            $bulk['term_add'] = ['label' => 'Add term', 'options' => $termOpts];
            $bulk['term_remove'] = ['label' => 'Remove term', 'options' => $termOpts];
        }
        if (in_array('author', $supports, true) && count($authors) > 1 && Auth::can('edit_others_posts')) {
            $bulk['author'] = ['label' => 'Change author', 'options' => $authors];
        }
        $bulk['seo_index'] = ['label' => 'Search engines', 'options' => ['index' => 'Allow indexing', 'noindex' => 'Hide (noindex)']];
        if (Auth::can('publish_posts') && \Slate\Indexing::enabled()) {
            $bulk['seo_request'] = 'Request indexing';
        }
        $bulk['trash'] = ['label' => 'Move to trash', 'danger' => true];
    }

    return new \Slate\ListTable("content.$slug", $cols, [
        'base' => $base, 'search' => 'Search ' . strtolower($def['plural']) . '…', 'views' => $views, 'filters' => $filters, 'bulk' => $bulk,
        'bulk_action' => "$base/bulk", 'all_matching' => true, 'export' => true,
        'sort' => $def['hierarchical'] ? 'menu_order' : 'published_at', 'dir' => $def['hierarchical'] ? 'asc' : 'desc',
        'row_actions' => static function (array $p) use ($base): string {
            $view = $p['status'] === 'publish';
            return '<a href="' . e("$base/{$p['id']}") . '">Edit</a>'
                . '<button type="button" class="link" data-quick-edit=\'' . e(json_encode(['id' => $p['id'], 'title' => $p['title'], 'slug' => $p['slug'], 'status' => $p['status'],
                    'date' => $p['published_at'] ? local_date($p['published_at'], 'Y-m-d\TH:i') : '', 'action' => "$base/{$p['id']}/quick"], JSON_UNESCAPED_UNICODE)) . '\'>Quick edit</button>'
                . '<a href="' . e(Posts::permalink($p) . ($view ? '' : '?preview=' . $p['id'])) . '" target="_blank" rel="noopener">' . ($view ? 'View' : 'Preview') . '</a>'
                . '<button type="submit" formaction="' . e("$base/{$p['id']}/duplicate") . '" class="link">Duplicate</button>';
        },
        'empty' => ['icon' => $def['icon'] ?? 'file', 'title' => 'No ' . strtolower($def['plural']) . ' yet', 'text' => 'Create your first one to get started.',
            'html' => '<a class="btn btn-primary" href="' . e("$base/new") . '">' . icon('plus', 16) . ' Add ' . e(strtolower($def['singular'])) . '</a>'],
    ]);
}

/** Taxonomy screen: add/edit form + searchable, sortable list of terms. */
function admin_terms_screen(string $tax, ?int $id): void
{
    Auth::require('manage_terms');
    $def = ContentModel::taxonomy($tax) ?? admin_404();
    $term = $id ? (Terms::find($id) ?? admin_404()) : null;
    $all = $def['hierarchical'] ? Terms::tree($tax) : Terms::all($tax);
    $fields = ContentModel::fieldsFor('term', $tax);
    $postType = $def['post_types'][0] ?? null;
    $cols = [
        'name' => ['label' => 'Name', 'primary' => true, 'sort' => 'name', 'render' => static fn($o) => '<a class="row-title" href="' . e(admin_url("terms/$tax/{$o['id']}")) . '">'
            . str_repeat('<span class="muted">- </span>', (int)($o['depth'] ?? 0)) . e($o['name']) . '</a>', 'export' => static fn($o) => (string)$o['name']],
        'slug' => ['label' => 'Slug', 'sort' => 'slug', 'render' => static fn($o) => '<code class="cell-code">' . e($o['slug']) . '</code>', 'export' => static fn($o) => (string)$o['slug']],
        'description' => ['label' => 'Description', 'default' => false, 'render' => static fn($o) => e(str_limit((string)$o['description'], 90)), 'export' => static fn($o) => (string)$o['description']],
    ];
    foreach ($fields as $name => $f) {
        if (in_array($f['type'] ?? '', ['repeater', 'group', 'gallery', 'wysiwyg', 'code', 'flexible', 'message', 'tab'], true)) {
            continue;
        }
        $cols["field_$name"] = ['label' => $f['label'] ?? $name, 'default' => false, 'render' => static function ($o) use ($name): string {
            $v = $o['fields'][$name] ?? null;
            return $v === null || $v === '' ? '<span class="muted">-</span>' : e(str_limit(is_array($v) ? implode(', ', array_map('strval', array_filter($v, 'is_scalar'))) : (string)$v, 60));
        }];
    }
    $cols['count'] = ['label' => 'Count', 'sort' => 'count', 'sort_dir' => 'desc', 'align' => 'right', 'render' => static fn($o) => $postType
        ? '<a href="' . e(admin_url("content/$postType") . '?f%5Btax_' . $tax . '%5D=' . $o['id']) . '">' . number_format((int)$o['count']) . '</a>' : number_format((int)$o['count']),
        'export' => static fn($o) => (string)(int)$o['count']];
    $cols['id'] = ['label' => 'ID', 'default' => false, 'sort' => 'id', 'align' => 'right', 'render' => static fn($o) => (string)(int)$o['id']];
    $table = new \Slate\ListTable("terms.$tax", $cols, [
        'base' => admin_url("terms/$tax"), 'search' => 'Search ' . strtolower($def['plural']) . '…', 'sort' => '', 'export' => true,
        'bulk' => ['delete' => ['label' => 'Delete', 'danger' => true, 'confirm' => 'Delete the selected ' . strtolower($def['plural']) . '? Content using them is kept.']],
        'bulk_action' => admin_url("terms/$tax/bulk"), 'per_page' => 50,
        'row_actions' => static fn($o) => '<a href="' . e(admin_url("terms/$tax/{$o['id']}")) . '">Edit</a><a href="' . e(Terms::link($o)) . '" target="_blank" rel="noopener">View</a>',
        'empty' => ['icon' => 'tag', 'title' => 'No ' . strtolower($def['plural']) . ' yet', 'text' => 'Add the first one with the form.'],
    ]);
    $s = $table->state();
    $rows = $all;
    if ($s['q'] !== '') {
        $q = mb_strtolower($s['q']);
        $rows = array_values(array_filter($rows, static fn($o) => str_contains(mb_strtolower($o['name'] . ' ' . $o['slug'] . ' ' . $o['description']), $q)));
    }
    if ($s['sort'] !== '') {
        $k = $s['sort'];
        usort($rows, static fn($a, $b) => ($s['dir'] === 'asc' ? 1 : -1) * (in_array($k, ['count', 'id'], true) ? (int)$a[$k] <=> (int)$b[$k] : strnatcasecmp((string)$a[$k], (string)$b[$k])));
    }
    if ($table->exporting()) {
        $table->exportCsv($rows, $tax . '-' . gmdate('Y-m-d') . '.csv');
    }
    admin_view('terms', ['tax' => $def, 'terms' => $all, 'term' => $term, 'fields' => $fields, 'table' => $table,
        'rows' => array_slice($rows, $s['offset'], $s['per']), 'total' => count($rows), 'title' => $term ? 'Edit ' . $def['singular'] : $def['plural']]);
}

/** Users list table. */
function admin_users_table(array $users): \Slate\ListTable
{
    $roles = Roles::all();
    $views = ['all' => ['All', count($users)]];
    foreach ($roles as $k => $r) {
        $n = count(array_filter($users, static fn($u) => $u['role'] === $k));
        if ($n) {
            $views[$k] = [$r['label'], $n];
        }
    }
    $sus = count(array_filter($users, static fn($u) => $u['status'] !== 'active'));
    if ($sus) {
        $views['suspended'] = ['Suspended', $sus];
    }
    $cols = [
        'name' => ['label' => 'User', 'primary' => true, 'sort' => 'name', 'render' => static fn($u) => '<a class="user-cell" href="' . e(admin_url('users/' . $u['id'])) . '"><span class="avatar avatar-sm">'
            . e(mb_strtoupper(mb_substr($u['display_name'] ?: $u['username'], 0, 1))) . '</span><span><strong>' . e($u['display_name'] ?: $u['username']) . '</strong><small class="muted">' . e($u['email']) . '</small></span></a>',
            'export' => static fn($u) => (string)($u['display_name'] ?: $u['username'])],
        'username' => ['label' => 'Username', 'default' => false, 'render' => static fn($u) => e($u['username'])],
        'email' => ['label' => 'Email', 'default' => false, 'render' => static fn($u) => e($u['email'])],
        'role' => ['label' => 'Role', 'sort' => 'role', 'render' => static fn($u) => '<span class="pill">' . e(Roles::label($u['role'])) . '</span>' . ($u['status'] !== 'active' ? ' <span class="status status-trash">Suspended</span>' : ''),
            'export' => static fn($u) => Roles::label($u['role']) . ($u['status'] !== 'active' ? ' (suspended)' : '')],
        'security' => ['label' => 'Sign-in security', 'render' => static fn($u) => $u['passkeys'] ? '<span class="pill pill-success">' . icon('key', 12) . ' Passkey</span>' . ($u['totp_secret'] ? ' <span class="pill pill-success">2FA</span>' : '')
            : ($u['totp_secret'] ? '<span class="pill pill-success">' . icon('lock', 12) . ' 2FA</span>' : '<span class="muted">Password only</span>'),
            'export' => static fn($u) => trim(($u['passkeys'] ? 'passkey ' : '') . ($u['totp_secret'] ? '2fa' : '')) ?: 'password'],
        'posts' => ['label' => 'Content', 'sort' => 'posts', 'align' => 'right', 'render' => static fn($u) => number_format((int)$u['posts'])],
        'last_login' => ['label' => 'Last sign-in', 'sort' => 'last_login', 'sort_dir' => 'desc', 'render' => static fn($u) => e(time_ago($u['last_login'])), 'export' => static fn($u) => (string)$u['last_login']],
        'created' => ['label' => 'Joined', 'default' => false, 'sort' => 'created', 'render' => static fn($u) => e(local_date($u['created_at'] ?? null)), 'export' => static fn($u) => (string)($u['created_at'] ?? '')],
        'id' => ['label' => 'ID', 'default' => false, 'sort' => 'id', 'align' => 'right', 'render' => static fn($u) => (string)(int)$u['id']],
    ];
    return new \Slate\ListTable('users', $cols, [
        'base' => admin_url('users'), 'search' => 'Search name or email…', 'views' => $views, 'sort' => 'id', 'dir' => 'asc', 'export' => true,
        'filters' => ['security' => ['label' => 'Sign-in security', 'all' => 'Any sign-in security', 'options' => ['protected' => 'Passkey or 2FA', 'none' => 'Password only']]],
        'bulk' => ['role' => ['label' => 'Change role', 'options' => array_map(static fn($r) => $r['label'], $roles)], 'activate' => 'Reactivate',
            'suspend' => ['label' => 'Suspend', 'danger' => true, 'confirm' => 'Suspend the selected users? They are signed out and cannot sign in until reactivated.']],
        'bulk_action' => admin_url('users/bulk'),
        'row_actions' => static fn($u) => '<a href="' . e(admin_url('users/' . $u['id'])) . '">Edit</a>',
        'empty' => ['icon' => 'users', 'title' => 'No users'],
    ]);
}

/** Posts::query() arguments for a content list from the table state. */
function admin_content_list_args(array $def, array $s): array
{
    $view = $s['view'];
    $args = ['type' => $def['slug'], 'status' => $view === 'all' || $view === '' ? 'any' : $view, 'page' => $s['page'], 'limit' => $s['per'],
        'with_terms' => true, 'search' => $s['q'], 'orderby' => $s['sort'], 'order' => $s['dir']];
    if (str_starts_with($s['sort'], 'field:')) {
        $f = ContentModel::fieldsFor('post', $def['slug'])[substr($s['sort'], 6)] ?? [];
        $args['numeric'] = ($f['type'] ?? '') === 'number';
    }
    if (!Auth::can('edit_others_posts')) {
        $args['author'] = Auth::id();
    } elseif (!empty($s['filters']['author'])) {
        $args['author'] = (int)$s['filters']['author'];
    }
    if (!empty($s['filters']['month'])) {
        $args['month'] = $s['filters']['month'];
    }
    if (in_array($s['filters']['indexing'] ?? '', ['index', 'noindex'], true)) {
        $args['noindex'] = $s['filters']['indexing'] === 'noindex';
    }
    $terms = [];
    foreach ($s['filters'] as $k => $v) {
        if (str_starts_with($k, 'tax_')) {
            $tx = substr($k, 4);
            $terms[] = is_numeric($v) ? (int)$v : (int)(Terms::findBySlug($tx, slugify($v))['id'] ?? DB::val('SELECT id FROM {{terms}} WHERE taxonomy = :t AND name = :n', ['t' => $tx, 'n' => $v]) ?? 0);
        }
    }
    if (!empty($_GET['term'])) {
        $terms[] = (int)$_GET['term'];
    }
    if ($terms) {
        // Every chosen term must match: intersect one taxonomy at a time.
        $ids = null;
        foreach ($terms as $tid) {
            $set = array_map('intval', DB::col('SELECT post_id FROM {{term_relationships}} WHERE term_id = :t', ['t' => $tid]));
            $ids = $ids === null ? $set : array_values(array_intersect($ids, $set));
        }
        $args['ids'] = $ids ?: [0];
    }
    return $args;
}

/** Add or remove one term on a post (bulk action). */
function admin_bulk_term(int $postId, string $action, int $termId): void
{
    $t = Terms::find($termId);
    if (!$t) {
        return;
    }
    $current = array_map('intval', DB::col('SELECT tr.term_id FROM {{term_relationships}} tr INNER JOIN {{terms}} t ON t.id = tr.term_id WHERE tr.post_id = :p AND t.taxonomy = :tx',
        ['p' => $postId, 'tx' => $t['taxonomy']]));
    $next = $action === 'term_add' ? array_unique(array_merge($current, [$termId])) : array_values(array_diff($current, [$termId]));
    if ($next !== $current) {
        Terms::sync($postId, $t['taxonomy'], $next);
    }
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
    if ($existing) {
        \Slate\Revisions::snapshot($existing, $data);
    }
    $newId = Posts::save($data, $id);
    $post = Posts::find($newId);
    log_activity($id ? 'content_updated' : 'content_created', $type . ':' . $post['title']);
    if (is_ajax()) {
        json_response(['ok' => true, 'id' => $newId, 'slug' => $post['slug'], 'status' => $post['status'],
            // On a private admin host stay on that host, so previews and on-page editing keep the session.
            'permalink' => \Slate\AdminAccess::onAdminHost() ? Posts::permalink($post) : Posts::permalink($post, true), 'edit_url' => admin_url("content/$type/$newId"),
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

/** Save one settings section (Settings, SEO or Appearance) and go back to it. */
function admin_settings_save(string $tab, string $section): never
{
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
        'design-system' => admin_save_design(),
        'seo-schema', 'seo-indexing' => admin_seo_save($def['render']), // admin/routes/seo.php
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
        Session::flash('warn', 'Your admin URL is now ' . url($newSlug) . ', bookmark it.');
    }
    redirect(Settings::url($tab, $section));
}

function admin_save_menus(): void
{
    $data = json_decode((string)input('menus_json', '[]'), true);
    if (is_array($data)) {
        Menus::save($data);
    }
}

function admin_save_design(): void
{
    // The styles editor (admin/assets/design-system.js) posts everything as one JSON document.
    $json = json_decode((string)input('ds_json', ''), true);
    if (is_array($json)) {
        \Slate\DesignSystem::save($json + \Slate\DesignSystem::get());
        return;
    }
    $in = (array)input('ds', []);
    $colors = [];
    foreach ((array)($in['color_id'] ?? []) as $i => $id) {
        $colors[] = ['id' => $id ?: ($in['color_name'][$i] ?? ''), 'name' => $in['color_name'][$i] ?? '', 'value' => $in['color_value'][$i] ?? ''];
    }
    $classes = [];
    foreach ((array)($in['class_name'] ?? []) as $i => $n) {
        $classes[] = ['name' => $n, 'css' => $in['class_css'][$i] ?? ''];
    }
    \Slate\DesignSystem::save(['colors' => $colors, 'classes' => $classes, 'fonts' => (array)($in['fonts'] ?? []), 'type' => (array)($in['type'] ?? []),
        'layout' => (array)($in['layout'] ?? []), 'apply_elements' => !empty($in['apply_elements']), 'responsive' => !empty($in['responsive']),
        'admin_match' => !empty($in['admin_match'])]);
}

/** A frontend URL that shows the template (even when not enabled) to its editors. */
function admin_template_preview_url(?array $t, int $item = 0): string
{
    if (!$t || $t['id'] === '') {
        return '';
    }
    $q = 'slate_template=' . rawurlencode($t['id']);
    $type = explode('__', (string)$t['target'], 2)[0];
    switch ($t['kind']) {
        case 'single':
            $p = $item ? \Slate\Posts::find($item) : null;
            if (!$p || $p['type'] !== $type) {
                $p = \Slate\Posts::query(['type' => $type, 'status' => 'publish', 'limit' => 1, 'count' => false])['items'][0]
                    ?? \Slate\Posts::query(['type' => $type, 'limit' => 1, 'count' => false])['items'][0] ?? null;
            }
            return $p ? \Slate\Posts::permalink($p) . '?' . $q : '';
        case 'archive':
            $type = \Slate\ContentModel::type($t['target']);
            $base = trim((string)($type['rewrite'] ?? ''), '/');
            return $t['target'] === 'post' || $base === '' || str_contains($base, '%') ? url('?' . $q) : url(\Slate\Posts::trail('/' . $base)) . '?' . $q;
        case 'term':
            $term = $item ? \Slate\Terms::find($item) : null;
            $term = $term && $term['taxonomy'] === $t['target'] ? $term : (\Slate\Terms::all($t['target'])[0] ?? null);
            return $term ? \Slate\Terms::link($term) . '?' . $q : '';
        default:
            return $t['target'] === 'search' ? url('?s=a&' . $q) : url('slate-preview-not-found/?' . $q);
    }
}
