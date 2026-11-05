<?php
/**
 * Leader Network — accounts, profile claims, owner editing, bookings, paid featured placement, "On this day".
 */
declare(strict_types=1);

use Slate\App;
use Slate\Auth;
use Slate\Csrf;
use Slate\DB;
use Slate\Frontend;
use Slate\Hooks;
use Slate\Modules;
use Slate\Options;
use Slate\PageCache;
use Slate\Posts;
use Slate\Router;
use Slate\Seo;
use Slate\Session;
use Slate\Terms;
use SlateModules\LeaderNetwork\Network;
use SlateModules\LeaderNetwork\Payments;

require_once __DIR__ . '/src/Network.php';
require_once __DIR__ . '/src/Payments.php';

Hooks::add('module_activated_leader-network', [Network::class, 'install']);

Modules::adminPage('Leader network', 'leader-network', static function (): void {
    Network::ensureInstalled();
    require __DIR__ . '/views/admin/page.php';
}, 'edit_others_posts', 'users');

// ---------------------------------------------------------------- admin actions
Hooks::add('admin_routes', static function (Router $r): void {
    $r->post('/m/leader-network/action', static function (): void {
        Auth::require('edit_others_posts');
        Network::ensureInstalled();
        $a = (string)($_POST['do'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $note = trim(strip_tags((string)($_POST['note'] ?? '')));
        $msg = 'Done.';
        switch ($a) {
            case 'claim_approve':
                $err = Network::approveClaim($id, Auth::id(), $note);
                $msg = $err ?? 'Claim approved. The owner can now manage the profile.';
                break;
            case 'claim_reject':
                Network::rejectClaim($id, Auth::id(), $note);
                $msg = 'Claim rejected.';
                break;
            case 'owner_remove':
                Network::removeOwner($id);
                $msg = 'Owner removed.';
                break;
            case 'edit_approve':
                Network::approveEdit($id, Auth::id());
                $msg = 'Changes published.';
                break;
            case 'edit_reject':
                Network::rejectEdit($id, Auth::id(), $note);
                $msg = 'Changes rejected.';
                break;
            case 'booking_status':
                DB::update('ln_bookings', ['status' => in_array($_POST['status'] ?? '', ['new', 'contacted', 'confirmed', 'declined', 'spam'], true) ? $_POST['status'] : 'new'], ['id' => $id]);
                break;
            case 'featured_activate':
                Network::activateFeatured($id, $note);
                $msg = 'Placement activated.';
                break;
            case 'featured_cancel':
                DB::update('ln_featured', ['status' => 'cancelled'], ['id' => $id]);
                PageCache::clear();
                $msg = 'Placement cancelled.';
                break;
            case 'featured_add':
                Auth::require('manage_settings');
                $plan = Network::plan((string)($_POST['plan'] ?? ''));
                $pid = (int)($_POST['post_id'] ?? 0);
                if ($plan && Network::profile($pid)) {
                    $fid = DB::insert('ln_featured', ['post_id' => $pid, 'term_id' => (int)($_POST['term_id'] ?? 0), 'user_id' => (int)(Network::owner($pid)['user_id'] ?? 0),
                        'plan' => $plan['id'], 'days' => (int)$plan['days'], 'amount' => 0, 'currency' => $plan['currency'], 'status' => 'pending', 'provider' => 'manual',
                        'payment_ref' => 'admin', 'created_at' => now()]);
                    Network::activateFeatured($fid, 'admin');
                    $msg = 'Profile featured.';
                } else {
                    $msg = 'Pick a published profile and a plan.';
                }
                break;
            case 'settings':
                Auth::require('manage_settings');
                Network::saveSettings($_POST);
                $msg = 'Settings saved.';
                break;
            case 'reindex':
                $msg = 'Indexed dates of ' . Network::reindexAll() . ' profiles.';
                break;
        }
        Session::flash(str_contains($msg, 'already') || str_contains($msg, 'Pick') ? 'error' : 'success', $msg);
        back();
    });
});

// ---------------------------------------------------------------- index dates for "On this day"
Hooks::add('post_saved', static function (int $id): void {
    $p = Posts::find($id);
    if ($p && $p['type'] === Network::get('post_type')) {
        Network::ensureInstalled();
        Network::indexPost($id);
    }
});
Hooks::add('post_deleted', static function (int $id): void {
    if (Options::get('ln_installed')) {
        DB::delete('ln_dates', ['post_id' => $id]);
    }
});

// ---------------------------------------------------------------- SEO for module pages
Hooks::add('seo_description', static fn(string $d, array $c): string => $c['ln_description'] ?? $d);
Hooks::add('seo_robots', static fn(string $r, array $c): string => !empty($c['ln_noindex']) ? 'noindex, follow' : $r);

// ---------------------------------------------------------------- frontend routes
Hooks::add('init', static function (): void {
    $path = '/' . trim(App::path(), '/') . '/';
    $otd = '/' . Network::get('otd_slug') . '/';
    if (!preg_match('#^/(account|claim|book|ln)/#', $path) && !str_starts_with($path, $otd)) {
        return;
    }
    Network::ensureInstalled();
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    $seg = explode('/', trim($path, '/'));

    if (str_starts_with($path, $otd)) {
        ln_on_this_day(array_slice(explode('/', trim(substr($path, strlen($otd)), '/')), 0, 2));
    }
    if ($seg[0] === 'ln' && ($seg[1] ?? '') === 'webhook' && $post) {
        Payments::webhook((string)($seg[2] ?? ''));
    }
    if ($seg[0] === 'book' && isset($seg[1]) && ctype_digit($seg[1])) {
        ln_booking_submit((int)$seg[1]);
    }
    if ($seg[0] === 'claim' && isset($seg[1]) && ctype_digit($seg[1])) {
        ln_claim((int)$seg[1], $post);
    }
    if ($seg[0] === 'account') {
        $sub = $seg[1] ?? '';
        match (true) {
            $sub === 'login' => ln_login($post),
            $sub === 'register' => ln_register($post),
            $sub === 'logout' => ln_logout(),
            $sub === 'forgot' => ln_forgot($post),
            $sub === 'reset' => ln_reset($post),
            $sub === 'verify' => ln_verify(),
            $sub === 'profile' && isset($seg[2]) && ctype_digit($seg[2]) => ln_profile_edit((int)$seg[2], $post),
            $sub === 'featured' && ($seg[2] ?? '') === 'return' => ln_featured_return(),
            $sub === 'featured' && isset($seg[2]) && ctype_digit($seg[2]) => ln_featured((int)$seg[2], $post),
            $sub === '' => ln_dashboard($post),
            default => null,
        };
    }
}, 5);

// ---------------------------------------------------------------- inject into profile & category pages
Hooks::add('html_output', static function (string $html): string {
    if (!Network::get('auto_inject') || !Options::get('ln_installed')) {
        return $html;
    }
    $kind = page_data('kind');
    $p = post();
    if ($kind === 'single' && Network::isProfile($p) && !str_contains($html, 'data-ln-actions')) {
        $block = ln_render_partial('profile-actions', ['p' => $p]);
        return ln_insert_before_footer($html, $block);
    }
    $term = page_data('term');
    if ($kind === 'taxonomy' && $term && $term['taxonomy'] === Network::get('category_tax') && Network::get('featured') && !str_contains($html, 'data-ln-featured')) {
        $ids = Network::featuredIds((int)$term['id']);
        if ($ids) {
            $block = ln_render_partial('featured-strip', ['ids' => $ids, 'term' => $term]);
            return ln_insert_after_header($html, $block);
        }
    }
    return $html;
});

function ln_insert_before_footer(string $html, string $block): string
{
    foreach (['<footer data-elementor-type="footer"', '<div data-elementor-type="footer"', '<footer'] as $needle) {
        $pos = strripos($html, $needle);
        if ($pos !== false) {
            return substr_replace($html, $block, $pos, 0);
        }
    }
    return str_ireplace('</body>', $block . '</body>', $html);
}

function ln_insert_after_header(string $html, string $block): string
{
    // After the site header (Elementor header template or a <header> element), else at the top of <main>, else after <body>.
    $body = stripos($html, '<body');
    $end = $body === false ? false : stripos($html, '</header>', $body);
    if ($end !== false) {
        return substr_replace($html, $block, $end + 9, 0);
    }
    foreach (['<main', '<body'] as $tag) {
        $m = stripos($html, $tag);
        if ($m !== false) {
            return substr_replace($html, $block, strpos($html, '>', $m) + 1, 0);
        }
    }
    return $html;
}

// ---------------------------------------------------------------- rendering helpers

function ln_render_partial(string $view, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/views/front/' . $view . '.php';
    return (string)ob_get_clean();
}

/** Render a full page with the site's header & footer. */
function ln_page(string $title, string $view, array $vars = [], array $seo = [], bool $cache = false): never
{
    Seo::$ctx = ['kind' => 'archive', 'title' => $title, 'url' => $seo['url'] ?? site_url(App::path()), 'ln_description' => $seo['description'] ?? '',
        'ln_noindex' => $seo['noindex'] ?? true] + ($seo['ctx'] ?? []);
    Frontend::$data = array_merge(Frontend::$data, ['kind' => 'ln-' . $view, 'title' => $title]);
    if (!$cache) {
        header('Cache-Control: no-store, private');
    }
    $body = ln_render_partial($view, $vars);
    ob_start();
    partial('header');
    echo '<link rel="stylesheet" href="' . e(url('/modules/leader-network/assets/ln.css')) . '?v=1">';
    echo '<main id="content" class="ln-page ln-' . e($view) . '">' . $body . '</main>';
    partial('footer');
    $html = (string)ob_get_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    if ($cache) {
        PageCache::store($html);
    }
    exit;
}

function ln_user(): ?array
{
    return Auth::user();
}

function ln_require_login(): array
{
    $u = Auth::user();
    if (!$u) {
        redirect(url('/account/login/') . '?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/account/'));
    }
    return $u;
}

function ln_safe_next(string $next): string
{
    return preg_match('#^/(?!/)[^\s]*$#', $next) ? $next : url('/account/');
}

function ln_flash_html(): string
{
    Session::start();
    $out = '';
    foreach (Session::takeFlash() as $f) {
        $out .= '<div class="ln-alert ln-alert-' . e($f['type']) . '" role="status">' . e($f['message']) . '</div>';
    }
    return $out;
}

function ln_check_csrf(): void
{
    Session::start();
    if (!Csrf::valid($_POST['_csrf'] ?? null)) {
        Session::flash('error', 'Your session expired. Please try again.');
        back();
    }
}

// ---------------------------------------------------------------- accounts

function ln_login(bool $post): never
{
    if (Auth::user()) {
        redirect(url('/account/'));
    }
    Session::start();
    $error = '';
    $needs2fa = false;
    if ($post) {
        ln_check_csrf();
        $r = Auth::attempt(trim((string)($_POST['login'] ?? '')), (string)($_POST['password'] ?? ''), $_POST['code'] ?? null);
        if ($r['ok']) {
            redirect(ln_safe_next((string)($_POST['next'] ?? '')));
        }
        $error = $r['error'] ?? '';
        $needs2fa = !empty($r['needs_2fa']);
    }
    ln_page('Sign in', 'login', ['error' => $error, 'needs2fa' => $needs2fa, 'next' => (string)($_GET['next'] ?? $_POST['next'] ?? '')]);
}

function ln_register(bool $post): never
{
    if (Auth::user()) {
        redirect(url('/account/'));
    }
    if (!Network::get('registration')) {
        ln_page('Registration closed', 'message', ['heading' => 'Registration is closed', 'text' => 'New accounts are not being accepted right now.']);
    }
    Session::start();
    $errors = [];
    $old = ['name' => '', 'email' => ''];
    if ($post) {
        ln_check_csrf();
        $name = trim(strip_tags((string)($_POST['name'] ?? '')));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $pass = (string)($_POST['password'] ?? '');
        $old = ['name' => $name, 'email' => $email];
        if (!empty($_POST['website'])) {
            $errors[] = 'Please try again.';
        }
        if (mb_strlen($name) < 2) {
            $errors[] = 'Enter your full name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($p = Auth::passwordProblems($pass)) {
            $errors[] = $p;
        }
        if (empty($_POST['terms'])) {
            $errors[] = 'Please accept the terms.';
        }
        if (!$errors && DB::val('SELECT id FROM {{users}} WHERE email = :e', ['e' => $email])) {
            $errors[] = 'An account with this email already exists. Sign in or reset your password.';
        }
        if (!$errors && !Network::throttle('register', 5, 3600)) {
            $errors[] = 'Too many sign-ups from your network. Try again later.';
        }
        if (!$errors) {
            $base = substr(preg_replace('/[^a-z0-9]+/', '', strtolower(strstr($email, '@', true))), 0, 40) ?: 'member';
            $username = $base;
            for ($i = 2; DB::val('SELECT id FROM {{users}} WHERE username = :u', ['u' => $username]); $i++) {
                $username = $base . $i;
            }
            $uid = DB::insert('users', ['username' => $username, 'email' => $email, 'password' => Auth::hash($pass), 'display_name' => mb_substr($name, 0, 100),
                'role' => 'subscriber', 'status' => 'active', 'meta' => json_encode(['ln_member' => 1, 'email_verified' => 0]), 'created_at' => now()]);
            ln_send_verification($uid, $email, $name);
            Auth::loginUser(DB::row('SELECT * FROM {{users}} WHERE id = :id', ['id' => $uid]));
            Session::flash('success', 'Welcome! We sent a confirmation link to ' . $email . '.');
            redirect(ln_safe_next((string)($_POST['next'] ?? '')));
        }
    }
    ln_page('Create an account', 'register', ['errors' => $errors, 'old' => $old, 'next' => (string)($_GET['next'] ?? $_POST['next'] ?? '')]);
}

function ln_send_verification(int $uid, string $email, string $name): void
{
    $token = Network::sign('verify', $uid . ':' . $email, 86400 * 3);
    Network::mail($email, 'Confirm your email address', "Hi $name,\n\nPlease confirm your email address:\n" . site_url('/account/verify/?t=' . rawurlencode($token)) . "\n\nThe link is valid for 3 days.");
}

function ln_verify(): never
{
    $data = Network::verify('verify', (string)($_GET['t'] ?? ''));
    Session::start();
    if ($data && preg_match('/^(\d+):(.+)$/', $data, $m) && ($u = DB::row('SELECT * FROM {{users}} WHERE id = :id AND email = :e', ['id' => (int)$m[1], 'e' => $m[2]]))) {
        $meta = json_decode((string)$u['meta'], true) ?: [];
        $meta['email_verified'] = 1;
        DB::update('users', ['meta' => json_encode($meta)], ['id' => (int)$u['id']]);
        Session::flash('success', 'Thanks — your email address is confirmed.');
    } else {
        Session::flash('error', 'That confirmation link is invalid or expired.');
    }
    redirect(url('/account/'));
}

function ln_logout(): never
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        ln_check_csrf();
        Auth::logout();
        setcookie(Session::FLAG, '', ['expires' => time() - 3600, 'path' => '/']);
    }
    redirect(url('/'));
}

