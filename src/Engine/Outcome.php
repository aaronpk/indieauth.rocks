<?php

declare(strict_types=1);

namespace IndieAuthRocks\Engine;

enum Outcome: string
{
    case PASS        = 'pass';
    case FAIL        = 'fail';
    case WARNING     = 'warning';
    case SKIPPED     = 'skipped';
    case NEEDS_INPUT = 'needs_input';
    case NOT_RUN     = 'not_run';
    case ERROR       = 'error';

    public function label(): string
    {
        return match ($this) {
            self::PASS        => 'Passed',
            self::FAIL        => 'Failed',
            self::WARNING     => 'Warning',
            self::SKIPPED     => 'Skipped',
            self::NEEDS_INPUT => 'Needs your answer',
            self::NOT_RUN     => 'Not run',
            self::ERROR       => 'Could not run',
        };
    }

    /** Whether this outcome is final for a test, as opposed to pending. */
    public function isFinal(): bool
    {
        return !in_array($this, [self::NEEDS_INPUT, self::NOT_RUN], true);
    }
}
