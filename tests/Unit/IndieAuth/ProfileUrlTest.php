<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\IndieAuth;

use InvalidArgumentException;
use IndieAuthRocks\IndieAuth\ProfileUrl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProfileUrl::class)]
final class ProfileUrlTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function acceptedDomains(): iterable
    {
        yield 'bare'            => ['example.com', 'example.com'];
        yield 'uppercase'       => ['Example.COM', 'example.com'];
        yield 'https with slash' => ['https://example.com/', 'example.com'];
        yield 'http no slash'   => ['http://example.com', 'example.com'];
        yield 'subdomain'       => ['  blog.example.co.uk ', 'blog.example.co.uk'];
        yield 'punycode'        => ['xn--bcher-kva.example', 'xn--bcher-kva.example'];
    }

    #[DataProvider('acceptedDomains')]
    public function test_accepts_domains(string $input, string $host): void
    {
        $this->assertSame($host, ProfileUrl::hostFromInput($input));
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedDomains(): iterable
    {
        yield 'empty'        => [''];
        yield 'ipv4'         => ['192.168.1.1'];
        yield 'ipv6'         => ['http://[::1]/'];
        yield 'no dot'       => ['localhost'];
        yield 'port'         => ['example.com:8080'];
        yield 'path'         => ['example.com/blog'];
        yield 'query'        => ['example.com/?a=b'];
        yield 'fragment'     => ['example.com/#me'];
        yield 'userinfo'     => ['https://user@example.com/'];
        yield 'ftp'          => ['ftp://example.com/'];
        yield 'bad label'    => ['-bad-.example.com'];
        yield 'unicode'      => ['bücher.example'];
    }

    #[DataProvider('rejectedDomains')]
    public function test_rejects_domains(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProfileUrl::hostFromInput($input);
    }

    public function test_parses_profile_urls_per_spec(): void
    {
        $this->assertSame('https://example.com/', ProfileUrl::parse('https://example.com')['url'] ?? null);
        $this->assertSame('https://example.com/username/', ProfileUrl::parse('https://EXAMPLE.com/username/')['url'] ?? null);
        $this->assertSame('/username/', ProfileUrl::parse('https://example.com/username/')['path'] ?? null);
        $this->assertSame('a=b', ProfileUrl::parse('https://example.com/?a=b')['query'] ?? null);

        $this->assertNull(ProfileUrl::parse('example.com'));
        $this->assertNull(ProfileUrl::parse('mailto:user@example.com'));
        $this->assertNull(ProfileUrl::parse('https://example.com/#me'));
        $this->assertNull(ProfileUrl::parse('https://example.com:443/'));
        $this->assertNull(ProfileUrl::parse('https://user:pass@example.com/'));
        $this->assertNull(ProfileUrl::parse('https://example.com/foo/../bar'));
        $this->assertNull(ProfileUrl::parse('https://example.com/./'));
        $this->assertNull(ProfileUrl::parse('https://127.0.0.1/'));
    }
}
