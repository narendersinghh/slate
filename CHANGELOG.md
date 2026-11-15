# Changelog

## 0.14.1: Builder safeguards
- Converting an item to a visual design saves a revision first and remembers the original content; "Use classic
  editor" restores it exactly (the design is kept for later).
- Content with template logic asks for confirmation before converting.
- The canvas shows a locked preview of the site's real header and footer (never saved into the page).
- A drop the builder does not take can no longer make the browser navigate.

## 0.14.0: Visual page builder
- **Visual builder** (GrapesJS 0.23.8, vendored, BSD-3): drag and drop, full style manager, desktop/tablet/mobile
  breakpoints, layers, undo/redo, HTML/CSS code view, saved reusable blocks, section starters (hero, features, CTA,
  testimonial, FAQ, image grid) and dynamic blocks (title, excerpt, form, latest posts) rendered by Slate.
  Your design-system colours and fonts come first and CSS variables work everywhere; images come from the media
  library. Styles are always class-based (no element ids), and visitors get plain HTML + CSS: no builder scripts,
  page-cached like any page. Inside the site header/footer or on a blank page. Saves create revisions.
- Optional at three levels: per item (classic editor or visual design, switch any time without losing either),
  per content type and site-wide (Settings → Content & media → Visual builder). The builder's files load only on
  the builder screen.
- Updates: `package.json` pins GrapesJS, `npm run vendor` copies it into `admin/assets/vendor/`; Dependabot proposes
  new versions after a 14-day cooldown, a Playwright test drags, styles, undoes, saves, reloads and checks the
  visitor page (screenshots kept as artifacts); patch versions merge into develop automatically when green, others
  wait for a person. Nothing is released to live automatically.
- Dashboard: stats first, site status and settings shortcuts in a compact side column; Settings expands in the
  sidebar and has a top-bar menu; every module page gets the same header and tabs (drawn by the core), wizards get
  a step indicator; themed scrollbars and file fields.
- No em dashes in the dashboard's text; the AI assistant avoids them too (Settings → AI assistant → Brand voice).

## 0.13.0: Cleaner dashboard
- **Sidebar**: Content, Modules (each module's pages grouped under its name, with counts), Design (Templates, Menus,
  Theme & styles) and Administration. Groups expand without leaving the page.
- **Settings reorganised**: one navigation with every category and section, a search box that finds any setting,
  an overview page, and new categories (Content & media, Email, Dashboard access). Old addresses redirect.
- **Site status** on the dashboard and settings overview: search-engine visibility, maintenance, page cache, email,
  backups, core files, dashboard address and your sign-in security, with quick switches.
- **List tables** (content, users, taxonomy terms, modules): column chooser with drag-to-reorder (remembered per user),
  custom-field columns, sortable columns, filters per taxonomy/author/month/search-engine visibility, compact or
  comfortable rows, page size, numbered pages, bulk actions on every matching row (publish, draft, add/remove term,
  change author, show/hide in search engines, trash), CSV export and **Quick edit**.
- **Private dashboard domain, guided**: Settings → Security → Dashboard access checks that the subdomain reaches this
  site, switches, and signs you in there with a one-time link; a **bridge kit** covers hosts that give subdomains
  their own folder; IP allow-list editor that refuses to lock you out.
- **For modules**: `Modules::settings()` (declarative settings, shown under Settings → Modules), `Slate\ListTable`,
  `Slate\AdminUi` (header, tabs, KPIs, empty states, pills), `icon` in module.json, page sections
  `main` / `settings` / `hidden`, `checkboxes` settings field, `Posts::query()` `month`, `noindex` and
  `orderby => 'field:name'`. Guide: docs/admin-ui.md.
- Modules list redesigned (filter by active/inactive/updates, Open and Settings links); phones get a settings jump
  menu and collapsible filters.

## 0.12.0: Hooks for modules
- `<html>` language and direction from `html_attrs()` / `html_lang` / `html_dir` filters (themes and Templates use it).
- Routing: `Frontend::resolving()`, `route_resolved` action (redirect/404 before rendering), `frontend_vars` filter.
- `Frontend::page()` and `Frontend::content()` render module pages through the theme and the normal output pipeline;
  `Theme::wrap()` never nests `<main>` when the theme's header already opens one (also fixes admin Templates on such themes).
