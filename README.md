<p align="center"><img src="admin/assets/brand/logo.svg" alt="Slate" height="64"></p>

# Slate CMS

A fast, secure, SEO-first content management system — the flexibility of WordPress without the bloat.

- **Modern admin** — clean dashboard, command palette (⌘/Ctrl + K), dark mode, fully responsive.
- **Content model built in** — custom post types, taxonomies and custom fields (20 field types incl. repeater, gallery, relationship). No plugins needed.
- **SEO built in** — meta titles/descriptions with templates, live Google preview & on-page analysis, robots meta, *noindex the whole site*, canonical URLs, Open Graph/Twitter, JSON-LD schema graph (Organization, WebSite, Article, Breadcrumbs, FAQ…), XML sitemaps, virtual robots.txt, redirects (incl. regex & 410).
- **Integrations** — Google Tag Manager, GA4, Search Console, Bing, Yandex, Pinterest, Meta Pixel, Microsoft Clarity, LinkedIn Insight, AdSense, plus custom header/body/footer code.
- **Media** — library with automatic optimization **on by default**: WebP, 87% quality, longest edge 1600px, transparency preserved, SEO-friendly file names, editable alt/title/caption, rename files (links update automatically).
- **AI assistant** — presets for Anthropic Claude, OpenAI, OpenRouter, Google Gemini, Groq, Mistral, DeepSeek, Ollama or any OpenAI-compatible endpoint. Improve/shorten/expand/fix text, generate FAQs, drafts, SEO meta, excerpts and image alt text.
- **Frontend editing** — logged-in editors click *Edit on page* to change titles, content, text and image fields right on the live site.
- **Speed** — one query loads all settings + content model; custom fields live on the row (no meta joins); full-page static cache serves visitors with **zero** database queries; HTML minification; lazy images with width/height.
- **Security** — Argon2id passwords, login rate-limiting & lockout, TOTP two-factor auth, custom admin URL, CSRF protection on every write, strict session handling, security headers/HSTS, upload MIME validation & no-execute uploads folder, encrypted secrets (API keys, 2FA), HTML sanitizer for non-admin roles, activity log.
- **Roles** — Administrator, Editor, Author, Contributor, Subscriber with granular capabilities.
- **Modules** — Slate’s name for plugins. Drop a folder into `/modules`, activate it in the admin.
- **Updates** — one-click core updates from GitHub Releases with automatic backup and rollback.

## Brand

Logo files live in `admin/assets/brand/` — `logo.svg` (wordmark), `logo-white.svg`, `logo-mark.svg`, `logo-mark-light.svg`, `logo-glyph.svg`, `favicon.svg` and `brand-sheet.png`.
Logo: a faceted origami songbird perched on a chalk line on a slate. Colours: brand orange `#F26B2D` (scale `#FFF5EF` → `#5C2008`) and ink `#0D0D0E`. Type: **Instrument Serif** for headings, **Inter** for UI text (self-hosted).

## Templates

**Templates** in the sidebar lets you design every page type without touching theme files: single pages for each post
type, archives, taxonomy archives, 404 and search. Write HTML with simple tags — `{{title}}`, `{{{content}}}`,
`{{field:tagline}}`, `{{field:photo|image}}`, `{{#each field:faqs}}{{item:question}}{{/each}}`,
`{{#query type=leaders limit=6}}…{{/query}}`, `{{#posts}}…{{/posts}}` — plus optional CSS. “Reset to starter” builds a
template that already shows every custom field. Preview it, then switch it **Active**.

## Migrating a WordPress site

1. **Modules** → activate **Auto CPT Generator** and **WordPress Importer**.
2. In WordPress: **Users → Profile → Application Passwords** → create one named “Slate”.
3. In Slate: **WordPress import** → enter the site URL, admin username and the application password → **Analyse site**.
4. Review what was detected, keep the options on (including *Mirror the design*) → **Start import**. Keep the tab open; it runs in resumable batches.
5. Check the site, then point the domain at Slate. Old URLs keep working (identical paths or 301 redirects).

Or over SSH, from the Slate folder (prompts for the application password, never stored in shell history):

```bash
php bin/slate import:wordpress --url=https://example.com --user=your-wp-admin
```

Elementor forms keep working: submissions appear under **Form submissions** and are emailed to the admin address.
After the domain switch, theme assets that were never requested during testing can no longer be fetched from the old server —
browse the main pages on the Slate URL before switching DNS so everything is cached locally.

## Requirements

- PHP **8.2+** with `pdo_mysql` or `pdo_sqlite`, `gd` (with WebP), `sodium`, `mbstring`, `curl`, `zip`, `fileinfo`
- MySQL 5.7+/MariaDB 10.3+ **or** SQLite 3 (zero-config)
- Apache with `mod_rewrite` (`.htaccess` included) or nginx (`nginx.conf.example` included)

