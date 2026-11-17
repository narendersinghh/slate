<?php
declare(strict_types=1);

namespace Slate;

/**
 * Site-building tools of the MCP server (Mcp.php is the transport, auth and the content tools): content model, terms,
 * templates, design system, menus, SEO, on-page editing, page imports, media details, settings, modules and the key's user,
 * plus the guide "How to build a site with Slate" (MCP prompt "build_site" and resource slate://guide/build-site).
 *
 * Every tool reuses the dashboard's own validation and save functions (ContentModel::clean…, Templates::save with its
 * versions, DesignSystem::save, Menus::save, Seo::save…, StructuredData::saveAdmin, Settings::save, PageEditor::apply),
 * checks the dashboard capability ('cap'), needs a read & write key for changes ('write') and confirm:true for deletions
 * ('destructive'). Writes are logged as "mcp_…".
 *
 * Deliberately not exposed: users (except reading the key's own user), module install/activate/upload (runs code),
 * secrets of any kind, security settings, email/SMTP, AI provider keys, update tokens, the Google service account,
 * API/webhook/MCP settings, custom head/body code, backups and scheduled tasks.
 */
final class McpSite
{
    public const GUIDE_URI = 'slate://guide/build-site';
    private const MAX_HTML = 1024 * 1024;
    private const MAX_CSS = 512 * 1024;

    /** Groups (and capabilities) of the tools defined in Mcp.php. */
    public const CORE_GROUPS = [
        'get_site' => 'Site', 'list_content' => 'Content', 'get_content' => 'Content', 'create_content' => 'Content', 'update_content' => 'Content',
        'search' => 'Content', 'get_revisions' => 'Content', 'restore_revision' => 'Content', 'write_page' => 'Designed pages',
        'list_templates' => 'Templates', 'get_template' => 'Templates', 'list_media' => 'Media', 'upload_media_from_url' => 'Media',
    ];

    /** Capabilities of the tools defined in Mcp.php (their handlers check them too). */
    public const CORE_CAPS = [
        'create_content' => 'edit_posts', 'update_content' => 'edit_posts', 'write_page' => 'edit_posts', 'restore_revision' => 'edit_posts',
        'list_templates' => 'manage_content_model', 'get_template' => 'manage_content_model', 'list_media' => 'edit_posts', 'upload_media_from_url' => 'upload_media',
    ];

    /** Settings the MCP server may read and change (tab => sections). Everything else is not exposed. */
    private const SETTINGS = [
        'general' => ['identity', 'reading', 'permalinks', 'locale'],
        'content' => ['writing', 'optimization', 'uploads'],
        'appearance' => ['theme'],
        'seo' => ['titles', 'indexing', 'sitemap', 'robots', 'schema', 'social'],
        'integrations' => ['analytics', 'verification'],
        'performance' => ['cache', 'output'],
        'ai' => ['voice'],
        'system' => ['maintenance'],
    ];
    /** Keys inside allowed sections that stay hidden (personal data, upload types that could allow scripts). */
    private const DENY_KEYS = ['admin_email', 'media_allowed'];
    /** Any key looking like a secret is never exposed, whatever section it is in. */
    private const SECRET_KEY = '/secret|token|password|passwd|_pass$|^pass_|api_?key|private|credential|^smtp_|_key$|service_account|^security_/i';

    /** What the MCP server deliberately leaves out (shown by describe_capabilities). */
    public const EXCLUDED = [
        'Users and roles (only the key\'s own user can be read with get_current_user).',
        'Installing, uploading, activating, updating or deleting modules (it runs code). Modules can only be listed.',
        'Secrets of any kind: API keys, AI provider keys, update tokens, the Google service-account key, IndexNow key management.',
        'Security settings (dashboard address, IP lists, headers and CSP, two-factor, sessions), email/SMTP, REST API, webhooks and MCP settings.',
        'Custom head/body/footer code, backups, scheduled tasks and core updates.',
        'Deleting content items (move them to draft instead, or ask the user).',
        'Reading form entries unless the Forms module allows it (Forms settings).',
    ];

    // ------------------------------------------------------------------ registry

    private static function obj(array $props, array $required = []): array
    {
        $s = ['type' => 'object', 'additionalProperties' => false, 'properties' => $props ?: new \stdClass()];
        if ($required) {
            $s['required'] = $required;
        }
        return $s;
    }

    private static function confirm(): array
    {
        return ['type' => 'boolean', 'description' => 'Must be true. Deleting cannot be undone here: ask the user first.'];
    }

