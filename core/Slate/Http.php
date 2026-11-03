<?php
declare(strict_types=1);

namespace Slate;

/** Small cURL client used by the AI integration and the updater. */
final class Http
{
    /**
     * @return array{status:int, body:string, error:?string, headers:array}
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 60, ?string $saveTo = null): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'The PHP cURL extension is required.', 'headers' => []];
        }
        $ch = curl_init($url);
        $respHeaders = [];
        $fh = null;
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HTTPHEADER => array_merge(['User-Agent: Slate-CMS/' . SLATE_VERSION], $headers),
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$respHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ];
        if ($saveTo) {
            $fh = fopen($saveTo, 'wb');
            $opts[CURLOPT_FILE] = $fh;
        } else {
            $opts[CURLOPT_RETURNTRANSFER] = true;
        }
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $result = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);
        if ($fh) {
            fclose($fh);
        }
        return ['status' => $status, 'body' => is_string($result) ? $result : '', 'error' => $error, 'headers' => $respHeaders];
    }

    public static function json(string $method, string $url, array $headers = [], ?array $payload = null, int $timeout = 60): array
    {
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $r = self::request($method, $url, $headers, $payload !== null ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, $timeout);
        $r['json'] = json_decode($r['body'], true);
        return $r;
    }
}
