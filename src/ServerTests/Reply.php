<?php

declare(strict_types=1);

namespace IndieAuthRocks\ServerTests;

use IndieAuthRocks\Fetch\FetchResult;

/** A response from the server under test, or the reason there was none. */
final class Reply
{
    public function __construct(
        public readonly ?FetchResult $result,
        public readonly ?string $error = null,
    ) {
    }

    public function status(): int
    {
        return $this->result?->status ?? 0;
    }

    public function isSuccess(): bool
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    public function isClientError(): bool
    {
        return $this->status() >= 400 && $this->status() < 500;
    }

    /** @return array<string, mixed>|null */
    public function json(): ?array
    {
        return $this->result?->json();
    }

    public function string(string $key): ?string
    {
        $value = $this->json()[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function header(string $name): ?string
    {
        return $this->result?->header($name);
    }

    /** "HTTP 400 invalid_grant" style description, for summaries. */
    public function describe(): string
    {
        if ($this->result === null) {
            return 'no response (' . ($this->error ?? 'request failed') . ')';
        }

        $error = $this->string('error');

        return 'HTTP ' . $this->status() . ($error !== null ? ' ' . $error : '');
    }
}
