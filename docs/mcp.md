# AI agents (MCP)

Slate has a built-in [Model Context Protocol](https://modelcontextprotocol.io) server. AI agents such as Claude Code,
Claude Desktop, ChatGPT, Cursor or VS Code can connect to it and build or manage the site: read the content model and
design system, set up the design system, content model, templates, pages, menus, SEO and forms, edit pages part by part,
import images and pages, and undo changes.

- Endpoint: `https://your-site.com/api/mcp` (Streamable HTTP, JSON-RPC 2.0 over POST, stateless)
- Off by default. It does not depend on the REST API switch.
- Protocol versions: 2025-11-25, 2025-06-18, 2025-03-26, 2024-11-05.

## Setup

1. **Settings → Integrations → AI agents (MCP)** → switch it on. The screen shows the endpoint and ready-made snippets.
2. **Profile → API keys** → create a key. *Read only* lets an agent look around; *Read & write* lets it create drafts and
   designs. The key acts as its user, with that user's role. For a narrower agent, create a user with the
   Author or Editor role and make the key there.
3. Add the server to your client, replacing `slt_…` with the key:

**Claude Code**

```bash
claude mcp add --transport http slate https://your-site.com/api/mcp --header "Authorization: Bearer slt_…"
```

**Claude Desktop** (`claude_desktop_config.json`, needs Node.js; `mcp-remote` bridges to the HTTP server)

```json
{
  "mcpServers": {
    "slate": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://your-site.com/api/mcp", "--header", "Authorization:${SLATE_AUTH}"],
      "env": { "SLATE_AUTH": "Bearer slt_…" }
    }
  }
}
```

**Cursor, VS Code, Windsurf and other clients with remote MCP support**

```json
{
  "mcpServers": {
    "slate": { "type": "http", "url": "https://your-site.com/api/mcp", "headers": { "Authorization": "Bearer slt_…" } }
  }
}
```

Any client that can send an `Authorization: Bearer` header works. Clients that only offer OAuth sign-in cannot connect
directly yet; use a bridge such as `mcp-remote`.

Test it from a terminal:

```bash
npx @modelcontextprotocol/inspector --cli https://your-site.com/api/mcp --transport http \
  --header "Authorization: Bearer slt_…" --method tools/list
```

## Tools

Every tool lists its JSON schema with descriptions in `tools/list`. Call `get_site` first, and `describe_capabilities` to
see which tools the key may use. Examples show the `arguments` of a `tools/call`.

The server also offers the prompt **`build_site`** (`prompts/get`, optional argument `brief`) and the same text as the
resource **`slate://guide/build-site`** (`resources/read`): a short guide that builds a site in this order: design
system, content model, templates, pages, images, menus, SEO, forms.

"Needs" names the dashboard capability. Tools marked *write* need a *Read & write* key; tools marked *confirm* delete
something and only run with `"confirm": true` (and are logged).

### Site

| Tool | What it does | Needs | Example |
|---|---|---|---|
| `get_site` | Name, URL, theme, content types with fields and taxonomies (with terms), design system, templates, field groups, menus and locations, active modules, what the key may do (`you.tools`). | any key | `{}` |
| `describe_capabilities` | Every tool by group with available yes/no and why, and what MCP does not expose. | any key | `{}` |
| `get_current_user` | The key's user: name, role, capabilities, key name and scope. No other users. | any key | `{}` |
| `list_modules` | Installed modules: name, version, active, dashboard pages. Read-only. | `manage_modules` or `manage_settings` | `{}` |

### Content

| Tool | What it does | Needs | Example |
|---|---|---|---|
| `list_content` | Items of one or all types: status, URL, designed page yes/no. Filters: status, search, order, paging. | any key | `{"type": "page", "status": "publish"}` |
| `get_content` | One item in full: content, excerpt, fields, terms, SEO, URL, designed page (head + body), revision count. | any key | `{"id": 12}` |
| `search` | Content, terms, media and templates at once. | any key | `{"query": "garden"}` |
| `create_content` | New item of any public type with content, excerpt, fields, terms, SEO, parent, featured image. Draft unless `publish: true`. | write, `edit_posts` | `{"type": "service", "title": "Garden design", "fields": {"price": 490}, "terms": {"area": ["North"]}, "seo": {"title": "Garden design", "schema_type": "Service"}}` |
| `update_content` | Change only what is sent. Fields merge, terms replace per taxonomy sent, SEO keys merge. `status` can publish/unpublish. | write, can edit the item | `{"id": 12, "seo": {"description": "Calm, green gardens."}}` |
| `get_revisions` | Earlier versions of an item; with `revision_id` one version in full plus a diff to the current one. | can edit the item | `{"id": 12}` |
| `restore_revision` | Put an earlier version back; the replaced version becomes a revision too. | write, can edit the item | `{"id": 12, "revision_id": 40}` |

### Content model

| Tool | What it does | Needs | Example |
|---|---|---|---|
| `get_content_model` | All post types, taxonomies and field groups in full, plus field types, editor features and icons. | `manage_content_model` | `{}` |
| `save_post_type` | Create or change a post type (send only what changes; `original_slug` renames). | write, `manage_content_model` | `{"slug": "service", "singular": "Service", "plural": "Services", "rewrite": "services"}` |
| `delete_post_type` | Delete a custom type; its items too with `delete_content`. | write, confirm, `manage_content_model` | `{"slug": "service", "confirm": true}` |
| `save_taxonomy` | Create or change a taxonomy for one or more types. | write, `manage_content_model` | `{"slug": "area", "post_types": ["service"], "hierarchical": true}` |
| `delete_taxonomy` | Delete a custom taxonomy and its terms. | write, confirm, `manage_content_model` | `{"slug": "area", "confirm": true}` |
| `save_field_group` | Create or change a group of custom fields (all field types, repeaters with `sub_fields`, relations, taxonomy fields) shown on types and/or taxonomies. | write, `manage_content_model` | `{"title": "Details", "post_types": ["service"], "fields": [{"name": "price", "label": "Price", "type": "number"}]}` |
| `delete_field_group` | Delete a field group (stored values stay on the content). | write, confirm, `manage_content_model` | `{"id": "g1a2b3c4d", "confirm": true}` |

### Terms

| Tool | What it does | Needs | Example |
|---|---|---|---|
| `list_terms` | Terms of a taxonomy with fields, count and URL. | any key | `{"taxonomy": "category"}` |
| `save_term` | Create or change a term: name, slug, description, parent, fields, SEO. | write, `manage_terms` | `{"taxonomy": "area", "name": "North", "description": "<p>Our northern team.</p>"}` |
| `delete_term` | Delete a term (content stays). | write, confirm, `manage_terms` | `{"id": 7, "confirm": true}` |

### Designed pages and on-page editing

| Tool | What it does | Needs | Example |
|---|---|---|---|
| `write_page` | Save a complete HTML document as a page that keeps its own design (new item or existing one). Texts, images and repeating parts become click-to-edit on the live page. | write, `edit_posts` | `{"title": "Home", "html": "<!doctype html><html><head><style>…</style></head><body>…</body></html>"}` |
| `get_page_structure` | The editable parts of an item (ids `e…` for elements, `l…` for repeating groups), the same ids the on-page editor uses. | can edit the item | `{"id": 8}` |
| `edit_page` | Change parts by id like a person on the live page: text, attributes, images, styles, tags, remove, duplicate/move/remove items of repeating groups, bind a group to live content (`loop_dynamic`). One revision per call. | write, can edit the item, `frontend_edit` | `{"id": 8, "ops": [{"op": "text", "id": "e1", "html": "Gardens that <em>thrive</em>"}]}` |
| `import_pages_from_url` | Fetch public pages with their CSS, images and fonts (SSRF-safe) and save each as a designed page (`design`: keep or site). | write, `edit_posts`, `unfiltered_html` | `{"urls": ["https://example.com/"], "design": "keep"}` |

### Templates

| Tool | What it does | Needs | Example |
|---|---|---|---|
| `list_templates`, `get_template` | Site templates (single, archive, term, 404, search), HTML and CSS. | `manage_content_model` | `{"id": "t1a2b3c4d"}` |
| `save_template` | Create or change a template (send only what changes). One active template per kind/target (`enabled: true`). Each change keeps the previous version. Scripts are removed for users without `unfiltered_html`. | write, `manage_content_model` | `{"kind": "single", "target": "service", "html": "<h1>{{title}}</h1>{{{content}}}<p>{{field:price}}</p>", "enabled": true}` |
| `undo_template` | Put back the previous version (up to 20 are kept). | write, `manage_content_model` | `{"id": "t1a2b3c4d"}` |
| `get_template_structure` | Static parts of a template that can be edited one by one (ids `te…`). | `manage_content_model` | `{"id": "t1a2b3c4d"}` |
| `edit_template_parts` | Change those parts with the `edit_page` operations; only the changed bytes are rewritten. | write, `manage_content_model` | `{"id": "t1a2b3c4d", "ops": [{"op": "text", "id": "te1", "html": "Our services"}]}` |
| `delete_template` | Delete a template. | write, confirm, `manage_content_model` | `{"id": "t1a2b3c4d", "confirm": true}` |

### Design system

| Tool | What it does | Needs | Example |
|---|---|---|---|
| `get_design_system` | Colours, fonts, type scale, layout, global classes (with hover), custom CSS variables, suggested fonts. | `manage_menus` or `manage_settings` | `{}` |
| `update_design_system` | Change only what is sent: colours, classes and variables are added or replaced by id/name, `remove_*` deletes; fonts, type scale and layout merge. | write, `manage_menus` or `manage_settings` | `{"colors": [{"id": "brand", "value": "#2a7d4f"}], "classes": [{"name": "card", "css": "padding:24px", "hover": "box-shadow:0 8px 24px rgba(0,0,0,.15)"}]}` |

Designed pages written by AI (`write_page`, Goraiya) that use `var(--…)` get the design-system variables, fonts and global
classes on the live page (not the heading and body element styles, so the page's own CSS stays in charge). Imported
pages keep exactly their own styles.

### Menus

| Tool | What it does | Needs | Example |
|---|---|---|---|
| `list_menus` | Menus with their items (resolved URLs) and the theme's locations. | `manage_menus` or `manage_settings` | `{}` |
| `save_menu` | Create a menu or replace one by id: name, location (taken from any other menu), nested items linking to items, terms or URLs. | write, `manage_menus` or `manage_settings` | `{"name": "Main", "location": "primary", "items": [{"label": "Services", "type": "custom", "url": "/services/", "children": [{"label": "", "type": "post", "object_id": 6}]}]}` |
| `delete_menu` | Delete a menu. | write, confirm, `manage_menus` or `manage_settings` | `{"id": "menu-1a2b3c", "confirm": true}` |

### SEO

Per-item SEO (title, description, focus keyword, canonical, robots, Open Graph, share image, nofollow, schema type) is
part of `create_content` / `update_content`. Global SEO settings (title templates, indexing, sitemap, robots.txt,
organisation schema, social) are settings keys starting with `seo_` (`update_settings`).

| Tool | What it does | Needs | Example |
|---|---|---|---|
| `get_seo_settings` | Global SEO settings, defaults per type and taxonomy, schema types, title variables, which instant-indexing engines are on (never their keys). | `manage_settings` | `{}` |
| `set_seo_defaults` | Defaults of one type (title template, robots, schema, sitemap, share image) or taxonomy (title template, robots, sitemap, archive schema). | write, `manage_settings` | `{"type": "service", "title_template": "%title% %sep% Services %sep% %site%", "schema": "Service"}` |
| `get_schema_mapping` | For a type and schema: the schema.org properties (required ones marked), the sources and the current mapping. | `manage_settings` | `{"type": "service"}` |
| `set_schema_mapping` | Set the schema type and where properties come from (`@title`, `field:price`, `tax:area`, `=fixed text`). | write, `manage_settings` | `{"type": "service", "schema": "Service", "map": {"offers.price": "field:price", "provider.name": "=Green Studio"}}` |
| `list_redirects` | Redirect rules. | `manage_settings` | `{}` |
| `add_redirects` | Add rules (301/302/307/308/410, regex), replacing rules with the same source. | write, `manage_settings` | `{"redirects": [{"from": "/old-page/", "to": "/new-page/"}]}` |
| `remove_redirects` | Remove rules by source. | write, `manage_settings` | `{"from": ["/old-page/"]}` |
| `request_indexing` | Ask Google / IndexNow to crawl published items now (needs instant indexing switched on). Addresses need `manage_settings`. | write, `publish_posts` | `{"ids": [12]}` |

### Media

| Tool | What it does | Needs | Example |
|---|---|---|---|
| `list_media` | Media library: URL, size, alt text. | `edit_posts` | `{"kind": "image"}` |
| `upload_media_from_url` | Import a public file into the media library (optimised like any upload), with alt text and file name. | write, `upload_media` | `{"url": "https://images.example.com/team.jpg", "filename": "team-photo", "alt": "Our team"}` |
| `update_media` | Alt text, title, caption, or rename the file (links in content follow). | write, `upload_media` (`manage_media` for others' files) | `{"id": 31, "alt": "Garden with a pond"}` |

### Settings

| Tool | What it does | Needs | Example |
|---|---|---|---|
| `get_settings` | The settings this key may see, grouped, with types, options and help. | per group: `manage_settings` (`manage_menus` for Appearance, `manage_updates` for System) | `{"group": "general"}` |
| `update_settings` | Change settings by key, validated like the dashboard. | write, as above | `{"values": {"site_title": "Green Studio", "front_page": "page", "front_page_id": 8}}` |

Exposed groups: site identity (except the admin email), homepage and reading, permalinks, date and language, revisions,
image optimisation, uploads (except the allowed file types), theme, SEO (titles, indexing, sitemap, robots.txt, schema,
social), analytics IDs, site verification, page cache and HTML output, AI brand voice, maintenance mode.

### Forms (Forms module)

Modules add their own tools. The Forms module (2.1+) adds:

| Tool | What it does | Needs | Example |
|---|---|---|---|
| `list_forms` | Forms with field and entry counts and embed codes. | `edit_others_posts` | `{}` |
| `get_form` | One form: fields, settings, entries, embed codes. | `edit_others_posts` | `{"id": 7}` |
| `save_form` | Create (from a template or own fields) or change a form: fields, messages, emails, limits, status. Keeps a revision. Place it with `{{form:slug}}`. | write, `edit_others_posts` | `{"title": "Contact", "template": "contact", "settings": {"submit_label": "Send"}}` |
| `list_form_entries` | Entry counts per form. The entries themselves only when **Forms › Settings › Let AI agents read entries** is on (never IP addresses or browser details). | `edit_others_posts` | `{"form_id": 7}` |

Results come as JSON text plus `structuredContent`. Problems an agent can fix (unknown type, not allowed, read-only key,
missing `confirm`) come back as tool results with `isError: true`; malformed calls (unknown tool, wrong argument types,
unknown arguments, values over their size limits) as JSON-RPC error `-32602`.

### Designing pages

`write_page` takes a complete HTML document (`<!doctype html><html><head>…</head><body>…</body></html>`) and stores it as
a page that keeps exactly that design (the same format as imported pages and pages made by Goraiya). Visitors get the
page with Slate's SEO tags; editable texts, images, icons and repeating groups (card lists, sliders) are marked so people
can change them by clicking on the live page, and lists can be connected to live content. Agents are told to put CSS in
the head, make it responsive, use the design-system CSS variables from `get_site`, write real copy and alt texts; scripts
are kept only for users allowed to use unfiltered HTML.

## Not available through MCP

These are left out on purpose, whatever the key's role:

- **Users and roles.** Only the key's own user can be read (`get_current_user`).
- **Installing, uploading, activating, updating or deleting modules**: it runs code on the server. Modules can only be listed.
- **Secrets**: API keys, AI provider keys, update tokens, the Google service-account key, IndexNow key management, SMTP credentials.
- **Security settings**: dashboard address, IP lists, security headers and CSP, two-factor, sessions; also email delivery,
  REST API, webhooks and MCP settings, custom head/body/footer code, backups, scheduled tasks and core updates.
- **Deleting content items** (unpublish them instead).
- **Form entries**, unless the site allows it in the Forms settings.

## Safety model

- **Keys and roles**: every call needs an API key. A read-only key can never change anything; a write key can do what
  its user's role allows, nothing more. Revoke a key in the profile and the agent loses access immediately.
- **Drafts first**: new content is a draft unless the agent passes `publish: true` *and* the user may publish
  (Contributors' content always stays a draft). The server instructions tell agents to ask before publishing.
- **Revisions**: every update, design and restore keeps the previous version (Settings → General → Writing decides how
  many). `restore_revision` or the dashboard's Revisions screen undoes any agent change.
- **Capabilities**: every tool checks the same capability as the dashboard screen it stands for (see the tables).
- **Deletions**: deleting a type, taxonomy, field group, term, template or menu needs `"confirm": true`; agents are told to
  ask the user first. Every deletion is logged.
- **Revisions and versions**: content changes keep revisions; template changes keep up to 20 versions (`undo_template`).
- **Sanitising**: users without `unfiltered_html` cannot store scripts or event handlers, in content, designed pages,
  templates or term descriptions. Arguments have size limits (HTML up to 1 MB, request body up to 12 MB).
- **Secrets**: never part of any result; settings are read and written through an allow-list, and keys that look like
  secrets are refused even inside allowed groups.
- **Media imports**: only public http(s) addresses on standard ports; private, loopback and reserved networks are
  refused, the resolved address is pinned for the download and every redirect is checked again. File type and size
  limits of the media library apply.
- **Browser protection**: requests with an `Origin` header are only accepted from the site itself or the REST API's
  allowed origins (Settings → Integrations → REST API → CORS), against DNS-rebinding attacks.
- **Rate limit**: 120 requests per minute per key (filter `mcp_rate_limit`); failed keys count towards the sign-in
  lockout like the REST API.
- **Activity log**: every write is logged with an `mcp_` action (`mcp_content_created`, `mcp_content_updated`,
  `mcp_page_written`, `mcp_page_edited`, `mcp_media_uploaded`, `mcp_revision_restored`, `mcp_post_type_saved`,
  `mcp_template_saved`, `mcp_design_saved`, `mcp_menu_saved`, `mcp_settings_saved`, `mcp_menu_deleted`, …).

## For module developers

- `mcp_tools` filter: add or change tools. Each entry is `name => ['title', 'description', 'inputSchema',
  'annotations', 'write' => bool, 'cap' => 'capability' or ['any', 'of'], 'destructive' => bool, 'group' => 'Label',
  'handler' => callable(array $args): array]`. Throw `Slate\ToolError` for errors the agent should see. Tools with
  `'write' => true` refuse read-only keys, `'cap'` is checked before the handler runs, `'destructive' => true` requires
  `"confirm": true` (add a `confirm` boolean to the schema). See `modules/slate-forms/src/McpTools.php`.
- `mcp_types` filter: content types the server exposes (default: all public types).
- `mcp_rate_limit` filter: requests per minute per key.
