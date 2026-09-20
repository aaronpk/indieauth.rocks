<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Support;

use Closure;
use Firebase\JWT\JWT;
use IndieAuthRocks\Fetch\FetchResult;
use IndieAuthRocks\Fetch\HttpRequest;
use IndieAuthRocks\IndieAuth\Pkce;
use IndieAuthRocks\ServerTests\Landing;

/**
 * A small, correct IndieAuth server for testing the test suite — with
 * switches that make it wrong in exactly one way each.
 *
 * It answers back-channel requests through FakeHttpClient and plays the
 * browser for the front channel: authorize() returns where the server would
 * send the browser, or null when it would show an error page.
 */
final class FakeIndieAuthServer
{
    public const BASE = 'https://server.example/';
    public const ME   = 'https://me.example/';

    /** @var array<string, bool> */
    public array $defects = [];

    /** @var array<string, array<string, mixed>> */
    private array $codes = [];

    /** @var array<string, array<string, mixed>> access token => grant */
    private array $access = [];

    /** @var array<string, array<string, mixed>> refresh token => grant */
    private array $refresh = [];

    private int $family = 0;

    /** @var array{kid: string, pem: string, jwk: array<string, string>}|null */
    private ?array $key = null;

    /** @param Closure(string): ?array $fetchClientDocument */
    public function __construct(private readonly Closure $fetchClientDocument, string ...$defects)
    {
        foreach ($defects as $defect) {
            $this->defects[$defect] = true;
        }

        // Only a server that issues JWTs can issue one with the wrong issuer.
        if (isset($this->defects['jwtWrongIssuer'])) {
            $this->defects['jwtTokens'] = true;
        }
    }

    public function install(FakeHttpClient $http): void
    {
        $http->html(self::ME, '<!doctype html><html><head><link rel="indieauth-metadata" href="' . self::BASE . 'metadata"></head><body>Me</body></html>');

        $metadata = [
            'issuer'                                         => self::BASE,
            'authorization_endpoint'                         => self::BASE . 'auth',
            'token_endpoint'                                 => self::BASE . 'token',
            'introspection_endpoint'                         => self::BASE . 'introspect',
            'introspection_endpoint_auth_methods_supported'  => ['Bearer'],
            'revocation_endpoint'                            => self::BASE . 'revoke',
            'revocation_endpoint_auth_methods_supported'     => ['none'],
            'userinfo_endpoint'                              => self::BASE . 'userinfo',
            'scopes_supported'                               => ['profile', 'email', 'create'],
            'response_types_supported'                       => ['code'],
            'grant_types_supported'                          => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'               => ['S256'],
            'authorization_response_iss_parameter_supported' => true,
        ];

        if (isset($this->defects['jwtTokens'])) {
            $metadata['jwks_uri'] = self::BASE . 'jwks';
            $http->handle(self::BASE . 'jwks', fn (HttpRequest $r): FetchResult => $this->json(['keys' => [$this->key()['jwk']]]));
        }

        $http->json(self::BASE . 'metadata', $metadata);

        if (!isset($this->defects['noWellKnown'])) {
            $http->json(self::BASE . '.well-known/oauth-authorization-server', $metadata);
        }

        $http->handle(self::BASE . 'token', fn (HttpRequest $r): FetchResult => $this->token($r));
        $http->handle(self::BASE . 'auth', fn (HttpRequest $r): FetchResult => $this->authEndpoint($r));
        $http->handle(self::BASE . 'revoke', fn (HttpRequest $r): FetchResult => $this->revoke($r));
        $http->handle(self::BASE . 'userinfo', fn (HttpRequest $r): FetchResult => $this->userinfo($r));
        $http->handle(self::BASE . 'introspect', fn (HttpRequest $r): FetchResult => $this->introspect($r));
    }

    // ============================================================ front channel