function ln_forgot(bool $post): never
{
    Session::start();
    $sent = false;
    if ($post) {
        ln_check_csrf();
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (Network::throttle('forgot', 5, 3600) && ($u = DB::row("SELECT * FROM {{users}} WHERE email = :e AND status = 'active'", ['e' => $email]))) {
            // Bound to the current password hash, so the link dies once used.
            $token = Network::sign('reset', $u['id'] . ':' . substr(hash('sha256', (string)$u['password']), 0, 16), 3600);
            Network::mail($email, 'Reset your password', "Hi {$u['display_name']},\n\nReset your password here (valid for 1 hour):\n" . site_url('/account/reset/?t=' . rawurlencode($token))
                . "\n\nIf you did not ask for this, ignore this email.");
        }
        $sent = true;
    }
    ln_page('Reset your password', 'forgot', ['sent' => $sent]);
}

function ln_reset(bool $post): never
{
    Session::start();
    $token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
    $data = Network::verify('reset', $token);
    $u = null;
    if ($data && preg_match('/^(\d+):([a-f0-9]{16})$/', $data, $m)) {
        $u = DB::row('SELECT * FROM {{users}} WHERE id = :id', ['id' => (int)$m[1]]);
        if ($u && substr(hash('sha256', (string)$u['password']), 0, 16) !== $m[2]) {
            $u = null;
        }
    }
    if (!$u) {
        ln_page('Link expired', 'message', ['heading' => 'This link is invalid or expired', 'text' => 'Request a new password reset link.', 'link' => [url('/account/forgot/'), 'Reset password']]);
    }
    $error = '';
    if ($post) {
        ln_check_csrf();
        $pass = (string)($_POST['password'] ?? '');
        if ($p = Auth::passwordProblems($pass)) {
            $error = $p;
        } else {
            DB::update('users', ['password' => Auth::hash($pass)], ['id' => (int)$u['id']]);
            Session::flash('success', 'Your password was changed. Sign in with the new password.');
            redirect(url('/account/login/'));
        }
    }
    ln_page('Choose a new password', 'reset', ['token' => $token, 'error' => $error]);
}

