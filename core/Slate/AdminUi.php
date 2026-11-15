<?php
declare(strict_types=1);

namespace Slate;

/**
 * Small building blocks so module screens look and behave like the core dashboard. All methods return HTML.
 * See docs/admin-ui.md for the full kit (ListTable, Modules::settings(), CSS classes).
 *
 *   echo AdminUi::header('Orders', 'Payments and fulfilment.', 'cart', ['New order' => admin_url('m/shop-new')]);
 *   echo AdminUi::tabs(['orders' => ['Orders', admin_url('m/shop'), 12], 'settings' => ['Settings', admin_url('settings/modules/slate-shop')]], 'orders');
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
        return $html . '</div>';
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
        return admin_url('settings/modules/' . $module);
    }
}
