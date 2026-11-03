<div class="page-head">
  <div><h1>Modules</h1><p class="muted">Extend Slate with optional features. Modules live in <code>/modules/&lt;name&gt;/</code>.</p></div>
</div>
<?php if (!$modules): ?>
  <div class="card empty-state"><?= icon('puzzle', 36) ?><h2>No modules installed</h2><p class="muted">Add a folder with <code>module.json</code> and <code>module.php</code> to <code>/modules</code>.</p></div>
<?php else: ?>
<div class="card-grid">
  <?php foreach ($modules as $m): ?>
  <div class="card module-card<?= $m['active'] ? ' is-active' : '' ?>">
    <div class="card-pad">
      <div class="row-between">
        <span class="type-icon"><?= icon('puzzle', 20) ?></span>
        <form method="post" action="<?= e(admin_url('modules/' . $m['slug'] . '/toggle')) ?>">
          <?= csrf_field() ?>
          <button class="btn btn-sm <?= $m['active'] ? '' : 'btn-primary' ?>" type="submit"><?= $m['active'] ? 'Deactivate' : 'Activate' ?></button>
        </form>
      </div>
      <h3><?= e($m['name']) ?> <small class="muted">v<?= e($m['version']) ?></small></h3>
      <p class="muted"><?= e($m['description']) ?></p>
      <small class="muted"><?= $m['author'] ? 'By ' . e($m['author']) : '' ?><?= $m['active'] ? ' · <span class="text-success">Active</span>' : '' ?></small>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<section class="card">
  <div class="card-head"><h2>Building a module</h2></div>
  <div class="card-pad prose">
<pre><code>modules/my-module/module.json
{ "name": "My module", "description": "What it does", "version": "1.0.0", "author": "You", "requires": "0.1.0" }

modules/my-module/module.php
&lt;?php
use Slate\Hooks;
use Slate\Modules;

Hooks::add('the_content', fn($html) => $html . '&lt;p&gt;Thanks for reading!&lt;/p&gt;');
Hooks::add('head', fn() => print '&lt;meta name="x" content="y"&gt;');
Modules::adminPage('My module', 'my-module', function () { echo 'Settings UI'; });</code></pre>
    <p class="muted">Available hooks include <code>init</code>, <code>head</code>, <code>body_open</code>, <code>footer</code>, <code>the_content</code>, <code>post_saved</code>, <code>post_deleted</code>, <code>media_uploaded</code>, <code>seo_title</code>, <code>seo_schema</code>, <code>settings_tabs</code>, <code>post_types</code>, <code>admin_routes</code>, <code>html_output</code>.</p>
  </div>
</section>
