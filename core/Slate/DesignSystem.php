<?php
declare(strict_types=1);

namespace Slate;

/**
 * Global design system for the website frontend (Settings → Appearance → Design system):
 * colours, typography (fonts + type scale), layout tokens, buttons and reusable global CSS classes.
 * Output as CSS custom properties + classes in <head>; Elementor's --e-global-* variables are aliased
 * so imported Elementor designs follow these settings too.
 */
final class DesignSystem
{
    public const FONTS = ['Inter', 'Instrument Serif', 'Poppins', 'Montserrat', 'Roboto', 'Open Sans', 'Lato', 'Raleway', 'Nunito', 'Work Sans',
        'DM Sans', 'Manrope', 'Plus Jakarta Sans', 'Outfit', 'Sora', 'Urbanist', 'Figtree', 'Rubik', 'Mulish', 'Barlow', 'Josefin Sans', 'Kanit',
        'Playfair Display', 'Merriweather', 'Lora', 'DM Serif Display', 'Cormorant Garamond', 'Libre Baskerville', 'Fraunces', 'Space Grotesk',
        'Bebas Neue', 'Oswald', 'Archivo', 'Hind', 'Mukta', 'Noto Sans', 'Source Sans 3', 'PT Serif', 'EB Garamond', 'Syne'];
    public const SYSTEM_FONTS = ['system-ui' => 'System UI', 'Georgia' => 'Georgia (serif)', 'ui-monospace' => 'Monospace'];
    public const TYPE_KEYS = ['h1' => 'Heading 1', 'h2' => 'Heading 2', 'h3' => 'Heading 3', 'h4' => 'Heading 4', 'h5' => 'Heading 5', 'h6' => 'Heading 6',
        'body' => 'Body text', 'small' => 'Small text', 'button' => 'Buttons'];

    public static function defaults(): array
    {
        return [
            'colors' => [
                ['id' => 'primary', 'name' => 'Primary', 'value' => '#F26B2D'],
                ['id' => 'secondary', 'name' => 'Secondary', 'value' => '#0D0D0E'],
                ['id' => 'text', 'name' => 'Text', 'value' => '#1F1F1F'],
                ['id' => 'accent', 'name' => 'Accent', 'value' => '#FFB48A'],
                ['id' => 'background', 'name' => 'Background', 'value' => '#FFFFFF'],
                ['id' => 'muted', 'name' => 'Muted', 'value' => '#6B6B6B'],
            ],
            'fonts' => ['heading' => 'Instrument Serif', 'body' => 'Inter', 'google' => true],
            'type' => [
                'h1' => ['font' => 'heading', 'size' => 56, 'weight' => 400, 'line' => 1.1, 'tracking' => -0.01],
                'h2' => ['font' => 'heading', 'size' => 40, 'weight' => 400, 'line' => 1.15, 'tracking' => -0.01],
                'h3' => ['font' => 'heading', 'size' => 30, 'weight' => 400, 'line' => 1.2, 'tracking' => 0],
                'h4' => ['font' => 'body', 'size' => 22, 'weight' => 600, 'line' => 1.3, 'tracking' => 0],
                'h5' => ['font' => 'body', 'size' => 18, 'weight' => 600, 'line' => 1.4, 'tracking' => 0],
                'h6' => ['font' => 'body', 'size' => 15, 'weight' => 600, 'line' => 1.4, 'tracking' => 0.04],
                'body' => ['font' => 'body', 'size' => 17, 'weight' => 400, 'line' => 1.7, 'tracking' => 0],
                'small' => ['font' => 'body', 'size' => 14, 'weight' => 400, 'line' => 1.5, 'tracking' => 0],
                'button' => ['font' => 'body', 'size' => 15, 'weight' => 600, 'line' => 1, 'tracking' => 0],
            ],
            'layout' => ['container' => 1200, 'radius' => 12, 'button_radius' => 10, 'section_space' => 80],
            'apply_elements' => true,
            'responsive' => true,
            'admin_match' => false,
            'classes' => [
                ['name' => 'btn', 'css' => "display:inline-flex;align-items:center;gap:.5em;padding:.9em 1.6em;border-radius:var(--radius-button);\nbackground:var(--color-primary);color:#fff;font:var(--type-button);text-decoration:none;transition:filter .2s;"],
                ['name' => 'btn-outline', 'css' => "display:inline-flex;align-items:center;padding:.85em 1.5em;border-radius:var(--radius-button);\nborder:1.5px solid currentColor;color:var(--color-primary);text-decoration:none;"],
                ['name' => 'container', 'css' => 'width:100%;max-width:var(--container);margin-inline:auto;padding-inline:24px;'],
                ['name' => 'section', 'css' => 'padding-block:var(--section-space);'],
                ['name' => 'eyebrow', 'css' => 'font:600 12px/1 var(--font-body);letter-spacing:.14em;text-transform:uppercase;color:var(--color-primary);'],
            ],
        ];
    }

