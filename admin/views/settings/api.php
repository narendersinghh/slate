<?php $base = rtrim(site_url('api/v1'), '/'); $on = \Slate\Api::enabled(); ?>
<div class="card-pad stack-sm">
  <strong class="small-label">Endpoints</strong>
  <?php if (!$on): ?><p class="muted small">Switch the API on above to use these.</p><?php endif; ?>
  <pre><code>GET    <?= e($base) ?>                        site, content types, taxonomies
GET    <?= e($base) ?>/content/{type}         ?page ?per_page ?search ?order ?{taxonomy}=slug
GET    <?= e($base) ?>/content/{type}/{id|slug}
POST   <?= e($base) ?>/content/{type}         create      (key with write access)
PATCH  <?= e($base) ?>/content/{type}/{id}    update
DELETE <?= e($base) ?>/content/{type}/{id}    move to trash (?force=1 deletes)
GET    <?= e($base) ?>/terms/{taxonomy}   /menus/{location}   /media/{id}   /me</code></pre>
  <p class="muted small">Create keys under <a href="<?= e(admin_url('profile#api-keys')) ?>">your profile › API keys</a>. Keys act with the permissions of their user.
    Example: <code>curl -H "Authorization: Bearer slt_…" <?= e($base) ?>/content/post</code></p>
</div>
