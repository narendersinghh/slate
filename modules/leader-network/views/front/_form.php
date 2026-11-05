<?php
// Small shared helpers for views.
if (!function_exists('ln_field')) {
    function ln_field(string $label, string $input, string $help = ''): string
    {
        return '<label class="ln-field"><span class="ln-label">' . e($label) . '</span>' . $input . ($help ? '<small class="ln-help">' . e($help) . '</small>' : '') . '</label>';
    }
    function ln_errors(array $errors): string
    {
        return $errors ? '<div class="ln-alert ln-alert-error" role="alert"><ul>' . implode('', array_map(static fn($x) => '<li>' . e($x) . '</li>', $errors)) . '</ul></div>' : '';
    }
    function ln_thumb(?array $p, string $class = 'ln-avatar'): string
    {
        $src = $p && !empty($p['featured_media_id']) ? \Slate\Media::url((int)$p['featured_media_id'], 'thumb') : '';
        $initials = $p ? mb_strtoupper(implode('', array_map(static fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($p['title'])), 0, 2)))) : '?';
        return $src ? '<img class="' . e($class) . '" src="' . e($src) . '" alt="' . e($p['title']) . '" loading="lazy" width="96" height="96">'
            : '<span class="' . e($class) . ' ln-avatar-fallback" aria-hidden="true">' . e($initials) . '</span>';
    }
}
