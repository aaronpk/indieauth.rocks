<?php

declare(strict_types=1);

namespace IndieAuthRocks\Engine;

/** What a test needs from the person running it. */
enum Mode: string
{
    /** Checked automatically from HTTP requests and responses. */
    case AUTO = 'auto';

    /** Sends the browser to the server with a request it should refuse; the person cancels or reports what they saw. */
    case PROBE = 'probe';

    /** Needs the person to approve an authorization request. */
    case APPROVE = 'approve';

    /** Needs the person to answer a question about what they saw. */
    case MANUAL = 'manual';

    /** Needs the person to sign in to their client with a test profile URL. */
    case SIGNIN = 'signin';

    public function label(): string
    {
        return match ($this) {
            self::AUTO    => 'Automatic',
            self::PROBE   => 'Browser check',
            self::APPROVE => 'Needs approval',
            self::MANUAL  => 'Asks you',
            self::SIGNIN  => 'Sign in with your client',
        };
    }
}
