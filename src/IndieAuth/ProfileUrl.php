<?php

declare(strict_types=1);

namespace IndieAuthRocks\IndieAuth;

use InvalidArgumentException;

/**
 * User profile URL rules from the IndieAuth spec (§3.2, §3.4), plus the
 * narrower rule this server applies to the domains it manages: only the root
 * of a host, scheme://host/.
 */
final class ProfileUrl
{
    /**
     * Turn what someone typed into "add a domain" into a bare lowercase host.
     *
     * @throws InvalidArgumentException with a message safe to show.
     */
    public static function hostFromInput(string $input): string
    {
        $input = trim($input);

        if ($input === '') {
            throw new InvalidArgumentException('Enter a domain name, like example.com.');
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $input) !== 1) {
            $input = 'https://' . $input;
        }

        $parts = parse_url($input);

        if ($parts === false || !isset($parts['host'], $parts['scheme'])) {
            throw new InvalidArgumentException('That does not look like a domain name.');
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only http and https URLs can be used.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('The URL must not contain a username or password.');
        }

        if (isset($parts['port'])) {
            throw new InvalidArgumentException('Profile URLs cannot include a port number.');
        }

        if (($parts['path'] ?? '/') !== '/' && ($parts['path'] ?? '') !== '' || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException(
                'Only domain names are supported, such as example.com — not a page or path on a site.',
            );
        }

        $host = strtolower($parts['host']);

        $problem = self::hostProblem($host);
        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }

        return $host;
    }

    /**
     * Validate a `me` value per the spec and return its parts, or null if it is
     * not a valid profile URL. A missing path is treated as "/".
     *
     * @return array{scheme: string, host: string, path: string, query: ?string, url: string}|null
     */
    public static function parse(string $url): ?array
    {
        $url = trim($url);

        if (preg_match('#^https?://#i', $url) !== 1 || preg_match('/[\s\x00-\x1f\x7f]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || str_contains($url, '#')) {
            return null;
        }

        $host = strtolower($parts['host']);

        if (self::hostProblem($host) !== null) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $query  = $parts['query'] ?? null;

        return [
            'scheme' => $scheme,
            'host'   => $host,
            'path'   => $path,
            'query'  => $query,
            'url'    => $scheme . '://' . $host . $path . ($query !== null ? '?' . $query : ''),
        ];
    }

    /** Why a host cannot be a profile URL host, or null if it can. */
    public static function hostProblem(string $host): ?string
    {
        if ($host === '' || strlen($host) > 253) {
            return 'That is not a valid domain name.';
        }

        if (@inet_pton(trim($host, '[]')) !== false || preg_match('/^[0-9.]+$/', $host) === 1) {
            return 'Profile URLs must use a domain name, not an IP address.';
        }

        if (!str_contains($host, '.')) {
            return 'Enter a full domain name, like example.com.';
        }

        foreach (explode('.', rtrim($host, '.')) as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                return 'That is not a valid domain name. (Internationalized names must be entered in their xn-- form.)';
            }
        }

        if (str_ends_with($host, '.')) {
            return 'Leave off the trailing dot.';
        }

        return null;
    }

    /** The canonical root URL for a host. */
    public static function root(string $scheme, string $host): string
    {
        return strtolower($scheme) . '://' . strtolower($host) . '/';
    }
}
