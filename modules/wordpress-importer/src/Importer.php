<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

use Slate\ContentModel;
use Slate\Crypto;
use Slate\DB;
use Slate\Media;
use Slate\Menus;
use Slate\Options;
use Slate\PageCache;
use Slate\Posts;
use Slate\Terms;
use SlateModules\AutoCpt\Generator;
use SlateModules\AutoCpt\WpClient;
use SlateModules\AutoCpt\Wxr;

/**
 * Batch importer: WordPress (REST API or WXR export) → Slate.
 * State lives in the lazily-loaded option "wpi_job"; id/url mappings in the {{wpi_map}} table.
 */
final class Importer
{
    public const WORK = SLATE_CACHE . '/wpi';
    private const BUDGET = 18; // seconds per HTTP request
    private float $t0;
    private array $job;
    private ?WpClient $wp = null;
    private ?array $wxr = null;

    // ------------------------------------------------------------------ setup

    public static function install(): void
    {
        $sqlite = DB::$driver === 'sqlite';
        $pk = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        DB::exec("CREATE TABLE IF NOT EXISTS {{wpi_map}} (
            kind VARCHAR(20) NOT NULL, old_id VARCHAR(64) NOT NULL, new_id INT NOT NULL DEFAULT 0,
            old_url VARCHAR(700) NOT NULL DEFAULT '', extra TEXT NULL, PRIMARY KEY (kind, old_id))$pk");
        DB::exec("CREATE TABLE IF NOT EXISTS {{wpi_forms}} (
            id " . ($sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY') . ",
            form VARCHAR(191) NOT NULL DEFAULT '', page VARCHAR(255) NOT NULL DEFAULT '', data TEXT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '', is_read TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL)$pk");
        if (!is_dir(self::WORK)) {
            @mkdir(self::WORK, 0755, true);
        }
    }

    public static function job(): ?array
    {
        $j = Options::get('wpi_job');
        return is_array($j) ? $j : null;
    }

    private static function store(array $job): void
    {
        Options::set('wpi_job', $job, false);
    }

    public static function client(array $conn): WpClient
    {
        return new WpClient((string)$conn['url'], (string)($conn['user'] ?? ''), Crypto::decrypt((string)($conn['pass'] ?? '')));
    }

    /** Analyse the source and return what will be imported. */
    public static function scan(array $conn): array
    {
        self::install();
        if (($conn['source'] ?? 'rest') === 'wxr') {
            $wxr = self::loadWxr();
            $spec = Generator::fromWxr($wxr);
            $summary = ['site' => $wxr['site']['title'], 'url' => $wxr['site']['url'], 'auth' => false, 'media' => count($wxr['media']),
                'menus' => count($wxr['menus']), 'elementor' => (bool)array_filter($wxr['posts'], static fn($p) => $p['elementor']),
                'seo' => (bool)array_filter($wxr['posts'], static fn($p) => $p['seo']), 'acf' => (bool)array_filter($wxr['posts'], static fn($p) => $p['fields'])];
        } else {
            $wp = self::client($conn);
            $root = $wp->get('/');
            if (!$root['ok']) {
                throw new \RuntimeException('Could not reach the WordPress REST API at ' . $wp->site . ' — ' . $root['error']);
            }
            $spec = Generator::fromWordPress($wp);
            $media = $wp->get('/wp/v2/media', ['per_page' => 1]);
            $me = $wp->authenticated() ? $wp->get('/wp/v2/users/me', ['context' => 'edit']) : ['ok' => false];
            if ($wp->authenticated() && !$me['ok']) {
                throw new \RuntimeException('WordPress rejected the username / application password: ' . $me['error']);
            }
            $menus = $wp->authenticated() ? $wp->get('/wp/v2/menus', ['per_page' => 100]) : ['ok' => false, 'data' => []];
            $sample = $wp->get('/wp/v2/pages', ['per_page' => 3]);
            $first = $sample['ok'] && is_array($sample['data']) ? ($sample['data'][0] ?? []) : [];
            $summary = ['site' => html_entity_decode((string)($root['data']['name'] ?? ''), ENT_QUOTES), 'url' => $wp->site,
                'auth' => $me['ok'] ?? false, 'user' => $me['data']['name'] ?? null, 'media' => $media['total'],
                'menus' => $menus['ok'] && is_array($menus['data']) ? count($menus['data']) : null,
                'elementor' => str_contains(json_encode($sample['data'] ?? ''), 'elementor'),
                'seo' => isset($first['yoast_head_json']) ? 'Yoast SEO' : (str_contains(json_encode($root['data']['namespaces'] ?? []), 'rankmath') ? 'Rank Math' : false),
                'acf' => isset($first['acf']), 'namespaces' => $root['data']['namespaces'] ?? []];
        }
        $counts = [];
        foreach ($spec['wp_map']['types'] as $wpSlug => $m) {
            $counts['types'][$wpSlug] = ['slug' => $m['slug'], 'total' => $m['total'] ?? 0];
        }
        foreach ($spec['wp_map']['taxonomies'] as $wpSlug => $m) {
            $counts['taxonomies'][$wpSlug] = ['slug' => $m['slug'], 'total' => $m['total'] ?? null];
        }
        return ['summary' => $summary, 'spec' => $spec, 'counts' => $counts];
    }

