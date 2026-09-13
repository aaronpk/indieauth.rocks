<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Support;

use Closure;
use IndieAuthRocks\Crypto\Random;
use IndieAuthRocks\Fetch\FetchException;
use IndieAuthRocks\Fetch\HttpClient;
use IndieAuthRocks\Fetch\HttpRequest;
use IndieAuthRocks\Fetch\PageFetcher;
use IndieAuthRocks\Fetch\UrlResolver;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\IndieAuth\LinkRelParser;
use IndieAuthRocks\IndieAuth\Pkce;
use IndieAuthRocks\IndieAuth\ProfileUrl;
use IndieAuthRocks\ServerTests\Landing;

/**
 * A small, careful IndieAuth client for testing the client suite — with
 * switches that each make it careless in one way.
 */
final class FakeIndieAuthClient
{
    public const CLIENT_ID    = 'https://client.example/';
    public const REDIRECT_URI = 'https://client.example/callback';

    /** @var array<string, bool> */
    public array $defects = [];

    /** @var array<string, array<string, string>> state => pending request */
    private array $pending = [];

    /** @var array<string, string>|null The most recent request, which a careless client falls back to. */
    private ?array $last = null;

    /**
     * @param HttpClient $http    Back channel (the app in-process).
     * @param Closure(string, string, array<string, string>): Response $browse
     *        The browser: method, url, post fields, carrying the tester's cookies.
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly Closure $browse,
        string ...$defects,
    ) {
        foreach ($defects as $defect) {
            $this->defects[$defect] = true;
        }
    }

    /**
     * Sign in with a profile URL, approving on the test server.
     *
     * @return array{outcome: string, me: ?string, reason: string}
     */
    public function signIn(string $profile, string $scope = 'profile create'): array
    {
        try {
            [$metadata, $discoveryUrls] = $this->discover($profile);
        } catch (FetchException $e) {
            return ['outcome' => 'error', 'me' => null, 'reason' => $e->getMessage()];
        }

        if ($metadata === null) {
            return ['outcome' => 'error', 'me' => null, 'reason' => 'No IndieAuth server found.'];
        }

        $verifier = Random::token(40);
        $state    = Random::token(16);
        $params   = [
            'response_type' => 'code',
            'client_id'     => self::CLIENT_ID,
            'redirect_uri'  => self::REDIRECT_URI,
            'state'         => $state,
            'scope'         => $scope,
            'me'            => $profile,
        ];

        if (!isset($this->defects['noPkce'])) {
            $params['code_challenge']        = Pkce::challengeFor($verifier);
            $params['code_challenge_method'] = 'S256';
        }

        $this->pending[$state] = [
            'verifier'       => $verifier,
            'issuer'         => (string) ($metadata['issuer'] ?? ''),
            'token_endpoint' => (string) ($metadata['token_endpoint'] ?? ''),
            'authorization'  => (string) $metadata['authorization_endpoint'],
            'profile'        => $profile,
            'urls'           => json_encode($discoveryUrls),
        ];

        $consent = ($this->browse)('GET', $metadata['authorization_endpoint'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986), []);

        if ($consent->status !== 200 || preg_match('/name="attempt" value="([^"]+)"/', $consent->body, $attempt) !== 1) {
            return ['outcome' => 'error', 'me' => null, 'reason' => 'The server refused the authorization request.'];
        }

        preg_match('/name="_csrf" value="([^"]+)"/', $consent->body, $csrf);
        preg_match('/<form method="post" action="([^"]+\/approve)"/', $consent->body, $action);

        $approved = ($this->browse)('POST', html_entity_decode($action[1]), [
            '_csrf'   => html_entity_decode($csrf[1] ?? ''),
            'attempt' => html_entity_decode($attempt[1]),
            'action'  => 'approve',
        ]);

        return $this->callback((string) $approved->header('location'));
    }

