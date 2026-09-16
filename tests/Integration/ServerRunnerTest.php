<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Integration;

use IndieAuthRocks\Engine\Catalog;
use IndieAuthRocks\Engine\Level;
use IndieAuthRocks\Engine\Run;
use IndieAuthRocks\ServerTests\Action;
use IndieAuthRocks\ServerTests\ProbeGrader;
use IndieAuthRocks\ServerTests\ServerRunner;
use IndieAuthRocks\Storage\ResultRepository;
use IndieAuthRocks\Storage\RunRepository;
use IndieAuthRocks\Tests\Support\AppTestCase;
use IndieAuthRocks\Tests\Support\FakeIndieAuthServer;

final class ServerRunnerTest extends AppTestCase
{
    private function server(string ...$defects): FakeIndieAuthServer
    {
        $server = new FakeIndieAuthServer(function (string $clientId): ?array {
            if (!str_starts_with($clientId, self::BASE)) {
                return null;
            }

            $response = $this->request('GET', $clientId);

            return $response->status === 200 ? json_decode($response->body, true) : null;
        }, ...$defects);

        $server->install($this->http);

        return $server;
    }

    /** Drive a whole run the way the CLI will, answering as a careful person would. */
    private function drive(FakeIndieAuthServer $server, bool $extended = false): Run
    {
        $runner = $this->container->get(ServerRunner::class);
        $runs   = $this->container->get(RunRepository::class);
        $run    = $runner->start(FakeIndieAuthServer::ME, 'cli', $extended);

        for ($i = 0; $i < 200; $i++) {
            $run    = $runs->find($run->id);
            $action = $runner->next($run);

            if ($action->type === Action::FINISHED) {
                return $runs->find($run->id);
            }

            if ($action->type === Action::ASK) {
                $runner->answer($run, $action->question->key, match ($action->question->key) {
                    'credential'          => 'cred',
                    'introspection-scope' => 'profile',
                    default               => 'both',
                }, 'driver');
                continue;
            }

            if ($action->type === Action::NAVIGATE) {
                $location = $server->authorize((string) $action->url, (string) $action->intent);

                if (FakeIndieAuthServer::isLanding($this->config(), $location)) {
                    $result = $runner->land($run, (string) $location);
                    $this->assertTrue($result['handled'], $result['message']);
                } elseif ($action->question !== null) {
                    $runner->answer($run, $action->step, 'error', 'driver');
                } else {
                    $runner->skip($run);
                }

                continue;
            }

            $runner->skip($run);
        }

        $this->fail('The run did not finish.');
    }

    /** @return array<string, string> test id => outcome */
    private function outcomes(Run $run): array
    {
        return array_map(static fn (array $r): string => (string) $r['outcome'], $this->container->get(ResultRepository::class)->forRun($run->id));
    }

    public function test_a_correct_server_passes_everything_required(): void
    {
        $run      = $this->drive($this->server(), extended: true);
        $outcomes = $this->outcomes($run);

        $this->assertSame(Run::COMPLETE, $run->status);
        $this->assertNotNull($run->state['login_me'] ?? null, 'The tester should have been signed in.');

        $problems = array_filter($outcomes, static fn (string $o): bool => in_array($o, ['fail', 'warning', 'error'], true));
        $this->assertSame([], $problems, 'Unexpected outcomes: ' . json_encode($problems));

        foreach (Catalog::suite(Catalog::SERVER) as $id => $test) {
            if ($test->level === Level::MUST && $id !== 'S304') {
                $this->assertArrayHasKey($id, $outcomes, $id . ' was never graded');
            }
        }

        $this->assertSame(false, $run->state['flags']['burns_on_failure'] ?? null);

        // Destructive checks each need live tokens; none should be starved.
        foreach (['S303', 'S501', 'S502', 'S503', 'S504', 'S505', 'S601', 'S602', 'S603', 'S803', 'S804'] as $id) {
            $this->assertSame('pass', $outcomes[$id] ?? 'missing', $id);
        }
    }

    public function test_jwt_access_tokens_are_checked_against_the_published_keys(): void
    {
        $outcomes = $this->outcomes($this->drive($this->server('jwtTokens')));

        foreach (['S420', 'S421', 'S422', 'S423'] as $id) {
            $this->assertSame('pass', $outcomes[$id] ?? 'missing', $id);
        }
    }

    public function test_opaque_access_tokens_skip_the_jwt_checks(): void
    {
        $outcomes = $this->outcomes($this->drive($this->server()));

        // Opaque tokens are fine: only the format is reported.
        $this->assertSame('pass', $outcomes['S420'] ?? 'missing');

        foreach (['S421', 'S422', 'S423'] as $id) {
            $this->assertSame('skipped', $outcomes[$id] ?? 'missing', $id);
        }
    }

