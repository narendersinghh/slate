<?php
declare(strict_types=1);

namespace Slate;

/**
 * Declarative settings registry.
 * Top tabs -> left side sections -> fields. Rendered and saved generically by the admin.
 * Sections with a "render" key use a custom partial (admin/views/settings/<render>.php).
 */
final class Settings
{
    private static ?array $tabs = null;
    private static ?array $defaults = null;

    public static function tabs(): array
    {
        if (self::$tabs !== null) {
            return self::$tabs;
        }
        $tabs = [
            'general' => ['label' => 'General', 'icon' => 'sliders', 'sections' => [
                'identity' => ['label' => 'Site identity', 'fields' => [
                    ['key' => 'site_title', 'type' => 'text', 'label' => 'Site title', 'default' => 'My Slate Site'],
                    ['key' => 'tagline', 'type' => 'text', 'label' => 'Tagline', 'default' => 'Just another Slate website'],
                    ['key' => 'site_logo', 'type' => 'media', 'label' => 'Logo', 'default' => null],
                    ['key' => 'site_icon', 'type' => 'media', 'label' => 'Site icon (favicon)', 'help' => 'Square image, at least 512×512px.', 'default' => null],
                    ['key' => 'admin_email', 'type' => 'email', 'label' => 'Administration email', 'default' => ''],
                ]],
                'reading' => ['label' => 'Homepage & reading', 'fields' => [
                    ['key' => 'front_page', 'type' => 'select', 'label' => 'Homepage displays', 'default' => 'posts',
                        'options' => ['posts' => 'Latest posts', 'page' => 'A static page']],
                    ['key' => 'front_page_id', 'type' => 'select', 'label' => 'Homepage', 'default' => 0, 'options' => 'pages', 'show_if' => 'front_page=page'],
                    ['key' => 'posts_page_id', 'type' => 'select', 'label' => 'Posts page', 'default' => 0, 'options' => 'pages', 'show_if' => 'front_page=page'],
                    ['key' => 'posts_per_page', 'type' => 'number', 'label' => 'Items per page', 'default' => 10, 'min' => 1, 'max' => 100],
                ]],
                'permalinks' => ['label' => 'Permalinks', 'fields' => [
                    ['key' => 'permalink_post', 'type' => 'select', 'label' => 'Post URL structure', 'default' => '/%slug%/', 'options' => [
                        '/%slug%/' => 'Post name — /sample-post/',
                        '/blog/%slug%/' => 'Blog prefix — /blog/sample-post/',
                        '/%year%/%monthnum%/%slug%/' => 'Month and name — /2026/11/sample-post/',
                        '/%year%/%monthnum%/%day%/%slug%/' => 'Day and name — /2026/11/03/sample-post/',
                        '/%category%/%slug%/' => 'Category and name — /news/sample-post/',
                        '/archives/%id%/' => 'Numeric — /archives/123/',
                        'custom' => 'Custom structure',
                    ]],
                    ['key' => 'permalink_custom', 'type' => 'text', 'label' => 'Custom structure', 'default' => '/%slug%/',
                        'help' => 'Tags: %year% %monthnum% %day% %slug% %id% %category%', 'show_if' => 'permalink_post=custom'],
                    ['key' => 'permalink_trailing_slash', 'type' => 'toggle', 'label' => 'Trailing slash', 'help' => 'URLs end with "/". The other form 301-redirects to this one.', 'default' => true],
                    ['type' => 'info', 'label' => 'Custom post types & taxonomies', 'help' => 'Each post type and taxonomy has its own URL base, editable under Content Model.'],
                ]],
                'locale' => ['label' => 'Date, time & language', 'fields' => [
                    ['key' => 'timezone', 'type' => 'select', 'label' => 'Timezone', 'default' => 'UTC', 'options' => 'timezones'],
                    ['key' => 'date_format', 'type' => 'text', 'label' => 'Date format', 'default' => 'M j, Y', 'help' => 'PHP date() format.'],
                    ['key' => 'time_format', 'type' => 'text', 'label' => 'Time format', 'default' => 'g:i a'],
                    ['key' => 'language', 'type' => 'text', 'label' => 'Site language (HTML lang)', 'default' => 'en'],
                ]],
                'users' => ['label' => 'Users & registration', 'fields' => [
                    ['key' => 'registration_enabled', 'type' => 'toggle', 'label' => 'Anyone can register', 'default' => false],
                    ['key' => 'default_role', 'type' => 'select', 'label' => 'New user default role', 'default' => 'subscriber', 'options' => 'roles'],
                ]],
            ]],

            'seo' => ['label' => 'SEO', 'icon' => 'search', 'sections' => [
                'titles' => ['label' => 'Titles & meta', 'fields' => [
                    ['key' => 'seo_separator', 'type' => 'select', 'label' => 'Title separator', 'default' => '–',
                        'options' => ['–' => '–', '-' => '-', '|' => '|', '•' => '•', '·' => '·', '»' => '»', '/' => '/']],
                    ['key' => 'seo_title_template', 'type' => 'text', 'label' => 'Default title template', 'default' => '%title% %sep% %site%',
                        'help' => 'Variables: %title% %site% %sep% %tagline% %page% %category% %year%'],
                    ['key' => 'seo_home_title', 'type' => 'text', 'label' => 'Homepage meta title', 'default' => '%site% %sep% %tagline%', 'counter' => 60],
                    ['key' => 'seo_home_description', 'type' => 'textarea', 'label' => 'Homepage meta description', 'default' => '', 'counter' => 160],
                    ['key' => 'seo_auto_description', 'type' => 'toggle', 'label' => 'Auto-generate missing descriptions', 'help' => 'Falls back to the excerpt / first paragraph.', 'default' => true],
                ]],
                'indexing' => ['label' => 'Indexing & robots meta', 'fields' => [
                    ['key' => 'seo_noindex_site', 'type' => 'toggle', 'label' => 'Noindex the entire website', 'danger' => true,
                        'help' => 'Adds noindex, nofollow to every page and disallows everything in robots.txt. Use for staging sites.', 'default' => false],
                    ['key' => 'seo_noindex_search', 'type' => 'toggle', 'label' => 'Noindex search results', 'default' => true],
                    ['key' => 'seo_noindex_paged', 'type' => 'toggle', 'label' => 'Noindex paginated archives (page 2+)', 'default' => false],
                    ['key' => 'seo_noindex_authors', 'type' => 'toggle', 'label' => 'Noindex author archives', 'default' => true],
                    ['key' => 'seo_max_snippet', 'type' => 'toggle', 'label' => 'Add max-snippet:-1, max-image-preview:large', 'default' => true],
                    ['key' => 'seo_canonical', 'type' => 'toggle', 'label' => 'Output canonical URLs', 'default' => true],
                ]],
                'types' => ['label' => 'Post types & taxonomies', 'render' => 'seo-types'],
                'sitemap' => ['label' => 'XML sitemap', 'fields' => [
                    ['key' => 'seo_sitemap_enabled', 'type' => 'toggle', 'label' => 'Enable XML sitemap', 'default' => true, 'help' => 'Available at /sitemap.xml'],
                    ['key' => 'seo_sitemap_images', 'type' => 'toggle', 'label' => 'Include featured images', 'default' => true],
                    ['key' => 'seo_sitemap_per_page', 'type' => 'number', 'label' => 'URLs per sitemap file', 'default' => 1000, 'min' => 100, 'max' => 50000],
                    ['type' => 'info', 'label' => 'What is included', 'help' => 'Choose which post types and taxonomies appear in the sitemap under SEO → Post types & taxonomies. Noindexed content is excluded automatically.'],
                ]],
                'robots' => ['label' => 'robots.txt', 'fields' => [
                    ['key' => 'seo_robots_txt', 'type' => 'code', 'label' => 'robots.txt content', 'rows' => 12,
                        'default' => "User-agent: *\nDisallow: /admin/\nDisallow: /?s=\n\nSitemap: {sitemap}",
                        'help' => '{sitemap} is replaced with your sitemap URL. Served virtually at /robots.txt'],
                ]],
                'schema' => ['label' => 'Schema (structured data)', 'fields' => [
                    ['key' => 'seo_schema_enabled', 'type' => 'toggle', 'label' => 'Output JSON-LD schema', 'default' => true],
                    ['key' => 'seo_schema_entity', 'type' => 'select', 'label' => 'Website represents', 'default' => 'Organization',
                        'options' => ['Organization' => 'Organization', 'LocalBusiness' => 'Local business', 'Person' => 'Person']],
                    ['key' => 'seo_schema_name', 'type' => 'text', 'label' => 'Organization / person name', 'default' => ''],
                    ['key' => 'seo_schema_logo', 'type' => 'media', 'label' => 'Logo / photo', 'default' => null],
                    ['key' => 'seo_schema_phone', 'type' => 'text', 'label' => 'Phone', 'default' => ''],
                    ['key' => 'seo_schema_address', 'type' => 'textarea', 'label' => 'Address', 'default' => '', 'rows' => 2],
                    ['key' => 'seo_schema_same_as', 'type' => 'textarea', 'label' => 'Social profiles (sameAs)', 'default' => '', 'help' => 'One URL per line.'],
                    ['key' => 'seo_schema_breadcrumbs', 'type' => 'toggle', 'label' => 'Breadcrumb schema', 'default' => true],
                    ['key' => 'seo_schema_sitelinks_search', 'type' => 'toggle', 'label' => 'Sitelinks search box', 'default' => true],
                ]],
                'social' => ['label' => 'Social (Open Graph)', 'fields' => [
                    ['key' => 'seo_og_enabled', 'type' => 'toggle', 'label' => 'Open Graph & Twitter meta tags', 'default' => true],
                    ['key' => 'seo_og_default_image', 'type' => 'media', 'label' => 'Default share image', 'help' => '1200×630px recommended.', 'default' => null],
                    ['key' => 'seo_twitter_card', 'type' => 'select', 'label' => 'Twitter/X card type', 'default' => 'summary_large_image',
                        'options' => ['summary_large_image' => 'Summary with large image', 'summary' => 'Summary']],
                    ['key' => 'seo_twitter_site', 'type' => 'text', 'label' => 'Twitter/X username', 'placeholder' => '@yourbrand', 'default' => ''],
                    ['key' => 'seo_facebook_app_id', 'type' => 'text', 'label' => 'Facebook App ID', 'default' => ''],
                ]],
                'redirects' => ['label' => 'Redirects', 'render' => 'redirects'],
            ]],

            'integrations' => ['label' => 'Integrations', 'icon' => 'plug', 'sections' => [
                'google' => ['label' => 'Google', 'fields' => [
                    ['key' => 'gtm_id', 'type' => 'text', 'label' => 'Google Tag Manager container ID', 'placeholder' => 'GTM-XXXXXXX', 'default' => '', 'pattern' => '^GTM-[A-Z0-9]+$'],
                    ['key' => 'ga4_id', 'type' => 'text', 'label' => 'Google Analytics 4 measurement ID', 'placeholder' => 'G-XXXXXXXXXX', 'default' => '', 'pattern' => '^G-[A-Z0-9]+$',
                        'help' => 'Leave empty if GA4 is already loaded through Tag Manager.'],
                    ['key' => 'gsc_verification', 'type' => 'text', 'label' => 'Google Search Console verification', 'placeholder' => 'content value of the meta tag', 'default' => ''],
                    ['key' => 'adsense_client', 'type' => 'text', 'label' => 'Google AdSense publisher ID', 'placeholder' => 'ca-pub-XXXXXXXX', 'default' => ''],
                ]],
                'other' => ['label' => 'Other services', 'fields' => [
                    ['key' => 'bing_verification', 'type' => 'text', 'label' => 'Bing Webmaster verification', 'default' => ''],
                    ['key' => 'yandex_verification', 'type' => 'text', 'label' => 'Yandex verification', 'default' => ''],
                    ['key' => 'pinterest_verification', 'type' => 'text', 'label' => 'Pinterest verification', 'default' => ''],
                    ['key' => 'meta_pixel_id', 'type' => 'text', 'label' => 'Meta (Facebook) Pixel ID', 'default' => '', 'pattern' => '^\d+$'],
                    ['key' => 'clarity_id', 'type' => 'text', 'label' => 'Microsoft Clarity project ID', 'default' => '', 'pattern' => '^[a-z0-9]+$'],
                    ['key' => 'linkedin_partner_id', 'type' => 'text', 'label' => 'LinkedIn Insight partner ID', 'default' => '', 'pattern' => '^\d+$'],
                ]],
                'code' => ['label' => 'Custom code', 'fields' => [
                    ['key' => 'code_head', 'type' => 'code', 'label' => 'Header code', 'help' => 'Printed inside <head>.', 'default' => '', 'rows' => 8],
                    ['key' => 'code_body', 'type' => 'code', 'label' => 'Body code', 'help' => 'Printed right after <body> (e.g. GTM noscript).', 'default' => '', 'rows' => 6],
                    ['key' => 'code_footer', 'type' => 'code', 'label' => 'Footer code', 'help' => 'Printed before </body>.', 'default' => '', 'rows' => 8],
                    ['key' => 'tracking_skip_logged_in', 'type' => 'toggle', 'label' => 'Do not load tracking for logged-in team members', 'default' => true],
                ]],
            ]],

            'media' => ['label' => 'Media', 'icon' => 'image', 'sections' => [
                'optimization' => ['label' => 'Image optimization', 'fields' => [
                    ['key' => 'media_optimize', 'type' => 'toggle', 'label' => 'Automatically compress uploaded images', 'default' => true],
                    ['key' => 'media_webp', 'type' => 'toggle', 'label' => 'Convert images to WebP', 'default' => true],
                    ['key' => 'media_quality', 'type' => 'number', 'label' => 'Quality (%)', 'default' => 87, 'min' => 40, 'max' => 100],
                    ['key' => 'media_max_edge', 'type' => 'number', 'label' => 'Maximum size of the longest edge (px)', 'default' => 1600, 'min' => 320, 'max' => 8000],
                    ['key' => 'media_keep_transparency', 'type' => 'toggle', 'label' => 'Keep transparency (PNG/GIF/WebP)', 'default' => true],
                    ['key' => 'media_thumb_size', 'type' => 'number', 'label' => 'Thumbnail size (px)', 'default' => 400, 'min' => 100, 'max' => 1000],
                ]],
                'uploads' => ['label' => 'Uploads', 'fields' => [
                    ['key' => 'media_max_upload_mb', 'type' => 'number', 'label' => 'Maximum upload size (MB)', 'default' => 20, 'min' => 1, 'max' => 2048],
                    ['key' => 'media_allowed', 'type' => 'text', 'label' => 'Allowed file extensions', 'default' => 'jpg,jpeg,png,gif,webp,avif,pdf,mp4,webm,mp3,wav,doc,docx,xls,xlsx,ppt,pptx,csv,txt,zip'],
                    ['key' => 'media_rename_seo', 'type' => 'toggle', 'label' => 'Rename files to SEO-friendly slugs', 'default' => true, 'help' => '"My Photo (1).JPG" → my-photo-1.webp'],
                    ['key' => 'media_lazy', 'type' => 'toggle', 'label' => 'Lazy-load images on the frontend', 'default' => true],
                ]],
            ]],

            'appearance' => ['label' => 'Appearance', 'icon' => 'palette', 'sections' => [
                'theme' => ['label' => 'Theme', 'fields' => [
                    ['key' => 'theme', 'type' => 'select', 'label' => 'Active theme', 'default' => 'default', 'options' => 'themes'],
                    ['key' => 'theme_accent', 'type' => 'color', 'label' => 'Accent colour', 'default' => '#4f46e5'],
                    ['key' => 'admin_bar_frontend', 'type' => 'toggle', 'label' => 'Show admin bar on the website when logged in', 'default' => true],
                ]],
                'menus' => ['label' => 'Menus', 'render' => 'menus'],
            ]],

            'performance' => ['label' => 'Performance', 'icon' => 'zap', 'sections' => [
                'cache' => ['label' => 'Page cache', 'render' => 'cache', 'fields' => [
                    ['key' => 'cache_pages', 'type' => 'toggle', 'label' => 'Full-page cache for visitors', 'help' => 'Pages are served as static HTML without touching the database. Cleared automatically when content changes.', 'default' => true],
                    ['key' => 'cache_ttl', 'type' => 'number', 'label' => 'Cache lifetime (minutes)', 'default' => 720, 'min' => 1, 'max' => 43200],
                    ['key' => 'cache_browser_assets', 'type' => 'toggle', 'label' => 'Long browser cache for theme assets & uploads', 'default' => true, 'help' => 'Handled by the bundled .htaccess / nginx config.'],
                ]],
                'output' => ['label' => 'HTML output', 'fields' => [
                    ['key' => 'html_minify', 'type' => 'toggle', 'label' => 'Minify HTML', 'default' => true],
                    ['key' => 'perf_preconnect', 'type' => 'textarea', 'label' => 'Preconnect to origins', 'help' => 'One origin per line, e.g. https://fonts.gstatic.com', 'default' => '', 'rows' => 3],
                ]],
            ]],

            'security' => ['label' => 'Security', 'icon' => 'shield', 'sections' => [
                'login' => ['label' => 'Login protection', 'fields' => [
                    ['key' => 'security_admin_slug', 'type' => 'text', 'label' => 'Admin URL slug', 'default' => 'admin', 'pattern' => '^[a-z0-9-]{3,40}$',
                        'help' => 'Change to hide the login page from bots, e.g. "studio" → /studio/login. Bookmark it!'],
                    ['key' => 'security_max_attempts', 'type' => 'number', 'label' => 'Failed attempts before lockout', 'default' => 5, 'min' => 3, 'max' => 50],
                    ['key' => 'security_lockout_minutes', 'type' => 'number', 'label' => 'Lockout duration (minutes)', 'default' => 15, 'min' => 1, 'max' => 1440],
                    ['key' => 'security_session_hours', 'type' => 'number', 'label' => 'Session idle timeout (hours)', 'default' => 12, 'min' => 1, 'max' => 720],
                    ['key' => 'security_min_password', 'type' => 'number', 'label' => 'Minimum password length', 'default' => 10, 'min' => 8, 'max' => 64],
                    ['key' => 'security_require_2fa', 'type' => 'toggle', 'label' => 'Require two-factor auth for administrators', 'default' => false],
                ]],
                'headers' => ['label' => 'HTTP security headers', 'fields' => [
                    ['key' => 'security_headers', 'type' => 'toggle', 'label' => 'Send security headers', 'help' => 'X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy.', 'default' => true],
                    ['key' => 'security_hsts', 'type' => 'toggle', 'label' => 'HSTS (Strict-Transport-Security)', 'help' => 'Only enable when the whole site works over HTTPS.', 'default' => false],
                    ['key' => 'security_trust_proxy', 'type' => 'toggle', 'label' => 'Behind a proxy / CDN (trust X-Forwarded-For)', 'default' => false],
                ]],
                'activity' => ['label' => 'Activity log', 'render' => 'activity'],
            ]],

            'ai' => ['label' => 'AI', 'icon' => 'sparkles', 'sections' => [
                'provider' => ['label' => 'Provider', 'render' => 'ai-provider', 'fields' => [
                    ['key' => 'ai_enabled', 'type' => 'toggle', 'label' => 'Enable AI assistant', 'default' => false],
                    ['key' => 'ai_provider', 'type' => 'select', 'label' => 'Provider preset', 'default' => 'anthropic', 'options' => 'ai_providers'],
                    ['key' => 'ai_api_key', 'type' => 'secret', 'label' => 'API key', 'default' => '', 'help' => 'Stored encrypted.'],
                    ['key' => 'ai_model', 'type' => 'text', 'label' => 'Model', 'default' => '', 'help' => 'Leave empty to use the preset default.'],
                    ['key' => 'ai_base_url', 'type' => 'text', 'label' => 'Base URL', 'default' => '', 'help' => 'Only for "Custom (OpenAI-compatible)" or self-hosted gateways.'],
                    ['key' => 'ai_temperature', 'type' => 'number', 'label' => 'Temperature', 'default' => 0.7, 'min' => 0, 'max' => 2, 'step' => 0.1],
                    ['key' => 'ai_max_tokens', 'type' => 'number', 'label' => 'Max output tokens', 'default' => 2000, 'min' => 100, 'max' => 64000],
                ]],
                'voice' => ['label' => 'Brand voice', 'fields' => [
                    ['key' => 'ai_system_prompt', 'type' => 'textarea', 'label' => 'Instructions for every request', 'rows' => 6,
                        'default' => 'You are a helpful content assistant for a website. Write clear, accurate, human-sounding copy. Avoid filler and clichés.',
                        'help' => 'Describe tone, audience, spelling (US/UK), words to avoid, etc.'],
                ]],
            ]],

            'system' => ['label' => 'System', 'icon' => 'cpu', 'sections' => [
                'updates' => ['label' => 'Updates', 'render' => 'updates', 'fields' => [
                    ['key' => 'update_repo', 'type' => 'text', 'label' => 'GitHub repository', 'default' => 'narendersinghh/slate', 'pattern' => '^[\w.-]+/[\w.-]+$'],
                    ['key' => 'update_channel', 'type' => 'select', 'label' => 'Release channel', 'default' => 'stable',
                        'options' => ['stable' => 'Stable releases only', 'beta' => 'Include pre-releases']],
                    ['key' => 'update_token', 'type' => 'secret', 'label' => 'GitHub token (private repos only)', 'default' => ''],
                    ['key' => 'update_auto_check', 'type' => 'toggle', 'label' => 'Check for updates automatically (every 12h)', 'default' => true],
                ]],
                'maintenance' => ['label' => 'Maintenance mode', 'fields' => [
                    ['key' => 'maintenance_mode', 'type' => 'toggle', 'label' => 'Enable maintenance mode', 'help' => 'Visitors get a 503 page; logged-in team members see the site.', 'default' => false],
                    ['key' => 'maintenance_message', 'type' => 'textarea', 'label' => 'Message', 'default' => 'We are making some improvements. Please check back soon.'],
                ]],
                'info' => ['label' => 'System info', 'render' => 'sysinfo'],
            ]],
        ];
        return self::$tabs = Hooks::filter('settings_tabs', $tabs);
    }

