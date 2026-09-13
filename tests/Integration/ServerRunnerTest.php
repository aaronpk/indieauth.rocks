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
                $runner->answer($run, $action->question->key, $action->question->key === 'credential' ? 'cred' : 'both', 'driver');
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
}
