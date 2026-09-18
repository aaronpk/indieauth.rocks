<?php

declare(strict_types=1);

namespace IndieAuthRocks\IndieAuth;

use IndieAuthRocks\Fetch\FetchResult;

/** Everything learned by discovering an IndieAuth server from a profile URL. */
final class DiscoveryResult
{
    /**
     * @param list<string>              $redirects      URLs visited before the final one.
     * @param list<string>              $headerMetadata indieauth-metadata URLs from Link headers.
     * @param list<string>              $htmlMetadata   indieauth-metadata URLs from HTML.
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public readonly string $profileUrl,
        public readonly ?FetchResult $profile = null,
        public readonly ?string $profileError = null,
        public readonly array $redirects = [],
        public readonly array $headerMetadata = [],
        public readonly array $htmlMetadata = [],
        public readonly ?string $legacyAuthorizationEndpoint = null,
        public readonly ?string $legacyTokenEndpoint = null,
        public readonly ?string $metadataUrl = null,
        public readonly ?FetchResult $metadataResponse = null,
        public readonly ?string $metadataError = null,
        public readonly ?array $metadata = null,
        /** The RFC 8414 location derived from the issuer, when there was one to derive from. */
        public readonly ?string $wellKnownUrl = null,
        public readonly ?FetchResult $wellKnownResponse = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $wellKnownMetadata = null,
    ) {
    }

    /**
     * Where RFC 8414 §3.1 says an issuer's metadata lives: the well-known
     * segment inserted between the host and the issuer's path.
     */
    public static function wellKnownUrlFor(string $issuer): ?string
    {
        $parts = parse_url($issuer);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $path = $parts['path'] ?? '';

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . '/.well-known/oauth-authorization-server' . ($path === '/' ? '' : $path);
    }

    public function finalUrl(): string
    {
        return $this->profile?->url ?? $this->profileUrl;
    }

    /** @return list<string> The entered URL plus every URL seen while fetching it. */
    public function discoveryUrls(): array
    {
        return array_values(array_unique([$this->profileUrl, ...$this->redirects, $this->finalUrl()]));
    }

    /** A string member of the metadata, or null. */
    public function string(string $key): ?string
    {
        $value = $this->metadata[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The authorization endpoint, from metadata or else legacy discovery. */
    public function authorizationEndpoint(): ?string
    {
        return $this->string('authorization_endpoint') ?? ($this->metadata === null ? $this->legacyAuthorizationEndpoint : null);
    }

    public function tokenEndpoint(): ?string
    {
        return $this->string('token_endpoint') ?? ($this->metadata === null ? $this->legacyTokenEndpoint : null);
    }

    /** @return array<string, mixed> A plain summary, safe to store in run state. */
    public function toArray(): array
    {
        return [
            'profile_url'                   => $this->profileUrl,
            'final_url'                     => $this->finalUrl(),
            'profile_status'                => $this->profile?->status,
            'profile_error'                 => $this->profileError,
            'redirects'                     => $this->redirects,
            'header_metadata'               => $this->headerMetadata,
            'html_metadata'                 => $this->htmlMetadata,
            'legacy_authorization_endpoint' => $this->legacyAuthorizationEndpoint,
            'legacy_token_endpoint'         => $this->legacyTokenEndpoint,
            'metadata_url'                  => $this->metadataUrl,
            'metadata_status'               => $this->metadataResponse?->status,
            'metadata_content_type'         => $this->metadataResponse?->contentType(),
            'metadata_cors'                 => $this->metadataResponse?->header('access-control-allow-origin'),
            'metadata_error'                => $this->metadataError,
            'metadata'                      => $this->metadata,
            'well_known_url'                => $this->wellKnownUrl,
            'well_known_status'             => $this->wellKnownResponse?->status,
        ];
    }
}
