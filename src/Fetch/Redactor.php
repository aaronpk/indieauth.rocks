<?php

declare(strict_types=1);

namespace IndieAuthRocks\Fetch;

/**
 * Removes secrets from transcripts before they are stored.
 *
 * A redacted value keeps its length and a short hash, so the person reading a
 * transcript can still tell that the code sent in one request is the same one
 * received in another.
 */
final class Redactor
{
    public const SECRET_KEYS = [
        'code', 'code_verifier', 'access_token', 'refresh_token', 'token',
        'client_secret', 'password', 'id_token', 'pin',
    ];

    private const SECRET_HEADERS = ['authorization', 'cookie', 'set-cookie', 'x-csrf-token'];

    public const MAX_REQUEST_BODY  = 16_000;
    public const MAX_RESPONSE_BODY = 64_000;

    public static function mask(string $value): string
    {
        return sprintf('[redacted len=%d sha256:%s]', strlen($value), substr(hash('sha256', $value), 0, 8));
    }

    public static function url(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (!is_string($query) || $query === '') {
            return $url;
        }

        $redacted = implode('&', array_map(static function (string $pair): string {
            if (!str_contains($pair, '=')) {
                return $pair;
            }

            [$name, $value] = explode('=', $pair, 2);

            return in_array(strtolower(urldecode($name)), self::SECRET_KEYS, true)
                ? $name . '=' . rawurlencode(self::mask(urldecode($value)))
                : $pair;
        }, explode('&', $query)));

        return str_replace('?' . $query, '?' . $redacted, $url);
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @return array<string, string|list<string>>
     */
    public static function headers(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $value) {
            if (!in_array(strtolower($name), self::SECRET_HEADERS, true)) {
                $out[$name] = $value;
                continue;
            }

            $out[$name] = is_array($value)
                ? array_map(self::maskHeader(...), $value)
                : self::maskHeader($value);
        }

        return $out;
    }

    public static function body(?string $body, string $contentType, int $limit): ?string
    {
        if ($body === null || $body === '') {
            return $body;
        }

        $type = strtolower(trim(explode(';', $contentType)[0]));

        if ($type === 'application/x-www-form-urlencoded') {
            $body = substr(self::url('?' . $body), 1);
        } elseif ($type === 'application/json' || str_ends_with($type, '+json')) {
            $decoded = json_decode($body, true, 32);

            if (is_array($decoded)) {
                $body = (string) json_encode(self::redactArray($decoded), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        if (strlen($body) > $limit) {
            $body = substr($body, 0, $limit) . "\n[truncated]";
        }

        return $body;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::redactArray($value);
            } elseif (is_string($key) && is_scalar($value) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                $data[$key] = self::mask((string) $value);
            }
        }

        return $data;
    }

    private static function maskHeader(string $value): string
    {
        // Keep the scheme of an Authorization header, which is often the point.
        if (preg_match('/^(Bearer|Basic)\s+(.+)$/i', $value, $m) === 1) {
            return $m[1] . ' ' . self::mask($m[2]);
        }

        return self::mask($value);
    }
}
