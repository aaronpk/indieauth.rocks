<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Integration;

use IndieAuthRocks\Engine\Run;
use IndieAuthRocks\Identity\Session;
use IndieAuthRocks\Identity\Sessions;
use IndieAuthRocks\ServerTests\Action;
use IndieAuthRocks\ServerTests\ServerRunner;
use IndieAuthRocks\Storage\RunRepository;
use IndieAuthRocks\Storage\TesterRepository;
use IndieAuthRocks\Tests\Support\AppTestCase;
use IndieAuthRocks\Tests\Support\FakeIndieAuthServer;

final class ReportTest extends AppTestCase
{
    private function completedRun(): Run
    {
        $server = new FakeIndieAuthServer(function (string $clientId): ?array {
            $response = $this->request('GET', $clientId);

            return $response->status === 200 ? json_decode($response->body, true) : null;
        });
        $server->install($this->http);

        $runner = $this->container->get(ServerRunner::class);
        $runs   = $this->container->get(RunRepository::class);
        $run    = $runner->start(FakeIndieAuthServer::ME, 'cli');

        for ($i = 0; $i < 100; $i++) {
            $run    = $runs->find($run->id);
            $action = $runner->next($run);

            if ($action->type === Action::FINISHED) {
                break;
            }

            if ($action->type === Action::ASK) {
                $runner->answer($run, $action->question->key, 'both');
                continue;
            }

            $location = $server->authorize((string) $action->url, (string) $action->intent);

            if (FakeIndieAuthServer::isLanding($this->config(), $location)) {
                $runner->land($run, (string) $location);
            } else {
                $runner->answer($run, (string) $action->step, 'error');
            }
        }

        return $runs->find($run->id);
    }

    private function signInAs(string $me): void
    {
        $tester  = $this->container->get(TesterRepository::class)->signIn($me);
        $session = $this->container->get(Sessions::class)->promote(Session::createAnonymous(), (string) $tester->id, ['indieauth']);

        $this->cookies = [Sessions::COOKIE => $session->id];
    }

    public function test_a_finished_run_can_be_published_and_is_public(): void
    {
        $run = $this->completedRun();
        $this->assertSame(Run::COMPLETE, $run->status);

        $this->signInAs(FakeIndieAuthServer::ME);

        $page = $this->request('GET', self::BASE . 'runs/' . $run->publicId);
        $this->assertStringContainsString('Publish an implementation report', $page->body);
        preg_match('/name="_csrf" value="([^"]+)"/', $page->body, $csrf);

        $published = $this->request('POST', self::BASE . 'runs/' . $run->publicId . '/publish', [
            '_csrf' => $csrf[1] ?? '',
            'name'  => 'Fake <Server>',
            'url'   => 'https://server.example/',
        ]);

        $this->assertSame(303, $published->status);
        $report = self::BASE . ltrim((string) $published->header('location'), '/');

        $this->cookies = [];

        $index = $this->request('GET', self::BASE . 'implementations');
        $this->assertStringContainsString('Fake &lt;Server&gt;', $index->body);

        $show = $this->request('GET', $report);
        $this->assertSame(200, $show->status);
        $this->assertStringContainsString('S208', $show->body);
        $this->assertStringNotContainsString('redacted', $show->body);
        $this->assertStringNotContainsString('/runs/' . $run->publicId, $show->body);
        $this->assertStringNotContainsString('Remove this report', $show->body);
    }

    public function test_only_the_tester_can_publish(): void
    {
        $run = $this->completedRun();

        $this->signInAs('https://someone-else.example/');
        $page = $this->request('GET', self::BASE . 'account');
        preg_match('/name="_csrf" value="([^"]+)"/', $page->body, $csrf);

        $response = $this->request('POST', self::BASE . 'runs/' . $run->publicId . '/publish', ['_csrf' => $csrf[1] ?? '', 'name' => 'Nope']);

        $this->assertSame(403, $response->status);
    }
}
