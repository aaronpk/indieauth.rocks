<?php

declare(strict_types=1);

namespace IndieAuthRocks\Checkup;

/**
 * How one step of a checkup turned out.
 *
 * Deliberately not Engine\Outcome: that carries a test's Level and grading
 * semantics, and totting up passes and failures is the opposite of what this
 * page is for. A checkup says what happened and, at most once, what to fix.
 */
enum StepState: string
{
    case Ok      = 'ok';
    case Note    = 'note';
    case Problem = 'problem';
    /** Something we cannot see, because it happened on the person's own server. */
    case Unknown = 'unknown';
    case Pending = 'pending';
    /** Not attempted, because an earlier step stopped the login. */
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Ok      => 'Worked',
            self::Note    => 'Worth knowing',
            self::Problem => 'This is the problem',
            self::Unknown => 'Out of our sight',
            self::Pending => 'Not yet',
            self::Skipped => 'Not reached',
        };
    }

    /** A class on the existing badge styles in app.css. */
    public function badgeClass(): string
    {
        return 'badge checkup-' . $this->value;
    }

    public function stopsTheLogin(): bool
    {
        return $this === self::Problem;
    }
}
