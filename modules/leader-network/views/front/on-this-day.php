<?php
require __DIR__ . '/_form.php';
use Slate\Posts;
use Slate\Terms;
use SlateModules\LeaderNetwork\Network;

$tax = (string)Network::get('category_tax');
$ctax = (string)Network::get('country_tax');
$terms = Terms::forPosts(array_column($rows, 'post_id'));
$slugFor = static fn(DateTimeImmutable $d): string => strtolower($d->format('F')) . '-' . (int)$d->format('j');
$prev = $date->modify('-1 day');
$next = $date->modify('+1 day');
$roleSeg = $role ? $role['slug'] . '/' : '';
$link = static fn(DateTimeImmutable $d, string $seg = '') => url($base . $slugFor($d) . '/' . $seg);
$topRoles = array_values(array_filter(Terms::all($tax), static fn($t) => empty($t['parent_id'])));
$groups = ['birth' => [], 'death' => [], 'event' => []];
foreach ($rows as $r) {
    $groups[$r['kind']][] = $r;
}
$yearNow = (int)$today->format('Y');
$fmtYear = static fn(?int $y): string => $y === null ? '' : ($y < 0 ? abs($y) . ' BC' : (string)$y);
$card = static function (array $r) use ($terms, $tax, $ctax, $yearNow, $fmtYear): string {
    $p = Posts::find((int)$r['post_id']);
    if (!$p) {
        return '';
    }
    $f = $p['fields'] ?? [];
    $roles = array_slice($terms[(int)$p['id']][$tax] ?? [], 0, 2);
    $country = $terms[(int)$p['id']][$ctax][0] ?? null;
    $y = $r['year'] !== null ? (int)$r['year'] : null;
    $birth = Network::parseDate((string)($f[Network::get('birth_field')] ?? ''));
    $death = Network::parseDate((string)($f[Network::get('death_field')] ?? ''));
    $line = match ($r['kind']) {
        'birth' => $y ? ($death ? 'Born ' . $fmtYear($y) : 'Born ' . $fmtYear($y) . ' · turns ' . ($yearNow - $y) . ' today') : 'Birthday',
        'death' => 'Died ' . $fmtYear($y) . ($birth && $birth['year'] && $y ? ' · aged ' . max(0, $y - (int)$birth['year'] - ((int)str_replace('-', '', $birth['md']) > (int)str_replace('-', '', (string)$r['md']) ? 1 : 0)) : '')
            . ($y ? ' · ' . ($yearNow - $y) . ' years ago' : ''),
        default => ($y ? $fmtYear($y) . ' · ' : '') . ($yearNow - (int)$y > 0 && $y ? ($yearNow - $y) . ' years ago' : ''),
    };
    $tagline = trim(strip_tags((string)($f['tagline'] ?? $f['profession'] ?? '')));
    return '<article class="ln-otd-card">'
        . '<a class="ln-otd-media" href="' . e(Posts::permalink($p)) . '">' . ln_thumb($p, 'ln-otd-img') . '</a>'
        . '<div class="ln-otd-body">'
        . '<p class="ln-meta">' . e(trim($line, ' ·')) . ($country ? ' · ' . e($country['name']) : '') . '</p>'
        . '<h3 class="ln-otd-name"><a href="' . e(Posts::permalink($p)) . '">' . e($p['title']) . '</a></h3>'
        . ($r['kind'] === 'event' && $r['label'] !== '' ? '<p class="ln-otd-event">' . e($r['label']) . ($r['source'] ? ' <a class="ln-src" href="' . e($r['source']) . '" target="_blank" rel="nofollow noopener" title="Source">source ↗</a>' : '') . '</p>'
            : ($tagline !== '' ? '<p class="ln-otd-tag">' . e(str_limit($tagline, 120)) . '</p>' : ''))
        . ($roles ? '<p class="ln-chips">' . implode('', array_map(static fn($t) => '<a class="ln-chip" href="' . e(Terms::link($t)) . '">' . e($t['name']) . '</a>', $roles)) . '</p>' : '')
        . '</div></article>';
};
$sections = ['birth' => ['Born on this day', 'Birthdays'], 'death' => ['Remembered today', 'Anniversaries of their passing'], 'event' => ['Milestones on this day', 'Firsts, awards, launches and turning points']];
// Schema.org ItemList of the people featured.
$items = [];
foreach ($rows as $i => $r) {
    if ($p = Posts::find((int)$r['post_id'])) {
        $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'url' => Posts::permalink($p, true), 'name' => $p['title']];
    }
}
?>
<section class="ln-otd-hero">
  <div class="ln-wrap">
    <p class="ln-eyebrow"><?= $isToday ? 'Today in leadership history' : 'This day in leadership history' ?></p>
    <h1 class="ln-h1"><?= $isToday ? 'On this day, ' : '' ?><em><?= e($label) ?></em><?= $role ? ' <span class="ln-h1-sub">in ' . e($role['name']) . '</span>' : '' ?></h1>
    <p class="ln-lead"><?php if ($rows): ?><?= (int)$counts['birth'] ?> birthday<?= $counts['birth'] === 1 ? '' : 's' ?>, <?= (int)$counts['death'] ?> anniversar<?= $counts['death'] === 1 ? 'y' : 'ies' ?> and <?= (int)$counts['event'] ?> milestone<?= $counts['event'] === 1 ? '' : 's' ?> from the lives of the leaders on <?= e(site_name()) ?>.<?php else: ?>No recorded birthdays or milestones<?= $role ? ' in ' . e($role['name']) : '' ?> on this date yet — try another day or category.<?php endif; ?></p>
    <nav class="ln-otd-nav" aria-label="Change date">
      <a class="ln-btn ln-btn-sm" href="<?= e($link($prev, $roleSeg)) ?>" rel="prev">← <?= e($prev->format('M j')) ?></a>
      <form class="ln-otd-pick" method="get" action="<?= e(url($base)) ?>" data-otd-pick>
        <label class="ln-sr" for="ln-otd-date">Pick a date</label>
        <input id="ln-otd-date" class="ln-input" type="date" value="<?= e($today->format('Y') . '-' . $md) ?>">
      </form>
      <?php if (!$isToday): ?><a class="ln-btn ln-btn-sm" href="<?= e(url($base . $roleSeg)) ?>">Today</a><?php endif; ?>
      <a class="ln-btn ln-btn-sm" href="<?= e($link($next, $roleSeg)) ?>" rel="next"><?= e($next->format('M j')) ?> →</a>
    </nav>
  </div>
