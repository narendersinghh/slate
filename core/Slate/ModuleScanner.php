<?php
declare(strict_types=1);

namespace Slate;

/**
 * Checks module code before it runs.
 *
 * - lint(): syntax check of every PHP file without executing anything (a broken file is refused).
 * - scan(): plain-language list of what the code is able to do (run shell commands, evaluate code, call other servers…),
 *   shown before installing or activating so the person deciding can judge it. It informs, it does not block:
 *   anyone may write and install modules.
 */
final class ModuleScanner
{
    private const MAX_FILES = 2000;
    private const MAX_BYTES = 2_000_000;

    /** Capability => [label, risk (high|medium|info), regex]. */
    private const RULES = [
        'shell' => ['Runs shell commands on the server', 'high',
            '/(?<![\w>:$])(?:shell_exec|exec|system|passthru|proc_open|popen|pcntl_exec)\s*\(/'],
        'eval' => ['Evaluates code built at runtime', 'high',
            '/\b(?:eval|assert|create_function)\s*\(|preg_replace\s*\(\s*([\'"]).*?\/e[a-z]*\1/i'],
        'obfuscation' => ['Contains encoded or obfuscated code', 'high',
            '/\b(?:gzinflate|gzuncompress|str_rot13)\s*\(\s*(?:base64_decode|str_rot13|gzinflate)|base64_decode\s*\(\s*[\'"][A-Za-z0-9+\/=]{200,}/'],
        'dynamic_include' => ['Includes files chosen at runtime', 'medium',
            '/\b(?:include|require)(?:_once)?\s*\(?\s*\$_(?:GET|POST|REQUEST|COOKIE)/'],
        'files' => ['Writes or deletes files', 'medium',
            '/(?<![\w>:$])(?:file_put_contents|fwrite|fputs|unlink|rmdir|rename|copy|move_uploaded_file|chmod|mkdir)\s*\(/'],
        'network' => ['Connects to other servers', 'info',
            '/\b(?:curl_init|fsockopen|stream_socket_client|Http::(?:request|json))\s*\(|file_get_contents\s*\(\s*[\'"]https?:/'],
        'database' => ['Runs its own database queries', 'info',
            '/\bDB::(?:exec|all|row|val|query|insert|update|delete)\s*\(/'],
        'tables' => ['Creates or changes database tables', 'info',
            '/\b(?:CREATE|ALTER|DROP)\s+TABLE\b/i'],
        'mail' => ['Sends email', 'info', '/(?<![\w>:$])mail\s*\(|\bMailer::send\s*\(/'],
        'superglobals' => ['Reads request data directly', 'info', '/\$_(?:GET|POST|REQUEST|COOKIE|FILES)\b/'],
        'options' => ['Changes site settings', 'info', '/\bOptions::(?:set|delete)\s*\(/'],
    ];

    /** @return string[] PHP files of a module folder (relative paths). */
    public static function phpFiles(string $dir): array
    {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (count($out) >= self::MAX_FILES) {
                break;
            }
            if ($f->isFile() && preg_match('/\.(php\d?|phtml|inc)$/i', $f->getFilename())) {
                $out[] = ltrim(substr($f->getPathname(), strlen($dir)), '/\\');
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Syntax-check every PHP file without running it.
     * @return string[] errors ("file.php line 12: unexpected '}'"), empty when all files parse.
     */
    public static function lint(string $dir): array
    {
        $errors = [];
        foreach (self::phpFiles($dir) as $rel) {
            $code = (string)@file_get_contents($dir . '/' . $rel, false, null, 0, self::MAX_BYTES);
            try {
                token_get_all($code, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $errors[] = "$rel line {$e->getLine()}: {$e->getMessage()}";
            } catch (\Throwable $e) {
                $errors[] = "$rel: {$e->getMessage()}";
            }
        }
        return $errors;
    }

    /**
     * What the module's code can do.
     * @return array{findings: array<string, array{label:string, risk:string, where:string[]}>, files:int, errors:string[]}
     */
    public static function scan(string $dir): array
    {
        $findings = [];
        $files = self::phpFiles($dir);
        foreach ($files as $rel) {
            $code = (string)@file_get_contents($dir . '/' . $rel, false, null, 0, self::MAX_BYTES);
            [$code, $backticks] = self::stripComments($code);
            if ($backticks) {
                $findings['shell'] ??= ['label' => self::RULES['shell'][0], 'risk' => 'high', 'where' => []];
                foreach (array_slice($backticks, 0, 3) as $ln) {
                    $findings['shell']['where'][] = "$rel:$ln";
                }
            }
            foreach (self::RULES as $key => [$label, $risk, $re]) {
                if (!preg_match_all($re, $code, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $findings[$key] ??= ['label' => $label, 'risk' => $risk, 'where' => []];
                foreach (array_slice($m[0], 0, 3) as [$hit, $offset]) {
                    if (count($findings[$key]['where']) < 6) {
                        $findings[$key]['where'][] = $rel . ':' . (substr_count($code, "\n", 0, $offset) + 1);
                    }
                }
            }
        }
        foreach ($findings as &$f) {
            $f['where'] = array_values(array_unique($f['where']));
        }
        unset($f);
        uasort($findings, static fn($a, $b) => self::rank($a['risk']) <=> self::rank($b['risk']));
        return ['findings' => $findings, 'files' => count($files), 'errors' => self::lint($dir)];
    }

    private static function rank(string $risk): int
    {
        return ['high' => 0, 'medium' => 1, 'info' => 2][$risk] ?? 3;
    }

    /**
     * Remove comments (keeping line numbers) so documentation does not trigger findings,
     * and find backtick shell operators (`ls`), which only exist as tokens outside strings.
     * @return array{0:string, 1:int[]}
     */
    private static function stripComments(string $code): array
    {
        try {
            $out = '';
            $line = 1;
            $backticks = [];
            $open = false;
            foreach (token_get_all($code) as $t) {
                $text = is_array($t) ? $t[1] : $t;
                if ($t === '`') {
                    if (!$open) {
                        $backticks[] = $line;
                    }
                    $open = !$open;
                }
                if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $out .= str_repeat("\n", substr_count($text, "\n"));
                } else {
                    $out .= $text;
                }
                $line += substr_count($text, "\n");
            }
            return [$out, $backticks];
        } catch (\Throwable) {
            return [$code, []];
        }
    }
}
