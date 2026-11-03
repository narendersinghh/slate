<?php
declare(strict_types=1);

namespace Slate;

/** First-run installer: writes config.php, creates tables, seeds defaults and the admin account. */
final class Installer
{
    public static function requirements(): array
    {
        return [
            'PHP ' . SLATE_MIN_PHP . '+' => version_compare(PHP_VERSION, SLATE_MIN_PHP, '>='),
            'PDO (MySQL or SQLite)' => extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'),
            'GD with WebP (image optimization)' => function_exists('imagewebp'),
            'sodium (encryption)' => function_exists('sodium_crypto_secretbox'),
            'mbstring' => extension_loaded('mbstring'),
            'cURL (AI & updates)' => function_exists('curl_init'),
            'zip (updates)' => class_exists('ZipArchive'),
            'Root folder writable (config.php)' => is_writable(SLATE_ROOT),
            'content/ writable' => is_writable(SLATE_CONTENT),
        ];
    }

    public static function run(array $in): array
    {
        $errors = [];
        $driver = ($in['db_driver'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
        $prefix = preg_match('/^[a-z0-9_]{1,10}$/i', (string)($in['db_prefix'] ?? 'sl_')) ? (string)($in['db_prefix'] ?? 'sl_') : 'sl_';
        $db = ['driver' => $driver, 'prefix' => $prefix];
        if ($driver === 'mysql') {
            $db += ['host' => trim((string)($in['db_host'] ?? 'localhost')), 'port' => (int)($in['db_port'] ?? 3306) ?: 3306,
                'name' => trim((string)($in['db_name'] ?? '')), 'user' => trim((string)($in['db_user'] ?? '')), 'pass' => (string)($in['db_pass'] ?? '')];
        } else {
            $db['path'] = SLATE_CONTENT . '/db-' . bin2hex(random_bytes(8)) . '.sqlite';
        }
        $username = trim((string)($in['username'] ?? ''));
        $email = strtolower(trim((string)($in['email'] ?? '')));
        $password = (string)($in['password'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $username)) {
            $errors[] = 'Username must be 3–60 characters: letters, numbers, dot, dash, underscore.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if (strlen($password) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        }
        if ($errors) {
            return $errors;
        }
        try {
            DB::connect($db);
        } catch (\Throwable $e) {
            return ['Database connection failed: ' . $e->getMessage()];
        }

        $config = [
            'db' => $db,
            'secret' => bin2hex(random_bytes(32)),
            'url' => rtrim((string)($in['site_url'] ?? ''), '/'),
            'debug' => false,
        ];
        if (!defined('SLATE_CONFIG')) {
            define('SLATE_CONFIG', $config);
        }

        try {
            Schema::install();
            Options::set('db_version', SLATE_DB_VERSION);
            Options::set('installed_at', now());
            Options::set('site_title', trim((string)($in['site_title'] ?? '')) ?: 'My Slate Site');
            Options::set('admin_email', $email);
            Options::set('roles', Roles::defaults());
            if (!empty($in['noindex'])) {
                Options::set('seo_noindex_site', true);
            }
            if (!DB::val('SELECT id FROM {{users}} WHERE username = :u', ['u' => $username])) {
                DB::insert('users', ['username' => $username, 'email' => $email, 'password' => Auth::hash($password),
                    'display_name' => $username, 'role' => 'administrator', 'status' => 'active', 'created_at' => now()]);
            }
            self::seed();
        } catch (\Throwable $e) {
            return ['Installation failed: ' . $e->getMessage()];
        }

        $php = "<?php\n// Slate configuration — generated " . gmdate('Y-m-d H:i') . " UTC. Keep this file private.\n"
            . 'const SLATE_CONFIG = ' . var_export($config, true) . ";\n";
        if (file_put_contents(SLATE_ROOT . '/config.php', $php, LOCK_EX) === false) {
            return ['Could not write config.php. Make the Slate folder writable, or create config.php manually.'];
        }
        @chmod(SLATE_ROOT . '/config.php', 0640);
        return [];
    }

    private static function seed(): void
    {
        if (DB::val('SELECT COUNT(*) FROM {{posts}}')) {
            return;
        }
        $cat = Terms::save('category', ['name' => 'Uncategorized', 'slug' => 'uncategorized']);
        $home = Posts::save(['type' => 'page', 'title' => 'Home', 'slug' => 'home', 'status' => 'publish',
            'content' => '<h2>Welcome to Slate</h2><p>This is your homepage. Edit it from the dashboard or right here on the page using frontend editing.</p>']);
        Posts::save(['type' => 'page', 'title' => 'About', 'slug' => 'about', 'status' => 'publish',
            'content' => '<p>Tell your visitors who you are and what you do.</p>']);
        Posts::save(['type' => 'page', 'title' => 'Contact', 'slug' => 'contact', 'status' => 'publish',
            'content' => '<p>How can people reach you?</p>', 'seo' => ['schema_type' => 'ContactPage']]);
        $blog = Posts::save(['type' => 'page', 'title' => 'Blog', 'slug' => 'blog', 'status' => 'publish', 'content' => '']);
        Posts::save(['type' => 'post', 'title' => 'Hello world', 'slug' => 'hello-world', 'status' => 'publish',
            'excerpt' => 'Your first post on Slate.', 'terms' => ['category' => [$cat]],
            'content' => '<p>Welcome to Slate. This is your first post. Edit or delete it, then start writing!</p>']);
        Options::set('front_page', 'page');
        Options::set('front_page_id', $home);
        Options::set('posts_page_id', $blog);
        Menus::save([['id' => 'main', 'name' => 'Main menu', 'location' => 'primary', 'items' => [
            ['label' => 'Home', 'type' => 'post', 'object_id' => $home],
            ['label' => 'About', 'type' => 'custom', 'url' => '/about/'],
            ['label' => 'Blog', 'type' => 'post', 'object_id' => $blog],
            ['label' => 'Contact', 'type' => 'custom', 'url' => '/contact/'],
        ]]]);
    }
}