    public static function loadWxr(): array
    {
        $cache = self::WORK . '/wxr.json';
        if (is_file($cache)) {
            return json_decode((string)file_get_contents($cache), true);
        }
        $file = self::WORK . '/export.xml';
        $data = Wxr::parse($file);
        file_put_contents($cache, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $data;
    }

    public static function start(array $conn, array $scan, array $opts): array
    {
        self::install();
        $opts += ['types' => [], 'model' => true, 'media' => true, 'seo' => true, 'menus' => true, 'settings' => true,
            'redirects' => true, 'design' => true, 'activate_theme' => true, 'drafts' => true];
        $steps = [['cleanup']];
        if ($opts['settings']) {
            $steps[] = ['settings'];
        }
        if ($opts['model']) {
            $steps[] = ['model'];
        }
        if ($opts['design']) {
            $steps[] = ['design'];
        }
        foreach ($scan['spec']['wp_map']['taxonomies'] as $wpTax => $m) {
            if (($m['total'] ?? 1) !== 0) {
                $steps[] = ['terms', $wpTax];
            }
        }
        if ($opts['media']) {
            $steps[] = ['media'];
        }
        foreach ($scan['spec']['wp_map']['types'] as $wpType => $m) {
            if (!$opts['types'] || in_array($wpType, $opts['types'], true)) {
                $steps[] = ['posts', $wpType];
            }
        }
        if ($opts['menus']) {
            $steps[] = ['menus'];
        }
        $steps[] = ['finalize'];
        $total = 0;
        foreach ($scan['counts']['types'] ?? [] as $wt => $c) {
            if (!$opts['types'] || in_array($wt, $opts['types'], true)) {
                $total += (int)$c['total'];
            }
        }
        $total += $opts['media'] ? (int)($scan['summary']['media'] ?? 0) : 0;
        $job = ['conn' => $conn, 'spec' => $scan['spec'], 'opts' => $opts, 'steps' => $steps, 'i' => 0, 'page' => 1,
            'total' => max(1, $total), 'done' => 0, 'stats' => [], 'log' => [], 'wp_settings' => [], 'started_at' => now(), 'finished_at' => null,
            'origin' => rtrim((string)($scan['summary']['url'] ?? $conn['url'] ?? ''), '/')];
        Options::set('wpi_origin', $job['origin']);
        self::store($job);
        return $job;
    }

    public static function reset(bool $deleteImported = false): void
    {
        self::install();
        if ($deleteImported) {
            foreach (DB::all("SELECT new_id FROM {{wpi_map}} WHERE kind = 'post'") as $r) {
                Posts::delete((int)$r['new_id'], true);
            }
            foreach (DB::all("SELECT new_id FROM {{wpi_map}} WHERE kind = 'media'") as $r) {
                Media::delete((int)$r['new_id']);
            }
            foreach (DB::all("SELECT new_id FROM {{wpi_map}} WHERE kind = 'term'") as $r) {
                Terms::delete((int)$r['new_id']);
            }
        }
        DB::exec('DELETE FROM {{wpi_map}}');
        Options::delete('wpi_job');
        foreach (glob(self::WORK . '/*') ?: [] as $f) {
            is_file($f) && !str_ends_with($f, 'export.xml') && @unlink($f);
        }
    }

    // ------------------------------------------------------------------ run

    /** Run steps until the time budget is used. */
    public static function step(): array
    {
        $job = self::job();
        if (!$job) {
            throw new \RuntimeException('No import is running.');
        }
        @set_time_limit(120);
        $self = new self($job);
        return $self->run();
    }

    private function __construct(array $job)
    {
        $this->job = $job;
        $this->t0 = microtime(true);
    }

    private function run(): array
    {
        while ($this->job['i'] < count($this->job['steps']) && microtime(true) - $this->t0 < self::BUDGET) {
            [$name, $arg] = $this->job['steps'][$this->job['i']] + [1 => null];
            try {
                $finished = match ($name) {
                    'cleanup' => $this->stepCleanup(),
                    'settings' => $this->stepSettings(),
                    'model' => $this->stepModel(),
                    'design' => $this->stepDesign(),
                    'terms' => $this->stepTerms((string)$arg),
                    'media' => $this->stepMedia(),
                    'posts' => $this->stepPosts((string)$arg),
                    'menus' => $this->stepMenus(),
                    'finalize' => $this->stepFinalize(),
                    default => true,
                };
            } catch (\Throwable $e) {
                $this->log('⚠ ' . $name . ($arg ? " ($arg)" : '') . ': ' . $e->getMessage());
                $finished = true;
            }
            if ($finished) {
                $this->job['i']++;
                $this->job['page'] = 1;
            }
            self::store($this->job);
        }
        $complete = $this->job['i'] >= count($this->job['steps']);
        if ($complete && !$this->job['finished_at']) {
            $this->job['finished_at'] = now();
            self::store($this->job);
            log_activity('wp_import_finished', $this->job['origin']);
        }
        $cur = $this->job['steps'][$this->job['i']] ?? ['done'];
        return ['done' => $complete, 'progress' => $complete ? 100 : min(99, (int)round(($this->job['done'] / $this->job['total']) * 90 + $this->job['i'] / count($this->job['steps']) * 10)),
            'step' => $this->label($cur), 'stats' => $this->job['stats'], 'log' => array_slice($this->job['log'], -40)];
    }

    private function label(array $step): string
    {
        return match ($step[0]) {
            'cleanup' => 'Removing Slate sample content', 'settings' => 'Reading site settings', 'model' => 'Creating post types, taxonomies & fields', 'design' => 'Mirroring theme design',
            'terms' => 'Importing ' . ($step[1] ?? '') . ' terms', 'media' => 'Importing & optimizing media (page ' . $this->job['page'] . ')',
            'posts' => 'Importing ' . ($step[1] ?? '') . ' (page ' . $this->job['page'] . ')', 'menus' => 'Importing menus',
            'finalize' => 'Linking parents, front page, permalinks & redirects', default => 'Done',
        };
    }

    private function log(string $msg): void
    {
        $this->job['log'][] = gmdate('H:i:s') . '  ' . $msg;
        if (count($this->job['log']) > 300) {
            $this->job['log'] = array_slice($this->job['log'], -300);
        }
    }

    private function stat(string $k, int $n = 1): void
    {
        $this->job['stats'][$k] = ($this->job['stats'][$k] ?? 0) + $n;
    }

    private function isWxr(): bool
    {
        return ($this->job['conn']['source'] ?? 'rest') === 'wxr';
    }

    private function wp(): WpClient
    {
        return $this->wp ??= self::client($this->job['conn'] + ['url' => $this->job['origin']]);
    }

    private function wxr(): array
    {
        return $this->wxr ??= self::loadWxr();
    }

    // ------------------------------------------------------------------ mapping

    public static function map(string $kind, string|int $oldId): ?int
    {
        $v = DB::val('SELECT new_id FROM {{wpi_map}} WHERE kind = :k AND old_id = :o', ['k' => $kind, 'o' => (string)$oldId]);
        return $v === null ? null : (int)$v;
    }

    private static function setMap(string $kind, string|int $oldId, int $newId, string $url = '', array $extra = []): void
    {
        DB::query('DELETE FROM {{wpi_map}} WHERE kind = :k AND old_id = :o', ['k' => $kind, 'o' => (string)$oldId]);
        DB::insert('wpi_map', ['kind' => $kind, 'old_id' => (string)$oldId, 'new_id' => $newId, 'old_url' => mb_substr($url, 0, 700),
            'extra' => $extra ? json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null]);
    }

    /** Map an old upload URL (any size) to the new media id. */
    public static function mediaByUrl(string $url): ?int
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return null;
        }
        $id = self::map('media_url', md5($path));
        if ($id !== null) {
            return $id;
        }
        // "image-300x200.jpg" / "image-scaled.jpg" → "image.jpg"
        $orig = preg_replace('/-(\d+x\d+|scaled|rotated|e\d{10,})(?=\.\w+$)/', '', $path);
        return $orig !== $path ? self::map('media_url', md5((string)$orig)) : null;
    }

    private function slateType(string $wpType): ?string
    {
        $slug = $this->job['spec']['wp_map']['types'][$wpType]['slug'] ?? null;
        return $slug && ContentModel::type($slug) ? $slug : null;
    }

    private function slateTax(string $wpTax): ?string
    {
        $slug = $this->job['spec']['wp_map']['taxonomies'][$wpTax]['slug'] ?? null;
        return $slug && ContentModel::taxonomy($slug) ? $slug : null;
    }

    // ------------------------------------------------------------------ steps

    /** Remove Slate's untouched sample content so WordPress URLs (/, /about/, /blog/…) are free. */
    private function stepCleanup(): bool
    {
        $samples = [
            ['page', 'home', 'This is your homepage'], ['page', 'about', 'Tell your visitors who you are'],
            ['page', 'contact', 'How can people reach you?'], ['page', 'blog', ''], ['post', 'hello-world', 'This is your first post'],
        ];
        $n = 0;
        foreach ($samples as [$type, $slug, $marker]) {
            $p = Posts::findBySlug($type, $slug, false);
            if (!$p || self::isMapped((int)$p['id'])) {
                continue;
            }
            $content = trim(strip_tags((string)$p['content']));
            if (($marker === '' && $content === '') || ($marker !== '' && str_contains($content, $marker))) {
                Posts::delete((int)$p['id'], true);
                $n++;
            }
        }
        $menus = Menus::all();
        if (isset($menus['main']) && $menus['main']['name'] === 'Main menu' && $this->job['opts']['menus']) {
            unset($menus['main']);
            Menus::save(array_values($menus));
        }
        $this->log($n ? "Removed $n untouched Slate sample item(s)." : 'No sample content to remove.');
        return true;
    }

    private static function isMapped(int $newId): bool
    {
        return (bool)DB::val("SELECT 1 FROM {{wpi_map}} WHERE kind = 'post' AND new_id = :id", ['id' => $newId]);
    }

    private function stepSettings(): bool
    {
        if ($this->isWxr()) {
            $s = $this->wxr()['site'];
            $this->job['wp_settings'] = ['title' => $s['title'], 'description' => $s['description']];
        } else {
            $r = $this->wp()->get('/wp/v2/settings');
            $root = $this->wp()->get('/');
            $set = $r['ok'] && is_array($r['data']) ? $r['data'] : [];
            $set += ['title' => html_entity_decode((string)($root['data']['name'] ?? ''), ENT_QUOTES),
                'description' => html_entity_decode((string)($root['data']['description'] ?? ''), ENT_QUOTES),
                'site_logo' => $root['data']['site_logo'] ?? null, 'site_icon' => $root['data']['site_icon'] ?? null,
                'timezone_string' => $root['data']['timezone_string'] ?? null];
            $this->job['wp_settings'] = $set;
        }
        $s = $this->job['wp_settings'];
        if (!empty($s['title'])) {
            Options::set('site_title', html_entity_decode((string)$s['title'], ENT_QUOTES));
        }
        if (isset($s['description'])) {
            Options::set('tagline', html_entity_decode((string)$s['description'], ENT_QUOTES));
        }
        $tz = $s['timezone'] ?? $s['timezone_string'] ?? '';
        if ($tz && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            Options::set('timezone', $tz);
        }
        if (!empty($s['date_format'])) {
            Options::set('date_format', $s['date_format']);
        }
        if (!empty($s['time_format'])) {
            Options::set('time_format', $s['time_format']);
        }
        if (!empty($s['posts_per_page'])) {
            Options::set('posts_per_page', max(1, min(100, (int)$s['posts_per_page'])));
        }
        $this->log('Site settings imported: ' . ($s['title'] ?? ''));
        return true;
    }

    private function stepModel(): bool
    {
        foreach (Generator::apply($this->job['spec']) as $line) {
            $this->log($line);
        }
        return true;
    }

    private function stepDesign(): bool
    {
        $url = $this->job['origin'] . '/';
        $html = $this->isWxr() ? (new WpClient($this->job['origin']))->html($url) : $this->wp()->html($url);
        if (!$html) {
            $this->log('⚠ Could not fetch the homepage to mirror the design.');
            return true;
        }
        $res = (new Mirror($this->job['origin']))->captureSite($html);
        $this->log("Design mirrored: {$res['css']} stylesheets, {$res['js']} scripts, header " . ($res['header'] ? '✓' : '✕') . ', footer ' . ($res['footer'] ? '✓' : '✕'));
        $ds = \Slate\DesignSystem::fromElementorCss(Mirror::globalCss());
        \Slate\DesignSystem::save($ds);
        $this->log('Design system updated from the site: ' . count($ds['colors']) . ' colours, fonts “' . $ds['fonts']['heading'] . '” / “' . $ds['fonts']['body'] . '”.');
        if ($this->job['opts']['activate_theme']) {
            Options::set('theme', 'wp-mirror');
            $this->log('Activated the “WordPress mirror” theme.');
        }
        return true;
    }

    private function stepTerms(string $wpTax): bool
    {
        $tax = $this->slateTax($wpTax);
        if (!$tax) {
            return true;
        }
        if ($this->isWxr()) {
            foreach (array_filter($this->wxr()['terms'], static fn($t) => $t['taxonomy'] === $wpTax) as $t) {
                $this->importTerm($tax, $wpTax, (string)$t['old_id'], $t['name'], $t['slug'], $t['description'], $t['parent'] ? 'slug:' . $t['parent'] : null, '', []);
            }
            return true;
        }
        $base = $this->job['spec']['wp_map']['taxonomies'][$wpTax]['rest_base'];
        $r = $this->wp()->get('/wp/v2/' . $base, ['per_page' => 100, 'page' => $this->job['page'], 'hide_empty' => 'false']);
        if (!$r['ok'] || !is_array($r['data']) || !$r['data']) {
            return true;
        }
        foreach ($r['data'] as $t) {
            $seo = isset($t['yoast_head_json']) ? $this->yoastSeo($t['yoast_head_json'], (string)($t['link'] ?? '')) : [];
            $this->importTerm($tax, $wpTax, (string)$t['id'], html_entity_decode((string)$t['name'], ENT_QUOTES), (string)$t['slug'],
                (string)($t['description'] ?? ''), !empty($t['parent']) ? 'id:' . $t['parent'] : null, (string)($t['link'] ?? ''), $seo, (array)($t['acf'] ?? []));
        }
        $this->job['page']++;
        return $this->job['page'] > max(1, $r['pages']);
    }

    private function importTerm(string $tax, string $wpTax, string $oldId, string $name, string $slug, string $desc, ?string $parentRef, string $link, array $seo, array $fields = []): void
    {
        $key = $wpTax . ':' . $oldId;
        $id = self::map('term', $key) ?? (Terms::findBySlug($tax, $slug)['id'] ?? null);
        $defs = ContentModel::fieldsFor('term', $tax);
        $id = Terms::save($tax, ['name' => $name, 'slug' => $slug, 'description' => $desc, 'seo' => $seo,
            'fields' => $defs ? ContentModel::sanitizeFields($defs, $fields, true) : []], $id);
        self::setMap('term', $key, $id, $link, ['parent' => $parentRef, 'tax' => $tax, 'wp_tax' => $wpTax, 'slug' => $slug]);
        $this->stat('terms');
    }

    private function stepMedia(): bool
    {
        if ($this->isWxr()) {
            $items = array_slice($this->wxr()['media'], ($this->job['page'] - 1) * 10, 10);
            foreach ($items as $m) {
                $this->importMedia((string)$m['old_id'], $m['url'], (string)$m['alt'], (string)$m['title'], strip_tags((string)$m['caption']), [], $m['date_gmt']);
            }
            $this->job['page']++;
            return count($items) < 10;
        }
        $r = $this->wp()->get('/wp/v2/media', ['per_page' => 10, 'page' => $this->job['page'], 'orderby' => 'id', 'order' => 'asc']);
        if (!$r['ok'] || !is_array($r['data']) || !$r['data']) {
            return true;
        }
        foreach ($r['data'] as $m) {
            $sizes = [];
            foreach ((array)($m['media_details']['sizes'] ?? []) as $sz) {
                if (!empty($sz['source_url'])) {
                    $sizes[] = $sz['source_url'];
                }
            }
            $this->importMedia((string)$m['id'], (string)$m['source_url'], (string)($m['alt_text'] ?? ''),
                html_entity_decode(strip_tags((string)($m['title']['rendered'] ?? '')), ENT_QUOTES), trim(html_entity_decode(strip_tags((string)($m['caption']['rendered'] ?? '')), ENT_QUOTES)),
                $sizes, $m['date_gmt'] ?? null);
            if (microtime(true) - $this->t0 > self::BUDGET + 8) {
                break;
            }
        }
        $this->job['page']++;
        return $this->job['page'] > max(1, $r['pages']);
    }

    private function importMedia(string $oldId, string $url, string $alt, string $title, string $caption, array $sizes, ?string $date): void
    {
        $this->job['done']++;
        if ($url === '' || self::map('media', $oldId) !== null) {
            return;
        }
        $abs = $this->wpAbs($url);
        $name = basename((string)parse_url($abs, PHP_URL_PATH));
        $name = preg_replace('/-(scaled|rotated)(?=\.\w+$)/', '', rawurldecode($name));
        $tmp = self::WORK . '/dl-' . bin2hex(random_bytes(6));
        if (!(new WpClient($this->job['origin']))->download($abs, $tmp)) {
            $this->log("⚠ Could not download $name");
            $this->stat('media_failed');
            return;
        }
        $res = Media::upload(['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)], true);
        @unlink($tmp);
        if (!$res['ok']) {
            $this->log("⚠ $name: " . $res['error']);
            $this->stat('media_failed');
            return;
        }
        $id = (int)$res['media']['id'];
        $upd = ['alt' => mb_substr($alt, 0, 255), 'title' => mb_substr($title ?: pathinfo($name, PATHINFO_FILENAME), 0, 255), 'caption' => $caption];
        if ($date) {
            $upd['created_at'] = $date;
        }
        DB::update('media', $upd, ['id' => $id]);
        self::setMap('media', $oldId, $id, $abs);
        foreach (array_unique(array_merge([$abs], $sizes)) as $u) {
            $p = (string)parse_url($this->wpAbs($u), PHP_URL_PATH);
            if ($p !== '') {
                self::setMap('media_url', md5($p), $id, $p);
            }
        }
        $this->stat('media');
    }

    private function stepPosts(string $wpType): bool
    {
        $type = $this->slateType($wpType);
        if (!$type) {
            $this->log("Skipped $wpType (no matching Slate post type).");
            return true;
        }
        if ($this->isWxr()) {
            $all = array_values(array_filter($this->wxr()['posts'], static fn($p) => $p['type'] === $wpType));
            $n = $this->batch();
            $items = array_slice($all, ($this->job['page'] - 1) * $n, $n);
            foreach ($items as $p) {
                $this->importPost($type, $wpType, $this->normalizeWxrPost($p));
            }
            $this->job['page']++;
            return count($items) < $n;
        }
        $base = $this->job['spec']['wp_map']['types'][$wpType]['rest_base'];
        $q = ['per_page' => $this->batch(), 'page' => $this->job['page'], 'orderby' => 'id', 'order' => 'asc'];
        if ($this->wp()->authenticated()) {
            $q += ['context' => 'edit', 'status' => $this->job['opts']['drafts'] ? 'publish,future,draft,pending,private' : 'publish,future'];
        }
        $r = $this->wp()->get('/wp/v2/' . $base, $q);
        if (!$r['ok'] && isset($q['context'])) {
            unset($q['context'], $q['status']);
            $r = $this->wp()->get('/wp/v2/' . $base, $q);
        }
        if (!$r['ok'] || !is_array($r['data']) || !$r['data']) {
            if (!$r['ok'] && $this->job['page'] === 1) {
                $this->log("⚠ $wpType: " . $r['error']);
            }
            return true;
        }
        foreach ($r['data'] as $p) {
            $this->importPost($type, $wpType, $this->normalizeRestPost($p, $wpType));
        }
        $this->job['page']++;
        return $this->job['page'] > max(1, $r['pages']);
    }

    /** Smaller batches when each item also fetches its rendered page (design mirror / SEO from HTML). */
    private function batch(): int
    {
        return $this->job['opts']['design'] ? 5 : 10;
    }

    private function normalizeRestPost(array $p, string $wpType): array
    {
        $terms = [];
        foreach ($this->job['spec']['wp_map']['taxonomies'] as $wpTax => $m) {
            foreach ((array)($p[$m['rest_base']] ?? []) as $tid) {
                $terms[] = ['taxonomy' => $wpTax, 'old_id' => (string)$tid];
            }
        }
        $fields = [];
        foreach (['meta', 'acf'] as $b) {
            if (!empty($p[$b]) && is_array($p[$b]) && !array_is_list($p[$b])) {
                $fields = array_merge($fields, $p[$b]);
            }
        }
        $title = $p['title']['raw'] ?? html_entity_decode(strip_tags((string)($p['title']['rendered'] ?? '')), ENT_QUOTES);
        $excerpt = $p['excerpt']['raw'] ?? null;
        if ($excerpt === null) {
            $excerpt = trim(html_entity_decode(strip_tags((string)($p['excerpt']['rendered'] ?? '')), ENT_QUOTES));
            $excerpt = preg_replace('/\s*\[(…|&hellip;|\.\.\.)\]\s*$/u', '', $excerpt); // drop WP auto-excerpts' "[…]"
        }
        return [
            'old_id' => (int)$p['id'], 'title' => (string)$title, 'slug' => rawurldecode((string)$p['slug']), 'status' => (string)($p['status'] ?? 'publish'),
            'date_gmt' => $p['date_gmt'] ?? null, 'modified_gmt' => $p['modified_gmt'] ?? null,
            'content' => (string)($p['content']['rendered'] ?? ''), 'excerpt' => (string)$excerpt,
            'parent' => (int)($p['parent'] ?? 0), 'menu_order' => (int)($p['menu_order'] ?? 0), 'link' => (string)($p['link'] ?? ''),
            'thumbnail' => (int)($p['featured_media'] ?? 0), 'template' => (string)($p['template'] ?? ''), 'terms' => $terms, 'fields' => $fields,
            'seo' => isset($p['yoast_head_json']) ? $this->yoastSeo($p['yoast_head_json'], (string)($p['link'] ?? '')) : [],
            'elementor' => str_contains((string)($p['content']['rendered'] ?? ''), 'data-elementor-type'),
        ];
    }

    private function normalizeWxrPost(array $p): array
    {
        return $p + ['modified_gmt' => null];
    }

    private function yoastSeo(array $y, string $link): array
    {
        $seo = [];
        if (!empty($y['title'])) {
            $seo['title'] = html_entity_decode((string)$y['title'], ENT_QUOTES);
        }
        if (!empty($y['description'])) {
            $seo['description'] = html_entity_decode((string)$y['description'], ENT_QUOTES);
        }
        if (($y['robots']['index'] ?? '') === 'noindex') {
            $seo['robots_index'] = 'noindex';
        }
        if (($y['robots']['follow'] ?? '') === 'nofollow') {
            $seo['nofollow'] = true;
        }
        if (!empty($y['canonical']) && rtrim((string)$y['canonical'], '/') !== rtrim($link, '/')) {
            $seo['canonical'] = (string)$y['canonical'];
        }
        if (!empty($y['og_title']) && $y['og_title'] !== ($y['title'] ?? '')) {
            $seo['og_title'] = html_entity_decode((string)$y['og_title'], ENT_QUOTES);
        }
        if (!empty($y['og_description']) && $y['og_description'] !== ($y['description'] ?? '')) {
            $seo['og_description'] = html_entity_decode((string)$y['og_description'], ENT_QUOTES);
        }
        if (!empty($y['og_image'][0]['url'])) {
            $seo['og_image_url'] = (string)$y['og_image'][0]['url'];
        }
        return $seo;
    }

    /** SEO from the rendered page head (works for Rank Math, AIOSEO, SEOPress, etc.). */
    private function seoFromHtml(string $html, string $link): array
    {
        $seo = [];
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $seo['title'] = trim(html_entity_decode($m[1], ENT_QUOTES));
        }
        if (preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)#i', $html, $m)
            || preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description#i', $html, $m)) {
            $seo['description'] = html_entity_decode($m[1], ENT_QUOTES);
        }
        if (preg_match('#<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']*)#i', $html, $m)) {
            if (str_contains($m[1], 'noindex')) {
                $seo['robots_index'] = 'noindex';
            }
            if (str_contains($m[1], 'nofollow')) {
                $seo['nofollow'] = true;
            }
        }
        if (preg_match('#<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)#i', $html, $m) && rtrim($m[1], '/') !== rtrim($link, '/')) {
            $seo['canonical'] = $m[1];
        }
        if (preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
            $seo['og_image_url'] = $m[1];
        }
        if (preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $mm)) {
            $seo['_schema_found'] = count($mm[1]);
        }
        return $seo;
    }

    private function importPost(string $type, string $wpType, array $p): void
    {
        $this->job['done']++;
        if (in_array($p['status'], ['trash', 'auto-draft', 'inherit'], true) || (!$this->job['opts']['drafts'] && $p['status'] !== 'publish' && $p['status'] !== 'future')) {
            return;
        }
        $key = $wpType . ':' . $p['old_id'];
        $existing = self::map('post', $key);
        if (!$existing && $p['slug'] !== '' && ($same = Posts::findBySlug($type, $p['slug'], false)) && !self::isMapped((int)$same['id'])) {
            $existing = (int)$same['id']; // same URL already exists in Slate → update it instead of creating "-2"
        }
        $opts = $this->job['opts'];
        $html = null;
        $needsHtml = ($opts['design'] || ($opts['seo'] && !$p['seo']) || ($this->isWxr() && $p['elementor'])) && $p['link'] !== '' && in_array($p['status'], ['publish', 'future'], true);
        if ($needsHtml) {
            $html = (new WpClient($this->job['origin']))->html($p['link']);
        }
        $content = $p['content'];
        if ($this->isWxr() && $p['elementor'] && $html) {
            $content = Mirror::extractElementor($html, (int)$p['old_id']) ?? $content;
        }
        $content = $this->rewriteHtml($content);
        $seo = $p['seo'];
        if ($opts['seo'] && !$seo && $html) {
            $seo = $this->seoFromHtml($html, $p['link']);
        }
        if (!$opts['seo']) {
            $seo = [];
        }
        if (!empty($seo['og_image_url'])) {
            $seo['og_image'] = self::mediaByUrl($this->wpAbs($seo['og_image_url']));
        }
        unset($seo['og_image_url'], $seo['_schema_found']);
        if (!empty($seo['canonical'])) {
            $seo['canonical'] = $this->relativeIfOwn($seo['canonical']);
        }

        $defs = ContentModel::fieldsFor('post', $type);
        $fields = $this->convertFields($defs, $p['fields']);
        $wpTemplate = $p['template'];
        if ($wpTemplate !== '') {
            $fields['_wp_template'] = $wpTemplate;
        }
        if ($opts['design'] && $html) {
            $fields += Mirror::pageFields($html, $this->job['origin'], (new Mirror($this->job['origin'])));
            if ($type !== 'page' && !Options::get('wpm_single_' . $type)) {
                if ($wrap = Mirror::captureSingle($html, $p['title'], $this->job['origin'])) {
                    Options::set('wpm_single_' . $type, $wrap);
                    $this->log("Captured the single “{$wpType}” layout for reuse.");
                }
            }
        }
        $terms = [];
        foreach ($p['terms'] as $t) {
            $tax = $this->slateTax($t['taxonomy']);
            if (!$tax || !in_array($tax, array_keys(ContentModel::taxonomiesFor($type)), true)) {
                continue;
            }
            $tid = isset($t['old_id']) ? self::map('term', $t['taxonomy'] . ':' . $t['old_id']) : (Terms::findBySlug($tax, (string)$t['slug'])['id'] ?? null);
            if ($tid) {
                $terms[$tax][] = $tid;
            }
        }
        foreach (array_keys(ContentModel::taxonomiesFor($type)) as $tax) {
            $terms[$tax] ??= [];
        }
        $status = match ($p['status']) {
            'publish', 'future' => 'publish', 'private' => 'private', 'pending' => 'pending', default => 'draft',
        };
        $data = [
            'type' => $type, 'title' => html_entity_decode($p['title'], ENT_QUOTES), 'slug' => $p['slug'] ?: $p['title'],
            'content' => $content, 'excerpt' => trim(html_entity_decode(strip_tags((string)$p['excerpt']), ENT_QUOTES)),
            'status' => $status, 'menu_order' => $p['menu_order'],
            'featured_media_id' => $p['thumbnail'] ? self::map('media', (string)$p['thumbnail']) : null,
            'template' => match ($wpTemplate) { 'elementor_canvas' => 'landing', 'elementor_header_footer', 'page-templates/full-width.php' => 'full-width', default => null },
            'fields' => $fields, 'seo' => $seo, 'terms' => $terms,
            'published_at' => $p['date_gmt'], 'created_at' => $p['date_gmt'] ?? now(), 'updated_at' => $p['modified_gmt'] ?? null,
        ];
        $id = Posts::save($data, $existing);
        // Posts::save normalises the slug; keep WordPress's exact slug for URL parity.
        if ($p['slug'] !== '' && Posts::find($id)['slug'] !== $p['slug'] && !DB::val('SELECT id FROM {{posts}} WHERE type = :t AND slug = :s AND id <> :id', ['t' => $type, 's' => $p['slug'], 'id' => $id])) {
            DB::update('posts', ['slug' => $p['slug']], ['id' => $id]);
        }
        self::setMap('post', $key, $id, $p['link'], ['parent' => $p['parent'] ? $wpType . ':' . $p['parent'] : null, 'type' => $type]);
        self::setMap('post_id', (string)$p['old_id'], $id, $p['link']);
        $this->stat($type);
    }

    /** Convert ACF / meta values to Slate field values (images → media ids, etc.). */
    private function convertFields(array $defs, array $values): array
    {
        $out = [];
        foreach ($values as $name => $v) {
            $name = preg_replace('/[^a-z0-9_]/', '_', strtolower((string)$name));
            $def = $defs[$name] ?? null;
            if (!$def) {
                continue;
            }
            $out[$name] = $this->convertValue($def, $v);
        }
        return $out;
    }

    private function convertValue(array $def, mixed $v): mixed
    {
        $mediaId = function ($x): ?int {
            if (is_array($x)) {
                $x = $x['ID'] ?? $x['id'] ?? ($x['url'] ?? null);
            }
            if (is_numeric($x)) {
                return self::map('media', (string)(int)$x);
            }
            return is_string($x) && $x !== '' ? self::mediaByUrl($this->wpAbs($x)) : null;
        };
        switch ($def['type']) {
            case 'image':
            case 'file':
                return $mediaId($v);
            case 'gallery':
                return array_values(array_filter(array_map($mediaId, is_array($v) ? $v : explode(',', (string)$v))));
            case 'relation':
                $ids = is_array($v) ? array_map(static fn($x) => is_array($x) ? ($x['ID'] ?? $x['id'] ?? 0) : $x, $v) : explode(',', (string)$v);
                return array_values(array_filter(array_map(static fn($x) => 'old:' . (int)$x, $ids), static fn($x) => $x !== 'old:0'));
            case 'repeater':
                $sub = [];
                foreach ($def['sub_fields'] ?? [] as $sf) {
                    $sub[$sf['name']] = $sf;
                }
                $rows = [];
                foreach ((array)$v as $row) {
                    if (is_array($row)) {
                        $r = [];
                        foreach ($row as $k => $rv) {
                            if (isset($sub[$k])) {
                                $r[$k] = $this->convertValue($sub[$k], $rv);
                            }
                        }
                        $rows[] = $r;
                    }
                }
                return $rows;
            case 'url':
                return $this->relativeIfOwn(is_array($v) ? (string)($v['url'] ?? '') : (string)$v);
            case 'richtext':
                return $this->rewriteHtml((string)$v);
            case 'toggle':
                return (bool)$v;
            case 'number':
                return is_numeric($v) ? $v + 0 : null;
            default:
                return is_scalar($v) ? $v : (is_array($v) ? implode(', ', array_filter($v, 'is_scalar')) : '');
        }
    }

    private function stepMenus(): bool
    {
        $menus = [];
        $locations = array_keys(\Slate\Theme::meta()['menus'] ?? ['primary' => 1, 'footer' => 1]);
        if ($this->isWxr()) {
            foreach ($this->wxr()['menus'] as $m) {
                $menus[] = ['name' => $m['name'], 'wp_location' => $m['name'], 'items' => $m['items']];
            }
        } else {
            $locs = $this->wp()->get('/wp/v2/menu-locations');
            $byMenu = [];
            foreach ($locs['ok'] && is_array($locs['data']) ? $locs['data'] : [] as $loc => $d) {
                if (!empty($d['menu'])) {
                    $byMenu[(int)$d['menu']][] = $loc;
                }
            }
            $list = $this->wp()->get('/wp/v2/menus', ['per_page' => 100]);
            foreach ($list['ok'] && is_array($list['data']) ? $list['data'] : [] as $menu) {
                $items = [];
                $page = 1;
                do {
                    $r = $this->wp()->get('/wp/v2/menu-items', ['menus' => $menu['id'], 'per_page' => 100, 'page' => $page]);
                    foreach ($r['ok'] && is_array($r['data']) ? $r['data'] : [] as $it) {
                        $items[] = ['old_id' => (int)$it['id'], 'title' => html_entity_decode(strip_tags((string)($it['title']['rendered'] ?? '')), ENT_QUOTES),
                            'order' => (int)($it['menu_order'] ?? 0), 'parent' => (int)($it['parent'] ?? 0), 'type' => (string)($it['type'] ?? 'custom'),
                            'object' => (string)($it['object'] ?? ''), 'object_id' => (int)($it['object_id'] ?? 0), 'url' => (string)($it['url'] ?? ''),
                            'target' => (string)($it['target'] ?? '')];
                    }
                    $page++;
                } while ($r['ok'] && $page <= $r['pages']);
                usort($items, static fn($a, $b) => $a['order'] <=> $b['order']);
                $menus[] = ['name' => html_entity_decode((string)$menu['name'], ENT_QUOTES), 'wp_location' => implode(' ', $byMenu[(int)$menu['id']] ?? []), 'items' => $items];
            }
            if (!$list['ok']) {
                $this->log('Menus API not accessible (needs an administrator application password). Rebuilding the main menu from the homepage navigation.');
                $html = $this->wp()->html($this->job['origin'] . '/');
                if ($html && ($items = Mirror::navFromHtml($html))) {
                    $menus[] = ['name' => 'Main menu', 'wp_location' => 'primary', 'items' => $items];
                }
            }
        }
        $out = [];
        $used = [];
        foreach ($menus as $m) {
            $loc = '';
            $wl = strtolower($m['wp_location']);
            if (str_contains($wl, 'foot') && in_array('footer', $locations, true)) {
                $loc = 'footer';
            } elseif ($wl !== '' && in_array('primary', $locations, true) && !isset($used['primary'])) {
                $loc = 'primary';
            }
            if ($loc) {
                $used[$loc] = true;
            }
            $out[] = ['id' => 'wp-' . slugify($m['name']), 'name' => $m['name'], 'location' => $loc, 'items' => $this->menuTree($m['items'], 0, 0)];
            $this->stat('menus');
        }
        if ($out) {
            $existing = array_filter(Menus::all(), static fn($m) => !str_starts_with((string)$m['id'], 'wp-'));
            foreach ($existing as &$e) {
                if (in_array($e['location'], array_column($out, 'location'), true)) {
                    $e['location'] = '';
                }
            }
            unset($e);
            Menus::save(array_merge(array_values($existing), $out));
            $this->log('Imported ' . count($out) . ' menu(s).');
        }
        return true;
    }

    private function menuTree(array $items, int $parent, int $depth): array
    {
        $out = [];
        foreach ($items as $it) {
            if ((int)$it['parent'] !== $parent || $depth > 3) {
                continue;
            }
            $node = ['label' => $it['title'], 'type' => 'custom', 'url' => $this->relativeIfOwn($it['url']), 'target' => $it['target'] === '_blank' ? '_blank' : '',
                'children' => $it['old_id'] ? $this->menuTree($items, (int)$it['old_id'], $depth + 1) : []];
            if (($it['type'] === 'post_type') && ($pid = self::map('post_id', (string)$it['object_id']))) {
                $node = ['type' => 'post', 'object_id' => $pid] + $node;
            } elseif ($it['type'] === 'taxonomy' && ($tax = $this->slateTax($it['object']))) {
                $tid = self::map('term', $it['object'] . ':' . $it['object_id']);
                if ($tid) {
                    $node = ['type' => 'term', 'object_id' => $tid] + $node;
                }
            }
            $out[] = $node;
        }
        return $out;
    }

    private function stepFinalize(): bool
    {
        // Post parents + relation fields.
        foreach (DB::all("SELECT new_id, extra FROM {{wpi_map}} WHERE kind = 'post'") as $r) {
            $extra = json_decode((string)$r['extra'], true) ?: [];
            if (!empty($extra['parent']) && ($pid = self::map('post', $extra['parent']))) {
                DB::update('posts', ['parent_id' => $pid], ['id' => (int)$r['new_id']]);
            }
            $post = Posts::find((int)$r['new_id']);
            if ($post) {
                $changed = false;
                array_walk_recursive($post['fields'], static function (&$v) use (&$changed) {
                    if (is_string($v) && str_starts_with($v, 'old:')) {
                        $v = self::map('post_id', substr($v, 4)) ?? 0;
                        $changed = true;
                    }
                });
                if ($changed) {
                    foreach ($post['fields'] as &$f) {
                        if (is_array($f) && array_is_list($f)) {
                            $f = array_values(array_filter($f, static fn($x) => $x !== 0));
                        }
                    }
                    unset($f);
                    DB::update('posts', ['fields' => json_encode($post['fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $post['id']]);
                }
            }
        }
        // Term parents.
        foreach (DB::all("SELECT old_id, new_id, extra FROM {{wpi_map}} WHERE kind = 'term'") as $r) {
            $extra = json_decode((string)$r['extra'], true) ?: [];
            $ref = (string)($extra['parent'] ?? '');
            if ($ref === '') {
                continue;
            }
            $pid = str_starts_with($ref, 'id:') ? self::map('term', $extra['wp_tax'] . ':' . substr($ref, 3)) : (Terms::findBySlug($extra['tax'], substr($ref, 5))['id'] ?? null);
            if ($pid && $pid !== (int)$r['new_id']) {
                DB::update('terms', ['parent_id' => $pid], ['id' => (int)$r['new_id']]);
            }
        }
        Terms::recount(DB::col('SELECT id FROM {{terms}}'));
        Posts::flush();
        Terms::flush();

        // Front page, posts page, logo, icon.
        $s = $this->job['wp_settings'];
        if (($s['show_on_front'] ?? '') === 'page' && !empty($s['page_on_front']) && ($fp = self::map('post_id', (string)$s['page_on_front']))) {
            Options::set('front_page', 'page');
            Options::set('front_page_id', $fp);
            Options::set('posts_page_id', !empty($s['page_for_posts']) ? (self::map('post_id', (string)$s['page_for_posts']) ?? 0) : 0);
        } elseif (($s['show_on_front'] ?? '') === 'posts') {
            Options::set('front_page', 'posts');
        } elseif (!isset($s['show_on_front']) && ($home = $this->guessFrontPage())) {
            Options::set('front_page', 'page');
            Options::set('front_page_id', $home);
        }
        foreach (['site_logo' => 'site_logo', 'site_icon' => 'site_icon'] as $wk => $sk) {
            if (!empty($s[$wk]) && ($mid = self::map('media', (string)$s[$wk]))) {
                Options::set($sk, $mid);
            }
        }

        // Permalink structure (from a blog post's URL) and trailing slash.
        $sample = DB::row("SELECT m.old_url, p.* FROM {{wpi_map}} m INNER JOIN {{posts}} p ON p.id = m.new_id WHERE m.kind = 'post' AND p.type = 'post' AND m.old_url <> '' LIMIT 1");
        if ($sample) {
            $path = trim((string)parse_url($sample['old_url'], PHP_URL_PATH), '/');
            $ts = strtotime($sample['published_at'] . ' UTC') ?: time();
            $structure = '/' . strtr($path, [$sample['slug'] => '%slug%', date('Y', $ts) => '%year%', date('m', $ts) => '%monthnum%', date('d', $ts) => '%day%', (string)$sample['id'] => '%id%']) . '/';
            if (str_contains($structure, '%slug%') || str_contains($structure, '%id%')) {
                $presets = ['/%slug%/', '/blog/%slug%/', '/%year%/%monthnum%/%slug%/', '/%year%/%monthnum%/%day%/%slug%/', '/archives/%id%/'];
                if (in_array($structure, $presets, true)) {
                    Options::set('permalink_post', $structure);
                } else {
                    Options::set('permalink_post', 'custom');
                    Options::set('permalink_custom', $structure);
                }
                $this->log('Permalink structure: ' . $structure);
            }
            Options::set('permalink_trailing_slash', str_ends_with((string)parse_url($sample['old_url'], PHP_URL_PATH), '/'));
        }

        // Redirect any URL that changed, so rankings and backlinks are preserved.
        $added = 0;
        if ($this->job['opts']['redirects']) {
            $rules = Options::get('redirects') ?: [];
            $existing = array_column($rules, 'from');
            foreach (DB::all("SELECT kind, new_id, old_url FROM {{wpi_map}} WHERE kind IN ('post','term') AND old_url <> ''") as $r) {
                $old = (string)parse_url($r['old_url'], PHP_URL_PATH);
                if ($r['kind'] === 'post') {
                    $p = Posts::find((int)$r['new_id']);
                    $new = $p ? (string)parse_url(Posts::permalink($p), PHP_URL_PATH) : '';
                } else {
                    $t = Terms::find((int)$r['new_id']);
                    $new = $t ? (string)parse_url(Terms::link($t), PHP_URL_PATH) : '';
                }
                $new = '/' . ltrim(substr($new, strlen(base_path())), '/');
                if ($old && $new && rtrim(rawurldecode($old), '/') !== rtrim($new, '/') && !in_array($old, $existing, true)) {
                    $rules[] = ['from' => $old, 'to' => $new, 'code' => 301, 'regex' => false];
                    $existing[] = $old;
                    $added++;
                }
            }
            Options::set('redirects', $rules);
        }
        $this->log("Finalized: parents linked, front page set, $added redirect(s) created for changed URLs.");
        PageCache::clear();
        return true;
    }

    private function guessFrontPage(): ?int
    {
        foreach (DB::all("SELECT new_id, old_url FROM {{wpi_map}} WHERE kind = 'post'") as $r) {
            if (rtrim($r['old_url'], '/') === rtrim($this->job['origin'], '/')) {
                return (int)$r['new_id'];
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ url helpers

    private function wpAbs(string $url): string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES);
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        return str_starts_with($url, '/') ? $this->job['origin'] . $url : $url;
    }

    /** Turn links to the old site into root-relative links. */
    public function relativeIfOwn(string $url): string
    {
        $hp = static fn(string $u): ?string => ($h = parse_url($u, PHP_URL_HOST)) ? $h . (($p = parse_url($u, PHP_URL_PORT)) ? ':' . $p : '') : null;
        $host = $hp($this->job['origin']);
        $uh = $hp($url);
        if ($uh && $host && (strcasecmp($uh, $host) === 0 || strcasecmp($uh, 'www.' . $host) === 0 || strcasecmp('www.' . $uh, $host) === 0)) {
            $rel = (string)parse_url($url, PHP_URL_PATH);
            $q = parse_url($url, PHP_URL_QUERY);
            $f = parse_url($url, PHP_URL_FRAGMENT);
            return url(ltrim($rel ?: '/', '/')) . ($q ? '?' . $q : '') . ($f ? '#' . $f : '');
        }
        return $url;
    }

    /** Replace imported media URLs with their Slate equivalents and make internal links relative. */
    public function rewriteHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $port = parse_url($this->job['origin'], PHP_URL_PORT);
        $host = preg_quote(preg_replace('/^www\./', '', (string)parse_url($this->job['origin'], PHP_URL_HOST)) . ($port ? ':' . $port : ''), '#');
        // Media URLs → new media.
        $html = preg_replace_callback('#(?:https?:)?//(?:www\.)?' . $host . '(/wp-content/uploads/[^"\'\s\)<>,]+)#i', function ($m) {
            $id = self::mediaByUrl($m[1]);
            return $id ? Media::url($id) : $m[1];
        }, $html);
        // srcset entries pointing at the same image are redundant once optimized.
        $html = preg_replace('#\s(srcset|sizes)="[^"]*content/uploads[^"]*"#i', '', (string)$html);
        // Remaining absolute links to the old site → relative.
        $html = preg_replace('#(href|src|action|data-src|data-bg)=(["\'])(?:https?:)?//(?:www\.)?' . $host . '/?#i', '$1=$2' . base_path() . '/', (string)$html);
        $html = preg_replace('#url\((["\']?)(?:https?:)?//(?:www\.)?' . $host . '/#i', 'url($1' . base_path() . '/', (string)$html);
        return (string)$html;
    }
}
