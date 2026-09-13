<?php

declare(strict_types=1);

namespace IndieAuthRocks\Identity;

use LogicException;

/**
 * Who is making this request: the session, and the account it resolves to.
 *
 * `user` is null whenever there is nobody usable behind the session — no
 * session, an anonymous one, or one naming an account that has since been
 * deleted.
 */
final class Visitor
{
    public function __construct(
        public readonly ?Session $session = null,
        public readonly ?Tester $user = null,
    ) {
    }

    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    /** The session, which callers into Guard::begin() know exists. */
    public function session(): Session
    {
        if ($this->session === null) {
            throw new LogicException('This request has no session. Use Guard::begin() for pages that need one.');
        }

        return $this->session;
    }

    public function user(): Tester
    {
        if ($this->user === null) {
            throw new LogicException('This request has no authenticated user.');
        }

        return $this->user;
    }
}