    /** @return iterable<string, array{0: string, 1: list<string>}> */
    public static function defects(): iterable
    {
        yield 'no iss'                        => ['noIss', ['S202']];
        yield 'wrong iss'                     => ['wrongIss', ['S202']];
        yield 'accepts unregistered redirect' => ['acceptsUnregisteredRedirect', ['S208', 'S210', 'S211', 'S212']];
        yield 'prefix matching'               => ['prefixMatch', ['S210']];
        yield 'trusts mismatched metadata'    => ['ignoresMetadataMismatch', ['S212']];
        yield 'no PKCE check'                 => ['noPkceCheck', ['S215', 'S216']];
        yield 'reusable codes'                => ['reusableCodes', ['S302']];
        yield 'tokens without scope'          => ['tokenForScopeless', ['S321']];
        yield 'no no-store'                   => ['noNoStore', ['S406', 'S501']];
        yield 'revocation does nothing'       => ['revokeNoop', ['S602', 'S603']];
        yield 'introspection without auth'    => ['introspectNoAuth', ['S801', 'S802']];
        yield 'returns the requested me'      => ['returnsHintMe', ['S220']];
        yield 'jwt with the wrong issuer'     => ['jwtWrongIssuer', ['S423']];
    }

    /** @param list<string> $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('defects')]
    public function test_each_defect_is_caught(string $defect, array $expected): void
    {
        $outcomes = $this->outcomes($this->drive($this->server($defect), extended: true));

        foreach ($expected as $id) {
            $this->assertContains($outcomes[$id] ?? 'missing', ['fail', 'warning'], sprintf('%s should flag %s, got %s', $defect, $id, $outcomes[$id] ?? 'missing'));
        }
    }

    public function test_a_server_that_burns_codes_gets_extra_approvals(): void
    {
        $run      = $this->drive($this->server('burnOnFailure'), extended: true);
        $outcomes = $this->outcomes($run);

        $this->assertTrue($run->state['flags']['burns_on_failure'] ?? false);

        // Each extra approval is asked for exactly once.
        $steps = array_count_values(array_column($run->state['attempts'], 'step'));
        foreach (['login', 'c2', 'c2b', 'c3', 'c3b'] as $step) {
            $this->assertSame(1, $steps[$step] ?? 0, $step);
        }

        foreach (['S302', 'S310', 'S311', 'S312', 'S313', 'S320'] as $id) {
            $this->assertSame('pass', $outcomes[$id] ?? 'missing', $id);
        }
    }

    public function test_probe_questions_cover_every_probe(): void
    {
        foreach (ProbeGrader::ALL as $id) {
            $this->assertNotSame([], ProbeGrader::question($id, 'alt.example')->choices, $id);
        }
    }

    /** Take a new run through its steps, answering as a careful person would, until the given step is next. */
    private function advanceTo(FakeIndieAuthServer $server, Run $run, string $step): Action
    {
        $runner = $this->container->get(ServerRunner::class);
        $runs   = $this->container->get(RunRepository::class);

        for ($i = 0; $i < 20; $i++) {
            $action = $runner->next($runs->find($run->id));

            if ($action->step === $step) {
                return $action;
            }

            if ($action->type === Action::ASK) {
                $runner->answer($runs->find($run->id), $action->question->key, 'both');
            } else {
                $runner->land($runs->find($run->id), (string) $server->authorize((string) $action->url, (string) $action->intent));
            }
        }

        $this->fail('Never reached ' . $step . '.');
    }

    public function test_looking_at_what_is_next_starts_nothing(): void
    {
        $this->server();
        $runner = $this->container->get(ServerRunner::class);
        $runs   = $this->container->get(RunRepository::class);
        $run    = $runner->start(FakeIndieAuthServer::ME, 'browser');

        $upcoming = $runner->upcoming($runs->find($run->id));
        $this->assertSame('login', $upcoming['step']);
        $this->assertFalse($upcoming['in_progress']);
        $this->assertContains('S301', $upcoming['tests']);
        $this->assertNull($runner->currentAction($runs->find($run->id)));
        $this->assertSame([], $runs->find($run->id)->state['attempts']);

        $runner->next($runs->find($run->id));

        $this->assertTrue($runner->upcoming($runs->find($run->id))['in_progress']);
        $this->assertSame('login', $runner->currentAction($runs->find($run->id))?->step);
    }

    public function test_every_redirect_back_is_described(): void
    {
        $server = $this->server();
        $runner = $this->container->get(ServerRunner::class);
        $runs   = $this->container->get(RunRepository::class);
        $run    = $runner->start(FakeIndieAuthServer::ME, 'browser');

        $action = $this->advanceTo($server, $run, 'S207');
        $result = $runner->land($runs->find($run->id), (string) $server->authorize((string) $action->url, 'cancel'));

        $this->assertSame('S207', $result['step']);
        $this->assertStringContainsString('Your server redirected back with error=access_denied for S207', $result['message']);
        $this->assertStringNotContainsString('approval screen', $result['message']);
    }

