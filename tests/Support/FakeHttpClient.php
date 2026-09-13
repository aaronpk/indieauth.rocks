<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Support;

use Closure;
use IndieAuthRocks\Fetch\FetchException;
use IndieAuthRocks\Fetch\FetchResult;
use IndieAuthRocks\Fetch\HttpClient;
use IndieAuthRocks\Fetch\HttpRequest;

/**
 * Canned or computed HTTP responses, so tests never touch the network.
 * Unknown URLs fail like an unreachable host.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var array<string, FetchResult|FetchException> Keyed by "METHOD url". */
    private array $routes = [];

    /** @var list<array{0: string, 1: Closure}> Prefix handlers, checked in order. */
    private array $prefixes = [];

    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @param array<string, string|list<string>> $headers */
    public function on(string $url, int $status, string $body = '', array $headers = [], string $method = 'GET'): void
    {
        $this->routes[$method . ' ' . $url] = new FetchResult($url, $status, $body, self::headers($headers), method: $method);
    }

    public function fail(string $url, string $message = 'Connection refused', string $method = 'GET'): void
    {
        $this->routes[$method . ' ' . $url] = FetchException::transient($message);
    }

    /** Answer every request whose URL starts with $prefix by calling $handler(HttpRequest): FetchResult. */
    public function handle(string $prefix, Closure $handler): void
    {
        $this->prefixes[] = [$prefix, $handler];
    }

    /** @param array<string, mixed> $document */
    public function json(string $url, array $document, array $headers = []): void
    {
        $this->on($url, 200, (string) json_encode($document, JSON_UNESCAPED_SLASHES), $headers + ['Content-Type' => 'application/json']);
    }

    public function html(string $url, string $html, array $headers = []): void
    {
        $this->on($url, 200, $html, $headers + ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function send(HttpRequest $request): FetchResult
    {
        $this->requests[] = $request;

        $route = $this->routes[$request->method . ' ' . $request->url] ?? null;

        if ($route === null) {
            foreach ($this->prefixes as [$prefix, $handler]) {
                if (str_starts_with($request->url, $prefix)) {
                    $route = $handler($request);
                    break;
                }
            }
        }

        if ($route === null) {
            throw FetchException::transient(sprintf('No route to %s %s in the test.', $request->method, $request->url));
        }

        if ($route instanceof FetchException) {
            throw $route;
        }

        return new FetchResult($request->url, $route->status, $route->body, $route->headers, [], $request->method, $request->headers, $request->body);
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @return array<string, list<string>>
     */
    public static function headers(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = is_array($value) ? $value : [$value];
        }

        return $normalized;
    }

    /** @param array<string, string|list<string>> $headers */
    public static function response(int $status, string $body = '', array $headers = []): FetchResult
    {
        return new FetchResult('', $status, $body, self::headers($headers));
    }

    /** @param array<string, mixed> $data */
    public static function jsonResponse(array $data, int $status = 200, array $headers = []): FetchResult
    {
        return self::response($status, (string) json_encode($data, JSON_UNESCAPED_SLASHES), $headers + ['Content-Type' => 'application/json']);
    }
}
