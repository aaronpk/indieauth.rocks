<?php

declare(strict_types=1);

namespace IndieAuthRocks\Http;

final class Url
{
    /**
     * Add query parameters to a URL, keeping any query it already has.
     *
     * @param array<string, string> $params
     */
    public static function appendQuery(string $url, array $params): string
    {
        $fragment = '';
        if (str_contains($url, '#')) {
            [$url, $fragment] = explode('#', $url, 2);
            $fragment = '#' . $fragment;
        }

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        if ($query === '') {
            return $url . $fragment;
        }

        $separator = str_contains($url, '?') ? (str_ends_with($url, '?') || str_ends_with($url, '&') ? '' : '&') : '?';

        return $url . $separator . $query . $fragment;
    }

    /** scheme://host[:port], with default ports omitted, or null. */
    public static function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port   = $parts['port'] ?? null;

        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        return $scheme . '://' . strtolower($parts['host']) . ($port !== null ? ':' . $port : '');
    }

    /** The host for display, e.g. "quill.p3k.io". */
    public static function displayHost(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }
}
