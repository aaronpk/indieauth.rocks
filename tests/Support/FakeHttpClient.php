<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Support;

use IndieAuthRocks\Fetch\FetchException;
use IndieAuthRocks\Fetch\FetchResult;
use IndieAuthRocks\Fetch\HttpClient;

/**
 * Canned HTTP responses, so tests exercise discovery and verification without
 * the network. Unknown URLs fail like an unreachable host.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var array<string, FetchResult|FetchException> */
    private array $routes = [];

    /** @var list<string> */
    public array $requests = [];

    /** @param array<string, string|list<string>> $headers */
    public function on(string $url, int $status, string $body = '', array $headers = []): void
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = is_array($value) ? $value : [$value];
        }

        $this->routes[$url] = new FetchResult($url, $status, $body, $normalized);
    }

    public function fail(string $url, string $message = 'Connection refused'): void
    {
        $this->routes[$url] = FetchException::transient($message);
    }

    /** A home page carrying an indieauth-metadata link. */
    public function homePage(string $url, string $metadataUrl): void
    {
        $this->on($url, 200, sprintf(
            '<!doctype html><html><head><title>Home</title><link rel="indieauth-metadata" href="%s"></head><body>Hi</body></html>',
            htmlspecialchars($metadataUrl),
        ), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<string, mixed> $document */
    public function json(string $url, array $document, array $headers = []): void
    {
        $this->on($url, 200, (string) json_encode($document, JSON_UNESCAPED_SLASHES), $headers + ['Content-Type' => 'application/json']);
    }

    public function get(string $url, string $accept, int $maxBytes): FetchResult
    {
        $this->requests[] = $url;

        $route = $this->routes[$url] ?? null;

        if ($route === null) {
            throw FetchException::transient(sprintf('No route to %s in the test.', $url));
        }

        if ($route instanceof FetchException) {
            throw $route;
        }

        return $route;
    }
}
