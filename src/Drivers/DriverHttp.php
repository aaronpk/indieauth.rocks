<?php

declare(strict_types=1);

namespace IndieAuthRocks\Drivers;

use RuntimeException;

/**
 * A minimal browser for drivers: keeps cookies per host, never follows
 * redirects. Only ever pointed at a server the operator configured, so it has
 * none of SafeFetcher's address checks.
 */
final class DriverHttp
{
    /** @var array<string, array<string, string>> host => name => value */
    private array $cookies = [];

    public function __construct(private readonly ?string $caFile = null)
    {
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    public function request(string $method, string $url, ?string $body = null, array $headers = []): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        if (($this->cookies[$host] ?? []) !== []) {
            $lines[] = 'Cookie: ' . implode('; ', array_map(
                static fn (string $n, string $v): string => $n . '=' . $v,
                array_keys($this->cookies[$host]),
                $this->cookies[$host],
            ));
        }

        $responseHeaders = [];
        $handle          = curl_init($url);

        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_USERAGENT      => 'IndieAuth Rocks driver',
            CURLOPT_HEADERFUNCTION => static function ($h, string $line) use (&$responseHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))][] = trim($value);
                }

                return strlen($line);
            },
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        if ($this->caFile !== null) {
            $options[CURLOPT_CAINFO] = $this->caFile;
        }

        curl_setopt_array($handle, $options);

        $result = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($handle);
        curl_close($handle);

        if ($result === false) {
            throw new RuntimeException(sprintf('%s %s failed: %s', $method, $url, $error));
        }

        foreach ($responseHeaders['set-cookie'] ?? [] as $cookie) {
            [$pair] = explode(';', $cookie);
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if ($value === '' || stripos($cookie, 'max-age=0') !== false) {
                unset($this->cookies[$host][trim($name)]);
            } else {
                $this->cookies[$host][trim($name)] = $value;
            }
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $result];
    }

    /** @return array{status: int, headers: array<string, list<string>>, body: string} */
    public function get(string $url): array
    {
        return $this->request('GET', $url, null, ['Accept' => 'text/html,application/json;q=0.9']);
    }

    /**
     * @param array<string, string> $fields
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    public function postForm(string $url, array $fields): array
    {
        return $this->request('POST', $url, http_build_query($fields), ['Content-Type' => 'application/x-www-form-urlencoded']);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed> The decoded JSON response, plus _status.
     */
    public function postJson(string $url, array $data, string $csrf): array
    {
        $response = $this->request('POST', $url, (string) json_encode($data), [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'X-CSRF-Token' => $csrf,
        ]);

        return (json_decode($response['body'], true) ?: []) + ['_status' => $response['status']];
    }
}
