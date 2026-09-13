<?php

declare(strict_types=1);

namespace IndieAuthRocks\Config;

use RuntimeException;
use Throwable;

/**
 * The server is misconfigured, as opposed to having hit a bug.
 *
 * These carry two messages. `getMessage()` is safe to show a visitor and says
 * what to do about it; `$detail` adds the filesystem paths and specifics, and
 * goes only to the log. That split is what lets a misconfigured server explain
 * itself without turning its error page into a directory listing.
 */
final class ConfigurationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $detail = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function logMessage(): string
    {
        return $this->detail === null
            ? $this->getMessage()
            : $this->getMessage() . ' (' . $this->detail . ')';
    }
}
