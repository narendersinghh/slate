# Dashboard UI kit for modules

Module screens should look and behave exactly like the core screens. Use these pieces instead of custom
markup; the result stays consistent when the dashboard design changes.

## Where things go

- **Sidebar.** Every page a module registers with `Modules::adminPage()` is listed under **Modules → your module name**
  (the module's `name` from module.json, with its `icon`). One page shows as a single link; several pages become a
  group. A badge callable shows a count (e.g. unread items) on the page and on the group.
- **Settings.** Declare settings with `Modules::settings()`. They appear on your module's own Settings tab (`/admin/m/your-module/settings`), in the
  settings search, and as a **Settings** link at the end of your sidebar group. Do not build your own settings forms.
- **Content.** Data that is really content (products, events, forms) should be a post type, so it gets the standard
  list table, editor, revisions, SEO and API for free.

```php
use Slate\Modules;

// section: 'main' (default), 'settings' (a custom settings page, shown last as "Settings") or 'hidden' (URL only)
Modules::adminPage('Orders', 'shop-orders', [Admin::class, 'orders'], 'manage_shop', 'cart', 'main', fn() => Orders::unpaidCount());
Modules::adminPage('Customers', 'shop-customers', [Admin::class, 'customers'], 'manage_shop', 'users');

Modules::settings([
    ['type' => 'heading', 'label' => 'Store', 'help' => 'Shown on receipts.'],
    ['key' => 'shop_currency', 'type' => 'select', 'label' => 'Currency', 'default' => 'USD', 'options' => ['USD' => 'US dollar', 'EUR' => 'Euro']],
    ['key' => 'shop_tax_rate', 'type' => 'number', 'label' => 'Tax rate', 'default' => 0, 'min' => 0, 'max' => 100, 'step' => 0.01, 'suffix' => '%'],
    ['key' => 'shop_guest_checkout', 'type' => 'toggle', 'label' => 'Allow guest checkout', 'default' => true],
    ['key' => 'shop_stripe_secret', 'type' => 'secret', 'label' => 'Stripe secret key', 'default' => '', 'show_if' => 'shop_gateway=stripe'],
], ['help' => 'How your store sells.', 'render' => fn() => print '<div class="card card-pad">Extra HTML (e.g. a "Send test email" button)</div>']);
```

Field types: `text`, `email`, `url`, `number` (`min`, `max`, `step`, `suffix`), `textarea` / `code` (`rows`),
`toggle`, `select` (`options` array), `checkboxes` (several choices from `options`, saved as an array), `secret` (stored encrypted; read it with `Crypto::decrypt(Options::get(key))`),
`media`, `color`, plus `heading` (group title) and `info` (a note). `show_if` => `'key=value'` / `'key!=value'`
shows a field only when another field matches. Values are read with `Options::get('key')`.
Prefix every key with your module (`shop_`), and keep labels short with the detail in `help`.

## Page building blocks, `Slate\AdminUi`

```php
use Slate\AdminUi;

echo AdminUi::header('Orders', 'Payments, fulfilment and refunds.', 'cart', ['New order' => admin_url('m/shop-new')]);
// The dashboard draws your module's page tabs (its sidebar pages + Settings) automatically.
// For a switch inside one page use views(); for wizards use steps().
echo AdminUi::views([
    'orders' => ['Orders', admin_url('m/shop-orders'), 120],
    'refunds' => ['Refunds', admin_url('m/shop-orders') . '?show=refunds'],
], 'orders');
echo AdminUi::steps(['source' => 'Choose source', 'review' => 'Review', 'run' => 'Import'], 'review');
echo AdminUi::kpis(['Revenue · 30 days' => '$1,240.00', 'Orders' => '18', 'Awaiting payment' => '2']);
echo AdminUi::emptyState('No orders yet', 'Orders appear here as soon as someone checks out.', 'cart');
echo AdminUi::pill('Paid', 'success');   // tones: success, warn, danger, info
```

## Lists, `Slate\ListTable`

Every list of records uses `ListTable`: search, status views, filters, sortable columns, a column chooser that
each user can reorder/hide (remembered), compact/comfortable density, page size, numbered pages, bulk actions
(optionally on every matching row) and CSV export. See the class docblock for all options.

```php
$t = new \Slate\ListTable('shop.orders', [
    'number' => ['label' => 'Order', 'primary' => true, 'sort' => 'id', 'render' => fn($o) => '<a class="row-title" href="…">#' . (int)$o['id'] . '</a>'],
    'customer' => ['label' => 'Customer', 'sort' => 'email', 'render' => fn($o) => e($o['name']) . '<br><small class="muted">' . e($o['email']) . '</small>'],
    'status' => ['label' => 'Status', 'render' => fn($o) => AdminUi::pill($o['status'], $o['status'] === 'paid' ? 'success' : 'warn'), 'export' => fn($o) => $o['status']],
    'total' => ['label' => 'Total', 'sort' => 'total', 'align' => 'right', 'render' => fn($o) => e(money($o['total']))],
    'created' => ['label' => 'Date', 'sort' => 'created_at', 'render' => fn($o) => e(local_date($o['created_at']))],
    'ip' => ['label' => 'IP address', 'default' => false],               // hidden until the user picks it
], [
    'base' => admin_url('m/shop-orders'),
    'search' => 'Search orders…',
    'views' => ['all' => ['All', $counts['all']], 'pending' => ['Awaiting payment', $counts['pending']], 'paid' => ['Paid', $counts['paid']]],
    'filters' => ['method' => ['label' => 'Payment method', 'options' => ['stripe' => 'Stripe', 'razorpay' => 'Razorpay']]],
    'bulk' => ['mark_paid' => 'Mark as paid', 'delete' => ['label' => 'Delete', 'danger' => true, 'confirm' => 'Delete the selected orders?']],
    'bulk_action' => admin_url('m/shop-orders'),                          // POSTs ids[] + action (+ CSRF)
    'sort' => 'created_at', 'dir' => 'desc', 'export' => true,
    'row_actions' => fn($o) => '<a href="…">View</a><a href="…">Refund</a>',
    'empty' => ['icon' => 'cart', 'title' => 'No orders yet', 'text' => 'Orders appear here after checkout.'],
]);
$s = $t->state();   // q, view, filters, sort, dir, page, per, offset, whitelisted, safe to use
// Query with $s (map 'sort' through your own whitelist of columns; never interpolate user input).
if ($t->exporting()) { $t->exportCsv($allMatchingRows); }
echo $t->render($rows, $total);
```

Handle the bulk POST at the top of your page callback (`Csrf::check()` is done by the dashboard for every POST).

## CSS classes worth knowing

| Class | Use |
|---|---|
| `card`, `card-head`, `card-pad`, `card-foot` | Panels |
| `btn`, `btn-primary`, `btn-ghost`, `btn-danger`, `btn-sm`, `icon-btn` | Buttons |
| `input`, `field` (label + control), `grid-form`, `inline-form`, `switch` (checkbox) | Forms |
| `stack`, `stack-sm`, `grid-2`, `split`, `row-between` | Layout |
| `pill` (+ `-success`, `-warn`, `-danger`, `-info`), `status status-publish` | Badges |
| `alert`, `alert-success`, `alert-error`, `banner` | Messages |
| `muted`, `small`, `num`, `nowrap` | Text |
| `kv` (two-column key/value grid), `steps` (numbered list) | Details |
| `progress` with an inner `<span style="width:40%">` | Progress bar |

Keep screens simple: one primary action per page, short labels, explanations in `help` text or a `muted` line,
no walls of options, put rarely changed options in settings, not on the working screen.
