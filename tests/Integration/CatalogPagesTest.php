<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Integration;

use IndieAuthRocks\Identity\Session;
use IndieAuthRocks\Identity\Sessions;
use IndieAuthRocks\Storage\TesterRepository;
use IndieAuthRocks\Tests\Support\AppTestCase;
use IndieAuthRocks\Tests\Support\FakeIndieAuthServer;

/** The lists of tests are also a place to start testing. */
final class CatalogPagesTest extends AppTestCase
{
    private static function csrf(string $html): string
    {
        preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);

        return $m[1] ?? '';
    }

    public function test_a_server_run_starts_from_the_server_test_list(): void
    {
        (new FakeIndieAuthServer(static fn (string $clientId): ?array => null))->install($this->http);

        $page = $this->request('GET', self::BASE . 'server-tests');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('action="/server-runs"', $page->body);

        $start = $this->request('POST', self::BASE . 'server-runs', ['_csrf' => self::csrf($page->body), 'me' => FakeIndieAuthServer::ME]);
        $this->assertSame(303, $start->status);
        $this->assertMatchesRegularExpression('#^/runs/[0-9A-Z]{26}$#', (string) $start->header('location'));
    }

    public function test_the_client_test_list_sends_a_visitor_to_sign_in_first(): void
    {
        $page = $this->request('GET', self::BASE . 'client-tests');

        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('<a class="button" href="/sign-in">Sign in to test a client</a>', $page->body);
        $this->assertStringNotContainsString('action="/client-runs"', $page->body);
    }

    public function test_a_signed_in_tester_starts_a_client_run_from_the_client_test_list(): void
    {
        $tester  = $this->container->get(TesterRepository::class)->signIn('https://tester.example/');
        $session = $this->container->get(Sessions::class)->promote(Session::createAnonymous(), (string) $tester->id, ['indieauth']);
        $this->cookies = [Sessions::COOKIE => $session->id];

        $page = $this->request('GET', self::BASE . 'client-tests');
        $this->assertStringContainsString('Start a new client run', $page->body);

        $start = $this->request('POST', self::BASE . 'client-runs', ['_csrf' => self::csrf($page->body)]);
        $this->assertSame(303, $start->status);
        $this->assertMatchesRegularExpression('#^/client-runs/[0-9A-Z]{26}$#', (string) $start->header('location'));
    }
}
