<?php

declare(strict_types=1);

namespace IndieAuthRocks\ServerTests\Checks;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use IndieAuthRocks\Engine\Verdict;
use IndieAuthRocks\Fetch\FetchException;
use IndieAuthRocks\Fetch\HttpClient;
use IndieAuthRocks\Fetch\HttpRequest;
use Throwable;

/**
 * Grades S420–S423: access tokens that are JWTs (RFC 9068).
 *
 * Opaque tokens are perfectly fine, so S420 only reports which kind a server
 * issues and the rest are skipped. When a token is a JWT, a resource server has
 * to be able to verify it from the keys the metadata publishes, which is what
 * these checks do.
 */
final class JwtChecks
{
    private const REST = ['S421', 'S422', 'S423'];

    /** @return list<Verdict> */
    public static function grade(
        ?string $token,
        ?string $jwksUrl,
        ?string $issuer,
        string $clientId,
        ?string $scope,
        HttpClient $http,
    ): array {
        if ($token === null) {
            return self::skipAll('No access token was issued.', ['S420', ...self::REST]);
        }

        $parts  = explode('.', $token);
        $header = count($parts) === 3 ? self::decode($parts[0]) : null;

        if ($header === null || !isset($header['alg'])) {
            return [
                Verdict::pass('S420', 'Access tokens are opaque strings, which resource servers check by introspection.'),
                ...self::skipAll('The access token is not a JWT.', self::REST),
            ];
        }

        $claims   = self::decode($parts[1]) ?? [];
        $verdicts = [Verdict::pass('S420', sprintf('Access tokens are JWTs, signed with %s.', $header['alg']))];

        // S421
        $typ = (string) ($header['typ'] ?? '');
        $verdicts[] = match (true) {
            strcasecmp((string) $header['alg'], 'none') === 0 => Verdict::fail('S421', 'The JWT header says alg=none, so the token is not signed.'),
            strcasecmp($typ, 'at+jwt') === 0                  => Verdict::pass('S421', 'The header is typ "at+jwt" with alg ' . $header['alg'] . '.'),
            default                                          => Verdict::unmet('S421', $typ === ''
                ? 'The JWT header has no typ. RFC 9068 asks for "at+jwt", so a token cannot be mistaken for an ID token.'
                : sprintf('The JWT header says typ "%s" rather than "at+jwt".', $typ)),
        };

        // S422
        $verdicts[] = self::signature($token, $jwksUrl, $http);

        // S423
        $verdicts[] = self::claims($claims, $issuer, $clientId, $scope);

        return $verdicts;
    }

    private static function signature(string $token, ?string $jwksUrl, HttpClient $http): Verdict
    {
        if ($jwksUrl === null) {
            return Verdict::unmet('S422', 'The metadata has no jwks_uri, so a resource server cannot get the key to verify the token with.');
        }

        try {
            $response = $http->send(HttpRequest::get($jwksUrl, 'application/json'));
        } catch (FetchException $e) {
            return Verdict::fail('S422', sprintf('%s could not be fetched: %s', $jwksUrl, $e->getMessage()));
        }

        $document = $response->status === 200 ? $response->json() : null;

        if ($document === null || !isset($document['keys'])) {
            return Verdict::fail('S422', sprintf('%s returned %s instead of a JWKS with a "keys" list.', $jwksUrl, 'HTTP ' . $response->status));
        }

        try {
            JWT::decode($token, JWK::parseKeySet($document));
        } catch (SignatureInvalidException) {
            return Verdict::fail('S422', 'The signature does not verify against any key in the JWKS.');
        } catch (ExpiredException) {
            // The signature was checked before the expiry was, so the key is right.
            return Verdict::pass('S422', 'The signature verifies against the published key (the token has since expired).');
        } catch (Throwable $e) {
            return Verdict::fail('S422', 'The token could not be verified with the published keys: ' . $e->getMessage());
        }

        return Verdict::pass('S422', 'The signature verifies against a key from ' . $jwksUrl);
    }

    /** @param array<string, mixed> $claims */
    private static function claims(array $claims, ?string $issuer, string $clientId, ?string $scope): Verdict
    {
        $missing  = [];
        $problems = [];

        foreach (['iss', 'aud', 'exp', 'iat', 'sub'] as $claim) {
            if (!isset($claims[$claim])) {
                $missing[] = $claim;
            }
        }

        if ($issuer !== null && isset($claims['iss']) && $claims['iss'] !== $issuer) {
            $problems[] = sprintf('iss is "%s" rather than the issuer "%s"', (string) $claims['iss'], $issuer);
        }

        if (isset($claims['exp']) && (int) $claims['exp'] <= time()) {
            $problems[] = 'exp is already in the past';
        }

        if (isset($claims['client_id']) && $claims['client_id'] !== $clientId) {
            $problems[] = 'client_id names a different client';
        }

        if ($scope !== null && isset($claims['scope']) && $claims['scope'] !== $scope) {
            $problems[] = sprintf('scope is "%s" but the token response said "%s"', (string) $claims['scope'], $scope);
        }

        if ($missing !== []) {
            $problems[] = 'missing ' . implode(', ', $missing);
        }

        return Verdict::check('S423', $problems === [],
            'iss, aud, exp, iat and sub are all present and consistent with the token response.',
            'The claims are not what a resource server needs: ' . implode('; ', $problems) . '.');
    }

    /**
     * @param list<string> $ids
     * @return list<Verdict>
     */
    private static function skipAll(string $reason, array $ids): array
    {
        return array_map(static fn (string $id): Verdict => Verdict::skipped($id, $reason), $ids);
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $segment): ?array
    {
        $json = base64_decode(strtr($segment, '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }
}
