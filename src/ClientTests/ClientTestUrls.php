<?php

declare(strict_types=1);

namespace IndieAuthRocks\ClientTests;

use IndieAuthRocks\Config\SiteConfig;

/**
 * URLs of the profiles and IndieAuth servers this site plays for one client
 * run. The run key appears in every URL, so requests find their run.
 *
 *   /u/{key}/{n}              the profile URL for test n
 *   /u/{key}/{n}-{variant}    a related page (e.g. 106-final, 402-other)
 *   /u/{key}/m/{n}            metadata linked relatively from a profile
 *   /s/{key}/{n}/…            the test server for test n
 *   /s/{key}/{n}d/…           a decoy server that must never be used
 */
final class ClientTestUrls
{
    public function __construct(
        private readonly SiteConfig $config,
        private readonly string $key,
    ) {
    }

    public function profile(int $n, string $variant = ''): string
    {
        return $this->config->url('u/' . $this->key . '/' . $n . ($variant !== '' ? '-' . $variant : ''));
    }

    public function altProfile(int $n): string
    {
        return $this->config->altUrl('u/' . $this->key . '/' . $n);
    }

    /** Where a relative "m/{n}" link on the profile page resolves to. */
    public function relativeMetadata(int $n): string
    {
        return $this->config->url('u/' . $this->key . '/m/' . $n);
    }

    public function server(int $n, bool $decoy = false): string
    {
        return $this->config->url('s/' . $this->key . '/' . $n . ($decoy ? 'd' : '') . '/');
    }

    public function issuer(int $n, bool $decoy = false): string
    {
        return $this->server($n, $decoy);
    }

    public function metadata(int $n, bool $decoy = false): string
    {
        return $this->server($n, $decoy) . 'metadata';
    }

    public function authorization(int $n, bool $decoy = false): string
    {
        return $this->server($n, $decoy) . 'auth';
    }

    public function token(int $n, bool $decoy = false): string
    {
        return $this->server($n, $decoy) . 'token';
    }

    public function altToken(int $n): string
    {
        return $this->config->altUrl('s/' . $this->key . '/' . $n . '/token');
    }

    public function approve(int $n, bool $decoy = false): string
    {
        return $this->server($n, $decoy) . 'approve';
    }

    public function revocation(int $n): string
    {
        return $this->server($n) . 'revoke';
    }

    public function userinfo(int $n): string
    {
        return $this->server($n) . 'userinfo';
    }

    /** @return array<string, mixed> */
    public function metadataDocument(int $n, bool $decoy = false): array
    {
        $document = [
            'issuer'                                         => $this->issuer($n, $decoy),
            'authorization_endpoint'                         => $this->authorization($n, $decoy),
            'token_endpoint'                                 => $n === 110 && !$decoy ? $this->altToken($n) : $this->token($n, $decoy),
            'revocation_endpoint'                            => $this->revocation($n),
            'revocation_endpoint_auth_methods_supported'     => ['none'],
            'userinfo_endpoint'                              => $this->userinfo($n),
            'scopes_supported'                               => ['profile', 'email', 'create', 'update', 'delete', 'media'],
            'response_types_supported'                       => ['code'],
            'grant_types_supported'                          => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'               => ['S256'],
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation'                          => $this->config->url('tests/C' . $n),
        ];

        if ($n === 110 && !$decoy) {
            $document['x_extension_example'] = ['nested' => true];
            $document['ui_locales_supported'] = ['en'];
        }

        return $document;
    }
}
