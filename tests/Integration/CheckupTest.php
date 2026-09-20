<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Integration;

use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Tests\Support\AppTestCase;
use IndieAuthRocks\Tests\Support\FakeIndieAuthServer;

/** "Check your login": the whole flow, narrated, for someone with no account. */
final class CheckupTest extends AppTestCase
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

    /** @param array<string, string> $extra */
    private function start(string $me = FakeIndieAuthServer::ME, array $extra = []): Response
    {
        $form = $this->request('GET', self::BASE . 'check');

        return $this->request('POST', self::BASE . 'check', ['_csrf' => self::csrf($form->body), 'me' => $me] + $extra);
    }

    /** Start a checkup and return its page. */
    private function page(string $me = FakeIndieAuthServer::ME): Response
    {
        return $this->request('GET', self::BASE . ltrim((string) $this->start($me)->header('location'), '/'));
    }

    /** Walk the whole thing: start, leave, approve, come back, read the result. */
    private function walk(FakeIndieAuthServer $server, string $approval = 'approve'): Response
    {
        $location = (string) $this->start()->header('location');
        $go       = $this->request('GET', self::BASE . ltrim($location, '/') . '/go');
        $landing  = (string) $server->authorize((string) $go->header('location'), $approval);
        $back     = $this->request('GET', $landing);

        return $this->request('GET', self::BASE . ltrim((string) $back->header('location'), '/'));
    }

    public function test_a_working_login_is_narrated_end_to_end(): void
    {
        $server = $this->server();
        $page   = $this->walk($server);

        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Your login works', $page->body);
        $this->assertStringContainsString(FakeIndieAuthServer::ME, $page->body);

        // Every step of the story is on the page, in order.
        foreach (['home-page', 'server-link', 'endpoints', 'request', 'approval', 'return', 'redeem', 'identity'] as $step) {
            $this->assertStringContainsString('id="' . $step . '"', $page->body, $step . ' is missing');
        }

        $this->assertStringContainsString('noindex', (string) $page->header('x-robots-tag'));
    }

    public function test_checking_a_login_never_signs_anybody_in(): void
    {
        $this->walk($this->server());

        $this->assertNotSame(200, $this->request('GET', self::BASE . 'account')->status);
    }

    public function test_the_client_document_says_what_the_person_is_doing(): void
    {
        $document = json_decode($this->request('GET', self::BASE . 'check/client')->body, true);

        $this->assertSame(self::BASE . 'check/client', $document['client_id']);
        $this->assertStringContainsString('Check your login', $document['client_name']);
        $this->assertSame([self::BASE . 'check/callback'], $document['redirect_uris']);
    }

    public function test_cancelling_is_not_treated_as_a_failure(): void
    {
        $page = $this->walk($this->server(), 'cancel');

        $this->assertStringContainsString('you chose to cancel', strtolower($page->body));
        $this->assertStringNotContainsString('This is the problem', $page->body);
    }

    public function test_a_reply_from_the_wrong_issuer_stops_the_check(): void
    {
        $page = $this->walk($this->server('wrongIss'));

        $this->assertStringContainsString('This is the problem', $page->body);
        $this->assertStringContainsString('settings say it is', $page->body);
    }

    public function test_a_site_with_no_indieauth_server_gets_advice_not_an_error(): void
    {
        $this->server();
        $this->http->html('https://nobody.example/', '<!doctype html><title>Nobody</title>');

        $page = $this->page('https://nobody.example/');

        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('does not say which IndieAuth server', $page->body);
        $this->assertStringContainsString('rel=&quot;indieauth-metadata&quot;', $page->body);
        $this->assertStringNotContainsString('Continue — sign in with your server', $page->body);
    }

    public function test_a_metadata_link_in_the_body_is_pointed_out(): void
    {
        $this->server();
        $this->http->html(
            'https://inbody.example/',
            '<!doctype html><html><head><title>Me</title></head><body>'
                . '<link rel="indieauth-metadata" href="' . FakeIndieAuthServer::BASE . 'metadata"></body></html>',
        );

        $page = $this->page('https://inbody.example/');

        $this->assertStringContainsString('in the body rather than the', $page->body);
    }

    public function test_the_page_can_be_shared_but_continuing_cannot(): void
    {
        $this->server();
        $location = (string) $this->start()->header('location');

        // Whoever the link is sent to reads the page, and nothing more.
        $this->cookies = [];

        $this->assertSame(200, $this->request('GET', self::BASE . ltrim($location, '/'))->status);
        $this->assertSame(403, $this->request('GET', self::BASE . ltrim($location, '/') . '/go')->status);
    }

    public function test_secrets_are_hidden_from_the_page(): void
    {
        $server = $this->server();
        $page   = $this->walk($server);

        $this->assertStringContainsString('[redacted len=', $page->body);
    }

    public function test_a_reply_that_belongs_to_no_check_is_explained(): void
    {
        $this->server();

        $page = $this->request('GET', self::BASE . 'check/callback?state=made-up&code=made-up');

        $this->assertSame(400, $page->status);
        $this->assertStringContainsString('could not be matched to a check', $page->body);
    }

    public function test_the_same_reply_cannot_be_used_twice(): void
    {
        $server   = $this->server();
        $location = (string) $this->start()->header('location');
        $go       = $this->request('GET', self::BASE . ltrim($location, '/') . '/go');
        $landing  = (string) $server->authorize((string) $go->header('location'), 'approve');

        $this->assertSame(303, $this->request('GET', $landing)->status);
        $this->assertSame(400, $this->request('GET', $landing)->status);
    }

    public function test_leaving_and_never_coming_back_says_so(): void
    {
        $server   = $this->server();
        $location = (string) $this->start()->header('location');
        $this->request('GET', self::BASE . ltrim($location, '/') . '/go');

        $page = $this->request('GET', self::BASE . ltrim($location, '/'));

        $this->assertStringContainsString('have not come back yet', $page->body);
    }

    public function test_an_email_address_is_refused_before_anything_is_fetched(): void
    {
        $page = $this->start('me@example.com');

        $this->assertSame(422, $page->status);
        $this->assertStringContainsString('looks like an email address', $page->body);
    }

    /**
     * The limit is set well above what anyone sorting their site out will do,
     * so this asserts that it exists and says when it lifts, not where it sits.
     */
    public function test_checking_over_and_over_is_eventually_refused(): void
    {
        $this->server();

        $response = $this->start();

        for ($i = 0; $i < 200 && $response->status === 303; $i++) {
            $response = $this->start();
        }

        $this->assertSame(429, $response->status);
        $this->assertStringContainsString('Try again in', $response->body);
        $this->assertGreaterThan(20, $i, 'an ordinary run of checks should not hit the limit');
    }
}
