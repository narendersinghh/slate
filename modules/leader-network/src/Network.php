<?php
declare(strict_types=1);

namespace SlateModules\LeaderNetwork;

use Slate\ContentModel;
use Slate\Crypto;
use Slate\DB;
use Slate\Options;
use Slate\PageCache;
use Slate\Posts;
use Slate\Terms;

/** Data layer: settings, owners, claims, edits, bookings, featured placements and the dates index. */
final class Network
{
    public const DEFAULTS = [
        'post_type' => 'leaders',
        'category_tax' => 'role',
        'country_tax' => 'nationality',
        'birth_field' => 'birth_date',
        'death_field' => 'death_date',
        'dates_field' => 'key_dates',        // repeater: date + event (+ source)
        'owner_fields' => ['tagline', 'summary', 'impact_summary', 'knows_about', 'official_website', 'social_links', 'videos', 'awards', 'books', 'booking_topics'],
        'moderate_edits' => true,
        'auto_inject' => true,
        'bookings' => true,
        'booking_needs_claim' => true,
        'claims' => true,
        'registration' => true,
        'featured' => true,
        'featured_plans' => [
            ['id' => 'month', 'name' => '30 days', 'days' => 30, 'price' => 999, 'currency' => 'INR'],
            ['id' => 'quarter', 'name' => '90 days', 'days' => 90, 'price' => 2499, 'currency' => 'INR'],
        ],
        'featured_slots' => 6,
        'payment_provider' => 'manual',      // manual | stripe | razorpay
        'manual_instructions' => 'Our team will email you payment details (UPI / bank transfer). Your placement goes live as soon as the payment is confirmed.',
        'stripe_secret' => '',
        'stripe_webhook_secret' => '',
        'razorpay_key_id' => '',
        'razorpay_key_secret' => '',
        'razorpay_webhook_secret' => '',
        'notify_email' => '',
        'otd_slug' => 'on-this-day',
    ];

    private static ?array $settings = null;
    private static array $owners = [];

    // ------------------------------------------------------------------ setup

