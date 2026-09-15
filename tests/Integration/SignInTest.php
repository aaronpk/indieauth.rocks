<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Integration;

use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Tests\Support\AppTestCase;
use IndieAuthRocks\Tests\Support\FakeIndieAuthServer;

/** Signing in with IndieAuth without testing a server, for people who only test a client. */
final class SignInTest extends AppTestCase
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

    /** Fill in and submit the sign-in form. */
    private function submit(string $me = FakeIndieAuthServer::ME): Response
    {
        $form = $this->request('GET', self::BASE . 'sign-in');

        return $this->request('POST', self::BASE . 'sign-in', ['_csrf' => self::csrf($form->body), 'me' => $me, 'next' => '/client-runs']);
    }

    public function test_a_tester_signs_in_and_can_start_a_client_run(): void
    {
        $server = $this->server();

        // Client runs send a visitor who is not signed in to the form.
        $runs = $this->request('GET', self::BASE . 'client-runs');
        $this->assertSame('/sign-in?next=%2Fclient-runs', $runs->header('location'));

        $form = $this->request('GET', self::BASE . 'sign-in?next=%2Fclient-runs');
        $this->assertSame(200, $form->status);
        $this->assertStringNotContainsString('form-action', (string) $form->header('content-security-policy'));

        $start = $this->submit();
        $this->assertSame(303, $start->status);
        $location = (string) $start->header('location');
        $this->assertStringContainsString('client_id=' . rawurlencode(self::BASE . 'sign-in/client'), $location);
        $this->assertStringContainsString('code_challenge_method=S256', $location);

        $landing = (string) $server->authorize($location, 'approve');
        $this->assertStringStartsWith(self::BASE . 'sign-in/callback?', $landing);

        $back = $this->request('GET', $landing);
        $this->assertSame(303, $back->status);
        $this->assertSame('/client-runs', $back->header('location'));

        $runs = $this->request('GET', self::BASE . 'client-runs');
        $this->assertSame(200, $runs->status);
        $this->assertStringContainsString('Start a new client run', $runs->body);

        // The same redirect cannot be used twice.
        $this->assertSame(400, $this->request('GET', $landing)->status);
    }

    public function test_a_redirect_opened_in_another_browser_signs_nobody_in(): void
    {
        $server  = $this->server();
        $landing = (string) $server->authorize((string) $this->submit()->header('location'), 'approve');

        $this->cookies = [];
        $page = $this->request('GET', $landing);

        $this->assertSame(400, $page->status);
        $this->assertStringContainsString('another browser', $page->body);
        $this->assertNotSame(200, $this->request('GET', self::BASE . 'account')->status);
    }

    public function test_a_response_from_the_wrong_issuer_is_not_trusted(): void
    {
        $server  = $this->server('wrongIss');
        $landing = (string) $server->authorize((string) $this->submit()->header('location'), 'approve');
        $page    = $this->request('GET', $landing);

        $this->assertSame(400, $page->status);
        $this->assertStringContainsString('different issuer', $page->body);
        $this->assertNotSame(200, $this->request('GET', self::BASE . 'account')->status);
    }

    public function test_cancelling_says_so(): void
    {
        $server = $this->server();
        $page   = $this->request('GET', (string) $server->authorize((string) $this->submit()->header('location'), 'cancel'));

        $this->assertSame(400, $page->status);
        $this->assertStringContainsString('Signing in was cancelled.', $page->body);
    }

    public function test_a_profile_without_an_indieauth_server_is_explained(): void
    {
        $this->server();
        $this->http->html('https://nobody.example/', '<!doctype html><title>Nobody</title>');

        $page = $this->submit('https://nobody.example/');

        $this->assertSame(422, $page->status);
        $this->assertStringContainsString('does not declare an IndieAuth server', $page->body);
    }

    public function test_after_signing_in_it_only_goes_somewhere_on_this_site(): void
    {
        foreach (['//evil.example/', 'https://evil.example/', '/\\evil.example'] as $next) {
            $form = $this->request('GET', self::BASE . 'sign-in?next=' . rawurlencode($next));
            $this->assertStringContainsString('name="next" value="/client-runs"', $form->body, $next);
        }
    }
}
