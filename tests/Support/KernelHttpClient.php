<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Support;

use Closure;
use IndieAuthRocks\Fetch\FetchException;
use IndieAuthRocks\Fetch\FetchResult;
use IndieAuthRocks\Fetch\HttpClient;
use IndieAuthRocks\Fetch\HttpRequest;
use IndieAuthRocks\Http\Response;

/**
 * An HttpClient whose requests to this site are handled by the app
 * in-process, and whose other requests go to a fallback. Lets a fake client
 * talk to the test server as if over the network.
 */
final class KernelHttpClient implements HttpClient
{
    /**
     * @param Closure(string, string, array<string, string>, array<string, string>, string): Response $dispatch
     *        method, url, post fields, headers, raw body
     * @param list<string> $hosts Hosts (host:port) handled by the app.
     */
    public function __construct(
        private readonly Closure $dispatch,
        private readonly array $hosts,
        private readonly ?HttpClient $fallback = null,
    ) {
    }

    public function send(HttpRequest $request): FetchResult
    {
        $host = strtolower((string) parse_url($request->url, PHP_URL_HOST)) . (parse_url($request->url, PHP_URL_PORT) !== null ? ':' . parse_url($request->url, PHP_URL_PORT) : '');

        if (!in_array($host, $this->hosts, true)) {
            if ($this->fallback === null) {
                throw FetchException::transient('No route to ' . $request->url);
            }

            return $this->fallback->send($request);
        }

        $post = [];
        if (str_starts_with((string) $request->header('content-type'), 'application/x-www-form-urlencoded')) {
            parse_str((string) $request->body, $post);
        }

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[strtolower($name)] = $value;
        }

        $response = ($this->dispatch)($request->method, $request->url, array_map('strval', array_filter($post, 'is_string')), $headers, (string) $request->body);

        return new FetchResult($request->url, $response->status, $response->body, $response->headers(), [], $request->method, $request->headers, $request->body);
    }
}