    public static function install(): void
    {
        $sqlite = DB::$driver === 'sqlite';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        DB::exec("CREATE TABLE IF NOT EXISTS {{ln_owners}} (
            post_id INT NOT NULL PRIMARY KEY, user_id INT NOT NULL, since DATETIME NOT NULL,
            booking_enabled TINYINT NOT NULL DEFAULT 0, booking_email VARCHAR(191) NOT NULL DEFAULT '', booking_note TEXT NULL)$tail");
        DB::exec("CREATE TABLE IF NOT EXISTS {{ln_claims}} (
            id $id, post_id INT NOT NULL, user_id INT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending',
            relation VARCHAR(40) NOT NULL DEFAULT '', full_name VARCHAR(191) NOT NULL DEFAULT '', phone VARCHAR(40) NOT NULL DEFAULT '',
            proof_url VARCHAR(500) NOT NULL DEFAULT '', message TEXT NULL, admin_note TEXT NULL,
            created_at DATETIME NOT NULL, decided_at DATETIME NULL, decided_by INT NULL)$tail");
        DB::exec("CREATE TABLE IF NOT EXISTS {{ln_edits}} (
            id $id, post_id INT NOT NULL, user_id INT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending',
            data MEDIUMTEXT NULL, admin_note TEXT NULL, created_at DATETIME NOT NULL, decided_at DATETIME NULL, decided_by INT NULL)$tail");
        DB::exec("CREATE TABLE IF NOT EXISTS {{ln_bookings}} (
            id $id, post_id INT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'new', name VARCHAR(191) NOT NULL DEFAULT '',
            email VARCHAR(191) NOT NULL DEFAULT '', phone VARCHAR(40) NOT NULL DEFAULT '', organization VARCHAR(191) NOT NULL DEFAULT '',
            event_type VARCHAR(80) NOT NULL DEFAULT '', event_date VARCHAR(20) NOT NULL DEFAULT '', location VARCHAR(191) NOT NULL DEFAULT '',
            audience VARCHAR(40) NOT NULL DEFAULT '', budget VARCHAR(80) NOT NULL DEFAULT '', message TEXT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '', created_at DATETIME NOT NULL)$tail");
        DB::exec("CREATE TABLE IF NOT EXISTS {{ln_featured}} (
            id $id, post_id INT NOT NULL, term_id INT NOT NULL DEFAULT 0, user_id INT NOT NULL DEFAULT 0, plan VARCHAR(40) NOT NULL DEFAULT '',
            days INT NOT NULL DEFAULT 30, amount INT NOT NULL DEFAULT 0, currency VARCHAR(8) NOT NULL DEFAULT 'INR',
            status VARCHAR(20) NOT NULL DEFAULT 'pending', provider VARCHAR(20) NOT NULL DEFAULT 'manual', payment_ref VARCHAR(191) NOT NULL DEFAULT '',
            starts_at DATETIME NULL, ends_at DATETIME NULL, created_at DATETIME NOT NULL)$tail");
        DB::exec("CREATE TABLE IF NOT EXISTS {{ln_dates}} (
            id $id, post_id INT NOT NULL, md CHAR(5) NOT NULL, year INT NULL, kind VARCHAR(10) NOT NULL, label VARCHAR(500) NOT NULL DEFAULT '',
            source VARCHAR(500) NOT NULL DEFAULT '')$tail");
        DB::exec("CREATE TABLE IF NOT EXISTS {{ln_events}} (
            id $id, kind VARCHAR(20) NOT NULL, ip VARCHAR(45) NOT NULL, created_at DATETIME NOT NULL)$tail");
        foreach ([
            'CREATE INDEX {{ln_dates}}_md ON {{ln_dates}} (md)',
            'CREATE INDEX {{ln_dates}}_post ON {{ln_dates}} (post_id)',
            'CREATE INDEX {{ln_claims}}_post ON {{ln_claims}} (post_id, status)',
            'CREATE INDEX {{ln_featured}}_live ON {{ln_featured}} (status, term_id, ends_at)',
            'CREATE INDEX {{ln_bookings}}_post ON {{ln_bookings}} (post_id)',
            'CREATE INDEX {{ln_events}}_ip ON {{ln_events}} (ip, kind, created_at)',
        ] as $sql) {
            try {
                DB::exec($sql);
            } catch (\Throwable) {
                // index exists
            }
        }
        Options::set('ln_installed', 1);
    }

    public static function ensureInstalled(): void
    {
        if (!Options::get('ln_installed')) {
            self::install();
        }
    }

    // ------------------------------------------------------------------ settings

    public static function settings(): array
    {
        if (self::$settings === null) {
            $s = Options::get('ln_settings');
            self::$settings = (is_array($s) ? $s : []) + self::DEFAULTS;
        }
        return self::$settings;
    }

    public static function get(string $key): mixed
    {
        return self::settings()[$key] ?? null;
    }

    public static function secret(string $key): string
    {
        $v = (string)self::get($key);
        return $v === '' ? '' : (string)Crypto::decrypt($v);
    }

