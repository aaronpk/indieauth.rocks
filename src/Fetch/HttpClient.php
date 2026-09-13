<?php

declare(strict_types=1);

namespace IndieAuthRocks\Fetch;

/**
 * A single HTTP GET, without following redirects.
 *
 * SafeFetcher is the production implementation; tests substitute canned
 * responses so they never touch the network.
 */
interface HttpClient
{
    /** @throws FetchException when the request could not be made or was refused. */
    public function get(string $url, string $accept, int $maxBytes): FetchResult;
}
