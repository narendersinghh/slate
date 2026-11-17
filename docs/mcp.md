# AI agents (MCP)

Slate has a built-in [Model Context Protocol](https://modelcontextprotocol.io) server. AI agents such as Claude Code,
Claude Desktop, ChatGPT, Cursor or VS Code can connect to it and build or manage the site: read the content model and
design system, write drafts, write complete designed pages (click-to-edit on the live site), import images and undo changes.

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

| Tool | What it does | Needs |
|---|---|---|
| `get_site` | Name, URL, theme, content types with custom fields and taxonomies (with terms), design system (colour, font, type and layout CSS variables, utility classes), what the key may do. | any key |
| `list_content` | Items of one or all types: status, URL, designed page yes/no. Filters: status, search, order, paging. | any key |
| `get_content` | One item in full: content, excerpt, fields, terms, SEO, URL, designed page (head + body), revision count. | any key |
| `search` | Content, terms, media and templates at once. | any key |
| `list_templates`, `get_template` | Site templates (Templates screen), read-only. | `manage_content_model` |
| `list_media` | Media library: URL, size, alt text. | `edit_posts` |
| `get_revisions` | Earlier versions of an item; with `revision_id` one version in full plus a diff to the current one. | can edit the item |
| `create_content` | New item of any public type with content, excerpt, fields, terms, SEO, parent, featured image. Draft unless `publish: true`. | write key, `edit_posts` |
| `update_content` | Change only what is sent. Fields merge, terms replace per taxonomy sent. `status` can publish/unpublish. | write key, can edit the item |
| `write_page` | Save a complete HTML document as a page that keeps its own design (new item or existing one). Texts, images and repeating parts become click-to-edit on the live page. | write key, `edit_posts` |
| `upload_media_from_url` | Import a public file into the media library (optimised like any upload), with alt text and file name. | write key, `upload_media` |
| `restore_revision` | Put an earlier version back; the replaced version becomes a revision too. | write key, can edit the item |

Results come as JSON text plus `structuredContent`. Problems an agent can fix (unknown type, not allowed, read-only key)
come back as tool results with `isError: true`; malformed calls (unknown tool, wrong argument types, unknown arguments)
as JSON-RPC error `-32602`.

### Designing pages

`write_page` takes a complete HTML document (`<!doctype html><html><head>…</head><body>…</body></html>`) and stores it as
a page that keeps exactly that design (the same format as imported pages and pages made by Goraiya). Visitors get the
page with Slate's SEO tags; editable texts, images, icons and repeating groups (card lists, sliders) are marked so people
can change them by clicking on the live page, and lists can be connected to live content. Agents are told to put CSS in
the head, make it responsive, use the design-system CSS variables from `get_site`, write real copy and alt texts; scripts
are kept only for users allowed to use unfiltered HTML.

## Safety model

- **Keys and roles**: every call needs an API key. A read-only key can never change anything; a write key can do what
  its user's role allows, nothing more. Revoke a key in the profile and the agent loses access immediately.
- **Drafts first**: new content is a draft unless the agent passes `publish: true` *and* the user may publish
  (Contributors' content always stays a draft). The server instructions tell agents to ask before publishing.
- **Revisions**: every update, design and restore keeps the previous version (Settings → General → Writing decides how
  many). `restore_revision` or the dashboard's Revisions screen undoes any agent change.
- **Sanitising**: users without `unfiltered_html` cannot store scripts or event handlers. No tool can delete content, change settings, users, modules or templates.
- **Media imports**: only public http(s) addresses on standard ports; private, loopback and reserved networks are
  refused, the resolved address is pinned for the download and every redirect is checked again. File type and size
  limits of the media library apply.
- **Browser protection**: requests with an `Origin` header are only accepted from the site itself or the REST API's
  allowed origins (Settings → Integrations → REST API → CORS), against DNS-rebinding attacks.
- **Rate limit**: 120 requests per minute per key (filter `mcp_rate_limit`); failed keys count towards the sign-in
  lockout like the REST API.
- **Activity log**: every write is logged with an `mcp_` action (`mcp_content_created`, `mcp_content_updated`,
  `mcp_page_designed`, `mcp_media_uploaded`, `mcp_revision_restored`).

## For module developers

- `mcp_tools` filter: add or change tools. Each entry is `name => ['title', 'description', 'inputSchema',
  'annotations', 'write' => bool, 'handler' => callable(array $args): array]`. Throw `Slate\ToolError` for errors the
  agent should see. Tools with `'write' => true` refuse read-only keys automatically.
- `mcp_types` filter: content types the server exposes (default: all public types).
- `mcp_rate_limit` filter: requests per minute per key.
