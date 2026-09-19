<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\IndieAuth;

use IndieAuthRocks\IndieAuth\Discovery;
use IndieAuthRocks\IndieAuth\DiscoveryResult;
use IndieAuthRocks\IndieAuth\MeVerifier;
use IndieAuthRocks\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Discovery::class)]
#[CoversClass(MeVerifier::class)]
final class DiscoveryTest extends TestCase
{
    private const METADATA = [
        'issuer'                           => 'https://auth.example/',
        'authorization_endpoint'           => 'https://auth.example/auth',
        'token_endpoint'                   => 'https://auth.example/token',
        'code_challenge_methods_supported' => ['S256'],
    ];

    public function test_normalizes_what_people_type(): void
    {
        $this->assertSame('https://example.com/', Discovery::normalizeInput('example.com'));
        $this->assertSame('https://example.com/', Discovery::normalizeInput(' HTTPS://Example.COM '));
        $this->assertSame('http://example.com/~me', Discovery::normalizeInput('http://example.com/~me'));
    }

    public function test_prefers_the_link_header_and_resolves_relative_urls(): void
    {
        $http = new FakeHttpClient();
        $http->html('https://me.example/', '<link rel="indieauth-metadata" href="https://other.example/meta">', [
            'Link' => '</meta>; rel="indieauth-metadata"',
        ]);
        $http->json('https://me.example/meta', self::METADATA);

        $result = (new Discovery($http))->discover('https://me.example/');

        $this->assertSame('https://me.example/meta', $result->metadataUrl);
        $this->assertSame(['https://me.example/meta'], $result->headerMetadata);
        $this->assertSame(['https://other.example/meta'], $result->htmlMetadata);
        $this->assertSame('https://auth.example/auth', $result->authorizationEndpoint());
        $this->assertNull($result->metadataError);
    }

    public function test_derives_and_fetches_the_well_known_url(): void
    {
        $this->assertSame('https://auth.example/.well-known/oauth-authorization-server', DiscoveryResult::wellKnownUrlFor('https://auth.example/'));
        $this->assertSame('https://auth.example/.well-known/oauth-authorization-server/s/abc', DiscoveryResult::wellKnownUrlFor('https://auth.example/s/abc/'), 'the terminating slash is removed first');
        $this->assertSame('https://auth.example:8443/.well-known/oauth-authorization-server/tenant', DiscoveryResult::wellKnownUrlFor('https://auth.example:8443/tenant'));
        $this->assertNull(DiscoveryResult::wellKnownUrlFor('https://auth.example/?x=1'));

        $http = new FakeHttpClient();
        $http->html('https://me.example/', '<link rel="indieauth-metadata" href="https://auth.example/s/abc/metadata">');
        $http->json('https://auth.example/s/abc/metadata', ['issuer' => 'https://auth.example/s/abc/'] + self::METADATA);
        $http->json('https://auth.example/.well-known/oauth-authorization-server/s/abc', ['issuer' => 'https://auth.example/s/abc/'] + self::METADATA);

        $result = (new Discovery($http))->discover('https://me.example/');

        $this->assertSame('https://auth.example/.well-known/oauth-authorization-server/s/abc', $result->wellKnownUrl);
        $this->assertSame(200, $result->wellKnownResponse?->status);
        $this->assertSame('https://auth.example/s/abc/', $result->wellKnownMetadata['issuer'] ?? null);
        $this->assertSame('https://auth.example/s/abc/metadata', $result->metadataUrl, 'the linked document is still the one used');
    }

    public function test_follows_redirects_and_records_them(): void
    {
        $http = new FakeHttpClient();
        $http->on('https://me.example/', 301, '', ['Location' => '/home']);
        $http->html('https://me.example/home', '<link rel="me indieauth-metadata" href="meta">');
        $http->json('https://me.example/meta', self::METADATA);

        $result = (new Discovery($http))->discover('https://me.example/');

        $this->assertSame('https://me.example/home', $result->finalUrl());
        $this->assertSame(['https://me.example/', 'https://me.example/home'], $result->discoveryUrls());
        $this->assertSame('https://me.example/meta', $result->metadataUrl);
    }

    public function test_reports_legacy_links_and_bad_metadata(): void
    {
        $http = new FakeHttpClient();
        $http->html('https://me.example/', '<link rel="authorization_endpoint" href="https://old.example/auth"><link rel="indieauth-metadata" href="/meta">');
        $http->on('https://me.example/meta', 200, '<html>not json</html>', ['Content-Type' => 'text/html']);

        $result = (new Discovery($http))->discover('https://me.example/');

        $this->assertSame('https://old.example/auth', $result->legacyAuthorizationEndpoint);
        $this->assertNull($result->metadata);
        $this->assertNotNull($result->metadataError);
    }

    public function test_reports_an_unreachable_profile(): void
    {
        $result = (new Discovery(new FakeHttpClient()))->discover('https://nowhere.example/');

        $this->assertNotNull($result->profileError);
        $this->assertNull($result->metadataUrl);
    }

    public function test_verifies_a_returned_me(): void
    {
        $http = new FakeHttpClient();
        $http->html('https://same.example/', '<link rel="indieauth-metadata" href="https://auth.example/meta">');
        $http->html('https://other.example/', '<link rel="authorization_endpoint" href="https://evil.example/auth">');
        $http->json('https://auth.example/meta', self::METADATA);

        $verifier = new MeVerifier(new Discovery($http));

        $this->assertSame(['ok' => true, 'fetched' => false], array_intersect_key(
            $verifier->verify('https://me.example/', ['https://me.example/'], 'https://auth.example/auth'),
            ['ok' => 1, 'fetched' => 1],
        ));
        $this->assertTrue($verifier->verify('https://same.example/', ['https://me.example/'], 'https://auth.example/auth')['ok']);
        $this->assertFalse($verifier->verify('https://other.example/', ['https://me.example/'], 'https://auth.example/auth')['ok']);
        $this->assertFalse($verifier->verify('https://gone.example/', ['https://me.example/'], 'https://auth.example/auth')['ok']);
    }
}
