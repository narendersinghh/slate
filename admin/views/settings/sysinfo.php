<?php
use Slate\DB;
$dbVersion = DB::$driver === 'sqlite' ? 'SQLite ' . DB::val('select sqlite_version()') : DB::val('SELECT VERSION()');
$rows = [
  'Slate version' => SLATE_VERSION . ' (DB schema ' . (int)\Slate\Options::get('db_version') . ')',
  'PHP' => PHP_VERSION . ' · ' . PHP_SAPI,
  'Database' => $dbVersion . ' · prefix ' . DB::$prefix,
  'Web server' => $_SERVER['SERVER_SOFTWARE'] ?? '—',
  'Memory limit' => ini_get('memory_limit'),
  'Upload limit' => ini_get('upload_max_filesize') . ' (post ' . ini_get('post_max_size') . ')',
  'OPcache' => function_exists('opcache_get_status') && @opcache_get_status(false) ? 'Enabled' : 'Disabled — enable it for best speed',
  'GD / WebP' => function_exists('imagewebp') ? 'Available' : 'Missing',
  'HTTPS' => is_https() ? 'Yes' : 'No',
  'Theme' => \Slate\Theme::active(),
  'Active modules' => implode(', ', \Slate\Modules::active()) ?: 'None',
  'Queries on this page' => (string)DB::$count,
];
?>
<div class="table-wrap"><table class="table table-compact">
  <tbody><?php foreach ($rows as $k => $v): ?><tr><th style="width:220px"><?= e($k) ?></th><td><?= e($v) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
