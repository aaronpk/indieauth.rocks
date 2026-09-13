<?php

declare(strict_types=1);

namespace IndieAuthRocks\IndieAuth;

use IndieAuthRocks\Http\Url;

/**
 * Client identifier and redirect URI rules (IndieAuth §3.3).
 */
final class ClientId
{
    /** Why a client_id is invalid, or null if it is acceptable. */
    public static function problem(string $clientId): ?string
    {
        if (preg_match('#^https?://#i', $clientId) !== 1) {
            return 'it must be an http or https URL';
        }

        if (preg_match('/[\s\x00-\x1f\x7f]/', $clientId) === 1 || strlen($clientId) > 1000) {
            return 'it contains characters that are not allowed';
        }

        $parts = parse_url($clientId);

        if ($parts === false || !isset($parts['host'])) {
            return 'it could not be parsed as a URL';
        }

        if (isset($parts['fragment']) || str_contains($clientId, '#')) {
            return 'it must not contain a fragment';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'it must not contain a username or password';
        }

        foreach (explode('/', $parts['path'] ?? '/') as $segment) {
            if ($segment === '.' || $segment === '..') {
                return 'it must not contain . or .. path segments';
            }
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if (@inet_pton($host) !== false && !in_array($host, ['127.0.0.1', '::1'], true)) {
            return 'it must use a domain name, not an IP address (except 127.0.0.1 or [::1])';
        }

        return null;
    }

    /** Loopback clients are never fetched. */
    public static function isLoopback(string $url): bool
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        return in_array($host, ['127.0.0.1', '::1', 'localhost'], true) || str_ends_with($host, '.localhost');
    }

    /** Why a redirect_uri is invalid, or null. */
    public static function redirectUriProblem(string $redirectUri): ?string
    {
        if (preg_match('#^https?://#i', $redirectUri) !== 1) {
            return 'it must be an http or https URL';
        }

        if (preg_match('/[\s\x00-\x1f\x7f]/', $redirectUri) === 1 || strlen($redirectUri) > 2000) {
            return 'it contains characters that are not allowed';
        }

        $parts = parse_url($redirectUri);

        if ($parts === false || !isset($parts['host'])) {
            return 'it could not be parsed as a URL';
        }

        if (isset($parts['fragment']) || str_contains($redirectUri, '#')) {
            return 'it must not contain a fragment';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'it must not contain a username or password';
        }

        return null;
    }

    public static function sameOrigin(string $a, string $b): bool
    {
        $originA = Url::origin($a);

        return $originA !== null && $originA === Url::origin($b);
    }
}
