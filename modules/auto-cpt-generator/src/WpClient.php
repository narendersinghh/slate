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
