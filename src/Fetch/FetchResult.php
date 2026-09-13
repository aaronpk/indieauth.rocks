<?php

declare(strict_types=1);

namespace IndieAuthRocks\Fetch;

final class FetchResult
{
    /**
     * @param array<string, list<string>> $headers Lowercased names; repeated headers kept.
     * @param list<string>                $redirects URLs visited before this one.
     */
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        public readonly array $redirects = [],
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** @return list<string> */
    public function headerValues(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    /** The media type without parameters, lowercased. */
    public function contentType(): string
    {
        return strtolower(trim(explode(';', (string) $this->header('content-type'))[0]));
    }

    public function isJson(): bool
    {
        $type = $this->contentType();

        return $type === 'application/json' || str_ends_with($type, '+json');
    }

    public function isRedirect(): bool
    {
        return in_array($this->status, [301, 302, 303, 307, 308], true) && $this->header('location') !== null;
    }

    /**
     * Seconds the response may be cached per Cache-Control, clamped to
     * [$min, $max]. Zero for no-store/no-cache, null when unstated.
     */
    public function cacheTtl(int $min, int $max): ?int
    {
        $control = (string) $this->header('cache-control');

        if (preg_match('/no-store|no-cache/i', $control) === 1) {
            return 0;
        }

        if (preg_match('/max-age\s*=\s*(\d+)/i', $control, $m) === 1) {
            return max($min, min((int) $m[1], $max));
        }

        return null;
    }

    /** @param list<string> $redirects */
    public function withRedirects(array $redirects): self
    {
        return new self($this->url, $this->status, $this->body, $this->headers, $redirects);
    }
}
