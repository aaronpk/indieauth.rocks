<?php

declare(strict_types=1);

namespace IndieAuthRocks\ServerTests;

use IndieAuthRocks\Config\SiteConfig;

/**
 * A browser arriving back at this site from the server under test: on the
 * registered callback, or somewhere on the other origin it should never have
 * been sent.
 */
final class Landing
{
    /** The registered callback on this site. */
    public const CALLBACK = 'callback';

    /** A never-registered URL on the other origin. */
    public const CATCH = 'catch';

    /** The registered cross-origin redirect URI. */
    public const CROSS = 'cross';

    /** A longer URL that merely starts with the registered cross-origin URI. */
    public const CROSS_SUFFIX = 'cross-suffix';

    /** @param array<string, string> $query Decoded without turning "+" into a space. */
    public function __construct(
        public readonly string $role,
        public readonly string $runKey,
        public readonly ?string $segment,
        public readonly array $query,
        public readonly string $url,
    ) {
    }

    public static function parse(SiteConfig $config, string $url): ?self
    {
        $host = $config->hostRole($url);
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $parts = $path === '' ? [] : explode('/', $path);

        $query = self::parseQuery((string) parse_url($url, PHP_URL_QUERY));

        if ($host === 'main' && count($parts) === 2 && $parts[0] === 'callback') {
            return new self(self::CALLBACK, $parts[1], null, $query, $url);
        }

        if ($host !== 'alt' || count($parts) < 2) {
            return null;
        }

        return match (true) {
            $parts[0] === 'catch' && count($parts) >= 3  => new self(self::CATCH, $parts[1], $parts[2], $query, $url),
            $parts[0] === 'cb' && count($parts) === 2    => new self(self::CROSS, $parts[1], null, $query, $url),
            $parts[0] === 'cb' && count($parts) >= 3     => new self(self::CROSS_SUFFIX, $parts[1], $parts[2], $query, $url),
            default                                      => null,
        };
    }

    /** @return array<string, string> First value wins; "+" stays literal. */
    public static function parseQuery(string $query): array
    {
        $params = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = rawurldecode($name);

            $params[$name] ??= rawurldecode($value);
        }

        return $params;
    }

    public function param(string $name): ?string
    {
        return $this->query[$name] ?? null;
    }

    /** The attempt id this site put at the start of the state value. */
    public function attemptId(): ?string
    {
        $state = $this->param('state');

        if ($state === null || !str_contains($state, '~')) {
            return null;
        }

        return strstr($state, '~', true) ?: null;
    }

    public function code(): ?string
    {
        $code = $this->param('code');

        return $code === null || $code === '' ? null : $code;
    }

    public function error(): ?string
    {
        return $this->param('error');
    }

    /** Whether the server sent the browser somewhere it should not have. */
    public function isUnregistered(): bool
    {
        return in_array($this->role, [self::CATCH, self::CROSS_SUFFIX], true);
    }
}