    public static function tools(): array
    {
        $str = static fn(int $max, string $desc = '', int $min = 0): array => array_filter(['type' => 'string', 'maxLength' => $max, 'minLength' => $min ?: null,
            'description' => $desc ?: null], static fn($v) => $v !== null);
        $slug = $str(39, 'Lowercase slug: letters, numbers, dashes or underscores, starting with a letter.', 2);
        $ops = ['type' => 'array', 'minItems' => 1, 'maxItems' => 200, 'items' => ['type' => 'object', 'additionalProperties' => true, 'required' => ['op']],
            'description' => 'Operations, applied in order and saved together (one revision): '
                . '{"op":"text","id":"e12","html":"New <strong>text</strong>"} (inline tags only), {"op":"attr","id":"e3","name":"href","value":"/contact/"}, '
                . '{"op":"image","id":"e7","src":"https://…/photo.webp","alt":"…"} (or "media": id), {"op":"style","id":"e4","style":{"color":"var(--color-primary)"}}, '
                . '{"op":"tag","id":"e5","tag":"h2"}, {"op":"remove","id":"e9"}, {"op":"loop","id":"l1","action":"duplicate|remove|move","index":0,"to":2}, '
                . '{"op":"loop_dynamic","id":"l1","source":{"kind":"posts","type":"post","limit":3,"orderby":"published_at","order":"desc"},"map":{"e20":"title","e21":"excerpt","link:0":"url"}}, '
                . '{"op":"loop_static","id":"l1"}, {"op":"field","bind":"post:12:title","value":"…"}.'];
        $fieldDef = ['type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'type'], 'properties' => [
            'name' => $str(60, 'Machine name (a-z, 0-9, _), used as {{field:name}} in templates and in fields of create_content.'),
            'label' => $str(200),
            'type' => ['type' => 'string', 'enum' => array_keys(ContentModel::FIELD_TYPES)],
            'required' => ['type' => 'boolean'],
            'help' => $str(500), 'placeholder' => $str(200), 'default' => $str(1000),
            'width' => ['type' => 'string', 'enum' => ['25', '33', '50', '66', '75', '100']],
            'options' => ['type' => 'array', 'maxItems' => 200, 'items' => ['type' => 'string', 'maxLength' => 200], 'description' => 'Choices of select, radio and checkbox fields.'],
            'post_type' => $str(200, 'Relationship fields: post type slugs they may point to, comma separated (empty = any).'),
            'taxonomy' => $str(39, 'Taxonomy fields: the taxonomy slug.'),
            'sub_fields' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'object'], 'description' => 'Repeater fields: the fields of one row (same shape, one level deep).'],
        ]];
        $menuItem = ['type' => 'object', 'additionalProperties' => false, 'required' => ['label'], 'properties' => [
            'label' => $str(120, 'Link text (empty for post/term links = their title).'),
            'type' => ['type' => 'string', 'enum' => ['custom', 'post', 'term'], 'description' => 'custom = url; post = a content item (object_id); term = a taxonomy term (object_id). Default custom.'],
            'url' => $str(500, 'For custom links: /path/, https://…, mailto: or tel:.'),
            'object_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Item or term id for post/term links.'],
            'new_tab' => ['type' => 'boolean'],
            'class' => $str(100, 'CSS classes for the menu item (e.g. "btn").'),
            'children' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'object'], 'description' => 'Sub-menu items (same shape, up to 3 levels).'],
        ]];
        $colors = ['type' => 'array', 'maxItems' => 40, 'items' => self::obj(['id' => $str(40, 'e.g. "primary"; becomes var(--color-ID), .text-ID, .bg-ID.'),
            'name' => $str(40), 'value' => $str(60, 'Hex (#1a2b3c), rgb(), rgba(), hsl() or transparent.')], ['id', 'value'])];
        $classes = ['type' => 'array', 'maxItems' => 100, 'items' => self::obj(['name' => $str(60, 'Class name without the dot.'),
            'css' => $str(4000, 'CSS declarations only, e.g. "padding:1rem;border-radius:var(--radius);" (no braces or selectors).'),
            'hover' => $str(4000, 'Declarations for :hover (optional).')], ['name'])];
        $vars = ['type' => 'array', 'maxItems' => 100, 'items' => self::obj(['name' => $str(60, 'Without the leading dashes, e.g. "shadow-card" for var(--shadow-card).'),
            'value' => $str(300)], ['name', 'value'])];
        $typeEntry = self::obj(['font' => ['type' => 'string', 'enum' => ['heading', 'body']], 'size' => ['type' => 'number', 'minimum' => 8, 'maximum' => 160, 'description' => 'px'],
            'weight' => ['type' => 'integer', 'minimum' => 100, 'maximum' => 900], 'line' => ['type' => 'number', 'minimum' => 0.8, 'maximum' => 3],
            'tracking' => ['type' => 'number', 'minimum' => -0.2, 'maximum' => 0.5, 'description' => 'em']]);
        $seoType = ['title_template' => $str(200, 'e.g. "%title% %sep% %site%". Variables: %title% %site% %tagline% %sep% %year% %page% %category%.'),
            'robots' => ['type' => 'string', 'enum' => ['index', 'noindex']], 'sitemap' => ['type' => 'boolean', 'description' => 'Include in the XML sitemap.']];
        $siteCap = ['manage_menus', 'manage_settings'];

        $t = [];
        // -------------------------------------------------------------- site
        $t['describe_capabilities'] = ['group' => 'Site', 'title' => 'What this key can do',
            'description' => 'Every tool grouped by feature, whether this key may use it (and if not, why), and what the MCP server deliberately does not expose. Use get_site for the site itself.',
            'inputSchema' => self::obj([])];
        $t['get_current_user'] = ['group' => 'Site', 'title' => 'The key\'s user',
            'description' => 'The user this API key acts as: name, role, capabilities and the key (name, scope). Other users are not available.',
            'inputSchema' => self::obj([])];
        $t['list_modules'] = ['group' => 'Site', 'title' => 'List modules', 'cap' => ['manage_modules', 'manage_settings'],
            'description' => 'Installed modules: name, version, active or not, and their dashboard pages. Read-only: installing or activating modules is not possible through MCP.',
            'inputSchema' => self::obj([])];
        // -------------------------------------------------------------- content model
        $t['get_content_model'] = ['group' => 'Content model', 'title' => 'Content model', 'cap' => 'manage_content_model',
            'description' => 'All post types, taxonomies and custom field groups with their full definitions, plus the field types, "supports" features and icons you can use.',
            'inputSchema' => self::obj([])];
        $t['save_post_type'] = ['group' => 'Content model', 'title' => 'Create or change a post type', 'write' => true, 'cap' => 'manage_content_model',
            'description' => 'Create a post type (e.g. "service", "project", "team") or change one: send only what changes. New types are public with an archive at /<rewrite>/ unless set otherwise. '
                . 'Rename with original_slug (existing items move along). Add custom fields with save_field_group and taxonomies with save_taxonomy.',
            'inputSchema' => self::obj([
                'slug' => $slug, 'original_slug' => $str(39, 'Current slug when renaming.'),
                'singular' => $str(60, 'e.g. "Service".'), 'plural' => $str(60, 'e.g. "Services".'),
                'description' => $str(500), 'public' => ['type' => 'boolean', 'description' => 'Has pages on the website. Default true for new types.'],
                'has_archive' => ['type' => 'boolean', 'description' => 'Listing page at /<rewrite>/. Default true.'],
                'hierarchical' => ['type' => 'boolean', 'description' => 'Items can have parents (like pages).'],
                'rewrite' => $str(100, 'URL base, e.g. "services" (items at /services/<slug>/). Default: the slug.'),
                'supports' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'enum' => array_keys(ContentModel::SUPPORTS)],
                    'description' => 'Editor features. Default for new types: title, editor, excerpt, featured_image, seo, frontend_edit.'],
                'icon' => $str(40, 'Dashboard icon name (see get_content_model icons).'),
                'menu_position' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 200],
                'show_in_menu' => ['type' => 'boolean'],
            ], ['slug'])];
        $t['delete_post_type'] = ['group' => 'Content model', 'title' => 'Delete a post type', 'write' => true, 'destructive' => true, 'cap' => 'manage_content_model',
            'description' => 'Delete a custom post type (post and page cannot be deleted). Its items stay in the database unless delete_content is true (then they are deleted permanently).',
            'inputSchema' => self::obj(['slug' => $slug, 'delete_content' => ['type' => 'boolean'], 'confirm' => self::confirm()], ['slug'])];
        $t['save_taxonomy'] = ['group' => 'Content model', 'title' => 'Create or change a taxonomy', 'write' => true, 'cap' => 'manage_content_model',
            'description' => 'Create a taxonomy (e.g. "service-category") for one or more post types, or change one (send only what changes). Then add terms with save_term or the terms argument of create_content.',
            'inputSchema' => self::obj([
                'slug' => $slug, 'original_slug' => $str(39, 'Current slug when renaming.'),
                'singular' => $str(60), 'plural' => $str(60),
                'post_types' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string', 'maxLength' => 39], 'description' => 'Post type slugs that use it.'],
                'hierarchical' => ['type' => 'boolean', 'description' => 'Terms can have parents (like categories). Default false (like tags).'],
                'public' => ['type' => 'boolean', 'description' => 'Term archive pages on the website. Default true.'],
                'rewrite' => $str(100, 'URL base of term archives. Default: the slug.'),
            ], ['slug'])];
        $t['delete_taxonomy'] = ['group' => 'Content model', 'title' => 'Delete a taxonomy', 'write' => true, 'destructive' => true, 'cap' => 'manage_content_model',
            'description' => 'Delete a custom taxonomy and all its terms (category and tag cannot be deleted). Content items stay.',
            'inputSchema' => self::obj(['slug' => $slug, 'confirm' => self::confirm()], ['slug'])];
        $t['save_field_group'] = ['group' => 'Content model', 'title' => 'Create or change custom fields', 'write' => true, 'cap' => 'manage_content_model',
            'description' => 'Create a group of custom fields shown on post types and/or taxonomies, or change one by id (fields, when sent, replace the group\'s fields). '
                . 'Field types: ' . implode(', ', array_keys(ContentModel::FIELD_TYPES)) . '. Repeaters take sub_fields; relation fields point to items of post_type; image/file fields store media ids. '
                . 'Values are set with create_content/update_content fields and shown in templates with {{field:name}}.',
            'inputSchema' => self::obj([
                'id' => $str(20, 'Existing group id (from get_content_model) to change it.'),
                'title' => $str(120),
                'position' => ['type' => 'string', 'enum' => ['main', 'side'], 'description' => 'Where the group shows in the editor.'],
                'post_types' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string', 'maxLength' => 39]],
                'taxonomies' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string', 'maxLength' => 39]],
                'fields' => ['type' => 'array', 'maxItems' => 100, 'items' => $fieldDef],
            ])];
        $t['delete_field_group'] = ['group' => 'Content model', 'title' => 'Delete custom fields', 'write' => true, 'destructive' => true, 'cap' => 'manage_content_model',
            'description' => 'Delete a field group. Values already stored on content stay but are no longer edited or shown by name.',
            'inputSchema' => self::obj(['id' => $str(20), 'confirm' => self::confirm()], ['id'])];
        // -------------------------------------------------------------- terms
        $t['list_terms'] = ['group' => 'Terms', 'title' => 'List terms',
            'description' => 'Terms of a taxonomy (categories, tags, custom) with id, slug, parent, description, custom fields, item count and URL.',
            'inputSchema' => self::obj(['taxonomy' => $str(39), 'search' => $str(100), 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Default 100.']], ['taxonomy'])];
        $t['save_term'] = ['group' => 'Terms', 'title' => 'Create or change a term', 'write' => true, 'cap' => 'manage_terms',
            'description' => 'Create a term in a taxonomy, or change one by id (send only what changes): name, slug, description (HTML), parent, custom fields of the taxonomy, SEO.',
            'inputSchema' => self::obj(['taxonomy' => $str(39), 'id' => ['type' => 'integer', 'minimum' => 1], 'name' => $str(100), 'slug' => $str(100),
                'description' => $str(20000), 'parent_id' => ['type' => ['integer', 'null']], 'fields' => ['type' => 'object', 'additionalProperties' => true],
                'seo' => self::obj(['title' => $str(300), 'description' => $str(500), 'robots_index' => ['type' => 'string', 'enum' => ['default', 'index', 'noindex']],
                    'og_image' => ['type' => ['integer', 'null']]])], ['taxonomy'])];
        $t['delete_term'] = ['group' => 'Terms', 'title' => 'Delete a term', 'write' => true, 'destructive' => true, 'cap' => 'manage_terms',
            'description' => 'Delete a term. Content using it stays.',
            'inputSchema' => self::obj(['id' => ['type' => 'integer', 'minimum' => 1], 'confirm' => self::confirm()], ['id'])];
        // -------------------------------------------------------------- designed pages, on-page editing, import
        $t['get_page_structure'] = ['group' => 'Designed pages', 'title' => 'Editable parts of a page',
            'description' => 'The parts of an item that can be edited one by one (the same ids the on-page editor uses): text elements, images, links (id "e…") and repeating groups (id "l…", with their items). '
                . 'Works for designed pages (write_page, imports) and for normal content. Then change them with edit_page.',
            'inputSchema' => self::obj(['id' => ['type' => 'integer', 'minimum' => 1]], ['id'])];
        $t['edit_page'] = ['group' => 'Designed pages', 'title' => 'Edit parts of a page', 'write' => true, 'cap' => ['edit_posts'],
            'description' => 'Change parts of a page by id, like a person clicking on the live page: replace a text, a link, an image, duplicate or remove an item of a repeating group, '
                . 'or connect a repeating group to live content (loop_dynamic). Ids come from get_page_structure. Saved together with one revision.',
            'inputSchema' => self::obj(['id' => ['type' => 'integer', 'minimum' => 1], 'ops' => $ops], ['id', 'ops'])];
        $t['import_pages_from_url'] = ['group' => 'Designed pages', 'title' => 'Import pages from a website', 'write' => true, 'cap' => 'unfiltered_html',
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true],
            'description' => 'Fetch public web pages (with their CSS, images, fonts and scripts) and save each as a designed page, editable by clicking on the live page. '
                . 'design "keep" keeps the page\'s own CSS, "site" uses this site\'s design system. Pages are drafts unless publish is true. Needs a user allowed to use unfiltered HTML (imported pages bring their own scripts).',
            'inputSchema' => self::obj([
                'urls' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 2000]],
                'follow_links' => ['type' => 'boolean', 'description' => 'Also import the pages these pages link to on the same site (up to 20 pages).'],
                'design' => ['type' => 'string', 'enum' => ['keep', 'site']],
                'type' => $str(39, 'Content type of the new items. Default "page".'),
                'publish' => ['type' => 'boolean'],
            ], ['urls'])];
        // -------------------------------------------------------------- templates
        $t['save_template'] = ['group' => 'Templates', 'title' => 'Create or change a template', 'write' => true, 'cap' => 'manage_content_model',
            'description' => 'Create a site template or change one by id (send only what changes; the previous HTML/CSS is kept as a version, undo_template puts it back). '
                . 'kind/target: single/<post type> (every item of a type; single/<type>__<slug> for one item), archive/<post type> (listing), term/<taxonomy> (term archive), special/404 or special/search. '
                . 'Only one template per kind/target is active: enabled:true switches the others off. inside_theme:true renders it between the theme header and footer. '
                . 'Without html, a new template starts from Slate\'s starter for that kind. HTML uses template tags, not PHP: {{title}} {{{content}}} {{excerpt}} {{url}} {{date}} {{image}} {{image_url}} '
                . '{{field:name}} {{{field:name}}} {{field:photo|image}} {{terms:category}} {{#if field:name}}…{{else}}…{{/if}} {{#each field:repeater}}{{item:sub}}{{/each}} '
                . '{{#each related:field}}…{{/each}} {{#posts}}…{{else}}No items{{/posts}} (archives) {{{pagination}}} {{#query type=post limit=3 orderby=title order=asc term=category:news}}…{{/query}} '
                . '{{#terms taxonomy=category}}{{item.name}}{{/terms}} {{site.name}} {{menu:primary}} {{{breadcrumbs}}} {{archive.title}} {{form:contact}}. '
                . 'Use the design-system variables and classes (get_site). Scripts are removed for users without unfiltered HTML.',
            'inputSchema' => self::obj([
                'id' => $str(20, 'Existing template id (list_templates) to change it.'),
                'name' => $str(120),
                'kind' => ['type' => 'string', 'enum' => array_keys(Templates::KINDS)],
                'target' => $str(120, 'Post type, "type__slug", taxonomy, "404" or "search".'),
                'html' => $str(self::MAX_HTML),
                'css' => $str(self::MAX_CSS, 'CSS for this template (plain CSS, may use var(--color-primary) etc.).'),
                'inside_theme' => ['type' => 'boolean', 'description' => 'Wrap in the theme header and footer. Default true for new templates.'],
                'enabled' => ['type' => 'boolean', 'description' => 'Make it the active template for its kind/target. Default false for new templates.'],
            ])];
        $t['undo_template'] = ['group' => 'Templates', 'title' => 'Undo a template change', 'write' => true, 'cap' => 'manage_content_model',
            'description' => 'Put back the previous HTML and CSS of a template (each save keeps up to 20 earlier versions).',
            'inputSchema' => self::obj(['id' => $str(20)], ['id'])];
        $t['delete_template'] = ['group' => 'Templates', 'title' => 'Delete a template', 'write' => true, 'destructive' => true, 'cap' => 'manage_content_model',
            'description' => 'Delete a template. Its pages then render with the theme (or another active template).',
            'inputSchema' => self::obj(['id' => $str(20), 'confirm' => self::confirm()], ['id'])];
        $t['get_template_structure'] = ['group' => 'Templates', 'title' => 'Editable parts of a template', 'cap' => 'manage_content_model',
            'description' => 'The static parts of a template that can be changed one by one (ids "te…", "tl…"): headings, texts, buttons, images outside of list blocks and template tags. Change them with edit_template_parts.',
            'inputSchema' => self::obj(['id' => $str(20)], ['id'])];
        $t['edit_template_parts'] = ['group' => 'Templates', 'title' => 'Edit parts of a template', 'write' => true, 'cap' => 'manage_content_model',
            'description' => 'Change static parts of a template by id (from get_template_structure), with the same operations as edit_page (text, attr, image, style, tag, remove, loop). '
                . 'Only the changed bytes are rewritten; the previous version is kept (undo_template). For bigger changes use save_template.',
            'inputSchema' => self::obj(['id' => $str(20), 'ops' => $ops], ['id', 'ops'])];
        // -------------------------------------------------------------- design system
        $t['get_design_system'] = ['group' => 'Design system', 'title' => 'Design system', 'cap' => $siteCap,
            'description' => 'The full design system (Appearance › Styles): colours, fonts, type scale, layout, global classes (with hover), custom CSS variables, and the font names you can choose from.',
            'inputSchema' => self::obj([])];
        $t['update_design_system'] = ['group' => 'Design system', 'title' => 'Change the design system', 'write' => true, 'cap' => $siteCap,
            'description' => 'Change the site-wide styles; send only what changes. Colours, classes and variables are added or replaced by id/name (remove_* deletes). '
                . 'Every colour becomes var(--color-ID) with .text-ID and .bg-ID classes; fonts become var(--font-heading)/var(--font-body); type sizes var(--fs-h1)… and var(--type-h1)…; '
                . 'layout var(--container) var(--radius) var(--radius-button) var(--section-space). Global classes are usable everywhere (.btn, .container, .section, .eyebrow are built in). Do this first when building a site.',
            'inputSchema' => self::obj([
                'colors' => $colors, 'remove_colors' => ['type' => 'array', 'maxItems' => 40, 'items' => ['type' => 'string', 'maxLength' => 40]],
                'fonts' => self::obj(['heading' => $str(60, 'Any Google font name (e.g. "Playfair Display") or system-ui, Georgia, ui-monospace.'), 'body' => $str(60),
                    'google' => ['type' => 'boolean', 'description' => 'Load the fonts from Google Fonts.']]),
                'type' => self::obj(array_fill_keys(array_keys(DesignSystem::TYPE_KEYS), $typeEntry)),
                'layout' => self::obj(['container' => ['type' => 'integer', 'minimum' => 600, 'maximum' => 2400, 'description' => 'Max content width, px.'],
                    'radius' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 60], 'button_radius' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 60],
                    'section_space' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 300, 'description' => 'Vertical padding of .section, px.']]),
                'classes' => $classes, 'remove_classes' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'string', 'maxLength' => 60]],
                'vars' => $vars, 'remove_vars' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'string', 'maxLength' => 60]],
                'apply_elements' => ['type' => 'boolean', 'description' => 'Style h1-h6, body text and small globally (not only through classes).'],
                'responsive' => ['type' => 'boolean', 'description' => 'Scale headings down on small screens.'],
            ])];
        // -------------------------------------------------------------- menus
        $t['list_menus'] = ['group' => 'Menus', 'title' => 'List menus', 'cap' => $siteCap,
            'description' => 'All menus with their items (resolved URLs) and the theme\'s menu locations (e.g. primary, footer) with the menu assigned to each.',
            'inputSchema' => self::obj([])];
        $t['save_menu'] = ['group' => 'Menus', 'title' => 'Create or change a menu', 'write' => true, 'cap' => $siteCap,
            'description' => 'Create a menu or replace one by id: name, location (a theme location from list_menus; assigning it takes it from any other menu) and items (custom links, content items, terms; nested children for sub-menus). '
                . 'Shown by the theme and in templates with {{menu:<location>}}.',
            'inputSchema' => self::obj([
                'id' => $str(40, 'Existing menu id to replace it.'), 'name' => $str(100),
                'location' => ['type' => ['string', 'null'], 'maxLength' => 40, 'description' => 'Theme location, "" or null for none.'],
                'items' => ['type' => 'array', 'maxItems' => 200, 'items' => $menuItem],
            ])];
        $t['delete_menu'] = ['group' => 'Menus', 'title' => 'Delete a menu', 'write' => true, 'destructive' => true, 'cap' => $siteCap,
            'description' => 'Delete a menu (its location then shows nothing).',
            'inputSchema' => self::obj(['id' => $str(40), 'confirm' => self::confirm()], ['id'])];
        // -------------------------------------------------------------- SEO
        $t['get_seo_settings'] = ['group' => 'SEO', 'title' => 'SEO settings', 'cap' => 'manage_settings',
            'description' => 'Global SEO settings (titles, indexing, sitemap, robots.txt, organisation schema, social), the defaults per content type and taxonomy (title template, robots, schema type, sitemap), '
                . 'the schema types you can use, and which instant-indexing engines are on. Change global settings with update_settings (keys starting with seo_), defaults with set_seo_defaults.',
            'inputSchema' => self::obj([])];
        $t['set_seo_defaults'] = ['group' => 'SEO', 'title' => 'SEO defaults of a type or taxonomy', 'write' => true, 'cap' => 'manage_settings',
            'description' => 'Set the SEO defaults of one content type (title template, robots, schema type, sitemap, default share image) or one taxonomy (title template, robots, sitemap, archive schema). Send type or taxonomy.',
            'inputSchema' => self::obj($seoType + [
                'type' => $str(39), 'taxonomy' => $str(39),
                'schema' => ['type' => 'string', 'enum' => array_keys(Seo::SCHEMA_TYPES), 'description' => 'Schema.org type of the items (types only).'],
                'og_image' => ['type' => ['integer', 'null'], 'description' => 'Default share image (media id) of the type.'],
                'archive_schema' => ['type' => 'string', 'enum' => ['collection', 'itemlist'], 'description' => 'Taxonomies: CollectionPage or ItemList on term archives.'],
            ])];
        $t['get_schema_mapping'] = ['group' => 'SEO', 'title' => 'Schema mapping of a type', 'cap' => 'manage_settings',
            'description' => 'For a content type and a schema type (default: the type\'s schema), the schema.org properties (required ones marked), the sources they can come from (built-in values, custom fields, taxonomies) and the current mapping.',
            'inputSchema' => self::obj(['type' => $str(39), 'schema' => ['type' => 'string', 'enum' => array_keys(Seo::SCHEMA_TYPES)]], ['type'])];
        $t['set_schema_mapping'] = ['group' => 'SEO', 'title' => 'Map schema properties', 'write' => true, 'cap' => 'manage_settings',
            'description' => 'Set the schema type of a content type and where its properties come from: map {"offers.price": "field:price", "name": "@title", "brand.name": "=Acme"} '
                . '(sources from get_schema_mapping; "=text" is a fixed value; "" clears). Properties not sent keep their mapping. The JSON-LD of every item of the type follows.',
            'inputSchema' => self::obj(['type' => $str(39), 'schema' => ['type' => 'string', 'enum' => array_keys(Seo::SCHEMA_TYPES)],
                'map' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'maxLength' => 500]]], ['type', 'schema'])];
        $t['list_redirects'] = ['group' => 'SEO', 'title' => 'List redirects', 'cap' => 'manage_settings',
            'description' => 'Redirect rules (from, to, code, regex).', 'inputSchema' => self::obj([])];
        $t['add_redirects'] = ['group' => 'SEO', 'title' => 'Add redirects', 'write' => true, 'cap' => 'manage_settings',
            'description' => 'Add redirect rules, replacing rules with the same "from". from is a path ("/old-page/"), or a regular expression with regex:true ("^/blog/(.*)$" to "/news/$1"). code 301 (default), 302, 307, 308, or 410 (gone, no target).',
            'inputSchema' => self::obj(['redirects' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'items' => self::obj([
                'from' => $str(500), 'to' => $str(1000), 'code' => ['type' => 'integer', 'enum' => [301, 302, 307, 308, 410]], 'regex' => ['type' => 'boolean']], ['from'])]], ['redirects'])];
        $t['remove_redirects'] = ['group' => 'SEO', 'title' => 'Remove redirects', 'write' => true, 'cap' => 'manage_settings',
            'description' => 'Remove the redirect rules with these "from" values.',
            'inputSchema' => self::obj(['from' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'items' => ['type' => 'string', 'maxLength' => 500]]], ['from'])];
        $t['request_indexing'] = ['group' => 'SEO', 'title' => 'Request indexing', 'write' => true, 'cap' => 'publish_posts',
            'description' => 'Ask search engines (Google Indexing API, IndexNow) to crawl published items now, like "Request indexing" in the editor. Needs instant indexing switched on under SEO. '
                . 'ids: published items; urls: addresses on this site (needs manage_settings).',
            'inputSchema' => self::obj(['ids' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'integer', 'minimum' => 1]],
                'urls' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'string', 'maxLength' => 2000]]])];
        // -------------------------------------------------------------- media
        $t['update_media'] = ['group' => 'Media', 'title' => 'Change media details', 'write' => true, 'cap' => 'upload_media',
            'description' => 'Set the alt text, title or caption of a media item, or rename its file (SEO-friendly name; links in content follow).',
            'inputSchema' => self::obj(['id' => ['type' => 'integer', 'minimum' => 1], 'alt' => $str(255), 'title' => $str(255), 'caption' => $str(1000),
                'filename' => $str(100, 'New file name without extension.')], ['id'])];
        // -------------------------------------------------------------- settings
        $t['get_settings'] = ['group' => 'Settings', 'title' => 'Read settings',
            'description' => 'Site settings this key may see, grouped: site identity, homepage & reading, permalinks, date & language, revisions, image optimisation, uploads, theme, SEO, analytics IDs, site verification, '
                . 'cache & HTML output, AI brand voice, maintenance mode. Secrets, security, email and integration credentials are never included.',
            'inputSchema' => self::obj(['group' => ['type' => 'string', 'enum' => array_keys(self::SETTINGS)]])];
        $t['update_settings'] = ['group' => 'Settings', 'title' => 'Change settings', 'write' => true,
            'description' => 'Change settings by key (keys from get_settings): {"values": {"site_title": "Acme", "front_page": "page", "front_page_id": 12, "seo_sitemap_enabled": true}}. '
                . 'Values are validated like in the dashboard; unknown or protected keys are refused.',
            'inputSchema' => self::obj(['values' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'number', 'integer', 'boolean', 'array', 'null']]]], ['values'])];
        foreach ($t as $name => &$tool) {
            $tool['handler'] = [self::class, 'tool' . str_replace('_', '', ucwords($name, '_'))];
        }
        unset($tool);
        return $t;
    }

    // ------------------------------------------------------------------ guide: prompt + resource

    public static function guide(): string
    {
        $forms = Modules::isActive('slate-forms');
        return "# How to build a site with Slate\n\n"
            . "Slate is a CMS: content lives in typed items (post types with custom fields and taxonomies), pages are rendered by templates or saved as complete designed pages, "
            . "and a global design system provides CSS variables and classes. Build in this order and check each step on the live site.\n\n"
            . "1. **Look around.** get_site (types, fields, templates, menus, design system, modules, your tools). describe_capabilities shows what this key may do.\n"
            . "2. **Design system first.** update_design_system: brand colours (each becomes var(--color-ID), .text-ID, .bg-ID), heading and body fonts, type scale, container width, radius, "
            . "section spacing, and global classes with hover states (e.g. .card, .btn-ghost). Everything after this uses these variables, so the site stays consistent.\n"
            . "3. **Content model.** save_post_type for each kind of item (services, projects, team, products), save_taxonomy for groupings, save_field_group for custom fields "
            . "(text, image, repeater, relation, select…). Field names become {{field:name}} in templates.\n"
            . "4. **Templates.** save_template with kind single (one item), archive (listing of a type), term (a taxonomy archive), special 404/search. Use template tags, not PHP: "
            . "{{title}}, {{{content}}}, {{field:price}}, {{field:photo|image}}, {{#each field:faq}}{{item:question}}{{/each}}, {{#posts}}…{{/posts}}, "
            . "{{#query type=service limit=3}}<a href=\"{{url}}\">{{title}}</a>{{/query}}, {{menu:primary}}, {{site.name}}. Set enabled:true to make it live. "
            . "undo_template reverts a change.\n"
            . "5. **Pages.** write_page for designed pages (home, landing pages): a complete HTML document with its CSS in <head>, responsive, using the design-system variables, "
            . "real copy and alt texts. Template tags work inside it ({{#query}} lists of live items, {{form:slug}}). create_content for ordinary pages and items, with fields and terms. "
            . "Everything is a draft until publish:true; ask the user before publishing. Fine-tune with get_page_structure + edit_page. Set the homepage with update_settings "
            . "(front_page: \"page\", front_page_id: <id>).\n"
            . "6. **Images.** upload_media_from_url with a descriptive filename and alt text; use the returned url or id (featured_media_id, image fields).\n"
            . "7. **Menus.** save_menu with items pointing at items (type post, object_id), terms or custom URLs, and a theme location from list_menus (primary, footer).\n"
            . "8. **SEO.** seo on create_content/update_content (title, description, schema_type), set_seo_defaults per type (title template, schema), set_schema_mapping "
            . "(e.g. Service, Product, Event properties from custom fields), add_redirects for old URLs, update_settings for site-wide SEO (seo_title_template, seo_schema_name, "
            . "seo_sitemap_enabled…), request_indexing after publishing.\n"
            . ($forms ? "9. **Forms.** save_form (fields, messages, notification email), then place it with {{form:slug}} in a page or template, or [form slug=\"…\"] in content. list_forms shows entry counts.\n"
                : "9. **Forms.** Activate the Forms module in the dashboard to get form tools (save_form) and {{form:slug}}.\n")
            . "\nRules: never invent ids, look them up; keep revisions (every content change keeps one, templates keep versions); deleting needs confirm:true and the user's consent; "
            . "secrets, users, security settings and module installs are not available through MCP.";
    }

    public static function prompts(): array
    {
        return [['name' => 'build_site', 'title' => 'How to build a site with Slate',
            'description' => 'Step by step: design system, content model, templates, pages, menus, SEO and forms, with the tools for each step.',
            'arguments' => [['name' => 'brief', 'description' => 'What the site is about (optional).', 'required' => false]]]];
    }

    public static function getPrompt(array $params): array
    {
        if (($params['name'] ?? '') !== 'build_site') {
            throw new McpError(-32602, 'Unknown prompt: ' . (is_string($params['name'] ?? null) ? $params['name'] : '(none)'));
        }
        $brief = trim(mb_substr((string)(is_scalar($params['arguments']['brief'] ?? null) ? $params['arguments']['brief'] : ''), 0, 4000));
        $text = self::guide() . ($brief !== '' ? "\n\n## The site to build\n\n" . $brief : '');
        return ['description' => 'How to build a site with Slate', 'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]]];
    }

    public static function resources(): array
    {
        return [['uri' => self::GUIDE_URI, 'name' => 'build-site-guide', 'title' => 'How to build a site with Slate', 'mimeType' => 'text/markdown',
            'description' => 'The order to build in and the tools for each step.']];
    }

    public static function readResource(array $params): array
    {
        if (($params['uri'] ?? '') !== self::GUIDE_URI) {
            throw new McpError(-32002, 'Resource not found: ' . (is_string($params['uri'] ?? null) ? $params['uri'] : '(none)'));
        }
        return ['contents' => [['uri' => self::GUIDE_URI, 'mimeType' => 'text/markdown', 'text' => self::guide()]]];
    }

    // ------------------------------------------------------------------ site

    /** Extra parts of get_site: menus and locations, templates, active modules, the guide. */
    public static function siteExtras(): array
    {
        $locations = (array)(Theme::meta()['menus'] ?? []);
        $menus = [];
        foreach (Menus::all() as $m) {
            $menus[] = ['id' => $m['id'], 'name' => $m['name'], 'location' => ($m['location'] ?? '') ?: null, 'items' => self::countItems((array)$m['items'])];
        }
        $out = [
            'menus' => $menus,
            'menu_locations' => array_map(static fn($k, $label) => ['location' => $k, 'label' => $label, 'menu_id' => Menus::byLocation((string)$k)['id'] ?? null],
                array_keys($locations), $locations),
            'modules' => array_values(array_map(static fn($m) => ['slug' => $m['slug'], 'name' => $m['name'] ?? $m['slug'], 'version' => (string)($m['version'] ?? '')],
                array_filter(Modules::all(), static fn($m) => !empty($m['active'])))),
            'guide' => 'Read the prompt "build_site" or the resource ' . self::GUIDE_URI . ' before building a whole site.',
        ];
        if (Auth::can('manage_content_model')) {
            $out['templates'] = array_values(array_map(static fn($t) => ['id' => $t['id'], 'name' => $t['name'], 'kind' => $t['kind'], 'target' => $t['target'],
                'active' => !empty($t['enabled'])], Templates::all()));
            $out['field_groups'] = array_values(array_map(static fn($g) => ['id' => $g['id'] ?? '', 'title' => $g['title'] ?? '',
                'post_types' => (array)($g['location']['post_types'] ?? []), 'taxonomies' => (array)($g['location']['taxonomies'] ?? []),
                'fields' => count((array)($g['fields'] ?? []))], ContentModel::fieldGroups()));
        }
        return $out;
    }

    private static function countItems(array $items): int
    {
        $n = 0;
        foreach ($items as $it) {
            $n += 1 + self::countItems((array)($it['children'] ?? []));
        }
        return $n;
    }

    public static function toolDescribeCapabilities(array $a): array
    {
        $groups = [];
        foreach (Mcp::allTools() as $name => $tool) {
            $why = Mcp::toolBlocked($tool);
            $groups[(string)($tool['group'] ?? 'Modules')][] = array_filter(['tool' => $name, 'title' => $tool['title'] ?? $name, 'writes' => !empty($tool['write']),
                'deletes' => !empty($tool['destructive']) ?: null, 'available' => $why === null, 'why_not' => $why], static fn($v) => $v !== null);
        }
        $u = Auth::user();
        return ['site' => (string)Options::get('site_title'), 'user' => $u['display_name'] ?? '', 'role' => $u['role'] ?? '', 'key_scope' => Mcp::scope(),
            'groups' => $groups, 'not_available_through_mcp' => self::EXCLUDED, 'guide' => self::GUIDE_URI . ' (or the prompt build_site)'];
    }

    public static function toolGetCurrentUser(array $a): array
    {
        $u = (array)Auth::user();
        $role = (string)($u['role'] ?? '');
        $caps = array_values(array_filter(array_keys(Roles::CAPS), static fn($c) => Auth::can($c)));
        $key = Mcp::key();
        return ['id' => (int)($u['id'] ?? 0), 'username' => (string)($u['username'] ?? ''), 'display_name' => (string)($u['display_name'] ?? ''),
            'role' => $role, 'role_label' => Roles::label($role), 'capabilities' => $caps,
            'key' => ['name' => (string)($key['name'] ?? ''), 'prefix' => 'slt_' . ($key['prefix'] ?? '') . '_…', 'scope' => Mcp::scope(),
                'created_at' => Mcp::iso($key['created_at'] ?? null), 'last_used' => Mcp::iso($key['last_used'] ?? null)]];
    }

    public static function toolListModules(array $a): array
    {
        $pages = [];
        foreach (Modules::adminPages() as $p) {
            if (($p['section'] ?? '') !== 'hidden' && !empty($p['module'])) {
                $pages[$p['module']][] = ['label' => $p['label'], 'url' => Mcp::absAdmin('m/' . $p['slug'])];
            }
        }
        $settings = Modules::settingsSections();
        $out = [];
        foreach (Modules::all() as $slug => $m) {
            $out[] = ['slug' => $slug, 'name' => (string)($m['name'] ?? $slug), 'version' => (string)($m['version'] ?? ''), 'active' => !empty($m['active']),
                'description' => (string)($m['description'] ?? ''), 'admin_pages' => $pages[$slug] ?? [], 'has_settings' => isset($settings[$slug])];
        }
        return ['modules' => $out, 'note' => 'Read-only. Installing, activating, updating and deleting modules runs code and is only possible in the dashboard.'];
    }

    // ------------------------------------------------------------------ content model

    public static function toolGetContentModel(array $a): array
    {
        $stored = (array)(Options::get('field_groups') ?: []);
        $groups = [];
        foreach (ContentModel::fieldGroups() as $id => $g) {
            $groups[] = ['id' => (string)($g['id'] ?? $id), 'title' => (string)($g['title'] ?? ''), 'position' => (string)($g['position'] ?? 'main'),
                'post_types' => (array)($g['location']['post_types'] ?? []), 'taxonomies' => (array)($g['location']['taxonomies'] ?? []),
                'editable' => isset($stored[$id]), 'fields' => array_map([Mcp::class, 'fieldDef'], (array)($g['fields'] ?? []))];
        }
        $storedTypes = (array)(Options::get('post_types') ?: []);
        $types = [];
        foreach (ContentModel::types() as $slug => $d) {
            $types[] = ['slug' => $slug, 'singular' => $d['singular'] ?? $slug, 'plural' => $d['plural'] ?? $slug, 'public' => ($d['public'] ?? true) !== false,
                'has_archive' => !empty($d['has_archive']), 'hierarchical' => !empty($d['hierarchical']), 'rewrite' => (string)($d['rewrite'] ?? ''),
                'supports' => array_values((array)($d['supports'] ?? [])), 'icon' => (string)($d['icon'] ?? ''), 'description' => (string)($d['description'] ?? ''),
                'builtin' => isset(ContentModel::builtinTypes()[$slug]), 'editable' => isset(ContentModel::builtinTypes()[$slug]) || isset($storedTypes[$slug]),
                'taxonomies' => array_keys(ContentModel::taxonomiesFor($slug)), 'items' => (int)DB::val('SELECT COUNT(*) FROM {{posts}} WHERE type = :t', ['t' => $slug])];
        }
        $storedTax = (array)(Options::get('taxonomies') ?: []);
        $taxes = [];
        foreach (ContentModel::taxonomies() as $slug => $d) {
            $taxes[] = ['slug' => $slug, 'singular' => $d['singular'] ?? $slug, 'plural' => $d['plural'] ?? $slug, 'post_types' => array_values((array)($d['post_types'] ?? [])),
                'hierarchical' => !empty($d['hierarchical']), 'public' => ($d['public'] ?? true) !== false, 'rewrite' => (string)($d['rewrite'] ?? ''),
                'builtin' => isset(ContentModel::builtinTaxonomies()[$slug]), 'editable' => isset(ContentModel::builtinTaxonomies()[$slug]) || isset($storedTax[$slug])];
        }
        return ['post_types' => $types, 'taxonomies' => $taxes, 'field_groups' => $groups, 'field_types' => ContentModel::FIELD_TYPES,
            'supports' => ContentModel::SUPPORTS, 'icons' => Icons::names(),
            'note' => 'editable:false means the definition comes from a module or is built in code; it cannot be changed here.'];
    }

    public static function toolSavePostType(array $a): array
    {
        $original = isset($a['original_slug']) ? (string)$a['original_slug'] : null;
        $current = ContentModel::type($original ?? (string)$a['slug']);
        $stored = (array)(Options::get('post_types') ?: []);
        $key = $original ?? (string)$a['slug'];
        if ($original !== null && !$current) {
            throw new ToolError("There is no post type “{$original}” to rename.");
        }
        if ($current && !isset(ContentModel::builtinTypes()[$key]) && !isset($stored[$key])) {
            throw new ToolError("The post type “{$key}” comes from a module and cannot be changed here.");
        }
        $in = array_intersect_key($a, array_flip(['slug', 'singular', 'plural', 'description', 'public', 'has_archive', 'hierarchical', 'rewrite', 'supports', 'icon', 'menu_position', 'show_in_menu']));
        if ($current) {
            $in += $current;
            $original ??= $key;
        } else {
            $in += ['public' => true, 'has_archive' => true, 'show_in_menu' => true, 'supports' => ['title', 'editor', 'excerpt', 'featured_image', 'seo', 'frontend_edit']];
        }
        $def = ContentModel::cleanTypeDef($in, $original);
        if (is_string($def)) {
            throw new ToolError($def);
        }
        ContentModel::saveType($def, $original);
        PageCache::clear();
        log_activity('mcp_post_type_saved', $def['slug']);
        return ['saved' => true, 'created' => !$current, 'post_type' => $def,
            'archive_url' => $def['public'] && $def['has_archive'] && $def['rewrite'] !== '' && !str_contains($def['rewrite'], '%') ? site_url(ltrim(Posts::trail('/' . $def['rewrite']), '/')) : null];
    }

    public static function toolDeletePostType(array $a): array
    {
        $slug = (string)$a['slug'];
        if (!ContentModel::type($slug) || !isset(((array)(Options::get('post_types') ?: []))[$slug]) && !isset(ContentModel::builtinTypes()[$slug])) {
            throw new ToolError("No custom post type “{$slug}”.");
        }
        if ($err = ContentModel::removeType($slug, !empty($a['delete_content']))) {
            throw new ToolError($err);
        }
        PageCache::clear();
        log_activity('mcp_post_type_deleted', $slug . (!empty($a['delete_content']) ? ' (with its items)' : ''));
        return ['deleted' => true, 'slug' => $slug, 'items_deleted' => !empty($a['delete_content'])];
    }

    public static function toolSaveTaxonomy(array $a): array
    {
        $original = isset($a['original_slug']) ? (string)$a['original_slug'] : null;
        $key = $original ?? (string)$a['slug'];
        $current = ContentModel::taxonomy($key);
        $stored = (array)(Options::get('taxonomies') ?: []);
        if ($original !== null && !$current) {
            throw new ToolError("There is no taxonomy “{$original}” to rename.");
        }
        if ($current && !isset(ContentModel::builtinTaxonomies()[$key]) && !isset($stored[$key])) {
            throw new ToolError("The taxonomy “{$key}” comes from a module and cannot be changed here.");
        }
        $in = array_intersect_key($a, array_flip(['slug', 'singular', 'plural', 'post_types', 'hierarchical', 'public', 'rewrite']));
        $warnings = [];
        if (isset($in['post_types']) && ($bad = array_diff($in['post_types'], array_keys(ContentModel::types())))) {
            $warnings[] = 'Ignored unknown post types: ' . implode(', ', $bad) . '.';
        }
        if ($current) {
            $in += $current;
            $original ??= $key;
        } else {
            $in += ['public' => true, 'rewrite' => (string)$a['slug']];
        }
        $def = ContentModel::cleanTaxonomyDef($in, $original);
        if (is_string($def)) {
            throw new ToolError($def);
        }
        ContentModel::saveTaxonomy($def, $original);
        PageCache::clear();
        log_activity('mcp_taxonomy_saved', $def['slug']);
        return ['saved' => true, 'created' => !$current, 'taxonomy' => $def] + ($warnings ? ['warnings' => $warnings] : []);
    }

    public static function toolDeleteTaxonomy(array $a): array
    {
        $slug = (string)$a['slug'];
        if (!ContentModel::taxonomy($slug) || !isset(((array)(Options::get('taxonomies') ?: []))[$slug]) && !isset(ContentModel::builtinTaxonomies()[$slug])) {
            throw new ToolError("No custom taxonomy “{$slug}”.");
        }
        if ($err = ContentModel::removeTaxonomy($slug)) {
            throw new ToolError($err);
        }
        PageCache::clear();
        log_activity('mcp_taxonomy_deleted', $slug);
        return ['deleted' => true, 'slug' => $slug];
    }

    public static function toolSaveFieldGroup(array $a): array
    {
        $stored = (array)(Options::get('field_groups') ?: []);
        $id = (string)($a['id'] ?? '');
        $current = $id !== '' ? ($stored[$id] ?? null) : null;
        if ($id !== '' && !$current) {
            throw new ToolError(isset(ContentModel::fieldGroups()[$id]) ? "The field group “{$id}” comes from a module and cannot be changed here." : "No field group with id “{$id}”.");
        }
        if (!$current && empty($a['fields'])) {
            throw new ToolError('Send the fields of the new group.');
        }
        $warnings = [];
        foreach (['post_types' => ContentModel::types(), 'taxonomies' => ContentModel::taxonomies()] as $k => $known) {
            if (isset($a[$k]) && ($bad = array_diff($a[$k], array_keys($known)))) {
                $warnings[] = "Ignored unknown $k: " . implode(', ', $bad) . '.';
            }
        }
        $data = [
            'id' => $current ? $id : '',
            'title' => $a['title'] ?? ($current['title'] ?? 'Fields'),
            'position' => $a['position'] ?? ($current['position'] ?? 'main'),
            'location' => ['post_types' => $a['post_types'] ?? ($current['location']['post_types'] ?? []), 'taxonomies' => $a['taxonomies'] ?? ($current['location']['taxonomies'] ?? [])],
            'fields' => $a['fields'] ?? ($current['fields'] ?? []),
        ];
        $group = ContentModel::cleanFieldGroup($data);
        if (isset($a['fields']) && count($group['fields']) < count($a['fields'])) {
            $warnings[] = 'Some fields were skipped: duplicate or empty names, or unknown types.';
        }
        // Two groups on the same type must not use the same field names.
        foreach (ContentModel::fieldGroups() as $gid => $g) {
            if ((string)$gid === $group['id'] || !array_intersect((array)($g['location']['post_types'] ?? []), $group['location']['post_types'])) {
                continue;
            }
            if ($dup = array_intersect(array_column((array)($g['fields'] ?? []), 'name'), array_column($group['fields'], 'name'))) {
                $warnings[] = 'Field names also used by the group “' . ($g['title'] ?? $gid) . '” on the same type: ' . implode(', ', $dup) . '.';
            }
        }
        ContentModel::saveFieldGroup($group);
        log_activity('mcp_field_group_saved', $group['title']);
        return ['saved' => true, 'created' => !$current, 'field_group' => $group] + ($warnings ? ['warnings' => $warnings] : []);
    }

    public static function toolDeleteFieldGroup(array $a): array
    {
        $id = (string)$a['id'];
        $stored = (array)(Options::get('field_groups') ?: []);
        if (!isset($stored[$id])) {
            throw new ToolError("No field group with id “{$id}” that can be deleted.");
        }
        ContentModel::deleteFieldGroup($id);
        log_activity('mcp_field_group_deleted', (string)($stored[$id]['title'] ?? $id));
        return ['deleted' => true, 'id' => $id];
    }

    // ------------------------------------------------------------------ terms

    private static function term(array $t): array
    {
        return ['id' => (int)$t['id'], 'taxonomy' => $t['taxonomy'], 'name' => $t['name'], 'slug' => $t['slug'], 'parent_id' => $t['parent_id'] ? (int)$t['parent_id'] : null,
            'description' => (string)($t['description'] ?? ''), 'count' => (int)($t['count'] ?? 0), 'fields' => ((array)($t['fields'] ?? [])) ?: new \stdClass(),
            'seo' => ((array)($t['seo'] ?? [])) ?: new \stdClass(), 'url' => Terms::link($t, true)];
    }

    private static function taxonomyOrFail(string $tax): array
    {
        $def = ContentModel::taxonomy($tax);
        if (!$def || (($def['public'] ?? true) === false && !Auth::can('manage_terms'))) {
            throw new ToolError("Unknown taxonomy “{$tax}”. Available: " . implode(', ', array_keys(ContentModel::taxonomies())) . '.');
        }
        return $def;
    }

    public static function toolListTerms(array $a): array
    {
        $tax = (string)$a['taxonomy'];
        self::taxonomyOrFail($tax);
        $q = trim((string)($a['search'] ?? ''));
        $all = array_values(array_filter(Terms::all($tax), static fn($t) => $q === '' || stripos($t['name'] . ' ' . $t['slug'], $q) !== false));
        return ['taxonomy' => $tax, 'total' => count($all), 'terms' => array_map([self::class, 'term'], array_slice($all, 0, (int)($a['limit'] ?? 100)))];
    }

    public static function toolSaveTerm(array $a): array
    {
        $tax = (string)$a['taxonomy'];
        self::taxonomyOrFail($tax);
        $current = null;
        if (isset($a['id'])) {
            $current = Terms::find((int)$a['id']);
            if (!$current || $current['taxonomy'] !== $tax) {
                throw new ToolError("No $tax term with id {$a['id']}.");
            }
        }
        $name = trim(strip_tags((string)($a['name'] ?? ($current['name'] ?? ''))));
        if ($name === '') {
            throw new ToolError('Give the term a name.');
        }
        $parent = array_key_exists('parent_id', $a) ? (int)$a['parent_id'] : (int)($current['parent_id'] ?? 0);
        if ($parent && (!($p = Terms::find($parent)) || $p['taxonomy'] !== $tax)) {
            throw new ToolError("Parent $parent is not a $tax term.");
        }
        $unfiltered = Auth::can('unfiltered_html');
        $desc = array_key_exists('description', $a) ? (string)$a['description'] : (string)($current['description'] ?? '');
        $defs = ContentModel::fieldsFor('term', $tax);
        $warnings = [];
        if (isset($a['fields']) && ($unknown = array_diff(array_keys($a['fields']), array_keys($defs)))) {
            $warnings[] = 'Ignored unknown fields: ' . implode(', ', $unknown) . '.';
        }
        $fields = (array)($current['fields'] ?? []);
        foreach (array_intersect_key((array)($a['fields'] ?? []), $defs) as $k => $v) {
            $fields[$k] = ContentModel::sanitizeValue($defs[$k], $v, $unfiltered);
        }
        $seo = (array)($current['seo'] ?? []);
        if (isset($a['seo'])) {
            $seo = array_intersect_key(Seo::cleanItemSeo($a['seo']), $a['seo']) + $seo;
        }
        $id = Terms::save($tax, ['name' => $name, 'slug' => (string)($a['slug'] ?? ($current['slug'] ?? '')), 'description' => $unfiltered ? $desc : Sanitizer::html($desc),
            'parent_id' => $parent, 'fields' => $fields, 'seo' => $seo], $current ? (int)$current['id'] : null);
        Terms::flush();
        log_activity('mcp_term_saved', "$tax:$name");
        return ['saved' => true, 'created' => !$current, 'term' => self::term((array)Terms::find($id))] + ($warnings ? ['warnings' => $warnings] : []);
    }

    public static function toolDeleteTerm(array $a): array
    {
        $t = Terms::find((int)$a['id']);
        if (!$t) {
            throw new ToolError("No term with id {$a['id']}.");
        }
        Terms::delete((int)$t['id']);
        PageCache::clear();
        log_activity('mcp_term_deleted', "{$t['taxonomy']}:{$t['name']}");
        return ['deleted' => true, 'id' => (int)$t['id'], 'name' => $t['name']];
    }

    // ------------------------------------------------------------------ on-page editing

    /** Editable elements of an annotated body: [{id, tag, text|src/alt, href}]. */
    private static function elements(string $body): array
    {
        [$doc, $root] = HtmlPages::fragment($body);
        $xp = new \DOMXPath($doc);
        $out = [];
        foreach ($xp->query('.//*[@data-se][not(ancestor::template)]', $root) ?: [] as $el) {
            /** @var \DOMElement $el */
            if (count($out) >= 600) {
                break;
            }
            $tag = strtolower($el->tagName);
            $row = ['id' => $el->getAttribute('data-se'), 'tag' => $tag];
            if ($tag === 'img') {
                $row['src'] = $el->getAttribute('src');
                $row['alt'] = $el->getAttribute('alt');
            } elseif (PageEditor::isIcon($el)) {
                $row['kind'] = 'icon';
            } else {
                $row['text'] = mb_substr(trim((string)preg_replace('/\s+/', ' ', $el->textContent)), 0, 160);
            }
            if ($tag === 'a' || $el->hasAttribute('href')) {
                $row['href'] = $el->getAttribute('href');
            }
            $out[] = $row;
        }
        return $out;
    }

    private static function loopsOut(array $loops): array
    {
        return array_map(static fn($l) => ['id' => $l['id'], 'dynamic' => $l['dynamic'], 'items' => array_map(static fn($it) => ['label' => $it['label'], 'ids' => $it['ids']], $l['items']),
            'fields' => $l['fields']], $loops);
    }

    public static function toolGetPageStructure(array $a): array
    {
        $post = Mcp::findPost((int)$a['id'], true);
        if (HtmlPages::active($post)) {
            $body = (string)(HtmlPages::data($post)['body'] ?? '');
            $kind = 'designed page';
        } else {
            $ann = PageEditor::annotateContent((string)$post['content']);
            if (!$ann) {
                return ['id' => (int)$post['id'], 'kind' => 'content', 'elements' => [], 'loops' => [],
                    'note' => 'This content cannot be edited part by part (empty, or its HTML cannot be mapped safely). Use update_content.'];
            }
            $body = (string)$ann['body'];
            $kind = 'content';
        }
        return ['id' => (int)$post['id'], 'title' => $post['title'], 'kind' => $kind, 'updated_at' => Mcp::iso($post['updated_at']),
            'url' => Posts::permalink($post, true), 'elements' => self::elements($body), 'loops' => self::loopsOut(PageEditor::loops($body)),
            'note' => 'Change parts with edit_page using these ids. Repeating groups (loops) can be bound to live content with loop_dynamic (sources: get_content_model).'];
    }

    public static function toolEditPage(array $a): array
    {
        $post = Mcp::findPost((int)$a['id'], true);
        if (!PageEditor::canEdit($post)) {
            throw new ToolError('The user of this key may not edit pages on the live site (capability frontend_edit).');
        }
        $before = Revisions::count((int)$post['id']);
        if ($err = PageEditor::apply($post, $a['ops'])) {
            throw new ToolError($err);
        }
        $now = Posts::find((int)$post['id']);
        log_activity('mcp_page_edited', "{$now['type']}:{$now['title']} (" . count($a['ops']) . ' change(s))');
        return ['saved' => true, 'id' => (int)$now['id'], 'url' => Posts::permalink($now, true), 'status' => $now['status'],
            'revision_saved' => Revisions::count((int)$now['id']) > $before, 'updated_at' => Mcp::iso($now['updated_at'])];
    }

    public static function toolImportPagesFromUrl(array $a): array
    {
        Mcp::requireCap('edit_posts', 'create content');
        if (!method_exists(SiteImport::class, 'fromUrls')) {
            throw new ToolError('Importing by address is not available in this version of Slate.');
        }
        $type = (string)($a['type'] ?? 'page');
        Mcp::type($type);
        @set_time_limit(400);
        [$ok, $folder] = SiteImport::fromUrls(array_slice($a['urls'], 0, SiteImport::URL_MAX_PAGES), !empty($a['follow_links']));
        if (!$ok) {
            throw new ToolError((string)$folder);
        }
        $res = SiteImport::import((string)$folder, ['status' => !empty($a['publish']) && Mcp::canPublish() ? 'publish' : 'draft', 'type' => $type,
            'design' => (string)($a['design'] ?? 'keep')]);
        if (!$res['ok']) {
            throw new ToolError((string)$res['error']);
        }
        log_activity('mcp_pages_imported', count($res['pages']) . ' page(s)');
        return ['imported' => count($res['pages']), 'pages' => array_map(static function ($p) {
            $post = Posts::find((int)$p['id']);
            return ['id' => (int)$p['id'], 'title' => $p['title'], 'status' => $post['status'] ?? 'draft', 'url' => $post ? Posts::permalink($post, true) : '', 'source' => $p['source'],
                'editable_parts' => (int)($p['report']['editable'] ?? 0), 'repeating_groups' => (int)($p['report']['loops'] ?? 0),
                'issues' => array_map(static fn($i) => ['level' => $i[0], 'message' => $i[1]], (array)($p['report']['issues'] ?? []))];
        }, $res['pages'])];
    }

    // ------------------------------------------------------------------ templates

    private static function templateOrFail(string $id): array
    {
        $t = Templates::find($id);
        if (!$t) {
            throw new ToolError("No template with id “{$id}” (list_templates).");
        }
        return $t;
    }

    /** Is kind/target something Slate can render? Returns an error message or null. */
    private static function badTarget(string $kind, string $target): ?string
    {
        $base = explode('__', $target, 2)[0];
        return match ($kind) {
            'single' => ContentModel::type($base) ? null : "Unknown post type “{$base}”.",
            'archive' => ContentModel::type($target) ? null : "Unknown post type “{$target}”.",
            'term' => ContentModel::taxonomy($target) ? null : "Unknown taxonomy “{$target}”.",
            'special' => isset(Templates::SPECIAL[$target]) ? null : 'Special templates are "404" or "search".',
            default => 'Unknown kind.',
        };
    }

    private static function templateOut(array $t): array
    {
        $example = null;
        if (!empty($t['enabled'])) {
            $base = explode('__', (string)$t['target'], 2);
            if ($t['kind'] === 'single') {
                $p = isset($base[1]) ? Posts::findBySlug($base[0], $base[1]) : (Posts::query(['type' => $base[0], 'status' => 'publish', 'limit' => 1, 'count' => false])['items'][0] ?? null);
                $example = $p ? Posts::permalink($p, true) : null;
            } elseif ($t['kind'] === 'term' && ($term = Terms::all((string)$t['target'])[0] ?? null)) {
                $example = Terms::link($term, true);
            }
        }
        return ['id' => $t['id'], 'name' => $t['name'], 'kind' => $t['kind'], 'target' => $t['target'], 'applies_to' => Templates::label($t['kind'], $t['target']),
            'active' => !empty($t['enabled']), 'inside_theme' => !empty($t['wrap']), 'versions' => count(Templates::versions((string)$t['id'])),
            'updated_at' => Mcp::iso($t['updated_at'] ?? null), 'example_url' => $example];
    }

    public static function toolSaveTemplate(array $a): array
    {
        $current = isset($a['id']) ? self::templateOrFail((string)$a['id']) : null;
        $kind = (string)($a['kind'] ?? ($current['kind'] ?? ''));
        $target = (string)($a['target'] ?? ($current['target'] ?? ''));
        if ($kind === '' || $target === '') {
            throw new ToolError('A new template needs kind and target (e.g. kind "single", target "service").');
        }
        if ($err = self::badTarget($kind, $target)) {
            throw new ToolError($err);
        }
        $warnings = [];
        $html = array_key_exists('html', $a) ? (string)$a['html'] : ($current ? (string)$current['html'] : Templates::starter($kind, $target));
        if (!Auth::can('unfiltered_html')) {
            $clean = HtmlPages::stripScripts($html);
            if ($clean !== $html) {
                $warnings[] = 'Scripts and event handlers were removed (this user may not use unfiltered HTML).';
            }
            $html = $clean;
        }
        $id = Templates::save([
            'id' => $current['id'] ?? '', 'name' => $a['name'] ?? ($current['name'] ?? ''), 'kind' => $kind, 'target' => $target, 'html' => $html,
            'css' => array_key_exists('css', $a) ? (string)$a['css'] : (string)($current['css'] ?? ''),
            'wrap' => array_key_exists('inside_theme', $a) ? (bool)$a['inside_theme'] : ($current ? !empty($current['wrap']) : true),
            'enabled' => array_key_exists('enabled', $a) ? (bool)$a['enabled'] : ($current ? !empty($current['enabled']) : false),
        ]);
        $t = (array)Templates::find($id);
        log_activity('mcp_template_saved', $t['name'] . (!empty($t['enabled']) ? ' (active)' : ''));
        return ['saved' => true, 'created' => !$current] + self::templateOut($t) + ($warnings ? ['warnings' => $warnings] : [])
            + (!$current && !array_key_exists('html', $a) ? ['note' => 'Started from Slate\'s starter template; read it with get_template and adapt it.'] : []);
    }

    public static function toolUndoTemplate(array $a): array
    {
        $t = self::templateOrFail((string)$a['id']);
        if (!Templates::undo((string)$t['id'])) {
            throw new ToolError('There is no earlier version of this template.');
        }
        log_activity('mcp_template_undo', (string)$t['name']);
        return ['restored' => true] + self::templateOut((array)Templates::find((string)$t['id']));
    }

    public static function toolDeleteTemplate(array $a): array
    {
        $t = self::templateOrFail((string)$a['id']);
        Templates::delete((string)$t['id']);
        log_activity('mcp_template_deleted', (string)$t['name']);
        return ['deleted' => true, 'id' => $t['id'], 'name' => $t['name']];
    }

    public static function toolGetTemplateStructure(array $a): array
    {
        $t = self::templateOrFail((string)$a['id']);
        $ann = PageEditor::annotateTemplate((string)$t['html']);
        if (!$ann) {
            return ['id' => $t['id'], 'elements' => [], 'loops' => [], 'note' => 'This template\'s HTML cannot be edited part by part. Use save_template.'];
        }
        return ['id' => $t['id'], 'name' => $t['name'], 'elements' => self::elements((string)$ann['html']), 'loops' => self::loopsOut((array)$ann['loops']),
            'note' => 'Parts showing template tags or inside list blocks are not listed: change those with save_template.'];
    }

    public static function toolEditTemplateParts(array $a): array
    {
        $t = self::templateOrFail((string)$a['id']);
        if ($err = PageEditor::applyTemplate($t, $a['ops'])) {
            throw new ToolError($err);
        }
        log_activity('mcp_template_edited', (string)$t['name']);
        return ['saved' => true] + self::templateOut((array)Templates::find((string)$t['id']));
    }

    // ------------------------------------------------------------------ design system

    public static function toolGetDesignSystem(array $a): array
    {
        $ds = DesignSystem::get();
        return [
            'colors' => array_map(static fn($c) => $c + ['css_variable' => "var(--color-{$c['id']})"], $ds['colors']),
            'fonts' => $ds['fonts'] + ['heading_variable' => 'var(--font-heading)', 'body_variable' => 'var(--font-body)'],
            'type' => $ds['type'], 'layout' => $ds['layout'], 'classes' => $ds['classes'],
            'vars' => array_map(static fn($v) => $v + ['css_variable' => "var(--{$v['name']})"], (array)($ds['vars'] ?? [])),
            'apply_elements' => !empty($ds['apply_elements']), 'responsive' => !empty($ds['responsive']),
            'suggested_fonts' => array_merge(DesignSystem::FONTS, array_keys(DesignSystem::SYSTEM_FONTS)),
            'type_keys' => DesignSystem::TYPE_KEYS,
        ];
    }

    public static function toolUpdateDesignSystem(array $a): array
    {
        $ds = DesignSystem::get();
        $warnings = [];
        $byKey = static function (array $list, string $key): array {
            $out = [];
            foreach ($list as $row) {
                $out[(string)$row[$key]] = $row;
            }
            return $out;
        };
        $norm = static fn(string $id): string => preg_replace('/[^a-z0-9-]/', '', strtolower(str_replace([' ', '_'], '-', $id)));
        $colors = $byKey($ds['colors'], 'id');
        foreach ((array)($a['remove_colors'] ?? []) as $id) {
            unset($colors[$norm((string)$id)]);
        }
        foreach ((array)($a['colors'] ?? []) as $c) {
            $id = $norm((string)$c['id']);
            if ($id === '' || !DesignSystem::validColor(trim((string)$c['value']))) {
                $warnings[] = "Colour “{$c['id']}” skipped: use an id and a hex, rgb(a), hsl(a) value.";
                continue;
            }
            $colors[$id] = ['id' => $id, 'name' => (string)($c['name'] ?? ($colors[$id]['name'] ?? ucfirst($id))), 'value' => trim((string)$c['value'])];
        }
        $classes = $byKey($ds['classes'], 'name');
        foreach ((array)($a['remove_classes'] ?? []) as $n) {
            unset($classes[ltrim((string)$n, '.')]);
        }
        foreach ((array)($a['classes'] ?? []) as $c) {
            $name = ltrim((string)$c['name'], '.');
            if (preg_match('/[{}<>]/', (string)($c['css'] ?? '') . (string)($c['hover'] ?? ''))) {
                $warnings[] = "Class “{$name}”: write declarations only (no braces, selectors or tags); they were removed.";
            }
            $classes[$name] = ['name' => $name, 'css' => (string)($c['css'] ?? ($classes[$name]['css'] ?? '')),
                'hover' => (string)($c['hover'] ?? ($classes[$name]['hover'] ?? ''))];
        }
        $vars = $byKey((array)($ds['vars'] ?? []), 'name');
        foreach ((array)($a['remove_vars'] ?? []) as $n) {
            unset($vars[ltrim((string)$n, '-')]);
        }
        foreach ((array)($a['vars'] ?? []) as $v) {
            $name = trim(ltrim((string)$v['name'], '-'));
            if (str_starts_with($name, 'color-')) {
                $warnings[] = "Variable “{$name}” skipped: colour variables come from colors.";
                continue;
            }
            $vars[$name] = ['name' => $name, 'value' => (string)$v['value']];
        }
        $type = $ds['type'];
        foreach ((array)($a['type'] ?? []) as $k => $entry) {
            $type[$k] = (array)$entry + (array)($type[$k] ?? []);
        }
        $fonts = $ds['fonts'];
        foreach (['heading', 'body'] as $f) {
            if (isset($a['fonts'][$f])) {
                if (DesignSystem::validFont((string)$a['fonts'][$f])) {
                    $fonts[$f] = (string)$a['fonts'][$f];
                } else {
                    $warnings[] = "Font “{$a['fonts'][$f]}” is not a valid font name; kept {$ds['fonts'][$f]}.";
                }
            }
        }
        if (isset($a['fonts']['google'])) {
            $fonts['google'] = (bool)$a['fonts']['google'];
        }
        DesignSystem::save([
            'colors' => array_values($colors), 'classes' => array_values($classes), 'vars' => array_values($vars), 'type' => $type, 'fonts' => $fonts,
            'layout' => (array)($a['layout'] ?? []) + $ds['layout'],
            'apply_elements' => $a['apply_elements'] ?? !empty($ds['apply_elements']), 'responsive' => $a['responsive'] ?? !empty($ds['responsive']),
            'admin_match' => !empty($ds['admin_match']),
        ]);
        log_activity('mcp_design_saved', implode(', ', array_keys(array_diff_key($a, ['confirm' => 1]))));
        return ['saved' => true] + self::toolGetDesignSystem([]) + ($warnings ? ['warnings' => $warnings] : []);
    }

    // ------------------------------------------------------------------ menus

    private static function menuItemsIn(array $items, array &$warnings, int $depth = 0): array
    {
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $type = in_array($it['type'] ?? 'custom', ['custom', 'post', 'term'], true) ? (string)($it['type'] ?? 'custom') : 'custom';
            $oid = (int)($it['object_id'] ?? 0);
            if ($type === 'post' && (!($p = Posts::find($oid)) || $p['status'] === 'trash')) {
                $warnings[] = "Menu item “" . ($it['label'] ?? '') . "”: no content item $oid; skipped.";
                continue;
            }
            if ($type === 'term' && !Terms::find($oid)) {
                $warnings[] = "Menu item “" . ($it['label'] ?? '') . "”: no term $oid; skipped.";
                continue;
            }
            if ($type === 'custom' && ContentModel::cleanUrl((string)($it['url'] ?? '')) === '') {
                $warnings[] = "Menu item “" . ($it['label'] ?? '') . "”: give a URL (/path/, https://…, mailto:, tel:); skipped.";
                continue;
            }
            $out[] = ['label' => (string)($it['label'] ?? ''), 'type' => $type, 'url' => (string)($it['url'] ?? ''), 'object_id' => $oid,
                'target' => !empty($it['new_tab']), 'class' => (string)($it['class'] ?? ''),
                'children' => $depth < 3 ? self::menuItemsIn((array)($it['children'] ?? []), $warnings, $depth + 1) : []];
        }
        return $out;
    }

    private static function menuOut(array $m): array
    {
        $map = static function (array $items) use (&$map): array {
            return array_map(static fn($it) => array_filter(['label' => $it['label'], 'type' => $it['type'], 'url' => $it['url'],
                'object_id' => $it['type'] !== 'custom' ? (int)$it['object_id'] : null, 'new_tab' => !empty($it['target']), 'class' => ($it['class'] ?? '') ?: null,
                'children' => $it['children'] ? $map($it['children']) : null], static fn($v) => $v !== null), $items);
        };
        return ['id' => $m['id'], 'name' => $m['name'], 'location' => ($m['location'] ?? '') ?: null, 'items' => $map(Menus::resolve((array)$m['items']))];
    }

    public static function toolListMenus(array $a): array
    {
        $locations = (array)(Theme::meta()['menus'] ?? []);
        return ['menus' => array_values(array_map([self::class, 'menuOut'], Menus::all())),
            'locations' => array_map(static fn($k, $l) => ['location' => $k, 'label' => $l, 'menu_id' => Menus::byLocation((string)$k)['id'] ?? null], array_keys($locations), $locations)];
    }

    public static function toolSaveMenu(array $a): array
    {
        $all = Menus::all();
        $id = (string)($a['id'] ?? '');
        $current = $id !== '' ? ($all[$id] ?? null) : null;
        if ($id !== '' && !$current) {
            throw new ToolError("No menu with id “{$id}” (list_menus). Leave id out to create one.");
        }
        if (!$current && trim((string)($a['name'] ?? '')) === '') {
            throw new ToolError('Give the new menu a name.');
        }
        $locations = (array)(Theme::meta()['menus'] ?? []);
        $location = array_key_exists('location', $a) ? (string)($a['location'] ?? '') : (string)($current['location'] ?? '');
        if ($location !== '' && !isset($locations[$location])) {
            throw new ToolError("Unknown menu location “{$location}”. The theme has: " . (implode(', ', array_keys($locations)) ?: 'none') . '.');
        }
        $warnings = [];
        $id = $current ? $id : 'menu-' . bin2hex(random_bytes(3));
        $menu = ['id' => $id, 'name' => (string)($a['name'] ?? $current['name']), 'location' => $location,
            'items' => isset($a['items']) ? self::menuItemsIn($a['items'], $warnings) : (array)($current['items'] ?? [])];
        $moved = [];
        if ($location !== '') {
            foreach ($all as $k => $m) {
                if ($k !== $id && ($m['location'] ?? '') === $location) {
                    $all[$k]['location'] = '';
                    $moved[] = $m['name'];
                }
            }
        }
        $all[$id] = $menu;
        Menus::save(array_values($all));
        if ($moved) {
            $warnings[] = "The location “{$location}” was taken from: " . implode(', ', $moved) . '.';
        }
        log_activity('mcp_menu_saved', $menu['name'] . ($location !== '' ? " ($location)" : ''));
        return ['saved' => true, 'created' => !$current, 'menu' => self::menuOut(Menus::all()[$id])] + ($warnings ? ['warnings' => $warnings] : []);
    }

    public static function toolDeleteMenu(array $a): array
    {
        $all = Menus::all();
        $id = (string)$a['id'];
        if (!isset($all[$id])) {
            throw new ToolError("No menu with id “{$id}”.");
        }
        $name = $all[$id]['name'];
        unset($all[$id]);
        Menus::save(array_values($all));
        log_activity('mcp_menu_deleted', $name);
        return ['deleted' => true, 'id' => $id, 'name' => $name];
    }

    // ------------------------------------------------------------------ SEO

    public static function toolGetSeoSettings(array $a): array
    {
        $types = [];
        foreach (ContentModel::types() as $slug => $d) {
            if (($d['public'] ?? true) !== false) {
                $types[$slug] = Seo::typeDefaults($slug) + ['og_image' => StructuredData::typeImage($slug) ?: null,
                    'schema_missing' => StructuredData::missing(Seo::typeDefaults($slug)['schema'], StructuredData::map($slug, Seo::typeDefaults($slug)['schema']))];
            }
        }
        $taxes = [];
        foreach (ContentModel::taxonomies() as $slug => $d) {
            if (($d['public'] ?? true) !== false) {
                $taxes[$slug] = Seo::taxDefaults($slug) + ['archive_schema' => StructuredData::taxMode($slug)];
            }
        }
        return ['settings' => self::settingsRead('seo'), 'types' => $types, 'taxonomies' => $taxes, 'schema_types' => Seo::SCHEMA_TYPES,
            'title_variables' => ['%title%', '%site%', '%tagline%', '%sep%', '%year%', '%page%', '%category%'],
            'redirects' => count((array)(Options::get('redirects') ?: [])), 'sitemap_url' => url('sitemap.xml'),
            'instant_indexing' => ['indexnow' => Indexing::indexNowEnabled(), 'google' => Indexing::googleEnabled(),
                'note' => 'Keys and the Google service account are managed in the dashboard only.']];
    }

    public static function toolSetSeoDefaults(array $a): array
    {
        $type = (string)($a['type'] ?? '');
        $tax = (string)($a['taxonomy'] ?? '');
        if (($type === '') === ($tax === '')) {
            throw new ToolError('Send either type or taxonomy.');
        }
        $types = (array)(Options::get('seo_types') ?: []);
        $taxes = (array)(Options::get('seo_taxonomies') ?: []);
        $pick = array_intersect_key($a, array_flip(['title_template', 'robots', 'sitemap', 'schema']));
        if ($type !== '') {
            Mcp::type($type);
            $types[$type] = $pick + Seo::typeDefaults($type);
        } else {
            self::taxonomyOrFail($tax);
            unset($pick['schema']);
            $taxes[$tax] = $pick + Seo::taxDefaults($tax);
        }
        Seo::saveTypeSettings($types, $taxes);
        if ($type !== '' && array_key_exists('og_image', $a)) {
            if ($a['og_image'] && !Media::find((int)$a['og_image'])) {
                throw new ToolError("No media item with id {$a['og_image']}.");
            }
            $images = (array)(Options::get('seo_og_type_images') ?: []);
            if ($a['og_image']) {
                $images[$type] = (int)$a['og_image'];
            } else {
                unset($images[$type]);
            }
            Options::set('seo_og_type_images', $images);
        }
        if ($tax !== '' && isset($a['archive_schema'])) {
            $modes = (array)(Options::get('seo_schema_tax') ?: []);
            $modes[$tax] = $a['archive_schema'];
            StructuredData::saveAdmin(['tax' => $modes]);
        }
        PageCache::clear();
        log_activity('mcp_seo_defaults_saved', $type !== '' ? "type:$type" : "taxonomy:$tax");
        return ['saved' => true] + ($type !== '' ? ['type' => $type, 'defaults' => Seo::typeDefaults($type) + ['og_image' => StructuredData::typeImage($type) ?: null]]
            : ['taxonomy' => $tax, 'defaults' => Seo::taxDefaults($tax) + ['archive_schema' => StructuredData::taxMode($tax)]]);
    }

    public static function toolGetSchemaMapping(array $a): array
    {
        $type = (string)$a['type'];
        Mcp::type($type);
        $schema = (string)($a['schema'] ?? Seo::typeDefaults($type)['schema']);
        $props = [];
        foreach (StructuredData::props($schema) as $path => $p) {
            $props[$path] = ['label' => $p['label'], 'kind' => $p['kind'], 'required' => $p['req']];
        }
        $sources = [];
        foreach (StructuredData::sources($type) as $group) {
            $sources += $group;
        }
        $map = StructuredData::mappable($schema) ? StructuredData::map($type, $schema) : [];
        return ['type' => $type, 'schema' => $schema, 'active_schema' => Seo::typeDefaults($type)['schema'], 'mappable' => StructuredData::mappable($schema),
            'properties' => $props ?: new \stdClass(), 'sources' => $sources, 'mapping' => $map ?: new \stdClass(),
            'saved' => StructuredData::savedMap($type, $schema) !== null, 'missing_required' => StructuredData::missing($schema, $map),
            'note' => 'Sources: @title @excerpt @description @content @image @url @published @modified @author @site, field:<name>, tax:<taxonomy>, or "=fixed text". '
                . 'Until a mapping is saved, the suggestions shown here are used.'];
    }

    public static function toolSetSchemaMapping(array $a): array
    {
        $type = (string)$a['type'];
        $def = Mcp::type($type);
        if (empty($def['public'])) {
            throw new ToolError("The type “{$type}” has no public pages.");
        }
        $schema = (string)$a['schema'];
        $warnings = [];
        $in = ['type' => $type, 'schema' => $schema, 'og_image' => StructuredData::typeImage($type), 'map' => [], 'fixed' => []];
        if (StructuredData::mappable($schema)) {
            $props = StructuredData::props($schema);
            $valid = [];
            foreach (StructuredData::sources($type) as $group) {
                $valid += $group;
            }
            $map = StructuredData::map($type, $schema);
            foreach ((array)($a['map'] ?? []) as $path => $src) {
                if (!isset($props[$path])) {
                    $warnings[] = "Unknown property “{$path}” for $schema (get_schema_mapping lists them).";
                    continue;
                }
                if ($src !== '' && !str_starts_with($src, '=') && !isset($valid[$src])) {
                    $warnings[] = "Unknown source “{$src}” for {$path}.";
                    continue;
                }
                $map[$path] = $src;
            }
            foreach ($map as $path => $src) {
                if (str_starts_with((string)$src, '=')) {
                    $in['map'][$path] = '=';
                    $in['fixed'][$path] = substr((string)$src, 1);
                } else {
                    $in['map'][$path] = (string)$src;
                }
            }
        } elseif (!empty($a['map'])) {
            $warnings[] = "$schema has no properties to map; only the schema type was set.";
        }
        StructuredData::saveAdmin($in);
        log_activity('mcp_schema_saved', "$type:$schema");
        $out = self::toolGetSchemaMapping(['type' => $type, 'schema' => $schema]);
        return ['saved' => true] + $out + ($warnings ? ['warnings' => $warnings] : []);
    }

    public static function toolListRedirects(array $a): array
    {
        $rows = array_values((array)(Options::get('redirects') ?: []));
        return ['redirects' => $rows, 'total' => count($rows)];
    }

    public static function toolAddRedirects(array $a): array
    {
        $new = Seo::cleanRedirects($a['redirects']);
        $skipped = count($a['redirects']) - count($new);
        $from = array_map(static fn($r) => strtolower('/' . trim($r['from'], '/')), $new);
        $rows = array_values(array_filter((array)(Options::get('redirects') ?: []), static fn($r) => !in_array(strtolower('/' . trim((string)$r['from'], '/')), $from, true)));
        foreach ($new as $r) {
            if ($r['regex'] && @preg_match('#^' . str_replace('#', '\#', $r['from']) . '$#', '') === false) {
                throw new ToolError("“{$r['from']}” is not a valid regular expression.");
            }
        }
        Seo::saveRedirects(array_merge($rows, $new));
        log_activity('mcp_redirects_added', (string)count($new));
        return ['added' => count($new), 'total' => count((array)Options::get('redirects'))] + ($skipped > 0 ? ['warnings' => ["$skipped rule(s) skipped: a target is needed unless the code is 410."]] : []);
    }

    public static function toolRemoveRedirects(array $a): array
    {
        $from = array_map(static fn($f) => strtolower('/' . trim((string)$f, '/')), $a['from']);
        $rows = (array)(Options::get('redirects') ?: []);
        $keep = array_values(array_filter($rows, static fn($r) => !in_array(strtolower('/' . trim((string)$r['from'], '/')), $from, true)));
        Seo::saveRedirects($keep);
        log_activity('mcp_redirects_removed', (string)(count($rows) - count($keep)));
        return ['removed' => count($rows) - count($keep), 'total' => count($keep)];
    }

    public static function toolRequestIndexing(array $a): array
    {
        if (empty($a['ids']) && empty($a['urls'])) {
            throw new ToolError('Send ids of published items, or urls.');
        }
        $results = [];
        foreach ((array)($a['ids'] ?? []) as $id) {
            $post = Mcp::findPost((int)$id, true);
            if ($post['status'] !== 'publish') {
                $results[] = ['id' => (int)$id, 'ok' => false, 'message' => 'Only published items can be submitted.'];
                continue;
            }
            [$ok, $msg] = Indexing::request([Posts::permalink($post, true)], $post);
            $results[] = ['id' => (int)$id, 'ok' => $ok, 'message' => $msg];
        }
        if (!empty($a['urls'])) {
            Mcp::requireCap('manage_settings', 'submit addresses for indexing');
            $own = array_values(array_filter($a['urls'], [Indexing::class, 'ownUrl']));
            [$ok, $msg] = Indexing::request($own);
            $results[] = ['urls' => $own, 'ok' => $ok, 'message' => $msg . (count($own) < count($a['urls']) ? ' ' . (count($a['urls']) - count($own)) . ' address(es) skipped: not on this website.' : '')];
        }
        log_activity('mcp_index_requested', count($results) . ' request(s)');
        return ['results' => $results];
    }

    // ------------------------------------------------------------------ media

    public static function toolUpdateMedia(array $a): array
    {
        $m = Media::find((int)$a['id']);
        if (!$m) {
            throw new ToolError("No media item with id {$a['id']}.");
        }
        if ((int)$m['uploaded_by'] !== Auth::id()) {
            Mcp::requireCap('manage_media', 'change media uploaded by others');
        }
        $data = array_intersect_key($a, array_flip(['alt', 'title', 'caption', 'filename']));
        if (!$data) {
            throw new ToolError('Send alt, title, caption or filename.');
        }
        if ($err = Media::update((int)$m['id'], $data)) {
            throw new ToolError($err);
        }
        log_activity('mcp_media_updated', basename((string)$m['path']));
        return ['updated' => true] + Mcp::media((array)Media::find((int)$m['id']));
    }

    // ------------------------------------------------------------------ settings

    /** Is this settings field exposed? */
    private static function fieldAllowed(array $f, string $tab, string $section): bool
    {
        $key = (string)($f['key'] ?? '');
        if ($key === '' || in_array($key, self::DENY_KEYS, true) || preg_match(self::SECRET_KEY, $key)) {
            return false;
        }
        if (!in_array($section, self::SETTINGS[$tab] ?? [], true)) {
            return false;
        }
        $type = (string)($f['type'] ?? 'text');
        return !in_array($type, ['secret', 'info', 'heading'], true) && ($type !== 'code' || $key === 'seo_robots_txt');
    }

    /** Exposed fields: key => [tab, section, field]. */
    public static function settingsAllowed(): array
    {
        $out = [];
        foreach (Settings::tabs() as $tab => $t) {
            if (!isset(self::SETTINGS[$tab])) {
                continue;
            }
            foreach ($t['sections'] as $sk => $sec) {
                foreach ($sec['fields'] ?? [] as $f) {
                    if (self::fieldAllowed($f, $tab, $sk)) {
                        $out[$f['key']] = [$tab, $sk, $f];
                    }
                }
            }
        }
        return $out;
    }

    private static function canTab(string $tab): bool
    {
        return Auth::can(Settings::cap($tab)) || Auth::can('manage_settings');
    }

    private static function settingsRead(?string $only = null): array
    {
        $groups = [];
        foreach (self::settingsAllowed() as $key => [$tab, $section, $f]) {
            if (($only !== null && $tab !== $only) || !self::canTab($tab)) {
                continue;
            }
            $row = ['key' => $key, 'label' => (string)($f['label'] ?? $key), 'type' => (string)$f['type'], 'value' => Options::get($key, $f['default'] ?? null)];
            if (!empty($f['help'])) {
                $row['help'] = (string)$f['help'];
            }
            foreach (['min', 'max', 'pattern'] as $k) {
                if (isset($f[$k])) {
                    $row[$k] = $f[$k];
                }
            }
            if (isset($f['options'])) {
                $row['options'] = match (true) {
                    is_array($f['options']) => $f['options'],
                    $f['options'] === 'timezones' => 'An IANA time zone such as "Europe/Berlin" or "UTC".',
                    $f['options'] === 'pages' => 'The id of a published page (list_content type page).',
                    default => Settings::options($f['options']),
                };
            }
            $label = Settings::tabs()[$tab]['label'] . ' › ' . (Settings::tabs()[$tab]['sections'][$section]['label'] ?? $section);
            $groups[$tab][$label][] = $row;
        }
        return $groups;
    }

    public static function toolGetSettings(array $a): array
    {
        return ['settings' => self::settingsRead(isset($a['group']) ? (string)$a['group'] : null),
            'not_available' => 'Secrets and keys, security, email/SMTP, AI provider, REST API, webhooks, MCP, custom code, backups, scheduled tasks and updates are not exposed through MCP.'];
    }

    public static function toolUpdateSettings(array $a): array
    {
        $allowed = self::settingsAllowed();
        $values = $a['values'];
        if (!$values) {
            throw new ToolError('Send at least one setting in values.');
        }
        $sections = [];
        foreach ($values as $key => $v) {
            if (!isset($allowed[$key])) {
                throw new ToolError("“{$key}” cannot be changed through MCP (unknown, or a protected setting). Use keys from get_settings.");
            }
            [$tab, $section, $f] = $allowed[$key];
            if (!self::canTab($tab)) {
                throw new ToolError("The user of this key may not change “{$key}” (needs " . Settings::cap($tab) . ').');
            }
            if ($f['type'] === 'toggle' && !is_bool($v) && !in_array($v, [0, 1, '0', '1'], true)) {
                throw new ToolError("“{$key}” is a toggle: send true or false.");
            }
            if ($f['type'] !== 'checkboxes' && is_array($v)) {
                throw new ToolError("“{$key}” takes a single value.");
            }
            $sections["$tab/$section"][$key] = $v;
        }
        $errors = [];
        foreach ($sections as $ts => $changes) {
            [$tab, $section] = explode('/', $ts, 2);
            // The section is saved as a whole (like the dashboard form): start from the current values.
            $input = [];
            foreach (Settings::section($tab, $section)['fields'] ?? [] as $f) {
                if (!isset($f['key']) || $f['type'] === 'secret') {
                    continue;
                }
                $cur = array_key_exists($f['key'], $changes) ? $changes[$f['key']] : Options::get($f['key'], $f['default'] ?? null);
                $input[$f['key']] = match (true) {
                    $f['type'] === 'toggle' => (bool)$cur,
                    is_array($cur) => array_map('strval', $cur),
                    $cur === null => '',
                    default => (string)$cur,
                };
            }
            $errors = array_merge($errors, Settings::save($tab, $section, $input));
        }
        log_activity('mcp_settings_saved', implode(', ', array_keys($values)));
        $now = [];
        foreach (array_keys($values) as $key) {
            $now[$key] = Options::get($key);
        }
        return ['saved' => !$errors, 'values' => $now] + ($errors ? ['errors' => $errors] : []);
    }
}
