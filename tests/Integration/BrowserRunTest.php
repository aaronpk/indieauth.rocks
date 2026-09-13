<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Integration;

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

            $this->fail('The run page offered nothing to do: ' . strip_tags(substr($page->body, 0, 3000)));
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
