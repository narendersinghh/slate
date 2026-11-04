<?php
declare(strict_types=1);

namespace SlateModules\Forms;

use Slate\Auth;
use Slate\DB;
use Slate\Hooks;
use Slate\Options;
use Slate\Posts;

/**
 * Forms: a "form" content type whose custom fields describe the form, a renderer for the
 * front end, a submission endpoint and the submissions store.
 */
final class Forms
{
    public const TYPE = 'form';
    public const ENDPOINT = '/_slate/form';
    public const FIELD_TYPES = [
        'text' => 'Text', 'email' => 'Email', 'tel' => 'Phone', 'url' => 'URL', 'number' => 'Number', 'textarea' => 'Paragraph',
        'select' => 'Dropdown', 'radio' => 'Radio buttons', 'checkbox' => 'Checkboxes', 'date' => 'Date', 'consent' => 'Consent checkbox',
        'hidden' => 'Hidden', 'html' => 'Text / HTML (no input)',
    ];
    public const STATUSES = ['new' => 'New', 'read' => 'Read', 'spam' => 'Spam'];

    private static bool $assetsPrinted = false;

    // ------------------------------------------------------------------ setup

    public static function install(): void
    {
        if ((int)Options::get('forms_db', 0) >= 1) {
            return;
        }
        $sqlite = DB::$driver === 'sqlite';
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $int = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
        $text = $sqlite ? 'TEXT' : 'LONGTEXT';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        DB::exec("CREATE TABLE IF NOT EXISTS {{form_submissions}} (
            id $pk,
            form_id $int NULL,
            form_title VARCHAR(191) NOT NULL DEFAULT '',
            page_url VARCHAR(700) NOT NULL DEFAULT '',
            page_title VARCHAR(255) NOT NULL DEFAULT '',
            data $text NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            user_agent VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            source VARCHAR(40) NOT NULL DEFAULT 'slate',
            created_at DATETIME NOT NULL
        )$tail");
        foreach (['CREATE INDEX idx_fs_form ON {{form_submissions}} (form_id, created_at)', 'CREATE INDEX idx_fs_status ON {{form_submissions}} (status, created_at)'] as $sql) {
            try {
                DB::exec($sql);
            } catch (\PDOException) {
            }
        }
        Options::set('forms_db', 1);
    }

    public static function postType(): array
    {
        return ['slug' => self::TYPE, 'singular' => 'Form', 'plural' => 'Forms', 'icon' => 'message', 'public' => false, 'has_archive' => false,
            'hierarchical' => false, 'show_in_menu' => true, 'rewrite' => '', 'menu_position' => 85, 'supports' => ['title'],
            'description' => 'Forms you can place on any page.', 'builtin' => true];
    }