    /** Where the browser ends up, or null for an error page. */
    public function authorize(string $url, string $intent): ?string
    {
        $pairs = [];
        foreach (explode('&', (string) parse_url($url, PHP_URL_QUERY)) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [rawurldecode($name), rawurldecode($value)];
        }

        $params = [];
        $counts = [];
        foreach ($pairs as [$name, $value]) {
            $params[$name] ??= $value;
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        if (($counts['redirect_uri'] ?? 0) > 1 || ($counts['client_id'] ?? 0) > 1) {
            return null;
        }

        $clientId    = $params['client_id'] ?? null;
        $redirectUri = $params['redirect_uri'] ?? null;

        if ($clientId === null || $redirectUri === null || str_contains($clientId, '/../') || str_contains($clientId, '/./')) {
            return null;
        }

        // Like a real server, fetch the client's metadata to show its name.
        $document = ($this->fetchClientDocument)($clientId);

        if (!isset($this->defects['acceptsUnregisteredRedirect']) && !$this->redirectAllowed($clientId, $redirectUri, $document)) {
            return null;
        }

        $state = $params['state'] ?? null;
        $iss   = isset($this->defects['wrongIss']) ? 'https://evil.example/' : self::BASE;

        $redirect = function (array $values) use ($redirectUri, $state, $iss): string {
            if ($state !== null) {
                $values['state'] = $state;
            }
            if (!isset($this->defects['noIss'])) {
                $values['iss'] = $iss;
            }

            return $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($values, '', '&', PHP_QUERY_RFC3986);
        };

        if (($params['response_type'] ?? null) !== 'code') {
            return $redirect(['error' => 'unsupported_response_type']);
        }

        if ($state === null) {
            return $redirect(['error' => 'invalid_request', 'error_description' => 'state is required']);
        }

        $challenge = $params['code_challenge'] ?? null;
        $method    = $params['code_challenge_method'] ?? null;

        // The PKCE mistakes S215, S216, S221 and S222 look for, each its own defect.
        $pkceOk = match (true) {
            isset($this->defects['noPkceCheck'])                                          => true,
            $challenge === null && $method !== null && isset($this->defects['methodWithoutChallenge']) => true,
            $challenge !== null && $method === null && isset($this->defects['defaultsToPlain'])        => true,
            default                                                                       => $challenge !== null && $method === 'S256',
        };

        if (!$pkceOk) {
            return $redirect(['error' => 'invalid_request', 'error_description' => 'PKCE with S256 is required']);
        }

        if ($intent !== 'approve') {
            return $redirect(['error' => 'access_denied']);
        }

        $code = bin2hex(random_bytes(20));
        $this->codes[$code] = [
            'client_id'    => $clientId,
            'redirect_uri' => $redirectUri,
            'challenge'    => $challenge,
            'method'       => $method,
            'scope'        => trim((string) ($params['scope'] ?? '')),
            'me'           => isset($this->defects['returnsHintMe']) && isset($params['me']) ? $params['me'] : self::ME,
            'used'         => false,
        ];

        return $redirect(['code' => $code]);
    }

    private function redirectAllowed(string $clientId, string $redirectUri, ?array $document): bool
    {
        $origin = static function (string $url): string {
            $p = parse_url($url);

            return strtolower(($p['scheme'] ?? '') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '') . (isset($p['user']) ? '@user' : ''));
        };

        if ($origin($clientId) === $origin($redirectUri) && parse_url($redirectUri, PHP_URL_USER) === null) {
            return true;
        }

        if ($document === null) {
            return false;
        }

        if (!isset($this->defects['ignoresMetadataMismatch']) && ($document['client_id'] ?? null) !== $clientId) {
            return false;
        }

        foreach ((array) ($document['redirect_uris'] ?? []) as $registered) {
            if ($registered === $redirectUri || (isset($this->defects['prefixMatch']) && str_starts_with($redirectUri, (string) $registered))) {
                return true;
            }
        }

        return false;
    }

    // ============================================================= back channel

    private function token(HttpRequest $request): FetchResult
    {
        if ($request->method === 'OPTIONS') {
            return FakeHttpClient::response(204, '', ['Access-Control-Allow-Origin' => '*', 'Access-Control-Allow-Methods' => 'POST']);
        }

        if ($request->method !== 'POST') {
            return FakeHttpClient::response(405, 'Method not allowed', ['Allow' => 'POST']);
        }

        $p = $request->formParams();

        return match ($p['grant_type'] ?? null) {
            'authorization_code' => $this->redeem($p, true),
            'refresh_token'      => $this->refreshGrant($p),
            null                 => $this->error('invalid_request'),
            default              => $this->error('unsupported_grant_type'),
        };
    }

