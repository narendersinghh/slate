<?php
declare(strict_types=1);

namespace SlateModules\AutoCpt;

use Slate\Http;

/** Minimal WordPress REST API client (application-password auth, pagination, ?rest_route fallback). */
final class WpClient
{
    public string $site;
    private ?string $auth;
    private ?string $restBase = null;

    public function __construct(string $site, string $user = '', string $password = '')
    {
        $site = trim($site);
        if (!preg_match('#^https?://#i', $site)) {
            $site = 'https://' . $site;
        }
        $this->site = rtrim($site, '/');
        $this->auth = $user !== '' && $password !== '' ? base64_encode($user . ':' . preg_replace('/\s+/', '', $password)) : null;
    }

    public function authenticated(): bool
    {
        return $this->auth !== null;
    }

    private function headers(): array
    {
        $h = ['Accept: application/json'];
        if ($this->auth) {
            $h[] = 'Authorization: Basic ' . $this->auth;
        }
        return $h;
    }

    private function base(): string
    {
        if ($this->restBase === null) {
            $r = Http::request('GET', $this->site . '/wp-json/', $this->headers(), null, 25);
            $this->restBase = ($r['status'] === 200 && str_contains($r['body'], '"namespaces"')) ? $this->site . '/wp-json' : $this->site . '/?rest_route=';
        }
        return $this->restBase;
    }

    /** @return array{ok:bool, data:mixed, status:int, total:int, pages:int, error:?string} */
    public function get(string $route, array $query = []): array
    {
        $base = $this->base();
        $route = '/' . ltrim($route, '/');
        if (str_ends_with($base, '?rest_route=')) {
            $url = $base . $route . ($query ? '&' . http_build_query($query) : '');
        } else {
            $url = $base . $route . ($query ? '?' . http_build_query($query) : '');
        }
        $r = Http::request('GET', $url, $this->headers(), null, 60);
        $data = json_decode($r['body'], true);
        $ok = $r['status'] >= 200 && $r['status'] < 300 && $data !== null;
        $error = null;
        if (!$ok) {
            $error = $r['error'] ?: (is_array($data) && isset($data['message']) ? $data['message'] . ' (HTTP ' . $r['status'] . ')' : 'HTTP ' . $r['status']);
        }
        return ['ok' => $ok, 'data' => $data, 'status' => $r['status'], 'error' => $error,
            'total' => (int)($r['headers']['x-wp-total'] ?? 0), 'pages' => (int)($r['headers']['x-wp-totalpages'] ?? 0)];
    }

    /** Fetch a public page's HTML. */
    public function html(string $url): ?string
    {
        $r = Http::request('GET', $url, ['Accept: text/html', 'User-Agent: Mozilla/5.0 (compatible; Slate-Importer/1.0)'], null, 60);
        return $r['status'] === 200 ? $r['body'] : null;
    }

    /**
     * Fetch many public pages in parallel (curl_multi). Returns [url => html|null].
     * @param string[] $urls
     */
    /** HTTP status of each URL fetched by the last htmlMulti() call (0 = network error). */
    public array $codes = [];

    /** URLs whose body is wanted even when they answer 404 (e.g. to capture the 404 template). */
    public array $keep404 = [];

    public function htmlMulti(array $urls, int $concurrency = 8): array
    {
        $this->codes = [];
        $out = [];
        $queue = array_values(array_unique(array_filter($urls)));
        if (!$queue) {
            return $out;
        }
        $mh = curl_multi_init();
        $active = [];
        $add = function () use (&$queue, &$active, $mh): void {
            $url = array_shift($queue);
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 45,
                CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_ENCODING => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_HTTPHEADER => ['Accept: text/html', 'User-Agent: Mozilla/5.0 (compatible; Slate-Importer/1.0)']]);
            curl_multi_add_handle($mh, $ch);
            $active[spl_object_id($ch)] = [$ch, $url];
        };
        while ($queue && count($active) < $concurrency) {
            $add();
        }
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 1.0);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                [, $url] = $active[spl_object_id($ch)];
                $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $this->codes[$url] = $code;
                $out[$url] = $code === 200 || ($code === 404 && isset($this->keep404[$url])) ? (string)curl_multi_getcontent($ch) : null;
                curl_multi_remove_handle($mh, $ch);
                // (freed automatically since PHP 8)
                unset($active[spl_object_id($ch)]);
                if ($queue) {
                    $add();
                }
            }
        } while ($active);
        curl_multi_close($mh);
        return $out;
    }

    /** Download many files in parallel: [url => destPath]. Returns [url => bool]. */
    public function downloadMulti(array $map, int $concurrency = 8): array
    {
        $out = [];
        $queue = $map;
        $mh = curl_multi_init();
        $active = [];
        $add = function () use (&$queue, &$active, $mh): void {
            $url = array_key_first($queue);
            $dest = $queue[$url];
            unset($queue[$url]);
            $fh = @fopen($dest, 'wb');
            if (!$fh) {
                return;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0 (compatible; Slate-Importer/1.0)']]);
            curl_multi_add_handle($mh, $ch);
            $active[spl_object_id($ch)] = [$ch, $url, $fh, $dest];
        };
        while ($queue && count($active) < $concurrency) {
            $add();
        }
        while ($active) {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 1.0);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                [, $url, $fh, $dest] = $active[spl_object_id($ch)];
                fclose($fh);
                $ok = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 && filesize($dest) > 0;
                if (!$ok) {
                    @unlink($dest);
                }
                $out[$url] = $ok;
                curl_multi_remove_handle($mh, $ch);
                // (freed automatically since PHP 8)
                unset($active[spl_object_id($ch)]);
                if ($queue) {
                    $add();
                }
            }
        }
        curl_multi_close($mh);
        return $out;
    }

    /** Download a URL to a local file. */
    public function download(string $url, string $dest): bool
    {
        $r = Http::request('GET', $url, ['User-Agent: Mozilla/5.0 (compatible; Slate-Importer/1.0)'], null, 120, $dest);
        if ($r['status'] !== 200 || !is_file($dest) || filesize($dest) === 0) {
            @unlink($dest);
            return false;
        }
        return true;
    }

    public function absolute(string $url): string
    {
        if (str_starts_with($url, '//')) {
            return (parse_url($this->site, PHP_URL_SCHEME) ?: 'https') . ':' . $url;
        }
        if (str_starts_with($url, '/')) {
            return $this->site . $url;
        }
        return $url;
    }
}
