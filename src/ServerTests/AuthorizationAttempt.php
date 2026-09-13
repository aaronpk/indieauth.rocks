<?php

declare(strict_types=1);

namespace IndieAuthRocks\ServerTests;

use IndieAuthRocks\Crypto\Random;
use IndieAuthRocks\Fetch\HttpRequest;
use IndieAuthRocks\IndieAuth\Pkce;

/**
 * One trip to the authorization endpoint. Parameters are kept as ordered
 * pairs so a test can repeat one.
 */
final class AuthorizationAttempt
{
    public const APPROVE = 'approve';
    public const CANCEL  = 'cancel';

    /** @param list<array{0: string, 1: string}> $params */
    public function __construct(
        public readonly string $id,
        public readonly string $step,
        public readonly array $params,
        public readonly string $intent,
        public readonly ?string $verifier = null,
    ) {
    }

    /**
     * A well-formed request, which a step then alters.
     *
     * @param array<string, string|null> $overrides Null removes a parameter.
     */
    public static function build(string $step, string $intent, string $clientId, string $redirectUri, array $overrides = []): self
    {
        $id       = Random::base32(12);
        $verifier = Random::token(48);

        $params = array_merge([
            'response_type'         => 'code',
            'client_id'             => $clientId,
            'redirect_uri'          => $redirectUri,
            // Characters that are easy to lose in careless encoding.
            'state'                 => $id . '~._-+/=!:@',
            'code_challenge'        => Pkce::challengeFor($verifier),
            'code_challenge_method' => Pkce::METHOD_S256,
        ], $overrides);

        $pairs = [];
        foreach ($params as $name => $value) {
            if ($value !== null) {
                $pairs[] = [$name, $value];
            }
        }

        return new self($id, $step, $pairs, $intent, $verifier);
    }

    /** @param array{0: string, 1: string} $pair */
    public function withExtraParam(array $pair): self
    {
        return new self($this->id, $this->step, [...$this->params, $pair], $this->intent, $this->verifier);
    }

    public function param(string $name): ?string
    {
        foreach ($this->params as [$key, $value]) {
            if ($key === $name) {
                return $value;
            }
        }

        return null;
    }

    public function url(string $authorizationEndpoint): string
    {
        $query = HttpRequest::encodeForm($this->params);

        return $authorizationEndpoint . (str_contains($authorizationEndpoint, '?') ? '&' : '?') . $query;
    }

    /** @return array<string, mixed> Without the verifier, which is kept in the vault. */
    public function toArray(): array
    {
        return ['id' => $this->id, 'step' => $this->step, 'params' => $this->params, 'intent' => $this->intent];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?string $verifier): self
    {
        return new self((string) $data['id'], (string) $data['step'], (array) $data['params'], (string) $data['intent'], $verifier);
    }
}
