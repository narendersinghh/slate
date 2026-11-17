<?php
declare(strict_types=1);

namespace Slate;

/**
 * Small building blocks so module screens look and behave like the core dashboard. All methods return HTML.
 * See docs/admin-ui.md for the full kit (ListTable, Modules::settings(), CSS classes).
 *
 *   echo AdminUi::header('Orders', 'Payments and fulfilment.', 'cart', ['New order' => admin_url('m/shop-new')]);
 *   echo AdminUi::tabs(['orders' => ['Orders', admin_url('m/shop'), 12], 'settings' => ['Settings', AdminUi::settingsUrl('slate-shop')]], 'orders');
 *   echo AdminUi::kpis(['Revenue (30 days)' => '$1,240', 'Orders' => 18]);
 */
final class AdminUi
{
    /** Page title row: icon, title, one-line description and action buttons (label => url, or label => [url, 'primary'|'ghost'|'danger', icon]). */
    public static function header(string $title, string $description = '', string $icon = '', array $actions = []): string
    {
        $html = '<div class="mod-head"><div class="mod-head-title">' . ($icon !== '' ? '<span class="mod-head-icon">' . icon($icon, 19) . '</span>' : '')
            . '<div><h1>' . e($title) . '</h1>' . ($description !== '' ? '<p class="muted">' . e($description) . '</p>' : '') . '</div></div>';
        if ($actions) {
            $html .= '<div class="page-actions">';
            $first = true;
            foreach ($actions as $label => $a) {
                [$url, $style, $ic] = array_pad((array)$a, 3, null);
                $style ??= $first ? 'primary' : '';
                $html .= '<a class="btn' . ($style ? ' btn-' . e($style) : '') . '" href="' . e((string)$url) . '">' . ($ic ? icon($ic, 16) . ' ' : '') . e((string)$label) . '</a>';
                $first = false;
            }
            $html .= '</div>';
        }
        return $html . '</div><!--slate:module-tabs-->';
    }

    /**
     * The standard navigation of a module: its sidebar pages (and Settings) as tabs. Printed by the dashboard on
     * every module page and on the module's settings screen, so modules never draw their own page tabs.
     */
    public static function moduleNav(string $module, string $currentUrl): string
    {
        $g = Modules::adminGroups()[$module] ?? null;
        if (!$g) {
            return '';
        }
        $tabs = [];
        foreach ($g['pages'] as $p) {
            if (Auth::can($p['cap'])) {
                $tabs[admin_url('m/' . $p['slug'])] = [$p['label'], admin_url('m/' . $p['slug']), self::badge($p)];
            }
        }
        if ($g['settings'] && Auth::can($g['settings']['cap'])) {
            $u = isset($g['settings']['url']) ? admin_url($g['settings']['url']) : admin_url('m/' . $g['settings']['slug']);
            $tabs[$u] = ['Settings', $u];
        }
        return count($tabs) > 1 ? self::tabs($tabs, $currentUrl) : '';
    }

    private static function badge(array $page): ?string
    {
        $n = isset($page['badge']) && is_callable($page['badge']) ? (int)($page['badge'])() : 0;
        return $n > 0 ? (string)$n : null;
    }

    /** Wizard progress: key => label; earlier steps are shown as done. */
    public static function steps(array $steps, string $current): string
    {
        $html = '<ol class="wizard-steps">';
        $i = 0;
        $reached = false;
        foreach ($steps as $k => $label) {
            $i++;
            $on = (string)$k === $current;
            $state = $on ? 'is-current' : ($reached ? '' : 'is-done');
            if ($on) {
                $reached = true;
            }
            $html .= '<li class="' . $state . '"' . ($on ? ' aria-current="step"' : '') . '><span>' . ($state === 'is-done' ? icon('check', 13) : $i) . '</span>' . e((string)$label) . '</li>';
        }
        if (!$reached) {
            $html = str_replace('is-done', '', $html);
        }
        return $html . '</ol>';
    }

    /** Secondary switch inside a page (e.g. "Claims | Verified owners"): key => [label, url, optional count]. */
    public static function views(array $items, string $current): string
    {
        $html = '<nav class="tabs-pill page-views">';
        foreach ($items as $k => $t) {
            [$label, $url, $count] = array_pad((array)$t, 3, null);
            $html .= '<a href="' . e((string)$url) . '"' . ((string)$k === $current ? ' class="active" aria-current="page"' : '') . '>' . e((string)$label)
                . ($count !== null && $count !== '' ? ' <span>' . e((string)$count) . '</span>' : '') . '</a>';
        }
        return $html . '</nav>';
    }

    /** Underlined tabs: key => [label, url, optional count]. */
    public static function tabs(array $tabs, string $current): string
    {
        $html = '<nav class="tabs-line mod-tabs">';
        foreach ($tabs as $k => $t) {
            [$label, $url, $count] = array_pad((array)$t, 3, null);
            $on = (string)$k === $current;
            $html .= '<a href="' . e((string)$url) . '"' . ($on ? ' class="active" aria-current="page"' : '') . '>' . e((string)$label)
                . ($count !== null && $count !== '' ? ' <span class="pill">' . e((string)$count) . '</span>' : '') . '</a>';
        }
        return $html . '</nav>';
    }

    /** Row of key figures: label => value (strings are printed as given, escaped). */
    public static function kpis(array $items): string
    {
        $html = '<div class="kpis">';
        foreach ($items as $label => $value) {
            $html .= '<div class="kpi"><span>' . e((string)$label) . '</span><strong>' . e((string)$value) . '</strong></div>';
        }
        return $html . '</div>';
    }

    /** Friendly empty state. $action: label => url. */
    public static function emptyState(string $title, string $text = '', string $icon = 'inbox', array $action = []): string
    {
        $btn = '';
        foreach ($action as $label => $url) {
            $btn = '<a class="btn btn-primary" href="' . e((string)$url) . '">' . e((string)$label) . '</a>';
        }
        return '<div class="card"><div class="empty-state">' . icon($icon, 32) . '<h2>' . e($title) . '</h2>'
            . ($text !== '' ? '<p class="muted">' . e($text) . '</p>' : '') . $btn . '</div></div>';
    }

    /** Status pill: tone = success|warn|danger|info|'' */
    public static function pill(string $text, string $tone = ''): string
    {
        return '<span class="pill' . ($tone !== '' ? ' pill-' . e($tone) : '') . '">' . e($text) . '</span>';
    }

    /** Dashboard URL of a module's declarative settings screen (Modules::settings()). */
    public static function settingsUrl(string $module): string
    {
        return Settings::url('modules', $module);
    }
}
