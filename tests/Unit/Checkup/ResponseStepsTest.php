<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\Checkup;

use IndieAuthRocks\Checkup\Step;
use IndieAuthRocks\Checkup\StepState;
use IndieAuthRocks\Checkup\Steps\ResponseSteps;
use PHPUnit\Framework\TestCase;

final class ResponseStepsTest extends TestCase
{
    private const STATE  = 'the-state-we-sent';
    private const ISSUER = 'https://server.example/';

    public function test_a_correct_reply_passes_both_steps(): void
    {
        $steps = $this->from(['state' => self::STATE, 'iss' => self::ISSUER, 'code' => 'abc']);

        $this->assertSame(StepState::Unknown, $this->step($steps, 'approval')->state, 'consent happens out of sight');
        $this->assertSame(StepState::Ok, $this->step($steps, 'return')->state);
    }

    public function test_cancelling_is_a_note_with_no_blame(): void
    {
        $steps = $this->from(['error' => 'access_denied', 'state' => self::STATE, 'iss' => self::ISSUER]);
        $step  = $this->step($steps, 'approval');

        $this->assertSame(StepState::Note, $step->state);
        $this->assertStringContainsString('Nothing is wrong', (string) $step->meaning);
        $this->assertSame(StepState::Ok, $this->step($steps, 'return')->state, 'a refusal is still a well-formed reply');
    }

    public function test_an_error_before_consent_is_a_problem_and_quotes_the_server(): void
    {
        $steps = $this->from([
            'error'             => 'invalid_request',
            'error_description' => 'state is required',
            'state'             => self::STATE,
            'iss'               => self::ISSUER,
        ]);
        $step = $this->step($steps, 'approval');

        $this->assertSame(StepState::Problem, $step->state);
        $this->assertStringContainsString('state is required', $step->headline);
    }

    public function test_a_missing_state_stops_the_check_and_says_why(): void
    {
        $step = $this->step($this->from(['code' => 'abc', 'iss' => self::ISSUER]), 'return');

        $this->assertSame(StepState::Problem, $step->state);
        $this->assertStringContainsString('not safe to use', (string) $step->meaning);
    }

    public function test_a_changed_state_stops_the_check(): void
    {
        $step = $this->step($this->from(['state' => 'something-else', 'code' => 'abc', 'iss' => self::ISSUER]), 'return');

        $this->assertSame(StepState::Problem, $step->state);
        $this->assertStringContainsString('different state value', $step->headline);
    }

    public function test_a_wrong_iss_names_both_values(): void
    {
        $step = $this->step($this->from(['state' => self::STATE, 'iss' => 'https://elsewhere.example/', 'code' => 'abc']), 'return');

        $this->assertSame(StepState::Problem, $step->state);
        $this->assertStringContainsString('https://elsewhere.example/', $step->headline);
        $this->assertStringContainsString(self::ISSUER, $step->headline);
    }

    public function test_a_missing_iss_is_only_a_note(): void
    {
        $step = $this->step($this->from(['state' => self::STATE, 'code' => 'abc']), 'return');

        $this->assertSame(StepState::Note, $step->state);
        $this->assertStringContainsString('no iss value', $step->headline);
    }

    public function test_neither_code_nor_error_is_a_problem(): void
    {
        $step = $this->step($this->from(['state' => self::STATE, 'iss' => self::ISSUER]), 'return');

        $this->assertSame(StepState::Problem, $step->state);
        $this->assertStringContainsString('neither a one-time code nor an error', $step->headline);
    }

    public function test_an_instant_return_is_explained_as_a_remembered_approval(): void
    {
        $steps = ResponseSteps::from(['state' => self::STATE, 'iss' => self::ISSUER, 'code' => 'abc'], self::STATE, self::ISSUER, 1);

        $this->assertStringContainsString('remembered an approval', (string) $this->step($steps, 'approval')->meaning);
    }

    /**
     * @param  array<string, string> $query
     * @return list<Step>
     */
    private function from(array $query): array
    {
        return ResponseSteps::from($query, self::STATE, self::ISSUER, 30);
    }

    /** @param list<Step> $steps */
    private function step(array $steps, string $id): Step
    {
        foreach ($steps as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }

        $this->fail('No ' . $id . ' step.');
    }
}
