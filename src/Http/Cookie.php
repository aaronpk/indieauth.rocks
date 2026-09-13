<?php

declare(strict_types=1);

namespace IndieAuthRocks\Http;

/**
 * A Set-Cookie value.
 *
 * Defaults are the secure ones: HttpOnly, SameSite=Lax, host-scoped, session
 * lifetime. SameSite=Lax rather than Strict because the OAuth redirect back
 * from a client is a cross-site top-level navigation and must carry the
 * session cookie.
 */
final class Cookie
{
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly ?int $expires = null,
        public readonly string $path = '/',
        public readonly bool $secure = false,
        public readonly bool $httpOnly = true,
        public readonly string $sameSite = 'Lax',
    ) {
    }

    public static function expire(string $name, bool $secure = false, string $path = '/'): self
    {
        return new self($name, '', expires: 1, path: $path, secure: $secure);
    }

    public function toHeader(): string
    {
        $parts = [rawurlencode($this->name) . '=' . rawurlencode($this->value)];

        if ($this->expires !== null) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $this->expires);
            $parts[] = 'Max-Age=' . max(0, $this->expires - time());
        }

        $parts[] = 'Path=' . $this->path;

        if ($this->secure) {
            $parts[] = 'Secure';
        }
        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=' . $this->sameSite;

        return implode('; ', $parts);
    }
}
