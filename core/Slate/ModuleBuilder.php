<?php
declare(strict_types=1);

namespace Slate;

/**
 * Creates new modules: a ready-to-edit blank module, or one written by the AI assistant from a plain-language description.
 * Generated code is never activated automatically: it is shown for review with the scanner's findings first.
 */
final class ModuleBuilder
{
    /** Files of a blank module. @return array<string,string> */
    public static function scaffold(string $slug, string $name, string $description = '', bool $adminPage = true): array
    {
        $ns = 'SlateModules\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $slug)));
        $manifest = [
            'name' => $name, 'slug' => $slug, 'description' => $description, 'version' => '1.0.0',
            'author' => (string)(Auth::user()['display_name'] ?? ''), 'requires' => SLATE_VERSION,
            'autoload' => [$ns . '\\' => 'src/'],
        ];
        if ($adminPage) {
            $manifest['settings'] = $slug;
        }
        $fn = str_replace('-', '_', $slug);
        $php = "<?php\n/**\n * $name\n * " . ($description ?: 'A Slate module.') . "\n * Docs: docs/modules.md\n */\ndeclare(strict_types=1);\n\n"
            . "use Slate\\Hooks;\nuse Slate\\Modules;\nuse Slate\\Options;\nuse Slate\\Router;\n\n"
            . "// Public URLs, e.g. /$slug\nHooks::add('routes', static function (Router \$r): void {\n"
            . "    // \$r->get('/$slug', static fn() => print 'Hello from $name');\n});\n\n"
            . "// Change content before it is shown\n// Hooks::add('the_content', static fn(string \$html, array \$post): string => \$html);\n";
        if ($adminPage) {
            $php .= "\nModules::adminPage(" . var_export($name, true) . ", '$slug', static function (): void {\n"
                . "    \$key = '{$fn}_settings';\n    \$s = (array)Options::get(\$key, []);\n"
                . "    if ((\$_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {\n"
                . "        \$s['message'] = trim((string)(\$_POST['message'] ?? ''));\n        Options::set(\$key, \$s);\n"
                . "        echo '<div class=\"banner banner-accent\"><div>Saved.</div></div>';\n    }\n"
                . "    echo '<form method=\"post\" class=\"card card-pad stack-sm\">' . csrf_field()\n"
                . "        . '<label class=\"field\"><span>Message</span><input class=\"input\" name=\"message\" value=\"' . e(\$s['message'] ?? '') . '\"></label>'\n"
                . "        . '<div><button class=\"btn btn-primary\">Save</button></div></form>';\n"
                . "}, 'manage_settings', 'puzzle');\n";
        }
        return [
            'module.json' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            'module.php' => $php,
            'src/.gitkeep' => '',
            'migrations/.gitkeep' => '',
        ];
    }

    /**
     * Ask the AI assistant to write a module.
     * @return array{ok:bool, error?:string, slug?:string, name?:string, files?:array<string,string>, notes?:string}
     */
    public static function generate(string $request): array
    {
        if (!Ai::enabled()) {
            return ['ok' => false, 'error' => 'Turn on the AI assistant under Settings › AI first.'];
        }
        $request = trim($request);
        if (mb_strlen($request) < 15) {
            return ['ok' => false, 'error' => 'Describe the module in a sentence or two.'];
        }
        $docs = (string)@file_get_contents(SLATE_ROOT . '/docs/modules.md');
        $existing = implode(', ', array_keys(Modules::all())) ?: 'none';
        $system = "You are an expert PHP 8.2 developer writing a module for Slate CMS (version " . SLATE_VERSION . ").\n"
            . "Follow this module documentation exactly; use only the APIs and hooks it lists:\n\n$docs\n\n"
            . "Rules: escape all output with e(); check Auth::can() in admin pages and call Slate\\Csrf::check() on every POST you handle "
            . "(put csrf_field() in forms); use prepared statements; prefix tables, options, functions and CSS classes with the slug; "
            . "no shell commands, no eval, no obfuscation, no external calls unless the request needs them; "
            . "create tables in migrations/001-*.php; keep it complete and working, no placeholders or TODOs. "
            . "Already installed modules (do not reuse these slugs): $existing.\n\n"
            . "Reply with ONLY a JSON object, no markdown fence: "
            . '{"slug":"lowercase-slug","name":"Name","notes":"what it does and how to use it, 2-5 sentences","files":{"module.json":"…","module.php":"…","migrations/001-tables.php":"…"}}';
        $r = Ai::complete("Write this Slate module:\n\n$request", $system, ['max_tokens' => 16000, 'temperature' => 0.2]);
        if (!$r['ok']) {
            return $r;
        }
        $text = trim((string)$r['text']);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
        if (!is_array($data) || empty($data['files']) || !is_array($data['files'])) {
            return ['ok' => false, 'error' => 'The AI did not return a module in the expected format. Try again, or make the request more specific.'];
        }
        $slug = slugify((string)($data['slug'] ?? ''));
        if (!Modules::validSlug($slug)) {
            return ['ok' => false, 'error' => 'The AI suggested an invalid module name. Try again.'];
        }
        $files = [];
        foreach ($data['files'] as $path => $content) {
            if (is_string($path) && is_string($content)) {
                $files[$path] = $content;
            }
        }
        // Make sure the manifest carries the slug and a sane Slate requirement.
        $manifest = json_decode($files['module.json'] ?? '', true);
        $manifest = is_array($manifest) ? $manifest : [];
        $manifest['slug'] = $slug;
        $manifest['name'] ??= (string)($data['name'] ?? $slug);
        $manifest['version'] ??= '1.0.0';
        if (empty($manifest['requires']) || version_compare((string)$manifest['requires'], SLATE_VERSION, '>')) {
            $manifest['requires'] = SLATE_VERSION;
        }
        $manifest['author'] ??= 'AI module builder';
        $files['module.json'] = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return ['ok' => true, 'slug' => $slug, 'name' => (string)$manifest['name'], 'files' => $files,
            'notes' => mb_substr((string)($data['notes'] ?? ''), 0, 2000)];
    }

    // ------------------------------------------------------------------ drafts awaiting review

    public static function saveDraft(array $draft): string
    {
        $id = bin2hex(random_bytes(8));
        $draft['created'] = time();
        Options::set('module_draft_' . $id, $draft, false);
        return $id;
    }

    public static function draft(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
            return null;
        }
        Options::lazy('module_draft_' . $id);
        $d = Options::get('module_draft_' . $id);
        return is_array($d) ? $d : null;
    }

    public static function dropDraft(string $id): void
    {
        if (preg_match('/^[a-f0-9]{16}$/', $id)) {
            Options::delete('module_draft_' . $id);
        }
    }

    /** Scan a draft's files without installing them. */
    public static function scanFiles(array $files): array
    {
        $dir = SLATE_CACHE . '/module-scan-' . bin2hex(random_bytes(6));
        try {
            foreach ($files as $path => $content) {
                $path = str_replace('\\', '/', (string)$path);
                if ($path === '' || str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path) || !preg_match('#^[\w./-]+$#', $path)) {
                    continue;
                }
                @mkdir(dirname("$dir/$path"), 0755, true);
                file_put_contents("$dir/$path", (string)$content);
            }
            return ModuleScanner::scan($dir);
        } finally {
            Updater::rrmdir($dir);
        }
    }
}