- Sitemaps: `sitemap_posts`, `sitemap_entry`, `sitemap_urlset` filters (e.g. hreflang alternates).
- Menus: `menu_item` filter.
- Fix: saving or deleting a custom-field group no longer stores groups that modules add at runtime.
- Multilingual 1.1 and Shop 1.1 use these hooks (no more workarounds).

## 0.11.0: Module foundations
- `Posts::query(['fields' => […]])`: filter by custom-field values (null = not set), portable via `DB::jsonField()`.
- `posts_query_args` and `posts_query_sql` hooks: modules can add conditions to every content query (used by Multilingual).
- Module scanner: fixed a pattern that silently disabled the "writes files" / "sends email" checks; `$var()`,
  `->method()` and `::method()` calls no longer count, fully-qualified `\copy()` / `\mail()` still do.
- New official modules in slate-modules: **Comments**, **Multilingual** and **Shop** (products, cart, checkout, orders,
  Stripe/Razorpay).

## 0.10.0: Passkeys, webhooks, automatic tasks, faster cache
- **Passkeys** (WebAuthn): sign in with Face ID, Touch ID, Windows Hello, a phone or a security key; user verification
  required, counts as two-factor; ES256 and RS256; clone detection via signature counter. Profile → Passkeys.
- **Webhooks**: content created/updated/published/unpublished/deleted, media uploaded, form submitted, backup created,
  security alert (+ module events). Signed (HMAC-SHA256 with timestamp), sent after the response, retried with back-off,
  delivery log with retry, test button, SSRF protection. Settings → Integrations → Webhooks.
- **Scheduled tasks run automatically**: after any page view, cached pages included, without delaying the visitor
  (finishes the response first; self-trigger fallback elsewhere). No cron setup needed; a server cron stays optional.
- **Email works with no setup**: "Automatic" sending uses SMTP when configured, otherwise the server's mail().
- **Page cache** (optional, on by default): purges are instant at any size (generation counters instead of deleting
  files; outdated files are swept in the background); "smart" mode refreshes only the edited item + listing pages;
  important pages are re-warmed in the background; never-cache paths and bypass cookies for dynamic sites;
  `PageCache::skip()` / `page_cacheable` for modules.
- Private admin host without side effects: previews and View links stay on the admin host, passkeys bound to the parent
  domain, dashboard assets hidden on the public host.
- DB v3: passkeys, webhook_deliveries.

## 0.9.0: Security
- **Separate admin domain**: `admin_host` in config.php (or `php bin/slate admin:host`), the dashboard only answers on
  that host and is a plain 404 everywhere else; the public site on the admin host is only shown to signed-in team members.
- **Dashboard IP allow-list** (`admin_ips`, IPv4/IPv6 CIDR). Recovery from SSH: `admin:url`, `admin:host`, `admin:ips`.
- **File integrity monitor**: core verified against the official GitHub files of the installed commit/release, modules and
  other code against a trusted snapshot, executables and `.htaccess` changes in data folders always flagged; daily task,
  email alert, dashboard warning, `php bin/slate integrity:check | integrity:trust`. Installs/updates through Slate are
  trusted automatically.
- **Security check** page (Settings → Security): HTTPS, HSTS, dashboard address/host/IPs, 2FA coverage, debug, config.php
  permissions, PHP, backups, cron, email, API, risky module code, integrity.
- "Require two-factor auth for administrators" is now enforced (until set up, only the profile page is reachable).
- Email alert when someone with settings access signs in from a new address.
- No fingerprints: no `X-Slate-Cache` header (only with debug), no "cached by" comment, neutral cookie names
  (`sid`, `li`, everyone signs in again once) and element ids.
- `.htaccess`/nginx: blocked paths answer 404; hidden files, `module.json`/`theme.json` and `.example/.bak/.sql…` files blocked.
- Fix: a syntax error in a core file is no longer blamed on (and switches off) the module that happened to be loading.

