# Building Slate modules

A module is a folder in `modules/` that adds features to a Slate site: a shop, bookings, memberships, a directory,
an API, an integration… Anyone can write one, by hand or with the AI builder (Modules → Add new → Create with AI),
and share it as a zip, a GitHub repository or through a registry.

```
modules/my-shop/
  module.json        manifest (required)
  module.php         entry file, loaded on every request while active (required)
  src/               classes, autoloaded when declared in module.json
  views/ assets/     anything else the module needs
  migrations/        001-create-tables.php, 002-…  run once each, in order
  uninstall.php      removes the module's data when deleted with "Also delete its data"
```

## module.json

```json
{
  "name": "My Shop",
  "slug": "my-shop",
  "description": "Products, cart and checkout.",
  "version": "1.0.0",
  "author": "You",
  "homepage": "https://example.com",
  "requires": "0.6.0",
  "requires_php": "8.2",
  "depends": { "payments": ">=1.0" },
  "autoload": { "SlateModules\\MyShop\\": "src/" },
  "source": "https://github.com/you/slate-my-shop",
  "settings": "my-shop",
  "tags": ["ecommerce"]
}
```

| Key | Meaning |
|---|---|
| `slug` | Folder name; lowercase letters, numbers, `-`, `_`. Defaults to the folder/repository name. |
| `requires` / `requires_php` | Minimum Slate / PHP version. |
| `depends` | Other modules needed (`{"slug": "constraint"}` or `["slug"]`). Constraints: `*`, `1.2.0`, `>=1.2`, `^1.0`, `~1.4`, `1.*`. They are activated automatically and cannot be deactivated while you need them. |
| `autoload` | PSR-4 map. Prefixes must start with `SlateModules\`. |
| `source` | Where updates come from: a zip URL, a GitHub repository or a folder in one (`…/tree/main/my-shop`). Filled in automatically when installed from a URL. |
| `settings` | Slug of a custom settings page (only if you do not use `Modules::settings()`). |
| `icon` | Icon shown in the sidebar and on the Modules screen (a name from `core/Slate/Icons.php`, e.g. `cart`). |

## module.php

```php
<?php
declare(strict_types=1);

use Slate\Hooks;
use Slate\Modules;
use Slate\Router;

// Content filters & actions
Hooks::add('the_content', fn(string $html, array $post) => $html . '<p>Thanks for reading</p>');
Hooks::add('head', fn() => print '<meta name="x" content="y">');

// Public URLs (GET routes also answer HEAD)
Hooks::add('routes', function (Router $r): void {
    $r->get('/cart', fn() => print my_shop_cart_page());
    $r->post('/api/cart/{id:\d+}', function (string $id): void {
        \Slate\Csrf::check();                         // forms posted by your own pages
        json_response(['ok' => true, 'id' => (int)$id]);
    });
});

// Admin pages: label, slug (URL /admin/m/<slug>), render, capability, icon, section ("main" | "settings" | "hidden"), badge.
// They appear in the sidebar under Modules → <your module>. Build them with ListTable / AdminUi, see docs/admin-ui.md.
Modules::adminPage('Orders', 'my-shop-orders', function (): void {
    echo \Slate\AdminUi::header('Orders', 'Everything people bought.', 'cart');
}, 'manage_settings', 'cart');

// Settings: rendered by the standard settings screen (the module's Settings tab, /admin/m/my-shop/settings) and searchable.
Modules::settings([
    ['key' => 'my_shop_currency', 'type' => 'select', 'label' => 'Currency', 'default' => 'USD', 'options' => ['USD' => 'US dollar', 'EUR' => 'Euro']],
]);

