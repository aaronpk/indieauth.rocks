<?php

declare(strict_types=1);

namespace IndieAuthRocks\Engine;

/** One pass through a test suite. State is a JSON document the runner owns. */
final class Run
{
    public const RUNNING   = 'running';
    public const WAITING   = 'waiting';
    public const COMPLETE  = 'complete';
    public const ABANDONED = 'abandoned';

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $state
     */
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $runKey,
        public readonly ?int $testerId,
        public readonly string $kind,
        public readonly ?string $targetUrl,
        public readonly string $mode,
        public readonly array $options,
        public readonly string $status,
        public array $state,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:        (int) $row['id'],
            publicId:  (string) $row['public_id'],
            runKey:    (string) $row['run_key'],
            testerId:  isset($row['tester_id']) ? (int) $row['tester_id'] : null,
            kind:      (string) $row['kind'],
            targetUrl: isset($row['target_url']) ? (string) $row['target_url'] : null,
            mode:      (string) $row['mode'],
            options:   json_decode((string) ($row['options_json'] ?? '{}'), true) ?: [],
            status:    (string) $row['status'],
            state:     json_decode((string) ($row['state_json'] ?? '{}'), true) ?: [],
            createdAt: (string) $row['created_at'],
        );
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::COMPLETE, self::ABANDONED], true);
    }
}