    public function test_a_quick_redirect_with_a_code_suggests_there_was_no_approval_screen(): void
    {
        $server = $this->server();
        $runner = $this->container->get(ServerRunner::class);
        $runs   = $this->container->get(RunRepository::class);
        $run    = $runner->start(FakeIndieAuthServer::ME, 'browser');

        // A server that remembers the sign-in approval sends S207 straight back with a code.
        $action = $this->advanceTo($server, $run, 'S207');
        $runner->markOpened($runs->find($run->id), (string) $action->attemptId);
        $result = $runner->land($runs->find($run->id), (string) $server->authorize((string) $action->url, 'approve'));

        $this->assertStringContainsString('redirected back with an authorization code for S207', $result['message']);
        $this->assertStringContainsString('without showing an approval screen', $result['message']);
        $this->assertStringContainsString('revoke that approval', $result['message']);
        $this->assertSame('S207', $runs->find($run->id)->state['queue'][0]);
    }

    public function test_a_browser_check_can_run_again_after_the_run_finished(): void
    {
        $runner  = $this->container->get(ServerRunner::class);
        $runs    = $this->container->get(RunRepository::class);
        $results = $this->container->get(ResultRepository::class);
        $run     = $this->drive($this->server('noPkceCheck'));

        $this->assertSame(Run::COMPLETE, $run->status);
        $this->assertContains($this->outcomes($run)['S216'] ?? 'missing', ['fail', 'warning']);
        $this->assertNull($runner->rerunReason($run, 'S216', $results->forRun($run->id)['S216']));

        // The server is fixed, and the check is run again.
        $fixed = $this->server();
        $this->assertSame('S216 is now the next step.', $runner->rerun($run, 'S216'));

        $run    = $runs->find($run->id);
        $action = $runner->next($run);
        $this->assertSame(Run::RUNNING, $run->status);
        $this->assertSame(Action::NAVIGATE, $action->type);
        $this->assertSame('S216', $action->step);

        $landed = $runner->land($runs->find($run->id), (string) $fixed->authorize((string) $action->url, 'cancel'));
        $this->assertTrue($landed['handled'], $landed['message']);

        $this->assertSame(Action::FINISHED, $runner->next($runs->find($run->id))->type);
        $this->assertSame(Run::COMPLETE, $runs->find($run->id)->status);
        $this->assertSame('pass', $this->outcomes($run)['S216']);
    }

    public function test_running_a_check_again_sets_aside_the_request_that_was_next(): void
    {
        $server = $this->server();
        $runner = $this->container->get(ServerRunner::class);
        $runs   = $this->container->get(RunRepository::class);
        $run    = $runner->start(FakeIndieAuthServer::ME, 'browser');

        $pending = $this->advanceTo($server, $run, 'S208');

        $this->assertSame('S207 is now the next step.', $runner->rerun($runs->find($run->id), 'S207'));
        $this->assertSame(['S207', 'S208'], array_slice($runs->find($run->id)->state['queue'], 0, 2));
        $this->assertSame('S207', $runner->next($runs->find($run->id))->step);

        // If the browser still comes back from the request that was set aside, it is not graded.
        $late = $runner->land($runs->find($run->id), self::BASE . 'callback/' . $run->runKey . '?error=access_denied&state=' . rawurlencode($pending->attemptId . '~x'));
        $this->assertFalse($late['handled']);
    }

    public function test_only_browser_checks_and_automatic_checks_run_again_on_their_own(): void
    {
        $runner    = $this->container->get(ServerRunner::class);
        $exchanges = $this->container->get(\IndieAuthRocks\Storage\ExchangeRepository::class);
        $run       = $this->drive($this->server());
        $results   = $this->container->get(ResultRepository::class)->forRun($run->id);

        $this->assertSame('discovery', $results['S101']['step']);
        $this->assertNull($runner->rerunReason($run, 'S101', $results['S101']));
        $this->assertStringContainsString('every redirect', (string) $runner->rerunReason($run, 'S201', $results['S201']));
        $this->assertStringContainsString('Start a new run', (string) $runner->rerunReason($run, 'S301', $results['S301']));
        $this->assertSame('This test has not run yet.', $runner->rerunReason($run, 'S304', null));

        $before = count($exchanges->forStep($run->id, 'discovery'));
        $this->assertSame('The discovery and endpoint checks ran again.', $runner->rerun($run, 'S101'));
        $this->assertGreaterThan($before, count($exchanges->forStep($run->id, 'discovery')));
        $this->assertSame(Run::COMPLETE, $this->container->get(RunRepository::class)->find($run->id)->status);
    }
}
