<?php

declare(strict_types=1);

namespace IndieAuthRocks\Fetch;

/** An outbound HTTP request made while testing someone's implementation. */
final class HttpRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly int $maxBytes = 1_000_000,
        public readonly int $timeout = 10,
    ) {
    }

    public static function get(string $url, string $accept = '*/*', array $headers = []): self
    {
        return new self('GET', $url, ['Accept' => $accept] + $headers);
    }

    /**
     * A form-encoded POST, as IndieAuth token requests are.
     *
     * @param array<string, string>|list<array{0: string, 1: string}> $params
     *        Name => value, or [name, value] pairs so a name can repeat.
     * @param array<string, string> $headers
     */
    public static function postForm(string $url, array $params, array $headers = []): self
    {
        return new self(
            'POST',
            $url,
            $headers + ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            self::encodeForm($params),
        );
    }

    /** @param array<string, string>|list<array{0: string, 1: string}> $params */
    public static function encodeForm(array $params): string
    {
        if (!array_is_list($params)) {
            return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        return implode('&', array_map(
            static fn (array $pair): string => rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]),
            $params,
        ));
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** @return array<string, string> The form body decoded, last value wins. */
    public function formParams(): array
    {
        parse_str((string) $this->body, $params);

        return array_map('strval', array_filter($params, 'is_string'));
    }
}
