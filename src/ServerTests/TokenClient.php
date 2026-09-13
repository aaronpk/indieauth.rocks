<?php

declare(strict_types=1);

namespace IndieAuthRocks\ServerTests;

use IndieAuthRocks\Crypto\Random;
use IndieAuthRocks\Fetch\FetchException;
use IndieAuthRocks\Fetch\HttpClient;
use IndieAuthRocks\Fetch\HttpRequest;

/** The back-channel requests an IndieAuth client makes. */
final class TokenClient
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    public function send(HttpRequest $request): Reply
    {
        try {
            return new Reply($this->http->send($request));
        } catch (FetchException $e) {
            return new Reply(null, $e->getMessage());
        }
    }

    /**
     * Redeem an authorization code at the token or authorization endpoint.
     *
     * @param array<string, string|null> $overrides Null removes a parameter.
     */
    public function redeem(string $endpoint, string $code, string $clientId, string $redirectUri, ?string $verifier, array $overrides = []): Reply
    {
        $params = array_filter(array_merge([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'code_verifier' => $verifier,
        ], $overrides), static fn (?string $v): bool => $v !== null);

        return $this->send(HttpRequest::postForm($endpoint, $params));
    }

    public function refresh(string $endpoint, string $refreshToken, string $clientId, ?string $scope = null): Reply
    {
        $params = ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'client_id' => $clientId];

        if ($scope !== null) {
            $params['scope'] = $scope;
        }

        return $this->send(HttpRequest::postForm($endpoint, $params));
    }

    public function revoke(string $endpoint, string $token): Reply
    {
        return $this->send(HttpRequest::postForm($endpoint, ['token' => $token]));
    }

    public function userinfo(string $endpoint, ?string $token): Reply
    {
        return $this->send(HttpRequest::get($endpoint, 'application/json', $token === null ? [] : ['Authorization' => 'Bearer ' . $token]));
    }

    public function introspect(string $endpoint, string $token, ?string $credential): Reply
    {
        return $this->send(HttpRequest::postForm($endpoint, ['token' => $token], $credential === null ? [] : ['Authorization' => 'Bearer ' . $credential]));
    }

    /** A value shaped like a real code or token, which no server issued. */
    public static function madeUp(): string
    {
        return Random::token(32);
    }
}
