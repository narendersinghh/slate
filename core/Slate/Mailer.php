<?php
declare(strict_types=1);

namespace Slate;

/**
 * Outgoing email for core and modules: Mailer::send('a@b.com', 'Subject', '<p>HTML</p>').
 * Works with no setup: SMTP when a host is configured, otherwise the server's own mail() (Hostinger, cPanel and most
 * hosts provide it), a built-in equivalent of PHPMailer (TLS/STARTTLS, AUTH LOGIN/PLAIN). Settings → General → Email.
 * Modules can take over delivery entirely with the "mail_send" filter (return true when sent).
 */
final class Mailer
{
    private static ?string $lastError = null;

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * @param string|string[] $to
     * @param array{text?:string, reply_to?:string, from_name?:string, from_email?:string, cc?:array, bcc?:array, headers?:array} $opts
     */
    public static function send(string|array $to, string $subject, string $html, array $opts = []): bool
    {
        self::$lastError = null;
        $to = array_values(array_filter(array_map('trim', (array)$to), static fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
        if (!$to) {
            self::$lastError = 'No valid recipient.';
            return false;
        }
        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
        $msg = ['to' => $to, 'subject' => $subject, 'html' => $html, 'opts' => $opts];
        $handled = Hooks::filter('mail_send', null, $msg);
        if (is_bool($handled)) {
            return $handled;
        }
        [$fromEmail, $fromName] = self::from($opts);
        $text = $opts['text'] ?? self::toText($html);
        $boundary = 'b' . bin2hex(random_bytes(12));
        $headers = [
            'Date' => date('r'),
            'From' => self::address($fromEmail, $fromName),
            'Message-ID' => '<' . bin2hex(random_bytes(10)) . '@' . self::domain($fromEmail) . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => "multipart/alternative; boundary=\"$boundary\"",
            'X-Mailer' => 'Slate',
        ];
        if (!empty($opts['reply_to']) && filter_var($opts['reply_to'], FILTER_VALIDATE_EMAIL)) {
            $headers['Reply-To'] = $opts['reply_to'];
        }
        foreach (['cc' => 'Cc'] as $k => $h) {
            $list = array_filter((array)($opts[$k] ?? []), static fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL));
            if ($list) {
                $headers[$h] = implode(', ', $list);
            }
        }
        foreach ((array)($opts['headers'] ?? []) as $k => $v) {
            if (preg_match('/^[A-Za-z0-9-]+$/', (string)$k) && !preg_match('/[\r\n]/', (string)$v)) {
                $headers[$k] = (string)$v;
            }
        }
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text)) . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode(self::wrapHtml($html, $subject))) . "--$boundary--\r\n";
        $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $bcc = array_values(array_filter((array)($opts['bcc'] ?? []), static fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));

        $transport = (string)Options::get('mail_transport', 'auto');
        $useSmtp = $transport === 'smtp' || ($transport === 'auto' && trim((string)Options::get('smtp_host', '')) !== '');
        $ok = $useSmtp
            ? self::smtp($fromEmail, array_merge($to, (array)($opts['cc'] ?? []), $bcc), $encSubject, $headers + ['To' => implode(', ', $to)], $body)
            : self::phpMail($to, $encSubject, $headers + ($bcc ? ['Bcc' => implode(', ', $bcc)] : []), $body, $fromEmail);
        if (!$ok) {
            error_log('Slate mail failed: ' . self::$lastError);
        }
        Hooks::action('mail_sent', $msg, $ok);
        return $ok;
    }

    /** True when a "From" address on the site's domain has been set (Settings → General → Email). */
    public static function configured(): bool
    {
        return (bool)filter_var((string)Options::get('mail_from_email'), FILTER_VALIDATE_EMAIL);
    }

    private static function from(array $opts): array
    {
        // Never default to the admin's personal address (e.g. Gmail): sending "as" another provider fails SPF/DMARC.
        $email = (string)($opts['from_email'] ?? Options::get('mail_from_email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $host = preg_replace('/^www\./', '', (string)parse_url(site_url(), PHP_URL_HOST)) ?: 'localhost';
            $email = 'no-reply@' . $host;
        }
        $name = (string)($opts['from_name'] ?? Options::get('mail_from_name') ?: Options::get('site_title', 'Slate'));
        return [$email, $name];
    }

    private static function address(string $email, string $name): string
    {
        $name = trim(preg_replace('/[\r\n"]+/', ' ', $name));
        return $name === '' ? $email : '=?UTF-8?B?' . base64_encode($name) . "?= <$email>";
    }

    private static function domain(string $email): string
    {
        return substr(strrchr($email, '@') ?: '@localhost', 1);
    }

    public static function toText(string $html): string
    {
        $t = preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr)[^>]*>#i', "\n", $html);
        $t = preg_replace('#<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', (string)$t);
        $t = html_entity_decode(strip_tags((string)$t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $t)));
    }

    private static function wrapHtml(string $html, string $title): string
    {
        if (stripos($html, '<html') !== false) {
            return $html;
        }
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . e($title) . '</title></head>'
            . '<body style="margin:0;padding:24px;background:#f6f6f7;font:15px/1.6 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1b1b1f">'
            . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;padding:28px">' . $html . '</div></body></html>';
    }

    private static function phpMail(array $to, string $subject, array $headers, string $body, string $from): bool
    {
        if (!function_exists('mail')) {
            self::$lastError = 'PHP mail() is disabled on this server. Configure SMTP instead.';
            return false;
        }
        $h = '';
        foreach ($headers as $k => $v) {
            $h .= "$k: $v\r\n";
        }
        // Envelope sender only when an address was configured on purpose (some hosts reject unknown -f senders).
        $ok = Options::get('mail_from_email') && preg_match('/^[\w.+-]+@[\w.-]+$/', $from)
            ? @mail(implode(', ', $to), $subject, $body, rtrim($h), '-f' . $from)
            : @mail(implode(', ', $to), $subject, $body, rtrim($h));
        if (!$ok) {
            self::$lastError = 'The server refused to send (mail() returned false).';
        }
        return $ok;
    }

    // ------------------------------------------------------------------ SMTP

    private static function smtp(string $from, array $rcpt, string $subject, array $headers, string $body): bool
    {
        $host = trim((string)Options::get('smtp_host', ''));
        $port = (int)Options::get('smtp_port');
        $enc = (string)Options::get('smtp_encryption');
        $user = (string)Options::get('smtp_user', '');
        $pass = Crypto::decrypt((string)Options::get('smtp_pass', ''));
        if ($host === '') {
            self::$lastError = 'SMTP host is not set.';
            return false;
        }
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            self::$lastError = "Cannot connect to $host:$port ($errstr).";
            return false;
        }
        stream_set_timeout($fp, 20);
        try {
            self::expect($fp, [220]);
            $ehlo = 'EHLO ' . (preg_replace('/[^a-z0-9.-]/i', '', (string)parse_url(site_url(), PHP_URL_HOST)) ?: 'localhost');
            $caps = self::cmd($fp, $ehlo, [250]);
            if ($enc === 'tls') {
                self::cmd($fp, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new \RuntimeException('STARTTLS negotiation failed.');
                }
                $caps = self::cmd($fp, $ehlo, [250]);
            }
            if ($user !== '') {
                if (stripos($caps, 'AUTH') !== false && preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $caps) && !preg_match('/AUTH[ =][^\r\n]*LOGIN/i', $caps)) {
                    self::cmd($fp, 'AUTH PLAIN ' . base64_encode("\0$user\0$pass"), [235]);
                } else {
                    self::cmd($fp, 'AUTH LOGIN', [334]);
                    self::cmd($fp, base64_encode($user), [334], true);
                    self::cmd($fp, base64_encode($pass), [235], true);
                }
            }
            self::cmd($fp, "MAIL FROM:<$from>", [250]);
            foreach (array_unique($rcpt) as $r) {
                self::cmd($fp, "RCPT TO:<$r>", [250, 251]);
            }
            self::cmd($fp, 'DATA', [354]);
            $h = '';
            foreach ($headers + ['Subject' => $subject] as $k => $v) {
                $h .= "$k: $v\r\n";
            }
            // Dot-stuffing: lines starting with "." get an extra dot.
            $data = preg_replace('/^\./m', '..', $h . "\r\n" . $body);
            self::cmd($fp, rtrim((string)$data, "\r\n") . "\r\n.", [250]);
            @fwrite($fp, "QUIT\r\n");
            return true;
        } catch (\RuntimeException $e) {
            self::$lastError = $e->getMessage();
            return false;
        } finally {
            @fclose($fp);
        }
    }

    /** @param resource $fp */
    private static function cmd($fp, string $line, array $codes, bool $secret = false): string
    {
        fwrite($fp, $line . "\r\n");
        return self::expect($fp, $codes, $secret ? '[credentials]' : strtok($line, "\r\n"));
    }

    /** @param resource $fp */
    private static function expect($fp, array $codes, string $after = 'connect'): string
    {
        $resp = '';
        while (($l = fgets($fp, 1024)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') {
                break;
            }
        }
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $codes, true)) {
            $what = str_starts_with($after, 'AUTH') || $after === '[credentials]' ? 'login' : strtok($after, ' :');
            throw new \RuntimeException('SMTP ' . $what . ' failed: ' . trim($resp ?: 'no response (timeout)'));
        }
        return $resp;
    }
}
