<?php

declare(strict_types=1);

namespace IndieAuthRocks\Identity;

use IndieAuthRocks\Crypto\Random;

/**
 * A browser session.
 *
 * A session exists before login: an anonymous session carries the CSRF secret
 * for the login form, and from phase 5 will carry the pending authorization
 * request. `sub` is null until the user authenticates.
 *
 * `authTime` is kept separately from the session TTL. The TTL slides with
 * activity, but `authTime` records when credentials were actually presented,
 * which is what `auth_time` and `max_age` have to be judged against.
 */
final class Session
{
    /** RFC 8176 authentication method reference for a password. */
    public const AMR_PASSWORD = 'pwd';

    /**
     * @param list<string>         $amr
     * @param array<string, mixed> $flash One-shot data carried across a redirect.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $csrf,
        public readonly int $createdAt,
        public readonly ?string $sub = null,
        public readonly ?int $authTime = null,
        public readonly array $amr = [],
        public readonly array $flash = [],
    ) {
    }

    /**
     * Attach data to be read once, after a redirect.
     *
     * This exists chiefly for account recovery: the generated temporary password
     * has to survive a POST-then-redirect without travelling in the URL, where it
     * would land in access logs and browser history.
     *
     * @param array<string, mixed> $flash
     */
    public function withFlash(array $flash): self
    {
        return new self(
            id:        $this->id,
            csrf:      $this->csrf,
            createdAt: $this->createdAt,
            sub:       $this->sub,
            authTime:  $this->authTime,
            amr:       $this->amr,
            flash:     $flash,
        );
    }

    public static function createAnonymous(): self
    {
        return new self(
            id:        Random::token(32),
            csrf:      Random::token(32),
            createdAt: time(),
        );
    }

    public function isAuthenticated(): bool
    {
        return $this->sub !== null;
    }

    /**
     * A new session for a user who has just authenticated.
     *
     * The identifier is regenerated rather than reused: a session fixation
     * attack works by getting a known identifier into the victim's browser
     * before login, so the pre-login identifier must never become an
     * authenticated one.
     *
     * @param list<string> $amr
     */
    public function promoteTo(string $sub, array $amr = [self::AMR_PASSWORD]): self
    {
        return new self(
            id:        Random::token(32),
            csrf:      Random::token(32),
            createdAt: time(),
            sub:       $sub,
            authTime:  time(),
            amr:       $amr,
            // Deliberately not carried over: anything flashed before login
            // belonged to the anonymous session.
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(string $id, array $data): ?self
    {
        if (!isset($data['csrf']) || !is_string($data['csrf']) || $data['csrf'] === '') {
            return null;
        }

        /** @var list<string> $amr */
        $amr = array_values(array_filter(
            (array) ($data['amr'] ?? []),
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        ));

        /** @var array<string, mixed> $flash */
        $flash = is_array($data['flash'] ?? null) ? $data['flash'] : [];

        return new self(
            id:        $id,
            csrf:      $data['csrf'],
            createdAt: (int) ($data['created_at'] ?? time()),
            sub:       isset($data['sub']) && is_string($data['sub']) ? $data['sub'] : null,
            authTime:  isset($data['auth_time']) ? (int) $data['auth_time'] : null,
            amr:       $amr,
            flash:     $flash,
        );
    }

    /**
     * The stored form. The session identifier is the Redis key, so it is
     * deliberately not part of the value.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'csrf'       => $this->csrf,
            'created_at' => $this->createdAt,
            'sub'        => $this->sub,
            'auth_time'  => $this->authTime,
            'amr'        => $this->amr,
            'flash'      => $this->flash,
        ];
    }
}
