<?php

declare(strict_types=1);

namespace IndieAuthRocks\Identity;

/** Someone who signed in, identified by their profile URL. */
final class Tester
{
    public function __construct(
        public readonly int $id,
        public readonly string $me,
        public readonly string $createdAt,
        public readonly ?string $lastLoginAt = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:          (int) $row['id'],
            me:          (string) $row['me'],
            createdAt:   (string) $row['created_at'],
            lastLoginAt: isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
        );
    }

    /** The profile URL without scheme or trailing slash, for display. */
    public function displayName(): string
    {
        return rtrim((string) preg_replace('#^https?://#', '', $this->me), '/');
    }
}
