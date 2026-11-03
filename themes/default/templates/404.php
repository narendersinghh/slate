<?php partial('header'); ?>
<div class="wrap narrow center not-found">
  <p class="eyebrow">Error 404</p>
  <h1>Page not found</h1>
  <p class="lead">The page you’re looking for doesn’t exist or has moved.</p>
  <form class="search-form" role="search" action="<?= e(home_url()) ?>"><input type="search" name="s" placeholder="Search…" aria-label="Search"><button>Search</button></form>
  <p><a class="button" href="<?= e(home_url()) ?>">Go to homepage</a></p>
</div>
<?php partial('footer'); ?>
