<?php

declare(strict_types=1);

namespace IndieAuthRocks\Config;

/**
 * Everything site-specific, read from the environment (usually .env).
 *
 * The app answers on up to three hostnames:
 *  - BASE_URL, the site itself, client_id host for server tests;
 *  - ALT_BASE_URL, a second origin used where a test needs "somewhere else";
 *  - FIXTURE_BASE_URL, development only, a profile page for automated runs.
 */
final class SiteConfig
{
    public readonly string $baseUrl;
    public readonly string $altBaseUrl;
    public readonly ?string $fixtureBaseUrl;

    public function __construct(
        public readonly string $siteName = 'IndieAuth Rocks',
        string $baseUrl = 'http://localhost:8095/',
        ?string $altBaseUrl = null,
        ?string $fixtureBaseUrl = null,
        public readonly string $dbHost = '127.0.0.1',
        public readonly int $dbPort = 3306,
        public readonly string $dbName = 'indieauthrocks',
        public readonly string $dbUser = 'indieauthrocks',
        public readonly string $dbPass = '',
        public readonly string $redisHost = '127.0.0.1',
        public readonly int $redisPort = 6379,
        public readonly int $redisDb = 0,
        public readonly string $redisPrefix = 'iar:',
        public readonly string $appSecret = '',
        public readonly bool $debug = false,
        public readonly bool $allowLoopbackFetch = false,
        public readonly bool $allowPrivateFetch = false,
        public readonly ?string $extraCaFile = null,
        public readonly int $sessionLifetime = 1_209_600,
        public readonly int $runRetentionDays = 30,
        public readonly int $checkupRetentionDays = 7,
    ) {
        $this->baseUrl        = self::normalizeBase($baseUrl, 'BASE_URL');
        $this->altBaseUrl     = self::normalizeBase($altBaseUrl ?? $baseUrl, 'ALT_BASE_URL');
        $this->fixtureBaseUrl = $fixtureBaseUrl === null ? null : self::normalizeBase($fixtureBaseUrl, 'FIXTURE_BASE_URL');
    }

    public static function fromEnvironment(): self
    {
        return new self(
            siteName:           Env::get('SITE_NAME', 'IndieAuth Rocks'),
            baseUrl:            Env::get('BASE_URL', 'http://localhost:8095/'),
            altBaseUrl:         Env::get('ALT_BASE_URL'),
            fixtureBaseUrl:     Env::get('FIXTURE_BASE_URL'),
            dbHost:             Env::get('DB_HOST', '127.0.0.1'),
            dbPort:             Env::int('DB_PORT', 3306),
            dbName:             Env::get('DB_NAME', 'indieauthrocks'),
            dbUser:             Env::get('DB_USER', 'indieauthrocks'),
            dbPass:             Env::get('DB_PASS', ''),
            redisHost:          Env::get('REDIS_HOST', '127.0.0.1'),
            redisPort:          Env::int('REDIS_PORT', 6379),
            redisDb:            Env::int('REDIS_DB', 0),
            redisPrefix:        Env::get('REDIS_PREFIX', 'iar:'),
            appSecret:          Env::get('APP_SECRET', ''),
            debug:              Env::flag('DEBUG'),
            allowLoopbackFetch: Env::flag('ALLOW_LOOPBACK_FETCH'),
            allowPrivateFetch:  Env::flag('ALLOW_PRIVATE_FETCH'),
            extraCaFile:        Env::get('EXTRA_CA_FILE'),
            runRetentionDays:   Env::int('RUN_RETENTION_DAYS', 30),
            checkupRetentionDays: Env::int('CHECKUP_RETENTION_DAYS', 7),
        );
    }

    public function url(string $path = ''): string
    {
        return $this->baseUrl . ltrim($path, '/');
    }

    public function altUrl(string $path = ''): string
    {
        return $this->altBaseUrl . ltrim($path, '/');
    }

    public function fixtureUrl(string $path = ''): ?string
    {
        return $this->fixtureBaseUrl === null ? null : $this->fixtureBaseUrl . ltrim($path, '/');
    }

    /** Whether the alternate host is a genuinely different origin. */
    public function hasDistinctAltOrigin(): bool
    {
        return self::authority($this->baseUrl) !== self::authority($this->altBaseUrl);
    }

    /**
     * Which of our hosts a request or URL is for: "main", "alt", "fixture" or
     * null. Accepts a Host header value or an absolute URL.
     */
    public function hostRole(string $hostOrUrl): ?string
    {
        $authority = str_contains($hostOrUrl, '://')
            ? self::authority($hostOrUrl)
            : self::normalizeAuthority($hostOrUrl, str_starts_with($this->baseUrl, 'https://') ? 'https' : 'http');

        return match (true) {
            $authority === self::authority($this->baseUrl)                                              => 'main',
            $authority === self::authority($this->altBaseUrl)                                           => 'alt',
            $this->fixtureBaseUrl !== null && $authority === self::authority($this->fixtureBaseUrl) => 'fixture',
            default                                                                                     => null,
        };
    }

    public function secureCookies(): bool
    {
        return str_starts_with($this->baseUrl, 'https://');
    }

    /**
     * The 32-byte key for sodium secretbox.
     *
     * @throws ConfigurationException
     */
    public function secretKey(): string
    {
        if (preg_match('/^[0-9a-f]{64}$/i', $this->appSecret) !== 1) {
            throw new ConfigurationException(
                'APP_SECRET must be 64 hex characters. Generate one with: openssl rand -hex 32',
            );
        }

        return (string) hex2bin($this->appSecret);
    }

    /** host[:port], lowercase, default ports omitted. */
    public static function authority(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            return '';
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $port   = $parts['port'] ?? null;

        return self::normalizeAuthority($parts['host'] . ($port !== null ? ':' . $port : ''), $scheme);
    }

    private static function normalizeAuthority(string $authority, string $scheme): string
    {
        $authority = strtolower(trim($authority));

        if (($scheme === 'https' && str_ends_with($authority, ':443')) || ($scheme === 'http' && str_ends_with($authority, ':80'))) {
            $authority = substr($authority, 0, strrpos($authority, ':'));
        }

        return $authority;
    }

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function normalizeBase(string $url, string $name): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigurationException(
                sprintf('%s must be an absolute http(s) URL with no query or fragment, e.g. https://example.com/', $name),
                sprintf('%s was %s', $name, var_export($url, true)),
            );
        }

        return rtrim($url, '/') . '/';
    }
}
