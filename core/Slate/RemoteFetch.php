<?php
declare(strict_types=1);

namespace Slate;

/**
 * Fetch files from other websites on behalf of a user (page import by link) without letting the request reach the
 * server's own network (SSRF):
 *   - http and https only, no user:password in the address;
 *   - the host name is resolved here and every address is checked: private, loopback, link-local, shared, reserved
 *     and multicast ranges are refused (unless SLATE_CONFIG['import_allow_private'] is true, for tests);
 *   - cURL connects to the checked address (CURLOPT_RESOLVE), so DNS cannot change between check and connect;
 *   - redirects are followed by hand (max 5) and every hop is checked again;
 *   - no proxy, short timeouts, a size limit per response.
 * Nothing fetched is ever executed: callers store the bytes as files.
 */
final class RemoteFetch
{
    public const MAX_REDIRECTS = 5;

    /** A clear User-Agent so site owners can see what fetched their page. */
    public static function userAgent(): string
    {
        return 'Mozilla/5.0 (compatible; Slate-Importer/' . SLATE_VERSION . '; page import requested by a site owner)';
    }

    public static function allowPrivate(): bool
    {
        return defined('SLATE_CONFIG') && !empty(SLATE_CONFIG['import_allow_private']);
    }

    /**
     * GET a URL. Returns ['ok', 'status', 'body', 'type' (content type, lower case, no parameters), 'url' (final URL
     * after redirects), 'error'].
     */
    public static function get(string $url, int $maxBytes = 15 * 1024 * 1024, int $timeout = 20): array
    {
        $fail = static fn(string $error, int $status = 0) => ['ok' => false, 'status' => $status, 'body' => '', 'type' => '', 'url' => $url, 'error' => $error];
        if (!function_exists('curl_init')) {
            return $fail('The PHP cURL extension is required to import from a link.');
        }
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$target, $error] = self::check($url);
            if ($target === null) {
                return $fail((string)$error);
            }
            $body = '';
            $headers = [];
            $tooBig = false;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPGET => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $target['ip']],
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXFILESIZE => $maxBytes,
                CURLOPT_HTTPHEADER => ['User-Agent: ' . self::userAgent(), 'Accept: */*'],
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooBig, $maxBytes): int {
                    if (strlen($body) + strlen($chunk) > $maxBytes) {
                        $tooBig = true;
                        return 0; // abort the transfer
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($ch);
            $errMsg = curl_error($ch);
            unset($ch);
            if ($tooBig || $errno === CURLE_FILESIZE_EXCEEDED) {
                return $fail('Larger than ' . round($maxBytes / 1048576) . ' MB', $status);
            }
            if ($errno) {
                return $fail($errno === CURLE_OPERATION_TIMEDOUT ? 'Timed out' : 'Could not connect (' . $errMsg . ')', $status);
            }
            if ($status >= 300 && $status < 400 && isset($headers['location'])) {
                $url = self::absolute(trim($headers['location']), $url);
                if ($url === null) {
                    return $fail('Redirected to an address that is not allowed', $status);
                }
                continue;
            }
            if ($status < 200 || $status >= 300) {
                return $fail('The server answered ' . $status, $status);
            }
            $type = strtolower(trim(explode(';', (string)($headers['content-type'] ?? ''))[0]));
            return ['ok' => true, 'status' => $status, 'body' => $body, 'type' => $type, 'url' => $url, 'error' => null];
        }
        return $fail('Too many redirects');
    }

    /**
     * Is this address allowed? Returns [['host', 'port', 'ip'], null] or [null, reason].
     */
    public static function check(string $url): array
    {
        $p = parse_url($url);
        $scheme = strtolower((string)($p['scheme'] ?? ''));
        $host = strtolower((string)($p['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return [null, 'Only http:// and https:// addresses can be imported'];
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return [null, 'Addresses with a user name or password are not allowed'];
        }
        $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            return [null, 'Invalid port'];
        }
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP)) {
            $ips = [$bare];
        } else {
            if (!preg_match('/^[a-z0-9_.-]+$/', $host)) {
                return [null, 'This address is not allowed'];
            }
            $ips = self::resolve($host);
            if (!$ips) {
                return [null, 'The site name could not be found (DNS)'];
            }
        }
        foreach ($ips as $ip) {
            if (!self::allowPrivate() && !self::publicIp($ip)) {
                return [null, 'The address points at a private or local network, which is not allowed'];
            }
        }
        $ip = $ips[0];
        return [['host' => $host, 'port' => $port, 'ip' => str_contains($ip, ':') ? '[' . $ip . ']' : $ip], null];
    }

    /** Every IPv4 and IPv6 address of a host name. */
    private static function resolve(string $host): array
    {
        $ips = @gethostbynamel($host) ?: [];
        $v6 = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($v6 as $r) {
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        return array_values(array_unique($ips));
    }

    /** True for addresses on the public internet only. */
    public static function publicIp(string $ip): bool
    {
        // IPv4 inside IPv6 (::ffff:10.0.0.1, ::10.0.0.1, 64:ff9b::10.0.0.1): check the IPv4 part.
        if (str_contains($ip, ':') && preg_match('/(\d{1,3}(?:\.\d{1,3}){3})$/', $ip, $m)) {
            return self::publicIp($m[1]);
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($ip);
            foreach ([['0.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['192.0.0.0', 24], ['192.0.2.0', 24],
                ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($n & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }
            return true;
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        $first = ord($bin[0]);
        // ::/128, ::1, fc00::/7 (unique local), fe80::/10 (link-local), ff00::/8 (multicast), 2001:db8::/32 (documentation)
        return !($bin === str_repeat("\0", 16) || $bin === str_repeat("\0", 15) . "\1" || ($first & 0xfe) === 0xfc
            || ($first === 0xfe && (ord($bin[1]) & 0xc0) === 0x80) || $first === 0xff || str_starts_with(bin2hex($bin), '20010db8'));
    }

    /** Resolve a (possibly relative) URL against a base URL. Returns null for non-http(s) results. */
    public static function absolute(string $ref, string $base): ?string
    {
        $ref = trim(html_entity_decode($ref, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($ref === '' || preg_match('#^(data|javascript|mailto|tel|about|blob):#i', $ref)) {
            return null;
        }
        $b = parse_url($base);
        if (!$b || empty($b['host'])) {
            return null;
        }
        $scheme = strtolower($b['scheme'] ?? 'http');
        $origin = $scheme . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($ref, '//')) {
            $ref = $scheme . ':' . $ref;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $ref)) {
            return preg_match('#^https?://#i', $ref) ? $ref : null;
        }
        if (str_starts_with($ref, '#')) {
            return $origin . ($b['path'] ?? '/') . (isset($b['query']) ? '?' . $b['query'] : '') . $ref;
        }
        $path = $b['path'] ?? '/';
        if (str_starts_with($ref, '?')) {
            return $origin . $path . $ref;
        }
        [$rpath, $rest] = array_pad(preg_split('/(?=[?#])/', $ref, 2), 2, '');
        $dir = str_starts_with($rpath, '/') ? '' : substr($path, 0, (int)strrpos($path, '/') + 1);
        $parts = [];
        foreach (explode('/', $dir . $rpath) as $i => $seg) {
            if ($seg === '..') {
                array_pop($parts);
            } elseif ($seg !== '.' && ($seg !== '' || $i === 0)) {
                $parts[] = $seg;
            }
        }
        $joined = implode('/', $parts);
        $joined = '/' . ltrim($joined, '/');
        if (preg_match('#(^|/)\.{0,2}$#', $rpath) && !str_ends_with($joined, '/')) {
            $joined .= '/';
        }
        return $origin . $joined . $rest;
    }
}
