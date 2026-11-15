<?php
declare(strict_types=1);

namespace Slate;

/**
 * Data table for dashboard list screens — core and modules use the same one, so every list behaves alike:
 * search, filters, status views, sortable columns, a column chooser (show/hide/reorder, remembered per user),
 * comfortable/compact density, page size, page numbers, bulk actions (also on every matching row) and CSV export.
 *
 *   $t = new ListTable('shop.orders', [
 *       'number' => ['label' => 'Order', 'primary' => true, 'sort' => 'id', 'render' => fn($o) => '<a href="…">#' . $o['id'] . '</a>'],
 *       'total'  => ['label' => 'Total', 'sort' => 'total', 'align' => 'right', 'render' => fn($o) => e(money($o['total']))],
 *       'email'  => ['label' => 'Email', 'default' => false, 'render' => fn($o) => e($o['email'])],
 *   ], [
 *       'base' => admin_url('m/shop-orders'),
 *       'search' => 'Search orders…',
 *       'views' => ['all' => ['All', 120], 'paid' => ['Paid', 80]],          // ?view=
 *       'filters' => ['method' => ['label' => 'Payment', 'options' => ['stripe' => 'Stripe', 'manual' => 'Manual']]],
 *       'bulk' => ['mark_paid' => 'Mark as paid', 'delete' => ['label' => 'Delete', 'danger' => true, 'confirm' => 'Delete?']],
 *       'bulk_action' => admin_url('m/shop-orders') . '?bulk=1',            // POST target (ids[] + action)
 *       'sort' => 'id', 'dir' => 'desc', 'per_page' => 25, 'export' => true,
 *   ]);
 *   $s = $t->state();          // ['q', 'view', 'filters', 'sort', 'dir', 'page', 'per', 'offset']
 *   … query your rows with $s …
 *   if ($t->exporting()) $t->exportCsv($allRows);   // streams a CSV and exits
 *   echo $t->render($rows, $total);
 *
 * Column keys: label, render(row): html, export(row): string (default: render without tags), sort (sort key or true =
 * column key), default (visible by default, true), primary (row title + row actions), align, class, width.
 * Options: row_id (key or callable, default "id"), row_actions(row): html, empty => [title, text, html], per_page_options.
 */
final class ListTable
{
    private const PER = [10, 25, 50, 100, 200];
    private array $prefs;
    private ?array $state = null;

    public function __construct(public readonly string $id, private array $columns, private array $opts = [])
    {
        $this->opts += ['base' => strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '', 'search' => 'Search…', 'views' => [], 'filters' => [], 'bulk' => [],
            'bulk_action' => '', 'sort' => '', 'dir' => 'desc', 'per_page' => 25, 'export' => false, 'row_id' => 'id', 'row_actions' => null,
            'empty' => [], 'per_page_options' => self::PER, 'all_matching' => false, 'view_param' => 'view'];
        $all = (array)(Auth::user()['meta']['tables'] ?? []);
        $this->prefs = is_array($all[$id] ?? null) ? $all[$id] : [];
        foreach ($this->columns as $k => &$c) {
            $c += ['label' => ucfirst((string)$k), 'default' => true, 'sort' => false, 'primary' => false, 'align' => '', 'class' => '', 'render' => null];
            if ($c['sort'] === true) {
                $c['sort'] = (string)$k;
            }
        }
        unset($c);
    }

    // ------------------------------------------------------------------ state

