<?php

declare(strict_types=1);

namespace IndieAuthRocks\Http;

/**
 * An immutable snapshot of an incoming HTTP request.
 *
 * Built from superglobals in production and from named arguments in tests, so
 * nothing below this layer ever touches $_GET/$_POST/$_SERVER directly.
 */
final class Request
{
    /**
     * @param array<string, string>              $query
     * @param array<string, string>              $post
     * @param array<string, string>              $headers Keys are lowercased.
     * @param array<string, string>              $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly string $body = '',
        public readonly bool $secure = false,
        public readonly string $host = 'localhost',
        public readonly string $ip = '127.0.0.1',
        public readonly ?string $queryString = null,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // Strip the query string; PATH_INFO is unreliable behind php -S.
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        // These two arrive without the HTTP_ prefix.
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $name) {
            if (isset($_SERVER[$server])) {
                $headers[$name] = (string) $_SERVER[$server];
            }
        }

        return new self(
            method:  $method,
            path:    self::normalizePath($path),
            query:   self::flatten($_GET),
            post:    self::flatten($_POST),
            headers: $headers,
            cookies: self::flatten($_COOKIE),
            body:    (string) file_get_contents('php://input'),
            secure:  self::detectHttps(),
            host:    (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            ip:      self::clientIp(),
            // Kept raw so repeated parameters can be detected. $_GET silently
            // keeps only the last value of a duplicated key.
            queryString: (string) ($_SERVER['QUERY_STRING'] ?? ''),
        );
    }

    /**
     * Parameters that appear more than once in the query string.
     *
     * RFC 6749 §3.1 requires request parameters to appear at most once. PHP
     * collapses duplicates, so a request carrying two `redirect_uri` values
     * would otherwise be silently resolved in favour of one of them — and an
     * attacker and a validator may not agree on which.
     *
     * @return list<string>
     */
    public function repeatedQueryParameters(): array
    {
        return self::repeated($this->queryString ?? '');
    }

    /** @return list<string> Parameters repeated in a form-encoded body. */
    public function repeatedBodyParameters(): array
    {
        return self::repeated($this->body);
    }

    /** @return list<string> */
    private static function repeated(string $encoded): array
    {
        if ($encoded === '') {
            return [];
        }

        $counts = [];
        foreach (explode('&', $encoded) as $pair) {
            if ($pair === '') {
                continue;
            }

            $name = urldecode(explode('=', $pair, 2)[0]);
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        return array_values(array_keys(array_filter($counts, static fn (int $n): bool => $n > 1)));
    }

    /** Collapse a trailing slash so /login/ and /login are the same route. */
    private static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * The client address. Behind a trusted reverse proxy (TRUST_PROXY=1) this is
     * the last address in X-Forwarded-For — the one the proxy itself saw —
     * otherwise every visitor would share the proxy's address and one noisy
     * client could exhaust every rate limit.
     */
    public static function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        if (!in_array(getenv('TRUST_PROXY'), ['1', 'true'], true)) {
            return $remote;
        }

        $forwarded = array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));

        foreach ($forwarded as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return $remote;
    }

    private static function detectHttps(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        // Only honoured when explicitly opted in, so a spoofed header on a
        // direct connection cannot flip cookies to Secure and break login.
        if (in_array(getenv('TRUST_PROXY'), ['1', 'true'], true)) {
            return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        }

        return false;
    }

    /**
     * Keep only scalar top-level values. Array parameters are not used
     * anywhere in OAuth and accepting them invites type-juggling bugs.
     *
     * @param  array<mixed>          $input
     * @return array<string, string>
     */
    private static function flatten(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }

    public function query(string $name, ?string $default = null): ?string
    {
        return $this->query[$name] ?? $default;
    }

    public function post(string $name, ?string $default = null): ?string
    {
        return $this->post[$name] ?? $default;
    }

    /** POST body first, then query string. */
    public function input(string $name, ?string $default = null): ?string
    {
        return $this->post[$name] ?? $this->query[$name] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /**
     * The bearer token from the Authorization header only.
     *
     * RFC 6750 also allows form and query transmission; both are deliberately
     * unsupported. Query-string tokens leak into logs and Referer headers.
     */
    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');
        if ($header === null || !preg_match('/^Bearer[ ]+([\x21-\x7e]+)$/', $header, $m)) {
            return null;
        }

        return $m[1];
    }

    /** @return array{0: string, 1: string}|null [client_id, client_secret] */
    public function basicAuth(): ?array
    {
        $header = $this->header('authorization');
        if ($header === null || !preg_match('/^Basic[ ]+([A-Za-z0-9+\/=]+)$/', $header, $m)) {
            return null;
        }

        $decoded = base64_decode($m[1], true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        [$user, $secret] = explode(':', $decoded, 2);

        // RFC 6749 §2.3.1 requires form-urlencoding the credentials first.
        return [urldecode($user), urldecode($secret)];
    }

    public function isForm(): bool
    {
        $type = $this->header('content-type', '') ?? '';

        return str_starts_with($type, 'application/x-www-form-urlencoded');
    }

    public function wantsJson(): bool
    {
        return str_contains((string) $this->header('accept', ''), 'application/json');
    }
}
