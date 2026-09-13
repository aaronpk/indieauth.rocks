<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\Fetch;

use IndieAuthRocks\Fetch\UrlResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UrlResolver::class)]
final class UrlResolverTest extends TestCase
{
    /** @return iterable<array{string, string}> RFC 3986 §5.4 normal examples that apply to http. */
    public static function examples(): iterable
    {
        yield ['g', 'http://a/b/c/g'];
        yield ['./g', 'http://a/b/c/g'];
        yield ['g/', 'http://a/b/c/g/'];
        yield ['/g', 'http://a/g'];
        yield ['//g', 'http://g/'];
        yield ['?y', 'http://a/b/c/d;p?y'];
        yield ['g?y', 'http://a/b/c/g?y'];
        yield ['#s', 'http://a/b/c/d;p?q#s'];
        yield ['g#s', 'http://a/b/c/g#s'];
        yield ['', 'http://a/b/c/d;p?q'];
        yield ['.', 'http://a/b/c/'];
        yield ['./', 'http://a/b/c/'];
        yield ['..', 'http://a/b/'];
        yield ['../', 'http://a/b/'];
        yield ['../g', 'http://a/b/g'];
        yield ['../..', 'http://a/'];
        yield ['../../g', 'http://a/g'];
        yield ['../../../g', 'http://a/g'];
        yield ['/./g', 'http://a/g'];
        yield ['g.', 'http://a/b/c/g.'];
        yield ['https://other.example/x', 'https://other.example/x'];
    }

    #[DataProvider('examples')]
    public function test_resolves_references(string $reference, string $expected): void
    {
        $this->assertSame($expected, UrlResolver::resolve('http://a/b/c/d;p?q', $reference));
    }

    public function test_refuses_non_http_schemes(): void
    {
        $this->assertNull(UrlResolver::resolve('https://example.com/', 'javascript:alert(1)'));
        $this->assertNull(UrlResolver::resolve('https://example.com/', 'mailto:me@example.com'));
        $this->assertNull(UrlResolver::resolve('not a url', '/path'));
    }
}
