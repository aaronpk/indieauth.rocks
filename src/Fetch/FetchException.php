<?php

declare(strict_types=1);

namespace IndieAuthRocks\Fetch;

use RuntimeException;
use Throwable;

/**
 * A metadata document could not be retrieved.
 *
 * Carries a `retryable` flag so the caller can tell a transient network problem
 * from a document that will never be acceptable. Only the latter is worth
 * remembering.
 */
final class FetchException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function refused(string $message): self
    {
        return new self($message, retryable: false);
    }

    public static function transient(string $message): self
    {
        return new self($message, retryable: true);
    }
}