// ---------------------------------------------------------------- dashboard

function ln_dashboard(bool $post): never
{
    $u = ln_require_login();
    if ($post) {
        ln_check_csrf();
        if (($_POST['do'] ?? '') === 'resend') {
            if (Network::throttle('verify', 3, 3600)) {
                ln_send_verification((int)$u['id'], $u['email'], $u['display_name']);
            }
            Session::flash('success', 'We sent a new confirmation link.');
        }
        if (($_POST['do'] ?? '') === 'name') {
            $name = trim(strip_tags((string)($_POST['name'] ?? '')));
            if (mb_strlen($name) >= 2) {
                DB::update('users', ['display_name' => mb_substr($name, 0, 100)], ['id' => (int)$u['id']]);
                Session::flash('success', 'Saved.');
            }
        }
        redirect(url('/account/'));
    }
    $owned = Network::ownedBy((int)$u['id']);
    Posts::prime($owned);
    $claims = DB::all('SELECT * FROM {{ln_claims}} WHERE user_id = :u ORDER BY id DESC LIMIT 20', ['u' => (int)$u['id']]);
    $bookings = $owned ? DB::all('SELECT * FROM {{ln_bookings}} WHERE post_id IN (' . implode(',', array_map('intval', $owned)) . ") AND status <> 'spam' ORDER BY id DESC LIMIT 50") : [];
    $featured = DB::all('SELECT * FROM {{ln_featured}} WHERE user_id = :u ORDER BY id DESC LIMIT 20', ['u' => (int)$u['id']]);
    ln_page('Your account', 'dashboard', compact('u', 'owned', 'claims', 'bookings', 'featured'));
}

