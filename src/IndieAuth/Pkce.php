<?php

declare(strict_types=1);

namespace IndieAuthRocks\IndieAuth;

/**
 * PKCE, RFC 7636. S256 only.
 *
 * `plain` is deliberately not implemented. It offers no protection against an
 * attacker who can read the authorization request, which is the case PKCE
 * exists for. RFC 7636 §4.3 makes `plain` the default when
 * `code_challenge_method` is omitted, so an omitted method is rejected rather
 * than quietly upgraded — a client that meant `plain` should be told, not
 * silently reinterpreted.
 */
final class Pkce
{
    public const METHOD_S256 = 'S256';

    /**
     * A code_challenge is a base64url-encoded SHA-256 digest: 43 characters
     * with no padding.
     */
    public static function isValidChallenge(string $challenge): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) === 1;
    }

    /**
     * RFC 7636 §4.1: the verifier is 43 to 128 characters of unreserved
     * characters.
     */
    public static function isValidVerifier(string $verifier): bool
    {
        return preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) === 1;
    }

    public static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Whether a verifier matches a stored challenge.
     *
     * A malformed verifier fails rather than being normalised, and the
     * comparison is constant-time.
     */
    public static function verify(string $verifier, string $challenge): bool
    {
        if (!self::isValidVerifier($verifier) || !self::isValidChallenge($challenge)) {
            return false;
        }

        return hash_equals($challenge, self::challengeFor($verifier));
    }
}
