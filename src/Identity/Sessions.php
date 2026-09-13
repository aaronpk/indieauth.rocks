<?php

declare(strict_types=1);

namespace IndieAuthRocks\Identity;

use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Http\Cookie;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Storage\RedisStore;

/**
 * Session storage and the session cookie.
 */
final class Sessions
{
    public const COOKIE = 'iar_session';

    public function __construct(
        private readonly RedisStore $redis,
        private readonly SiteConfig $config,
    ) {
    }

    /** The session named by the request cookie, or null. */
    public function load(Request $request): ?Session
    {
        $id = $request->cookie(self::COOKIE);

        // Reject anything that is not a token this server would have minted,
        // so a hostile cookie value never reaches Redis as a key.
        if ($id === null || preg_match('/^[A-Za-z0-9_-]{43}$/', $id) !== 1) {
            return null;
        }

        $data = $this->redis->getJson($this->key($id));

        return $data === null ? null : Session::fromArray($id, $data);
    }

    /** Persist a session and refresh its expiry. */
    public function save(Session $session): void
    {
        $this->redis->setJson($this->key($session->id), $session->toArray(), $this->config->sessionLifetime);
    }

    public function destroy(Session $session): void
    {
        $this->redis->delete($this->key($session->id));
    }

    /**
     * Replace a session with its authenticated successor.
     *
     * The old record is deleted, so the pre-login identifier stops working the
     * moment login succeeds.
     *
     * @param list<string> $amr
     */
    public function promote(Session $session, string $sub, array $amr = [Session::AMR_PASSWORD]): Session
    {
        $promoted = $session->promoteTo($sub, $amr);

        $this->destroy($session);
        $this->save($promoted);

        return $promoted;
    }

    /** Every session belonging to a user, for admin revocation. */
    public function forgetAllFor(string $sub): int
    {
        $removed = 0;

        foreach ($this->redis->scan('sess:*') as $key) {
            $data = $this->redis->getJson($key);
            if (($data['sub'] ?? null) === $sub) {
                $this->redis->delete($key);
                $removed++;
            }
        }

        return $removed;
    }

    /** How many sessions a user currently has, for the admin interface. */
    public function countFor(string $sub): int
    {
        $count = 0;

        foreach ($this->redis->scan('sess:*') as $key) {
            $data = $this->redis->getJson($key);

            if (($data['sub'] ?? null) === $sub) {
                $count++;
            }
        }

        return $count;
    }

    public function attach(Response $response, Session $session): Response
    {
        return $response->withCookie(new Cookie(
            name:    self::COOKIE,
            value:   $session->id,
            expires: time() + $this->config->sessionLifetime,
            secure:  $this->config->secureCookies(),
        ));
    }

    public function clearCookie(Response $response): Response
    {
        return $response->withCookie(Cookie::expire(self::COOKIE, secure: $this->config->secureCookies()));
    }

    private function key(string $id): string
    {
        return 'sess:' . $id;
    }
}
