<?php
declare(strict_types=1);

namespace Slate;

/**
 * Passkeys (WebAuthn): sign in with Face ID, Touch ID, Windows Hello, an Android phone or a security key, no password,
 * nothing to phish. Users add passkeys under Profile › Passkeys; the login page offers "Sign in with a passkey".
 *
 * Verification follows the WebAuthn Level 2 spec for the parts that matter for security: challenge, origin, RP ID hash,
 * user presence/verification flags, signature over authenticatorData‖SHA-256(clientDataJSON) and the signature counter.
 * Attestation is not required ("none"), as on most sites. Supported keys: ES256 (P-256) and RS256.
 */
final class Passkeys
{
    private const TIMEOUT = 120;

    public static function supported(): bool
    {
        // Browsers only allow passkeys on HTTPS (and on localhost for development).
        return function_exists('openssl_verify') && (is_https() || in_array(self::rpId(), ['localhost', '127.0.0.1'], true));
    }

    /**
     * RP ID = the domain passkeys belong to. With a private admin subdomain (cms.example.com) the passkeys are bound to
     * the parent site domain (example.com), so they keep working if the dashboard host changes and on the main domain.
     * Override with 'passkey_rp_id' in config.php.
     */
    public static function rpId(): string
    {
        $current = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')));
        $forced = defined('SLATE_CONFIG') ? strtolower(trim((string)(SLATE_CONFIG['passkey_rp_id'] ?? ''))) : '';
        if ($forced !== '' && ($current === $forced || str_ends_with($current, '.' . $forced))) {
            return $forced;
        }
        $site = preg_replace('/^www\./', '', strtolower((string)parse_url(site_url(), PHP_URL_HOST)));
        if ($site !== '' && $current !== $site && str_ends_with($current, '.' . $site)) {
            return $site;
        }
        return $current;
    }

