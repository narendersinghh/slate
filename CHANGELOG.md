# Changelog

## 0.3.1
- Database layer reconnects and retries once when a shared host closes an idle MySQL connection ("server has gone away").

## 0.3.0 — Templates, URL tokens, faithful Elementor Pro migration
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
- `php bin/slate import:wordpress --url=… --user=…` — run the full WordPress import from the command line (SSH), with a hidden
  password prompt; activates the modules automatically and can be re-run to sync. Also `cache:clear` and `version`.
- `bin/` is blocked from web access (.htaccess, nginx example, dev server).

## 0.2.0 — WordPress migration, design system, new brand
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

## 0.1.0 — Initial release
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