    private function authEndpoint(HttpRequest $request): FetchResult
    {
        if ($request->method === 'GET') {
            return FakeHttpClient::response(400, '<p>Missing parameters</p>', ['Content-Type' => 'text/html', 'X-Frame-Options' => 'DENY']);
        }

        $p = $request->formParams();

        return ($p['grant_type'] ?? null) === 'authorization_code' ? $this->redeem($p, false) : $this->error('invalid_request');
    }

    /** @param array<string, string> $p */
    private function redeem(array $p, bool $atTokenEndpoint): FetchResult
    {
        $code  = $p['code'] ?? '';
        $grant = $this->codes[$code] ?? null;

        if ($grant === null) {
            return $this->error('invalid_grant');
        }

        if ($grant['used'] && !isset($this->defects['reusableCodes'])) {
            // Replaying a code revokes what it issued.
            foreach ($this->access as $token => $data) {
                if (($data['code'] ?? null) === $code) {
                    unset($this->access[$token]);
                }
            }

            return $this->error('invalid_grant');
        }

        $ok = ($p['client_id'] ?? null) === $grant['client_id']
            && ($p['redirect_uri'] ?? null) === $grant['redirect_uri']
            && ($grant['challenge'] === null
                ? !isset($p['code_verifier'])
                : isset($p['code_verifier']) && ($grant['method'] === 'plain' ? $p['code_verifier'] === $grant['challenge'] : Pkce::verify($p['code_verifier'], (string) $grant['challenge'])));

        if (!$ok) {
            if (isset($this->defects['burnOnFailure'])) {
                unset($this->codes[$code]);
            }

            return $this->error('invalid_grant');
        }

        // A server written to the older specification takes an exchange without
        // a scope only at its authorization endpoint, and refuses here without
        // spending the code.
        if ($atTokenEndpoint && $grant['scope'] === '' && isset($this->defects['scopelessOnlyAtAuthorization'])) {
            return $this->error('invalid_request');
        }

        $this->codes[$code]['used'] = true;

        $body = ['me' => $grant['me']];

        if (in_array('profile', explode(' ', $grant['scope']), true)) {
            $body['profile'] = ['name' => 'Test Person', 'url' => $grant['me']];
        }

        if ($atTokenEndpoint && ($grant['scope'] !== '' || isset($this->defects['tokenForScopeless']))) {
            $body += $this->issue($grant['scope'], $grant['client_id'], $grant['me'], $code);
        }

        return $this->json($body);
    }

    /** @param array<string, string> $p */
    private function refreshGrant(array $p): FetchResult
    {
        $token = $p['refresh_token'] ?? '';
        $grant = $this->refresh[$token] ?? null;

        if ($grant === null || ($grant['client_id'] !== ($p['client_id'] ?? null))) {
            return $this->error('invalid_grant');
        }

        if ($grant['used']) {
            $this->revokeFamily($grant['family']);

            return $this->error('invalid_grant');
        }

        if (isset($p['scope']) && array_diff(explode(' ', $p['scope']), explode(' ', $grant['scope'])) !== []) {
            return $this->error('invalid_scope');
        }

        if (!isset($this->defects['noRotation'])) {
            $this->refresh[$token]['used'] = true;
        }

        $issued = $this->issue($grant['scope'], $grant['client_id'], $grant['me'], null, $grant['family']);

        if (isset($this->defects['noRotation'])) {
            unset($this->refresh[$issued['refresh_token']]);
            $issued['refresh_token'] = $token;
        }

        return $this->json($issued + ['me' => $grant['me']]);
    }

    private function revoke(HttpRequest $request): FetchResult
    {
        $token = $request->formParams()['token'] ?? '';

        if (!isset($this->defects['revokeNoop'])) {
            $family = $this->access[$token]['family'] ?? $this->refresh[$token]['family'] ?? null;

            if ($family !== null) {
                $this->revokeFamily($family);
            }
        }

        return FakeHttpClient::response(200);
    }

