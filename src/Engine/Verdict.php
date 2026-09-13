<?php

declare(strict_types=1);

namespace IndieAuthRocks\Engine;

/** The outcome of one test, with a sentence explaining it. */
final class Verdict
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $testId,
        public readonly Outcome $outcome,
        public readonly string $summary = '',
        public readonly array $details = [],
    ) {
    }

    public static function pass(string $testId, string $summary = '', array $details = []): self
    {
        return new self($testId, Outcome::PASS, $summary, $details);
    }

    public static function fail(string $testId, string $summary, array $details = []): self
    {
        return new self($testId, Outcome::FAIL, $summary, $details);
    }

    public static function warning(string $testId, string $summary, array $details = []): self
    {
        return new self($testId, Outcome::WARNING, $summary, $details);
    }

    public static function skipped(string $testId, string $summary): self
    {
        return new self($testId, Outcome::SKIPPED, $summary);
    }

    public static function error(string $testId, string $summary): self
    {
        return new self($testId, Outcome::ERROR, $summary);
    }

    /**
     * The implementation did not do what the test checks. What that means
     * depends on the test's level: a failure for a MUST, a warning for a
     * SHOULD, and only a note for anything optional.
     */
    public static function unmet(string $testId, string $summary, array $details = []): self
    {
        $level = Catalog::find($testId)?->level ?? Level::MUST;

        return new self($testId, $level->unmet(), $summary, $details);
    }

    public static function check(string $testId, bool $ok, string $passSummary, string $unmetSummary): self
    {
        return $ok ? self::pass($testId, $passSummary) : self::unmet($testId, $unmetSummary);
    }

    /** Whether this verdict is worse than another, for tests checked repeatedly. */
    public function isWorseThan(?self $other): bool
    {
        if ($other === null) {
            return true;
        }

        $rank = static fn (Outcome $o): int => match ($o) {
            Outcome::FAIL        => 5,
            Outcome::ERROR       => 4,
            Outcome::WARNING     => 3,
            Outcome::PASS        => 2,
            Outcome::SKIPPED     => 1,
            Outcome::NEEDS_INPUT, Outcome::NOT_RUN => 0,
        };

        return $rank($this->outcome) > $rank($other->outcome);
    }
}