// Lifecycle
Hooks::add('module_activated_my-shop', fn() => null);
Hooks::add('module_deactivated_my-shop', fn() => null);
Hooks::add('module_updated_my-shop', fn(string $from, string $to) => null);
```

### Hooks

| Hook | Kind | Arguments → return |
|---|---|---|
| `init` | action | after modules & theme load, before routing |
| `routes` | action | `Router $r`: register public URLs |
| `route_path` | filter | `string $path` → path Slate should resolve (map your URL scheme onto Slate's) |
| `admin_routes` | action | `Router $r`: extra admin URLs under `/admin/…` |
| `head`, `body_open`, `footer` | action | print into the page |
| `the_content` | filter | `string $html, array $post` → html |
| `html_output` | filter | full page HTML → html |
| `permalink`, `term_link` | filter | url of a post / term |
| `menu_item` | filter | `$item, ?$post, ?$term` → one resolved menu item (url, label, children) |
| `html_lang`, `html_dir` | filter | `<html lang>` / `dir` (themes print `<html <?= html_attrs() ?>>`) |
| `route_resolved` | action | `$kind, $vars`, the URL is resolved, nothing rendered yet: redirect or 404 here |
| `frontend_vars` | filter | `$vars, $kind` → variables passed to the theme/template |
| `sitemap_posts`, `sitemap_entry`, `sitemap_urlset` | filter | rows of a sitemap page; lines of one `<url>` entry (return `[]` to drop it); the opening `<urlset>` tag |
| `posts_query_args` | filter | change `Posts::query()` arguments (e.g. default filters) |
| `posts_query_sql` | filter | `[$where, $params, $joins], $args` → add SQL conditions/joins to every `Posts::query()` |
| `post_types`, `taxonomies` | filter | add content types and taxonomies in code |
| `field_groups` | filter | add custom-field groups in code |
| `post_before_save` | filter | post data before it is written |
| `post_saved`, `post_deleted`, `media_uploaded` | action | react to changes |
| `settings_tabs`, `settings_saved` | filter / action | add settings screens |
| `seo_title`, `seo_description`, `seo_robots`, `seo_head`, `seo_schema`, `seo_schema_node` | filter | SEO output |
| `template_tag`, `template_lookup`, `template_block` | filter | add `{{tags}}`, conditions and `{{#blocks}}` to Templates |
| `design_system_css` | filter | CSS variables of the design system |
| `modules_loaded` | action | every active module is loaded |
| `webhook_events` | filter | add events (then `Webhooks::dispatch('shop.order_paid', $data)`) |
| `page_cacheable` | filter | return `false` to keep the current response out of the page cache (or call `PageCache::skip()`) |
| `page_cache_kind` | filter | `'single'` or `'listing'`, mark pages that list other content as listings so "smart" purges refresh them |
| `cache_warm_paths` | filter | paths the background task re-warms after a purge |
| `cron_jobs` | filter | `['name' => ['every' => 3600, 'label' => '…', 'run' => fn() => …]]`: scheduled tasks |
| `api_routes` | action | `Router $r`: extra REST endpoints under `/api/v1/…` |
| `api_post`, `api_types` | filter | shape API output / choose exposed content types |
| `mail_send` | filter | `(null, $message)` → return `true`/`false` to deliver mail yourself (e.g. an email API) |
| `backup_paths` | filter | extra paths to include in files backups |
| `cli_commands` | filter | `['name' => ['Help text', fn(array $opts, string $arg, callable $out, callable $fail)]]`: add `php bin/slate <name>` commands |

### Core APIs

```php
use Slate\{DB, Options, Posts, Terms, Media, ContentModel, Auth, Http, Ai, Csrf, Session, PageCache, Mailer, Revisions};

DB::exec("CREATE TABLE IF NOT EXISTS {{shop_orders}} (…)");   // {{name}} adds the table prefix
DB::all('SELECT * FROM {{posts}} WHERE type = :t', ['t' => 'product']);
DB::row(...); DB::val(...); DB::insert(...); DB::update(...); DB::transaction(fn() => ...);

Options::get('my_shop_settings', []);  Options::set('my_shop_settings', $v);
Posts::query(['type' => 'product', 'status' => 'publish', 'limit' => 12, 'page' => 1, 'orderby' => 'title', 'order' => 'asc',
    'fields' => ['color' => 'red', 'lang' => null]]);   // custom-field equality; null = not set
DB::jsonField('p.fields', 'color');   // SQL expression for a custom field (MySQL + SQLite)
Posts::find($id); Posts::findBySlug('product', 'blue-shirt'); Posts::save($data, $id);
Terms::all('category'); Media::url($mediaId, 'medium');
Auth::user(); Auth::can('edit_posts'); Http::json('POST', $url, $headers, $payload); Ai::complete($prompt);
Mailer::send('a@b.com', 'Subject', '<p>HTML</p>', ['reply_to' => 'c@d.com']);   // SMTP when configured
Revisions::snapshot($post, $newData);   // before Posts::save(), to make a change undoable
```

Rendering module pages: `Frontend::page($kind, $vars, ['theme-template'])` or `Frontend::content($kind, $html)` (inside the theme's header/footer). `Frontend::resolving()` is true while core matches the URL.

Helpers: `e()` (escape), `site_url()`, `admin_url()`, `url()`, `json_response()`, `redirect()`, `input()`, `csrf_field()`,
`slugify()`, `log_activity()`, `field()`, `permalink()`, `query()`, `menu()`, `image()`.

## Migrations

`migrations/001-orders.php` runs once on activation; files added in later versions run after an update. Each file either
runs its statements directly or returns a callable:

```php
<?php
use Slate\DB;
return function (): void {
    DB::exec("CREATE TABLE IF NOT EXISTS {{shop_orders}} (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, total DECIMAL(10,2) NOT NULL, created_at DATETIME NOT NULL)");
};
```

Write SQL that works on MySQL/MariaDB; when you need SQLite support too, check `DB::$driver` (`mysql` or `sqlite`).

## Safety rules Slate applies

- Every PHP file is syntax-checked before it is installed or activated; broken modules are refused.
- A module that crashes while loading is **switched off automatically** and the error is shown on the Modules page, so
  the site stays up. Create an empty file `content/safe-mode` to start without any module (e.g. over FTP).
- Before installing or activating, the Modules page lists what the code can do (shell commands, evaluated code,
  file writes, network calls, database tables…), so whoever installs it can decide.
- Updates back up the installed version to `content/backups/modules/` and restore it if anything fails.
- PHP files inside `modules/` can never be opened directly from the web.
- Site owners can switch installing off entirely with `'modules_locked' => true` in `config.php`.

## Write modules that are good citizens

- Escape everything you print with `e()`; check `Auth::can()` in admin pages and `Csrf::check()` on POSTs.
- Use prepared statements (`DB::all($sql, $params)`), never string-built SQL with request data.
- Prefix options, tables, CSS classes and functions with your slug.
- Keep work out of `module.php`'s top level: register hooks there, do the work inside them.
- Never edit core files; everything is possible through hooks. Missing a hook? Open an issue.

## Sharing a module

- **Zip**: the zip must contain `module.json` at its root or in one top folder.
- **GitHub**: `https://github.com/you/repo`, or one folder of a repository holding several modules:
  `https://github.com/you/slate-modules/tree/main/my-shop`. Private repositories work with a token (Modules → Sources).
- **Registry**: publish a JSON file and add its URL under Modules → Sources:

```json
{
  "name": "My registry",
  "modules": [
    { "slug": "my-shop", "name": "My Shop", "description": "…", "version": "1.0.0", "requires": "0.6.0",
      "author": "You", "homepage": "https://…", "download": "https://github.com/you/slate-modules/tree/main/my-shop",
      "sha256": "optional checksum of the zip download", "tags": ["ecommerce"] }
  ]
}
```

- **Keep it on your server only**: a site-specific module needs no repository. It lives in `modules/<slug>/` on the
  site (included in your hosting backups), and **Download** on the Modules page (or `php bin/slate module:export <slug>`)
  packages it as a zip you can install on any other Slate site.

Command line: `php bin/slate module:list | module:make <slug> | module:install <url|zip> | module:activate <slug> |
module:deactivate <slug> | module:update <slug> | module:check <slug> | module:export <slug> | module:delete <slug>`.
