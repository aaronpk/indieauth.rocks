<?php

declare(strict_types=1);

namespace IndieAuthRocks\IndieAuth;

use IndieAuthRocks\Fetch\FetchException;
use IndieAuthRocks\Fetch\HttpClient;
use IndieAuthRocks\Fetch\HttpRequest;
use IndieAuthRocks\Fetch\PageFetcher;
use IndieAuthRocks\Fetch\UrlResolver;

/**
 * IndieAuth discovery (§4.1): fetch the profile URL, find the metadata URL in
 * the Link header or HTML, and fetch the metadata document.
 */
final class Discovery
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Turn what someone typed into a profile URL to fetch: add https:// if
     * there is no scheme and "/" if there is no path (§3.4).
     */
    public static function normalizeInput(string $input): string
    {
        $input = trim($input);

        if ($input !== '' && preg_match('#^[a-z][a-z0-9+.-]*://#i', $input) !== 1) {
            $input = 'https://' . $input;
        }

        $parts = parse_url($input);

        if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            $scheme = strtolower($parts['scheme']);
            $host   = strtolower($parts['host']);
            $rest   = substr($input, strlen($parts['scheme'] . '://' . $parts['host']));

            if ($rest === '' || $rest[0] === '?' || $rest[0] === '#') {
                $rest = '/' . $rest;
            }

            return $scheme . '://' . $host . $rest;
        }

        return $input;
    }

    public function discover(string $profileUrl): DiscoveryResult
    {
        try {
            $page = (new PageFetcher($this->http))->fetch($profileUrl, 'text/html, application/xhtml+xml;q=0.9, */*;q=0.1', 2_000_000);
        } catch (FetchException $e) {
            return new DiscoveryResult($profileUrl, profileError: $e->getMessage());
        }

        $headerLinks = [];
        foreach ($page->headerValues('link') as $header) {
            foreach (LinkRelParser::fromLinkHeader($header, 'indieauth-metadata') as $href) {
                $url = UrlResolver::resolve($page->url, $href);
                if ($url !== null) {
                    $headerLinks[] = $url;
                }
            }
        }

        $htmlLinks = LinkRelParser::fromHtml($page->body, $page->url, 'indieauth-metadata');
        $authz     = LinkRelParser::find($page, 'authorization_endpoint')[0] ?? null;
        $token     = LinkRelParser::find($page, 'token_endpoint')[0] ?? null;

        $metadataUrl = $headerLinks[0] ?? $htmlLinks[0] ?? null;

        $result = new DiscoveryResult(
            profileUrl:                  $profileUrl,
            profile:                     $page,
            redirects:                   $page->redirects,
            headerMetadata:              array_values(array_unique($headerLinks)),
            htmlMetadata:                array_values(array_unique($htmlLinks)),
            legacyAuthorizationEndpoint: $authz,
            legacyTokenEndpoint:         $token,
            metadataUrl:                 $metadataUrl,
        );

        if ($metadataUrl === null || $page->status !== 200) {
            return $result;
        }

        try {
            $response = $this->http->send(HttpRequest::get($metadataUrl, 'application/json'));
        } catch (FetchException $e) {
            return self::with($result, metadataError: $e->getMessage());
        }

        $metadata = $response->status === 200 ? $response->json() : null;

        $result = self::with(
            $result,
            metadataResponse: $response,
            metadata:         $metadata,
            metadataError:    $metadata === null
                ? ($response->status !== 200
                    ? sprintf('The metadata URL returned HTTP %d.', $response->status)
                    : 'The metadata is not a JSON object.')
                : null,
        );

        // A client that starts from the issuer rather than a profile page
        // looks for the document at the RFC 8414 location. Fetched to see
        // whether it is there and says the same thing.
        $issuer    = is_string($metadata['issuer'] ?? null) ? $metadata['issuer'] : null;
        $wellKnown = $issuer === null ? null : DiscoveryResult::wellKnownUrlFor($issuer);

        if ($wellKnown === null) {
            return $result;
        }

        try {
            $wellKnownResponse = $this->http->send(HttpRequest::get($wellKnown, 'application/json'));
        } catch (FetchException) {
            return self::with($result, wellKnownUrl: $wellKnown);
        }

        return self::with(
            $result,
            wellKnownUrl:      $wellKnown,
            wellKnownResponse: $wellKnownResponse,
            wellKnownMetadata: $wellKnownResponse->status === 200 ? $wellKnownResponse->json() : null,
        );
    }

    private static function with(
        DiscoveryResult $r,
        ?\IndieAuthRocks\Fetch\FetchResult $metadataResponse = null,
        ?array $metadata = null,
        ?string $metadataError = null,
        ?string $wellKnownUrl = null,
        ?\IndieAuthRocks\Fetch\FetchResult $wellKnownResponse = null,
        ?array $wellKnownMetadata = null,
    ): DiscoveryResult {
        return new DiscoveryResult(
            $r->profileUrl, $r->profile, $r->profileError, $r->redirects, $r->headerMetadata, $r->htmlMetadata,
            $r->legacyAuthorizationEndpoint, $r->legacyTokenEndpoint, $r->metadataUrl,
            $metadataResponse ?? $r->metadataResponse, $metadataError ?? $r->metadataError, $metadata ?? $r->metadata,
            $wellKnownUrl ?? $r->wellKnownUrl, $wellKnownResponse ?? $r->wellKnownResponse, $wellKnownMetadata ?? $r->wellKnownMetadata,
        );
    }
}
