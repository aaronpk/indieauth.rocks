<?php

declare(strict_types=1);

namespace IndieAuthRocks\Fetch;

/**
 * Follows redirects one hop at a time on top of an HttpClient, so each hop is
 * vetted afresh. Refuses to be downgraded from https to http.
 */
final class PageFetcher
{
    public function __construct(private readonly HttpClient $client)
    {
    }

    /** @throws FetchException */
    public function fetch(string $url, string $accept, int $maxBytes, int $maxRedirects = 5): FetchResult
    {
        $visited = [];

        for ($hop = 0; ; $hop++) {
            $result = $this->client->get($url, $accept, $maxBytes);

            if (!$result->isRedirect()) {
                return $result->withRedirects($visited);
            }

            if ($hop >= $maxRedirects) {
                throw FetchException::refused(sprintf('Gave up after %d redirects.', $maxRedirects));
            }

            $next = UrlResolver::resolve($url, (string) $result->header('location'));

            if ($next === null) {
                throw FetchException::refused(sprintf('%s redirected to an invalid URL.', $url));
            }

            if (str_starts_with($url, 'https://') && !str_starts_with(strtolower($next), 'https://')) {
                throw FetchException::refused(sprintf('%s redirected from https to %s, which is not secure.', $url, $next));
            }

            $visited[] = $url;
            $url       = $next;
        }
    }
}
