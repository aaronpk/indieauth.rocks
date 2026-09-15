<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Integration;

use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Storage\RunRepository;
use IndieAuthRocks\Tests\Support\AppTestCase;
use IndieAuthRocks\Tests\Support\FakeIndieAuthServer;

/** A whole server run through the web pages, the way a person would click it. */
final class BrowserRunTest extends AppTestCase
{
    private function server(string ...$defects): FakeIndieAuthServer
    {
        $server = new FakeIndieAuthServer(function (string $clientId): ?array {
            $response = $this->request('GET', $clientId);

            return $response->status === 200 ? json_decode($response->body, true) : null;
        }, ...$defects);

        $server->install($this->http);

        return $server;
    }

    private static function csrf(string $html): string
    {
        preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);

        return $m[1] ?? '';
    }

    /** From the run page, start the next step (or continue the one in progress), and return its page, or null if there is none. */
    private function openStep(string $runUrl, Response $runPage): ?Response
    {
        if (preg_match('#<form method="post" action="(/runs/[^"]+/start)"#', $runPage->body, $form) === 1) {
            $started = $this->request('POST', self::BASE . ltrim($form[1], '/'), ['_csrf' => self::csrf($runPage->body)]);
            $this->assertSame(303, $started->status);
        }

        $step = $this->request('GET', $runUrl . '/step');

        return $step->status === 200 ? $step : null;
    }

    public function test_a_person_can_run_the_server_suite_in_the_browser(): void
    {
        $server = $this->server();

        $home  = $this->request('GET', self::BASE);
        $start = $this->request('POST', self::BASE . 'server-runs', ['_csrf' => self::csrf($home->body), 'me' => 'me.example']);

        $this->assertSame(303, $start->status);
        $runUrl = self::BASE . ltrim((string) $start->header('location'), '/');
        $this->assertMatchesRegularExpression('#/runs/[0-9A-Z]{26}$#', $runUrl);

        for ($i = 0; $i < 60; $i++) {
            $page = $this->request('GET', $runUrl);
            $this->assertSame(200, $page->status);

            if (str_contains($page->body, 'data-action="finished"')) {
                break;
            }

            // The list names what is next; its instructions are one click away.
            $this->assertStringNotContainsString('/go/', $page->body);
            $page = $this->openStep($runUrl, $page);

            if ($page === null) {
                continue;
            }

            preg_match('/data-step="([^"]*)" data-intent="([^"]*)"/', $page->body, $meta);
            $csrf = self::csrf($page->body);

            if (preg_match('#href="(/runs/[^"]+/go/[^"]+)"#', $page->body, $go) === 1) {
                $redirect = $this->request('GET', self::BASE . ltrim($go[1], '/'));
                $this->assertSame(302, $redirect->status);

                $location = $server->authorize((string) $redirect->header('location'), $meta[2] ?? 'approve');

                if (FakeIndieAuthServer::isLanding($this->config(), $location)) {
                    $landing = $this->request('GET', (string) $location);
                    $this->assertContains($landing->status, [200, 303]);
                    continue;
                }

                preg_match('/name="key" value="([^"]+)"/', $page->body, $key);
                $this->request('POST', $runUrl . '/answer', ['_csrf' => $csrf, 'key' => $key[1] ?? '', 'value' => 'error']);
                continue;
            }

            if (preg_match('/name="key" value="([^"]+)"/', $page->body, $key) === 1) {
                $this->request('POST', $runUrl . '/answer', ['_csrf' => $csrf, 'key' => $key[1], 'value' => $key[1] === 'credential' ? 'cred' : 'both']);
                continue;
            }

            $this->fail('The step page offered nothing to do: ' . strip_tags(substr($page->body, 0, 3000)));
        }

        $this->assertStringContainsString('data-action="finished"', $page->body);
        $this->assertStringNotContainsString('class="result-fail"', $page->body);
        $this->assertStringNotContainsString('class="result-warning"', $page->body);
        $this->assertStringContainsString('0 failed', $page->body);

        // Signing in to the server under test signed the person in here.
        $account = $this->request('GET', self::BASE . 'account');
        $this->assertSame(200, $account->status);
        $this->assertStringContainsString('me.example', $account->body);

        // Transcripts are available and redacted.
        $transcript = $this->request('GET', $runUrl . '/tests/S301');
        $this->assertSame(200, $transcript->status);
        $this->assertStringContainsString('redacted len=', $transcript->body);
        $this->assertDoesNotMatchRegularExpression('/"access_token":\s*"[0-9a-f]{48}"/', $transcript->body);
    }

    public function test_another_browser_cannot_drive_or_read_the_transcripts_of_a_run(): void
    {
        $this->server();

        $home  = $this->request('GET', self::BASE);
        $start = $this->request('POST', self::BASE . 'server-runs', ['_csrf' => self::csrf($home->body), 'me' => 'https://me.example/']);
        $run   = self::BASE . ltrim((string) $start->header('location'), '/');

        $this->cookies = [];

        $page = $this->request('GET', $run);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('someone else', $page->body);
        $this->assertStringNotContainsString('/go/', $page->body);

        $this->assertSame(403, $this->request('GET', $run . '/tests/S101')->status);
    }

    public function test_a_callback_from_another_browser_records_nothing_and_signs_nobody_in(): void
    {
        $this->server();

        $home  = $this->request('GET', self::BASE);
        $start = $this->request('POST', self::BASE . 'server-runs', ['_csrf' => self::csrf($home->body), 'me' => 'https://me.example/']);
        $run   = $this->container->get(RunRepository::class)->findByPublicId(basename((string) $start->header('location')));
        $this->request('GET', self::BASE . 'runs/' . $run->publicId);

        // A victim follows a callback link crafted from the attacker's run.
        $this->cookies = [];
        $landing = $this->request('GET', self::BASE . 'callback/' . $run->runKey . '?code=attackers-code&state=lost');

        $this->assertSame(403, $landing->status);
        $this->assertNull($landing->header('location'));
        $this->assertNotSame(200, $this->request('GET', self::BASE . 'account')->status);
    }

    public function test_a_redirect_back_is_announced_and_a_check_can_be_run_again_from_its_page(): void
    {
        $server = $this->server();

        $home   = $this->request('GET', self::BASE);
        $start  = $this->request('POST', self::BASE . 'server-runs', ['_csrf' => self::csrf($home->body), 'me' => 'me.example']);
        $runUrl = self::BASE . ltrim((string) $start->header('location'), '/');
        $runId  = basename($runUrl);

        // The list says what is next, but nothing starts until asked.
        $page = $this->request('GET', $runUrl);
        $this->assertStringContainsString('data-action="upcoming" data-step="login"', $page->body);
        $this->assertStringNotContainsString('/go/', $page->body);
        $this->assertSame([], $this->container->get(RunRepository::class)->findByPublicId($runId)->state['attempts']);

        // Sign in. The question about the approval screen keeps the step open.
        $step = $this->openStep($runUrl, $page);
        preg_match('#href="(/runs/[^"]+/go/[^"]+)"#', $step->body, $go);
        $location = $server->authorize((string) $this->request('GET', self::BASE . ltrim($go[1], '/'))->header('location'), 'approve');
        $this->assertSame('/runs/' . $runId . '/step', $this->request('GET', (string) $location)->header('location'));

        $step = $this->request('GET', $runUrl . '/step');
        $this->assertStringContainsString('Your server redirected back with an authorization code for this step', $step->body);
        $answered = $this->request('POST', $runUrl . '/answer', ['_csrf' => self::csrf($step->body), 'key' => 'S206', 'value' => 'both']);
        $this->assertSame('/runs/' . $runId, $answered->header('location'));

        // Back at the list, which says how sign-in went and what is next.
        $page = $this->request('GET', $runUrl);
        $this->assertStringContainsString('Finished Sign in with your server. Results:', $page->body);
        $this->assertStringContainsString('data-action="upcoming" data-step="S207"', $page->body);
        $this->assertStringContainsString('class="result-not_run is-next"', $page->body);

        // S207: cancel, and land back on the list. The last step's notice was shown once and is gone.
        $step = $this->openStep($runUrl, $page);
        $this->assertStringContainsString('data-step="S207"', $step->body);
        $this->assertStringNotContainsString('Finished Sign in with your server', $step->body);
        preg_match('#href="(/runs/[^"]+/go/[^"]+)"#', $step->body, $go);
        $location = $server->authorize((string) $this->request('GET', self::BASE . ltrim($go[1], '/'))->header('location'), 'cancel');
        $this->assertSame('/runs/' . $runId, $this->request('GET', (string) $location)->header('location'));

        $page = $this->request('GET', $runUrl);
        $this->assertStringContainsString('Your server redirected back with error=access_denied for S207', $page->body);
        $this->assertStringContainsString('data-action="upcoming" data-step="S208"', $page->body);
        $this->assertStringContainsString('<td class="test-id"><a href="/runs/' . $runId . '/tests/S207">', $page->body);

        // The test's page describes it, lists the redirect back, and offers to run it again.
        $test = $this->request('GET', $runUrl . '/tests/S207');
        $this->assertSame(200, $test->status);
        $this->assertStringContainsString('Passes when:', $test->body);
        $this->assertStringContainsString('/callback/', $test->body);
        $this->assertStringContainsString('Run this test again', $test->body);

        $rerun = $this->request('POST', $runUrl . '/tests/S207/rerun', ['_csrf' => self::csrf($test->body)]);
        $this->assertSame(303, $rerun->status);
        $this->assertSame('/runs/' . $runId . '/step', $rerun->header('location'));

        $step = $this->request('GET', $runUrl . '/step');
        $this->assertStringContainsString('data-step="S207"', $step->body);
        $this->assertStringContainsString('Running S207 again.', $step->body);

        // Tests checked with others explain why there is no button.
        $login = $this->request('GET', $runUrl . '/tests/S301');
        $this->assertStringNotContainsString('Run this test again', $login->body);
        $this->assertStringContainsString('Start a new run', $login->body);

        // Another browser cannot.
        $this->cookies = [];
        $this->assertContains($this->request('POST', $runUrl . '/tests/S207/rerun', ['_csrf' => 'x'])->status, [400, 403]);
    }

    public function test_unregistered_redirects_land_on_the_catch_page(): void
    {
        $server = $this->server('acceptsUnregisteredRedirect');
        $runner = $this->container->get(\IndieAuthRocks\ServerTests\ServerRunner::class);
        $runs   = $this->container->get(RunRepository::class);

        $run = $runner->start(FakeIndieAuthServer::ME, 'browser');

        // Get through sign-in, to S207, then to S208.
        for ($i = 0; $i < 5; $i++) {
            $run    = $runs->find($run->id);
            $action = $runner->next($run);

            if ($action->step === 'S208') {
                $location = (string) $server->authorize((string) $action->url, 'cancel');
                $this->assertStringStartsWith(self::ALT . 'catch/', $location);

                $page = $this->request('GET', $location);
                $this->assertSame(200, $page->status);
                $this->assertStringContainsString('somewhere it shouldn', $page->body);

                return;
            }

            if ($action->type === 'ask') {
                $runner->answer($run, $action->question->key, 'both');
            } else {
                $runner->land($run, (string) $server->authorize((string) $action->url, (string) $action->intent));
            }
        }

        $this->fail('Never reached S208.');
    }
}
