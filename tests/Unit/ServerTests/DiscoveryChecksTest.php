<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\ServerTests;

use IndieAuthRocks\Engine\Outcome;
use IndieAuthRocks\Engine\Verdict;
use IndieAuthRocks\Fetch\FetchResult;
use IndieAuthRocks\IndieAuth\DiscoveryResult;
use IndieAuthRocks\ServerTests\Checks\DiscoveryChecks;
use PHPUnit\Framework\TestCase;

final class DiscoveryChecksTest extends TestCase
{
    private const METADATA = [
        'authorization_endpoint'           => 'https://auth.example/s/abc/auth',
        'token_endpoint'                   => 'https://auth.example/token',
        'code_challenge_methods_supported' => ['S256'],
    ];

    /**
     * §4.1.1 says the issuer is a prefix of the metadata URL. An issuer with a
     * path cannot be one when its metadata lives where RFC 8414 puts it, so
     * that exact location counts too — and nothing else does.
     */
    public function test_s105_accepts_a_prefix_or_the_rfc_8414_location(): void
    {
        $issuer = 'https://auth.example/s/abc/';

        $this->assertSame(Outcome::PASS, $this->s105($issuer, 'https://auth.example/s/abc/metadata')->outcome);
        $this->assertSame(Outcome::PASS, $this->s105($issuer, 'https://auth.example/.well-known/oauth-authorization-server/s/abc/')->outcome);
        $this->assertSame(Outcome::PASS, $this->s105('https://auth.example/', 'https://auth.example/.well-known/oauth-authorization-server')->outcome);

        $other = $this->s105($issuer, 'https://auth.example/.well-known/oauth-authorization-server/s/xyz/');
        $this->assertSame(Outcome::FAIL, $other->outcome);
        $this->assertStringContainsString('neither a prefix', $other->summary);

        $this->assertSame(Outcome::FAIL, $this->s105($issuer, 'https://auth.example/.well-known/oauth-authorization-server/s/abc')->outcome, 'the derived URL keeps the trailing slash');
        $this->assertSame(Outcome::FAIL, $this->s105('http://auth.example/s/abc/', 'http://auth.example/s/abc/metadata')->outcome, 'https is still required');
    }

    private function s105(string $issuer, string $metadataUrl): Verdict
    {
        $metadata = ['issuer' => $issuer] + self::METADATA;
        $body     = (string) json_encode($metadata);

        $result = new DiscoveryResult(
            profileUrl:       'https://me.example/',
            profile:          new FetchResult('https://me.example/', 200, '', ['content-type' => ['text/html']]),
            htmlMetadata:     [$metadataUrl],
            metadataUrl:      $metadataUrl,
            metadataResponse: new FetchResult($metadataUrl, 200, $body, ['content-type' => ['application/json']]),
            metadata:         $metadata,
        );

        foreach (DiscoveryChecks::grade($result) as $verdict) {
            if ($verdict->testId === 'S105') {
                return $verdict;
            }
        }

        $this->fail('No S105 verdict');
    }
}