## Install

1. Upload the files to your web root (or a sub-folder).
2. Make the root folder and `content/` writable by PHP.
3. Visit your site — the installer opens at `/install`. Choose MySQL or SQLite, create the admin account.
4. Sign in at `/admin` (you can change this URL under **Settings → Security**).

Local development:

```bash
php -S localhost:8000 dev-server.php
```

## Where things are

| Area | Location in admin |
|---|---|
| Content (posts, pages, your custom types) | Sidebar → Content |
| Post types / taxonomies / custom fields | Sidebar → Content model |
| Everything else (SEO, integrations, menus, media optimization, cache, security, AI, updates) | **Settings** → top tabs → left sections |

Settings tabs: **General** (identity, homepage, permalinks, locale, users) · **SEO** (titles, indexing, per-type defaults, sitemap, robots.txt, schema, social, redirects) · **Integrations** (Google, other services, custom code) · **Media** · **Appearance** (theme, menus) · **Performance** · **Security** · **AI** · **System** (updates, maintenance, info).

## Project structure

```
index.php            Front controller
core/                Framework (Slate\* classes), helpers, template API
admin/               Dashboard: routes, JSON API, views, assets (vanilla JS/CSS, no build step)
themes/default/      Default theme (templates/, partials/, assets/, theme.json, functions.php)
modules/             Modules (plugins) — hello-world example included
content/             Uploads, cache, backups, SQLite DB (never touched by updates)
```

## Theme API (cheat sheet)

```php
<?php partial('header'); ?>
<?= editable('title', 'h1') ?>              // editable on the live site
<?= editable('content') ?>
<?= e(field('client')) ?>                   // custom field
<?= image(field('hero'), ['class' => 'hero']) ?>
<?php foreach (query(['type' => 'project', 'limit' => 6]) as $p): ?>
  <a href="<?= e(permalink($p)) ?>"><?= e($p['title']) ?></a>
<?php endforeach; ?>
<?= menu('primary') ?>  <?= breadcrumbs() ?>  <?= paginate() ?>
```

Template hierarchy: `template-{name}` → `front-page` → `single-{type}-{slug}` → `single-{type}` / `page` → `single` → `index`; archives: `archive-{type}` → `archive`; taxonomies: `taxonomy-{tax}-{term}` → `taxonomy-{tax}` → `taxonomy` → `archive`. Also `home`, `search`, `404`, `maintenance`.

Always call `slate_head()`, `slate_body_open()` and `slate_footer()` — they output SEO tags, schema, analytics, custom code and the admin bar.

## Modules

```
modules/my-module/module.json   {"name":"My module","version":"1.0.0","requires":"0.1.0"}
modules/my-module/module.php
```

```php
use Slate\Hooks; use Slate\Modules;
Hooks::add('the_content', fn($html, $post) => $html . '<p>Thanks for reading</p>');
Hooks::add('seo_schema', fn($graph) => $graph);
Modules::adminPage('My module', 'my-module', fn() => print 'Settings UI');
```

Hooks: `init`, `head`, `body_open`, `footer`, `the_content`, `html_output`, `post_before_save`, `post_saved`, `post_deleted`, `media_uploaded`, `permalink`, `post_types`, `taxonomies`, `settings_tabs`, `settings_saved`, `seo_title`, `seo_description`, `seo_robots`, `seo_head`, `seo_schema`, `seo_schema_node`, `admin_routes`, `modules_loaded`.

## Releasing updates

The updater (Settings → System → Updates) reads **GitHub Releases** of `narendersinghh/slate` (configurable).

1. Bump `SLATE_VERSION` in `core/bootstrap.php` (and `SLATE_DB_VERSION` + a migration in `core/Slate/Schema.php` if the schema changes).
2. Update `CHANGELOG.md`, commit, tag and publish a release, e.g. `v0.2.0`.
3. Optionally attach a `slate.zip` asset; otherwise the source zipball is used.

Sites then see “Update available”. Updating backs up the current core to `content/backups/`, replaces `core/`, `admin/`, `themes/default/` and `index.php`, and never touches `config.php`, `content/`, `modules/` or other themes. Customise a copy of the default theme rather than editing it in place.

## Security notes

- Keep `config.php` private (it contains the encryption secret). The bundled `.htaccess`/nginx rules block direct access to it, to PHP files under `core/`, `themes/`, `modules/`, and to anything executable in `content/`.
- Enable HTTPS and then HSTS (Settings → Security → Headers).
- Turn on two-factor authentication for every administrator (Profile).

## Roadmap

- AI site assistant that can search and edit any content via tool calls, with approval
- Form builder & submissions inbox; loop/query blocks for frontend editing
- Revisions & autosave history, QR code for 2FA setup, multilingual content
