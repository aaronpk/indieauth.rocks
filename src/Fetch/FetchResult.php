<?php

declare(strict_types=1);

namespace IndieAuthRocks\Fetch;

final class FetchResult
{
    /**
     * @param array<string, list<string>> $headers        Lowercased names; repeated headers kept.
     * @param list<string>                $redirects      URLs visited before this one.
     * @param array<string, string>       $requestHeaders
     */
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        public readonly array $redirects = [],
        public readonly string $method = 'GET',
        public readonly array $requestHeaders = [],
        public readonly ?string $requestBody = null,
        public readonly int $durationMs = 0,
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

    /** @return array<string, mixed>|null The body as a JSON object, or null. */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true, 32);

        return is_array($decoded) && ($decoded === [] || !array_is_list($decoded)) ? $decoded : null;
    }

    /** @param list<string> $redirects */
    public function withRedirects(array $redirects): self
    {
        return new self($this->url, $this->status, $this->body, $this->headers, $redirects, $this->method, $this->requestHeaders, $this->requestBody, $this->durationMs);
    }
}