## 0.8.0: Revisions, email, backups, scheduled tasks, REST API
- **Revisions**: every editor save keeps the previous version (title, content, excerpt, custom fields, SEO); compare with a
  line diff and restore in one click (restoring is undoable too). Settings → General → Writing & revisions (default 25 per item).
- **Email**: one mail system for core and modules, the server's mail() or **SMTP** (STARTTLS/SSL, login), HTML + text,
  test button. Settings → General → Email. `Slate\Mailer::send()`; `mail_send` filter lets a module deliver (e.g. an API service).
- **Forgot password**: reset links by email (one hour, single use, never reveal whether an account exists, rate limited
  with the login lockout); a reset signs the account out everywhere else.
- **Backups**: database (pure PHP, works without mysqldump) and optional files archive in `content/backups/`; daily/weekly
  schedule, retention, download, delete. `php bin/slate backup:create [--files] | backup:list | backup:restore <file>`
  (restore makes a safety backup first).
- **Scheduled tasks**: `cron_jobs` filter for core and modules; runs from a server cron (`php bin/slate cron`) or after
  visits when none is set up. Built-in jobs: scheduled publishing, cleanup (old login attempts, activity log after N days,
  stale temp files and AI drafts), automatic backups, module update checks. Settings → System → Scheduled tasks.
- **REST API** at `/api/v1` (off by default): list/get content with filters and pagination, terms, menus, media; create,
  update and delete with API keys (Profile → API keys; read or read & write, act with the user's permissions, shown once,
  stored hashed); CORS allow-list; `api_routes` / `api_post` / `api_types` hooks.
- Example module removed from the core; `modules/` ships empty.
- Settings `show_if` supports `!=`.