    private static function origin(): string
    {
        return (is_https() ? 'https://' : 'http://') . strtolower((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }

    // ------------------------------------------------------------------ registration

    public static function registrationOptions(array $user): array
    {
        $challenge = random_bytes(32);
        Session::start();
        $_SESSION['webauthn_reg'] = ['c' => self::b64($challenge), 't' => time(), 'u' => (int)$user['id']];
        $exclude = array_map(static fn($r) => ['type' => 'public-key', 'id' => $r['credential_id']],
            DB::all('SELECT credential_id FROM {{passkeys}} WHERE user_id = :u', ['u' => $user['id']]));
        return [
            'challenge' => self::b64($challenge),
            'rp' => ['name' => (string)Options::get('site_title', 'Slate'), 'id' => self::rpId()],
            'user' => ['id' => self::b64(self::userHandle((int)$user['id'])), 'name' => (string)$user['username'],
                'displayName' => (string)($user['display_name'] ?: $user['username'])],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'timeout' => self::TIMEOUT * 1000,
            'attestation' => 'none',
            'excludeCredentials' => $exclude,
        ];
    }

    /** @return string|null error */
    public static function register(array $user, array $cred, string $name): ?string
    {
        Session::start();
        $pending = $_SESSION['webauthn_reg'] ?? null;
        unset($_SESSION['webauthn_reg']);
        if (!$pending || (int)$pending['u'] !== (int)$user['id'] || time() - (int)$pending['t'] > self::TIMEOUT) {
            return 'The request expired. Try again.';
        }
        try {
            $clientJson = self::unb64((string)($cred['response']['clientDataJSON'] ?? ''));
            $client = json_decode($clientJson, true);
            self::checkClient($client, 'webauthn.create', (string)$pending['c']);
            $att = Cbor::decode(self::unb64((string)($cred['response']['attestationObject'] ?? '')));
            if (!is_array($att) || !isset($att['authData'])) {
                throw new \RuntimeException('Malformed attestation.');
            }
            $auth = self::parseAuthData((string)$att['authData'], true);
            if (empty($auth['credentialId']) || empty($auth['publicKey'])) {
                throw new \RuntimeException('The authenticator did not return a credential.');
            }
            $pem = self::coseToPem($auth['publicKey']);
            $id = self::b64($auth['credentialId']);
            if ($id !== (string)($cred['id'] ?? $id)) {
                throw new \RuntimeException('Credential ID mismatch.');
            }
            if (DB::val('SELECT id FROM {{passkeys}} WHERE credential_id = :c', ['c' => $id])) {
                throw new \RuntimeException('This passkey is already registered.');
            }
        } catch (\Throwable $e) {
            return 'Passkey not added: ' . $e->getMessage();
        }
        $transports = implode(',', array_filter((array)($cred['response']['transports'] ?? []), static fn($t) => is_string($t) && preg_match('/^[a-z-]{2,20}$/', $t)));
        DB::insert('passkeys', ['user_id' => (int)$user['id'], 'credential_id' => $id, 'public_key' => $pem, 'sign_count' => (int)$auth['signCount'],
            'name' => mb_substr(trim($name) ?: 'Passkey', 0, 100), 'transports' => $transports, 'created_at' => now()]);
        log_activity('passkey_added', $user['username']);
        return null;
    }

    // ------------------------------------------------------------------ sign-in

    public static function loginOptions(): array
    {
        $challenge = random_bytes(32);
        Session::start();
        $_SESSION['webauthn_login'] = ['c' => self::b64($challenge), 't' => time()];
        return ['challenge' => self::b64($challenge), 'rpId' => self::rpId(), 'timeout' => self::TIMEOUT * 1000,
            'userVerification' => 'required', 'allowCredentials' => []];
    }

    /**
     * Verify an assertion and sign the user in.
     * @return array{ok:bool, error?:string}
     */
    public static function login(array $cred): array
    {
        if (Auth::isLockedOut()) {
            return ['ok' => false, 'error' => 'Too many failed attempts. Try again later.'];
        }
        Session::start();
        $pending = $_SESSION['webauthn_login'] ?? null;
        unset($_SESSION['webauthn_login']);
        $fail = static function (string $msg): array {
            DB::insert('login_attempts', ['ip' => Auth::ip(), 'username' => 'passkey', 'attempted_at' => now()]);
            return ['ok' => false, 'error' => $msg];
        };
        if (!$pending || time() - (int)$pending['t'] > self::TIMEOUT) {
            return ['ok' => false, 'error' => 'The request expired. Try again.'];
        }
        $id = (string)($cred['id'] ?? '');
        $row = $id !== '' ? DB::row('SELECT * FROM {{passkeys}} WHERE credential_id = :c', ['c' => $id]) : null;
        if (!$row) {
            return $fail('This passkey is not registered on this site.');
        }
        try {
            $clientJson = self::unb64((string)($cred['response']['clientDataJSON'] ?? ''));
            self::checkClient(json_decode($clientJson, true), 'webauthn.get', (string)$pending['c']);
            $authData = self::unb64((string)($cred['response']['authenticatorData'] ?? ''));
            $auth = self::parseAuthData($authData, false);
            $sig = self::unb64((string)($cred['response']['signature'] ?? ''));
            $ok = openssl_verify($authData . hash('sha256', $clientJson, true), $sig, $row['public_key'], OPENSSL_ALGO_SHA256);
            if ($ok !== 1) {
                throw new \RuntimeException('Signature check failed.');
            }
            // A counter that does not increase means the key may have been cloned.
            if ($auth['signCount'] > 0 || (int)$row['sign_count'] > 0) {
                if ($auth['signCount'] <= (int)$row['sign_count']) {
                    throw new \RuntimeException('The passkey counter went backwards; it may have been copied. Remove it and add it again.');
                }
            }
        } catch (\Throwable $e) {
            return $fail($e->getMessage());
        }
        $user = DB::row('SELECT * FROM {{users}} WHERE id = :id', ['id' => $row['user_id']]);
        if (!$user || $user['status'] !== 'active') {
            return $fail('This account is not active.');
        }
        DB::update('passkeys', ['sign_count' => (int)$auth['signCount'], 'last_used' => now()], ['id' => $row['id']]);
        Auth::loginUser($user);
        log_activity('passkey_login', $user['username'] . ' (' . $row['name'] . ')');
        return ['ok' => true];
    }

    // ------------------------------------------------------------------ management

    public static function forUser(int $userId): array
    {
        return DB::all('SELECT id, name, transports, last_used, created_at FROM {{passkeys}} WHERE user_id = :u ORDER BY id', ['u' => $userId]);
    }

    public static function count(int $userId): int
    {
        return (int)DB::val('SELECT COUNT(*) FROM {{passkeys}} WHERE user_id = :u', ['u' => $userId]);
    }

    public static function remove(int $id, int $userId): void
    {
        DB::query('DELETE FROM {{passkeys}} WHERE id = :id AND user_id = :u', ['id' => $id, 'u' => $userId]);
        log_activity('passkey_removed', (string)$id);
    }

    // ------------------------------------------------------------------ internals

    private static function userHandle(int $userId): string
    {
        // Opaque per-site handle (no username or database id leaks into the authenticator).
        return substr(Crypto::hmac('webauthn-user:' . $userId), 0, 32);
    }

    private static function checkClient(mixed $client, string $type, string $challenge): void
    {
        if (!is_array($client)) {
            throw new \RuntimeException('Malformed client data.');
        }
        if (($client['type'] ?? '') !== $type) {
            throw new \RuntimeException('Wrong request type.');
        }
        if (!hash_equals($challenge, (string)($client['challenge'] ?? ''))) {
            throw new \RuntimeException('Challenge mismatch.');
        }
        if (rtrim((string)($client['origin'] ?? ''), '/') !== self::origin()) {
            throw new \RuntimeException('Origin mismatch.');
        }
    }

    /** @return array{rpIdHash:string, flags:int, signCount:int, credentialId?:string, publicKey?:array} */
    private static function parseAuthData(string $d, bool $withCredential): array
    {
        if (strlen($d) < 37) {
            throw new \RuntimeException('Authenticator data too short.');
        }
        $out = ['rpIdHash' => substr($d, 0, 32), 'flags' => ord($d[32]), 'signCount' => unpack('N', substr($d, 33, 4))[1]];
        if (!hash_equals(hash('sha256', self::rpId(), true), $out['rpIdHash'])) {
            throw new \RuntimeException('This passkey belongs to another site.');
        }
        if (!($out['flags'] & 0x01)) {
            throw new \RuntimeException('User presence was not confirmed.');
        }
        // User verification (fingerprint, face, PIN) makes a passkey two factors in one: required.
        if (!($out['flags'] & 0x04)) {
            throw new \RuntimeException('The device did not verify you (fingerprint, face or PIN).');
        }
        if ($withCredential) {
            if (!($out['flags'] & 0x40)) {
                throw new \RuntimeException('No credential data.');
            }
            $len = unpack('n', substr($d, 53, 2))[1];
            $out['credentialId'] = substr($d, 55, $len);
            $out['publicKey'] = Cbor::decode(substr($d, 55 + $len), true);
        }
        return $out;
    }

    /** COSE_Key › PEM public key (EC2 P-256 / RSA). */
    public static function coseToPem(mixed $k): string
    {
        if (!is_array($k)) {
            throw new \RuntimeException('Bad public key.');
        }
        $kty = $k[1] ?? null;
        $alg = $k[3] ?? null;
        if ($kty === 2 && $alg === -7 && ($k[-1] ?? null) === 1) {
            $x = (string)$k[-2];
            $y = (string)$k[-3];
            if (strlen($x) !== 32 || strlen($y) !== 32) {
                throw new \RuntimeException('Bad EC key.');
            }
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        } elseif ($kty === 3 && $alg === -257) {
            $int = static fn(string $b): string => "\x02" . self::derLen(strlen($b = (ord($b[0]) & 0x80 ? "\x00" . $b : $b))) . $b;
            $rsa = "\x30" . self::derLen(strlen($seq = $int((string)$k[-1]) . $int((string)$k[-2]))) . $seq;
            $bit = "\x03" . self::derLen(strlen($rsa) + 1) . "\x00" . $rsa;
            $algId = hex2bin('300d06092a864886f70d0101010500');
            $der = "\x30" . self::derLen(strlen($algId . $bit)) . $algId . $bit;
        } else {
            throw new \RuntimeException('Unsupported key type (use a passkey with ES256 or RS256).');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        if (!openssl_pkey_get_public($pem)) {
            throw new \RuntimeException('The public key could not be read.');
        }
        return $pem;
    }

    private static function derLen(int $n): string
    {
        if ($n < 128) {
            return chr($n);
        }
        $b = ltrim(pack('N', $n), "\x00");
        return chr(0x80 | strlen($b)) . $b;
    }

    public static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string
    {
        $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if ($r === false) {
            throw new \RuntimeException('Bad encoding.');
        }
        return $r;
    }
}
