<?php

declare(strict_types=1);

namespace IndieAuthRocks\Fetch;

/**
 * Relative reference resolution, RFC 3986 §5.2, for http(s) base URLs.
 */
final class UrlResolver
{
    /** The absolute URL, or null if either input is unusable. */
    public static function resolve(string $base, string $reference): ?string
    {
        $reference = trim($reference);
        $b         = parse_url($base);

        if ($b === false || !isset($b['scheme'], $b['host'])) {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $reference) === 1) {
            $r = parse_url($reference);
            if ($r === false || !isset($r['scheme'])) {
                return null;
            }

            if (!in_array(strtolower($r['scheme']), ['http', 'https'], true) || !isset($r['host'])) {
                return null;
            }

            return self::build($r['scheme'], self::authority($r), self::removeDotSegments($r['path'] ?? ''), $r['query'] ?? null, $r['fragment'] ?? null);
        }

        // Network-path reference.
        if (str_starts_with($reference, '//')) {
            return self::resolve($b['scheme'] . ':' . $reference, $b['scheme'] . ':' . $reference);
        }

        $fragment = null;
        if (str_contains($reference, '#')) {
            [$reference, $fragment] = explode('#', $reference, 2);
        }

        $query = null;
        if (str_contains($reference, '?')) {
            [$reference, $query] = explode('?', $reference, 2);
        }

        $basePath = $b['path'] ?? '';

        if ($reference === '') {
            $path  = $basePath;
            $query ??= $b['query'] ?? null;
        } elseif (str_starts_with($reference, '/')) {
            $path = self::removeDotSegments($reference);
        } else {
            $merged = ($basePath === '' ? '/' : substr($basePath, 0, (int) strrpos($basePath, '/') + 1)) . $reference;
            $path   = self::removeDotSegments($merged);
        }

        return self::build($b['scheme'], self::authority($b), $path, $query, $fragment);
    }

    public static function removeDotSegments(string $path): string
    {
        $output = [];
        $input  = $path;

        while ($input !== '') {
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);
            } elseif (str_starts_with($input, './')) {
                $input = substr($input, 2);
            } elseif (str_starts_with($input, '/./')) {
                $input = substr($input, 2);
            } elseif ($input === '/.') {
                $input = '/';
            } elseif (str_starts_with($input, '/../')) {
                $input = substr($input, 3);
                array_pop($output);
            } elseif ($input === '/..') {
                $input = '/';
                array_pop($output);
            } elseif ($input === '.' || $input === '..') {
                $input = '';
            } else {
                $start   = str_starts_with($input, '/') ? 1 : 0;
                $next    = strpos($input, '/', $start);
                $segment = $next === false ? $input : substr($input, 0, $next);
                $output[] = $segment;
                $input   = $next === false ? '' : substr($input, $next);
            }
        }

        return implode('', $output);
    }

    /** @param array<string, mixed> $parts */
    private static function authority(array $parts): string
    {
        $authority = '';

        if (isset($parts['user'])) {
            $authority .= $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@';
        }

        $authority .= $parts['host'];

        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        return $authority;
    }

    private static function build(string $scheme, string $authority, string $path, ?string $query, ?string $fragment): string
    {
        if ($path === '') {
            $path = '/';
        }

        return strtolower($scheme) . '://' . $authority . $path
            . ($query !== null ? '?' . $query : '')
            . ($fragment !== null ? '#' . $fragment : '');
    }
}
