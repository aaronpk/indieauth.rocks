<?php

declare(strict_types=1);

namespace IndieAuthRocks\Drivers;

use IndieAuthRocks\ServerTests\Action;
use IndieAuthRocks\ServerTests\Question;

/**
 * Plays the person in the browser, so the server suite can run unattended
 * against a server whose sign-in and consent screens the driver understands.
 */
interface UserAgentDriver
{
    /** Carry out a NAVIGATE action: follow the request through sign-in and consent. */
    public function navigate(Action $action): DriverOutcome;

    /** Answer a question from what was seen, or null if the driver cannot tell. */
    public function answer(Question $question, ?DriverOutcome $last): ?string;
}