    private function userinfo(HttpRequest $request): FetchResult
    {
        $auth = (string) $request->header('authorization');

        if (!str_starts_with($auth, 'Bearer ')) {
            return FakeHttpClient::response(401, '', ['WWW-Authenticate' => 'Bearer']);
        }

        $grant = $this->access[substr($auth, 7)] ?? null;

        if ($grant === null) {
            return FakeHttpClient::response(401, '', ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }

        return $this->json(['name' => 'Test Person', 'url' => $grant['me']]);
    }

    private function introspect(HttpRequest $request): FetchResult
    {
        $grant = $this->access[$request->formParams()['token'] ?? ''] ?? null;

        if ($request->header('authorization') !== 'Bearer cred' && !isset($this->defects['introspectNoAuth'])) {
            return FakeHttpClient::jsonResponse(['error' => 'unauthorized'], 401);
        }

        return $this->json($grant === null
            ? ['active' => false]
            : ['active' => true, 'me' => $grant['me'], 'client_id' => $grant['client_id'], 'scope' => $grant['scope']]);
    }

    // ================================================================== helpers

    /** @return array<string, mixed> */
    private function issue(string $scope, string $clientId, string $me, ?string $code, ?int $family = null): array
    {
        $family ??= ++$this->family;
        $access  = isset($this->defects['jwtTokens']) ? $this->jwt($scope, $clientId, $me) : bin2hex(random_bytes(24));
        $refresh = bin2hex(random_bytes(24));

        $grant = ['scope' => $scope, 'client_id' => $clientId, 'me' => $me, 'family' => $family, 'code' => $code];

        $this->access[$access]   = $grant;
        $this->refresh[$refresh] = $grant + ['used' => false];

        return [
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'scope'         => $scope,
            'expires_in'    => 3600,
            'refresh_token' => $refresh,
        ];
    }

    /** A JWT access token (RFC 9068), for servers that issue them. */
    private function jwt(string $scope, string $clientId, string $me): string
    {
        $key = $this->key();

        return JWT::encode([
            'iss'       => isset($this->defects['jwtWrongIssuer']) ? 'https://evil.example/' : self::BASE,
            'sub'       => $me,
            'aud'       => self::BASE . 'resource',
            'exp'       => time() + 3600,
            'iat'       => time(),
            'jti'       => bin2hex(random_bytes(8)),
            'client_id' => $clientId,
            'scope'     => $scope,
            'me'        => $me,
        ], $key['pem'], 'RS256', $key['kid'], ['typ' => 'at+jwt']);
    }

    /** @return array{kid: string, pem: string, jwk: array<string, string>} */
    private function key(): array
    {
        if ($this->key !== null) {
            return $this->key;
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        $details = (array) openssl_pkey_get_details($key);
        $kid     = bin2hex(random_bytes(4));

        return $this->key = ['kid' => $kid, 'pem' => (string) $pem, 'jwk' => [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n'   => JWT::urlsafeB64Encode((string) $details['rsa']['n']),
            'e'   => JWT::urlsafeB64Encode((string) $details['rsa']['e']),
        ]];
    }

    private function revokeFamily(int $family): void
    {
        foreach ($this->access as $token => $data) {
            if ($data['family'] === $family) {
                unset($this->access[$token]);
            }
        }

        foreach ($this->refresh as $token => $data) {
            if ($data['family'] === $family) {
                unset($this->refresh[$token]);
            }
        }
    }

    private function error(string $error): FetchResult
    {
        return $this->json(['error' => $error], 400);
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): FetchResult
    {
        $headers = ['Content-Type' => 'application/json'];

        if (!isset($this->defects['noNoStore'])) {
            $headers['Cache-Control'] = 'no-store';
        }

        return FakeHttpClient::jsonResponse($body, $status, $headers);
    }

    /** Whether a URL is one the test suite would treat as a landing. */
    public static function isLanding(\IndieAuthRocks\Config\SiteConfig $config, ?string $url): bool
    {
        return $url !== null && Landing::parse($config, $url) !== null;
    }
}