    /** The form builder itself is a pair of field groups on the "form" type. */
    public static function fieldGroups(): array
    {
        $types = array_map(static fn($k, $l) => "$k : $l", array_keys(self::FIELD_TYPES), self::FIELD_TYPES);
        return [
            'slate_form_fields' => ['id' => 'slate_form_fields', 'title' => 'Form fields', 'position' => 'main', 'system' => true,
                'location' => ['post_types' => [self::TYPE], 'taxonomies' => []],
                'fields' => [[
                    'name' => 'form_fields', 'label' => 'Fields', 'type' => 'repeater', 'width' => '100', 'options' => [], 'post_type' => '',
                    'help' => 'One row per field, in order. "Choices" (one per line, optionally value : Label) are used by Dropdown, Radio and Checkboxes. Leave "Key" empty to build it from the label.',
                    'sub_fields' => [
                        ['name' => 'label', 'label' => 'Label', 'type' => 'text'],
                        ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => $types],
                        ['name' => 'name', 'label' => 'Key', 'type' => 'text'],
                        ['name' => 'placeholder', 'label' => 'Placeholder', 'type' => 'text'],
                        ['name' => 'required', 'label' => 'Required', 'type' => 'toggle'],
                        ['name' => 'width', 'label' => 'Width', 'type' => 'select', 'options' => ['100 : Full width', '50 : Half', '33 : One third']],
                        ['name' => 'options', 'label' => 'Choices (one per line, value : Label) / text', 'type' => 'textarea',
                            'show_if' => ['field' => 'type', 'in' => ['select', 'radio', 'checkbox', 'consent', 'html']]],
                        ['name' => 'help', 'label' => 'Help text', 'type' => 'text'],
                    ],
                ]],
            ],
            'slate_form_settings' => ['id' => 'slate_form_settings', 'title' => 'Form settings', 'position' => 'side', 'system' => true,
                'location' => ['post_types' => [self::TYPE], 'taxonomies' => []],
                'fields' => [
                    ['name' => 'submit_label', 'label' => 'Button text', 'type' => 'text', 'placeholder' => 'Send', 'width' => '100'],
                    ['name' => 'success_message', 'label' => 'Success message', 'type' => 'textarea', 'placeholder' => 'Thanks! Your message has been sent.', 'width' => '100'],
                    ['name' => 'error_message', 'label' => 'Error message', 'type' => 'textarea', 'placeholder' => 'Sorry, that didn’t go through. Please try again.', 'width' => '100'],
                    ['name' => 'note', 'label' => 'Small print under the button', 'type' => 'textarea', 'width' => '100',
                        'help' => 'Optional, e.g. a privacy note. Simple HTML links are allowed.'],
                    ['name' => 'redirect_url', 'label' => 'Redirect after sending (optional)', 'type' => 'url', 'placeholder' => '/thank-you/', 'width' => '100'],
                    ['name' => 'notify_to', 'label' => 'Email notifications to', 'type' => 'text', 'width' => '100',
                        'help' => 'Comma-separated. Empty = the admin email in Settings → General. Write "none" to turn emails off.'],
                    ['name' => 'email_subject', 'label' => 'Email subject', 'type' => 'text', 'placeholder' => 'New submission: {form}', 'width' => '100'],
                    ['name' => 'style', 'label' => 'Look', 'type' => 'select', 'width' => '100', 'options' => ['slate : Slate default styles', 'theme : Use the website’s own styles']],
                    ['name' => 'css_class', 'label' => 'Extra CSS classes', 'type' => 'text', 'width' => '100',
                        'help' => 'Place this form anywhere: the Form button in any editor, a Form custom field, {{form:this-slug}} in Templates, or [form slug="this-slug"].'],
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------ lookup

    public static function find(string|int $ref): ?array
    {
        $ref = trim((string)$ref);
        if ($ref === '') {
            return null;
        }
        $form = ctype_digit($ref) ? Posts::find((int)$ref) : Posts::findBySlug(self::TYPE, $ref);
        return $form && $form['type'] === self::TYPE && $form['status'] === 'publish' ? $form : null;
    }

    /** Normalised field list of a form. */
    public static function fields(array $form): array
    {
        $out = [];
        $used = [];
        foreach ((array)($form['fields']['form_fields'] ?? []) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = isset(self::FIELD_TYPES[$row['type'] ?? '']) ? (string)$row['type'] : 'text';
            $label = trim((string)($row['label'] ?? ''));
            $key = strtolower(trim((string)($row['name'] ?? '')));
            $key = preg_replace('/[^a-z0-9_-]+/', '_', $key !== '' ? $key : ($label !== '' ? $label : 'field_' . ($i + 1)));
            $key = trim((string)$key, '_') ?: 'field_' . ($i + 1);
            while (isset($used[$key])) {
                $key .= '_' . ($i + 1);
            }
            $used[$key] = true;
            $choices = [];
            if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
                foreach (preg_split('/\R/', (string)($row['options'] ?? '')) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    [$v, $l] = str_contains($line, ':') ? array_map('trim', explode(':', $line, 2)) : [$line, $line];
                    $choices[$v] = $l;
                }
            }
            $out[] = ['key' => $key, 'label' => $label, 'type' => $type, 'placeholder' => (string)($row['placeholder'] ?? ''),
                'required' => !empty($row['required']), 'width' => in_array((string)($row['width'] ?? ''), ['50', '33'], true) ? (string)$row['width'] : '100',
                'choices' => $choices, 'text' => (string)($row['options'] ?? ''), 'help' => (string)($row['help'] ?? '')];
        }
        return $out;
    }

    // ------------------------------------------------------------------ rendering

    /** CSS class map; a theme can swap these for its own (filter "form_classes"). */
    private static function classes(array $form): array
    {
        $base = ['form' => 'slate-form', 'field' => 'slate-form__field', 'label' => 'slate-form__label', 'input' => 'slate-form__input',
            'textarea' => 'slate-form__input slate-form__textarea', 'select' => 'slate-form__input slate-form__select', 'choice' => 'slate-form__choice',
            'help' => 'slate-form__help', 'button' => 'slate-form__submit', 'success' => 'slate-form__message slate-form__success',
            'error' => 'slate-form__message slate-form__error', 'html' => 'slate-form__text', 'note' => 'slate-form__note'];
        $base = Hooks::filter('form_classes', $base, $form);
        // Per-form overrides (e.g. kept from a migrated design): fields._classes = {success: "…", …}
        foreach ((array)($form['fields']['_classes'] ?? []) as $k => $v) {
            if (isset($base[$k]) && is_string($v)) {
                $base[$k] = trim(($k === 'success' || $k === 'error' ? 'slate-form__message slate-form__' . $k . ' ' : '') . $v);
            }
        }
        return $base;
    }

    public static function render(string|int $ref): string
    {
        $form = self::find($ref);
        if (!$form) {
            return Auth::can('edit_posts') ? '<p class="slate-form-missing">[Form “' . e((string)$ref) . '” not found or not published]</p>' : '';
        }
        $f = $form['fields'];
        $c = self::classes($form);
        $style = ($f['style'] ?? '') === 'theme' ? 'theme' : 'slate';
        $uid = 'sf' . $form['id'] . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
        $h = '<form id="' . $uid . '" class="' . e(trim($c['form'] . ' slate-form--' . $style . ' slate-form--' . $form['slug'] . ' ' . ($f['css_class'] ?? '')))
            . '" method="post" action="' . e(url(ltrim(self::ENDPOINT, '/'))) . '" data-slate-form="' . (int)$form['id'] . '" aria-label="' . e($form['title']) . '"'
            . (!empty($f['redirect_url']) ? ' data-redirect="' . e((string)$f['redirect_url']) . '"' : '') . '>';
        // Theme look: label + control are direct children of the form (as in the original design, whose CSS
        // may position them with :nth-child), so no wrappers and the technical inputs go last.
        $flat = $style === 'theme';
        $tail = '';
        foreach (self::fields($form) as $fd) {
            $id = $uid . '-' . $fd['key'];
            $name = 'fields[' . $fd['key'] . ']';
            $req = $fd['required'] ? ' required' : '';
            $ph = $fd['placeholder'] !== '' ? ' placeholder="' . e($fd['placeholder']) . '"' : '';
            $label = e($fd['label']) . ($fd['required'] && $style === 'slate' ? ' <span class="slate-form__req" aria-hidden="true">*</span>' : '');
            if ($fd['type'] === 'hidden') {
                $tail .= '<input type="hidden" name="' . e($name) . '" value="' . e($fd['placeholder']) . '">';
                continue;
            }
            if (!$flat) {
                $h .= '<div class="' . e($c['field'] . ' slate-form__field--' . $fd['type']) . '" style="--sf-w:' . $fd['width'] . '%">';
            }
            switch ($fd['type']) {
                case 'html':
                    $h .= '<div class="' . e($c['html']) . '">' . \Slate\Sanitizer::html($fd['text'] !== '' ? $fd['text'] : $fd['label']) . '</div>';
                    break;
                case 'textarea':
                    $h .= '<label class="' . e($c['label']) . '" for="' . $id . '">' . $label . '</label><textarea class="' . e($c['textarea']) . '" id="' . $id . '" name="' . e($name) . '" rows="5"' . $ph . $req . '></textarea>';
                    break;
                case 'select':
                    $h .= '<label class="' . e($c['label']) . '" for="' . $id . '">' . $label . '</label><select class="' . e($c['select']) . '" id="' . $id . '" name="' . e($name) . '"' . $req . '>';
                    $h .= $fd['placeholder'] !== '' ? '<option value="">' . e($fd['placeholder']) . '</option>' : '';
                    foreach ($fd['choices'] as $v => $l) {
                        $h .= '<option value="' . e((string)$v) . '">' . e($l) . '</option>';
                    }
                    $h .= '</select>';
                    break;
                case 'radio':
                case 'checkbox':
                    $h .= '<fieldset class="slate-form__group"><legend class="' . e($c['label']) . '">' . $label . '</legend>';
                    foreach ($fd['choices'] as $v => $l) {
                        $h .= '<label class="' . e($c['choice']) . '"><input type="' . $fd['type'] . '" name="' . e($name) . ($fd['type'] === 'checkbox' ? '[]' : '') . '" value="' . e((string)$v) . '"'
                            . ($fd['type'] === 'radio' ? $req : '') . '> ' . e($l) . '</label>';
                    }
                    $h .= '</fieldset>';
                    break;
                case 'consent':
                    $h .= '<label class="' . e($c['choice']) . '"><input type="checkbox" name="' . e($name) . '" value="yes"' . $req . '> ' . ($fd['text'] !== '' ? \Slate\Sanitizer::html($fd['text']) : e($fd['label'])) . '</label>';
                    break;
                default:
                    $auto = ['email' => 'email', 'tel' => 'tel', 'url' => 'url'][$fd['type']] ?? (preg_match('/\bname\b/i', $fd['label']) ? 'name' : 'on');
                    $h .= '<label class="' . e($c['label']) . '" for="' . $id . '">' . $label . '</label><input class="' . e($c['input']) . '" type="' . e($fd['type']) . '" id="' . $id . '" name="' . e($name) . '" autocomplete="' . $auto . '"' . $ph . $req . '>';
            }
            if ($fd['help'] !== '' && $fd['type'] !== 'html') {
                $h .= '<small class="' . e($c['help']) . '">' . e($fd['help']) . '</small>';
            }
            if (!$flat) {
                $h .= '</div>';
            }
        }
        $h .= '<button class="' . e($c['button']) . '" type="submit">' . e(trim((string)($f['submit_label'] ?? '')) ?: 'Send') . '</button>';
        if (trim((string)($f['note'] ?? '')) !== '') {
            $h .= '<p class="' . e($c['note']) . '">' . \Slate\Sanitizer::html((string)$f['note']) . '</p>';
        }
        $msg = static fn(string $v, string $d) => \Slate\Sanitizer::html(nl2br(trim($v) !== '' ? $v : $d, false));
        $h .= '<div class="' . e($c['success']) . '" role="status" aria-live="polite" tabindex="-1" hidden>' . $msg((string)($f['success_message'] ?? ''), 'Thanks! Your message has been sent.') . '</div>';
        $h .= '<div class="' . e($c['error']) . '" role="alert" tabindex="-1" hidden>' . $msg((string)($f['error_message'] ?? ''), 'Sorry, that didn’t go through. Please try again.') . '</div>';
        $h .= $tail . '<input type="hidden" name="_form" value="' . (int)$form['id'] . '"><input type="hidden" name="_page" value=""><input type="hidden" name="_title" value=""><input type="hidden" name="_js" value="">'
            . '<div class="slate-form__hp" aria-hidden="true"><label>Leave this empty <input type="text" name="_hp" tabindex="-1" autocomplete="off"></label></div>';
        $h .= '</form>';
        return $h . self::assets();
    }

    /** Small stylesheet + script, printed once per page. */
    private static function assets(): string
    {
        if (self::$assetsPrinted) {
            return '';
        }
        self::$assetsPrinted = true;
        $dir = SLATE_MODULES . '/slate-forms/assets/';
        $v = substr(md5((string)@filemtime($dir . 'forms.js') . @filemtime($dir . 'forms.css')), 0, 8);
        return '<link rel="stylesheet" href="' . e(url('modules/slate-forms/assets/forms.css')) . '?v=' . $v . '">'
            . '<script src="' . e(url('modules/slate-forms/assets/forms.js')) . '?v=' . $v . '" defer></script>';
    }

    /** Replace form widgets / shortcodes in a page. */
    public static function replaceTokens(string $html): string
    {
        if (!str_contains($html, 'data-slate-form-widget') && !str_contains($html, '[form ')) {
            return $html;
        }
        // Editor widget: <div class="slate-form-widget" data-slate-form-widget="12" …>…</div>
        $html = (string)preg_replace_callback('#<(div|p)\b[^>]*\bdata-slate-form-widget="([\w-]+)"[^>]*>.*?</\1>#is', static fn($m) => self::render($m[2]), $html);
        // Shortcode: [form id="12"] or [form slug="contact"] (optionally wrapped in its own paragraph).
        return (string)preg_replace_callback('#(?:<p>\s*)?\[form\s+(?:id|slug)=["“”]?([\w-]+)["“”]?[^\]]*\](?:\s*</p>)?#i', static fn($m) => self::render($m[1]), $html);
    }

    // ------------------------------------------------------------------ submissions

    public static function handleSubmit(): never
    {
        $wantsJson = is_ajax() || !empty($_POST['_js']);
        $reply = static function (bool $ok, string $message, int $code = 200, ?string $redirect = null) use ($wantsJson): never {
            if ($wantsJson) {
                json_response(['ok' => $ok, 'message' => $message, 'redirect' => $redirect], $code);
            }
            if ($ok && $redirect) {
                redirect($redirect);
            }
            http_response_code($code);
            header('Content-Type: text/html; charset=utf-8');
            $back = (string)($_SERVER['HTTP_REFERER'] ?? url());
            echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
                . '<title>' . e(site_name()) . '</title><body style="font:16px/1.6 system-ui,sans-serif;max-width:560px;margin:15vh auto;padding:0 20px">'
                . '<p>' . nl2br(e($message)) . '</p><p><a href="' . e($back) . '">← Back</a></p></body>';
            exit;
        };
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $reply(false, 'Method not allowed.', 405);
        }
        self::install();
        $form = self::find((string)(int)($_POST['_form'] ?? 0));
        if (!$form) {
            $reply(false, 'This form is no longer available.', 404);
        }
        $f = $form['fields'];
        $success = trim((string)($f['success_message'] ?? '')) ?: 'Thanks! Your message has been sent.';
        $redirect = trim((string)($f['redirect_url'] ?? '')) ?: null;
        $ip = Auth::ip();

        // Spam protection: honeypot, rate limit, and a JavaScript marker.
        if (!empty($_POST['_hp'])) {
            $reply(true, $success, 200, $redirect); // pretend success
        }
        $recent = (int)DB::val('SELECT COUNT(*) FROM {{form_submissions}} WHERE ip = :ip AND created_at > :t', ['ip' => $ip, 't' => gmdate('Y-m-d H:i:s', time() - 3600)]);
        if ($recent >= 15) {
            $reply(false, 'Too many submissions from your connection. Please try again later.', 429);
        }

        $input = (array)($_POST['fields'] ?? []);
        $data = [];
        $errors = [];
        foreach (self::fields($form) as $fd) {
            if ($fd['type'] === 'html') {
                continue;
            }
            $raw = $input[$fd['key']] ?? null;
            if ($fd['type'] === 'checkbox') {
                $val = array_values(array_intersect(array_map('strval', (array)$raw), array_map('strval', array_keys($fd['choices']))));
            } else {
                $val = is_array($raw) ? '' : trim(str_replace("\0", '', (string)$raw));
                $val = mb_substr(strip_tags($val), 0, $fd['type'] === 'textarea' ? 10000 : 1000);
                if ($val !== '') {
                    if ($fd['type'] === 'email' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = ($fd['label'] ?: 'Email') . ' is not a valid email address.';
                    } elseif ($fd['type'] === 'url' && !preg_match('#^https?://#i', $val)) {
                        $val = 'https://' . $val;
                    } elseif ($fd['type'] === 'number' && !is_numeric($val)) {
                        $errors[] = ($fd['label'] ?: 'Number') . ' must be a number.';
                    } elseif (in_array($fd['type'], ['select', 'radio'], true) && !array_key_exists($val, $fd['choices'])) {
                        $val = '';
                    }
                }
            }
            if ($fd['required'] && ($val === '' || $val === [])) {
                $errors[] = ($fd['label'] ?: $fd['key']) . ' is required.';
            }
            $data[$fd['key']] = ['label' => $fd['label'] ?: $fd['key'], 'value' => $val, 'type' => $fd['type']];
        }
        if ($errors) {
            $reply(false, implode("\n", $errors), 422);
        }
        $page = mb_substr(\Slate\ContentModel::cleanUrl((string)($_POST['_page'] ?? ($_SERVER['HTTP_REFERER'] ?? ''))), 0, 700);
        $status = empty($_POST['_js']) ? 'spam' : 'new'; // bots rarely run JavaScript; keep them reviewable under Spam
        $id = self::store($form, $data, $page, mb_substr(strip_tags((string)($_POST['_title'] ?? '')), 0, 255), $status);
        if ($status === 'new') {
            self::notify($form, $data, $page, $id);
        }
        Hooks::action('form_submitted', $id, $form, $data);
        $reply(true, $success, 200, $redirect);
    }

    public static function store(?array $form, array $data, string $page, string $pageTitle, string $status = 'new', string $source = 'slate', ?string $createdAt = null, ?string $ip = null, ?string $ua = null, ?string $formTitle = null): int
    {
        self::install();
        return DB::insert('form_submissions', [
            'form_id' => $form['id'] ?? null,
            'form_title' => mb_substr($formTitle ?? (string)($form['title'] ?? 'Form'), 0, 191),
            'page_url' => mb_substr($page, 0, 700),
            'page_title' => mb_substr($pageTitle, 0, 255),
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => $ip ?? Auth::ip(),
            'user_agent' => mb_substr($ua ?? (string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'status' => isset(self::STATUSES[$status]) ? $status : 'new',
            'source' => $source,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    public static function notify(array $form, array $data, string $page, int $id): void
    {
        $f = $form['fields'];
        $to = trim((string)($f['notify_to'] ?? ''));
        if (strtolower($to) === 'none') {
            return;
        }
        $list = array_values(array_filter(array_map('trim', explode(',', $to !== '' ? $to : (string)Options::get('admin_email'))), static fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL)));
        if (!$list) {
            return;
        }
        $subject = str_replace(['{form}', '{site}'], [$form['title'], (string)Options::get('site_title')], trim((string)($f['email_subject'] ?? '')) ?: 'New submission: {form}');
        $body = "New submission from “{$form['title']}”" . ($page ? " on $page" : '') . "\n\n";
        $replyTo = '';
        foreach ($data as $row) {
            $v = is_array($row['value']) ? implode(', ', $row['value']) : (string)$row['value'];
            $body .= $row['label'] . ":\n" . ($v === '' ? '—' : $v) . "\n\n";
            if (!$replyTo && $row['type'] === 'email' && filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $replyTo = $v;
            }
        }
        $body .= "View all submissions: " . admin_url('m/submissions?id=' . $id) . "\n";
        $host = (string)parse_url(site_url(), PHP_URL_HOST);
        $headers = 'From: ' . preg_replace('/[^\w .-]/u', '', (string)Options::get('site_title')) . ' <noreply@' . $host . ">\r\nContent-Type: text/plain; charset=utf-8"
            . ($replyTo ? "\r\nReply-To: " . $replyTo : '');
        @mail(implode(',', $list), '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    }

    /** Field => value pairs for display, whatever shape the submission was stored in. */
    public static function rows(array $submission): array
    {
        $data = json_decode((string)$submission['data'], true) ?: [];
        $out = [];
        foreach ($data as $k => $v) {
            if (is_array($v) && array_key_exists('value', $v)) {
                $out[] = ['label' => (string)($v['label'] ?? $k), 'value' => is_array($v['value']) ? implode(', ', $v['value']) : (string)$v['value']];
            } else {
                $out[] = ['label' => ucfirst(str_replace(['_', '-'], ' ', (string)$k)), 'value' => is_array($v) ? implode(', ', $v) : (string)$v];
            }
        }
        return $out;
    }

    public static function newCount(): int
    {
        try {
            return (int)DB::val("SELECT COUNT(*) FROM {{form_submissions}} WHERE status = 'new'");
        } catch (\Throwable) {
            return 0;
        }
    }
}
