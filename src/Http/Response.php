<?php

declare(strict_types=1);

namespace IndieAuthRocks\Http;

/**
 * An immutable HTTP response. Headers may repeat (Set-Cookie), so values are
 * stored as lists.
 */
final class Response
{
    /** @param array<string, list<string>> $headers */
    private function __construct(
        public readonly int $status,
        public readonly string $body,
        private readonly array $headers = [],
        /**
         * Whether Kernel leaves `form-action` out of this page's policy.
         *
         * The client-test consent form ends at the client's redirect URI.
         * Chromium and WebKit check `form-action` against every redirect that
         * follows a submission, and clients commonly redirect again from their
         * callback or use a custom-scheme redirect URI. No list of origins can
         * cover that chain, so consent pages drop the directive.
         */
        private readonly bool $formActionUnrestricted = false,
    ) {
    }

    /** @param array<string, string> $headers */
    public static function make(int $status = 200, string $body = '', array $headers = []): self
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = [$value];
        }

        return new self($status, $body, $normalized);
    }

    public static function html(string $body, int $status = 200): self
    {
        return self::make($status, $body, ['content-type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $body, int $status = 200): self
    {
        return self::make($status, $body, ['content-type' => 'text/plain; charset=utf-8']);
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return self::make($status, $body === false ? '{}' : $body, [
            'content-type' => 'application/json; charset=utf-8',
        ]);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return self::make($status, '', ['location' => $location]);
    }

    /**
     * A 303 redirect, for use after a POST.
     *
     * OAuth 2.1 §7.5.4 forbids 307 for any redirect of a request that might
     * carry user credentials, because 307 preserves the method and body — the
     * browser would re-POST the password to the client's redirect URI. It
     * recommends 303, which forces the follow-up to be a GET and drops the body.
     * 302 is permitted but leaves the method technically at the user agent's
     * discretion.
     */
    public static function seeOther(string $location): self
    {
        return self::redirect($location, 303);
    }

    public static function noContent(): self
    {
        return self::make(204);
    }

    /**
     * Let a form on this page end wherever the redirects after it lead.
     *
     * Only for pages whose form finishes an authorization flow. Templates are
     * escaped and no inline script runs, so `form-action` is defence in depth
     * the rest of the site keeps.
     */
    public function withoutFormActionRestriction(): self
    {
        return new self($this->status, $this->body, $this->headers, true);
    }

    public function formActionUnrestricted(): bool
    {
        return $this->formActionUnrestricted;
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[strtolower($name)] = [$value];

        // Carried through, or a later withNoStore() would silently restore the
        // form-action restriction a controller had already lifted.
        return new self($this->status, $this->body, $headers, $this->formActionUnrestricted);
    }

    /** Append rather than replace. Required for multiple Set-Cookie headers. */
    public function withAddedHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[strtolower($name)][] = $value;

        return new self($this->status, $this->body, $headers, $this->formActionUnrestricted);
    }

    public function withCookie(Cookie $cookie): self
    {
        return $this->withAddedHeader('set-cookie', $cookie->toHeader());
    }

    /**
     * Responses containing tokens or user data must never be cached, per
     * RFC 6749 §5.1.
     */
    public function withNoStore(): self
    {
        return $this->withHeader('cache-control', 'no-store')->withHeader('pragma', 'no-cache');
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** @return array<string, list<string>> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $values) {
                $first = true;
                foreach ($values as $value) {
                    header($name . ': ' . $value, $first);
                    $first = false;
                }
            }
        }

        echo $this->body;
    }
}
