<?php
/**
 * Forms — form builder (Content → Forms), Form widget / field / template tag, submissions inbox.
 */
declare(strict_types=1);

use Slate\App;
use Slate\Auth;
use Slate\DB;
use Slate\Hooks;
use Slate\Modules;
use Slate\Router;
use Slate\Session;
use SlateModules\Forms\Forms;

require_once __DIR__ . '/src/Forms.php';

Hooks::add('module_activated_slate-forms', [Forms::class, 'install']);

// Content type + its builder fields.
Hooks::add('post_types', static function (array $types): array {
    $types[Forms::TYPE] = Forms::postType();
    return $types;
});
Hooks::add('field_groups', static fn(array $groups): array => $groups + Forms::fieldGroups());

// Front end: widgets and shortcodes anywhere in the page (content, snapshots, templates).
Hooks::add('html_output', [Forms::class, 'replaceTokens']);

Hooks::add('init', static function (): void {
    if (App::path() === Forms::ENDPOINT) {
        Forms::handleSubmit();
    }
}, 1);

// Theme helper: echo slate_form('contact');
if (!function_exists('slate_form')) {
    function slate_form(string|int $ref): string
    {
        return Forms::render($ref);
    }
}

// ---------------------------------------------------------------- admin
Modules::adminPage('Submissions', 'submissions', static function (): void {
    Forms::install();
    require __DIR__ . '/views/submissions.php';
}, 'edit_others_posts', 'message');

Hooks::add('admin_routes', static function (Router $r): void {
    $r->post('/m/submissions/bulk', static function (): void {
        Auth::require('edit_others_posts');
        Forms::install();
        $ids = array_values(array_filter(array_map('intval', (array)input('ids', []))));
        $action = (string)input('action', '');
        if ($ids) {
            [$in, $params] = DB::in($ids, 'sid');
            match ($action) {
                'delete' => DB::query("DELETE FROM {{form_submissions}} WHERE id IN ($in)", $params),
                'read', 'new', 'spam' => DB::query("UPDATE {{form_submissions}} SET status = :st WHERE id IN ($in)", $params + ['st' => $action]),
                default => null,
            };
            Session::flash('success', count($ids) . ' submission' . (count($ids) === 1 ? '' : 's') . ' updated.');
            log_activity('submissions_' . preg_replace('/\W/', '', $action), (string)count($ids));
        }
        $to = (string)input('return', '');
        redirect($to !== '' && str_starts_with($to, admin_url()) ? $to : admin_url('m/submissions'));
    });
    $r->get('/m/submissions/export', static function (): void {
        Auth::require('edit_others_posts');
        Forms::install();
        [$where, $params] = forms_filter_sql();
        $rows = DB::all("SELECT * FROM {{form_submissions}} $where ORDER BY id DESC LIMIT 50000", $params);
        $labels = [];
        $decoded = [];
        foreach ($rows as $r) {
            $pairs = Forms::rows($r);
            $decoded[] = [$r, $pairs];
            foreach ($pairs as $p) {
                $labels[$p['label']] = true;
            }
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="submissions-' . gmdate('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $cols = array_keys($labels);
        fputcsv($out, array_merge(['ID', 'Received (UTC)', 'Form', 'Status', 'Page'], $cols, ['IP']));
        foreach ($decoded as [$r, $pairs]) {
            $map = array_column($pairs, 'value', 'label');
            $line = [$r['id'], $r['created_at'], $r['form_title'], $r['status'], $r['page_url']];
            foreach ($cols as $c) {
                $v = (string)($map[$c] ?? '');
                $line[] = preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v; // no spreadsheet formula injection
            }
            $line[] = $r['ip'];
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    });
});

/** WHERE clause for the inbox filters (?form= &status= &q=). */
function forms_filter_sql(): array
{
    $w = [];
    $p = [];
    if (($form = (int)($_GET['form'] ?? 0)) > 0) {
        $w[] = 'form_id = :form';
        $p['form'] = $form;
    }
    $status = (string)($_GET['status'] ?? '');
    if (isset(Forms::STATUSES[$status])) {
        $w[] = 'status = :status';
        $p['status'] = $status;
    } elseif ($status !== 'all') {
        $w[] = "status <> 'spam'";
    }
    if (($q = trim((string)($_GET['q'] ?? ''))) !== '') {
        $w[] = '(data LIKE :q OR page_url LIKE :q2 OR form_title LIKE :q3)';
        $p['q'] = $p['q2'] = $p['q3'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}
