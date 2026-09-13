<?php

declare(strict_types=1);

namespace IndieAuthRocks\Engine;

/**
 * How strongly the specification (or security best practice) asks for the
 * behavior a test checks. It decides what not meeting it means.
 */
enum Level: string
{
    case MUST   = 'must';
    case SHOULD = 'should';
    case MAY    = 'may';
    case INFO   = 'info';

    /** The outcome when an implementation does not do what the test checks. */
    public function unmet(): Outcome
    {
        return match ($this) {
            self::MUST   => Outcome::FAIL,
            self::SHOULD => Outcome::WARNING,
            self::MAY, self::INFO => Outcome::PASS,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MUST   => 'Required',
            self::SHOULD => 'Recommended',
            self::MAY    => 'Optional',
            self::INFO   => 'Informational',
        };
    }
}
