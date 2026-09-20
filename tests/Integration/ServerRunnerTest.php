<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Integration;

use IndieAuthRocks\Engine\Catalog;
use IndieAuthRocks\Engine\Level;
use IndieAuthRocks\Engine\Run;
use IndieAuthRocks\ServerTests\Action;
use IndieAuthRocks\ServerTests\ProbeGrader;
use IndieAuthRocks\ServerTests\ServerRunner;
use IndieAuthRocks\ServerTests\TestOptions;
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
        yield 'no PKCE check'                 => ['noPkceCheck', ['S215', 'S216', 'S221', 'S222']];
        yield 'method without a challenge'    => ['methodWithoutChallenge', ['S221']];
        yield 'missing method means plain'    => ['defaultsToPlain', ['S222']];
        yield 'no well-known metadata'        => ['noWellKnown', ['S117']];
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

    /**
     * Every code is exchanged at the token endpoint now. A server that still
     * takes a scopeless exchange only at its authorization endpoint signs
     * people in, so it is warned rather than failed.
     */
    public function test_a_server_still_redeeming_at_the_authorization_endpoint_is_warned(): void
    {
        $outcomes = $this->outcomes($this->drive($this->server('scopelessOnlyAtAuthorization')));

        $this->assertSame('warning', $outcomes['S320'] ?? 'missing');
        $this->assertSame('pass', $outcomes['S301'] ?? 'missing', 'a code with a scope is unaffected');
    }

    public function test_a_correct_server_exchanges_a_scopeless_code_at_the_token_endpoint(): void
    {
        $this->assertSame('pass', $this->outcomes($this->drive($this->server()))['S320'] ?? 'missing');
    }

    /**
     * Whatever state a test is in, its page can offer something or say why not.
     * A silent dead end is what sent someone looking for a button that was not
     * there, so this guards every id in the catalog at once.
     */
    public function test_every_test_offers_an_action_or_a_reason(): void
    {
        $this->server();
        $runner   = $this->container->get(ServerRunner::class);
        $results  = $this->container->get(ResultRepository::class);
        $run      = $this->container->get(RunRepository::class)->find($runner->start(FakeIndieAuthServer::ME, 'browser')->id);
        $recorded = $results->forRun($run->id);

        foreach (Catalog::suite(Catalog::SERVER) as $test) {
            $options = $runner->optionsFor($run, $test->id, $recorded[$test->id] ?? null);

            if ($options->kind === TestOptions::NONE) {
                $this->assertNotSame('', (string) $options->reason, $test->id . ' refuses without saying why');

                continue;
            }

            $this->assertNotSame('', $options->label, $test->id . ' offers an unlabelled action');
            $this->assertNotNull($options->step, $test->id . ' offers an action with no step');
        }
    }

    /** Asking for a test graded by a contingency step offers the step people know. */
    public function test_a_contingency_step_is_never_the_one_offered(): void
    {
        $this->server();
        $runner = $this->container->get(ServerRunner::class);
        $run    = $this->container->get(RunRepository::class)->find($runner->start(FakeIndieAuthServer::ME, 'browser')->id);

        $this->assertSame('c3', $runner->stepFor('S311'), 'S311 is graded by c3; c4 only exists when codes burn');
        $this->assertSame('c3', $runner->optionsFor($run, 'S311', ['step' => 'c4', 'outcome' => 'pass'])->step);
        $this->assertSame(TestOptions::RUN, $runner->optionsFor($run, 'S311', ['step' => 'c4', 'outcome' => 'pass'])->kind);
    }

    public function test_a_stuck_step_can_be_started_again_but_a_waiting_one_cannot(): void
    {
        $server = $this->server();
        $runner = $this->container->get(ServerRunner::class);
        $runs   = $this->container->get(RunRepository::class);
        $run    = $runner->start(FakeIndieAuthServer::ME, 'browser');

        $pending = $this->advanceTo($server, $run, 'S208');
        $run     = $runs->find($run->id);

        // Its own page offers to continue or start over.
        $options = $runner->optionsFor($run, 'S208', null);
        $this->assertSame(TestOptions::CONTINUE, $options->kind);
        $this->assertTrue($options->restartable);

        $this->assertNull($runner->restart($run));

        $run     = $runs->find($run->id);
        $started = $runner->next($run);
        $this->assertSame('S208', $started->step);
        $this->assertNotSame($pending->attemptId, $started->attemptId, 'a new request, not the abandoned one');

        // A landing from the abandoned request no longer counts.
        $late = $runner->land($runs->find($run->id), self::BASE . 'callback/' . $run->runKey . '?error=access_denied&state=' . rawurlencode($pending->attemptId . '~x'));
        $this->assertFalse($late['handled']);
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

        // A test graded inside a step is not refused any more: its step can be
        // asked for by name, which is what someone wanting it actually needs.
        $login = $runner->optionsFor($run, 'S301', $results['S301']);
        $this->assertSame(TestOptions::RUN, $login->kind);
        $this->assertSame('login', $login->step);
        $this->assertStringContainsString('needs one approval', $login->hint);

        $slow = $runner->optionsFor($run, 'S304', null);
        $this->assertSame(TestOptions::RUN, $slow->kind, 'a test that has not run is the one you most want to start');
        $this->assertSame('S304', $slow->step);

        $before = count($exchanges->forStep($run->id, 'discovery'));
        $this->assertSame('The discovery and endpoint checks ran again.', $runner->rerun($run, 'S101'));
        $this->assertGreaterThan($before, count($exchanges->forStep($run->id, 'discovery')));
        $this->assertSame(Run::COMPLETE, $this->container->get(RunRepository::class)->find($run->id)->status);
    }

    public function test_each_test_shows_only_the_requests_made_for_it(): void
    {
        $exchanges = $this->container->get(\IndieAuthRocks\Storage\ExchangeRepository::class);
        $run       = $this->drive($this->server());
        $bodies    = static fn (array $rows): string => implode("\n", array_map(static fn (array $row): string => (string) $row['request_body'], $rows));

        // S407 only reads the sign-in's token response: none of the refresh tests' requests.
        $s407 = $exchanges->forTest($run->id, 'login', 'S407');
        $this->assertStringContainsString('grant_type=authorization_code', $bodies($s407));
        $this->assertStringNotContainsString('grant_type=refresh_token', $bodies($s407));
        $this->assertLessThan(count($exchanges->forStep($run->id, 'login')), count($s407));

        // The refresh tests see their refreshes, and the redemption that issued the token.
        $s501 = $exchanges->forTest($run->id, 'login', 'S501');
        $this->assertStringContainsString('grant_type=refresh_token', $bodies($s501));
        $this->assertStringContainsString('grant_type=authorization_code', $bodies($s501));
        $refreshes = static fn (array $rows): int => count(array_filter($rows, static fn (array $row): bool => str_contains((string) $row['request_body'], 'grant_type=refresh_token')));
        $this->assertSame(4, $refreshes($exchanges->forStep($run->id, 'login')));
        // S502: the first refresh, whose token it uses, and its own with another client_id.
        $this->assertSame(2, $refreshes($exchanges->forTest($run->id, 'login', 'S502')));

        // Probes are tagged one by one.
        $this->assertSame(['OPTIONS'], array_values(array_unique(array_column(array_filter($exchanges->forTest($run->id, 'probes', 'S334'), static fn (array $row): bool => $row['test_ids'] !== null), 'method'))));
    }

    public function test_request_tags_nest_and_are_restored_after_an_exception(): void
    {
        $http      = $this->container->get(\IndieAuthRocks\Fetch\RecordingHttpClient::class);
        $exchanges = $this->container->get(\IndieAuthRocks\Storage\ExchangeRepository::class);
        $run       = $this->container->get(ServerRunner::class)->start(FakeIndieAuthServer::ME, 'cli', false);

        $this->http->on('https://tags.example/a', 200);
        $this->http->on('https://tags.example/b', 200);
        $this->http->on('https://tags.example/c', 200);
        $this->http->on('https://tags.example/d', 200);

        $get = static fn (string $path) => $http->send(\IndieAuthRocks\Fetch\HttpRequest::get('https://tags.example/' . $path));

        $http->context($run->id, 'tags');
        $http->forTests(['S1', 'S2'], function () use ($http, $get): void {
            $get('a');
            $http->forTests(['S3'], static fn () => $get('b'));

            try {
                $http->forTests(['S4'], static function (): never {
                    throw new \RuntimeException('boom');
                });
            } catch (\RuntimeException) {
            }

            $get('c');
        });
        $get('d');
        $http->context(null);

        $tags = array_column($exchanges->forStep($run->id, 'tags'), 'test_ids', 'url');

        $this->assertSame([
            'https://tags.example/a' => 'S1,S2',
            'https://tags.example/b' => 'S3',
            'https://tags.example/c' => 'S1,S2',
            'https://tags.example/d' => null,
        ], $tags);
        $this->assertSame(['https://tags.example/a', 'https://tags.example/c', 'https://tags.example/d'], array_column($exchanges->forTest($run->id, 'tags', 'S2'), 'url'));
    }
}
