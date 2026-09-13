<?php

declare(strict_types=1);

namespace IndieAuthRocks\Drivers;

/** Where a driven browser ended up. */
final class DriverOutcome
{
    public const LANDED  = 'landed';
    public const ERROR   = 'error';
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $kind,
        public readonly ?string $landedUrl = null,
        public readonly int $status = 0,
        public readonly string $html = '',
        public readonly ?string $consentHtml = null,
    ) {
    }

    public static function landed(string $url, ?string $consentHtml): self
    {
        return new self(self::LANDED, $url, consentHtml: $consentHtml);
    }

    public static function stopped(string $kind, int $status, string $html, ?string $consentHtml = null): self
    {
        return new self($kind, null, $status, $html, $consentHtml);
    }
}
