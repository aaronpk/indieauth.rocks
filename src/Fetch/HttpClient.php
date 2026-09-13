<?php

declare(strict_types=1);

namespace IndieAuthRocks\Fetch;

/**
 * Sends one HTTP request without following redirects.
 *
 * SafeFetcher is the production implementation; tests substitute fake
 * servers so they never touch the network.
 */
interface HttpClient
{
    /** @throws FetchException when the request could not be made or was refused. */
    public function send(HttpRequest $request): FetchResult;
}
