<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\Fetch;

use IndieAuthRocks\Fetch\FetchException;
use IndieAuthRocks\Fetch\HttpRequest;
use IndieAuthRocks\Fetch\SafeFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Refusals that happen before any DNS lookup or connection. */
#[CoversClass(SafeFetcher::class)]
final class SafeFetcherTest extends TestCase
{
    public function test_refuses_internationalised_hostnames_that_curl_would_resolve_itself(): void
    {
        $this->expectException(FetchException::class);
        $this->expectExceptionMessage('punycode');

        (new SafeFetcher())->send(HttpRequest::get('https://exämple.test/'));
    }

    public function test_refuses_ports_of_other_services(): void
    {
        $this->expectException(FetchException::class);
        $this->expectExceptionMessage('Port 25');

        (new SafeFetcher())->send(HttpRequest::get('http://example.test:25/'));
    }
}
