<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\Checkup;

use IndieAuthRocks\Checkup\Step;
use IndieAuthRocks\Checkup\StepState;
use IndieAuthRocks\Checkup\Steps\RedemptionSteps;
use IndieAuthRocks\Fetch\FetchResult;
use IndieAuthRocks\ServerTests\Reply;
use PHPUnit\Framework\TestCase;

final class RedemptionStepsTest extends TestCase
{
    private const ENDPOINT = 'https://server.example/token';
    private const ME       = 'https://me.example/';

    public function test_a_good_exchange_confirms_who_you_are(): void
    {
        $steps = $this->from($this->reply(200, ['me' => self::ME]));

        $this->assertSame(StepState::Ok, $this->step($steps, 'redeem')->state);
        $this->assertSame(StepState::Ok, $this->step($steps, 'identity')->state);
    }

    public function test_invalid_grant_is_explained_in_plain_terms(): void
    {
        $step = $this->step($this->from($this->reply(400, ['error' => 'invalid_grant'])), 'redeem');

        $this->assertSame(StepState::Problem, $step->state);
        $this->assertStringContainsString('already been used', (string) $step->meaning);
        $this->assertStringContainsString('link preview', (string) $step->fix);
        $this->assertCount(1, $this->from($this->reply(400, ['error' => 'invalid_grant'])), 'nothing after it can be checked');
    }

    public function test_invalid_client_explains_registration(): void
    {
        $step = $this->step($this->from($this->reply(400, ['error' => 'invalid_client'])), 'redeem');

        $this->assertStringContainsString('registered with it', (string) $step->meaning);
    }

    public function test_a_web_page_instead_of_json_is_caught(): void
    {
        $reply = new Reply(new FetchResult(self::ENDPOINT, 200, '<html>hello</html>', ['content-type' => ['text/html']]));
        $step  = $this->step($this->from($reply), 'redeem');

        $this->assertSame(StepState::Problem, $step->state);
        $this->assertStringContainsString('other than a JSON document', $step->headline);
    }

    public function test_success_without_me_is_a_problem(): void
    {
        $step = $this->step($this->from($this->reply(200, ['access_token' => 'x'])), 'redeem');

        $this->assertSame(StepState::Problem, $step->state);
        $this->assertStringContainsString('did not say who you are', $step->headline);
    }

    public function test_an_unreachable_endpoint_explains_that_the_app_fetches_it(): void
    {
        $step = $this->step($this->from(new Reply(null, 'Connection timed out')), 'redeem');

        $this->assertSame(StepState::Problem, $step->state);
        $this->assertStringContainsString('Your browser never talks to this address', (string) $step->meaning);
    }

    public function test_a_token_without_no_store_is_noted(): void
    {
        $reply = $this->reply(200, ['me' => self::ME, 'access_token' => 'secret', 'token_type' => 'Bearer', 'scope' => 'profile']);
        $step  = $this->step($this->from($reply, wantedToken: true), 'redeem');

        $this->assertSame(StepState::Note, $step->state);
        $this->assertStringContainsString('no-store', (string) $step->meaning);
    }

    /** The draft exchanges every code at the token endpoint, with or without a scope. */
    public function test_a_server_with_no_token_endpoint_is_told_so(): void
    {
        $steps = RedemptionSteps::from(
            $this->reply(200, ['me' => self::ME]),
            'https://server.example/auth',
            self::ME,
            false,
            null,
            noTokenEndpoint: true,
        );
        $step = $this->step($steps, 'redeem');

        $this->assertSame(StepState::Note, $step->state);
        $this->assertStringContainsString('does not advertise a token endpoint', (string) $step->meaning);
        $this->assertStringContainsString('authorization endpoint', (string) $step->meaning);
    }

    public function test_a_different_but_verified_me_says_which_address_to_use(): void
    {
        $steps = $this->from(
            $this->reply(200, ['me' => 'https://www.me.example/']),
            verified: ['ok' => true, 'fetched' => true, 'reason' => 'it names the same server.'],
        );
        $step = $this->step($steps, 'identity');

        $this->assertSame(StepState::Note, $step->state);
        $this->assertStringContainsString('Sign in to apps as https://www.me.example/', (string) $step->fix);
    }

    public function test_an_unverifiable_me_is_refused(): void
    {
        $steps = $this->from(
            $this->reply(200, ['me' => 'https://someone-else.example/']),
            verified: ['ok' => false, 'fetched' => true, 'reason' => 'it does not name this server.'],
        );
        $step = $this->step($steps, 'identity');

        $this->assertSame(StepState::Problem, $step->state);
        $this->assertStringContainsString('could not be confirmed', $step->headline);
    }

    /**
     * @param  array{ok: bool, fetched: bool, reason: string}|null $verified
     * @return list<Step>
     */
    private function from(Reply $reply, bool $wantedToken = false, ?array $verified = null): array
    {
        return RedemptionSteps::from($reply, self::ENDPOINT, self::ME, $wantedToken, $verified);
    }

    /** @param array<string, mixed> $json */
    private function reply(int $status, array $json): Reply
    {
        return new Reply(new FetchResult(
            self::ENDPOINT,
            $status,
            (string) json_encode($json),
            ['content-type' => ['application/json']],
        ));
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