    public static function defaults(): array
    {
        if (self::$defaults !== null) {
            return self::$defaults;
        }
        $d = [];
        foreach (self::tabs() as $tab) {
            foreach ($tab['sections'] as $section) {
                foreach ($section['fields'] ?? [] as $f) {
                    if (isset($f['key'])) {
                        $d[$f['key']] = $f['default'] ?? null;
                    }
                }
            }
        }
        return self::$defaults = $d + [
            'menus' => [],
            'redirects' => [],
            'seo_types' => [],
            'seo_taxonomies' => [],
            'active_modules' => [],
        ];
    }

    public static function default(string $key): mixed
    {
        return self::defaults()[$key] ?? null;
    }

    public static function section(string $tab, string $section): ?array
    {
        return self::tabs()[$tab]['sections'][$section] ?? null;
    }

    /** Resolve dynamic option sources for select fields. */
    public static function options(string|array $source): array
    {
        if (is_array($source)) {
            return $source;
        }
        return match ($source) {
            'pages' => [0 => '— Select —'] + array_column(
                DB::all("SELECT id, title FROM {{posts}} WHERE type = 'page' AND status = 'publish' ORDER BY title"),
                'title',
                'id'
            ),
            'timezones' => array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()),
            'roles' => array_map(static fn($r) => $r['label'], Roles::all()),
            'themes' => Theme::available(),
            'ai_providers' => array_map(static fn($p) => $p['label'], Ai::PRESETS),
            default => [],
        };
    }

    /** Validate & save posted values for a section. Returns list of errors. */
    public static function save(string $tab, string $section, array $input): array
    {
        $def = self::section($tab, $section);
        if (!$def) {
            return ['Unknown settings section.'];
        }
        $errors = [];
        foreach ($def['fields'] ?? [] as $f) {
            if (!isset($f['key'])) {
                continue;
            }
            $key = $f['key'];
            $raw = $input[$key] ?? null;
            $value = match ($f['type']) {
                'toggle' => !empty($raw),
                'number' => isset($f['step']) ? (float)$raw : (int)$raw,
                'media' => $raw ? (int)$raw : null,
                'secret' => null,
                'email' => trim((string)$raw),
                default => is_string($raw) ? str_replace("\r\n", "\n", $raw) : '',
            };
            if ($f['type'] === 'secret') {
                // Keep current secret unless a new one is entered or "clear" is ticked.
                if (!empty($input[$key . '__clear'])) {
                    Options::set($key, '');
                } elseif (is_string($raw) && $raw !== '') {
                    Options::set($key, Crypto::encrypt(trim($raw)));
                }
                continue;
            }
            if ($f['type'] === 'number') {
                if (isset($f['min']) && $value < $f['min']) {
                    $value = $f['min'];
                }
                if (isset($f['max']) && $value > $f['max']) {
                    $value = $f['max'];
                }
            }
            if ($f['type'] === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = $f['label'] . ' is not a valid email address.';
                continue;
            }
            if (is_string($value) && !in_array($f['type'], ['code', 'textarea'], true)) {
                $value = trim($value);
            }
            if (!empty($f['pattern']) && is_string($value) && $value !== '' && !preg_match('#' . $f['pattern'] . '#', $value)) {
                $errors[] = $f['label'] . ' has an invalid format.';
                continue;
            }
            if ($f['type'] === 'select' && !in_array($f['options'], ['pages'], true)) {
                $opts = self::options($f['options']);
                if (!in_array((string)$value, array_map('strval', array_keys($opts)), true)) {
                    $value = $f['default'];
                }
            }
            if ($f['type'] === 'select' && $f['options'] === 'pages') {
                $value = (int)$value;
            }
            Options::set($key, $value);
        }
        Hooks::action('settings_saved', $tab, $section);
        PageCache::clear();
        return $errors;
    }
}