</section>

<div class="ln-wrap">
  <nav class="ln-otd-cats" aria-label="Filter by category">
    <a class="ln-chip<?= $role ? '' : ' is-active' ?>" href="<?= e($isToday ? url($base) : $link($date)) ?>">All leaders</a>
    <?php foreach ($topRoles as $t): ?>
      <a class="ln-chip<?= $role && ((int)$role['id'] === (int)$t['id'] || (int)($role['parent_id'] ?? 0) === (int)$t['id']) ? ' is-active' : '' ?>" href="<?= e($isToday ? url($base . $t['slug'] . '/') : $link($date, $t['slug'] . '/')) ?>"><?= e($t['name']) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php foreach ($sections as $k => [$h, $sub]): if (!$groups[$k]) continue; ?>
    <section class="ln-otd-section" id="<?= e($k) ?>">
      <div class="ln-otd-section-head"><h2 class="ln-h2"><?= e($h) ?></h2><p class="ln-muted"><?= e($sub) ?> · <?= count($groups[$k]) ?></p></div>
      <div class="ln-otd-grid"><?php foreach ($groups[$k] as $r) echo $card($r); ?></div>
    </section>
  <?php endforeach; ?>

  <?php if (!$rows): ?>
    <div class="ln-empty"><p>Nothing recorded for <?= e($label) ?><?= $role ? ' in ' . e($role['name']) : '' ?> yet.</p>
      <a class="ln-btn" href="<?= e($link($next, $roleSeg)) ?>">See <?= e($next->format('F j')) ?> →</a></div>
  <?php endif; ?>

  <section class="ln-otd-week">
    <h2 class="ln-h3">This week in history</h2>
    <div class="ln-otd-days">
      <?php for ($i = -3; $i <= 3; $i++): $d = $date->modify(($i >= 0 ? '+' : '') . $i . ' day'); ?>
        <a class="ln-day<?= $i === 0 ? ' is-active' : '' ?>" href="<?= e($link($d, $roleSeg)) ?>"><span><?= e($d->format('M')) ?></span><strong><?= e($d->format('j')) ?></strong></a>
      <?php endfor; ?>
    </div>
  </section>
  <p class="ln-muted ln-small ln-otd-note">Dates come from each leader’s verified profile. Spotted a mistake? Open the profile and let us know.</p>
</div>
<?php if ($items): ?>
<script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => 'On this day: ' . $label, 'itemListElement' => array_slice($items, 0, 100)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endif; ?>
<script>
(function () {
  var f = document.querySelector('[data-otd-pick]'); if (!f) return;
  var months = ['january','february','march','april','may','june','july','august','september','october','november','december'];
  f.querySelector('input').addEventListener('change', function () {
    var v = this.value.split('-'); if (v.length < 3) return;
    location.href = <?= json_encode(url($base)) ?> + months[parseInt(v[1], 10) - 1] + '-' + parseInt(v[2], 10) + '/' + <?= json_encode($roleSeg) ?>;
  });
})();
</script>
