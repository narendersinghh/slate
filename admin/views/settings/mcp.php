<?php
$endpoint = \Slate\Mcp::endpoint();
$on = \Slate\Mcp::enabled();
$keyUrl = admin_url('profile#api-keys');
$claudeCode = 'claude mcp add --transport http slate ' . $endpoint . ' --header "Authorization: Bearer slt_…"';
$desktop = json_encode(['mcpServers' => ['slate' => ['command' => 'npx', 'args' => ['-y', 'mcp-remote', $endpoint, '--header', 'Authorization:${SLATE_AUTH}'],
    'env' => ['SLATE_AUTH' => 'Bearer slt_…']]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$generic = json_encode(['mcpServers' => ['slate' => ['type' => 'http', 'url' => $endpoint, 'headers' => ['Authorization' => 'Bearer slt_…']]]],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$snippet = static fn(string $label, string $code) => '<div class="stack-sm"><span class="row-between"><strong class="small-label">' . e($label) . '</strong>'
    . '<button type="button" class="btn btn-sm" data-copy="' . e($code) . '">' . icon('copy', 14) . ' Copy</button></span><pre><code>' . e($code) . '</code></pre></div>';
?>
<div class="card-pad stack">
  <?php if (!$on): ?><p class="muted small">Switch the MCP server on above, then connect an agent with the steps below.</p><?php endif; ?>
  <div class="stack-sm">
    <strong class="small-label">Endpoint</strong>
    <span class="row-between"><code style="word-break:break-all"><?= e($endpoint) ?></code>
      <button type="button" class="btn btn-sm" data-copy="<?= e($endpoint) ?>"><?= icon('copy', 14) ?> Copy</button></span>
    <p class="muted small">Streamable HTTP, JSON-RPC over POST. Agents can build the whole site with the same permissions as in the dashboard: design system,
      post types, taxonomies and custom fields, templates, designed pages and drafts, menus, SEO, redirects, media and forms. They can restore
      revisions and undo template changes; deleting needs an explicit confirmation. Users, module installs, secrets and security settings are
      never available to agents.</p>
  </div>
  <ol class="small stack-sm" style="padding-left:1.2em;margin:0">
    <li>Create a key under <a href="<?= e($keyUrl) ?>">your profile › API keys</a>. Choose <em>Read only</em> to let an agent look around,
      <em>Read &amp; write</em> to let it create drafts and designs. The agent acts as you, with your role's permissions; a dedicated user with the
      Author or Editor role keeps it narrower.</li>
    <li>Add the server to your AI client with one of the snippets below, replacing <code>slt_…</code> with the key.</li>
    <li>Ask, for example: <em>“Look at my site and draft a services page in my brand style.”</em> New content stays a draft unless you ask the agent to publish.</li>
  </ol>
  <?= $snippet('Claude Code (terminal)', $claudeCode) ?>
  <?= $snippet('Claude Desktop (claude_desktop_config.json, needs Node.js)', (string)$desktop) ?>
  <?= $snippet('Cursor, VS Code, Windsurf and other clients with remote MCP support', (string)$generic) ?>
  <p class="muted small">Any client that can send an <code>Authorization</code> header works. Keys can be revoked any time in your profile;
    every change an agent makes appears in the activity log (actions starting with <code>mcp_</code>) and keeps a revision. Full guide: <code>docs/mcp.md</code>.</p>
</div>