// ---------------------------------------------------------------- claim

function ln_claim(int $id, bool $post): never
{
    $p = Network::profile($id);
    if (!$p || !Network::get('claims')) {
        Frontend::notFound();
        exit;
    }
    if (!Auth::user()) {
        Session::start();
        ln_page('Claim ' . $p['title'], 'claim-intro', ['p' => $p]);
    }
    $u = Auth::user();
    $owner = Network::owner($id);
    $pending = Network::pendingClaim($id, (int)$u['id']);
    $errors = [];
    if ($post && !$owner && !$pending) {
        ln_check_csrf();
        $in = ['relation' => (string)($_POST['relation'] ?? ''), 'full_name' => trim(strip_tags((string)($_POST['full_name'] ?? ''))),
            'phone' => trim(preg_replace('/[^\d+()\s-]/', '', (string)($_POST['phone'] ?? ''))), 'proof_url' => trim((string)($_POST['proof_url'] ?? '')),
            'message' => mb_substr(trim(strip_tags((string)($_POST['message'] ?? ''))), 0, 3000)];
        if (!in_array($in['relation'], ['self', 'team', 'agency', 'family', 'estate'], true)) {
            $errors[] = 'Tell us how you are connected to this profile.';
        }
        if (mb_strlen($in['full_name']) < 2) {
            $errors[] = 'Enter your full name.';
        }
        if ($in['proof_url'] === '' || !filter_var($in['proof_url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $in['proof_url'])) {
            $errors[] = 'Add a link we can use to verify you (official website, verified social profile, company page…).';
        }
        if (empty($_POST['confirm'])) {
            $errors[] = 'Please confirm that the information is true.';
        }
        if (!$errors && !Network::throttle('claim', 5, 86400)) {
            $errors[] = 'Too many claims today. Try again tomorrow.';
        }
        if (!$errors) {
            DB::insert('ln_claims', $in + ['post_id' => $id, 'user_id' => (int)$u['id'], 'status' => 'pending', 'created_at' => now()]);
            Network::notifyAdmin('New profile claim: ' . $p['title'], "{$in['full_name']} ({$u['email']}) claims “{$p['title']}” as: {$in['relation']}\nProof: {$in['proof_url']}\n\n{$in['message']}\n\nReview: " . admin_url('m/leader-network'));
            Session::flash('success', 'Thanks! Your claim was sent. We usually verify claims within 2 working days and will email you.');
            redirect(url('/account/'));
        }
    }
    ln_page('Claim ' . $p['title'], 'claim', compact('p', 'u', 'owner', 'pending', 'errors'));
}

// ---------------------------------------------------------------- owner editing

function ln_profile_edit(int $id, bool $post): never
{
    $u = ln_require_login();
    $p = Network::profile($id);
    if (!$p || !Network::isOwner($id, (int)$u['id'])) {
        http_response_code(403);
        ln_page('Not allowed', 'message', ['heading' => 'You do not manage this profile', 'text' => 'Claim the profile first; once our team verifies it you can edit it here.']);
    }
    $owner = Network::owner($id);
    if ($post) {
        ln_check_csrf();
        if (($_POST['do'] ?? '') === 'booking') {
            $email = filter_var(trim((string)($_POST['booking_email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
            DB::update('ln_owners', ['booking_enabled' => !empty($_POST['booking_enabled']) ? 1 : 0, 'booking_email' => $email,
                'booking_note' => mb_substr(trim(strip_tags((string)($_POST['booking_note'] ?? ''))), 0, 1000)], ['post_id' => $id]);
            PageCache::clear();
            Session::flash('success', !empty($_POST['booking_enabled']) ? 'Booking requests are on. Visitors now see a “Book” form on your profile.' : 'Booking requests are off.');
        } else {
            $r = Network::submitEdit($id, (int)$u['id'], (array)($_POST['fields'] ?? []));
            Session::flash('success', match ($r) {
                'pending' => 'Thanks! Your changes were sent for a quick review and will be live soon.',
                'published' => 'Your changes are live.',
                default => 'Nothing to save.',
            });
        }
        redirect(url('/account/profile/' . $id . '/'));
    }
    $pendingEdit = DB::row("SELECT * FROM {{ln_edits}} WHERE post_id = :p AND status = 'pending' ORDER BY id DESC LIMIT 1", ['p' => $id]);
    $values = $p['fields'] ?? [];
    if ($pendingEdit) {
        $values = array_merge($values, (json_decode((string)$pendingEdit['data'], true)['fields'] ?? []));
    }
    ln_page('Manage ' . $p['title'], 'profile-edit', ['p' => $p, 'owner' => $owner, 'fields' => Network::editableFields(), 'values' => $values, 'pendingEdit' => $pendingEdit]);
}

// ---------------------------------------------------------------- booking requests

function ln_booking_submit(int $id): never
{
    $p = Network::profile($id);
    $owner = $p ? Network::owner($id) : null;
    $enabled = $p && Network::get('bookings') && (!Network::get('booking_needs_claim') || ($owner && $owner['booking_enabled']));
    $wantsJson = is_ajax() || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json');
    $fail = static function (string $msg) use ($wantsJson, $p): never {
        if ($wantsJson) {
            json_response(['ok' => false, 'error' => $msg], 422);
        }
        Session::start();
        Session::flash('error', $msg);
        redirect($p ? Posts::permalink($p) . '#ln-book' : url('/'));
    };
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$enabled) {
        $fail('Booking requests are not available for this profile.');
    }
    // Spam protection: honeypot, minimum fill time (set by the page's script) and per-IP limits.
    $ts = (int)($_POST['ln_ts'] ?? 0);
    if (!empty($_POST['website']) || $ts === 0 || (time() * 1000 - $ts) < 3000) {
        $wantsJson ? json_response(['ok' => true, 'message' => 'Thank you!']) : redirect(Posts::permalink($p));
    }
    $in = [];
    foreach (['name' => 191, 'email' => 191, 'phone' => 40, 'organization' => 191, 'event_type' => 80, 'event_date' => 20, 'location' => 191, 'audience' => 40, 'budget' => 80] as $k => $max) {
        $in[$k] = mb_substr(trim(strip_tags((string)($_POST[$k] ?? ''))), 0, $max);
    }
    $in['message'] = mb_substr(trim(strip_tags((string)($_POST['message'] ?? ''))), 0, 4000);
    if (mb_strlen($in['name']) < 2 || !filter_var($in['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($in['message']) < 10) {
        $fail('Please add your name, a valid email and a short description of the event.');
    }
    if (!Network::throttle('booking', 5, 3600)) {
        $fail('Too many requests from your network. Please try again later.');
    }
    DB::insert('ln_bookings', $in + ['post_id' => $id, 'status' => 'new', 'ip' => Auth::ip(), 'created_at' => now()]);
    $body = "New booking request for “{$p['title']}”\n\n";
    foreach ($in as $k => $v) {
        if ($v !== '') {
            $body .= ucfirst(str_replace('_', ' ', $k)) . ": $v\n";
        }
    }
    $to = $owner ? (($owner['booking_email'] ?: (string)DB::val('SELECT email FROM {{users}} WHERE id = :id', ['id' => (int)$owner['user_id']]))) : '';
    if ($to) {
        Network::mail($to, 'Booking request: ' . ($in['event_type'] ?: 'new event'), $body . "\nManage requests: " . site_url('/account/'), $in['email']);
    }
    Network::notifyAdmin('Booking request for ' . $p['title'], $body);
    if ($wantsJson) {
        json_response(['ok' => true, 'message' => 'Thank you! Your request was sent' . ($owner ? ' to ' . $p['title'] . '’s team' : '') . '. Expect a reply by email.']);
    }
    Session::start();
    Session::flash('success', 'Thank you! Your request was sent.');
    redirect(Posts::permalink($p));
}

// ---------------------------------------------------------------- featured placements

function ln_featured(int $id, bool $post): never
{
    $u = ln_require_login();
    $p = Network::profile($id);
    if (!$p || !Network::isOwner($id, (int)$u['id']) || !Network::get('featured')) {
        http_response_code(403);
        ln_page('Not allowed', 'message', ['heading' => 'Only verified owners can feature a profile', 'text' => 'Claim the profile first.']);
    }
    $terms = \Slate\Terms::forPosts([$id])[$id][Network::get('category_tax')] ?? [];
    $error = '';
    if ($post) {
        ln_check_csrf();
        $plan = Network::plan((string)($_POST['plan'] ?? ''));
        $termId = (int)($_POST['term_id'] ?? 0);
        if (!$plan || !in_array($termId, array_map(static fn($t) => (int)$t['id'], $terms), true)) {
            $error = 'Choose a category and a plan.';
        } else {
            $fid = DB::insert('ln_featured', ['post_id' => $id, 'term_id' => $termId, 'user_id' => (int)$u['id'], 'plan' => $plan['id'], 'days' => (int)$plan['days'],
                'amount' => (int)$plan['price'], 'currency' => $plan['currency'], 'status' => 'pending', 'provider' => (string)Network::get('payment_provider'), 'created_at' => now()]);
            $order = DB::row('SELECT * FROM {{ln_featured}} WHERE id = :id', ['id' => $fid]);
            $r = Payments::checkout($order, $u);
            if (!empty($r['url'])) {
                redirect($r['url']);
            }
            if (!empty($r['manual'])) {
                Network::notifyAdmin('Featured placement requested', "{$u['display_name']} ({$u['email']}) requested “{$plan['name']}” for “{$p['title']}”.\nAmount: "
                    . Network::money((int)$plan['price'], $plan['currency']) . "\n\nActivate after payment: " . admin_url('m/leader-network?tab=featured'));
                Session::flash('success', 'Request received. ' . Network::get('manual_instructions'));
                redirect(url('/account/'));
            }
            DB::update('ln_featured', ['status' => 'failed'], ['id' => $fid]);
            $error = $r['error'] ?? 'Payment could not be started.';
        }
    }
    ln_page('Feature ' . $p['title'], 'featured', ['p' => $p, 'terms' => $terms, 'plans' => (array)Network::get('featured_plans'), 'error' => $error]);
}

function ln_featured_return(): never
{
    $u = ln_require_login();
    $order = DB::row('SELECT * FROM {{ln_featured}} WHERE id = :id AND user_id = :u', ['id' => (int)($_GET['order'] ?? 0), 'u' => (int)$u['id']]);
    Session::start();
    if ($order && Payments::confirmReturn($order, $_GET)) {
        Session::flash('success', 'Payment received — your featured placement is live.');
    } else {
        Session::flash('error', 'We could not confirm the payment yet. If you were charged, it will activate automatically within a few minutes, or contact us.');
    }
    redirect(url('/account/'));
}

// ---------------------------------------------------------------- On this day

function ln_on_this_day(array $parts): never
{
    $tz = new DateTimeZone((string)option('timezone', 'UTC'));
    $today = new DateTimeImmutable('now', $tz);
    $date = $today;
    $role = null;
    $months = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
    $first = (string)($parts[0] ?? '');
    if ($first !== '' && preg_match('/^(' . implode('|', $months) . ')-(\d{1,2})$/', $first, $m)) {
        $mo = array_search($m[1], $months, true) + 1;
        if (!checkdate($mo, (int)$m[2], 2024)) {
            Frontend::notFound();
            exit;
        }
        $date = new DateTimeImmutable(sprintf('2024-%02d-%02d', $mo, (int)$m[2]), $tz);
        $first = (string)($parts[1] ?? '');
    } elseif ($first !== '' && isset($parts[1])) {
        Frontend::notFound();
        exit;
    }
    if ($first !== '') {
        $role = Terms::findBySlug((string)Network::get('category_tax'), $first);
        if (!$role) {
            Frontend::notFound();
            exit;
        }
    }
    $md = $date->format('m-d');
    $rows = Network::onThisDay($md, $role);
    $isToday = $md === $today->format('m-d');
    $label = $date->format('F j');
    $title = ($isToday ? 'On this day, ' : 'On ') . $label . ($role ? ': ' . $role['name'] : '');
    $base = '/' . Network::get('otd_slug') . '/';
    $canonical = site_url($base . ($isToday && !($parts[0] ?? '') ? '' : strtolower($date->format('F')) . '-' . (int)$date->format('j') . '/') . ($role ? $role['slug'] . '/' : ''));
    $counts = ['birth' => 0, 'death' => 0, 'event' => 0];
    foreach ($rows as $r) {
        $counts[$r['kind']]++;
    }
    $desc = $rows
        ? sprintf('%s: %d leader birthdays, %d anniversaries and %d milestones from history%s.', $label, $counts['birth'], $counts['death'], $counts['event'], $role ? ' in ' . $role['name'] : '')
        : 'Birthdays, anniversaries and milestones of leaders on ' . $label . '.';
    ln_page($title, 'on-this-day', compact('rows', 'date', 'today', 'role', 'isToday', 'label', 'base', 'counts', 'md'),
        ['url' => $canonical, 'description' => $desc, 'noindex' => !$rows], (bool)($parts[0] ?? false));
}