    public function state(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }
        $sorts = array_filter(array_column($this->columns, 'sort'));
        $sort = (string)($_GET['sort'] ?? '');
        if (!in_array($sort, $sorts, true)) {
            $sort = (string)$this->opts['sort'];
        }
        $dir = strtolower((string)($_GET['dir'] ?? $this->opts['dir'])) === 'asc' ? 'asc' : 'desc';
        $per = (int)($_GET['per'] ?? $this->prefs['per'] ?? $this->opts['per_page']);
        if (!in_array($per, $this->opts['per_page_options'], true)) {
            $per = (int)$this->opts['per_page'];
        }
        if (isset($_GET['per']) && $per !== (int)($this->prefs['per'] ?? 0)) {
            $this->savePrefs(['per' => $per]);
        }
        $filters = [];
        foreach ($this->opts['filters'] as $k => $f) {
            $v = $_GET['f'][$k] ?? '';
            if (is_string($v) && $v !== '') {
                $filters[$k] = $v;
            }
        }
        $view = (string)($_GET[$this->opts['view_param']] ?? '');
        if ($view !== '' && !isset($this->opts['views'][$view])) {
            $view = '';
        }
        $page = max(1, (int)($_GET['p'] ?? 1));
        return $this->state = ['q' => trim((string)($_GET['q'] ?? '')), 'view' => $view ?: (string)(array_key_first($this->opts['views']) ?? ''),
            'filters' => $filters, 'sort' => $sort, 'dir' => $dir, 'page' => $page, 'per' => $per, 'offset' => ($page - 1) * $per];
    }

    public function exporting(): bool
    {
        return $this->opts['export'] && ($_GET['export'] ?? '') === 'csv';
    }

    /** Visible column keys in the user's order. */
    public function visible(): array
    {
        $saved = array_values(array_filter((array)($this->prefs['cols'] ?? []), fn($k) => isset($this->columns[$k])));
        $cols = $saved ?: array_keys(array_filter($this->columns, static fn($c) => $c['default']));
        foreach ($this->columns as $k => $c) {
            if ($c['primary'] && !in_array($k, $cols, true)) {
                array_unshift($cols, $k);
            }
        }
        return $cols;
    }

    /** All column keys: visible ones first (in order), then the hidden ones. */
    private function ordered(): array
    {
        $vis = $this->visible();
        return array_merge($vis, array_values(array_diff(array_keys($this->columns), $vis)));
    }

    // ------------------------------------------------------------------ preferences (per user, in users.meta)

    public function savePrefs(array $changes): void
    {
        self::storePrefs($this->id, $changes);
        $this->prefs = array_merge($this->prefs, $changes);
    }

    public static function storePrefs(string $id, array $changes): void
    {
        $uid = Auth::id();
        if (!$uid || !preg_match('/^[\w.:-]{1,80}$/', $id)) {
            return;
        }
        $meta = json_decode((string)DB::val('SELECT meta FROM {{users}} WHERE id = :id', ['id' => $uid]), true) ?: [];
        $tables = (array)($meta['tables'] ?? []);
        $cur = (array)($tables[$id] ?? []);
        if (isset($changes['cols'])) {
            $cur['cols'] = array_slice(array_values(array_filter((array)$changes['cols'], static fn($c) => is_string($c) && preg_match('/^[\w.:-]{1,60}$/', $c))), 0, 60);
        }
        if (isset($changes['per'])) {
            $cur['per'] = max(5, min(500, (int)$changes['per']));
        }
        if (isset($changes['density'])) {
            $cur['density'] = $changes['density'] === 'compact' ? 'compact' : 'comfortable';
        }
        if (!empty($changes['reset'])) {
            $cur = [];
        }
        $tables[$id] = $cur;
        if (count($tables) > 80) {
            $tables = array_slice($tables, -80, null, true);
        }
        $meta['tables'] = $tables;
        DB::update('users', ['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $uid]);
    }

    // ------------------------------------------------------------------ URLs

    /** Current URL with some query values replaced (null removes). */
    public function url(array $over = []): string
    {
        $s = $this->state();
        $q = ['q' => $s['q'] ?: null, $this->opts['view_param'] => $s['view'] !== (string)array_key_first($this->opts['views']) ? $s['view'] : null,
            'f' => $s['filters'] ?: null, 'sort' => $s['sort'] !== $this->opts['sort'] ? $s['sort'] : null,
            'dir' => $s['dir'] !== $this->opts['dir'] ? $s['dir'] : null, 'p' => $s['page'] > 1 ? $s['page'] : null];
        foreach ($_GET as $k => $v) {
            if (!array_key_exists($k, $q) && !in_array($k, ['per', 'export'], true) && is_string($v)) {
                $q[$k] = $v; // keep screen-specific parameters (e.g. ?type=)
            }
        }
        $q = array_filter(array_merge($q, $over), static fn($v) => $v !== null && $v !== '' && $v !== []);
        $base = (string)$this->opts['base'];
        return $base . ($q ? (str_contains($base, '?') ? '&' : '?') . http_build_query($q) : '');
    }

    // ------------------------------------------------------------------ rendering

    public function render(array $rows, int $total): string
    {
        $s = $this->state();
        $vis = $this->visible();
        $density = ($this->prefs['density'] ?? 'comfortable') === 'compact' ? 'compact' : 'comfortable';
        ob_start();
        ?>
<div class="lt" data-lt="<?= e($this->id) ?>" data-prefs="<?= e(admin_url('ui/table')) ?>">
  <div class="lt-toolbar">
    <div class="lt-top">
    <?php if ($this->opts['views']): ?>
    <nav class="tabs-pill" aria-label="Views">
      <?php foreach ($this->opts['views'] as $k => $v): [$label, $count] = array_pad((array)$v, 2, null); ?>
        <a href="<?= e($this->url([$this->opts['view_param'] => $k === array_key_first($this->opts['views']) ? null : $k, 'p' => null])) ?>" class="<?= $s['view'] === (string)$k ? 'active' : '' ?>"><?= e($label) ?><?php if ($count !== null): ?> <span><?= number_format((int)$count) ?></span><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <div class="lt-tools">
      <?php if ($this->opts['export']): ?>
        <a class="icon-btn" href="<?= e($this->url(['export' => 'csv', 'p' => null])) ?>" title="Export these rows as CSV" aria-label="Export CSV"><?= icon('download', 16) ?></a>
      <?php endif; ?>
      <button type="button" class="icon-btn" data-lt-density="<?= $density ?>" title="Row density" aria-label="Toggle row density"><?= icon('rows', 16) ?></button>
      <div class="dropdown">
        <button type="button" class="btn btn-sm" data-dropdown><?= icon('columns', 15) ?> Columns</button>
        <div class="dropdown-menu dropdown-right lt-colmenu" data-lt-colmenu>
          <div class="dropdown-head"><strong>Columns</strong><small>Drag to reorder</small></div>
          <?php foreach ($this->ordered() as $k): $c = $this->columns[$k]; ?>
            <label class="lt-colitem" draggable="true" data-col="<?= e($k) ?>"><span class="lt-grip"><?= icon('grip', 14) ?></span>
              <input type="checkbox" value="<?= e($k) ?>"<?= in_array($k, $vis, true) ? ' checked' : '' ?><?= $c['primary'] ? ' disabled' : '' ?>> <span><?= e($c['label']) ?></span></label>
          <?php endforeach; ?>
          <button type="button" class="btn btn-ghost btn-sm lt-reset" data-lt-reset>Reset to default</button>
        </div>
      </div>
    </div>
    </div>
    <form class="lt-filters" method="get" action="<?= e(strtok((string)$this->opts['base'], '?')) ?>" data-lt-filters>
      <?php foreach ($this->hiddenInputs(['q', 'f', 'p']) as $n => $v): ?><input type="hidden" name="<?= e($n) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
      <?php if ($this->opts['search'] !== false): ?>
        <label class="lt-search"><?= icon('search', 15) ?><input class="input" type="search" name="q" value="<?= e($s['q']) ?>" placeholder="<?= e($this->opts['search']) ?>" aria-label="Search"></label>
      <?php endif; ?>
      <?php if ($this->opts['filters']): ?><button type="button" class="btn btn-sm lt-filter-toggle" data-lt-filter-toggle><?= icon('filter', 14) ?> Filters<?= $s['filters'] ? ' <span class="pill pill-accent">' . count($s['filters']) . '</span>' : '' ?></button><?php endif; ?>
      <?php foreach ($this->opts['filters'] as $k => $f): $cur = $s['filters'][$k] ?? ''; ?>
        <?php if (($f['type'] ?? 'select') === 'text'): ?>
          <input class="input lt-filter<?= $cur !== '' ? ' is-set' : '' ?>" name="f[<?= e($k) ?>]" value="<?= e($cur) ?>" placeholder="<?= e($f['label']) ?>" aria-label="<?= e($f['label']) ?>"<?= !empty($f['list']) ? ' list="lt-' . e($this->id . '-' . $k) . '"' : '' ?>>
          <?php if (!empty($f['list'])): ?><datalist id="lt-<?= e($this->id . '-' . $k) ?>"><?php foreach ($f['list'] as $o): ?><option value="<?= e($o) ?>"><?php endforeach; ?></datalist><?php endif; ?>
        <?php else: ?>
          <select class="input lt-filter<?= $cur !== '' ? ' is-set' : '' ?>" name="f[<?= e($k) ?>]" aria-label="<?= e($f['label']) ?>" data-autosubmit>
            <option value=""><?= e($f['all'] ?? 'All ' . strtolower($f['label'])) ?></option>
            <?php foreach ($f['options'] as $ov => $ol): ?><option value="<?= e($ov) ?>"<?= (string)$ov === $cur ? ' selected' : '' ?>><?= e($ol) ?></option><?php endforeach; ?>
          </select>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($s['q'] !== '' || $s['filters']): ?><a class="btn btn-ghost btn-sm" href="<?= e($this->url(['q' => null, 'f' => null, 'p' => null])) ?>"><?= icon('x', 14) ?> Clear</a><?php endif; ?>
    </form>
  </div>

  <form method="post" action="<?= e((string)$this->opts['bulk_action']) ?>" class="card lt-card" data-bulk>
    <?= csrf_field() ?>
    <?php if ($this->opts['bulk']): ?>
    <div class="bulk-bar" hidden>
      <span class="bulk-count">0 selected</span>
      <?php if ($this->opts['all_matching'] && $total > count($rows)): ?>
        <label class="bulk-all"><input type="checkbox" name="all_matching" value="1" data-bulk-all> Select all <?= number_format($total) ?> matching</label>
        <input type="hidden" name="query" value="<?= e(http_build_query($_GET)) ?>">
      <?php endif; ?>
      <span class="bulk-actions">
      <?php foreach ($this->opts['bulk'] as $k => $b): $b = is_array($b) ? $b : ['label' => $b]; ?>
        <?php if (!empty($b['options'])): ?>
          <span class="bulk-select"><select class="input input-sm" name="bulk_value[<?= e($k) ?>]" aria-label="<?= e($b['label']) ?>"><option value=""><?= e($b['label']) ?>…</option>
            <?php foreach ($b['options'] as $ov => $ol): ?><option value="<?= e($ov) ?>"><?= e($ol) ?></option><?php endforeach; ?></select>
            <button class="btn btn-sm" name="action" value="<?= e($k) ?>">Apply</button></span>
        <?php else: ?>
          <button class="btn btn-sm<?= !empty($b['danger']) ? ' btn-danger' : '' ?>" name="action" value="<?= e($k) ?>"<?= !empty($b['confirm']) ? ' data-confirm="' . e($b['confirm']) . '"' : '' ?>><?= e($b['label']) ?></button>
        <?php endif; ?>
      <?php endforeach; ?>
      </span>
    </div>
    <?php endif; ?>

    <?php if (!$rows): $em = $this->opts['empty']; $searching = $s['q'] !== '' || $s['filters']; ?>
      <div class="empty-state">
        <?= icon($searching ? 'search' : ($em['icon'] ?? 'inbox'), 32) ?>
        <h2><?= e($searching ? 'Nothing matches' : ($em['title'] ?? 'Nothing here yet')) ?></h2>
        <p class="muted"><?= e($searching ? 'Try other words or clear the filters.' : ($em['text'] ?? '')) ?></p>
        <?php if ($searching): ?><a class="btn" href="<?= e($this->url(['q' => null, 'f' => null, 'p' => null])) ?>">Clear search & filters</a><?php elseif (!empty($em['html'])): ?><?= $em['html'] ?><?php endif; ?>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table lt-table density-<?= $density ?>">
        <thead><tr>
          <?php if ($this->opts['bulk']): ?><th class="col-check"><input type="checkbox" data-check-all aria-label="Select all on this page"></th><?php endif; ?>
          <?php foreach ($this->ordered() as $k): $c = $this->columns[$k]; ?>
            <th data-col="<?= e($k) ?>" class="<?= e(trim($c['class'] . ($c['align'] === 'right' ? ' num' : ''))) ?>"<?= in_array($k, $vis, true) ? '' : ' hidden' ?><?= !empty($c['width']) ? ' style="width:' . e($c['width']) . '"' : '' ?>>
              <?php if ($c['sort']): $on = $s['sort'] === $c['sort']; $next = $on && $s['dir'] === 'asc' ? 'desc' : ($on ? 'asc' : ($c['sort_dir'] ?? 'asc')); ?>
                <a class="lt-sort<?= $on ? ' on' : '' ?>" href="<?= e($this->url(['sort' => $c['sort'], 'dir' => $next, 'p' => null])) ?>"><?= e($c['label']) ?><?= icon($on ? ($s['dir'] === 'asc' ? 'arrow-up' : 'arrow-down') : 'sort', 13) ?></a>
              <?php else: ?><?= e($c['label']) ?><?php endif; ?>
            </th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $row): $rid = is_callable($this->opts['row_id']) ? ($this->opts['row_id'])($row) : ($row[$this->opts['row_id']] ?? ''); ?>
          <tr>
            <?php if ($this->opts['bulk']): ?><td class="col-check"><input type="checkbox" name="ids[]" value="<?= e((string)$rid) ?>" aria-label="Select row"></td><?php endif; ?>
            <?php foreach ($this->ordered() as $k): $c = $this->columns[$k]; ?>
              <td data-col="<?= e($k) ?>" class="<?= e(trim($c['class'] . ($c['align'] === 'right' ? ' num' : '') . ($c['primary'] ? ' col-primary' : ''))) ?>"<?= in_array($k, $vis, true) ? '' : ' hidden' ?>>
                <?= $c['render'] ? ($c['render'])($row) : e((string)($row[$k] ?? '')) ?>
                <?php if ($c['primary'] && $this->opts['row_actions']): ?><div class="row-actions"><?= ($this->opts['row_actions'])($row) ?></div><?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?= $this->footer($total, count($rows)) ?>
  </form>
</div>
        <?php
        return (string)ob_get_clean();
    }

    private function footer(int $total, int $shown): string
    {
        $s = $this->state();
        $pages = max(1, (int)ceil($total / max(1, $s['per'])));
        if (!$total) {
            return '';
        }
        $from = $s['offset'] + 1;
        $to = $s['offset'] + $shown;
        $html = '<div class="lt-foot"><span class="muted">' . number_format($from) . '–' . number_format($to) . ' of ' . number_format($total) . '</span>';
        $html .= '<label class="lt-per muted">Rows per page <select class="input input-sm" data-lt-per>';
        foreach ($this->opts['per_page_options'] as $n) {
            $html .= '<option value="' . $n . '"' . ($n === $s['per'] ? ' selected' : '') . '>' . $n . '</option>';
        }
        $html .= '</select></label>';
        if ($pages > 1) {
            $html .= '<nav class="pager-nums" aria-label="Pages">';
            $html .= $s['page'] > 1 ? '<a class="btn btn-sm btn-ghost" href="' . e($this->url(['p' => $s['page'] - 1])) . '" aria-label="Previous page">' . icon('chevron-left', 15) . '</a>' : '';
            $last = 0;
            foreach (array_unique([1, 2, $s['page'] - 1, $s['page'], $s['page'] + 1, $pages - 1, $pages]) as $n) {
                if ($n < 1 || $n > $pages) {
                    continue;
                }
                if ($last && $n > $last + 1) {
                    $html .= '<span class="muted">…</span>';
                }
                $html .= $n === $s['page'] ? '<span class="btn btn-sm is-current" aria-current="page">' . $n . '</span>'
                    : '<a class="btn btn-sm btn-ghost" href="' . e($this->url(['p' => $n])) . '">' . $n . '</a>';
                $last = $n;
            }
            $html .= $s['page'] < $pages ? '<a class="btn btn-sm btn-ghost" href="' . e($this->url(['p' => $s['page'] + 1])) . '" aria-label="Next page">' . icon('chevron-right', 15) . '</a>' : '';
            $html .= '</nav>';
        }
        return $html . '</div>';
    }

    /** Query values to carry through the filter form, flattened (excluding $skip). */
    private function hiddenInputs(array $skip): array
    {
        $out = [];
        foreach ($_GET as $k => $v) {
            if (in_array($k, $skip, true) || in_array($k, ['export', 'per'], true) || !is_string($v)) {
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    // ------------------------------------------------------------------ export

    /** Stream the given rows as CSV with the visible columns, then exit. */
    public function exportCsv(iterable $rows, string $filename = ''): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean(); // anything the page printed before the export must not end up in the file
        }
        $cols = $this->visible();
        $name = $filename ?: preg_replace('/[^a-z0-9-]+/', '-', strtolower($this->id)) . '-' . gmdate('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn($k) => $this->columns[$k]['label'], $cols), ',', '"', '');
        foreach ($rows as $row) {
            $line = [];
            foreach ($cols as $k) {
                $c = $this->columns[$k];
                $v = isset($c['export']) ? ($c['export'])($row) : ($c['render'] ? ($c['render'])($row) : (string)($row[$k] ?? ''));
                $v = trim(html_entity_decode(strip_tags(preg_replace('#<div class="row-actions">.*?</div>#s', '', (string)$v)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                // Neutralise spreadsheet formulas.
                $line[] = preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : preg_replace('/\s+/', ' ', $v);
            }
            fputcsv($out, $line, ',', '"', '');
        }
        fclose($out);
        exit;
    }
}
