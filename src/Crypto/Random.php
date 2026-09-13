<?php

declare(strict_types=1);

namespace IndieAuthRocks\Crypto;

use InvalidArgumentException;

/**
 * Random value generation. Everything here draws from random_bytes().
 */
final class Random
{
    /** Crockford base32: no I, L, O or U, so identifiers cannot be misread. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * A URL-safe opaque token. 32 bytes gives 256 bits, which is the floor for
     * anything that acts as a bearer credential.
     */
    public static function token(int $bytes = 32): string
    {
        if ($bytes < 16) {
            throw new InvalidArgumentException('Tokens must be at least 16 bytes.');
        }

        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /**
     * A ULID: 48-bit millisecond timestamp then 80 bits of randomness, encoded
     * as 26 base32 characters.
     *
     * Lexicographic order matches creation order, so `ls data/users` comes out
     * chronological.
     */
    public static function ulid(?int $milliseconds = null): string
    {
        $ms = $milliseconds ?? (int) floor(microtime(true) * 1000);

        // 10 characters carry 50 bits; the top 2 are always zero.
        $bits = str_pad(decbin($ms), 50, '0', STR_PAD_LEFT);
        $bits .= self::bits(random_bytes(10));

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec($chunk)];
        }

        return $out;
    }

    /**
     * A lowercase Crockford base32 identifier, 5 bits per character. Used for
     * the public account id in metadata URLs: 16 characters is 80 bits.
     */
    public static function base32(int $length = 16): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return strtolower($out);
    }

    /** A prefixed identifier, e.g. usr_01K4ZQ8YB3... */
    public static function id(string $prefix): string
    {
        return $prefix . '_' . self::ulid();
    }

    /**
     * A temporary password for admin-driven recovery: readable over the phone,
     * still ~62 bits of entropy with the ambiguity-free alphabet.
     */
    public static function humanPassword(int $groups = 3, int $size = 4): string
    {
        $parts = [];
        for ($g = 0; $g < $groups; $g++) {
            $part = '';
            for ($i = 0; $i < $size; $i++) {
                $part .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $parts[] = $part;
        }

        return implode('-', $parts);
    }

    /** SHA-256, base64url encoded. Used to key tokens in Redis. */
    public static function fingerprint(string $value): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $value, true)), '+/', '-_'), '=');
    }

    private static function bits(string $bytes): string
    {
        $bits = '';
        /** @var list<int> $values */
        $values = array_values((array) unpack('C*', $bytes));
        foreach ($values as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        return $bits;
    }
}
