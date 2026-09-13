<?php

declare(strict_types=1);

namespace IndieAuthRocks\Storage;

use RuntimeException;
use Throwable;

/**
 * Redis could not be reached.
 *
 * A distinct type so the Kernel can answer 503 with something actionable
 * instead of a generic 500. Predis exceptions are translated into this at the
 * storage boundary, so nothing above this layer needs to know which client
 * library is in use.
 */
final class StorageUnavailableException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