    public static function get(): array
    {
        $ds = Options::get('design_system');
        $d = self::defaults();
        if (!is_array($ds)) {
            return $d;
        }
        $ds += $d;
        $ds['fonts'] += $d['fonts'];
        $ds['layout'] += $d['layout'];
        foreach ($d['type'] as $k => $v) {
            $ds['type'][$k] = ($ds['type'][$k] ?? []) + $v;
        }
        return $ds;
    }

    public static function save(array $in): void
    {
        $d = self::defaults();
        $colors = [];
        foreach ((array)($in['colors'] ?? []) as $c) {
            $id = preg_replace('/[^a-z0-9-]/', '', strtolower(str_replace([' ', '_'], '-', (string)($c['id'] ?? $c['name'] ?? ''))));
            $val = trim((string)($c['value'] ?? ''));
            if ($id === '' || !self::validColor($val)) {
                continue;
            }
            $colors[$id] = ['id' => $id, 'name' => mb_substr(strip_tags((string)($c['name'] ?? $id)), 0, 40), 'value' => $val];
        }
        $type = [];
        foreach (self::TYPE_KEYS as $k => $_) {
            $t = (array)($in['type'][$k] ?? []);
            $type[$k] = [
                'font' => in_array($t['font'] ?? '', ['heading', 'body'], true) ? $t['font'] : $d['type'][$k]['font'],
                'size' => max(8, min(160, (float)($t['size'] ?? $d['type'][$k]['size']))),
                'weight' => max(100, min(900, (int)($t['weight'] ?? $d['type'][$k]['weight']))),
                'line' => max(0.8, min(3, (float)($t['line'] ?? $d['type'][$k]['line']))),
                'tracking' => max(-0.2, min(0.5, (float)($t['tracking'] ?? $d['type'][$k]['tracking']))),
            ];
        }
        $classes = [];
        foreach ((array)($in['classes'] ?? []) as $c) {
            $name = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($c['name'] ?? ''));
            if ($name === '' || !preg_match('/^[a-zA-Z_-]/', $name)) {
                continue;
            }
            $classes[] = ['name' => $name, 'css' => self::cleanCss((string)($c['css'] ?? ''))];
        }
        $font = static fn($f, $def) => self::validFont((string)$f) ? (string)$f : $def;
        Options::set('design_system', [
            'colors' => array_values($colors),
            'fonts' => ['heading' => $font($in['fonts']['heading'] ?? '', $d['fonts']['heading']), 'body' => $font($in['fonts']['body'] ?? '', $d['fonts']['body']),
                'google' => !empty($in['fonts']['google'])],
            'type' => $type,
            'layout' => [
                'container' => max(600, min(2400, (int)($in['layout']['container'] ?? 1200))),
                'radius' => max(0, min(60, (int)($in['layout']['radius'] ?? 12))),
                'button_radius' => max(0, min(60, (int)($in['layout']['button_radius'] ?? 10))),
                'section_space' => max(0, min(300, (int)($in['layout']['section_space'] ?? 80))),
            ],
            'apply_elements' => !empty($in['apply_elements']),
            'responsive' => !empty($in['responsive']),
            'admin_match' => !empty($in['admin_match']),
            'classes' => $classes,
        ]);
        PageCache::clear();
    }

    public static function validColor(string $v): bool
    {
        return (bool)preg_match('/^(#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\)|hsla?\([\d\s.,%deg]+\)|transparent|currentColor)$/i', $v);
    }

    public static function validFont(string $f): bool
    {
        return $f !== '' && (bool)preg_match('/^[A-Za-z0-9 \-]{2,60}$/', $f);
    }

    /** Declarations only — no braces, no tags, no imports. */
    public static function cleanCss(string $css): string
    {
        $css = str_replace(["\r", '<', '>', '{', '}', '@import', 'expression(', 'javascript:'], ['', '', '', '', '', '', '', ''], $css);
        return trim(mb_substr($css, 0, 4000));
    }

    private static function family(string $f): string
    {
        if (isset(self::SYSTEM_FONTS[$f])) {
            return match ($f) {
                'Georgia' => 'Georgia, "Times New Roman", serif',
                'ui-monospace' => 'ui-monospace, SFMono-Regular, Menlo, monospace',
                default => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
            };
        }
        $serif = preg_match('/serif|garamond|baskerville|playfair|lora|merriweather|fraunces/i', $f) && !preg_match('/sans/i', $f);
        return '"' . $f . '", ' . ($serif ? 'Georgia, serif' : 'system-ui, -apple-system, "Segoe UI", sans-serif');
    }

    /** Google Fonts stylesheet URL for the chosen families. */
    public static function fontsUrl(?array $ds = null): ?string
    {
        $ds ??= self::get();
        if (empty($ds['fonts']['google'])) {
            return null;
        }
        $fams = [];
        foreach (array_unique([$ds['fonts']['heading'], $ds['fonts']['body']]) as $f) {
            if (!isset(self::SYSTEM_FONTS[$f])) {
                $fams[] = 'family=' . str_replace(' ', '+', $f) . ($f === 'Instrument Serif' ? ':ital@0;1' : ':wght@300;400;500;600;700');
            }
        }
        return $fams ? 'https://fonts.googleapis.com/css2?' . implode('&', $fams) . '&display=swap' : null;
    }

    public static function css(?array $ds = null): string
    {
        $ds ??= self::get();
        $v = [];
        foreach ($ds['colors'] as $c) {
            $v[] = '--color-' . $c['id'] . ':' . $c['value'];
            // Elementor aliases: imported layouts follow the Slate palette.
            $v[] = '--e-global-color-' . $c['id'] . ':' . $c['value'];
        }
        $v[] = '--font-heading:' . self::family($ds['fonts']['heading']);
        $v[] = '--font-body:' . self::family($ds['fonts']['body']);
        foreach (['primary' => 'heading', 'secondary' => 'heading', 'text' => 'body', 'accent' => 'body'] as $el => $f) {
            $v[] = '--e-global-typography-' . $el . '-font-family:' . self::family($ds['fonts'][$f]);
        }
        foreach ($ds['type'] as $k => $t) {
            $fam = $t['font'] === 'heading' ? 'var(--font-heading)' : 'var(--font-body)';
            $v[] = "--fs-$k:" . self::fluid((float)$t['size'], (bool)$ds['responsive']);
            $v[] = "--type-$k:{$t['weight']} var(--fs-$k)/{$t['line']} $fam";
        }
        $v[] = '--container:' . $ds['layout']['container'] . 'px';
        $v[] = '--radius:' . $ds['layout']['radius'] . 'px';
        $v[] = '--radius-button:' . $ds['layout']['button_radius'] . 'px';
        $v[] = '--section-space:' . $ds['layout']['section_space'] . 'px';
        $v[] = '--accent:var(--color-primary)';
        $css = ':root{' . implode(';', $v) . '}';
        if ($ds['apply_elements']) {
            $css .= 'body{font:var(--type-body);color:var(--color-text);letter-spacing:' . $ds['type']['body']['tracking'] . 'em}';
            foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $h) {
                $css .= "$h,.$h{font:var(--type-$h);letter-spacing:{$ds['type'][$h]['tracking']}em}";
            }
            $css .= 'small,.small{font:var(--type-small)}';
        }
        foreach ($ds['colors'] as $c) {
            $id = $c['id'];
            $css .= ".has-$id-color,.text-$id{color:var(--color-$id)!important}.has-$id-background-color,.bg-$id{background-color:var(--color-$id)!important}";
        }
        foreach ($ds['classes'] as $c) {
            if ($c['css'] !== '') {
                $css .= '.' . $c['name'] . '{' . str_replace("\n", '', $c['css']) . '}';
            }
        }
        return Hooks::filter('design_system_css', $css, $ds);
    }

    private static function fluid(float $px, bool $responsive): string
    {
        if (!$responsive || $px <= 20) {
            return $px . 'px';
        }
        $min = round(max(16, $px * 0.62), 1);
        $vw = round($px / 14.4, 2);
        return "clamp({$min}px, {$vw}vw, {$px}px)";
    }

    public static function head(): string
    {
        $ds = self::get();
        $out = '';
        if ($url = self::fontsUrl($ds)) {
            $out .= '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
                . '<link rel="stylesheet" href="' . e($url) . '">';
        }
        return $out . '<style id="slate-design-system">' . self::css($ds) . '</style>';
    }

    /** Brand palette for the admin (when "match website" is on): primary + generated shades. */
    public static function adminCss(): string
    {
        $ds = self::get();
        if (empty($ds['admin_match'])) {
            return '';
        }
        $primary = '';
        foreach ($ds['colors'] as $c) {
            if ($c['id'] === 'primary' && preg_match('/^#[0-9a-f]{6}$/i', $c['value'])) {
                $primary = $c['value'];
            }
        }
        $css = ':root{';
        if ($primary) {
            foreach (['50' => 0.94, '100' => 0.86, '200' => 0.7, '300' => 0.5, '400' => 0.25] as $k => $t) {
                $css .= "--o-$k:" . self::mix($primary, '#ffffff', $t) . ';';
            }
            $css .= '--o-500:' . $primary . ';';
            foreach (['600' => 0.15, '700' => 0.3, '800' => 0.45, '900' => 0.62] as $k => $t) {
                $css .= "--o-$k:" . self::mix($primary, '#000000', $t) . ';';
            }
        }
        $css .= '--serif:' . self::family($ds['fonts']['heading']) . ';--font:' . self::family($ds['fonts']['body']) . '}';
        $out = '<style id="slate-admin-brand">' . $css . '</style>';
        if ($url = self::fontsUrl($ds)) {
            $out = '<link rel="stylesheet" href="' . e($url) . '">' . $out;
        }
        return $out;
    }

    private static function mix(string $a, string $b, float $t): string
    {
        $ca = sscanf($a, '#%02x%02x%02x');
        $cb = sscanf($b, '#%02x%02x%02x');
        return sprintf('#%02x%02x%02x', ...array_map(static fn($i) => (int)round($ca[$i] + ($cb[$i] - $ca[$i]) * $t), [0, 1, 2]));
    }

    /** Import Elementor kit globals (colours + fonts) into the design system. */
    public static function fromElementorCss(string $css): array
    {
        $ds = self::get();
        if (preg_match_all('/--e-global-color-([a-z0-9]+)\s*:\s*([^;}]+)/i', $css, $m, PREG_SET_ORDER)) {
            $names = ['primary' => 'Primary', 'secondary' => 'Secondary', 'text' => 'Text', 'accent' => 'Accent'];
            $byId = [];
            foreach ($ds['colors'] as $c) {
                $byId[$c['id']] = $c;
            }
            foreach ($m as $x) {
                $id = strtolower($x[1]);
                $val = trim($x[2]);
                if (self::validColor($val)) {
                    $byId[$id] = ['id' => $id, 'name' => $names[$id] ?? ($byId[$id]['name'] ?? 'Color ' . $id), 'value' => $val];
                }
            }
            $ds['colors'] = array_values($byId);
        }
        $font = static function (string $key) use ($css): ?string {
            return preg_match('/--e-global-typography-' . $key . '-font-family\s*:\s*["\']?([^"\';,}]+)/i', $css, $m) ? trim($m[1]) : null;
        };
        if ($f = $font('primary')) {
            $ds['fonts']['heading'] = $f;
        }
        if ($f = $font('text')) {
            $ds['fonts']['body'] = $f;
        }
        foreach (['primary' => 'h2', 'text' => 'body'] as $k => $t) {
            if (preg_match('/--e-global-typography-' . $k . '-font-size\s*:\s*([\d.]+)px/i', $css, $mm)) {
                $ds['type'][$t]['size'] = (float)$mm[1];
            }
            if (preg_match('/--e-global-typography-' . $k . '-font-weight\s*:\s*(\d+)/i', $css, $mm)) {
                $ds['type'][$t]['weight'] = (int)$mm[1];
            }
        }
        // Mirrored Elementor sites already style their elements; don't double-apply.
        $ds['apply_elements'] = false;
        return $ds;
    }
}
