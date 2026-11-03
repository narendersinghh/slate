<?php
declare(strict_types=1);

namespace Slate;

/** Authenticated encryption (libsodium) for secrets stored in the DB, e.g. AI API keys, 2FA secrets. */
final class Crypto
{
    private static function key(): string
    {
        $secret = defined('SLATE_CONFIG') ? (SLATE_CONFIG['secret'] ?? '') : '';
        if (strlen($secret) < 32) {
            throw new \RuntimeException('Missing or weak "secret" in config.php');
        }
        return sodium_crypto_generichash($secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'enc:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(?string $cipher): string
    {
        if (!$cipher || !str_starts_with($cipher, 'enc:')) {
            return (string)$cipher;
        }
        $raw = base64_decode(substr($cipher, 4), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        return $plain === false ? '' : $plain;
    }
}