    public static function saveSettings(array $in): void
    {
        $s = self::settings();
        foreach (['post_type', 'category_tax', 'country_tax', 'birth_field', 'death_field', 'dates_field', 'payment_provider', 'notify_email', 'otd_slug'] as $k) {
            if (isset($in[$k])) {
                $s[$k] = trim(strip_tags((string)$in[$k]));
            }
        }
        $s['otd_slug'] = trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower($s['otd_slug'])), '-') ?: 'on-this-day';
        foreach (['moderate_edits', 'auto_inject', 'bookings', 'booking_needs_claim', 'claims', 'registration', 'featured'] as $k) {
            $s[$k] = !empty($in[$k]);
        }
        $s['owner_fields'] = array_values(array_filter(array_map('trim', (array)($in['owner_fields'] ?? []))));
        $s['featured_slots'] = max(1, min(48, (int)($in['featured_slots'] ?? 6)));
        $s['manual_instructions'] = trim(strip_tags((string)($in['manual_instructions'] ?? $s['manual_instructions'])));
        $plans = [];
        foreach ((array)($in['plans'] ?? []) as $p) {
            $name = trim(strip_tags((string)($p['name'] ?? '')));
            $days = (int)($p['days'] ?? 0);
            if ($name !== '' && $days > 0) {
                $plans[] = ['id' => substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), 0, 30) ?: 'plan' . count($plans), 'name' => $name, 'days' => $days,
                    'price' => max(0, (int)($p['price'] ?? 0)), 'currency' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($p['currency'] ?? 'INR')), 0, 3)) ?: 'INR'];
            }
        }
        if ($plans) {
            $s['featured_plans'] = $plans;
        }
        // Secrets: only replaced when a new value is typed; stored encrypted.
        foreach (['stripe_secret', 'stripe_webhook_secret', 'razorpay_key_secret', 'razorpay_webhook_secret'] as $k) {
            $v = trim((string)($in[$k] ?? ''));
            if ($v !== '') {
                $s[$k] = Crypto::encrypt($v);
            }
            if (!empty($in[$k . '_clear'])) {
                $s[$k] = '';
            }
        }
        $s['razorpay_key_id'] = trim((string)($in['razorpay_key_id'] ?? $s['razorpay_key_id']));
        Options::set('ln_settings', $s);
        self::$settings = null;
        PageCache::clear();
    }

    public static function plan(string $id): ?array
    {
        foreach ((array)self::get('featured_plans') as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ helpers

    public static function isProfile(?array $post): bool
    {
        return $post && $post['type'] === self::get('post_type') && $post['status'] === 'publish';
    }

    public static function profile(int $id): ?array
    {
        $p = Posts::find($id);
        return self::isProfile($p) ? $p : null;
    }

    public static function money(int $amount, string $currency): string
    {
        $sym = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'][$currency] ?? $currency . ' ';
        return $sym . number_format($amount);
    }

    /** Rate limit by IP: true when the action is still allowed (and records it). */
    public static function throttle(string $kind, int $max, int $seconds): bool
    {
        $ip = \Slate\Auth::ip();
        $n = (int)DB::val('SELECT COUNT(*) FROM {{ln_events}} WHERE ip = :ip AND kind = :k AND created_at > :t',
            ['ip' => $ip, 'k' => $kind, 't' => gmdate('Y-m-d H:i:s', time() - $seconds)]);
        if ($n >= $max) {
            return false;
        }
        DB::insert('ln_events', ['kind' => $kind, 'ip' => $ip, 'created_at' => now()]);
        if (random_int(1, 50) === 1) {
            DB::query('DELETE FROM {{ln_events}} WHERE created_at < :t', ['t' => gmdate('Y-m-d H:i:s', time() - 86400 * 2)]);
        }
        return true;
    }

    /** HMAC token bound to a purpose (password reset, email verification). */
    public static function sign(string $purpose, string $data, int $ttl): string
    {
        $exp = time() + $ttl;
        $payload = $purpose . '|' . $data . '|' . $exp;
        return rtrim(strtr(base64_encode($data . '|' . $exp), '+/', '-_'), '=') . '.' . substr(hash_hmac('sha256', $payload, self::key()), 0, 40);
    }

    public static function verify(string $purpose, string $token): ?string
    {
        [$b, $mac] = array_pad(explode('.', $token, 2), 2, '');
        $raw = base64_decode(strtr($b, '-_', '+/'), true);
        if (!$raw || !str_contains($raw, '|')) {
            return null;
        }
        $pos = strrpos($raw, '|');
        $data = substr($raw, 0, $pos);
        $exp = (int)substr($raw, $pos + 1);
        if ($exp < time() || !hash_equals(substr(hash_hmac('sha256', $purpose . '|' . $data . '|' . $exp, self::key()), 0, 40), $mac)) {
            return null;
        }
        return $data;
    }

    private static function key(): string
    {
        $k = (string)Options::get('ln_key');
        if ($k === '') {
            $k = bin2hex(random_bytes(32));
            Options::set('ln_key', $k);
        }
        return $k;
    }

    public static function mail(string $to, string $subject, string $body, string $replyTo = ''): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $host = (string)parse_url(site_url(), PHP_URL_HOST);
        $headers = 'From: ' . preg_replace('/[\r\n]/', '', (string)Options::get('site_title', 'Slate')) . ' <noreply@' . $host . ">\r\nContent-Type: text/plain; charset=utf-8";
        if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers .= "\r\nReply-To: " . $replyTo;
        }
        return @mail($to, '=?UTF-8?B?' . base64_encode('[' . Options::get('site_title', 'Slate') . '] ' . $subject) . '?=', $body, $headers);
    }

    public static function notifyAdmin(string $subject, string $body): void
    {
        $to = (string)self::get('notify_email') ?: (string)Options::get('admin_email');
        self::mail($to, $subject, $body);
    }

    // ------------------------------------------------------------------ owners & claims

    public static function owner(int $postId): ?array
    {
        if (!array_key_exists($postId, self::$owners)) {
            self::$owners[$postId] = DB::row('SELECT * FROM {{ln_owners}} WHERE post_id = :p', ['p' => $postId]);
        }
        return self::$owners[$postId];
    }

    public static function ownedBy(int $userId): array
    {
        return DB::col('SELECT post_id FROM {{ln_owners}} WHERE user_id = :u ORDER BY since', ['u' => $userId]);
    }

    public static function isOwner(int $postId, int $userId): bool
    {
        $o = self::owner($postId);
        return $o && $userId && (int)$o['user_id'] === $userId;
    }

    public static function pendingClaim(int $postId, int $userId): ?array
    {
        return DB::row("SELECT * FROM {{ln_claims}} WHERE post_id = :p AND user_id = :u AND status = 'pending'", ['p' => $postId, 'u' => $userId]);
    }

    public static function approveClaim(int $claimId, int $adminId, string $note = ''): ?string
    {
        $c = DB::row('SELECT * FROM {{ln_claims}} WHERE id = :id', ['id' => $claimId]);
        if (!$c || $c['status'] !== 'pending') {
            return 'This claim was already handled.';
        }
        if (self::owner((int)$c['post_id'])) {
            return 'The profile already has a verified owner. Remove the current owner first.';
        }
        DB::insert('ln_owners', ['post_id' => (int)$c['post_id'], 'user_id' => (int)$c['user_id'], 'since' => now(), 'booking_enabled' => 0, 'booking_email' => '', 'booking_note' => '']);
        DB::update('ln_claims', ['status' => 'approved', 'admin_note' => $note, 'decided_at' => now(), 'decided_by' => $adminId], ['id' => $claimId]);
        DB::query("UPDATE {{ln_claims}} SET status = 'rejected', admin_note = 'Another claim was approved.', decided_at = :t, decided_by = :a WHERE post_id = :p AND status = 'pending'",
            ['t' => now(), 'a' => $adminId, 'p' => (int)$c['post_id']]);
        unset(self::$owners[(int)$c['post_id']]);
        PageCache::clear();
        $u = DB::row('SELECT email, display_name FROM {{users}} WHERE id = :id', ['id' => (int)$c['user_id']]);
        $p = Posts::find((int)$c['post_id']);
        if ($u && $p) {
            self::mail($u['email'], 'Your profile claim was approved',
                "Hi {$u['display_name']},\n\nYour claim for “{$p['title']}” was approved. You can now update the profile, turn on booking requests and get featured:\n\n"
                . site_url('/account/') . "\n\n" . ($note ? "Note from our team: $note\n\n" : '') . 'Thank you!');
        }
        return null;
    }

    public static function rejectClaim(int $claimId, int $adminId, string $note = ''): void
    {
        $c = DB::row('SELECT * FROM {{ln_claims}} WHERE id = :id', ['id' => $claimId]);
        if (!$c || $c['status'] !== 'pending') {
            return;
        }
        DB::update('ln_claims', ['status' => 'rejected', 'admin_note' => $note, 'decided_at' => now(), 'decided_by' => $adminId], ['id' => $claimId]);
        $u = DB::row('SELECT email, display_name FROM {{users}} WHERE id = :id', ['id' => (int)$c['user_id']]);
        $p = Posts::find((int)$c['post_id']);
        if ($u && $p) {
            self::mail($u['email'], 'Update on your profile claim',
                "Hi {$u['display_name']},\n\nWe could not verify your claim for “{$p['title']}”." . ($note ? "\n\nReason: $note" : '')
                . "\n\nYou can submit a new claim with additional proof (for example an official email address or a link to a verified social account):\n" . site_url('/claim/' . $p['id'] . '/'));
        }
    }

    public static function removeOwner(int $postId): void
    {
        DB::delete('ln_owners', ['post_id' => $postId]);
        unset(self::$owners[$postId]);
        PageCache::clear();
    }

    // ------------------------------------------------------------------ owner edits

    /** Fields the owner may change, with their definitions from the content model. */
    public static function editableFields(): array
    {
        $all = [];
        foreach (ContentModel::fieldsFor('post', (string)self::get('post_type')) as $f) {
            $all[$f['name']] = $f;
        }
        $out = [];
        foreach ((array)self::get('owner_fields') as $name) {
            if (isset($all[$name]) && in_array($all[$name]['type'], ['text', 'textarea', 'richtext', 'url', 'email', 'number', 'date', 'repeater', 'select'], true)) {
                $out[$name] = $all[$name];
            }
        }
        return $out;
    }

    /** Clean submitted values against the field definitions. */
    public static function cleanFields(array $in): array
    {
        $out = [];
        foreach (self::editableFields() as $name => $f) {
            if (!array_key_exists($name, $in)) {
                continue;
            }
            $out[$name] = self::cleanValue($f, $in[$name]);
        }
        return $out;
    }

    private static function cleanValue(array $f, mixed $v): mixed
    {
        switch ($f['type']) {
            case 'repeater':
                $rows = [];
                foreach (array_slice((array)$v, 0, 50) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $clean = [];
                    foreach ((array)($f['sub_fields'] ?? []) as $sf) {
                        $clean[$sf['name']] = self::cleanValue($sf, $row[$sf['name']] ?? '');
                    }
                    if (implode('', array_map(static fn($x) => is_array($x) ? json_encode($x) : (string)$x, $clean)) !== '') {
                        $rows[] = $clean;
                    }
                }
                return $rows;
            case 'richtext':
                $v = mb_substr(trim((string)$v), 0, 20000);
                if ($v !== '' && strip_tags($v) === $v) {
                    // Plain text from the owner editor: keep paragraphs and line breaks.
                    $paras = array_filter(array_map('trim', preg_split("/\R{2,}/", $v)));
                    $v = implode('', array_map(static fn($x) => '<p>' . nl2br(e($x), false) . '</p>', $paras));
                }
                return \Slate\Sanitizer::html($v);
            case 'url':
                $u = trim((string)$v);
                return $u !== '' && preg_match('#^https?://#i', $u) && filter_var($u, FILTER_VALIDATE_URL) ? mb_substr($u, 0, 500) : '';
            case 'email':
                return filter_var(trim((string)$v), FILTER_VALIDATE_EMAIL) ?: '';
            case 'number':
                return is_numeric($v) ? $v + 0 : '';
            case 'date':
                return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
            case 'select':
                $opts = array_map(static fn($o) => is_array($o) ? (string)($o['value'] ?? '') : (string)$o, (array)($f['options'] ?? []));
                return in_array((string)$v, $opts, true) ? (string)$v : '';
            case 'textarea':
                return mb_substr(trim(strip_tags((string)$v)), 0, 5000);
            default:
                return mb_substr(trim(strip_tags((string)$v)), 0, 500);
        }
    }

    public static function submitEdit(int $postId, int $userId, array $fields): string
    {
        $fields = self::cleanFields($fields);
        if (!$fields) {
            return 'nothing';
        }
        if (!self::get('moderate_edits')) {
            Posts::save(['fields' => $fields], $postId);
            return 'published';
        }
        DB::query("DELETE FROM {{ln_edits}} WHERE post_id = :p AND user_id = :u AND status = 'pending'", ['p' => $postId, 'u' => $userId]);
        DB::insert('ln_edits', ['post_id' => $postId, 'user_id' => $userId, 'status' => 'pending',
            'data' => json_encode(['fields' => $fields], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'created_at' => now()]);
        $p = Posts::find($postId);
        self::notifyAdmin('Profile update waiting for review', 'An owner submitted changes to “' . ($p['title'] ?? '#' . $postId) . "”.\n\nReview: " . admin_url('m/leader-network?tab=edits'));
        return 'pending';
    }

    public static function approveEdit(int $editId, int $adminId): void
    {
        $e = DB::row('SELECT * FROM {{ln_edits}} WHERE id = :id', ['id' => $editId]);
        if (!$e || $e['status'] !== 'pending') {
            return;
        }
        $data = json_decode((string)$e['data'], true) ?: [];
        if (!empty($data['fields'])) {
            Posts::save(['fields' => $data['fields']], (int)$e['post_id']);
        }
        DB::update('ln_edits', ['status' => 'approved', 'decided_at' => now(), 'decided_by' => $adminId], ['id' => $editId]);
        $u = DB::row('SELECT email, display_name FROM {{users}} WHERE id = :id', ['id' => (int)$e['user_id']]);
        $p = Posts::find((int)$e['post_id']);
        if ($u && $p) {
            self::mail($u['email'], 'Your profile update is live', "Hi {$u['display_name']},\n\nYour changes to “{$p['title']}” are now live:\n" . Posts::permalink($p, true));
        }
    }

    public static function rejectEdit(int $editId, int $adminId, string $note): void
    {
        $e = DB::row('SELECT * FROM {{ln_edits}} WHERE id = :id', ['id' => $editId]);
        if (!$e || $e['status'] !== 'pending') {
            return;
        }
        DB::update('ln_edits', ['status' => 'rejected', 'admin_note' => $note, 'decided_at' => now(), 'decided_by' => $adminId], ['id' => $editId]);
        $u = DB::row('SELECT email, display_name FROM {{users}} WHERE id = :id', ['id' => (int)$e['user_id']]);
        $p = Posts::find((int)$e['post_id']);
        if ($u && $p) {
            self::mail($u['email'], 'About your profile update', "Hi {$u['display_name']},\n\nWe could not publish your changes to “{$p['title']}”." . ($note ? "\n\nReason: $note" : ''));
        }
    }

    // ------------------------------------------------------------------ featured placements

    /** Live featured placements for a category term (or all when 0). @return int[] post ids */
    public static function featuredIds(int $termId = 0, int $limit = 0): array
    {
        $limit = $limit ?: (int)self::get('featured_slots');
        $sql = "SELECT post_id FROM {{ln_featured}} WHERE status = 'active' AND ends_at > :now" . ($termId ? ' AND term_id = :t' : '') . ' ORDER BY starts_at ASC LIMIT ' . (int)$limit;
        $params = ['now' => now()] + ($termId ? ['t' => $termId] : []);
        return array_values(array_unique(array_map('intval', DB::col($sql, $params))));
    }

    public static function isFeatured(int $postId): array
    {
        return DB::col("SELECT term_id FROM {{ln_featured}} WHERE post_id = :p AND status = 'active' AND ends_at > :now", ['p' => $postId, 'now' => now()]);
    }

    public static function activateFeatured(int $id, string $ref = ''): void
    {
        $f = DB::row('SELECT * FROM {{ln_featured}} WHERE id = :id', ['id' => $id]);
        if (!$f || $f['status'] === 'active') {
            return;
        }
        // Extend from the end of an existing live placement in the same category.
        $cur = DB::val("SELECT MAX(ends_at) FROM {{ln_featured}} WHERE post_id = :p AND term_id = :t AND status = 'active' AND ends_at > :now",
            ['p' => (int)$f['post_id'], 't' => (int)$f['term_id'], 'now' => now()]);
        $start = $cur ?: now();
        $end = gmdate('Y-m-d H:i:s', strtotime($start . ' UTC') + 86400 * max(1, (int)$f['days']));
        DB::update('ln_featured', ['status' => 'active', 'starts_at' => now(), 'ends_at' => $end, 'payment_ref' => $ref ?: (string)$f['payment_ref']], ['id' => $id]);
        PageCache::clear();
        $u = DB::row('SELECT email, display_name FROM {{users}} WHERE id = :id', ['id' => (int)$f['user_id']]);
        $p = Posts::find((int)$f['post_id']);
        $t = $f['term_id'] ? Terms::find((int)$f['term_id']) : null;
        if ($u && $p) {
            self::mail($u['email'], 'Your featured placement is live',
                "Hi {$u['display_name']},\n\n“{$p['title']}” is now featured" . ($t ? " in {$t['name']}" : '') . ' until ' . substr($end, 0, 10) . ".\n\n" . Posts::permalink($p, true));
        }
    }

    public static function expireFeatured(): void
    {
        DB::query("UPDATE {{ln_featured}} SET status = 'expired' WHERE status = 'active' AND ends_at <= :now", ['now' => now()]);
    }

    // ------------------------------------------------------------------ "On this day" index

    /** Rebuild the dates index for one profile (birth, death and key dates). */
    public static function indexPost(int $postId): void
    {
        DB::delete('ln_dates', ['post_id' => $postId]);
        $p = Posts::find($postId);
        if (!self::isProfile($p)) {
            return;
        }
        $f = $p['fields'] ?? [];
        $add = static function (string $date, string $kind, string $label, string $source = '') use ($postId): void {
            $d = self::parseDate($date);
            if ($d) {
                DB::insert('ln_dates', ['post_id' => $postId, 'md' => $d['md'], 'year' => $d['year'], 'kind' => $kind,
                    'label' => mb_substr($label, 0, 500), 'source' => mb_substr($source, 0, 500)]);
            }
        };
        $add((string)($f[self::get('birth_field')] ?? ''), 'birth', '');
        $add((string)($f[self::get('death_field')] ?? ''), 'death', '');
        foreach ((array)($f[self::get('dates_field')] ?? []) as $row) {
            if (is_array($row)) {
                $add((string)($row['date'] ?? ''), 'event', trim(strip_tags((string)($row['event'] ?? ''))), (string)($row['source'] ?? ''));
            }
        }
    }

    public static function reindexAll(): int
    {
        DB::exec('DELETE FROM {{ln_dates}}');
        $ids = DB::col("SELECT id FROM {{posts}} WHERE type = :t AND status = 'publish'", ['t' => self::get('post_type')]);
        foreach (array_chunk($ids, 200) as $chunk) {
            Posts::prime($chunk);
            foreach ($chunk as $id) {
                self::indexPost((int)$id);
            }
        }
        return count($ids);
    }

    /** Full dates only (YYYY-MM-DD, YYYYMMDD or "5 November 1968"); partial dates are skipped. */
    public static function parseDate(string $s): ?array
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(-?\d{1,4})-(\d{2})-(\d{2})/', $s, $m) || preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) {
            [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } else {
            $ts = strtotime($s . ' 00:00 UTC');
            if ($ts === false || !preg_match('/\b\d{1,2}\b/', $s)) {
                return null;
            }
            [$y, $mo, $d] = [(int)gmdate('Y', $ts), (int)gmdate('n', $ts), (int)gmdate('j', $ts)];
        }
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
            return null;
        }
        return ['md' => sprintf('%02d-%02d', $mo, $d), 'year' => $y ?: null];
    }

    /** Entries for a month-day, optionally limited to a category (and its children). */
    public static function onThisDay(string $md, ?array $term = null): array
    {
        $rows = DB::all('SELECT d.* FROM {{ln_dates}} d JOIN {{posts}} p ON p.id = d.post_id WHERE d.md = :md AND p.status = :s ORDER BY d.year ASC', ['md' => $md, 's' => 'publish']);
        if ($term && $rows) {
            $ids = [(int)$term['id']];
            foreach (Terms::all((string)$term['taxonomy']) as $t) {
                if ((int)($t['parent_id'] ?? 0) === (int)$term['id']) {
                    $ids[] = (int)$t['id'];
                }
            }
            [$in, $params] = DB::in(array_column($rows, 'post_id'), 'p');
            [$tin, $tparams] = DB::in($ids, 't');
            $keep = array_flip(array_map('intval', DB::col("SELECT DISTINCT post_id FROM {{term_relationships}} WHERE post_id IN ($in) AND term_id IN ($tin)", $params + $tparams)));
            $rows = array_values(array_filter($rows, static fn($r) => isset($keep[(int)$r['post_id']])));
        }
        Posts::prime(array_column($rows, 'post_id'));
        return $rows;
    }
}
