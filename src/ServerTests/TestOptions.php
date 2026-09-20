<?php

declare(strict_types=1);

namespace IndieAuthRocks\ServerTests;

/**
 * What a person can do about one test, on its own page, right now.
 *
 * The run is a queue, but almost nothing about it is really ordered: the
 * browser checks are steps in their own right and can run in any order, and a
 * step in flight can be set aside. This says which of those applies, so the
 * page can offer it rather than explaining why it cannot.
 */
final class TestOptions
{
    /** Nothing can be done from here; `reason` says why. */
    public const NONE = 'none';
    /** The step covering this test is the one in progress: go to its page. */
    public const CONTINUE = 'continue';
    /** Put the step covering this test at the front of the queue. */
    public const RUN = 'run';

    public function __construct(
        public readonly string $kind,
        public readonly ?string $step = null,
        public readonly string $label = '',
        public readonly string $hint = '',
        public readonly ?string $reason = null,
        /** Whether the step in progress can be thrown away and started again. */
        public readonly bool $restartable = false,
    ) {
    }

    public static function none(string $reason): self
    {
        return new self(self::NONE, reason: $reason);
    }

    public static function continueStep(string $step, string $hint, bool $restartable): self
    {
        return new self(self::CONTINUE, $step, 'Continue this test', $hint, restartable: $restartable);
    }

    public static function run(string $step, string $label, string $hint): self
    {
        return new self(self::RUN, $step, $label, $hint);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind'        => $this->kind,
            'step'        => $this->step,
            'label'       => $this->label,
            'hint'        => $this->hint,
            'reason'      => $this->reason,
            'restartable' => $this->restartable,
        ];
    }
}