## 0.7.0: Modules live outside the core
- Forms, Site Migration and Auto CPT Generator moved to [slate-modules](https://github.com/narendersinghh/slate-modules),
  which is also the default registry: install them from **Modules → Available**. Slate itself ships only the
  `hello-world` example; installed modules are no longer tracked by the core repository, so `git pull` never touches them.
- Site Migration declares its dependency on Auto CPT Generator.
- `cli_commands` hook: modules add `php bin/slate` commands. `import:wordpress` now comes from the Site Migration module.
- Unknown CLI commands report an error instead of silently printing help.
- **Download** any installed module as an installable zip (Modules → Download, or `php bin/slate module:export <slug>`),
  so site-specific modules can stay on the server and still be reused elsewhere.

## 0.6.0: Open module system
- **Install modules from anywhere**: upload a zip, paste a GitHub repository or a folder inside one
  (`…/tree/main/my-module`, private repositories with a token), any zip URL, or one click from **registries**, plain JSON lists anyone can publish (Modules → Sources). Modules → Available lists everything your registries offer,
  installed or not, with optional sha256 checksums.
- **Updates** for modules from their registry or source, with automatic backup to `content/backups/modules/`, rollback
  on failure and one-click restore of previous versions. Delete modules (optionally running their `uninstall.php`).
- **Create modules** from a blank template or with the **AI module builder**: describe it, review the code and what it
  can do, then install. Developer guide: `docs/modules.md`.
- **Code check** before install/activation: syntax errors are refused; a plain-language list shows what the code can do
  (shell commands, evaluated code, file writes, network calls, own tables…).
- **Crash protection**: a module that fails while loading is switched off automatically and reported on the Modules page, the site stays up. `content/safe-mode` (or `php bin/slate safe-mode:on`) starts without modules.
- Manifest: `depends` with version constraints (activated together, protected from deactivation), `requires_php`,
  PSR-4 `autoload`, `source`, `settings`, `tags`. Per-module `migrations/` run once on activation and after updates
  (also after a git pull or FTP upload).
- Modules can register public URLs with the new `routes` hook (`$r->get('/cart', …)`).
- `'modules_locked' => true` in config.php switches installing/updating/deleting off for hardened sites.
- CLI: `module:list|make|install|activate|deactivate|update|check|delete`, `safe-mode:on|off`.
- `.htaccess`/nginx: `.phtml`, `.phar`, `.php5`, `.inc` files in core, modules, themes and content are blocked too.

## 0.5.1: Security hardening
- Security headers: cached pages now send exactly the same headers as uncached ones (HSTS was missing on cached pages, and
  switching headers off had no effect on them). New **Content-Security-Policy** setting (Settings → Security → Headers) with a
  safe default (`base-uri 'self'; object-src 'none'; frame-ancestors 'self'`), plus `Cross-Origin-Opener-Policy`.
  `X-Powered-By` is removed from cached pages too; the obsolete `interest-cohort` permission is now `browsing-topics`.
- Behind a proxy/CDN, the visitor IP is taken from the right-most `X-Forwarded-For` entry (added by the proxy), so clients
  can no longer forge their IP to dodge login rate limiting.
- PHP 8.4: no more deprecation warnings for session ID settings on every admin request.
- Core docs and examples are now site-neutral.

## 0.5.0: Live templates
- Templates engine: modules can add tags (`template_tag`), conditions (`template_lookup`) and loop blocks (`template_block`);
  `{{term.*}}` values and `{{#each term.field:…}}` on taxonomy archives; dotted paths (`{{item.fields.color}}`);
  templates for one specific item (target `page__home`); filters `|texturize` `|slug` `|pad2` `|host` `|strip` `|upper` `|number`.
- `texturize()` helper (curly quotes, dashes, ellipses outside tags/scripts).
- Hooks `route_path` (modules map their URL schemes) and `term_link`.
- Site Mirror: items created in Slate and listings rendered by Templates get the design's assets automatically.
- Site modules can now render every page of a site from live content through Templates instead of frozen copies.

## 0.4.0: Forms, richer custom fields, brand-neutral mirror
- **Forms module**: build forms under Content → Forms (text, email, phone, URL, number, paragraph, dropdown, radio, checkboxes,
  date, consent, hidden and text blocks; required, width, placeholder, help). Place a saved form anywhere with the **Form** button
  in any editor, a **Form** custom field, `{{form:slug}}` in Templates, `[form slug="…"]` or `slate_form('slug')` in themes.
  AJAX submit with no-JS fallback, honeypot + rate limiting, email notifications with Reply-To, optional redirect.
- **Submissions inbox** (sidebar → Submissions): filter by form / status / text, New · Read · Spam, bulk actions, detail view with
  reply-by-email, CSV export. Form posts to the previous site's endpoints are stored here too.
- Custom fields: new **Taxonomy terms**, **Form** and **Tab** field types; relationship fields can target several post types.
- Site Mirror theme (was "WordPress mirror"): page-builder class names, script globals and asset paths are renamed to neutral
  `slatekit` names and served from `/content/assets/`; missing assets are fetched once and renamed automatically.
- Dashboard: migration tools renamed ("Migration"), no third-party product names in the admin.

## 0.3.5
- WordPress Importer + mirror theme: the site's own "page not found" template is captured and used for 404s.

## 0.3.4
- WordPress Importer: the blog listing (posts page, e.g. `/blog/`) is captured with its Elementor archive template.
- WordPress Importer: post types whose archive URL is a 404 on WordPress get their archive switched off, matching the source.
- WordPress Importer: `start` accepts `options.only` (e.g. `["archives"]`) to re-run selected steps on an existing import.
- Mirror theme: fallback pagination styles no longer leak into mirrored archive templates.

## 0.3.3
- Mirror theme: pages and archives with several Elementor CSS sets (e.g. archive templates) keep all of them in the
  global / local slots, in their original order.

## 0.3.2
- WordPress mirror theme: a page's own Elementor `global-N` / `local-N` stylesheets now take the place of the captured
  page's files in the head, keeping WordPress's cascade order (fixes per-element sizes being overridden by generic rules).

## 0.3.1
- Database layer reconnects and retries once when a shared host closes an idle MySQL connection ("server has gone away").

## 0.3.0: Templates, URL tokens, faithful Elementor Pro migration
- **Templates** (sidebar → Templates): site-wide templates for every post type (single + archive), every taxonomy and the
  404 / search pages. HTML + safe tags (`{{title}}`, `{{field:name}}`, `{{#each field:repeater}}`, `{{#query …}}`, `{{#posts}}`,
  `{{#if}}`, filters like `|image` `|date` `|embed`), per-template CSS, starter generator that lists every custom field,
  click-to-insert tag reference, preview before activating. Active templates override the theme (and imported snapshots).
- Routing: post-type URLs with taxonomy tokens (`%nationality%/profile` → `/in/profile/sadhguru/`), multi-segment bases
  (`us/role`), root-level taxonomy archives (`/in/`), flat or nested child-term URLs.
- Importer: exact ACF field types/labels from ACF REST metadata, date conversion, relationship targets; URL token detection;
  Rank Math SEO + rich schema (Person, Book, Event, FAQ…) from the rendered page; snapshots of Elementor Pro single templates
  and archive pages so dynamic layouts look identical; parallel page fetching; mirrored assets reused across pages.

## 0.2.1
- `php bin/slate import:wordpress --url=… --user=…`, run the full WordPress import from the command line (SSH), with a hidden
  password prompt; activates the modules automatically and can be re-run to sync. Also `cache:clear` and `version`.
- `bin/` is blocked from web access (.htaccess, nginx example, dev server).

## 0.2.0: WordPress migration, design system, new brand
- New brand: origami-bird-on-slate logo, Instrument Serif + Inter, #F26B2D palette; redesigned admin (light & dark).
- **Design system** (Settings → Appearance → Design system): global colours, fonts, type scale, layout tokens and global CSS classes
  for the website, exposed as CSS variables and mapped to Elementor `--e-global-*` variables; optional "match the admin to my website".
- **Auto CPT Generator** module: create post types, taxonomies and custom fields from a live WordPress site, a WXR export,
  a plain-English description (AI) or JSON.
- **WordPress Importer** module: REST API (application password) or WXR import of settings, content model, terms, media
  (auto-optimized), all post types, ACF fields (incl. repeaters, images, relations), SEO (Yoast, Rank Math, or any plugin via page head),
  menus, front/blog page, logo, permalink structure, identical URLs (hierarchical pages & categories) and automatic 301s for anything that differs.
  Resumable batches; re-running updates instead of duplicating.
- **Design mirror**: captures theme / Elementor CSS, JS, fonts, header, footer, popups, per-page Elementor CSS and single-post layouts
  into the new "WordPress mirror" theme; brand colours & fonts imported into the design system.
- Compatibility layer: old `/wp-content/uploads/…` URLs 301 to optimized copies, missing theme/plugin assets are fetched on demand,
  `?p=` / `?page_id=` links redirect, Elementor form submissions are stored (Form submissions inbox) and emailed.
- Updater: falls back to tags when no release exists; disabled automatically on Git-deployed sites (updates arrive via push).
- Hierarchical taxonomy URLs (/category/parent/child/), Posts::flush(), lazy options API for modules, PHP blocked under wp-content/.

## 0.1.0: Initial release
- Core: PHP 8.2+, MySQL/MariaDB or SQLite, front controller, hooks, modules.
- Admin: modern dashboard, command palette (⌘K), dark mode, responsive.
- Roles & capabilities, Argon2id passwords, login rate limiting, TOTP two-factor, custom admin URL, CSRF, security headers, activity log.
- Content model: custom post types, taxonomies and field groups (20 field types incl. repeater, gallery, relation).
- Rich text editor with AJAX save, scheduling, revisions-ready schema, duplicate, bulk actions.
- Media library with automatic optimization (WebP, 87% quality, 1600px max edge, transparency preserved), SEO file renaming, alt text.
- SEO: titles/meta templates, per-item SEO panel with live Google preview & analysis, robots meta, noindex site, canonical, Open Graph/Twitter, JSON-LD schema graph, breadcrumbs, XML sitemaps, virtual robots.txt, redirects manager.
- Integrations: GTM, GA4, Search Console, Bing, Yandex, Pinterest, Meta Pixel, Clarity, LinkedIn, AdSense, custom head/body/footer code.
- AI assistant with presets: Anthropic Claude, OpenAI, OpenRouter, Google Gemini, Groq, Mistral, DeepSeek, Ollama, custom.
- Frontend (on-page) editing for titles, content, text and image fields.
- Full-page static cache, HTML minification, menus, maintenance mode.
- Core updates from GitHub Releases with automatic backup.