    /**
     * Handle a redirect to the client's redirect URI.
     *
     * @return array{outcome: string, me: ?string, reason: string}
     */
    public function callback(string $url): array
    {
        $query   = Landing::parseQuery((string) parse_url($url, PHP_URL_QUERY));
        $state   = $query['state'] ?? '';
        $pending = $this->pending[$state] ?? null;

        if ($pending === null) {
            $fallback = $this->pending !== [] ? end($this->pending) : $this->last;

            if (!isset($this->defects['skipStateCheck']) || !is_array($fallback)) {
                return ['outcome' => 'error', 'me' => null, 'reason' => 'Unknown state.'];
            }

            $pending = $fallback;
        } else {
            unset($this->pending[$state]);
        }

        $this->last = $pending;

        if (isset($query['error'])) {
            return ['outcome' => 'error', 'me' => null, 'reason' => 'The server returned ' . $query['error']];
        }

        // iss is only checked when the server's metadata said it would send one.
        if (!isset($this->defects['skipIssCheck']) && $pending['issuer'] !== '' && ($query['iss'] ?? null) !== $pending['issuer']) {
            return ['outcome' => 'error', 'me' => null, 'reason' => 'iss does not match.'];
        }

        $endpoint = $pending['token_endpoint'] !== '' ? $pending['token_endpoint'] : $pending['authorization'];
        $response = $this->http->send(HttpRequest::postForm($endpoint, [
            'grant_type'    => 'authorization_code',
            'code'          => (string) ($query['code'] ?? ''),
            'client_id'     => self::CLIENT_ID,
            'redirect_uri'  => self::REDIRECT_URI,
            'code_verifier' => $pending['verifier'],
        ]));

        $body = $response->json() ?? [];
        $me   = is_string($body['me'] ?? null) ? $body['me'] : null;

        if ($response->status !== 200 || $me === null || ProfileUrl::parse($me) === null) {
            return ['outcome' => 'error', 'me' => null, 'reason' => 'No valid me in the token response.'];
        }

        $urls = (array) json_decode($pending['urls'], true);

        if (!isset($this->defects['noMeVerification']) && !in_array($me, $urls, true)) {
            [$declared] = $this->discover($me);

            if (($declared['authorization_endpoint'] ?? null) !== $pending['authorization']) {
                return ['outcome' => 'error', 'me' => null, 'reason' => 'me does not declare the same server.'];
            }
        }

        return ['outcome' => 'signed_in', 'me' => $me, 'reason' => ''];
    }

    /** @return array{0: ?array<string, mixed>, 1: list<string>} */
    private function discover(string $profile): array
    {
        $page = (new PageFetcher($this->http))->fetch($profile, 'text/html');
        $urls = array_values(array_unique([$profile, ...$page->redirects, $page->url]));

        $href = null;
        if (!isset($this->defects['headerIgnored'])) {
            foreach ($page->headerValues('link') as $header) {
                $href ??= LinkRelParser::fromLinkHeader($header, 'indieauth-metadata')[0] ?? null;
            }
        }

        $metadataUrl = null;

        if ($href !== null) {
            $metadataUrl = UrlResolver::resolve($page->url, $href);
        } elseif (isset($this->defects['naiveRelative']) && preg_match('/rel="[^"]*indieauth-metadata[^"]*" href="([^"]+)"/', $page->body, $m) === 1 && !str_contains($m[1], '://')) {
            $metadataUrl = rtrim($page->url, '/') . '/' . $m[1];
        } else {
            $metadataUrl = LinkRelParser::fromHtml($page->body, $page->url, 'indieauth-metadata')[0] ?? null;
        }

        if ($metadataUrl === null) {
            $authz = LinkRelParser::find($page, 'authorization_endpoint')[0] ?? null;
            $token = LinkRelParser::find($page, 'token_endpoint')[0] ?? null;

            return [$authz === null ? null : ['authorization_endpoint' => $authz, 'token_endpoint' => $token ?? '', 'issuer' => null], $urls];
        }

        $metadata = $this->http->send(HttpRequest::get($metadataUrl, 'application/json'))->json();

        return [is_array($metadata) && isset($metadata['authorization_endpoint']) ? $metadata : null, $urls];
    }
}
