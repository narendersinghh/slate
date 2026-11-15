<?php
declare(strict_types=1);

namespace Slate;

/**
 * Minimal CBOR (RFC 8949) decoder, enough for WebAuthn attestation objects and COSE keys:
 * unsigned/negative integers, byte and text strings, arrays, maps, tags (ignored), true/false/null.
 * Byte strings are returned as PHP binary strings. Indefinite lengths and floats are rejected.
 */
final class Cbor
{
    private const MAX_DEPTH = 16;

    /** Decode one item. Unless $allowTrailing, the whole input must be consumed. */
    public static function decode(string $data, bool $allowTrailing = false): mixed
    {
        $pos = 0;
        $value = self::item($data, $pos, 0);
        if (!$allowTrailing && $pos !== strlen($data)) {
            throw new \RuntimeException('Unexpected data after CBOR item.');
        }
        return $value;
    }

    private static function item(string $d, int &$p, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException('CBOR nested too deeply.');
        }
        $first = self::byte($d, $p);
        $major = $first >> 5;
        $info = $first & 0x1F;
        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22, 23 => null,
                default => throw new \RuntimeException('Unsupported CBOR simple/float value.'),
            };
        }
        $len = self::length($d, $p, $info);
        switch ($major) {
            case 0:
                return $len;
            case 1:
                return -1 - $len;
            case 2:
            case 3:
                if ($p + $len > strlen($d)) {
                    throw new \RuntimeException('CBOR string runs past the end.');
                }
                $s = substr($d, $p, $len);
                $p += $len;
                return $s;
            case 4:
                $out = [];
                for ($i = 0; $i < $len; $i++) {
                    $out[] = self::item($d, $p, $depth + 1);
                }
                return $out;
            case 5:
                $out = [];
                for ($i = 0; $i < $len; $i++) {
                    $k = self::item($d, $p, $depth + 1);
                    if (!is_int($k) && !is_string($k)) {
                        throw new \RuntimeException('Unsupported CBOR map key.');
                    }
                    $out[$k] = self::item($d, $p, $depth + 1);
                }
                return $out;
            case 6:
                return self::item($d, $p, $depth + 1); // tag: return the tagged value
        }
        throw new \RuntimeException('Bad CBOR.');
    }

    private static function byte(string $d, int &$p): int
    {
        if ($p >= strlen($d)) {
            throw new \RuntimeException('CBOR ended early.');
        }
        return ord($d[$p++]);
    }

    private static function length(string $d, int &$p, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $bytes = match ($info) {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            default => throw new \RuntimeException('Indefinite or reserved CBOR length.'),
        };
        if ($p + $bytes > strlen($d)) {
            throw new \RuntimeException('CBOR ended early.');
        }
        $n = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $n = ($n << 8) | ord($d[$p++]);
        }
        if ($n < 0 || $n > 64 * 1024 * 1024) {
            throw new \RuntimeException('CBOR length out of range.');
        }
        return $n;
    }
}
