<?php

declare(strict_types=1);

namespace IndieAuthRocks\Checkup;

/**
 * The one sentence at the top of a checkup.
 *
 * Someone arrives here because an app told them "login failed" and nothing
 * else. They should not have to read nine steps to find out which one went
 * wrong, so the first problem is repeated at the top, with its fix.
 */
final class Summary
{
    private function __construct(
        public readonly StepState $state,
        public readonly string $headline,
        public readonly ?string $fix = null,
        public readonly ?string $stepId = null,
        public readonly int $notes = 0,
    ) {
    }

    /** @param list<Step> $steps */
    public static function of(array $steps, string $phase, ?string $me, string $profileUrl): self
    {
        $notes = 0;

        foreach ($steps as $step) {
            if ($step->state === StepState::Note) {
                ++$notes;
            }
        }

        foreach ($steps as $step) {
            if ($step->state->stopsTheLogin()) {
                return new self(StepState::Problem, $step->headline, $step->fix, $step->id, $notes);
            }
        }

        if ($phase !== Checkup::FINISHED) {
            return new self(
                StepState::Pending,
                $phase === Checkup::SENT
                    ? 'Your login is half done: you have not come back from your server yet.'
                    : 'Your website checks out so far. Sign in with your server to check the rest.',
                null,
                null,
                $notes,
            );
        }

        $address = $me ?? $profileUrl;

        return new self(
            StepState::Ok,
            $address === ''
                ? 'Your login works.'
                : sprintf('Your login works. Sign in to apps as %s.', $address),
            null,
            null,
            $notes,
        );
    }

    /** What to say about the notes, when there are any. */
    public function notesLine(): ?string
    {
        return match (true) {
            $this->notes === 0 => null,
            $this->notes === 1 => 'One other thing is worth knowing.',
            default            => sprintf('%d other things are worth knowing.', $this->notes),
        };
    }
}
